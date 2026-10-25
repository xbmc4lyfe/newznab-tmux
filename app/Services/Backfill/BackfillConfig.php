<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderStorageTransaction;
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

    /**
     * PHP stays the default for summarizeBackfillSettingsBatch(): the paired benchmark in
     * tests/Unit/RustBinariesTransactionMissedPartBackfillW42ParityTest.php does not clear 1.5x.
     */
    public const bool W421_RUST_FFI_DEFAULT = false;

    private const array W421_SETTINGS_KEYS = ['configs', 'invalid_rows', 'bad_date', 'update_mode', 'backfill_mode', 'bad_mode', 'compressed',
        'disable_group'];

    /**
     * Summarizes candidate backfill settings rows (`safeBackFillDate`, `safePartRepair`, `compressedHeaders`,
     * `disableBackfillGroup`): a date that is not a `YYYY-MM-DD` string passing checkdate() is bad, a repair mode other
     * than exactly 'update' or 'backfill' is bad, and a flag is set when it is true or a nonzero int (or 1-18 digit
     * string). Non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeBackfillSettingsBatch(array $rows): array
    {
        return (self::usesW421RustFfi() ? self::summarizeBackfillSettingsBatchNative($rows) : null)
            ?? self::summarizeBackfillSettingsBatchPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeBackfillSettingsBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W421_SETTINGS_KEYS, 0);
        $summary['configs'] = count($rows);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $summary['invalid_rows']++;

                continue;
            }
            $date = $row['safeBackFillDate'] ?? null;
            $summary['bad_date'] += (int) ! (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) === 1
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]));
            $mode = $row['safePartRepair'] ?? null;
            if ($mode === 'update') {
                $summary['update_mode']++;
            } elseif ($mode === 'backfill') {
                $summary['backfill_mode']++;
            } else {
                $summary['bad_mode']++;
            }
            foreach (['compressedHeaders' => 'compressed', 'disableBackfillGroup' => 'disable_group'] as $field => $key) {
                $flag = $row[$field] ?? null;
                $summary[$key] += (int) ($flag === true || (($value = BinariesService::w411Int($flag)) !== null && $value !== 0));
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>|null
     */
    public static function summarizeBackfillSettingsBatchNative(array $rows): ?array
    {
        $words = [count($rows)];
        $blob = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $compressed = $row['compressedHeaders'] ?? null;
                $disable = $row['disableBackfillGroup'] ?? null;
                BinariesService::w411PackRow($words, $blob, [$row['safeBackFillDate'] ?? null, $row['safePartRepair'] ?? null,
                    is_bool($compressed) ? (int) $compressed : $compressed, is_bool($disable) ? (int) $disable : $disable]);
            } else {
                $words[] = 0;
            }
        }
        $values = HeaderStorageTransaction::w421Invoke(self::w421FfiSlot(), 4, $words, $blob);

        return $values !== null && count($values) === count(self::W421_SETTINGS_KEYS) ? array_combine(self::W421_SETTINGS_KEYS, $values) : null;
    }

    public static function useW421RustFfi(bool $enable = true): void
    {
        self::w421ToggleState($enable, true);
    }

    public static function usesW421RustFfi(): bool
    {
        return self::w421ToggleState() ?? self::W421_RUST_FFI_DEFAULT;
    }

    public static function resetW421RustFfi(): void
    {
        self::w421ToggleState(null, true);
        $slot = &self::w421FfiSlot();
        $slot = null;
    }

    private static function w421ToggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    /** The cached Wave 42.1 library handle, by reference (readonly classes cannot declare static properties). */
    private static function &w421FfiSlot(): FFI|false|null
    {
        static $ffi = null;

        return $ffi;
    }
}
