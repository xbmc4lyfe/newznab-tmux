<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52 parity test). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    private const string W52_FFI_CDEF = 'ssize_t nntmux_w521_backfill_config_date_days_batch(const char *dates, size_t dates_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 52 batch routed to Rust or PHP, then restores the routing. */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Epoch day (days since 1970-01-01) of each candidate `safeBackFillDate`, or null when it is
     * not a `YYYY-MM-DD` string passing checkdate() (the {@see summarizeBackfillSettingsBatch()}
     * rule).
     *
     * @param  list<string>  $dates
     * @return list<int|null>
     */
    public static function safeBackfillDateDays(array $dates): array
    {
        return self::usesW52RustFfi() ? self::safeBackfillDateDaysNative($dates) : self::safeBackfillDateDaysPhp($dates);
    }

    /**
     * @param  list<string>  $dates
     * @return list<int|null>
     */
    public static function safeBackfillDateDaysPhp(array $dates): array
    {
        $days = [];
        foreach ($dates as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1
                || ! checkdate($month = (int) $parts[2], $day = (int) $parts[3], $year = (int) $parts[1])) {
                $days[] = null;

                continue;
            }
            // Proleptic Gregorian days from civil; checkdate() keeps the year positive.
            $y = $month <= 2 ? $year - 1 : $year;
            $era = intdiv($y, 400);
            $yoe = $y - $era * 400;
            $doy = intdiv(153 * ($month > 2 ? $month - 3 : $month + 9) + 2, 5) + $day - 1;
            $days[] = $era * 146_097 + $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy - 719_468;
        }

        return $days;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a date
     * holding a NUL byte).
     *
     * @param  list<string>  $dates
     * @return list<int|null>
     */
    public static function safeBackfillDateDaysNative(array $dates): array
    {
        $count = \count($dates);
        $buffer = $count === 0 ? null : self::w52Buffer(2 * $count);
        if ($buffer === null) {
            return self::safeBackfillDateDaysPhp($dates);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $dates);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w52Decode($out, $ffi->nntmux_w521_backfill_config_date_days_batch($joined, \strlen($joined), $count, $out, $cap), 2 * $count);
        } catch (Throwable) {
            $words = null;
        }
        if ($words === null || \count($words) < $count) {
            return self::safeBackfillDateDaysPhp($dates);
        }
        $days = \array_slice($words, 0, $count);
        foreach (\array_slice($words, $count) as $index) {
            $days[$index] = null;
        }

        return $days;
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: CData, 2: int}|null
     */
    private static function w52Buffer(int $words): ?array
    {
        $buffer = self::w52RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w52Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 52 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics (readonly classes cannot declare static properties).
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('backfill_binaries_w52', self::W52_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    // ========================================================================
    // WAVE 66.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.91x, p99 3.33x; uncommon mean 6.07x, p99 5.24x); see RustBackfillBinariesConfigServiceHandlerW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    public const string W66_BACKFILL_CONFIG_FFI_CDEF = <<<'C'
        ssize_t nntmux_w661_backfill_config_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w66BackfillConfigPlanBatch(). */
    public const int W66_BACKFILL_CONFIG_TRAILER = 32;

    public static function usesW66RustFfi(): bool
    {
        $override = self::w66RustFfiState();

        return \is_bool($override) ? $override : self::W66_RUST_FFI_DEFAULT;
    }

    /** Forces the BackfillConfig Wave 66 Rust path on until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Forces the BackfillConfig Wave 66 PHP path until reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    /**
     * Runs $callback with the BackfillConfig Wave 66 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 66.1 backfill configuration plans, one per `compressed|echo|safe_date|part_repair|disable|messages|threads|days` row
     * (trimmed fields). Flags are case-insensitive 1/true/yes/on or 0/false/no/off (compressed defaults on, echo and disable
     * off). The safe date is `YYYY-MM-DD`: 0 empty (2012-08-14), 1 valid, 2 bad format, 3 impossible, 4 before 1990, 5 after
     * 2099. Part repair (lowercased): 0 empty, 1 backfill, 2 update, 3 other. Numbers are 1-15 digits: messages (empty 20000)
     * clamp to 1000-1000000, threads (empty 1) to 1-64, days (empty 0) to 0-3650. Decision: 0 empty row, 1 malformed flag or
     * number, 2 bad date, 3 unknown repair mode, 4 clamped or out-of-range date, 5 valid. Flags: 1 compressed, 2 echo,
     * 4 disable, 8/16/32 messages/threads/days clamped, 64 non-ASCII, 128 control byte, 256 row repeated (case-insensitive).
     * Per item `decision | dateState << 3 | repair << 6 | flags << 8 | messages << 17 | threads << 37 | days << 44 |
     * clamp(year - 1970, 0, 127) << 56`; then 6 decision, 6 date state, 4 repair and 9 flag counts, the message sum and
     * maximum, the thread sum, the largest day window, the first and last valid epoch day and the distinct rows.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BackfillConfigPlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66BackfillConfigPlanBatchNative($items) : self::w66BackfillConfigPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BackfillConfigPlanBatchNative(array $items): array
    {
        return self::w66Invoke($items, \count($items) + self::W66_BACKFILL_CONFIG_TRAILER) ?? self::w66BackfillConfigPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BackfillConfigPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W66_BACKFILL_CONFIG_TRAILER, 0);
        $seen = [];
        $minEpoch = null;
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawCompressed, $rawEcho, $rawDate, $rawRepair, $rawDisable, $rawMessages, $rawThreads, $rawDays] = explode('|', $item, 8)
                + ['', '', '', '', '', '', '', ''];
            $compressed = self::w66Flag($rawCompressed, 1);
            $echo = self::w66Flag($rawEcho, 0);
            $disable = self::w66Flag($rawDisable, 0);
            [$dateState, $year, $epoch] = self::w66SafeDate($rawDate);
            $repair = match (strtolower(trim($rawRepair))) {
                '' => 0,
                'backfill' => 1,
                'update' => 2,
                default => 3,
            };
            $messages = self::w66Uint($rawMessages, 20_000);
            $threads = self::w66Uint($rawThreads, 1);
            $days = self::w66Uint($rawDays, 0);
            $malformed = $compressed < 0 || $echo < 0 || $disable < 0 || $messages < 0 || $threads < 0 || $days < 0;
            [$messages, $clampedMessages] = self::w66Clamp($messages, 1000, 1_000_000);
            [$threads, $clampedThreads] = self::w66Clamp($threads, 1, 64);
            [$days, $clampedDays] = self::w66Clamp($days, 0, 3650);
            $trimmed = trim($item);
            $decision = match (true) {
                $trimmed === '' => 0,
                $malformed => 1,
                $dateState === 2 || $dateState === 3 => 2,
                $repair === 3 => 3,
                $clampedMessages || $clampedThreads || $clampedDays || $dateState >= 4 => 4,
                default => 5,
            };
            $flags = ($compressed === 1 ? 1 : 0) | ($echo === 1 ? 2 : 0) | ($disable === 1 ? 4 : 0) | ($clampedMessages ? 8 : 0)
                | ($clampedThreads ? 16 : 0) | ($clampedDays ? 32 : 0) | (preg_match('/[\x80-\xff]/', $item) === 1 ? 64 : 0)
                | (strcspn($item, self::W66_CONTROL_BYTES) !== \strlen($item) ? 128 : 0);
            if ($trimmed !== '') {
                $key = strtolower($trimmed);
                if (isset($seen[$key])) {
                    $flags |= 256;
                } else {
                    $seen[$key] = true;
                }
            }
            $tail[$decision]++;
            $tail[6 + $dateState]++;
            $tail[12 + $repair]++;
            for ($b = 0; $b < 9; $b++) {
                $tail[16 + $b] += $flags >> $b & 1;
            }
            $tail[25] += $messages;
            $tail[26] = max($tail[26], $messages);
            $tail[27] += $threads;
            $tail[28] = max($tail[28], $days);
            if ($dateState !== 2 && $dateState !== 3) {
                $minEpoch = $minEpoch === null ? $epoch : min($minEpoch, $epoch);
                $tail[30] = max($tail[30], $epoch);
            }
            $out[] = $decision | $dateState << 3 | $repair << 6 | $flags << 8 | $messages << 17 | $threads << 37 | $days << 44
                | max(0, min($year - 1970, 127)) << 56;
        }
        $tail[29] = $minEpoch ?? 0;
        $tail[31] = \count($seen);
        array_push($out, ...$tail);

        return $out;
    }

    private const string W66_DIGITS = '0123456789';

    private const string W66_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w66Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W66_DIGITS) === $length ? (int) $value : -1;
    }

    /** A trimmed, case-insensitive boolean: empty is $empty, 1/true/yes/on 1, 0/false/no/off 0, anything else -1. */
    private static function w66Flag(string $raw, int $empty): int
    {
        return match (strtolower(trim($raw))) {
            '' => $empty,
            '1', 'true', 'yes', 'on' => 1,
            '0', 'false', 'no', 'off' => 0,
            default => -1,
        };
    }

    /**
     * $value clamped to $min..$max and whether clamping changed it; malformed (-1) is 0, unchanged.
     *
     * @return array{0: int, 1: bool}
     */
    private static function w66Clamp(int $value, int $min, int $max): array
    {
        if ($value < 0) {
            return [0, false];
        }
        $clamped = max($min, min($max, $value));

        return [$clamped, $clamped !== $value];
    }

    /**
     * `[state, year, max(0, epoch day)]` of a trimmed `YYYY-MM-DD` date: 0 empty (the 2012-08-14 default), 1 valid,
     * 2 bad format, 3 impossible date, 4 before 1990, 5 after 2099.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function w66SafeDate(string $raw): array
    {
        $date = trim($raw);
        if ($date === '') {
            [$year, $month, $day] = [2012, 8, 14];
        } else {
            if (\strlen($date) !== 10 || $date[4] !== '-' || $date[7] !== '-' || strspn($date, self::W66_DIGITS, 0, 4) !== 4
                || strspn($date, self::W66_DIGITS, 5, 2) !== 2 || strspn($date, self::W66_DIGITS, 8, 2) !== 2) {
                return [2, 0, 0];
            }
            [$year, $month, $day] = [(int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2)];
        }
        $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
        $monthDays = match ($month) {
            2 => $leap ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
        if ($month < 1 || $month > 12 || $day < 1 || $day > $monthDays) {
            return [3, 0, 0];
        }
        $state = $date === '' ? 0 : ($year < 1990 ? 4 : ($year > 2099 ? 5 : 1));

        return [$state, $year, max(0, self::w66DaysFromCivil($year, $month, $day))];
    }

    /** Days since 1970-01-01 of a proleptic Gregorian date (Hinnant's `days_from_civil`). */
    private static function w66DaysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yoe = $year - $era * 400;
        $doy = intdiv(153 * ($month > 2 ? $month - 3 : $month + 9) + 2, 5) + $day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146_097 + $doe - 719_468;
    }

    /**
     * Wave 66 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('backfill_config_w661', self::W66_BACKFILL_CONFIG_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 66.1 BackfillConfig export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w66Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w66RustFfiState('ffi');
            $out = self::w66RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w66RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W66_BACKFILL_CONFIG_FFI_CDEF.)
            $written = $ffi->nntmux_w661_backfill_config_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
