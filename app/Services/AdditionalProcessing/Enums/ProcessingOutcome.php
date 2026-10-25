<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum ProcessingOutcome: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_pout_classify(const char *value, size_t len);
        C;

    /** `nntmux_pout_classify` flag bits above the case index. */
    private const int SUCCESSFUL_BIT = 0x100;

    private const int DELETED_BIT = 0x200;

    case Completed = 'completed';
    case NoUsefulArtifacts = 'no-useful-artifacts';
    case Passworded = 'passworded';
    case GroupUnavailable = 'group-unavailable';
    case TemporaryWorkspaceUnavailable = 'temporary-workspace-unavailable';
    case TimedOut = 'timed-out';
    case DeletedAfterTimeout = 'deleted-after-timeout';
    case DeletedBrokenNzb = 'deleted-broken-nzb';
    case DeletedPar2Only = 'deleted-par2-only';
    case NotFound = 'not-found';
    // A partial publication whose NZB late articles may still replace; processed once settled.
    case AwaitingLateArticles = 'awaiting-late-articles';
    case Failed = 'failed';

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    public function isSuccessful(): bool
    {
        return self::usesRustFfi() ? $this->isSuccessfulNative() : $this->isSuccessfulPhp();
    }

    public function isSuccessfulPhp(): bool
    {
        return match ($this) {
            self::Completed, self::NoUsefulArtifacts, self::Passworded => true,
            default => false,
        };
    }

    public function isSuccessfulNative(): bool
    {
        $code = self::classifyNative($this->value);

        return $code === null ? $this->isSuccessfulPhp() : ($code & self::SUCCESSFUL_BIT) !== 0;
    }

    public function isDeleted(): bool
    {
        return self::usesRustFfi() ? $this->isDeletedNative() : $this->isDeletedPhp();
    }

    public function isDeletedPhp(): bool
    {
        return match ($this) {
            self::DeletedAfterTimeout, self::DeletedBrokenNzb, self::DeletedPar2Only => true,
            default => false,
        };
    }

    public function isDeletedNative(): bool
    {
        $code = self::classifyNative($this->value);

        return $code === null ? $this->isDeletedPhp() : ($code & self::DELETED_BIT) !== 0;
    }

    /**
     * Resolve a stored outcome code; null when it names no case.
     */
    public static function resolve(string $value): ?self
    {
        return self::usesRustFfi() ? self::resolveNative($value) : self::resolvePhp($value);
    }

    public static function resolvePhp(string $value): ?self
    {
        return self::tryFrom($value);
    }

    public static function resolveNative(string $value): ?self
    {
        $code = self::classifyNative($value);

        return $code === null ? self::resolvePhp($value) : (self::cases()[$code & 0xFF] ?? self::resolvePhp($value));
    }

    /**
     * `nntmux_pout_classify` result, or null for an unknown value or an unavailable library.
     */
    private static function classifyNative(string $value): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_pout_classify($value, \strlen($value));
        } catch (Throwable) {
            return null;
        }

        return \is_int($code) && $code >= 0 ? $code : null;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // PHP stays the default: the paired W50.6 benchmark p99 fell below 1.5x in repeated runs.
    private const bool W50_RUST_FFI_DEFAULT = false;

    public const string W50_FFI_CDEF = <<<'C'
        int32_t nntmux_w506_processing_outcome_batch(const char *values, size_t values_len, const char *value_twin, size_t value_twin_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W50_OUTCOME_HEADER = 20;

    private const int W50_MAX_ITEMS = 1_048_576;

    /** Backing value => declaration index; 12 is an unknown value. */
    private const array W50_CASE_INDEX = [
        'completed' => 0,
        'no-useful-artifacts' => 1,
        'passworded' => 2,
        'group-unavailable' => 3,
        'temporary-workspace-unavailable' => 4,
        'timed-out' => 5,
        'deleted-after-timeout' => 6,
        'deleted-broken-nzb' => 7,
        'deleted-par2-only' => 8,
        'not-found' => 9,
        'awaiting-late-articles' => 10,
        'failed' => 11,
    ];

    public static function usesW50RustFfi(): bool
    {
        return self::w50RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 50.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('swap', $previous);
        }
    }

    /**
     * Classify a batch of stored outcome codes as `resolvePhp()`, `isSuccessfulPhp()` and
     * `isDeletedPhp()` do (cases 0-2 are successful, 6-8 deleted, 12 is an unknown value).
     *
     * Layout: `[0]` count, `[1..13]` per case, `[14]` successful, `[15]` deleted, `[16]` first
     * unknown and `[17]` first deleted index (-1 when none), `[18]` successful ppm, `[19]`
     * FNV-1a digest; then per value `case | successful << 4 | deleted << 5`.
     *
     * @param  array<array-key, string>  $values
     * @return list<int>
     */
    public static function classifyOutcomeBatch(array $values): array
    {
        return (self::usesW50RustFfi() ? self::classifyOutcomeBatchNative($values) : null) ?? self::classifyOutcomeBatchPhp($values);
    }

    /**
     * @param  array<array-key, string>  $values
     * @return list<int>
     */
    public static function classifyOutcomeBatchPhp(array $values): array
    {
        $count = \count($values);
        $out = array_fill(0, self::W50_OUTCOME_HEADER, 0);
        $out[0] = $count;
        $out[16] = $out[17] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        $index = 0;
        foreach ($values as $value) {
            $case = self::W50_CASE_INDEX[$value] ?? 12;
            $successful = $case <= 2 ? 1 : 0;
            $deleted = $case >= 6 && $case <= 8 ? 1 : 0;
            $out[1 + $case]++;
            $out[14] += $successful;
            $out[15] += $deleted;
            if ($case === 12 && $out[16] < 0) {
                $out[16] = $index;
            }
            if ($deleted === 1 && $out[17] < 0) {
                $out[17] = $index;
            }
            $row = $case | $successful << 4 | $deleted << 5;
            $digest = (($digest ^ $row) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $index++;
        }
        if ($count > 0) {
            $out[18] = intdiv($out[14] * 1_000_000, $count);
        }
        $out[19] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $values
     * @return list<int>|null
     */
    public static function classifyOutcomeBatchNative(array $values): ?array
    {
        $values = array_values($values);
        $count = \count($values);
        $fields = self::W50_OUTCOME_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W50_MAX_ITEMS ? self::w50RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w50Strings($values);
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w506_processing_outcome_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $unpacked = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $unpacked === false ? null : array_values($unpacked);
    }

    /**
     * Wave 50.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W50_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w50', self::W50_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w50Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }
}
