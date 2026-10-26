<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * NZB import result values used while processing imported NZB files.
 */
enum NzbImportStatus: string
{
    case Inserted = 'inserted';

    case Queued = 'queued';

    case MetadataIgnored = 'metadata_ignored';

    case MetadataDeferred = 'metadata_deferred';

    case Duplicate = 'duplicate';

    case Blacklisted = 'blacklisted';

    case NoGroup = 'nogroup';

    case Failed = 'failed';

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
        $result = self::computeNative(3, [$value]);

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
        $result = self::computeNative(4, [$value]);

        return is_string($result) ? $result : self::labelPhp($value);
    }

    public static function labelPhp(string $value): ?string
    {
        return match ($value) {
            'inserted' => 'Inserted',
            'queued' => 'Queued',
            'metadata_ignored' => 'Metadata Ignored',
            'metadata_deferred' => 'Metadata Deferred',
            'duplicate' => 'Duplicate',
            'blacklisted' => 'Blacklisted',
            'nogroup' => 'No Group',
            'failed' => 'Failed',
            default => null,
        };
    }

    /**
     * Enabled: the paired benchmark in the W59 parity test measures 7.463x common mean, 5.177x
     * common p99, 5.036x uncommon mean and 3.025x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_NZB_IMPORT_FFI_CDEF = 'ssize_t nntmux_w591_nzb_import_rollup_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W59_NZB_IMPORT_WORDS = 2;

    /** Rollup words after the rows: per code 0-8, success, terminal, retryable, duplicate, pending, malformed. */
    public const int W59_NZB_IMPORT_TAIL = 15;

    public const int W59_SOURCE_SHIFT = 4;

    public const int W59_SOURCE_VALUE = 1;

    public const int W59_SOURCE_NAME = 2;

    public const int W59_SOURCE_ALIAS = 3;

    public const int W59_NZB_SUCCESS = 256;

    public const int W59_NZB_TERMINAL = 512;

    public const int W59_NZB_RETRYABLE = 1024;

    public const int W59_NZB_EXHAUSTED = 2048;

    public const int W59_NZB_DUPLICATE = 4096;

    public const int W59_NZB_PENDING = 8192;

    public const int W59_NZB_MALFORMED = 16384;

    private const array W59_CODES = [
        'inserted' => 1, 'queued' => 2, 'metadata_ignored' => 3, 'metadata_deferred' => 4,
        'duplicate' => 5, 'blacklisted' => 6, 'nogroup' => 7, 'failed' => 8,
    ];

    private const array W59_NAMES = [
        'inserted' => 1, 'queued' => 2, 'metadataignored' => 3, 'metadatadeferred' => 4,
        'duplicate' => 5, 'blacklisted' => 6, 'nogroup' => 7, 'failed' => 8,
    ];

    private const array W59_ALIASES = [
        'insert' => 1, 'added' => 1, 'imported' => 1, 'ok' => 1, 'success' => 1,
        'queue' => 2, 'pending' => 2, 'enqueued' => 2,
        'ignored' => 3, 'metaignored' => 3,
        'deferred' => 4, 'metadefer' => 4, 'metadatadefer' => 4,
        'dupe' => 5, 'dup' => 5, 'exists' => 5, 'alreadyexists' => 5,
        'blacklist' => 6, 'blocked' => 6, 'banned' => 6,
        'missinggroup' => 7, 'unknowngroup' => 7, 'group' => 7,
        'fail' => 8, 'error' => 8, 'failure' => 8, 'invalid' => 8,
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
    public static function importStatusRollupBatch(array $rows): array
    {
        return self::usesW59RustFfi() ? self::importStatusRollupBatchNative($rows) : self::importStatusRollupBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function importStatusRollupBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W59_NZB_IMPORT_WORDS + self::W59_NZB_IMPORT_TAIL;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($buffer === null) {
            return self::importStatusRollupBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w591_nzb_import_rollup_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::importStatusRollupBatchPhp($rows);
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nzb_import_status_w59', self::W59_NZB_IMPORT_FFI_CDEF) ?? false;
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
     * Two words per `status\tattempts\tmax_attempts` row: the status code (case position plus
     * one, 0 unknown), the token source `<< W59_SOURCE_SHIFT` and the success, terminal,
     * retryable, exhausted, duplicate and pending flags; then the attempts left (attempts from
     * 0, max attempts clamped to 0-1000) for no-group and failed imports. Every known status is
     * exactly one of pending (queued, metadata deferred), retryable or terminal. A malformed row
     * yields `[W59_NZB_MALFORMED, 0]`. The W59_NZB_IMPORT_TAIL rollup words follow the rows.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function importStatusRollupBatchPhp(array $rows): array
    {
        $words = [];
        $tail = array_fill(0, self::W59_NZB_IMPORT_TAIL, 0);
        foreach ($rows as $row) {
            $fields = explode("\t", $row);
            if (\count($fields) !== 3 || preg_match('/\A-?[0-9]{1,18}\z/', $fields[1]) !== 1 || preg_match('/\A-?[0-9]{1,18}\z/', $fields[2]) !== 1) {
                array_push($words, self::W59_NZB_MALFORMED, 0);
                $tail[0]++;
                $tail[14]++;

                continue;
            }
            [$code, $source] = self::w59ResolveCode($fields[0]);
            $attempts = max(0, (int) $fields[1]);
            $max = max(0, min(1000, (int) $fields[2]));
            $retryClass = $code === 7 || $code === 8;
            $flags = match (true) {
                $code === 2 || $code === 4 => self::W59_NZB_PENDING,
                $retryClass && $attempts < $max => self::W59_NZB_RETRYABLE,
                $retryClass => self::W59_NZB_TERMINAL | self::W59_NZB_EXHAUSTED,
                $code !== 0 => self::W59_NZB_TERMINAL,
                default => 0,
            } | ($code >= 1 && $code <= 4 ? self::W59_NZB_SUCCESS : 0) | ($code === 5 ? self::W59_NZB_DUPLICATE : 0);
            array_push($words, $code | $source << self::W59_SOURCE_SHIFT | $flags, $retryClass ? max(0, $max - $attempts) : 0);
            $tail[$code]++;
            $tail[9] += ($flags & self::W59_NZB_SUCCESS) !== 0 ? 1 : 0;
            $tail[10] += ($flags & self::W59_NZB_TERMINAL) !== 0 ? 1 : 0;
            $tail[11] += ($flags & self::W59_NZB_RETRYABLE) !== 0 ? 1 : 0;
            $tail[12] += ($flags & self::W59_NZB_DUPLICATE) !== 0 ? 1 : 0;
            $tail[13] += ($flags & self::W59_NZB_PENDING) !== 0 ? 1 : 0;
        }

        return [...$words, ...$tail];
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
