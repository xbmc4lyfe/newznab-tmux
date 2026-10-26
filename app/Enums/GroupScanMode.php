<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\TmuxCleanupRule;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Enums cannot declare static properties, so the Rust toggle and FFI handle live in
 * {@see TmuxCleanupRule} keyed by this class.
 */
enum GroupScanMode: int
{
    case Posts = 0;
    case Days = 1;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_group_scan_mode_normalize(
            int64_t raw_mode,
            int64_t days_to_scan,
            int64_t messages_to_scan,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIngestionTmuxEnumsCleanupModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    /** Mirrors `IngestionConfiguration::SCAN_DAYS_MAX`. */
    private const int SCAN_DAYS_MAX = 36500;

    private const int UINT32_MAX = 4294967295;

    public static function usesRustFfi(): bool
    {
        return TmuxCleanupRule::enumRustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        TmuxCleanupRule::setEnumRustFfiToggle(self::class, $use);
    }

    /**
     * Resolves a new-group scan: days mode scans 1-36500 days, any other value scans by post
     * count clamped to `uint32`.
     *
     * @return array{mode: self, is_valid: bool, unit: string, target: int}
     */
    public static function normalizeScanSelection(int $rawMode, int $daysToScan, int $messagesToScan): array
    {
        return self::usesRustFfi()
            ? self::normalizeScanSelectionNative($rawMode, $daysToScan, $messagesToScan)
            : self::normalizeScanSelectionPhp($rawMode, $daysToScan, $messagesToScan);
    }

    /**
     * @return array{mode: self, is_valid: bool, unit: string, target: int}
     */
    public static function normalizeScanSelectionNative(int $rawMode, int $daysToScan, int $messagesToScan): array
    {
        $fields = TmuxCleanupRule::callCleaningNative(
            TmuxCleanupRule::enumFfi(self::class, self::FFI_CDEF),
            'nntmux_w35_group_scan_mode_normalize',
            [$rawMode, $daysToScan, $messagesToScan],
        );
        $mode = $fields !== null && \count($fields) === 4 && $fields[0] !== null && ctype_digit($fields[0]) ? self::tryFrom((int) $fields[0]) : null;
        if ($mode === null || ($fields[1] !== '0' && $fields[1] !== '1') || $fields[2] !== strtolower($mode->name)
            || $fields[3] === null || ! ctype_digit($fields[3])) {
            return self::normalizeScanSelectionPhp($rawMode, $daysToScan, $messagesToScan);
        }

        return [
            'mode' => $mode,
            'is_valid' => $fields[1] === '1',
            'unit' => $fields[2],
            'target' => (int) $fields[3],
        ];
    }

    /**
     * @return array{mode: self, is_valid: bool, unit: string, target: int}
     */
    public static function normalizeScanSelectionPhp(int $rawMode, int $daysToScan, int $messagesToScan): array
    {
        $mode = $rawMode === self::Days->value ? self::Days : self::Posts;

        return [
            'mode' => $mode,
            'is_valid' => self::tryFrom($rawMode) !== null,
            'unit' => strtolower($mode->name),
            'target' => $mode === self::Days
                ? max(1, min($daysToScan, self::SCAN_DAYS_MAX))
                : max(0, min($messagesToScan, self::UINT32_MAX)),
        ];
    }

    /**
     * Enabled: the paired benchmark in the W59 parity test measures 5.908x common mean, 4.315x
     * common p99, 4.66x uncommon mean and 3.969x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_GROUP_SCAN_FFI_CDEF = 'ssize_t nntmux_w591_group_scan_plan_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W59_GROUP_SCAN_WORDS = 5;

    public const int W59_SOURCE_SHIFT = 4;

    public const int W59_SOURCE_VALUE = 1;

    public const int W59_SOURCE_NAME = 2;

    public const int W59_SOURCE_ALIAS = 3;

    public const int W59_SCAN_VALID = 64;

    public const int W59_SCAN_DAYS_UNIT = 128;

    public const int W59_SCAN_TARGET_CLAMPED = 256;

    public const int W59_SCAN_LIMIT_ADJUSTED = 512;

    public const int W59_SCAN_EMPTY_RANGE = 1024;

    public const int W59_SCAN_MALFORMED = 2048;

    public const int W59_SCAN_LIMIT_SHIFT = 16;

    private const array W59_NAMES = ['posts' => 0, 'days' => 1];

    private const array W59_ALIASES = [
        'post' => 0, 'messages' => 0, 'message' => 0, 'count' => 0, 'articles' => 0, 'headers' => 0,
        'day' => 1, 'date' => 1, 'age' => 1, 'time' => 1,
    ];

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 59 batch routed to Rust or PHP, then restores the routing. */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function groupScanPlanBatch(array $rows): array
    {
        return self::usesW59RustFfi() ? self::groupScanPlanBatchNative($rows) : self::groupScanPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function groupScanPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W59_GROUP_SCAN_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($buffer === null) {
            return self::groupScanPlanBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w591_group_scan_plan_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::groupScanPlanBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($result);
    }

    /**
     * Wave 59 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics because
     * enums cannot declare properties.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('group_scan_mode_w59', self::W59_GROUP_SCAN_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    /**
     * Five words per `mode\tdays\tmessages\tfirst\tlast\tbatch_limit` row: the resolved value,
     * the token source `<< W59_SOURCE_SHIFT`, the valid, days-unit, target-clamped,
     * limit-adjusted and empty-range flags and the batch limit (1-1000000, default 20000)
     * `<< W59_SCAN_LIMIT_SHIFT`; then the normalizeScanSelectionPhp() target, the first header,
     * the header count (the whole `first..last` range by days, the newest `target` headers by
     * posts) and the batch count. A malformed row yields `[W59_SCAN_MALFORMED, 0, 0, 0, 0]`.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function groupScanPlanBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $fields = explode("\t", $row);
            $ints = [];
            if (\count($fields) === 6) {
                for ($i = 1; $i <= 5 && preg_match('/\A-?[0-9]{1,18}\z/', $fields[$i]) === 1; $i++) {
                    $ints[] = (int) $fields[$i];
                }
            }
            if (\count($ints) !== 5) {
                array_push($words, self::W59_SCAN_MALFORMED, 0, 0, 0, 0);

                continue;
            }
            [$days, $messages, $first, $last, $limit] = $ints;
            [$value, $source] = self::w59ResolveMode($fields[0]);
            $resolved = $value ?? self::Posts->value;
            $isDays = $resolved === self::Days->value;
            $raw = $isDays ? $days : $messages;
            $target = $isDays ? max(1, min($days, self::SCAN_DAYS_MAX)) : max(0, min($messages, self::UINT32_MAX));
            $flags = $resolved | $source << self::W59_SOURCE_SHIFT
                | ($value !== null ? self::W59_SCAN_VALID : 0)
                | ($isDays ? self::W59_SCAN_DAYS_UNIT : 0)
                | ($raw !== $target ? self::W59_SCAN_TARGET_CLAMPED : 0);
            if ($limit < 1) {
                $limit = 20000;
                $flags |= self::W59_SCAN_LIMIT_ADJUSTED;
            } elseif ($limit > 1000000) {
                $limit = 1000000;
                $flags |= self::W59_SCAN_LIMIT_ADJUSTED;
            }
            if ($first < 1 || $last < $first) {
                $flags |= self::W59_SCAN_EMPTY_RANGE;
                $start = $headers = 0;
            } elseif ($isDays) {
                $start = $first;
                $headers = $last - $first + 1;
            } else {
                $headers = min($last - $first + 1, $target);
                $start = $last - $headers + 1;
            }
            array_push($words, $flags | $limit << self::W59_SCAN_LIMIT_SHIFT, $target, $start, $headers, intdiv($headers + $limit - 1, $limit));
        }

        return $words;
    }

    /**
     * Resolves a mode token to `[case value or null, source]`: a strict integer must be a case
     * value; otherwise the trimmed, lowercased token without `_`, `-` and spaces is looked up
     * among case names, then aliases.
     *
     * @return array{0: ?int, 1: int}
     */
    private static function w59ResolveMode(string $token): array
    {
        $token = trim($token);
        if (preg_match('/\A-?[0-9]{1,18}\z/', $token) === 1) {
            $value = (int) $token;

            return self::tryFrom($value) === null ? [null, 0] : [$value, self::W59_SOURCE_VALUE];
        }
        $key = str_replace(['_', '-', ' '], '', strtolower($token));
        if (isset(self::W59_NAMES[$key])) {
            return [self::W59_NAMES[$key], self::W59_SOURCE_NAME];
        }

        return isset(self::W59_ALIASES[$key]) ? [self::W59_ALIASES[$key], self::W59_SOURCE_ALIAS] : [null, 0];
    }
}
