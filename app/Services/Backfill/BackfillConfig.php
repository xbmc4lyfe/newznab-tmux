<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Configuration DTO for Backfill processing.
 * Encapsulates all settings in an immutable object for easier testing and injection.
 */
final readonly class BackfillConfig
{
    public function __construct(
        public bool $compressedHeaders = true,
        public bool $echoCli = false,
        public string $safeBackFillDate = '2012-08-14',
        public string $safePartRepair = 'backfill',
        public bool $disableBackfillGroup = false,
    ) {}

    /**
     * Create configuration from application settings.
     */
    public static function fromSettings(): self
    {
        $configuration = app(ConfigurationProvider::class)->ingestion();

        return new self(...self::normalizeTuple(
            config('nntmux_nntp.compressed_headers'),
            config('nntmux.echocli'),
            $configuration->safeBackfillDate,
            $configuration->safePartRepair,
            $configuration->disableBackfillGroup,
        ));
    }

    public static function safePartRepairMode(bool $repair): string
    {
        return (self::rustEnabled() ? self::safePartRepairModeNative($repair) : null)
            ?? ($repair ? 'update' : 'backfill');
    }

    public static function safePartRepairModeNative(bool $repair): ?string
    {
        return self::nativeCall(0, $repair ? '1' : '0');
    }

    /** @return array{bool, bool, string, string, bool} */
    public static function normalizeTuple(mixed $compressed, mixed $echo, string $date, bool $repair, bool $disable): array
    {
        return (self::rustEnabled() ? self::normalizeTupleNative($compressed, $echo, $date, $repair, $disable) : null)
            ?? [(bool) $compressed, (bool) $echo, $date, $repair ? 'update' : 'backfill', $disable];
    }

    /** @return array{bool, bool, string, string, bool}|null */
    public static function normalizeTupleNative(mixed $compressed, mixed $echo, string $date, bool $repair, bool $disable): ?array
    {
        $input = self::packFrames([$compressed ? '1' : '0', $echo ? '1' : '0', $date, $repair ? '1' : '0', $disable ? '1' : '0']);
        $fields = $input === null ? null : self::unpackFrames(self::nativeCall(1, $input));
        if ($fields === null || count($fields) !== 5) {
            return null;
        }

        return [$fields[0] === '1', $fields[1] === '1', $fields[2], $fields[3], $fields[4] === '1'];
    }

    private static function nativeCall(int $mode, string $input): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave20-backfill', 'intptr_t nntmux_wave20_backfill(uint32_t mode, const char *input, size_t input_len, char *out, size_t out_cap);');
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 2 + 256), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_wave20_backfill($mode, $input, strlen($input), $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $values */
    private static function packFrames(array $values): ?string
    {
        $input = '';
        foreach ($values as $value) {
            if (strlen($value) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /** @return list<string>|null */
    private static function unpackFrames(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $values = [];
        for ($offset = 0, $length = strlen($input); $offset < $length;) {
            if ($length - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', substr($input, $offset, 4));
            if ($header === false || ! is_int($header['length']) || $header['length'] > $length - $offset - 4) {
                return null;
            }
            $offset += 4;
            $values[] = substr($input, $offset, $header['length']);
            $offset += $header['length'];
        }

        return $values;
    }

    // Readonly DTOs cannot have static properties.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function useRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }
}
