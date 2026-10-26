<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Result of a behavioural proxy-fetch analysis.
 *
 * @property array<string, int> $reasons Per-signal score contributions.
 */
final readonly class ProxyVerdict
{
    /**
     * Rust candidates for `allow()` and the `fromScore()` block decision. They stay
     * opt-in: neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchPageProxySortDropdownParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_proxy_verdict_allow(int32_t *out_block, int64_t *out_score);
        int32_t nntmux_proxy_verdict_should_block(int64_t score, int64_t threshold);
        C;

    /**
     * @param  array<string, int>  $reasons
     */
    public function __construct(
        public bool $shouldBlock,
        public int $score,
        public array $reasons,
    ) {}

    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::rustEnabled($useRustFfi);
    }

    /**
     * Cached search-domain FFI handle; `false` records a missing library.
     */
    private static function ffiState(bool $assign = false, FFI|false|null $ffi = null): FFI|false|null
    {
        static $state = null;
        if ($assign) {
            $state = $ffi;
        }

        return $state;
    }

    public static function setSearchFfi(FFI|false|null $ffi): void
    {
        self::ffiState(true, $ffi);
    }

    public static function searchFfi(): ?FFI
    {
        if (self::ffiState() === null) {
            self::ffiState(true, RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false);
        }
        $ffi = self::ffiState();

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * A verdict that never blocks (used when detection is disabled).
     */
    public static function allow(): self
    {
        return self::rustEnabled() ? self::allowNative() : self::allowPhp();
    }

    public static function allowPhp(): self
    {
        return new self(false, 0, []);
    }

    public static function allowNative(): self
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::allowPhp();
        }

        $block = $ffi->new('int32_t[1]');
        $score = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $reasons = $ffi->nntmux_proxy_verdict_allow($block, $score);

        return $reasons === 0 ? new self($block[0] === 1, (int) $score[0], []) : self::allowPhp();
    }

    /**
     * Normalize a scored analysis into a verdict: block once the score reaches the
     * threshold.
     *
     * @param  array<string, int>  $reasons
     */
    public static function fromScore(int $score, int $threshold, array $reasons): self
    {
        return self::rustEnabled()
            ? self::fromScoreNative($score, $threshold, $reasons)
            : self::fromScorePhp($score, $threshold, $reasons);
    }

    /**
     * @param  array<string, int>  $reasons
     */
    public static function fromScorePhp(int $score, int $threshold, array $reasons): self
    {
        return new self($score >= $threshold, $score, $reasons);
    }

    /**
     * @param  array<string, int>  $reasons
     */
    public static function fromScoreNative(int $score, int $threshold, array $reasons): self
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::fromScorePhp($score, $threshold, $reasons);
        }

        // @phpstan-ignore method.notFound
        $block = $ffi->nntmux_proxy_verdict_should_block($score, $threshold);

        return $block === 0 || $block === 1
            ? new self($block === 1, $score, $reasons)
            : self::fromScorePhp($score, $threshold, $reasons);
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W48 parity test). */
    public const bool W48_AGGREGATE_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_proxy_verdict_aggregate_batch(const char *ips, size_t ips_len, const char *reasons, size_t reasons_len, size_t count, int64_t threshold, char *out, size_t cap);
        C;

    private const string W48_REASON_NAME_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_';

    /**
     * Slot 0 holds the Wave 48 toggle, slot 1 the cached `cleaning_w48` handle (`false` when
     * missing); readonly classes cannot declare static properties.
     */
    private static function w48State(int $slot, bool $assign = false, mixed $value = null): mixed
    {
        static $state = [null, null];
        if ($assign) {
            $state[$slot] = $value;
        }

        return $state[$slot];
    }

    /** Null restores the benchmark default; setUseRustFfi() is untouched. */
    public static function useW48AggregateRustFfi(?bool $enabled = true): void
    {
        self::w48State(0, true, $enabled);
    }

    public static function usesW48AggregateRustFfi(): bool
    {
        $enabled = self::w48State(0);

        return \is_bool($enabled) ? $enabled : self::W48_AGGREGATE_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW48AggregateRustFfi(): void
    {
        self::w48State(0, true);
        self::w48State(1, true);
    }

    /**
     * Batch verdict aggregation over `name:points,...` reason specs (`[a-z0-9_]{1,32}` names,
     * `-?\d{1,4}` points, later duplicates replace earlier ones). Three words per item:
     * `score | blocked << 10 | confidence << 11 | reasons << 18 | invalid << 26 |
     * (topPoints + 10000) << 34` (score clamps to 0..1000, confidence is the score's percent of
     * a positive threshold), the `/24` IPv4 (`1 << 48 | a.b.c`) or `/48` IPv6 (`2 << 48 | first
     * six bytes`) subnet key or -1, and `peers | blockedPeers << 16 | crc32(topReason) << 32`
     * for the batch members sharing that subnet. A final word holds the FNV-1a 64 digest of the
     * item words.
     *
     * @param  list<string>  $clientIps
     * @param  list<string>  $reasonSpecs  Parallel to $clientIps.
     * @return list<int>
     */
    public static function aggregateVerdicts(array $clientIps, array $reasonSpecs, int $threshold): array
    {
        return self::usesW48AggregateRustFfi()
            ? self::aggregateVerdictsNative($clientIps, $reasonSpecs, $threshold)
            : self::aggregateVerdictsPhp($clientIps, $reasonSpecs, $threshold);
    }

    /**
     * @param  list<string>  $clientIps
     * @param  list<string>  $reasonSpecs
     * @return list<int>
     */
    public static function aggregateVerdictsPhp(array $clientIps, array $reasonSpecs, int $threshold): array
    {
        $items = $groups = [];
        foreach ($clientIps as $i => $ip) {
            $reasons = [];
            $invalid = 0;
            foreach (explode(',', $reasonSpecs[$i] ?? '') as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $colon = strpos($part, ':');
                $name = $colon === false ? '' : substr($part, 0, $colon);
                $points = $colon === false ? '' : substr($part, $colon + 1);
                $digits = str_starts_with($points, '-') ? substr($points, 1) : $points;
                if ($name === '' || \strlen($name) > 32 || strspn($name, self::W48_REASON_NAME_CHARS) !== \strlen($name)
                    || $digits === '' || \strlen($digits) > 4 || strspn($digits, '0123456789') !== \strlen($digits)) {
                    $invalid++;

                    continue;
                }
                $reasons[$name] = (int) $points;
            }
            $score = max(0, min(1000, array_sum($reasons)));
            $blocked = $score >= $threshold;
            $confidence = $threshold > 0 ? min(100, intdiv($score * 100, $threshold)) : 100;
            $topName = null;
            $topPoints = 0;
            foreach ($reasons as $name => $points) {
                if ($topName === null || $points > $topPoints) {
                    $topName = (string) $name;
                    $topPoints = $points;
                }
            }
            $key = self::w48Subnet($ip);
            if ($key !== null) {
                $groups[$key] ??= [0, 0];
                $groups[$key][0]++;
                $groups[$key][1] += (int) $blocked;
            }
            $items[] = [
                $score | (int) $blocked << 10 | $confidence << 11 | min(255, \count($reasons)) << 18
                    | min(255, $invalid) << 26 | ($topPoints + 10000) << 34,
                $key,
                $topName === null ? 0 : crc32($topName),
            ];
        }
        $out = [];
        foreach ($items as [$word, $key, $crc]) {
            [$peers, $blockedPeers] = $key === null ? [0, 0] : $groups[$key];
            $out[] = $word;
            $out[] = $key ?? -1;
            $out[] = min(65535, $peers) | min(65535, $blockedPeers) << 16 | $crc << 32;
        }
        $out[] = self::w48Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a value holds a NUL byte.
     *
     * @param  list<string>  $clientIps
     * @param  list<string>  $reasonSpecs
     * @return list<int>
     */
    public static function aggregateVerdictsNative(array $clientIps, array $reasonSpecs, int $threshold): array
    {
        $count = \count($clientIps);
        $ffi = $count === 0 ? null : self::w48Ffi();
        $ips = implode("\0", $clientIps);
        $specs = implode("\0", $reasonSpecs);
        if ($ffi === null || \count($reasonSpecs) !== $count
            || substr_count($ips, "\0") !== $count - 1 || substr_count($specs, "\0") !== $count - 1) {
            return self::aggregateVerdictsPhp($clientIps, $reasonSpecs, $threshold);
        }
        $bytes = ($count * 3 + 1) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::aggregateVerdictsPhp($clientIps, $reasonSpecs, $threshold);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_proxy_verdict_aggregate_batch($ips, \strlen($ips), $specs, \strlen($specs), $count, $threshold, $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::aggregateVerdictsPhp($clientIps, $reasonSpecs, $threshold);
    }

    private static function w48Ffi(): ?FFI
    {
        $ffi = self::w48State(1);
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning_w48', self::W48_FFI_CDEF) ?? false;
            self::w48State(1, true, $ffi);
        }

        return $ffi instanceof FFI ? $ffi : null;
    }

    /**
     * `/24` IPv4 or `/48` IPv6 key of a `filter_var()`-valid IP, otherwise null.
     */
    private static function w48Subnet(string $ip): ?int
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (\strlen($packed) === 4) {
            return 1 << 48 | \ord($packed[0]) << 16 | \ord($packed[1]) << 8 | \ord($packed[2]);
        }
        /** @var array{1: int} $prefix */
        $prefix = unpack('J', "\0\0".substr($packed, 0, 6));

        return 2 << 48 | $prefix[1];
    }

    /**
     * FNV-1a 64 of the little-endian item words, as a signed int.
     *
     * @param  list<int>  $words
     */
    private static function w48Digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$words), true));

        return $digest[1];
    }

    // Rust is the default only while the paired W59.6 benchmark clears 1.5x on all four metrics.
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        int32_t nntmux_w596_proxy_verdict_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t threshold, int64_t *out, size_t out_cap);
        C;

    private const int W59_MAX_ITEMS = 1_048_576;

    public const int W59_VERDICT_HEADER = 18;

    /** Known reason names and their flag bits. */
    private const array W59_REASON_BITS = ['referer' => 0, 'ua_pair' => 1, 'download_ratio' => 2, 'ip_correlation' => 3,
        'indexer_ua' => 4, 'empty_ua' => 5, 'private_ip' => 6, 'local_referer' => 7];

    /**
     * Verdict rollup over scores with comma-separated reason names (blank-trimmed; empty names skipped).
     * The score clamps to 0..1000; class 0 allow, 1 suspicious (twice the score reaches the threshold), 2
     * block; confidence is the score's percent of a positive threshold (100 otherwise).
     *
     * Layout: `[0]` count, `[1..3]` per class, `[4..11]` per known reason, `[12]` max score, `[13]` score
     * sum, `[14]` first blocked index (-1), `[15]` mean confidence, `[16]` out-of-range scores, `[17]`
     * FNV-1a digest; then per verdict `score | class << 10 | confidence << 12 | reasons << 19 |
     * min(unknown, 255) << 27 | min(duplicates, 255) << 35 | min(names, 255) << 43 | out of range << 51`.
     *
     * @param  array<array-key, int>  $scores
     * @param  array<array-key, string>  $reasonSpecs  Parallel to $scores.
     * @return list<int>
     */
    public static function rollupVerdictBatch(array $scores, array $reasonSpecs, int $threshold = 50): array
    {
        return (self::usesW59RustFfi() ? self::rollupVerdictBatchNative($scores, $reasonSpecs, $threshold) : null)
            ?? self::rollupVerdictBatchPhp($scores, $reasonSpecs, $threshold);
    }

    /**
     * @param  array<array-key, int>  $scores
     * @param  array<array-key, string>  $reasonSpecs
     * @return list<int>
     */
    public static function rollupVerdictBatchPhp(array $scores, array $reasonSpecs, int $threshold = 50): array
    {
        $scores = array_values($scores);
        $reasonSpecs = array_values($reasonSpecs);
        $count = \count($scores);
        $out = array_fill(0, self::W59_VERDICT_HEADER, 0);
        $out[0] = $count;
        $out[14] = -1;
        $digest = 2_166_136_261;
        $confidenceSum = 0;
        $rows = [];
        foreach ($scores as $at => $raw) {
            $raw = (int) $raw;
            $score = max(0, min(1_000, $raw));
            $class = $score >= $threshold ? 2 : ($score * 2 >= $threshold ? 1 : 0);
            $confidence = $threshold > 0 ? min(100, intdiv($score * 100, $threshold)) : 100;
            $mask = $unknown = $duplicates = $names = 0;
            foreach (explode(',', (string) ($reasonSpecs[$at] ?? '')) as $part) {
                $name = trim($part, " \t");
                if ($name === '') {
                    continue;
                }
                $names++;
                $bit = self::W59_REASON_BITS[$name] ?? null;
                if ($bit === null) {
                    $unknown++;
                } elseif (($mask & 1 << $bit) !== 0) {
                    $duplicates++;
                } else {
                    $mask |= 1 << $bit;
                }
            }
            $out[1 + $class]++;
            if ($mask !== 0) {
                for ($bit = 0; $bit < 8; $bit++) {
                    $out[4 + $bit] += ($mask >> $bit) & 1;
                }
            }
            $out[12] = max($out[12], $score);
            $out[13] += $score;
            if ($class === 2 && $out[14] < 0) {
                $out[14] = $at;
            }
            $confidenceSum += $confidence;
            $out[16] += (int) ($raw !== $score);
            $row = $score | $class << 10 | $confidence << 12 | $mask << 19 | min($unknown, 255) << 27 | min($duplicates, 255) << 35
                | min($names, 255) << 43 | (int) ($raw !== $score) << 51;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[15] = intdiv($confidenceSum, $count);
        }
        $out[17] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust rollup, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $scores
     * @param  array<array-key, string>  $reasonSpecs
     * @return list<int>|null
     */
    public static function rollupVerdictBatchNative(array $scores, array $reasonSpecs, int $threshold = 50): ?array
    {
        $count = \count($scores);
        if ($count > self::W59_MAX_ITEMS || \count($reasonSpecs) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w59Strings(array_values($reasonSpecs));
        $ints = pack('q*', ...array_map(intval(...), array_values($scores)));
        $fields = self::W59_VERDICT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w59Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w596_proxy_verdict_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $threshold, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 59.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    public static function usesW59RustFfi(): bool
    {
        $forced = self::w59RustFfiState();

        return \is_bool($forced) ? $forced : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 59.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w59` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w59', self::W59_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 59.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w59Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w59RustFfiState('binding', $fields);
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
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w59Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
