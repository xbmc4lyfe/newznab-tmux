<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
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

    private static bool $useRustFfi = false;

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
}
