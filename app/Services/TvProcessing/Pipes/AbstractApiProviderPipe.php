<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Exceptions\TvProviderException;
use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\TvPosterResolver;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvProcessingRunState;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared flow for the pipes backed by an external API (TVDB, TVMaze, TMDB, Trakt).
 *
 * 1. Without a show yet: search the API, store the show and its poster.
 * 2. Season packs stop there.
 * 3. Otherwise resolve the episode with this provider's id for the show: the local
 *    episode table first, then the API, downloading each show's episode list at most
 *    once per refresh window.
 *
 * A transient API failure marks the release for a later retry instead of a miss.
 */
abstract class AbstractApiProviderPipe extends AbstractTvProviderPipe
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_api_pipe_format(int32_t kind, int64_t season, int64_t episode, const char *col, size_t col_len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvPipelineAndIgdbDataNodeParityTest). */
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

    /**
     * Wave 48.3 batch provider rate-limit, cache-skip, retry-after and eligibility evaluation.
     * Disabled: seven paired runs missed the 1.5x uncommon p99 gate in one (min common mean 2.04x,
     * p99 1.84x; uncommon mean 1.60x, p99 1.44x); PHP-side column packing dominates these short rows (see the W48 parity test).
     */
    public const bool W48_API_PROVIDER_RUST_FFI_DEFAULT = false;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_tv_api_provider_gate_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Seconds an open circuit (TvProcessingRunState::CIRCUIT_BREAKER_THRESHOLD failures) stays open after the last failure. */
    public const int W48_BREAKER_COOLDOWN = 300;

    /** Seconds a not-found title is skipped without a new search. */
    public const int W48_NOT_FOUND_TTL = 21600;

    /** Timestamps are clamped to [0, 2^52] so window arithmetic stays in integer range. */
    public const int W48_MAX_TIMESTAMP = 1 << 52;

    public const int W48_MAX_RETRY_AFTER = 86400;

    /**
     * Gate decisions: unconfigured, circuit open, rate limited, cached not found, no title,
     * search, season pack, missing site id, episode lookup.
     */
    public const int W48_GATE_DECISIONS = 9;

    private static ?bool $w48RustFfiOverride = null;

    private static FFI|false|null $w48RustFfiHandle = null;

    /** Null restores W48_API_PROVIDER_RUST_FFI_DEFAULT. */
    public static function useW48ApiProviderRustFfi(?bool $enable = true): void
    {
        self::$w48RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w48RustFfiHandle = null;
        }
    }

    public static function usesW48ApiProviderRustFfi(): bool
    {
        return self::$w48RustFfiOverride ?? self::W48_API_PROVIDER_RUST_FFI_DEFAULT;
    }

    /**
     * Four values per item: the gate decision (see W48_GATE_DECISIONS, first that applies:
     * not configured; failures at the breaker threshold within W48_BREAKER_COOLDOWN of the last
     * failure; retry_after (clamped to [0, W48_MAX_RETRY_AFTER]) still pending since the last
     * failure; without a show, a not-found mark younger than W48_NOT_FOUND_TTL; without a show,
     * an empty normalized title or else a search; with one, a season pack, a site id <= 0 or
     * else an episode lookup), the seconds to wait, the FNV-1a 32 cache key of
     * `lowercase provider:normalized title` and the normalized title length. Then the nine
     * decision counts, the max wait and the distinct lowercase provider count. Timestamps are
     * clamped to [0, W48_MAX_TIMESTAMP]; flags are non-zero integers; missing keys read as '' or 0.
     *
     * @param  list<array<string, mixed>>  $items  provider, title, configured, has_show, season_pack, site_id, failures, last_failure_at, retry_after, not_found_at
     * @return list<int>
     */
    public static function w48GateBatch(array $items, int $now): array
    {
        return self::usesW48ApiProviderRustFfi()
            ? self::w48GateBatchNative($items, $now)
            : self::w48GateBatchPhp($items, $now);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48GateBatchNative(array $items, int $now): array
    {
        $count = \count($items);
        $c = self::w48Columns($items, [
            'provider' => '', 'title' => '', 'configured' => 0, 'has_show' => 0, 'season_pack' => 0, 'site_id' => 0,
            'failures' => 0, 'last_failure_at' => 0, 'retry_after' => 0, 'not_found_at' => 0,
        ]);

        return self::w48Invoke(
            self::w48RustFfi(),
            'nntmux_w48_tv_api_provider_gate_batch',
            [$c['provider'], $c['title']],
            [$c['configured'], $c['has_show'], $c['season_pack'], $c['site_id'], $c['failures'], $c['last_failure_at'], $c['retry_after'], $c['not_found_at']],
            $count,
            $count * 4 + self::W48_GATE_DECISIONS + 2,
            [$now],
        ) ?? self::w48GateBatchPhp($items, $now);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48GateBatchPhp(array $items, int $now): array
    {
        $now = max(0, min(self::W48_MAX_TIMESTAMP, $now));
        $out = [];
        $counts = array_fill(0, self::W48_GATE_DECISIONS, 0);
        $maxWait = 0;
        $providers = [];
        foreach ($items as $item) {
            $provider = strtolower((string) ($item['provider'] ?? ''));
            $title = self::w48Normalize((string) ($item['title'] ?? ''));
            $hasShow = (int) ($item['has_show'] ?? 0) !== 0;
            $failures = max(0, (int) ($item['failures'] ?? 0));
            $last = max(0, min(self::W48_MAX_TIMESTAMP, (int) ($item['last_failure_at'] ?? 0)));
            $retryAfter = max(0, min(self::W48_MAX_RETRY_AFTER, (int) ($item['retry_after'] ?? 0)));
            $notFoundAt = max(0, min(self::W48_MAX_TIMESTAMP, (int) ($item['not_found_at'] ?? 0)));
            [$decision, $wait] = match (true) {
                (int) ($item['configured'] ?? 0) === 0 => [0, 0],
                $failures >= TvProcessingRunState::CIRCUIT_BREAKER_THRESHOLD && $last + self::W48_BREAKER_COOLDOWN > $now => [1, $last + self::W48_BREAKER_COOLDOWN - $now],
                $retryAfter > 0 && $last + $retryAfter > $now => [2, $last + $retryAfter - $now],
                ! $hasShow && $notFoundAt > 0 && $notFoundAt + self::W48_NOT_FOUND_TTL > $now => [3, $notFoundAt + self::W48_NOT_FOUND_TTL - $now],
                ! $hasShow => [$title === '' ? 4 : 5, 0],
                (int) ($item['season_pack'] ?? 0) !== 0 => [6, 0],
                (int) ($item['site_id'] ?? 0) <= 0 => [7, 0],
                default => [8, 0],
            };
            $counts[$decision]++;
            $maxWait = max($maxWait, $wait);
            $providers[$provider] = true;
            array_push($out, $decision, $wait, self::w48Fnv($provider.':'.$title), \strlen($title));
        }
        array_push($out, ...$counts);
        array_push($out, $maxWait, \count($providers));

        return $out;
    }

    private static function w48RustFfi(): ?FFI
    {
        if (self::$w48RustFfiHandle === null) {
            self::$w48RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w48', self::W48_FFI_CDEF) ?? false;
        }

        return self::$w48RustFfiHandle === false ? null : self::$w48RustFfiHandle;
    }

    /** Wave 56.3 paired benchmarks decide this default; see RustTvProcessingPipesProviderPassableResultW56ParityTest. */
    public const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_API_PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w563_tv_api_search_eligibility_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-title words of w56SearchEligibilityBatch(). */
    public const int W56_SEARCH_ELIGIBILITY_TRAILER = 7;

    /** Lowercase trailing country tokens stripped from search titles; words store index + 1. */
    public const array W56_SEARCH_COUNTRIES = ['us', 'uk', 'au', 'ca', 'nz'];

    public static function usesW56RustFfi(): bool
    {
        $override = self::w56RustFfiState();

        return \is_bool($override) ? $override : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 56 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 56.3 search eligibility. Per clean title, its lowercase `[a-z0-9]` tokens lose a trailing
     * 19xx/20xx year and then a trailing W56_SEARCH_COUNTRIES token (each only while two or more
     * tokens remain); the word is `flags | country << 5 | (year - 1900) << 8 | min(length, 0x7FFF) << 16
     * | fnv << 31` for the space-joined search title, with flags 1 no tokens, 2 all-numeric search
     * tokens, 4 year stripped, 8 country stripped and 16 searchable (neither 1 nor 2, two or more
     * bytes), `country` the list index + 1 and `fnv` its FNV-1a 32. Then the five flag counts, the
     * distinct searchable hashes and the max length.
     *
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w56SearchEligibilityBatch(array $titles): array
    {
        return self::usesW56RustFfi() ? self::w56SearchEligibilityBatchNative($titles) : self::w56SearchEligibilityBatchPhp($titles);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w56SearchEligibilityBatchNative(array $titles): array
    {
        return self::w56Invoke($titles, \count($titles) + self::W56_SEARCH_ELIGIBILITY_TRAILER) ?? self::w56SearchEligibilityBatchPhp($titles);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w56SearchEligibilityBatchPhp(array $titles): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0];
        $keys = [];
        $maxLength = 0;
        foreach ($titles as $title) {
            $tokens = preg_split('/[^a-z0-9]+/', strtolower((string) $title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $n = \count($tokens);
            $flags = $n === 0 ? 1 : 0;
            $year = 0;
            $country = 0;
            if ($n >= 2 && preg_match('/^(?:19|20)\d{2}$/', $tokens[$n - 1]) === 1) {
                $year = (int) $tokens[--$n];
                $flags |= 4;
            }
            if ($n >= 2 && ($index = array_search($tokens[$n - 1], self::W56_SEARCH_COUNTRIES, true)) !== false) {
                $country = $index + 1;
                $flags |= 8;
                $n--;
            }
            $search = implode(' ', \array_slice($tokens, 0, $n));
            if ($n > 0 && ctype_digit(str_replace(' ', '', $search))) {
                $flags |= 2;
            }
            $fnv = self::w48Fnv($search);
            $length = min(\strlen($search), 0x7FFF);
            if (($flags & 3) === 0 && $length >= 2) {
                $flags |= 16;
                $keys[$fnv] = true;
            }
            for ($bit = 0; $bit < 5; $bit++) {
                $totals[$bit] += ($flags >> $bit) & 1;
            }
            $maxLength = max($maxLength, $length);
            $out[] = $flags | $country << 5 | ($year > 0 ? $year - 1900 : 0) << 8 | $length << 16 | $fnv << 31;
        }
        array_push($out, ...$totals);
        array_push($out, \count($keys), $maxLength);

        return $out;
    }

    /**
     * Wave 56 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w56', self::W56_API_PIPE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w56RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w56RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 56.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w56Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w56RustFfiState('ffi');
            $out = self::w56RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w56RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W56_API_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w563_tv_api_search_eligibility_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        private ?AbstractTvProvider $provider = null,
        private readonly ?TvPosterResolver $posterResolver = null,
    ) {}

    /** The videos column holding this provider's show id. */
    abstract protected function siteColumn(): string;

    abstract protected function createProvider(): AbstractTvProvider;

    /** Whether the provider is configured; unconfigured providers are skipped silently. */
    protected function isAvailable(): bool
    {
        return true;
    }

    protected function provider(): AbstractTvProvider
    {
        return $this->provider ??= $this->createProvider();
    }

    protected function process(TvProcessingPassable $passable): TvProcessingResult
    {
        $name = $this->getName();

        if (! $this->isAvailable()) {
            return TvProcessingResult::skipped('not configured', $name);
        }

        /** @var ParsedTvRelease $parsed */
        $parsed = $passable->parsed;

        if ($passable->runState->isCircuitOpen($name)) {
            $passable->markTransient($name);

            return TvProcessingResult::transient($name, null, ['reason' => 'circuit open']);
        }

        try {
            $result = $this->attempt($passable, $parsed);
            $passable->runState->recordSuccess($name);

            return $result;
        } catch (TvProviderException $e) {
            $passable->runState->recordTransient($name);
            $passable->markTransient($name, $e->retryAfterSeconds);
            $this->outputTransient($parsed->cleanName, $e->getMessage());
            Log::info('TV provider failed transiently', [
                'provider' => $name,
                'release_id' => $passable->context->releaseId,
                'status' => $e->statusCode,
                'retry_after' => $e->retryAfterSeconds,
                'error' => $e->getMessage(),
            ]);

            return TvProcessingResult::transient($name, $e->retryAfterSeconds, ['error' => $e->getMessage()]);
        }
    }

    private function attempt(TvProcessingPassable $passable, ParsedTvRelease $parsed): TvProcessingResult
    {
        $name = $this->getName();
        $title = $parsed->cleanName;
        $provider = $this->provider();

        if (! $passable->hasShow()) {
            if ($passable->runState->isTitleNotFound($name, $title)) {
                $this->outputSkipped($title, 'previously not found');

                return TvProcessingResult::skipped('previously not found', $name);
            }

            $this->outputSearching($title);
            $show = $provider->getShowInfo($parsed->titleWithoutYear(), $parsed->year, $parsed->countryCode());
            if (! \is_array($show) || empty($show['title'])) {
                $passable->runState->rememberTitleNotFound($name, $title);
                $this->outputNotFound($title);

                return TvProcessingResult::notFound($name, ['title' => $title]);
            }

            if (empty($show['country'])) {
                $show['country'] = $parsed->countryCode();
            }

            $videoId = $provider->add($show);
            if ($videoId <= 0) {
                $this->outputNotFound($title);

                return TvProcessingResult::notFound($name, ['title' => $title]);
            }

            $passable->setShow($videoId, $name);
            $this->storePoster($provider, $videoId, $show);
        }

        $videoId = $passable->videoId;

        if ($parsed->seasonPack) {
            $passable->markSeasonPack($name);
            $this->outputSeasonPack($title);

            return TvProcessingResult::seasonPack($videoId, $name);
        }

        $siteId = (int) $provider->getSiteIDFromVideoID($this->siteColumn(), $videoId);
        if ($siteId <= 0) {
            return TvProcessingResult::skipped(self::formatMissingSiteIdReason($this->siteColumn()), $name);
        }

        $episodeId = $this->resolveEpisode($passable, $parsed, $provider, $siteId, $videoId);
        if ($episodeId > 0) {
            $passable->markMatched($episodeId, $name);
            $this->outputMatch($title, $parsed);

            return TvProcessingResult::matched($videoId, $episodeId, $name);
        }

        $this->outputEpisodeMissing($title, $parsed);

        return TvProcessingResult::episodeMissing($videoId, $name);
    }

    private function resolveEpisode(TvProcessingPassable $passable, ParsedTvRelease $parsed, AbstractTvProvider $provider, int $siteId, int $videoId): int
    {
        if (! $parsed->hasEpisodeReference()) {
            return 0;
        }

        $episodeId = $this->findLocalEpisode($provider, $videoId, $parsed);
        if ($episodeId > 0) {
            return $episodeId;
        }

        // A show we hold no episodes for gets its whole list in one go.
        if (! $provider->countEpsByVideoID($videoId) && $this->refreshEpisodeList($passable, $provider, $siteId, $videoId)) {
            $episodeId = $this->findLocalEpisode($provider, $videoId, $parsed);
            if ($episodeId > 0) {
                return $episodeId;
            }
        }

        if ($parsed->hasEpisodeNumbers()
            && $passable->runState->firstEpisodeLookup($this->getName(), $videoId, self::formatSeasonEpisodeKey((int) $parsed->season, (int) $parsed->episode))) {
            $episode = $provider->fetchEpisode($siteId, (int) $parsed->season, (int) $parsed->episode, $videoId);
            if (\is_array($episode)) {
                $episodeId = (int) $provider->addEpisode($videoId, $episode);
                if ($episodeId > 0) {
                    return $episodeId;
                }
            }
        }

        if ($parsed->hasAirdate() && $provider->supportsAirdateLookup()
            && $passable->runState->firstEpisodeLookup($this->getName(), $videoId, (string) $parsed->airdate)) {
            $provider->fetchEpisodesByAirdate($siteId, $videoId, (string) $parsed->airdate);
            $episodeId = $this->findLocalEpisode($provider, $videoId, $parsed);
            if ($episodeId > 0) {
                return $episodeId;
            }
        }

        // Last resort: the stored list may be stale or renumbered.
        if ($this->refreshEpisodeList($passable, $provider, $siteId, $videoId)) {
            return $this->findLocalEpisode($provider, $videoId, $parsed);
        }

        return 0;
    }

    public static function formatSeasonEpisodeKey(int $season, int $episode): string
    {
        return self::usesRustFfi()
            ? self::formatSeasonEpisodeKeyNative($season, $episode)
            : self::formatSeasonEpisodeKeyPhp($season, $episode);
    }

    public static function formatSeasonEpisodeKeyNative(int $season, int $episode): string
    {
        return self::callFormatNative(0, $season, $episode, '')
            ?? self::formatSeasonEpisodeKeyPhp($season, $episode);
    }

    public static function formatSeasonEpisodeKeyPhp(int $season, int $episode): string
    {
        return $season.'x'.$episode;
    }

    public static function formatMissingSiteIdReason(string $siteColumn): string
    {
        return self::usesRustFfi()
            ? self::formatMissingSiteIdReasonNative($siteColumn)
            : self::formatMissingSiteIdReasonPhp($siteColumn);
    }

    public static function formatMissingSiteIdReasonNative(string $siteColumn): string
    {
        return self::callFormatNative(1, 0, 0, $siteColumn)
            ?? self::formatMissingSiteIdReasonPhp($siteColumn);
    }

    public static function formatMissingSiteIdReasonPhp(string $siteColumn): string
    {
        return 'show has no '.$siteColumn.' id';
    }

    private static function callFormatNative(int $kind, int $season, int $episode, string $siteColumn): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $colLen = \strlen($siteColumn);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $colLen + 32), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_api_pipe_format($kind, $season, $episode, $siteColumn, $colLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_api_pipe_format($kind, $season, $episode, $siteColumn, $colLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private function findLocalEpisode(AbstractTvProvider $provider, int $videoId, ParsedTvRelease $parsed): int
    {
        return (int) $provider->getBySeasonEp($videoId, $parsed->season ?? -1, $parsed->episode ?? 0, (string) $parsed->airdate);
    }

    /**
     * Download the show's episode list unless it was refreshed within the refresh window.
     */
    private function refreshEpisodeList(TvProcessingPassable $passable, AbstractTvProvider $provider, int $siteId, int $videoId): bool
    {
        if ($passable->runState->wasEpisodeListRefreshed($this->getName(), $videoId)) {
            return false;
        }

        $provider->fetchAllEpisodes($siteId, $videoId);
        $passable->runState->markEpisodeListRefreshed($this->getName(), $videoId);

        return true;
    }

    /**
     * @param  array<string, mixed>  $show
     */
    private function storePoster(AbstractTvProvider $provider, int $videoId, array $show): void
    {
        try {
            if ($provider->hasCover($videoId)) {
                return;
            }

            $url = ($this->posterResolver ?? new TvPosterResolver)->resolve($show);
            if ($url !== '') {
                $provider->savePoster($videoId, $url);
            }
        } catch (Throwable $e) {
            Log::info('TV poster download failed', ['video_id' => $videoId, 'error' => $e->getMessage()]);
        }
    }
}
