<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Memo shared by every release processed in one pipeline run.
 *
 * Keeps providers from searching the same unknown title or downloading the same
 * show's episode list twice, and trips a per-provider circuit breaker after
 * repeated transient failures.
 */
final class TvProcessingRunState
{
    /** How long a show's episode list counts as fresh across worker processes. */
    public const int EPISODE_REFRESH_TTL = 3600;

    /** Consecutive transient failures before a provider is skipped for the rest of the run. */
    public const int CIRCUIT_BREAKER_THRESHOLD = 3;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_run_state_episode_lookup_key(int32_t kind, const char *provider, size_t provider_len, int64_t video_id, const char *episode_key, size_t episode_key_len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvShowAdderAndMetadataParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** @var array<string, array<string, true>> */
    private array $notFoundTitles = [];

    /** @var array<string, true> */
    private array $refreshedShows = [];

    /** @var array<string, true> */
    private array $episodeLookups = [];

    /** @var array<string, int> */
    private array $consecutiveTransient = [];

    public function isTitleNotFound(string $provider, string $title): bool
    {
        return isset($this->notFoundTitles[$provider][mb_strtolower($title)]);
    }

    public function rememberTitleNotFound(string $provider, string $title): void
    {
        $this->notFoundTitles[$provider][mb_strtolower($title)] = true;
    }

    public function wasEpisodeListRefreshed(string $provider, int $videoId): bool
    {
        $key = $this->refreshKey($provider, $videoId);

        return isset($this->refreshedShows[$key]) || Cache::has($key);
    }

    public function markEpisodeListRefreshed(string $provider, int $videoId): void
    {
        $key = $this->refreshKey($provider, $videoId);
        $this->refreshedShows[$key] = true;
        Cache::put($key, 1, self::EPISODE_REFRESH_TTL);
    }

    /**
     * True the first time a provider asks for this episode of a show in this run, so
     * several releases of one episode don't repeat the same API lookup.
     */
    public function firstEpisodeLookup(string $provider, int $videoId, string $episodeKey): bool
    {
        $key = $this->episodeLookupKey($provider, $videoId, $episodeKey);
        if (isset($this->episodeLookups[$key])) {
            return false;
        }
        $this->episodeLookups[$key] = true;

        return true;
    }

    public function recordTransient(string $provider): void
    {
        $this->consecutiveTransient[$provider] = ($this->consecutiveTransient[$provider] ?? 0) + 1;
    }

    public function recordSuccess(string $provider): void
    {
        $this->consecutiveTransient[$provider] = 0;
    }

    public function isCircuitOpen(string $provider): bool
    {
        return ($this->consecutiveTransient[$provider] ?? 0) >= self::CIRCUIT_BREAKER_THRESHOLD;
    }

    private function refreshKey(string $provider, int $videoId): string
    {
        return self::usesRustFfi()
            ? self::refreshKeyNative($provider, $videoId)
            : self::refreshKeyPhp($provider, $videoId);
    }

    public static function refreshKeyNative(string $provider, int $videoId): string
    {
        return self::nativeKey(0, $provider, $videoId, '') ?? self::refreshKeyPhp($provider, $videoId);
    }

    public static function refreshKeyPhp(string $provider, int $videoId): string
    {
        return 'tv:eps-refreshed:'.mb_strtolower($provider).':'.$videoId;
    }

    private function episodeLookupKey(string $provider, int $videoId, string $episodeKey): string
    {
        return self::usesRustFfi()
            ? self::episodeLookupKeyNative($provider, $videoId, $episodeKey)
            : self::episodeLookupKeyPhp($provider, $videoId, $episodeKey);
    }

    public static function episodeLookupKeyNative(string $provider, int $videoId, string $episodeKey): string
    {
        return self::nativeKey(1, $provider, $videoId, $episodeKey)
            ?? self::episodeLookupKeyPhp($provider, $videoId, $episodeKey);
    }

    public static function episodeLookupKeyPhp(string $provider, int $videoId, string $episodeKey): string
    {
        return $provider.':'.$videoId.':'.$episodeKey;
    }

    private static function nativeKey(int $kind, string $provider, int $videoId, string $episodeKey): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $providerLen = \strlen($provider);
        $episodeKeyLen = \strlen($episodeKey);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, $providerLen + $episodeKeyLen + 64, $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_run_state_episode_lookup_key($kind, $provider, $providerLen, $videoId, $episodeKey, $episodeKeyLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_run_state_episode_lookup_key($kind, $provider, $providerLen, $videoId, $episodeKey, $episodeKeyLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Wave 47.3 batch provider cache-key digest and failure-backoff windows. Defaults to Rust:
     * the paired benchmark cleared 1.5x on common and uncommon mean and p99;
     * useW47RunStateRustFfi(false) selects PHP.
     */
    public const bool W47_RUN_STATE_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_tv_run_state_backoff_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** First backoff window once the circuit breaker trips; it doubles per further failure. */
    public const int W47_BACKOFF_BASE = 60;

    public const int W47_BACKOFF_MAX = 3600;

    /** Timestamps are clamped to [0, 2^52] so window arithmetic stays in integer range. */
    public const int W47_MAX_TIMESTAMP = 1 << 52;

    /** Values written per item, followed by the open count, max remaining and distinct providers. */
    public const int W47_FIELDS_PER_ITEM = 4;

    private static ?bool $w47RustFfiOverride = null;

    private static FFI|false|null $w47RustFfiHandle = null;

    /** Null restores W47_RUN_STATE_RUST_FFI_DEFAULT. */
    public static function useW47RunStateRustFfi(?bool $enable = true): void
    {
        self::$w47RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w47RustFfiHandle = null;
        }
    }

    public static function usesW47RunStateRustFfi(): bool
    {
        return self::$w47RustFfiOverride ?? self::W47_RUN_STATE_RUST_FFI_DEFAULT;
    }

    /**
     * Four values per item: the FNV-1a 32 digest of `lowercase provider:video id:episode key`,
     * the backoff window (0 below CIRCUIT_BREAKER_THRESHOLD failures, then W47_BACKOFF_BASE
     * doubling up to W47_BACKOFF_MAX), the retry time (0 without a window) and the seconds
     * remaining at $now. Then the open-window count, the max remaining and the distinct
     * lowercase provider count. Missing keys read as '' or 0.
     *
     * @param  list<array<string, int|string>>  $items  provider, video_id, episode_key, failures, last_failure_at
     * @return list<int>
     */
    public static function w47BackoffBatch(array $items, int $now): array
    {
        return self::usesW47RunStateRustFfi()
            ? self::w47BackoffBatchNative($items, $now)
            : self::w47BackoffBatchPhp($items, $now);
    }

    /**
     * @param  list<array<string, int|string>>  $items
     * @return list<int>
     */
    public static function w47BackoffBatchNative(array $items, int $now): array
    {
        if (($ffi = self::w47RustFfi()) === null) {
            return self::w47BackoffBatchPhp($items, $now);
        }
        $count = \count($items);
        $providers = array_column($items, 'provider');
        $keys = array_column($items, 'episode_key');
        $videos = array_column($items, 'video_id');
        $failures = array_column($items, 'failures');
        $lasts = array_column($items, 'last_failure_at');
        if (\count($providers) !== $count || \count($keys) !== $count || \count($videos) !== $count
            || \count($failures) !== $count || \count($lasts) !== $count) {
            // Items with missing keys: fill the defaults item by item.
            $providers = $keys = $videos = $failures = $lasts = [];
            foreach ($items as $item) {
                $providers[] = $item['provider'] ?? '';
                $keys[] = $item['episode_key'] ?? '';
                $videos[] = $item['video_id'] ?? 0;
                $failures[] = $item['failures'] ?? 0;
                $lasts[] = $item['last_failure_at'] ?? 0;
            }
        }
        $blob = $count === 0 ? '' : implode("\0", $providers)."\0".implode("\0", $keys);
        if ($count > 0 && substr_count($blob, "\0") !== 2 * $count - 1) {
            return self::w47BackoffBatchPhp($items, $now);
        }
        try {
            $packed = pack('q*', $now, ...$videos, ...$failures, ...$lasts);
            $bytes = ($count * self::W47_FIELDS_PER_ITEM + 3) * 8;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $bytes, $capacity);
            if ($buffer === null) {
                return self::w47BackoffBatchPhp($items, $now);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_tv_run_state_backoff_batch($blob, \strlen($blob), $packed, \strlen($packed), $count, $buffer, $capacity);
            if ($written !== $bytes) {
                return self::w47BackoffBatchPhp($items, $now);
            }
            $values = unpack('P*', FFI::string($buffer, $bytes));
            if ($values === false) {
                return self::w47BackoffBatchPhp($items, $now);
            }

            /** @var list<int> */
            return array_values($values);
        } catch (Throwable) {
            return self::w47BackoffBatchPhp($items, $now);
        }
    }

    /**
     * @param  list<array<string, int|string>>  $items
     * @return list<int>
     */
    public static function w47BackoffBatchPhp(array $items, int $now): array
    {
        $now = max(0, min(self::W47_MAX_TIMESTAMP, $now));
        $out = [];
        $open = 0;
        $maxRemaining = 0;
        $providers = [];
        foreach ($items as $item) {
            $provider = strtolower((string) ($item['provider'] ?? ''));
            $videoId = (int) ($item['video_id'] ?? 0);
            $failures = max(0, (int) ($item['failures'] ?? 0));
            $last = max(0, min(self::W47_MAX_TIMESTAMP, (int) ($item['last_failure_at'] ?? 0)));
            $steps = $failures - self::CIRCUIT_BREAKER_THRESHOLD;
            $window = match (true) {
                $steps < 0 => 0,
                $steps >= 6 => self::W47_BACKOFF_MAX,
                default => self::W47_BACKOFF_BASE << $steps,
            };
            $retryAt = $window > 0 ? $last + $window : 0;
            $remaining = max(0, $retryAt - $now);
            if ($remaining > 0) {
                $open++;
            }
            $maxRemaining = max($maxRemaining, $remaining);
            $providers[$provider] = true;
            array_push(
                $out,
                self::w47Fnv($provider.':'.$videoId.':'.($item['episode_key'] ?? '')),
                $window,
                $retryAt,
                $remaining,
            );
        }
        array_push($out, $open, $maxRemaining, \count($providers));

        return $out;
    }

    private static function w47Fnv(string $bytes): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('N', hash('fnv1a32', $bytes, true));

        return $digest[1];
    }

    private static function w47RustFfi(): ?FFI
    {
        if (self::$w47RustFfiHandle === null) {
            self::$w47RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w47', self::W47_FFI_CDEF) ?? false;
        }

        return self::$w47RustFfiHandle === false ? null : self::$w47RustFfiHandle;
    }
}
