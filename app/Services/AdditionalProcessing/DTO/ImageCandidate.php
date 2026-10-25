<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * An image posted in the release, with every article needed to download it whole.
 */
final class ImageCandidate
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w356_dto_provider(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_capacity);
        C;

    // PHP remains the default until the complete caller clears the 1.5x gate.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    private static function rust(string $input, int $capacity): ?string
    {
        if (strlen($input) > 16_777_216 || $capacity < 1 || $capacity > 16_777_216) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $output = self::$ffi->new("char[{$capacity}]");
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w356_dto_provider(2, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public readonly string $title,
        public readonly array $messageIds,
        public readonly int $sourceIndex,
        public readonly int $postedSize = 0,
    ) {}

    /**
     * Trim byte strings, discard nonstring/empty IDs and deduplicate in first-seen order.
     * Constructor data remains untouched; callers opt into this descriptor explicitly.
     *
     * @param  array<array-key, mixed>  $messageIds
     * @return array{title: string, messageIds: list<string>, sourceIndex: int, postedSize: int}
     */
    public static function normalizeCandidateDescriptor(string $title, array $messageIds, int $sourceIndex, int $postedSize = 0): array
    {
        return (self::usesRustFfi() ? self::normalizeCandidateDescriptorNative($title, $messageIds, $sourceIndex, $postedSize) : null)
            ?? self::normalizeCandidateDescriptorPhp($title, $messageIds, $sourceIndex, $postedSize);
    }

    /**
     * @param  array<array-key, mixed>  $messageIds
     * @return array{title: string, messageIds: list<string>, sourceIndex: int, postedSize: int}
     */
    public static function normalizeCandidateDescriptorPhp(string $title, array $messageIds, int $sourceIndex, int $postedSize = 0): array
    {
        $ids = [];
        foreach ($messageIds as $id) {
            if (is_string($id) && ($id = trim($id)) !== '') {
                $ids[$id] = $id;
            }
        }

        return ['title' => trim($title), 'messageIds' => array_values($ids), 'sourceIndex' => max(0, $sourceIndex), 'postedSize' => max(0, $postedSize)];
    }

    /**
     * @param  array<array-key, mixed>  $messageIds
     * @return array{title: string, messageIds: list<string>, sourceIndex: int, postedSize: int}|null
     */
    public static function normalizeCandidateDescriptorNative(string $title, array $messageIds, int $sourceIndex, int $postedSize = 0): ?array
    {
        $ids = array_values(array_filter($messageIds, is_string(...)));
        $input = pack('q2V', $sourceIndex, $postedSize, strlen($title)).$title.pack('V', count($ids));
        foreach ($ids as $id) {
            $input .= pack('V', strlen($id)).$id;
        }
        $bytes = self::rust($input, strlen($input));
        if ($bytes === null || strlen($bytes) < 24) {
            return null;
        }
        /** @var array{sourceIndex: int, postedSize: int, titleLength: int} $header */
        $header = unpack('qsourceIndex/qpostedSize/VtitleLength', $bytes);
        $offset = 20 + $header['titleLength'];
        if ($offset > strlen($bytes) - 4) {
            return null;
        }
        $title = substr($bytes, 20, $header['titleLength']);
        /** @var array{count: int} $count */
        $count = unpack('Vcount', $bytes, $offset);
        $offset += 4;
        if ($count['count'] > intdiv(strlen($bytes) - $offset, 4)) {
            return null;
        }
        $ids = [];
        for ($i = 0; $i < $count['count']; $i++) {
            if ($offset > strlen($bytes) - 4) {
                return null;
            }
            /** @var array{length: int} $length */
            $length = unpack('Vlength', $bytes, $offset);
            $offset += 4;
            if ($length['length'] > strlen($bytes) - $offset) {
                return null;
            }
            $ids[] = substr($bytes, $offset, $length['length']);
            $offset += $length['length'];
        }

        return $offset === strlen($bytes)
            ? ['title' => $title, 'messageIds' => $ids, 'sourceIndex' => $header['sourceIndex'], 'postedSize' => $header['postedSize']]
            : null;
    }
}
