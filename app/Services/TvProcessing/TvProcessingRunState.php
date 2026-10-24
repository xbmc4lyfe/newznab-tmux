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
}
