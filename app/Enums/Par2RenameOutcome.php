<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum Par2RenameOutcome: string
{
    case Renamed = 'renamed';

    case NoMatch = 'no_match';

    case Retryable = 'retryable';

    case Deferred = 'deferred';

    public const string FFI_CDEF = 'intptr_t nntmux_w22_article_source_enums_log_requests(uint32_t operation, const uint8_t *input, size_t length, uint8_t *output, size_t capacity);';

    public static function enableRustFfi(): void
    {
        self::toggleState(true);
    }

    public static function disableRustFfi(): void
    {
        self::toggleState(false);
    }

    public static function resetRustFfi(): void
    {
        self::toggleState(false);
        self::ffi(true);
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState();
    }

    private static function toggleState(?bool $next = null): bool
    {
        static $enabled = false;
        if ($next !== null) {
            $enabled = $next;
        }

        return $enabled;
    }

    private static function ffi(bool $reset = false): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = null;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('wave22-article-source-enums-log-requests', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<array-key, mixed>|string|null
     */
    private static function computeNative(int $operation, array $arguments): array|string|null
    {
        try {
            foreach ($arguments as $key => $value) {
                if (\ReflectionReference::fromArrayElement($arguments, $key) !== null) {
                    return null;
                }
                if ($operation === 8 && is_array($value)) {
                    if (! array_is_list($value)) {
                        return null;
                    }
                    foreach ($value as $index => $level) {
                        if (! is_string($level) || \ReflectionReference::fromArrayElement($value, $index) !== null) {
                            return null;
                        }
                    }
                } elseif ($value !== null && ! is_string($value) && ! is_bool($value) && ! is_int($value)) {
                    return null;
                }
            }
            $input = serialize($arguments);
            $length = strlen($input);
            if ($length > 64 * 1024 * 1024 - 1024 || ($ffi = self::ffi()) === null) {
                return null;
            }
            $capacity = $length + 1024;
            $inputBuffer = $ffi->new('uint8_t['.max(1, $length).']');
            FFI::memcpy($inputBuffer, $input, $length);
            $output = $ffi->new('uint8_t['.$capacity.']');
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w22_article_source_enums_log_requests($operation, $inputBuffer, $length, $output, $capacity);
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $result = unserialize(FFI::string($output, $written), ['allowed_classes' => false]);

            return is_array($result) || is_string($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function classify(string $value): ?self
    {
        $result = self::usesRustFfi() ? self::classifyNative($value) : self::classifyPhp($value);

        return $result === null ? null : self::tryFrom($result);
    }

    public static function classifyNative(string $value): ?string
    {
        $result = self::computeNative(5, [$value]);

        return is_string($result) ? $result : self::classifyPhp($value);
    }

    public static function classifyPhp(string $value): ?string
    {
        return self::tryFrom($value)?->value;
    }

    public function label(): string
    {
        return (self::usesRustFfi() ? self::labelNative($this->value) : null) ?? self::labelPhp($this->value) ?? $this->value;
    }

    public static function labelNative(string $value): ?string
    {
        $result = self::computeNative(6, [$value]);

        return is_string($result) ? $result : self::labelPhp($value);
    }

    public static function labelPhp(string $value): ?string
    {
        return match ($value) {
            'renamed' => 'Renamed',
            'no_match' => 'No Match',
            'retryable' => 'Retryable',
            'deferred' => 'Deferred',
            default => null,
        };
    }

    /**
     * Enabled: the paired benchmark in the W59 parity test measures 3.928x common mean, 3.702x
     * common p99, 2.253x uncommon mean and 1.694x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_PAR2_RENAME_FFI_CDEF = 'ssize_t nntmux_w591_par2_rename_summary_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W59_PAR2_RENAME_WORDS = 2;

    /** Summary words after the rows: per code 0-4, per bucket 1-3, per reason class 0-7, requeued. */
    public const int W59_PAR2_RENAME_TAIL = 17;

    public const int W59_SOURCE_SHIFT = 4;

    public const int W59_SOURCE_VALUE = 1;

    public const int W59_SOURCE_NAME = 2;

    public const int W59_SOURCE_ALIAS = 3;

    public const int W59_PAR2_BUCKET_SHIFT = 8;

    public const int W59_PAR2_REASON_SHIFT = 12;

    public const int W59_PAR2_REQUEUE = 65536;

    public const int W59_PAR2_VALID = 131072;

    public const int W59_BUCKET_SUCCESS = 1;

    public const int W59_BUCKET_SKIP = 2;

    public const int W59_BUCKET_FAILURE = 3;

    public const int W59_REASON_NONE = 0;

    public const int W59_REASON_TIMEOUT = 1;

    public const int W59_REASON_CORRUPT = 2;

    public const int W59_REASON_MISSING = 3;

    public const int W59_REASON_LOCKED = 4;

    public const int W59_REASON_PERMISSION = 5;

    public const int W59_REASON_COLLISION = 6;

    public const int W59_REASON_OTHER = 7;

    private const array W59_CODES = ['renamed' => 1, 'no_match' => 2, 'retryable' => 3, 'deferred' => 4];

    private const array W59_NAMES = ['renamed' => 1, 'nomatch' => 2, 'retryable' => 3, 'deferred' => 4];

    private const array W59_ALIASES = [
        'rename' => 1, 'ok' => 1, 'success' => 1, 'done' => 1, 'fixed' => 1,
        'none' => 2, 'unmatched' => 2, 'skip' => 2, 'skipped' => 2, 'miss' => 2,
        'retry' => 3, 'transient' => 3, 'error' => 3, 'failed' => 3, 'fail' => 3,
        'defer' => 4, 'later' => 4, 'pending' => 4, 'wait' => 4, 'postponed' => 4,
    ];

    /** Reason classes 1-6 by case-insensitive needle, first match wins. */
    private const array W59_REASON_NEEDLES = [
        ['timeout', 'timed out'],
        ['crc', 'checksum', 'corrupt', 'damaged'],
        ['missing', 'not found', 'no such'],
        ['lock', 'busy'],
        ['permission', 'denied', 'read-only'],
        ['exists', 'duplicate', 'collision'],
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
    public static function renameOutcomeSummaryBatch(array $rows): array
    {
        return self::usesW59RustFfi() ? self::renameOutcomeSummaryBatchNative($rows) : self::renameOutcomeSummaryBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function renameOutcomeSummaryBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W59_PAR2_RENAME_WORDS + self::W59_PAR2_RENAME_TAIL;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($buffer === null) {
            return self::renameOutcomeSummaryBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w591_par2_rename_summary_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::renameOutcomeSummaryBatchPhp($rows);
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
                $ffi ??= RustPipelineLibrary::resolveDomain('par2_rename_outcome_w59', self::W59_PAR2_RENAME_FFI_CDEF) ?? false;
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
     * Two words per `outcome[\treason]` row: the outcome code (case position plus one, 0
     * unknown), the token source `<< W59_SOURCE_SHIFT`, the bucket (success for renamed, skip
     * for no match and deferred, failure otherwise) `<< W59_PAR2_BUCKET_SHIFT`, the trimmed
     * reason's class `<< W59_PAR2_REASON_SHIFT` and the requeue (retryable, deferred) and valid
     * flags; then the trimmed reason length. The W59_PAR2_RENAME_TAIL summary words follow the
     * rows.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function renameOutcomeSummaryBatchPhp(array $rows): array
    {
        $words = [];
        $tail = array_fill(0, self::W59_PAR2_RENAME_TAIL, 0);
        foreach ($rows as $row) {
            $parts = explode("\t", $row, 2);
            $reason = trim($parts[1] ?? '');
            [$code, $source] = self::w59ResolveCode($parts[0]);
            $bucket = match ($code) {
                1 => self::W59_BUCKET_SUCCESS,
                2, 4 => self::W59_BUCKET_SKIP,
                default => self::W59_BUCKET_FAILURE,
            };
            $class = self::w59ReasonClass($reason);
            $requeue = $code === 3 || $code === 4;
            array_push(
                $words,
                $code | $source << self::W59_SOURCE_SHIFT | $bucket << self::W59_PAR2_BUCKET_SHIFT | $class << self::W59_PAR2_REASON_SHIFT
                    | ($requeue ? self::W59_PAR2_REQUEUE : 0) | ($code !== 0 ? self::W59_PAR2_VALID : 0),
                \strlen($reason),
            );
            $tail[$code]++;
            $tail[4 + $bucket]++;
            $tail[8 + $class]++;
            $tail[16] += $requeue ? 1 : 0;
        }

        return [...$words, ...$tail];
    }

    private static function w59ReasonClass(string $reason): int
    {
        if ($reason === '') {
            return self::W59_REASON_NONE;
        }
        foreach (self::W59_REASON_NEEDLES as $index => $needles) {
            foreach ($needles as $needle) {
                if (stripos($reason, $needle) !== false) {
                    return $index + 1;
                }
            }
        }

        return self::W59_REASON_OTHER;
    }

    /**
     * Resolves a token to `[code, source]` (code is the case position plus one, 0 unknown): an
     * exact case value; otherwise the trimmed, lowercased token without `_`, `-` and spaces is
     * looked up among case names, then aliases.
     *
     * @return array{0: int, 1: int}
     */
    private static function w59ResolveCode(string $raw): array
    {
        if (isset(self::W59_CODES[$raw])) {
            return [self::W59_CODES[$raw], self::W59_SOURCE_VALUE];
        }
        $key = str_replace(['_', '-', ' '], '', strtolower(trim($raw)));
        if (isset(self::W59_NAMES[$key])) {
            return [self::W59_NAMES[$key], self::W59_SOURCE_NAME];
        }

        return isset(self::W59_ALIASES[$key]) ? [self::W59_ALIASES[$key], self::W59_SOURCE_ALIAS] : [0, 0];
    }
}
