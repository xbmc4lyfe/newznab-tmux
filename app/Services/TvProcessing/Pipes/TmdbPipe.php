<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for TMDB API lookups.
 */
class TmdbPipe extends AbstractApiProviderPipe
{
    public const int PIPE_KIND = 3;

    public const string FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_tv_pipe_resolve_spec(
        int32_t pipe_kind,
        int64_t *out_priority,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_tv_pipe_is_available(
        int32_t pipe_kind,
        uint32_t has_unavailable_reason,
        const char *reason_ptr,
        size_t reason_len
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustTvProcessingPipesProviderParityTest.php.
     */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected int $priority = 40;

    /**
     * Wave 48.3 batch TMDB TV search, external-id and episode payload scoring and match selection.
     * Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.83x, p99 2.17x; uncommon mean 2.01x, p99 1.74x; see the W48 parity test).
     */
    public const bool W48_TMDB_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_tv_tmdb_score_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Lowest score a candidate needs to be selected for its group. */
    public const int W48_MIN_SCORE = 70;

    private static ?bool $w48RustFfiOverride = null;

    private static FFI|false|null $w48RustFfiHandle = null;

    /** Null restores W48_TMDB_RUST_FFI_DEFAULT. */
    public static function useW48TmdbRustFfi(?bool $enable = true): void
    {
        self::$w48RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w48RustFfiHandle = null;
        }
    }

    public static function usesW48TmdbRustFfi(): bool
    {
        return self::$w48RustFfiOverride ?? self::W48_TMDB_RUST_FFI_DEFAULT;
    }

    /**
     * Four values per search candidate: the score, the title similarity, the year delta (-1
     * unless both years are known) and whether it is its group's selected match. The
     * similarity of the normalized query to the normalized name or original name (the higher)
     * is 100 when equal, 80 when one is the other plus further tokens, else the distinct-token
     * Dice percent (0 when either is empty). The score adds +20/+10/-15 for a year delta of
     * 0/1/more (query_year clamped to [0, 9999] against the first_air_date year), +10 for a
     * case-insensitively equal non-empty country, popularity / 10 capped at 10, +5 from 100
     * votes, +5 for a positive tvdb id, +5 for a `tt<digits>` imdb id and -20 when the query
     * season exceeds a known season count. Each group selects its highest score from
     * W48_MIN_SCORE (first on ties). Then the selected count, the distinct group count and the
     * max score (0 for an empty batch). Missing keys read as '' or 0.
     *
     * @param  list<array<string, mixed>>  $items  query, name, original_name, first_air_date, query_country, origin_country, imdb_id, group, query_year, query_season, popularity, vote_count, tvdb_id, season_count
     * @return list<int>
     */
    public static function w48ScoreBatch(array $items): array
    {
        return self::usesW48TmdbRustFfi()
            ? self::w48ScoreBatchNative($items)
            : self::w48ScoreBatchPhp($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48ScoreBatchNative(array $items): array
    {
        $count = \count($items);
        $c = self::w48Columns($items, [
            'query' => '', 'name' => '', 'original_name' => '', 'first_air_date' => '', 'query_country' => '', 'origin_country' => '',
            'imdb_id' => '', 'group' => 0, 'query_year' => 0, 'query_season' => 0, 'popularity' => 0, 'vote_count' => 0,
            'tvdb_id' => 0, 'season_count' => 0,
        ]);

        return self::w48Invoke(
            self::w48RustFfi(),
            'nntmux_w48_tv_tmdb_score_batch',
            [$c['query'], $c['name'], $c['original_name'], $c['first_air_date'], $c['query_country'], $c['origin_country'], $c['imdb_id']],
            [$c['group'], $c['query_year'], $c['query_season'], $c['popularity'], $c['vote_count'], $c['tvdb_id'], $c['season_count']],
            $count,
            $count * 4 + 3,
        ) ?? self::w48ScoreBatchPhp($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<int>
     */
    public static function w48ScoreBatchPhp(array $items): array
    {
        $out = [];
        $scores = [];
        $best = [];
        $maxScore = 0;
        $index = 0;
        foreach ($items as $item) {
            $query = self::w48Normalize((string) ($item['query'] ?? ''));
            $querySet = $query === '' ? [] : array_flip(explode(' ', $query));
            $similarity = max(
                self::w48Similarity($query, $querySet, self::w48Normalize((string) ($item['name'] ?? ''))),
                self::w48Similarity($query, $querySet, self::w48Normalize((string) ($item['original_name'] ?? ''))),
            );
            $candidateYear = preg_match('/^\d{4}/', (string) ($item['first_air_date'] ?? ''), $m) === 1 ? (int) $m[0] : 0;
            $queryYear = max(0, min(9999, (int) ($item['query_year'] ?? 0)));
            if ($queryYear > 0 && $candidateYear > 0) {
                $delta = abs($queryYear - $candidateYear);
                $yearBonus = match ($delta) {
                    0 => 20,
                    1 => 10,
                    default => -15,
                };
            } else {
                $delta = -1;
                $yearBonus = 0;
            }
            $country = (string) ($item['query_country'] ?? '');
            $querySeason = (int) ($item['query_season'] ?? 0);
            $seasonCount = (int) ($item['season_count'] ?? 0);
            $score = $similarity + $yearBonus
                + ($country !== '' && strcasecmp($country, (string) ($item['origin_country'] ?? '')) === 0 ? 10 : 0)
                + min(10, intdiv(max(0, (int) ($item['popularity'] ?? 0)), 10))
                + ((int) ($item['vote_count'] ?? 0) >= 100 ? 5 : 0)
                + ((int) ($item['tvdb_id'] ?? 0) > 0 ? 5 : 0)
                + (preg_match('/^tt\d+$/D', (string) ($item['imdb_id'] ?? '')) === 1 ? 5 : 0)
                + ($querySeason > 0 && $seasonCount > 0 && $querySeason > $seasonCount ? -20 : 0);
            $maxScore = $index === 0 ? $score : max($maxScore, $score);
            $scores[] = $score;
            $group = (int) ($item['group'] ?? 0);
            $best[$group] ??= -1;
            if ($score >= self::W48_MIN_SCORE && ($best[$group] < 0 || $score > $scores[$best[$group]])) {
                $best[$group] = $index;
            }
            array_push($out, $score, $similarity, $delta, 0);
            $index++;
        }
        $selected = 0;
        foreach ($best as $pick) {
            if ($pick >= 0) {
                $out[$pick * 4 + 3] = 1;
                $selected++;
            }
        }
        array_push($out, $selected, \count($best), $maxScore);

        return $out;
    }

    /**
     * @param  array<int|string, int>  $querySet
     */
    private static function w48Similarity(string $query, array $querySet, string $name): int
    {
        if ($query === '' || $name === '') {
            return 0;
        }
        if ($query === $name) {
            return 100;
        }
        if (str_starts_with($name, $query.' ') || str_starts_with($query, $name.' ')) {
            return 80;
        }
        $nameSet = array_flip(explode(' ', $name));

        return intdiv(200 * \count(array_intersect_key($querySet, $nameSet)), \count($querySet) + \count($nameSet));
    }

    private static function w48RustFfi(): ?FFI
    {
        if (self::$w48RustFfiHandle === null) {
            self::$w48RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w48', self::W48_FFI_CDEF) ?? false;
        }

        return self::$w48RustFfiHandle === false ? null : self::$w48RustFfiHandle;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function getName(): string
    {
        return self::resolvePipeSpec()['name'];
    }

    protected function siteColumn(): string
    {
        return self::resolvePipeSpec()['site_column'];
    }

    protected function createProvider(): AbstractTvProvider
    {
        return new TmdbProvider;
    }

    protected function isAvailable(): bool
    {
        $sourceKey = self::resolvePipeSpec()['source_key'];

        return self::evaluateAvailability(MetadataSources::unavailableReason($sourceKey));
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpec(): array
    {
        if (self::usesRustFfi()) {
            return self::resolvePipeSpecNative();
        }

        return self::resolvePipeSpecPhp();
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpecPhp(): array
    {
        return [
            'name' => 'TMDB',
            'site_column' => 'tmdb',
            'priority' => 40,
            'source_key' => MetadataSources::TMDB,
        ];
    }

    /**
     * @return array{name: string, site_column: string, priority: int, source_key: string}
     */
    public static function resolvePipeSpecNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolvePipeSpecPhp();
        }

        try {
            $outPriority = $ffi->new('int64_t[1]');
            $outLens = $ffi->new('size_t[3]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($buf === null) {
                return self::resolvePipeSpecPhp();
            }

            $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', self::PIPE_KIND, $outPriority, $outLens, $buf, $cap);
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::resolvePipeSpecPhp();
                }
                $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', self::PIPE_KIND, $outPriority, $outLens, $buf, $cap);
            }
            if ($rc < 0) {
                return self::resolvePipeSpecPhp();
            }

            $nameLen = (int) $outLens[0];
            $colLen = (int) $outLens[1];
            $srcLen = (int) $outLens[2];
            $packed = $rc === 0 ? '' : FFI::string($buf, $rc);

            return [
                'name' => substr($packed, 0, $nameLen),
                'site_column' => substr($packed, $nameLen, $colLen),
                'priority' => (int) $outPriority[0],
                'source_key' => substr($packed, $nameLen + $colLen, $srcLen),
            ];
        } catch (Throwable) {
            return self::resolvePipeSpecPhp();
        }
    }

    public static function evaluateAvailability(?string $unavailableReason): bool
    {
        if (self::usesRustFfi()) {
            return self::evaluateAvailabilityNative($unavailableReason);
        }

        return self::evaluateAvailabilityPhp($unavailableReason);
    }

    public static function evaluateAvailabilityPhp(?string $unavailableReason): bool
    {
        return $unavailableReason === null;
    }

    public static function evaluateAvailabilityNative(?string $unavailableReason): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::evaluateAvailabilityPhp($unavailableReason);
        }

        try {
            $hasReason = $unavailableReason !== null ? 1 : 0;
            $str = $unavailableReason ?? '';
            $rc = (int) self::callFfi($ffi, 'nntmux_tv_pipe_is_available', self::PIPE_KIND, $hasReason, $str, strlen($str));
            if ($rc < 0) {
                return self::evaluateAvailabilityPhp($unavailableReason);
            }

            return $rc === 1;
        } catch (Throwable) {
            return self::evaluateAvailabilityPhp($unavailableReason);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    // ========================================================================
    // WAVE 62.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.11x, p99 6.11x; uncommon mean 4.92x, p99 4.07x); see RustTvParsedReleaseLocalDbParseInfoTmdbTraktPipeW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_TMDB_PIPE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w623_tmdb_pipe_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 62.3 batch. */
    public const int W62_TMDB_PIPE_TRAILER = 17;

    /** C0 control bytes and DEL. */
    private const string W62_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the TmdbPipe Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.3 TMDB lookup plans. Each item is `title|tvdb|imdb|tmdb|year|country|unavailableReason`, every field
     * trimmed. Per item `titleState | tvdbState << 2 | imdbState << 4 | tmdbState << 6 | unavailable << 8 | route << 9 |
     * yearState << 12 | countryState << 14 | min(queryLength, 255) << 16 | min(routedId, 0xFFFFFFFF) << 24`: the title
     * is 0 empty, 1 up to 255 bytes without control bytes or 2 invalid; ids are 0 empty, 1 a positive 1-10 digit id
     * (IMDb also `tt` and 7-10 digits) or 2 invalid; the year is 0 empty, 1 19xx/20xx or 2 invalid; the country is
     * 0 empty, 1 two letters or 2 invalid; a non-empty reason marks the pipe unavailable; the query is the lowercase
     * `[a-z0-9]+` title tokens joined by spaces. The route is 0 unavailable, 1 skip, 2 direct TMDB id, 3 find by
     * TVDB id, 4 find by IMDb id or 5 title search (valid title, non-empty query); the routed id is the id it uses.
     * Then the six route counts, the three TVDB, IMDb and TMDB state counts, the distinct requests (route and id, or
     * query and year) and the items carrying any valid id.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62TmdbPlanBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62TmdbPlanBatchNative($items) : self::w62TmdbPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62TmdbPlanBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_TMDB_PIPE_TRAILER) ?? self::w62TmdbPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62TmdbPlanBatchPhp(array $items): array
    {
        $out = [];
        $routes = [0, 0, 0, 0, 0, 0];
        $tvdbs = $imdbs = $tmdbs = [0, 0, 0];
        $withIds = 0;
        $requests = [];
        foreach ($items as $item) {
            [$rawTitle, $rawTvdb, $rawImdb, $rawTmdb, $rawYear, $rawCountry, $reason] = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $title = trim($rawTitle);
            $titleState = self::w62TextState($title);
            [$tvdbState, $tvdb] = self::w62IdState(trim($rawTvdb));
            [$imdbState, $imdb] = self::w62ImdbState(trim($rawImdb));
            [$tmdbState, $tmdb] = self::w62IdState(trim($rawTmdb));
            $text = trim($rawYear);
            $year = self::w62Year($text);
            $yearState = $text === '' ? 0 : ($year === null ? 2 : 1);
            $text = trim($rawCountry);
            $countryState = $text === '' ? 0 : (\strlen($text) === 2 && strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') === 2 ? 1 : 2);
            $unavailable = trim($reason) !== '' ? 1 : 0;
            $query = implode(' ', self::w62Tokens($title));
            $queryLength = \strlen($query);
            [$route, $id] = match (true) {
                $unavailable === 1 => [0, 0],
                $tmdbState === 1 => [2, $tmdb],
                $tvdbState === 1 => [3, $tvdb],
                $imdbState === 1 => [4, $imdb],
                $titleState === 1 && $queryLength > 0 => [5, 0],
                default => [1, 0],
            };
            if ($route >= 2 && $route <= 4) {
                $requests[$route.':'.$id] = true;
            } elseif ($route === 5) {
                $requests['q:'.$query.'|'.($year ?? 0)] = true;
            }
            $routes[$route]++;
            $tvdbs[$tvdbState]++;
            $imdbs[$imdbState]++;
            $tmdbs[$tmdbState]++;
            $withIds += $tvdbState === 1 || $imdbState === 1 || $tmdbState === 1 ? 1 : 0;
            $out[] = $titleState | $tvdbState << 2 | $imdbState << 4 | $tmdbState << 6 | $unavailable << 8 | $route << 9 | $yearState << 12
                | $countryState << 14 | min($queryLength, 255) << 16 | min($id, 0xFFFFFFFF) << 24;
        }
        array_push($out, ...$routes, ...$tvdbs, ...$imdbs, ...$tmdbs);
        array_push($out, \count($requests), $withIds);

        return $out;
    }

    /**
     * (0 empty, 1 a positive 1-10 digit id, 2 invalid; the id or 0).
     *
     * @return array{int, int}
     */
    private static function w62IdState(string $text): array
    {
        if ($text === '') {
            return [0, 0];
        }
        $value = self::w62Digits($text, 1, 10);

        return $value !== null && $value > 0 ? [1, $value] : [2, 0];
    }

    /**
     * As w62IdState(), also accepting `tt` (any case) and 7-10 digits.
     *
     * @return array{int, int}
     */
    private static function w62ImdbState(string $text): array
    {
        if (\strlen($text) >= 2 && strtolower(substr($text, 0, 2)) === 'tt') {
            $value = self::w62Digits(substr($text, 2), 7, 10);

            return $value !== null && $value > 0 ? [1, $value] : [2, 0];
        }

        return self::w62IdState($text);
    }

    /** 0 empty, 1 up to 255 bytes without control bytes, 2 otherwise. */
    private static function w62TextState(string $text): int
    {
        $length = \strlen($text);

        return $length === 0 ? 0 : ($length <= 255 && strcspn($text, self::W62_CONTROL_CHARS) === $length ? 1 : 2);
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w62Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /** A 19xx/20xx four-digit year, else null. */
    private static function w62Year(string $text): ?int
    {
        return \strlen($text) === 4 && (str_starts_with($text, '19') || str_starts_with($text, '20')) ? self::w62Digits($text, 4, 4) : null;
    }

    /**
     * Lowercase `[a-z0-9]+` tokens.
     *
     * @return list<string>
     */
    private static function w62Tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w623', self::W62_TMDB_PIPE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
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
                $handle = self::w62RustFfiState('ffi');
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
     * Calls the Wave 62.3 TmdbPipe export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_TMDB_PIPE_FFI_CDEF.)
            $written = $ffi->nntmux_w623_tmdb_pipe_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
}
