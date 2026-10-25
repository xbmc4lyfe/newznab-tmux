<?php

declare(strict_types=1);

namespace App\Services\TvProcessing\Pipes;

use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
