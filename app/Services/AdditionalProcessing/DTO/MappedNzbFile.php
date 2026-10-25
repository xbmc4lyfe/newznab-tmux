<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * An NZB file whose real name the PAR2 index revealed.
 */
final class MappedNzbFile
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
            $written = self::$ffi->nntmux_w356_dto_provider(3, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 1 || $written > $capacity) {
                return null;
            }

            return FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public readonly int $sourceIndex,
        public readonly Par2FileEntry $file,
        public readonly MappingConfidence $confidence,
    ) {}

    /**
     * Normalize known backing values without constructing an enum; unknown values rank zero.
     *
     * @return array{sourceIndex: int, filename: string, fileSize: int, confidence: string, confidenceRank: int}
     */
    public static function summarizeMapping(int $sourceIndex, string $filename, int $fileSize, string $confidenceValue): array
    {
        return (self::usesRustFfi() ? self::summarizeMappingNative($sourceIndex, $filename, $fileSize, $confidenceValue) : null)
            ?? self::summarizeMappingPhp($sourceIndex, $filename, $fileSize, $confidenceValue);
    }

    /** @return array{sourceIndex: int, filename: string, fileSize: int, confidence: string, confidenceRank: int} */
    public static function summarizeMappingPhp(int $sourceIndex, string $filename, int $fileSize, string $confidenceValue): array
    {
        $confidence = str_replace('_', '-', strtolower(trim($confidenceValue)));
        $rank = match ($confidence) {
            'verified' => 2,
            'unique-size' => 1,
            default => 0,
        };

        return ['sourceIndex' => max(0, $sourceIndex), 'filename' => trim($filename), 'fileSize' => max(0, $fileSize), 'confidence' => $rank > 0 ? $confidence : 'unknown', 'confidenceRank' => $rank];
    }

    /** @return array{sourceIndex: int, filename: string, fileSize: int, confidence: string, confidenceRank: int}|null */
    public static function summarizeMappingNative(int $sourceIndex, string $filename, int $fileSize, string $confidenceValue): ?array
    {
        $input = pack('q2V', $sourceIndex, $fileSize, strlen($filename)).$filename.pack('V', strlen($confidenceValue)).$confidenceValue;
        $bytes = self::rust($input, strlen($input) + 32);
        if ($bytes === null || strlen($bytes) < 32) {
            return null;
        }
        /** @var array{sourceIndex: int, fileSize: int, confidenceRank: int, filenameLength: int} $header */
        $header = unpack('qsourceIndex/qfileSize/qconfidenceRank/VfilenameLength', $bytes);
        $offset = 28 + $header['filenameLength'];
        if ($offset > strlen($bytes) - 4) {
            return null;
        }
        /** @var array{length: int} $length */
        $length = unpack('Vlength', $bytes, $offset);
        if ($length['length'] !== strlen($bytes) - $offset - 4) {
            return null;
        }

        return ['sourceIndex' => $header['sourceIndex'], 'filename' => substr($bytes, 28, $header['filenameLength']), 'fileSize' => $header['fileSize'], 'confidence' => substr($bytes, $offset + 4), 'confidenceRank' => $header['confidenceRank']];
    }
}
