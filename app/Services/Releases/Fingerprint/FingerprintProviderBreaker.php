<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Cross-job breaker for an unreachable alternate NNTP provider, shared by every fingerprint collector.
 */
final class FingerprintProviderBreaker
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_fingerprint_breaker_key(const char *name, size_t name_len, const char *port, size_t port_len, uint8_t tls, const char *state, size_t state_len, const char *generation, size_t generation_len, char *out, size_t out_cap);
        int64_t nntmux_fingerprint_breaker_suspect_seconds(int64_t window_seconds, int64_t cooldown_seconds);
        C;

    /**
     * Wave 47.2 production default for simulateBatch() is Rust: the paired benchmark in
     * tests/Unit/RustFingerprintDispatchBreakerParserAnalyzerCollectorW47ParityTest.php measures 2.68-2.85x mean and
     * 2.20-2.58x p99 on common batches and 1.99-2.19x mean and 1.68-1.92x p99 on uncommon batches. PHP stays the
     * fallback when the library is unavailable or a time or span is beyond the native limits.
     */
    public const bool W47_BREAKER_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_fingerprint_breaker_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t window_seconds, int64_t cooldown_seconds, char *out_buf, size_t out_cap);
        C;

    /** Simulated events: skipReason(), recordUnavailable(), recordReachable() and releaseProbe(). */
    public const int W47_CHECK = 0;

    public const int W47_FAILURE = 1;

    public const int W47_SUCCESS = 2;

    public const int W47_RELEASE = 3;

    /** The native simulation declines times beyond +/-2^52 and spans beyond +/-2^40 so every deadline stays an int. */
    public const int W47_MAX_TIME = 4503599627370496;

    public const int W47_MAX_SPAN = 1099511627776;

    private const array W47_BREAKER_KEYS = ['total', 'invalid', 'out_of_order', 'providers', 'allowed', 'skipped_recent', 'skipped_probe', 'claims',
        'failures', 'recoveries', 'released', 'ignored', 'open', 'suspect', 'max_window_failures', 'failure_rate_permille', 'final_time'];

    private static bool $useRustFfi = false;

    private static ?bool $w47BreakerRustFfiOverride = null;

    private static FFI|false|null $w47BreakerRustFfiHandle = null;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $scratch = null;

    private static int $scratchCap = 0;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function scratch(FFI $ffi, int $minBytes): ?FFI\CData
    {
        if (self::$scratch === null || self::$scratchCap < $minBytes) {
            self::$scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, self::$scratchCap);
        }

        return self::$scratch;
    }

    /** @var array<string, string> Failure generations whose re-probe this instance claimed, keyed by provider. */
    private array $claims = [];

    /**
     * Null when this job should probe the alternate. Each failure starts a new generation: during its
     * window every job skips; afterwards one job claims the re-probe until it succeeds (marking that
     * generation recovered) or fails (starting the next). Keys are only ever added per generation, so
     * a late success or claim from an older generation cannot unfence a newer failure.
     *
     * @param  array{name: string, port: string, tls: bool}  $server
     */
    public function skipReason(array $server): ?string
    {
        unset($this->claims[self::key($server)]);
        if (self::windowSeconds() <= 0) {
            return null;
        }
        try {
            $generation = Cache::get(self::key($server));
            if (! is_string($generation) || $generation === ''
                || Cache::has(self::key($server, 'recovered', $generation))) {
                return null;
            }
            if (Cache::has(self::key($server, 'open', $generation))) {
                return 'recent_provider_failure';
            }
            if (! Cache::add(self::key($server, 'probe', $generation), true, self::probeSeconds())) {
                return 'provider_probe_in_progress';
            }
            $this->claims[self::key($server)] = $generation;
        } catch (Throwable) {
            // Without the cache every job probes the provider itself, as before.
        }

        return null;
    }

    /** @param array{name: string, port: string, tls: bool} $server */
    public function recordUnavailable(array $server): void
    {
        unset($this->claims[self::key($server)]);
        $seconds = self::windowSeconds();
        if ($seconds <= 0) {
            return;
        }
        try {
            $generation = bin2hex(random_bytes(16));
            Cache::put(self::key($server, 'open', $generation), true, $seconds);
            Cache::put(self::key($server), $generation, self::suspectSeconds());
        } catch (Throwable) {
            // Without the cache every job probes the provider itself, as before.
        }
    }

    /** @param array{name: string, port: string, tls: bool} $server */
    public function recordReachable(array $server): void
    {
        $generation = $this->claims[self::key($server)] ?? null;
        unset($this->claims[self::key($server)]);
        if ($generation === null) {
            return;
        }
        try {
            Cache::put(self::key($server, 'recovered', $generation), true, self::suspectSeconds());
        } catch (Throwable) {
            // The claim expires with the job timeout and another job re-probes.
        }
    }

    /** @param array{name: string, port: string, tls: bool} $server */
    public function releaseProbe(array $server): void
    {
        $generation = $this->claims[self::key($server)] ?? null;
        unset($this->claims[self::key($server)]);
        if ($generation === null) {
            return;
        }
        try {
            // Only this job could claim this generation, so dropping its claim affects no other job.
            Cache::forget(self::key($server, 'probe', $generation));
        } catch (Throwable) {
            // The claim expires with the job timeout.
        }
    }

    private static function suspectSeconds(): int
    {
        return (self::$useRustFfi ? self::suspectSecondsNative() : null)
            ?? self::suspectSecondsPhp();
    }

    public static function suspectSecondsPhp(?int $windowSeconds = null, int $cooldownSeconds = 0): int
    {
        $window = $windowSeconds ?? self::windowSeconds();
        if ($cooldownSeconds > 0) {
            return min($cooldownSeconds, max(30, $window));
        }

        return max(3600, $window * 4);
    }

    public static function suspectSecondsNative(?int $windowSeconds = null, int $cooldownSeconds = 0): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $window = $windowSeconds ?? self::windowSeconds();
        try {
            // @phpstan-ignore method.notFound
            $seconds = $ffi->nntmux_fingerprint_breaker_suspect_seconds($window, $cooldownSeconds);
            if (! is_int($seconds) || $seconds < 0) {
                return null;
            }

            return $seconds;
        } catch (Throwable) {
            return null;
        }
    }

    private static function windowSeconds(): int
    {
        return (int) config('nntmux_nntp.fingerprint_provider_breaker_seconds', 900);
    }

    /**
     * A connect can retry many times, so the claim lasts as long as the job may run. A finished probe
     * releases it at once; only a crashed prober leaves it to expire.
     */
    private static function probeSeconds(): int
    {
        return CollectReleaseFingerprintMetadata::TIMEOUT_SECONDS;
    }

    /** @param array{name: string, port: string, tls: bool} $server */
    private static function key(array $server, string $state = 'generation', string $generation = ''): string
    {
        return (self::$useRustFfi ? self::keyNative($server, $state, $generation) : null)
            ?? self::keyPhp($server, $state, $generation);
    }

    /** @param array{name: string, port: string, tls: bool} $server */
    public static function keyPhp(array $server, string $state = 'generation', string $generation = ''): string
    {
        return 'release-fingerprint:provider-breaker:'.sha1(strtolower($server['name']).'|'.$server['port'].'|'.($server['tls'] ? 'tls' : 'plain'))
            .':'.$state.($generation === '' ? '' : ':'.$generation);
    }

    /** @param array{name: string, port: string, tls: bool} $server */
    public static function keyNative(array $server, string $state = 'generation', string $generation = ''): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $name = $server['name'];
        $port = $server['port'];
        $tls = $server['tls'] ? 1 : 0;
        try {
            $scratch = self::scratch($ffi, max(256, strlen($state) + strlen($generation) + 128));
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fingerprint_breaker_key(
                $name,
                strlen($name),
                $port,
                strlen($port),
                $tls,
                $state,
                strlen($state),
                $generation,
                strlen($generation),
                $scratch,
                $cap
            );
            if (! is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Force the Wave 47.2 simulation onto Rust (true) or PHP (false); null restores W47_BREAKER_RUST_FFI_DEFAULT. */
    public static function useW47BreakerRustFfi(?bool $enable = true): void
    {
        self::$w47BreakerRustFfiOverride = $enable;
    }

    public static function usesW47BreakerRustFfi(): bool
    {
        return self::$w47BreakerRustFfiOverride ?? self::W47_BREAKER_RUST_FFI_DEFAULT;
    }

    public static function resetW47BreakerRustFfi(): void
    {
        self::$w47BreakerRustFfiOverride = null;
        self::$w47BreakerRustFfiHandle = null;
    }

    /**
     * Replay a batch of breaker events (event $i is $events[$i] for provider $providers[$i] at $times[$i]) against
     * one in-memory model of the cache keys above, as if every event came from the same job. A check before a
     * provider's first failure, after its generation expires (suspectSecondsPhp()) or once it recovered is allowed;
     * within the failure window it is skipped as recent, while another claim lasts ($probeSeconds) as in progress,
     * and otherwise it claims the re-probe. A success or release only applies to the claim of the latest check.
     * Unknown events are invalid; a time earlier than the previous event's counts as out of order and replays at the
     * previous time. A window of zero or less disables the breaker. Also reports, at the final time, providers still
     * open or suspect (awaiting a re-probe), the most failures within any window, and the failure rate per mille.
     *
     * @param  list<int>  $providers
     * @param  list<int>  $times
     * @param  list<int>  $events
     * @return array{total: int, invalid: int, out_of_order: int, providers: int, allowed: int, skipped_recent: int, skipped_probe: int, claims: int, failures: int, recoveries: int, released: int, ignored: int, open: int, suspect: int, max_window_failures: int, failure_rate_permille: int, final_time: int}
     *
     * @throws InvalidArgumentException when the three lists differ in length
     */
    public static function simulateBatch(array $providers, array $times, array $events, int $windowSeconds, int $cooldownSeconds = 0,
        int $probeSeconds = CollectReleaseFingerprintMetadata::TIMEOUT_SECONDS): array
    {
        return (self::usesW47BreakerRustFfi() ? self::simulateBatchNative($providers, $times, $events, $windowSeconds, $cooldownSeconds, $probeSeconds) : null)
            ?? self::simulateBatchPhp($providers, $times, $events, $windowSeconds, $cooldownSeconds, $probeSeconds);
    }

    /**
     * @param  list<int>  $providers
     * @param  list<int>  $times
     * @param  list<int>  $events
     * @return array{total: int, invalid: int, out_of_order: int, providers: int, allowed: int, skipped_recent: int, skipped_probe: int, claims: int, failures: int, recoveries: int, released: int, ignored: int, open: int, suspect: int, max_window_failures: int, failure_rate_permille: int, final_time: int}
     */
    public static function simulateBatchPhp(array $providers, array $times, array $events, int $windowSeconds, int $cooldownSeconds = 0,
        int $probeSeconds = CollectReleaseFingerprintMetadata::TIMEOUT_SECONDS): array
    {
        $count = \count($providers);
        self::w47AssertSameLength($count, $times, $events);
        $window = $windowSeconds;
        $suspect = self::suspectSecondsPhp($window, $cooldownSeconds);
        $times = array_values($times);
        $events = array_values($events);
        // invalid, out_of_order, allowed, skipped_recent, skipped_probe, claims, failures, recoveries, released, ignored
        $c = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $maxWindow = 0;
        $last = null;
        // Per provider: failure generation, the open/suspect/probe/recovered deadlines, the latest check's claim and
        // the failure times still inside the window (from $head on).
        $generation = $open = $suspectUntil = $probe = $recovered = $claimed = $failures = $head = [];
        foreach (array_values($providers) as $i => $provider) {
            $kind = $events[$i];
            if ($kind < self::W47_CHECK || $kind > self::W47_RELEASE) {
                $c[0]++;

                continue;
            }
            $t = $times[$i];
            if ($last !== null && $t < $last) {
                $c[1]++;
                $t = $last;
            }
            $last = $t;
            if (! isset($generation[$provider])) {
                $generation[$provider] = $head[$provider] = 0;
                $open[$provider] = $suspectUntil[$provider] = $probe[$provider] = $recovered[$provider] = PHP_INT_MIN;
                $claimed[$provider] = false;
                $failures[$provider] = [];
            }
            if ($kind === self::W47_CHECK) {
                $claimed[$provider] = false;
                if ($window <= 0 || $generation[$provider] === 0 || $t >= $suspectUntil[$provider] || $t < $recovered[$provider]) {
                    $c[2]++;
                } elseif ($t < $open[$provider]) {
                    $c[3]++;
                } elseif ($t < $probe[$provider]) {
                    $c[4]++;
                } else {
                    $probe[$provider] = $t + $probeSeconds;
                    $claimed[$provider] = true;
                    $c[5]++;
                    $c[2]++;
                }
            } elseif ($kind === self::W47_FAILURE) {
                $c[6]++;
                $claimed[$provider] = false;
                if ($window > 0) {
                    $generation[$provider]++;
                    $open[$provider] = $t + $window;
                    $suspectUntil[$provider] = $t + $suspect;
                    $recovered[$provider] = $probe[$provider] = PHP_INT_MIN;
                    $failures[$provider][] = $t;
                    while ($failures[$provider][$head[$provider]] <= $t - $window) {
                        $head[$provider]++;
                    }
                    $maxWindow = max($maxWindow, \count($failures[$provider]) - $head[$provider]);
                }
            } elseif (! $claimed[$provider]) {
                $c[9]++;
            } elseif ($kind === self::W47_SUCCESS) {
                $recovered[$provider] = $t + $suspect;
                $claimed[$provider] = false;
                $c[7]++;
            } else {
                $probe[$provider] = PHP_INT_MIN;
                $claimed[$provider] = false;
                $c[8]++;
            }
        }
        $now = $last ?? 0;
        $openAtEnd = $suspectAtEnd = 0;
        foreach ($generation as $provider => $failed) {
            if ($failed === 0 || $now >= $suspectUntil[$provider] || $now < $recovered[$provider]) {
                continue;
            }
            if ($now < $open[$provider]) {
                $openAtEnd++;
            } else {
                $suspectAtEnd++;
            }
        }
        $valid = $count - $c[0];

        return self::w47BreakerSummary([$count, $c[0], $c[1], \count($generation), ...\array_slice($c, 2), $openAtEnd, $suspectAtEnd, $maxWindow,
            $valid > 0 ? intdiv($c[6] * 1000, $valid) : 0, $now]);
    }

    /**
     * Null when the library is missing, a time or span is beyond the native limits or the batch is too large.
     *
     * @param  list<int>  $providers
     * @param  list<int>  $times
     * @param  list<int>  $events
     * @return array{total: int, invalid: int, out_of_order: int, providers: int, allowed: int, skipped_recent: int, skipped_probe: int, claims: int, failures: int, recoveries: int, released: int, ignored: int, open: int, suspect: int, max_window_failures: int, failure_rate_permille: int, final_time: int}|null
     */
    public static function simulateBatchNative(array $providers, array $times, array $events, int $windowSeconds, int $cooldownSeconds = 0,
        int $probeSeconds = CollectReleaseFingerprintMetadata::TIMEOUT_SECONDS): ?array
    {
        $count = \count($providers);
        self::w47AssertSameLength($count, $times, $events);
        $ffi = self::w47BreakerRustFfi();
        if ($ffi === null || $count > FingerprintDispatch::W47_MAX_ITEMS) {
            return null;
        }
        $blob = pack('q*', ...array_values($providers), ...array_values($times), ...array_values($events));
        $values = FingerprintDispatch::callW47Native($ffi, 'nntmux_w47_fingerprint_breaker_batch', $blob, pack('q', $probeSeconds), $windowSeconds,
            $cooldownSeconds, \count(self::W47_BREAKER_KEYS));
        if ($values === null || $values[0] !== $count || min(\array_slice($values, 0, 16)) < 0) {
            return null;
        }

        return self::w47BreakerSummary($values);
    }

    /**
     * @param  array<array-key, mixed>  ...$lists
     *
     * @throws InvalidArgumentException
     */
    private static function w47AssertSameLength(int $count, array ...$lists): void
    {
        foreach ($lists as $list) {
            if (\count($list) !== $count) {
                throw new InvalidArgumentException('Wave 47.2 batch lists must have the same length.');
            }
        }
    }

    /**
     * @param  list<int>  $values
     * @return array{total: int, invalid: int, out_of_order: int, providers: int, allowed: int, skipped_recent: int, skipped_probe: int, claims: int, failures: int, recoveries: int, released: int, ignored: int, open: int, suspect: int, max_window_failures: int, failure_rate_permille: int, final_time: int}
     */
    private static function w47BreakerSummary(array $values): array
    {
        return array_combine(self::W47_BREAKER_KEYS, $values);
    }

    private static function w47BreakerRustFfi(): ?FFI
    {
        self::$w47BreakerRustFfiHandle ??= RustPipelineLibrary::resolveDomain(FingerprintDispatch::W47_FFI_DOMAIN, self::W47_FFI_CDEF) ?? false;

        return self::$w47BreakerRustFfiHandle === false ? null : self::$w47BreakerRustFfiHandle;
    }
}
