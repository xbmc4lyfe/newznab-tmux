<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MovieInfo;
use App\Services\MovieService;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Parity, C ABI and paired benchmark suite for the Rust candidate scoring helpers in
 * `rust/pipeline-ffi/src/categorize.rs` (`candidate_scoring`):
 * - `AbstractTvProvider::checkMatch` / `scoreCandidate` / `normalizeShowTitle` (`nntmux_tv_check_match`,
 *   `nntmux_tv_score_candidate`, `nntmux_tv_score_candidates_batch_nul`, `nntmux_tv_normalize_show_title`)
 * - `MovieService::matchesCurrentContext` / `localIMDBSearch` scoring / `cleanReleaseNameForMovieLookup`
 *   (`nntmux_movie_matches_context`, `nntmux_movie_score_local_candidates_batch_nul`,
 *   `nntmux_movie_clean_release_name`)
 *
 * Paired randomized benchmark (`test_benchmark_paired_callers`, `NNTMUX_TVM_BENCH=1 NNTMUX_TVM_BENCH_RUNS=200`; Apple
 * M5, macOS 27.2 arm64, PHP 8.5.12 CLI with pcre.jit=1 and opcache off, rustc 1.99.1 `cargo build --release` with
 * LTO, base ea3de407c, host load average 4-5). Three repetitions of 200 paired runs x 16 samples per side (3,200
 * samples per side and repetition, randomized side order, 200 warmup pairs); each sample is the per-call mean of an
 * 8-call block. Ranges are min-max across repetitions of PHP/Rust latency ratios, including the PHP FFI wrapper:
 * - `scoreCandidate`: common 1.61-1.67x mean / 1.64-2.94x p99, uncommon 3.93-4.10x / 3.42-4.34x (adopted).
 * - `scoreCandidatesNative` batch vs the PHP loop: 3 candidates 1.77-1.79x / 1.40-1.59x, 40 candidates
 *   12.17-12.58x / 7.58-8.56x. The provider loops are outside this change and the short-batch p99 is inconclusive,
 *   so the batch is parity-tested only.
 * - `checkMatch`: common 1.17-1.27x / 1.02-1.65x, uncommon 1.05-1.07x / 1.10-1.24x; PHP's C `similar_text()` is
 *   the same algorithm (retained native PHP; `checkMatchNative` parity-tested).
 * - `normalizeShowTitle`: common 0.63-0.66x / 0.31-0.64x, uncommon 0.75-0.82x / 0.28-0.80x; one FFI call costs more
 *   than the hash lookup (retained native PHP; `normalizeShowTitleNative` parity-tested).
 * - `matchesCurrentContext`: common 1.34-1.36x / 1.29-1.71x, uncommon 1.83-1.84x / 1.65-2.02x (retained native PHP;
 *   `matchesContextNative` parity-tested).
 * - `bestLocalMatch` (the `localIMDBSearch` scoring loop over hydrated `MovieInfo` rows): 5 rows 1.54-1.61x /
 *   1.33-1.57x, 60 rows 1.85-1.86x / 1.72-1.81x; Eloquent attribute reads are shared by both sides (retained native
 *   PHP; `scoreLocalCandidatesNative` parity-tested).
 * - `cleanReleaseNameForMovieLookup`: common 0.79-0.81x / 0.54-0.72x, uncommon 1.64-1.67x / 1.29-1.71x (retained
 *   native PHP; `cleanReleaseNameForMovieLookupNative` parity-tested).
 * Correctness: the Rust paths decline (fall back to PHP) where `mb_strtolower()` could diverge (code points above
 * U+00FF, invalid UTF-8), on non-digit current years, non-string/int fields, and NUL bytes in NUL-separated batches.
 */
final class RustTvMovieCandidateScoringParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        double nntmux_tv_check_match(const char *ours, size_t ours_len, const char *scrape, size_t scrape_len, double probability);
        double nntmux_tv_score_candidate(const char *ours, size_t ours_len, int64_t our_year, const char *title, size_t title_len, const char *started, size_t started_len, int64_t started_int, const char *our_country, size_t our_country_len, const char *candidate_country, size_t candidate_country_len, uint32_t flags);
        ssize_t nntmux_tv_score_candidates_batch_nul(const char *ours, size_t ours_len, int64_t our_year, uint32_t flags, const char *our_country, size_t our_country_len, const char *titles, size_t titles_len, const char *starteds, size_t starteds_len, const char *countries, size_t countries_len, size_t count, double *out_scores);
        const char *nntmux_tv_normalize_show_title(const char *name, size_t name_len);
        int32_t nntmux_movie_matches_context(const char *current_title, size_t current_title_len, const char *current_year, size_t current_year_len, const char *title, size_t title_len, const char *year, size_t year_len);
        ssize_t nntmux_movie_score_local_candidates_batch_nul(const char *current_title, size_t current_title_len, const char *current_year, size_t current_year_len, const char *titles, size_t titles_len, const char *years, size_t years_len, size_t count, double *out_score);
        const char *nntmux_movie_clean_release_name(const char *name, size_t name_len);
        C;

    private const array TITLE_TOKENS = [
        'The', 'Doctor', 'Who', 'Office', 'Shameless', 'Shameful', 'Late', 'Show', 'with', 'Stephen', 'Colbert',
        'Daily', 'Agents', 'of', 'S.H.I.E.L.D.', 'Pokémon', 'POKÉMON', 'Ölüm', 'Ærø', 'façade', 'Straße', 'ÿ', 'µ',
        "\u{00A0}", '×', 'Þ', 'İstanbul', 'Шоу', '東京', "\xC3", "\xFF", '(2005)', '(1963)', '(2105)', '(19)', '2005',
        'US', 'UK', 'Moana', '2', 'II', 'Part', 'Salve', 'Geral', 'State', 'Fear', '0', '-', ':', '!', '&', '.',
    ];

    private const array SEPARATORS = [' ', ' ', ' ', '', '  ', "\t", "\x0B", "\x0C", "\n", "\r", "\0", '.', '_', ' - '];

    private AbstractTvProvider $tv;

    private MovieService $movie;

    private bool $rustEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('tv_movie_candidate_scoring_test', self::CDEF) === null) {
            self::markTestSkipped('Rust pipeline library with candidate scoring exports is not available.');
        }
        $this->tv = (new ReflectionClass(LocalDbProvider::class))->newInstanceWithoutConstructor();
        $this->movie = (new ReflectionClass(MovieService::class))->newInstanceWithoutConstructor();
        $this->setRust(true);
    }

    protected function tearDown(): void
    {
        $this->setRust(true);
        parent::tearDown();
    }

    public function test_c_abi_null_and_decline_contracts(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('tv_movie_candidate_scoring_test', self::CDEF);
        self::assertNotNull($ffi);
        // @phpstan-ignore method.notFound
        self::assertSame(-1.0, $ffi->nntmux_tv_check_match(null, 3, 'abc', 3, 75.0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1.0, $ffi->nntmux_tv_score_candidate(null, 1, 0, 'a', 1, '', 0, 0, '', 0, '', 0, 0));
        // @phpstan-ignore method.notFound
        self::assertNull($ffi->nntmux_tv_normalize_show_title(null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_movie_matches_context('Moana', 5, '20x6', 4, 'Moana', 5, '2026', 4));
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_movie_matches_context('', 0, '20x6', 4, 'Moana', 5, '2026', 4));
        // @phpstan-ignore method.notFound
        self::assertNull($ffi->nntmux_movie_clean_release_name("a\0b", 3));

        $out = $ffi->new('double[4]');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tv_score_candidates_batch_nul('x', 1, 0, 0, '', 0, "a\0b", 3, 'n', 1, "\0", 1, 2, $out));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tv_score_candidates_batch_nul('x', 1, 0, 0, '', 0, 'a', 1, 'x', 1, '', 0, 1, $out));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tv_score_candidates_batch_nul('x', 1, 0, 0, '', 0, 'a', 1, 'i12z', 4, '', 0, 1, $out));
        $one = $ffi->new('double');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_movie_score_local_candidates_batch_nul('Moana', 5, '', 0, "a\0b", 3, '', 0, 2, FFI::addr($one)));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_movie_score_local_candidates_batch_nul('Moana', 5, '', 0, 'Zzz', 3, '', 0, 1, FFI::addr($one)));

        self::assertNull(AbstractTvProvider::scoreCandidateNative('İstanbul', null, 'istanbul'));
        self::assertNull(AbstractTvProvider::scoreCandidateNative("Bad\xC3", null, 'bad'));
        self::assertNull(AbstractTvProvider::scoreCandidatesNative('Шоу', null, [['Show', null, '']]));
        self::assertSame([100.0, null], AbstractTvProvider::scoreCandidatesNative('Show', null, [['SHOW', null, ''], ['東京', null, '']]));
        self::assertNull(MovieService::matchesContextNative('Moana', '2026', ['bad'], '2026'));
        self::assertNull(MovieService::matchesContextNative('Moana', '2026', 'Moana', 2026.0));
        self::assertNull(MovieService::scoreLocalCandidatesNative('Moana', '2026', [['title' => "a\0b", 'year' => '']]));
    }

    public function test_tv_score_candidate_parity(): void
    {
        $cases = [
            ['Doctor Who', 2005, 'Doctor Who', '1963-11-23', '', ''],
            ['Doctor Who', 2005, 'Doctor Who (2005)', '2005-03-26', '', ''],
            ['Doctor Who', 2005, 'Doctor Who (2005)', null, 'UK', 'GB'],
            ['Shameless', 2011, 'Shameful', '1990-01-01', '', ''],
            ['The Office', null, 'Parks and Recreation', '2009-04-09', '', ''],
            ['The Office', 2005, 'The Office (US)', 2005, ' us', 'US '],
            ['Pokémon', null, 'POKÉMON', null, '', ''],
            ['Show', 2020, "Show\x0C(2020)", 1850, '', ''],
            ['Show', 2020, "\x0C(2020)", 3000, '', ''],
            ['Show', PHP_INT_MIN, 'Show', PHP_INT_MAX, '', ''],
            ['Show', PHP_INT_MAX, 'Show', '2020é', '', ''],
            ['Show', 2020, "Show\0 (2020)", 2020.5, '', ''],
            ['Show', 2020, "Sh\now (2020)", true, '', ''],
            ['  ', null, 'Show', null, '', ''],
        ];
        $starteds = [null, '', '2005', '2005-01-01', ' 1999 ', '20051', '2005_x', '2099', '1899', 2005, 1899, 1900, 0, -5, 2005.0, true, [], '2005é'];
        $countries = ['', 'US', 'us', 'UK', 'uk ', 'GB', ' CA', 'Ü'];
        mt_srand(20261024);
        foreach ($this->fuzz(1500, 5) as $i => $title) {
            $ours = $i % 3 === 0 ? $title : $this->fuzz(1, 5)[0];
            if ($i % 5 === 0) {
                $ours = mb_strtoupper(trim(preg_replace('/\s*\(.*$/', '', $title) ?? ''));
            }
            $cases[] = [
                $ours,
                [null, 2005, 2006, 1963, 2020][mt_rand(0, 4)],
                $title,
                $starteds[mt_rand(0, count($starteds) - 1)],
                $countries[mt_rand(0, count($countries) - 1)],
                $countries[mt_rand(0, count($countries) - 1)],
            ];
        }

        $native = 0;
        foreach ($cases as $i => $args) {
            $expected = $this->tv->scoreCandidatePhp(...$args);
            $rust = AbstractTvProvider::scoreCandidateNative(...$args);
            if ($rust === null) {
                self::assertTrue($this->hasUnsupportedLowercase($args[0]) || $this->hasUnsupportedLowercase($args[2]), "unexpected decline #{$i}: ".json_encode(array_map('bin2hex', [$args[0], $args[2]])));

                continue;
            }
            $native++;
            self::assertSame($expected, $rust, "scoreCandidate #{$i}: ".var_export($args, true));
            self::assertSame($expected, $this->tv->scoreCandidate(...$args));
        }
        self::assertGreaterThan(800, $native);
    }

    public function test_tv_score_candidates_batch_parity(): void
    {
        mt_srand(424242);
        $starteds = [null, '2005-01-01', '1963', 2005, 1899, 3000, -1, 2005.0, '', ' 2006x'];
        for ($round = 0; $round < 80; $round++) {
            $titles = $this->fuzz(mt_rand(1, 30), 5);
            $ours = mt_rand(0, 1) === 1 ? $titles[0] : 'Doctor Who';
            $year = [null, 2005, 1963][mt_rand(0, 2)];
            $country = ['', 'UK', 'us'][mt_rand(0, 2)];
            $candidates = array_map(fn (string $t): array => [str_replace("\0", '', $t), $starteds[mt_rand(0, count($starteds) - 1)], ['', 'GB', 'US', 'ca'][mt_rand(0, 3)]], $titles);
            $scores = AbstractTvProvider::scoreCandidatesNative($ours, $year, $candidates, $country);
            if ($this->hasUnsupportedLowercase($ours)) {
                self::assertNull($scores);

                continue;
            }
            self::assertIsArray($scores);
            foreach ($candidates as $i => [$title, $started, $candidateCountry]) {
                $expected = $this->tv->scoreCandidatePhp($ours, $year, $title, $started, $country, $candidateCountry);
                if ($scores[$i] === null) {
                    self::assertTrue($this->hasUnsupportedLowercase($title));

                    continue;
                }
                self::assertSame($expected, $scores[$i], "batch {$round}/{$i}");
            }
        }
    }

    public function test_tv_check_match_and_normalize_show_title_parity(): void
    {
        mt_srand(77);
        $values = array_merge($this->fuzz(400, 4), ['2024', '', '0', 'Example Show']);
        foreach ($values as $i => $a) {
            $b = $values[mt_rand(0, count($values) - 1)];
            foreach ([75, 0, '80', 100.0, 'x', null] as $probability) {
                $expected = $this->tv->checkMatch($a, $b, $probability);
                self::assertSame($expected, $this->tv->checkMatchNative($a, $b, $probability), "checkMatch #{$i}");
            }
        }
        self::assertSame(100.0, $this->tv->checkMatchNative('2024', 2024, 80));
        self::assertSame(0.0, $this->tv->checkMatchNative('2024', ['bad'], 0));

        $method = new ReflectionMethod($this->tv, 'normalizeShowTitle');
        $titles = ['grits', ' The Daily Show ', "DAILY SHOW\t", "\0colbert\x0B", 'Oak Island - Fluch und Legende', 'DIE SIMPSONS',
            'Dark', 'Darker', "da\0rk", 'dark ', "dark\x0C", 'Der Prinz von Bel Air', 'Polizeiruf 110', 'Tatört', '', ' ', str_repeat('a', 80)];
        foreach (array_merge($titles, $this->fuzz(300, 4)) as $title) {
            self::assertSame($method->invoke($this->tv, $title), AbstractTvProvider::normalizeShowTitleNative($title), bin2hex($title));
        }
    }

    public function test_movie_matches_context_parity(): void
    {
        $method = new ReflectionMethod($this->movie, 'matchesCurrentContext');
        $currentTitles = ['Moana', 'Salve Geral Irmandade', 'The Matrix', 'Amélie', '0', ''];
        $currentYears = ['', '2026', '1999', '2001', '0', '0099'];
        $titles = ['Moana', 'Moana 2', 'MOANA!', 'State of Fear', 'The Matrix Reloaded', 'the-matrix', 'Amelie', 'AMÉLIE', '', '0', null, 0, 5, 'Salve Geral: Irmandade'];
        $years = ['', '0', null, 0, '2026', '2025', '2024', 2026, '1999-03-31', 'c. 2001', '12345', '199', 'x', '2001/2002'];
        $cases = [];
        foreach ($currentTitles as $ct) {
            foreach ($currentYears as $cy) {
                foreach ($titles as $t) {
                    foreach ($years as $y) {
                        $cases[] = [$ct, $cy, $t, $y];
                    }
                }
            }
        }
        mt_srand(99);
        foreach ($this->fuzz(800, 4) as $t) {
            $cases[] = [$this->fuzz(1, 3)[0], ['', '2005', '1999'][mt_rand(0, 2)], $t, ['', '2005', '2006', '1963'][mt_rand(0, 3)]];
        }
        foreach ($cases as $i => [$ct, $cy, $t, $y]) {
            $this->setCurrent($ct, $cy);
            $expected = (bool) $method->invoke($this->movie, $t, $y);
            $mismatch = $this->candidateMismatch();
            $packed = MovieService::matchesContextNative($ct, $cy, $t, $y);
            self::assertNotNull($packed, "decline #{$i}");
            self::assertSame([$expected, $mismatch], [($packed & 1) !== 0, ($packed & 2) !== 0], "matchesContext #{$i}: ".var_export([$ct, $cy, $t, $y], true));
        }
        self::assertNull(MovieService::matchesContextNative('Moana', ' 2026', 'Moana', '2026'));
    }

    public function test_movie_local_candidates_and_clean_release_name_parity(): void
    {
        $php = new ReflectionMethod($this->movie, 'bestLocalMatch');
        mt_srand(31337);
        $pool = ['Moana', 'Moana 2', 'Moana!', 'MOANA', 'The Matrix', 'Matrix', 'The Matrix Reloaded', 'Amélie', 'Amelie', '0', '', 'Moana: The Musical'];
        for ($round = 0; $round < 300; $round++) {
            $ct = ['Moana', 'The Matrix', 'Amélie', $this->fuzz(1, 3)[0]][mt_rand(0, 3)];
            $cy = ['', '2016', '2024', '1999', '2001'][mt_rand(0, 4)];
            $this->setCurrent($ct, $cy);
            $matches = [];
            for ($i = 0, $n = mt_rand(1, 40); $i < $n; $i++) {
                $matches[] = new MovieInfo([
                    'imdbid' => sprintf('%07d', $round * 100 + $i),
                    'title' => mt_rand(0, 2) === 0 ? str_replace("\0", '', $this->fuzz(1, 3)[0]) : $pool[mt_rand(0, count($pool) - 1)],
                    'year' => ['', '2016', '2015', '2017', '2024', '1999', '2001', '0', null][mt_rand(0, 8)],
                ]);
            }
            $expected = $php->invoke($this->movie, $matches);
            $best = MovieService::scoreLocalCandidatesNative($ct, $cy, array_map(static fn (MovieInfo $m): array => ['title' => $m['title'], 'year' => $m['year']], $matches));
            self::assertNotNull($best);
            self::assertSame($expected, $best === false ? null : $matches[$best['index']]['imdbid'], "local #{$round}");
        }

        $phpClean = new ReflectionMethod($this->movie, 'cleanReleaseNameForMovieLookup');
        $names = ['The.Matrix.1999.1080p.BluRay-GRP', 'Title_2024', '  ..Title . . 2024__  ', "a\tb", "a\t\tb", "\x0C x \x0B", "\0Title\0", "Ti\0tle", '', '.', '_._'];
        foreach (array_merge($names, $this->fuzz(500, 6)) as $name) {
            $rust = MovieService::cleanReleaseNameForMovieLookupNative($name);
            if (str_contains($name, "\0")) {
                self::assertNull($rust);

                continue;
            }
            self::assertSame($phpClean->invoke($this->movie, $name), $rust, bin2hex($name));
        }
    }

    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_TVM_BENCH') !== '1') {
            self::markTestSkipped('Opt-in paired microbenchmark.');
        }
        $runs = max(20, (int) (getenv('NNTMUX_TVM_BENCH_RUNS') ?: 60));
        $tv = $this->tv;
        $movie = $this->movie;
        $rust = fn (): bool => $this->rustEnabled;

        // Bound closures reach the protected/private callers without per-call reflection overhead. Methods kept in
        // native PHP are compared against their `*Native` helper wired the way production would call it.
        $tvScope = \Closure::bind(static fn (AbstractTvProvider $p): array => [
            static fn (array $a): float => $p->scoreCandidate(...$a),
            static fn (array $a): ?float => $rust() ? $p->checkMatchNative(...$a) : $p->checkMatch(...$a),
            static fn (string $t): ?string => $rust() ? AbstractTvProvider::normalizeShowTitleNative($t) : $p->normalizeShowTitle($t),
        ], null, AbstractTvProvider::class);
        [$scoreCandidate, $checkMatch, $normalize] = $tvScope($tv);
        $movieScope = \Closure::bind(static fn (MovieService $m): array => [
            static function (array $a) use ($m, $rust): bool {
                $m->currentTitle = $a[0];
                $m->currentYear = $a[1];
                if ($rust() && ($packed = MovieService::matchesContextNative($a[0], $a[1], $a[2], $a[3])) !== null) {
                    if (($packed & 2) !== 0) {
                        $m->candidateMismatch = true;
                    }

                    return ($packed & 1) !== 0;
                }

                return $m->matchesCurrentContext($a[2], $a[3]);
            },
            static function (array $a) use ($m, $rust): ?string {
                $m->currentTitle = $a[0];
                $m->currentYear = $a[1];
                if ($rust()) {
                    $candidates = [];
                    foreach ($a[2] as $match) {
                        $candidates[] = ['title' => $match['title'], 'year' => $match['year']];
                    }
                    $best = MovieService::scoreLocalCandidatesNative($a[0], $a[1], $candidates);
                    if ($best !== null) {
                        return $best === false ? null : $a[2][$best['index']]['imdbid'];
                    }
                }

                return $m->bestLocalMatch($a[2]);
            },
            static fn (string $n): string => $rust() ? (MovieService::cleanReleaseNameForMovieLookupNative($n) ?? $m->cleanReleaseNameForMovieLookup($n)) : $m->cleanReleaseNameForMovieLookup($n),
        ], null, MovieService::class);
        [$context, $local, $cleanName] = $movieScope($movie);

        $batch = static fn (array $a): array => $rust()
            ? (AbstractTvProvider::scoreCandidatesNative($a[0], $a[1], $a[2], $a[3]) ?? [])
            : array_map(static fn (array $c): float => $tv->scoreCandidatePhp($a[0], $a[1], $c[0], $c[1], $a[3], $c[2]), $a[2]);

        $tvmazeCommon = [['Doctor Who', 'Doctor Who', '2005-03-26', 'GB'], ['Doctor Who', 'Doctor Who (1963)', '1963-11-23', 'GB'], ['Doctor Who Confidential', 'Doctor Who Confidential', '2005-03-26', 'GB']];
        $uncommon = [];
        foreach (range(1, 40) as $i) {
            $uncommon[] = ["The Late Show with Stephen Colbert Special Edition Part {$i}", '2015-09-08', 'US'];
        }
        $models = static fn (int $n, string $base): array => array_map(static fn (int $i): MovieInfo => new MovieInfo([
            'imdbid' => sprintf('%07d', $i), 'title' => $i % 3 === 0 ? $base : "{$base} {$i}", 'year' => (string) (2010 + $i % 10),
        ]), range(1, $n));

        $workloads = [
            'scoreCandidate/common' => [$scoreCandidate, [['Doctor Who', 2005, 'Doctor Who', '1963-11-23', 'UK', 'GB'], ['Doctor Who', 2005, 'Doctor Who (2005)', '2005-03-26', 'UK', 'GB'], ['The Office', null, 'The Office (US)', null, 'US', 'US']]],
            'scoreCandidate/uncommon' => [$scoreCandidate, [['The Late Show with Stephen Colbert', 2015, 'The Late Show with Stephen Colbert Special Edition (2015)', 2015, 'US', 'US'], ['Pokémon Horizons The Series', 2023, 'POKÉMON HORIZONS: THE SERIES', '2023-04-14', '', 'JP']]],
            'scoreCandidatesBatch/common3' => [$batch, [['Doctor Who', 2005, $tvmazeCommon, 'UK']]],
            'scoreCandidatesBatch/uncommon40' => [$batch, [['The Late Show with Stephen Colbert', 2015, $uncommon, 'US']]],
            'checkMatch/common' => [$checkMatch, [['doctor who', 'doctor who confidential', 75], ['the office us', 'the office', 75]]],
            'checkMatch/uncommon' => [$checkMatch, [[str_repeat('the late show with stephen colbert ', 3), str_repeat('late night with seth meyers ', 3), 75]]],
            'normalizeShowTitle/common' => [$normalize, ['Doctor Who', 'The Big Bang Theory', 'Daily Show']],
            'normalizeShowTitle/uncommon' => [$normalize, [' Game of Thrones Das Lied von Eis und Feuer ', str_repeat('Very Long Show Title ', 5)]],
            'matchesCurrentContext/common' => [$context, [['Moana', '2016', 'Moana', '2016'], ['The Matrix', '1999', 'The Matrix', 1999], ['Moana', '2026', 'Moana 2', '2024']]],
            'matchesCurrentContext/uncommon' => [$context, [['Salve Geral Irmandade', '2026', 'State of Fear', '2026'], [str_repeat('Long Movie Title ', 4), '2010', str_repeat('Long Movie Title: ', 4), '2011-05-01']]],
            'bestLocalMatch/common5' => [$local, [['Moana', '2016', $models(5, 'Moana')]]],
            'bestLocalMatch/uncommon60' => [$local, [['The Matrix', '2015', $models(60, 'The Matrix')]]],
            'cleanReleaseName/common' => [$cleanName, ['The.Matrix.1999.1080p.BluRay.x264-GRP', 'Moana_2016_2160p_WEB-DL']],
            'cleanReleaseName/uncommon' => [$cleanName, [str_repeat('Some.Very_Long..Movie__Title. ', 6).'2024.REMUX-GRP', "  Weird \t\t Spacing .. _ Title  2020  "]],
        ];

        $pct99 = static function (array $values): float {
            sort($values);

            return (float) $values[(int) ceil(count($values) * 0.99) - 1];
        };

        mt_srand(20261024);
        foreach ($workloads as $name => [$call, $inputs]) {
            $this->setRust(false);
            $baseline = array_map($call, $inputs);
            $this->setRust(true);
            self::assertEquals($baseline, array_map($call, $inputs), $name);
            for ($i = 0; $i < 200; $i++) {
                foreach ([false, true] as $side) {
                    $this->setRust($side);
                    $call($inputs[$i % count($inputs)]);
                }
            }
            $samples = [[], []];
            for ($run = 0; $run < $runs; $run++) {
                foreach (mt_rand(0, 1) === 1 ? [0, 1] : [1, 0] as $side) {
                    $this->setRust($side === 1);
                    for ($block = 0; $block < 16; $block++) {
                        $t0 = hrtime(true);
                        for ($i = 0; $i < 8; $i++) {
                            $call($inputs[$i % count($inputs)]);
                        }
                        $samples[$side][] = (hrtime(true) - $t0) / 8;
                    }
                }
            }
            $meanPhp = array_sum($samples[0]) / count($samples[0]);
            $meanRust = array_sum($samples[1]) / count($samples[1]);
            fwrite(STDOUT, sprintf(
                "BENCH %s n=%d php_mean=%.1fns php_p99=%.1fns rust_mean=%.1fns rust_p99=%.1fns mean=%.2fx p99=%.2fx\n",
                $name, count($samples[0]), $meanPhp, $pct99($samples[0]), $meanRust, $pct99($samples[1]),
                $meanPhp / $meanRust, $pct99($samples[0]) / $pct99($samples[1]),
            ));
        }
        $this->addToAssertionCount(1);
    }

    private function setRust(bool $enabled): void
    {
        $this->rustEnabled = $enabled;
        AbstractTvProvider::setUseRustFfi($enabled);
    }

    private function setCurrent(string $title, string $year): void
    {
        $class = new ReflectionClass(MovieService::class);
        $class->getProperty('currentTitle')->setValue($this->movie, $title);
        $class->getProperty('currentYear')->setValue($this->movie, $year);
        $class->getProperty('candidateMismatch')->setValue($this->movie, false);
    }

    private function candidateMismatch(): bool
    {
        return (bool) (new ReflectionClass(MovieService::class))->getProperty('candidateMismatch')->getValue($this->movie);
    }

    /**
     * Whether `mb_strtolower(trim($value))` touches code points the Rust helper declines (outside ASCII
     * and U+0080..U+00FF, or invalid UTF-8).
     */
    private function hasUnsupportedLowercase(string $value): bool
    {
        return preg_match('/^(?:[\x00-\x7F]|[\xC2\xC3][\x80-\xBF])*$/', trim($value)) !== 1
            || preg_match('/^(?:[\x00-\x7F]|[\xC2\xC3][\x80-\xBF])*$/', $value) !== 1;
    }

    /**
     * Seeded token-soup titles with occasional byte mutations.
     *
     * @return array<int, string>
     */
    private function fuzz(int $count, int $maxTokens): array
    {
        $alphabet = "aZ09 .-_()[]:\t\x0B\x0C\n\0\xC3\xA9";
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $s = '';
            for ($t = 0, $n = mt_rand(1, $maxTokens); $t < $n; $t++) {
                $s .= self::TITLE_TOKENS[mt_rand(0, count(self::TITLE_TOKENS) - 1)].self::SEPARATORS[mt_rand(0, count(self::SEPARATORS) - 1)];
            }
            if (mt_rand(0, 2) === 0) {
                $s .= [' ', '', "\x0C", '  '][mt_rand(0, 3)].'('.[19, 20, 21][mt_rand(0, 2)].mt_rand(0, 9).mt_rand(0, 9).')';
            }
            if (mt_rand(0, 4) === 0) {
                $s = substr_replace($s, $alphabet[mt_rand(0, strlen($alphabet) - 1)], mt_rand(0, strlen($s)), mt_rand(0, 1));
            }
            $out[] = $s;
        }

        return $out;
    }
}
