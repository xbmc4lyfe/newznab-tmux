<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Collection filecheck status values used during release processing.
 *
 * These statuses track the progress of collections through the release
 * creation pipeline, from initial discovery to NZB file creation.
 */
enum CollectionFileCheckStatus: int
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_collection_file_check_description(int64_t status, char *out, size_t cap);
        int32_t nntmux_collection_file_check_classify(int64_t status);
        ssize_t nntmux_collection_file_check_list_values(int32_t kind, int64_t *out_values, size_t cap);
        C;

    /** Default status for newly discovered collections */
    case Default = 0;

    /** Collection has all expected binary files */
    case CompleteCollection = 1;

    /** All parts of binaries are complete */
    case CompleteParts = 2;

    /** Collection size has been calculated */
    case Sized = 3;

    /** Collection has been converted to a release */
    case Inserted = 4;

    /** Collection marked for deletion */
    case Delete = 5;

    /** Temporary complete status during processing */
    case TempComplete = 15;

    /** Collection has zero-numbered parts (special handling) */
    case ZeroPart = 16;

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

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
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
            $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * Get the human-readable description for this status.
     */
    public function description(): string
    {
        return self::usesRustFfi()
            ? $this->descriptionNative()
            : $this->descriptionPhp();
    }

    public function descriptionNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->descriptionPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return $this->descriptionPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collection_file_check_description($this->value, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->descriptionPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_collection_file_check_description($this->value, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->descriptionPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->descriptionPhp();
        }
    }

    public function descriptionPhp(): string
    {
        return match ($this) {
            self::Default => 'Newly discovered',
            self::CompleteCollection => 'Complete collection',
            self::CompleteParts => 'Complete parts',
            self::Sized => 'Size calculated',
            self::Inserted => 'Inserted as release',
            self::Delete => 'Marked for deletion',
            self::TempComplete => 'Temporarily complete',
            self::ZeroPart => 'Zero-part collection',
        };
    }

    /**
     * Check if this status indicates processing is complete.
     */
    public function isProcessingComplete(): bool
    {
        return self::usesRustFfi()
            ? $this->isProcessingCompleteNative()
            : $this->isProcessingCompletePhp();
    }

    public function isProcessingCompleteNative(): bool
    {
        if (($ffi = self::ffi()) === null) {
            return $this->isProcessingCompletePhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_collection_file_check_classify($this->value);
            if (\is_int($mask) && $mask >= 0) {
                return ($mask & 1) !== 0;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->isProcessingCompletePhp();
    }

    public function isProcessingCompletePhp(): bool
    {
        return match ($this) {
            self::Inserted, self::Delete => true,
            default => false,
        };
    }

    /**
     * Get statuses that indicate a collection is ready for release creation.
     *
     * @return array<self>
     */
    public static function readyForRelease(): array
    {
        return self::usesRustFfi()
            ? self::readyForReleaseNative()
            : self::readyForReleasePhp();
    }

    /**
     * @return array<self>
     */
    public static function readyForReleaseNative(): array
    {
        return self::nativeStatusList(0, 1) ?? self::readyForReleasePhp();
    }

    /**
     * @return array<self>
     */
    public static function readyForReleasePhp(): array
    {
        return [self::Sized];
    }

    /**
     * Get statuses that indicate a collection is still being processed.
     *
     * @return array<self>
     */
    public static function inProgress(): array
    {
        return self::usesRustFfi()
            ? self::inProgressNative()
            : self::inProgressPhp();
    }

    /**
     * @return array<self>
     */
    public static function inProgressNative(): array
    {
        return self::nativeStatusList(1, 5) ?? self::inProgressPhp();
    }

    /**
     * @return array<self>
     */
    public static function inProgressPhp(): array
    {
        return [
            self::Default,
            self::CompleteCollection,
            self::CompleteParts,
            self::TempComplete,
            self::ZeroPart,
        ];
    }

    /**
     * @return array<self>|null
     */
    private static function nativeStatusList(int $kind, int $expectedCount): ?array
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            $outValues = $ffi->new("int64_t[{$expectedCount}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collection_file_check_list_values($kind, $outValues, $expectedCount);
            if (! \is_int($written) || $written !== $expectedCount) {
                return null;
            }
            $result = [];
            for ($i = 0; $i < $expectedCount; $i++) {
                $case = self::tryFrom((int) $outValues[$i]);
                if ($case === null) {
                    return null;
                }
                $result[] = $case;
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    // Rust is the default only while the paired W61.6 benchmark clears 1.5x on all four metrics.
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_FILECHECK_FFI_CDEF = <<<'C'
        int32_t nntmux_w616_collection_filecheck_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t min_bytes, int64_t *out, size_t out_cap);
        C;

    public const int W61_FILECHECK_HEADER = 28;

    private const int W61_MAX_ITEMS = 1_048_576;

    private const int W61_COUNT_CAP = (1 << 20) - 1;

    private const int W61_VALUE_CAP = (1 << 40) - 1;

    private const int W61_TOTAL_CAP = 1 << 62;

    /** Backing value => case index (8 is unknown). */
    private const array W61_INDEX = [0 => 0, 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 15 => 6, 16 => 7];

    /** Backing value per case index. */
    private const array W61_VALUES = [0, 1, 2, 3, 4, 5, 15, 16];

    /** Case name => case index. */
    private const array W61_NAMES = [
        'Default' => 0, 'CompleteCollection' => 1, 'CompleteParts' => 2, 'Sized' => 3, 'Inserted' => 4, 'Delete' => 5, 'TempComplete' => 6, 'ZeroPart' => 7,
    ];

    /** Lowercased case name => case index. */
    private const array W61_LOWER_NAMES = [
        'default' => 0, 'completecollection' => 1, 'completeparts' => 2, 'sized' => 3, 'inserted' => 4, 'delete' => 5, 'tempcomplete' => 6, 'zeropart' => 7,
    ];

    /** Allowed target case indexes per current case index, one bit per case. */
    private const array W61_NEXT = [
        1 << 1 | 1 << 2 | 1 << 5 | 1 << 6 | 1 << 7,
        1 << 2 | 1 << 3 | 1 << 5 | 1 << 6,
        1 << 3 | 1 << 5,
        1 << 4 | 1 << 5,
        1 << 5,
        0,
        1 << 1 | 1 << 2 | 1 << 3 | 1 << 5,
        1 << 1 | 1 << 2 | 1 << 5,
    ];

    /**
     * Plan collection filechecks in order. Statuses resolve by a 1-3 digit backing value (source 0), case name
     * (1), `strtolower(trim($status))` of a case name (2), else unknown case 8 (3). Targets (missing ones -1)
     * give transition 0 none (negative or the same value), 1 allowed, 2 forbidden, 3 unknown target, or 4 for
     * an unknown status. Received and expected file counts clamp to 0..2^20-1 and bytes (and `$minBytes`) to
     * 0..2^40-1. Release-readiness reasons: 1 not sized, 2 no expected files, 4 missing files, 8 below
     * `$minBytes`; a collection is ready without reasons.
     *
     * Layout: `[0]` count, `[1..9]` per case, `[10..13]` per source, `[14..18]` per transition, `[19]` ready,
     * `[20]` in progress, `[21]` processing complete, `[22]` first ready and `[23]` first forbidden index (-1),
     * `[24]` ready bytes, `[25]` received and `[26]` expected files, `[27]` FNV-1a digest; then per collection
     * `case | source << 4 | transition << 6 | reasons << 9 | ready << 13 | in progress << 14 | complete << 15 |
     * permille << 16 | received << 26 | min(MiB, 2^17-1) << 46`.
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $targets
     * @param  array<array-key, int>  $received
     * @param  array<array-key, int>  $expected
     * @param  array<array-key, int>  $bytes
     * @return list<int>
     */
    public static function planFileCheckBatch(array $statuses, array $targets, array $received, array $expected, array $bytes, int $minBytes = 0): array
    {
        return (self::usesW61RustFfi() ? self::planFileCheckBatchNative($statuses, $targets, $received, $expected, $bytes, $minBytes) : null)
            ?? self::planFileCheckBatchPhp($statuses, $targets, $received, $expected, $bytes, $minBytes);
    }

    /**
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $targets
     * @param  array<array-key, int>  $received
     * @param  array<array-key, int>  $expected
     * @param  array<array-key, int>  $bytes
     * @return list<int>
     */
    public static function planFileCheckBatchPhp(array $statuses, array $targets, array $received, array $expected, array $bytes, int $minBytes = 0): array
    {
        $statuses = array_values($statuses);
        $count = \count($statuses);
        $targets = self::w61Column($targets, $count, -1);
        $received = self::w61Column($received, $count);
        $expected = self::w61Column($expected, $count);
        $bytes = self::w61Column($bytes, $count);
        $minBytes = min(max($minBytes, 0), self::W61_VALUE_CAP);
        $out = array_fill(0, self::W61_FILECHECK_HEADER, 0);
        $out[0] = $count;
        $out[22] = -1;
        $out[23] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($statuses as $at => $status) {
            $status = (string) $status;
            $source = 0;
            $case = \strlen($status) <= 3 && ctype_digit($status) ? (self::W61_INDEX[(int) $status] ?? null) : null;
            if ($case === null) {
                $source = 1;
                $case = self::W61_NAMES[$status] ?? null;
                if ($case === null) {
                    $case = self::W61_LOWER_NAMES[strtolower(trim($status))] ?? 8;
                    $source = $case === 8 ? 3 : 2;
                }
            }
            $target = (int) $targets[$at];
            if ($case === 8) {
                $transition = 4;
            } elseif ($target < 0 || $target === self::W61_VALUES[$case]) {
                $transition = 0;
            } else {
                $next = self::W61_INDEX[$target] ?? null;
                $transition = $next === null ? 3 : ((self::W61_NEXT[$case] >> $next & 1) === 1 ? 1 : 2);
            }
            $have = min(max((int) $received[$at], 0), self::W61_COUNT_CAP);
            $want = min(max((int) $expected[$at], 0), self::W61_COUNT_CAP);
            $size = min(max((int) $bytes[$at], 0), self::W61_VALUE_CAP);
            $reasons = ($case !== 3 ? 1 : 0) | ($want === 0 ? 2 : 0) | ($have < $want ? 4 : 0) | ($size < $minBytes ? 8 : 0);
            $ready = $reasons === 0 ? 1 : 0;
            $inProgress = $case <= 2 || $case === 6 || $case === 7 ? 1 : 0;
            $complete = $case === 4 || $case === 5 ? 1 : 0;
            $permille = $want > 0 ? min(intdiv($have * 1_000, $want), 1_000) : 0;
            $out[1 + $case]++;
            $out[10 + $source]++;
            $out[14 + $transition]++;
            $out[19] += $ready;
            $out[20] += $inProgress;
            $out[21] += $complete;
            if ($ready === 1) {
                if ($out[22] < 0) {
                    $out[22] = $at;
                }
                $out[24] = min($out[24] + $size, self::W61_TOTAL_CAP);
            }
            if ($transition === 2 && $out[23] < 0) {
                $out[23] = $at;
            }
            $out[25] += $have;
            $out[26] += $want;
            $row = $case | $source << 4 | $transition << 6 | $reasons << 9 | $ready << 13 | $inProgress << 14 | $complete << 15
                | $permille << 16 | $have << 26 | min($size >> 20, (1 << 17) - 1) << 46;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[27] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $targets
     * @param  array<array-key, int>  $received
     * @param  array<array-key, int>  $expected
     * @param  array<array-key, int>  $bytes
     * @return list<int>|null
     */
    public static function planFileCheckBatchNative(array $statuses, array $targets, array $received, array $expected, array $bytes, int $minBytes = 0): ?array
    {
        $count = \count($statuses);
        if ($count > self::W61_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w61Strings(array_values($statuses));
        $ints = pack('q*', ...self::w61Column($targets, $count, -1), ...self::w61Column($received, $count), ...self::w61Column($expected, $count), ...self::w61Column($bytes, $count));
        $words = self::W61_FILECHECK_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w61Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w616_collection_filecheck_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $minBytes, $out, $words));
    }

    /**
     * Run `$callback` with the Wave 61.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $forced = self::w61RustFfiState();

        return \is_bool($forced) ? $forced : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 61.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'binding'` returns the cached binding and an output buffer of at least `$val` values, or null.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w616', self::W61_FILECHECK_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 61.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w61Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w61RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w61Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w61Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
