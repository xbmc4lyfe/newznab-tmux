<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
