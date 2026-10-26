<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
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

    // ========================================================================
    // WAVE 62.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs reached 2.58x common mean, 2.30x common p99, 2.23x uncommon mean and 2.07x uncommon p99; see RustTvMazeTvdbPipeOutcomeWriterPosterResolverRunStateW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_RUN_STATE_FFI_CDEF = 'ssize_t nntmux_w624_tv_run_state_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per runStatePlanRowBatch() record. */
    public const int W62_ROW_WORDS = 6;

    /** int64 summary words after the runStatePlanRowBatch() records (none for an empty batch). */
    public const int W62_TRAILER_WORDS = 11;

    private const array W62_PROVIDERS = ['tvdb' => 1, 'tvmaze' => 2, 'tmdb' => 3, 'trakt' => 4, 'local db' => 5];

    private const string W62_DIGITS = '0123456789';

    /** Upper clamp for epoch-second timestamps. */
    private const int W62_TIME_MAX = 1 << 40;

    private const int W62_MAX_COUNTER = 1_000_000_000;

    /**
     * TV processing run-state plan rows for [provider, title, episode key, videos id, consecutive
     * transients, refreshed at, now, hits, misses] items (times in epoch seconds; now clamps to
     * 0..2^40, hits and misses to 0..10^9).
     *
     * Titles normalize to `[a-z0-9]` tokens; the attempted-title key is the trimmed, lowercased
     * provider, 0x1F and that title. The refresh and episode lookup keys follow refreshKeyPhp()
     * (ASCII lowercase) and episodeLookupKeyPhp(). Episode keys: 0 empty, 1 `NxM` (1-4 and 1-5
     * digits), 2 a valid date, 3 otherwise. Refresh state: 0 never, 1 within EPISODE_REFRESH_TTL,
     * 2 in the future, 3 stale. Decision: 1 circuit open, 2 nothing to search, 3 search, 4 title
     * already attempted in this batch, 5 refresh the episode list, 6 episode lookup, 7 episode
     * already looked up in this batch, 8 cached.
     *
     * Rows [decision | provider << 4 | refresh state << 8 | episode key class << 10 | flags << 12
     * (1 repeated title, 2 repeated lookup, 4 circuit open, 8 non-ASCII title, 16 future refresh) |
     * title bytes << 20, title hash (0), refresh key hash, lookup key hash, hit rate per mille (-1),
     * refresh age (-1)], then the trailer [decisions 1-8, hits, misses, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function runStatePlanRowBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::runStatePlanRowBatchNative($items) : self::runStatePlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function runStatePlanRowBatchPhp(array $items): array
    {
        self::w62Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $titles = [];
        $lookups = [];
        foreach ($items as [$provider, $title, $episodeKey, $videoId, $transients, $refreshedAt, $now, $hits, $misses]) {
            $name = strtolower(trim($provider));
            $providerClass = $name === '' ? 0 : (self::W62_PROVIDERS[$name] ?? 6);
            $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($title)), ' ');
            $length = \strlen($normalized);
            $titleKey = $name."\x1f".$normalized;
            $titleHash = $length === 0 ? 0 : self::w62Fnv($titleKey);
            $refreshHash = self::w62Fnv('tv:eps-refreshed:'.strtolower($provider).':'.$videoId);
            $lookupKey = $provider.':'.$videoId.':'.$episodeKey;
            $lookupHash = self::w62Fnv($lookupKey);
            $key = trim($episodeKey);
            $x = strpos($key, 'x');
            $rest = $x === false ? 0 : \strlen($key) - $x - 1;
            $episodeClass = match (true) {
                $key === '' => 0,
                $x !== false && $x >= 1 && $x <= 4 && strspn($key, self::W62_DIGITS, 0, $x) === $x && $rest >= 1 && $rest <= 5
                    && strspn($key, self::W62_DIGITS, $x + 1) === $rest => 1,
                self::w62Ymd($key) > 0 => 2,
                default => 3,
            };
            $now = max(0, min(self::W62_TIME_MAX, $now));
            $refreshState = match (true) {
                $refreshedAt <= 0 => 0,
                $refreshedAt > $now => 2,
                $now - $refreshedAt < self::EPISODE_REFRESH_TTL => 1,
                default => 3,
            };
            $age = $refreshedAt > 0 && $refreshedAt <= $now ? $now - $refreshedAt : -1;
            $circuit = $transients >= self::CIRCUIT_BREAKER_THRESHOLD;
            $hits = max(0, min(self::W62_MAX_COUNTER, $hits));
            $misses = max(0, min(self::W62_MAX_COUNTER, $misses));
            $total = $hits + $misses;
            $rate = $total > 0 ? intdiv($hits * 1000, $total) : -1;
            $duplicateTitle = false;
            if ($length > 0) {
                $duplicateTitle = isset($titles[$titleKey]);
                $titles[$titleKey] = true;
            }
            $duplicateLookup = false;
            if (($episodeClass === 1 || $episodeClass === 2) && $videoId > 0) {
                $duplicateLookup = isset($lookups[$lookupKey]);
                $lookups[$lookupKey] = true;
            }
            $decision = match (true) {
                $circuit => 1,
                $videoId <= 0 && $length === 0 => 2,
                $videoId <= 0 => $duplicateTitle ? 4 : 3,
                $refreshState === 0 || $refreshState === 3 => 5,
                $episodeClass === 1 || $episodeClass === 2 => $duplicateLookup ? 7 : 6,
                default => 8,
            };
            $flags = ($duplicateTitle ? 1 : 0) | ($duplicateLookup ? 2 : 0) | ($circuit ? 4 : 0)
                | (mb_check_encoding($title, 'ASCII') ? 0 : 8) | ($refreshState === 2 ? 16 : 0);
            $word = $decision | $providerClass << 4 | $refreshState << 8 | $episodeClass << 10 | $flags << 12 | min(255, $length) << 20;
            array_push($out, $word, $titleHash, $refreshHash, $lookupHash, $rate, $age);
            $summary[$decision - 1]++;
            $summary[8] += $hits;
            $summary[9] += $misses;
            $value = $word ^ $titleHash << 20 ^ $lookupHash;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[10] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function runStatePlanRowBatchNative(array $items): array
    {
        self::w62Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w62Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8));

        return self::w62Call($blob, $twin, $ints, $count, $count * self::W62_ROW_WORDS + self::W62_TRAILER_WORDS)
            ?? self::runStatePlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w62Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W62 TV processing run state plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 9 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])) {
                throw new InvalidArgumentException('W62 TV processing run state plan batch items must be [string provider, string title, string episode key, int videos id, int consecutive transients, int refreshed at, int now, int hits, int misses] lists.');
            }
        }
    }

    public static function usesW62RustFfi(): bool
    {
        $enabled = self::w62RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W62 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('enabled');
        self::w62RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w62Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w62Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w62RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w62', self::W62_RUN_STATE_FFI_CDEF) ?? false;
            self::w62RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w62RustFfiState('out', $out);
                self::w62RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W62_RUN_STATE_FFI_CDEF.)
            $written = $ffi->nntmux_w624_tv_run_state_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w62RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W62 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** A trimmed `YYYY-MM-DD` (or '.'/'/' separated) valid date with a positive year as `YYYYMMDD`, else 0. */
    private static function w62Ymd(string $day): int
    {
        if (\strlen($day) !== 10 || strspn($day, self::W62_DIGITS, 0, 4) !== 4 || strspn($day, self::W62_DIGITS, 5, 2) !== 2
            || strspn($day, self::W62_DIGITS, 8, 2) !== 2) {
            return 0;
        }
        $separator = $day[4];
        if (($separator !== '-' && $separator !== '.' && $separator !== '/') || $day[7] !== $separator) {
            return 0;
        }
        $year = (int) substr($day, 0, 4);
        $month = (int) substr($day, 5, 2);
        $date = (int) substr($day, 8, 2);

        return $year > 0 && checkdate($month, $date, $year) ? $year * 10_000 + $month * 100 + $date : 0;
    }

    /** FNV-1a 32 of $bytes as an unsigned int. */
    private static function w62Fnv(string $bytes): int
    {
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
