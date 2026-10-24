<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TraktService;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use DariusIII\TVMaze\TVShow;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Reproduce the paired single-call experiment with benchmark('/tmp/wave11-tv-trakt.json').
 * Baseline: 8fc669876 (the production loops already score each candidate through
 * `nntmux_tv_score_candidate`). Adoption requires >=1.5x mean AND p99 on common AND
 * uncommon inputs for each method. PHP 8.5, rustc release profile, Darwin arm64 Apple M5,
 * 10 CPUs; OPcache CLI off; shared host (load average 4-9 from parallel workers).
 * 1,024 warmups, 20,480 randomized paired single calls per method/workload.
 * Runs 4-6 (PHP latency / native latency), common then uncommon mean/p99 (run 6 is the
 * committed source; runs 4-5 predate only the isUsableExternalId 19+-digit decline):
 *                             run 4      run 5      run 6     | run 4      run 5      run 6
 * tmdbSelectBestShow          1.45/1.44  1.44/1.45  1.46/1.42 | 1.03/1.05  1.03/1.04  1.03/1.04
 * tvmazeSelectBestShow        1.62/1.55  1.63/1.68  1.67/1.69 | 1.03/1.04  1.02/1.03  1.02/1.02
 * tvdbSelectBestShow          1.71/1.66  1.70/1.56  1.74/1.69 | 1.03/1.03  1.03/1.03  1.03/1.03
 * traktSelectBestShow         1.48/1.40  1.47/1.29  1.49/1.49 | 1.02/1.03  1.02/1.03  1.02/1.01
 * traktImageUrl               0.41/0.33  0.42/0.38  0.42/0.57 | 0.37/0.43  0.37/0.43  0.37/0.40
 * formatImdbId                0.66/1.00  0.66/1.12  0.66/1.00 | 2.61/6.89  2.60/7.26  2.63/4.86
 * formatMediaId (dispatch)    1.58/1.81  1.59/1.83  1.66/1.78 | 2.47/2.89  2.39/3.27  2.42/3.00
 * isUsableExternalId          1.02/1.60  1.07/1.83  1.05/1.61 | 3.72/11.16 3.77/9.77  3.67/6.33
 * Uncommon selection (60 long candidates) is dominated by similar_text(), already in Rust
 * per candidate, so batching saves only the call overhead. Only the formatMediaId() slug
 * path qualifies and is the production default; IMDb ids keep the PHP regex there.
 * Non-ASCII ids (fallback workload: never Trakt slugs or numeric ids, rarely the movie names
 * MovieService::searchTraktTV() passes) decline to PHP at 0.83x/0.84x (run 6). Runs 1-3 used
 * an earlier dispatcher and harness; raw samples, orders and hashes:
 * /tmp/wave11-w3/wave11-tv-trakt-run{1..6}.json.
 * The TVDB `/ \(\d{4}\)$/` year-suffix stripper named in the task no longer exists at the
 * baseline (scoreCandidate() splits title years itself), so only its loop is measured.
 */
final class RustTvProvidersTraktMatchingParityTest extends TestCase
{
    protected function tearDown(): void
    {
        AbstractTvProvider::setUseRustFfi(true);
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function bare(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function ffi(): FFI
    {
        $ffi = TraktService::traktFfi();
        self::assertNotNull($ffi, 'Build and copy the release pipeline library before running parity tests.');
        self::assertSame(-1, TmdbProvider::bestCandidateIndexNative('Zzz', null, '', ['Qqq'], ['n'], ['']));

        return $ffi;
    }

    /** @return list<string> */
    private static function names(): array
    {
        return ['The Office', 'The Office (US)', 'Office', 'Doctor Who', 'Doctor Who (2005)', 'Doctor Who (1963)',
            'Shameless', 'Shameless (US)', 'Shameless UK', 'Law & Order: Special Victims Unit', 'Law and Order SVU',
            'Grey\'s Anatomy', 'Greys Anatomy', 'Mr. Robot', 'Mr Robot', 'House of the Dragon', 'Dragon House',
            'Pokémon', 'Pokemon', 'Élite', 'Elite', 'Café Society', 'CSI: Miami', 'CSI Miami', '24', '9-1-1',
            '  padded  ', '', ' ', 'Señor Ávila', 'Ñandú', 'Tokyo 東京', 'Σ Sigma', "Bad\xffUTF8", "Nul\0Byte",
            str_repeat('The Very Long Title Of A Documentary Mini Series ', 6).'Part 2'];
    }

    /** @return list<array{0: string, 1: ?int, 2: string}> */
    private static function queries(): array
    {
        $queries = [];
        foreach (['The Office', 'Doctor Who', 'Shameless', 'Law and Order SVU', 'Mr Robot', 'Pokemon', 'Élite', '24', '',
            'Tokyo 東京', "Bad\xffUTF8", 'House of the Dragon', str_repeat('The Very Long Title Of A Documentary Mini Series ', 6).'Part 2'] as $i => $name) {
            foreach ([null, 2005, 1963, 2022] as $j => $year) {
                $queries[] = [$name, $year, ['', 'US', 'GB', 'UK'][($i + $j) % 4]];
            }
        }

        return $queries;
    }

    /** @return list<mixed> */
    private static function starteds(): array
    {
        return ['2005-03-24', '1963-11-23', '', '20', ' 2001-07-09', '2022', 2005, 1899, 0, null, 2005.0, '1999x', 'unknown'];
    }

    /** @return list<array<int|string, mixed>> */
    private static function tmdbResultSets(int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $names = self::names();
        $starteds = array_values(array_filter(self::starteds(), 'is_string'));
        $sets = [[], [['id' => 1]], [['name' => 'No Id']], ['scalar', null]];
        for ($s = 0; $s < 60; $s++) {
            $set = [];
            $count = $random->getInt(1, 25);
            for ($i = 0; $i < $count; $i++) {
                $name = $names[$random->getInt(0, count($names) - 1)];
                $set['k'.$i] = ['id' => $random->getInt(0, 3) === 0 ? '' : $i + 1, 'name' => $name,
                    'original_name' => $random->getInt(0, 1) ? $name : $names[$random->getInt(0, count($names) - 1)],
                    'first_air_date' => $starteds[$random->getInt(0, count($starteds) - 1)],
                    'origin_country' => $random->getInt(0, 4) === 0 ? [] : [['US', 'GB', 'DE', 'us', 'UK'][$random->getInt(0, 4)]]];
            }
            $sets[] = $set;
        }

        return $sets;
    }

    /** @return list<list<TVShow>> */
    private static function tvmazeResultSets(int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $names = self::names();
        $starteds = self::starteds();
        $sets = [[], [new TVShow(['id' => 0, 'name' => 'Zero'])]];
        for ($s = 0; $s < 60; $s++) {
            $set = [];
            $count = $random->getInt(1, 12);
            for ($i = 0; $i < $count; $i++) {
                $akas = [];
                for ($a = $random->getInt(0, 6); $a > 0; $a--) {
                    $akas[] = $random->getInt(0, 5) === 0 ? ['name' => ''] : ['name' => $names[$random->getInt(0, count($names) - 1)], 'country' => null];
                }
                $data = ['id' => $i + 1, 'name' => $names[$random->getInt(0, count($names) - 1)],
                    'premiered' => $starteds[$random->getInt(0, count($starteds) - 1)],
                    'network' => ['name' => 'Net', 'country' => ['code' => ['US', 'GB', 'CA'][$random->getInt(0, 2)]]]];
                if ($akas !== [] || $random->getInt(0, 1)) {
                    $data['_embedded']['akas'] = $akas;
                }
                $set[] = new TVShow($data);
            }
            $sets[] = $set;
        }

        return $sets;
    }

    /** @return list<array<int|string, mixed>> */
    private static function tvdbResultSets(int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $names = self::names();
        $starteds = self::starteds();
        $sets = [[], [new stdClass]];
        for ($s = 0; $s < 60; $s++) {
            $set = [];
            $count = $random->getInt(1, 25);
            for ($i = 0; $i < $count; $i++) {
                $show = new stdClass;
                $show->tvdb_id = (string) (70000 + $i);
                $show->name = $names[$random->getInt(0, count($names) - 1)];
                $aliases = [];
                for ($a = $random->getInt(0, 5); $a > 0; $a--) {
                    $aliases[] = [$names[$random->getInt(0, count($names) - 1)], '', 7][$random->getInt(0, 4) % 3];
                }
                $show->aliases = $random->getInt(0, 4) === 0 ? null : $aliases;
                if ($random->getInt(0, 2)) {
                    $show->first_air_time = $starteds[$random->getInt(0, count($starteds) - 1)];
                }
                if ($random->getInt(0, 1)) {
                    $show->year = (string) $random->getInt(1950, 2026);
                }
                if ($random->getInt(0, 3)) {
                    $show->country = ['usa', 'gbr', 'US', 'GB'][$random->getInt(0, 3)];
                }
                $set[$i] = $show;
            }
            $sets[] = $set;
        }

        return $sets;
    }

    /** @return list<array<int|string, mixed>> */
    private static function traktResultSets(int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $names = self::names();
        $sets = [[], [['show' => ['title' => '']]], ['x', ['show' => 'x'], ['movie' => []]]];
        for ($s = 0; $s < 60; $s++) {
            $set = [];
            $count = $random->getInt(1, 10);
            for ($i = 0; $i < $count; $i++) {
                $show = ['title' => $names[$random->getInt(0, count($names) - 1)], 'ids' => ['trakt' => $i + 1]];
                if ($random->getInt(0, 3)) {
                    $show['year'] = [2005, 1963, '2022', null, 1899][$random->getInt(0, 4)];
                }
                $set[] = ['type' => 'show', 'score' => 1, 'show' => $show];
            }
            $sets[] = $set;
        }

        return $sets;
    }

    public function test_provider_selection_matches_php_with_rust_and_php_scoring(): void
    {
        self::ffi();
        $tmdb = self::bare(TmdbProvider::class);
        $tvmaze = self::bare(TvMazeProvider::class);
        $tvdb = self::bare(TvdbProvider::class);
        $trakt = self::bare(TraktProvider::class);
        $sets = [self::tmdbResultSets(11), self::tvmazeResultSets(12), self::tvdbResultSets(13), self::traktResultSets(14)];
        foreach ([true, false] as $rustScoring) {
            AbstractTvProvider::setUseRustFfi($rustScoring);
            foreach (self::queries() as [$name, $year, $country]) {
                foreach ($sets[0] as $set) {
                    self::assertSame($tmdb->selectBestShowPhp($set, $name, $year, $country), $tmdb->selectBestShowNative($set, $name, $year, $country));
                }
                foreach ($sets[1] as $set) {
                    self::assertSame($tvmaze->selectBestShowPhp($set, $name, $year, $country), $tvmaze->selectBestShowNative($set, $name, $year, $country));
                }
                foreach ($sets[2] as $set) {
                    self::assertSame($tvdb->selectBestShowPhp($set, $name, $year, $country), $tvdb->selectBestShowNative($set, $name, $year, $country));
                }
                foreach ($sets[3] as $set) {
                    self::assertSame($trakt->selectBestShowPhp($set, $name, $year), $trakt->selectBestShowNative($set, $name, $year));
                }
            }
        }
    }

    public function test_selection_keeps_first_strict_maximum_and_returns_the_original_value(): void
    {
        self::ffi();
        $tvdb = self::bare(TvdbProvider::class);
        $first = (object) ['tvdb_id' => 1, 'name' => 'The Office', 'aliases' => [], 'first_air_time' => '2005-03-24'];
        $second = (object) ['tvdb_id' => 2, 'name' => 'The Office', 'aliases' => [], 'first_air_time' => '2005-03-24'];
        self::assertSame($first, $tvdb->selectBestShowNative(['a' => $first, 'b' => $second], 'The Office', 2005, ''));

        $tmdb = self::bare(TmdbProvider::class);
        $shows = [7 => ['id' => 9, 'name' => 'Office', 'original_name' => 'The Office', 'first_air_date' => '2001', 'extra' => [1]]];
        self::assertSame($shows[7], $tmdb->selectBestShowNative($shows, 'The Office', null, ''));

        $trakt = self::bare(TraktProvider::class);
        self::assertNull($trakt->selectBestShowNative([['show' => ['title' => 'Completely Different']]], 'The Office', null));
    }

    public function test_bridge_declines_unsupported_frames(): void
    {
        self::ffi();
        self::assertNull(TmdbProvider::bestCandidateIndexNative('A', null, '', [], [], []));
        self::assertNull(TmdbProvider::bestCandidateIndexNative('A', null, '', ['A'], [], ['']));
        self::assertNull(TmdbProvider::bestCandidateIndexNative('A', null, '', ["A\0B"], ['n'], ['']));
        self::assertNull(TmdbProvider::bestCandidateIndexNative('A', null, '', ['A'], ['x'], ['']));
        self::assertNull(TmdbProvider::bestCandidateIndexNative('A', null, '', ['東京'], ['n'], ['']));
        self::assertNull(TmdbProvider::bestCandidateIndexNative("Bad\xff", null, '', ['A'], ['n'], ['']));
        self::assertSame(1, TmdbProvider::bestCandidateIndexNative('Pokémon', 1997, 'JP', ['Digimon', 'Pokémon'], ['i1999', 's1997-04-01'], ['JP', 'JP']));
        self::assertSame('i-5', TmdbProvider::encodeCandidateStarted(-5));
        self::assertSame('n', TmdbProvider::encodeCandidateStarted(2005.0));
    }

    /** @return list<mixed> */
    private static function images(): array
    {
        return [null, 'x', [], ['full' => 'https://walter.trakt.tv/images/shows/1/posters/full/a.jpg'],
            ['full' => 'walter-r2.trakt.tv/images/shows/000/001/388/posters/thumb/abc.jpg.webp'],
            ['medium' => '//walter.trakt.tv/m.jpg'], ['thumb' => '///t.jpg'], ['full' => null, 'medium' => 'm.jpg'],
            ['walter.trakt.tv/0.jpg', 'walter.trakt.tv/1.jpg'], [0 => 5], ['full' => ''], ['full' => 'http'],
            ['full' => 'httpx://a'], ['full' => 'HTTP://a'], ['full' => 12], ['full' => '/'], ['full' => "a\0b"],
            ['full' => 'wälter.trakt.tv/ü.jpg'], ['full' => str_repeat('walter.trakt.tv/long/', 400).'a.jpg']];
    }

    public function test_trakt_image_url_matches_php(): void
    {
        self::ffi();
        $trakt = self::bare(TraktProvider::class);
        foreach (self::images() as $image) {
            self::assertSame($trakt->imageUrlPhp($image), $trakt->imageUrlNative($image), var_export($image, true));
        }
        $dispatch = new ReflectionMethod(TraktProvider::class, 'imageUrl');
        foreach ([null, true, false] as $toggle) {
            $trakt->useRustFfi = $toggle;
            self::assertSame('https://w.tv/a.jpg', $dispatch->invoke($trakt, ['full' => '//w.tv/a.jpg']));
        }
    }

    /** @return list<string> */
    private static function ids(): array
    {
        $ids = ['', ' ', '0', '00', '-0', '+0', '1', '+12', '-3', ' 12 ', "\t7\n", "\v8\f", '1.5', '.5', '5.', '1e3', '1e', 'e3',
            '0x1A', '1_000', '99999999999999999999', '-99999999999999999999', '000000000000000000001', str_repeat('9', 309), ' '.str_repeat('1', 400).' ', "\0", " \0 ", 'slug', 'breaking-bad', 'Breaking Bad',
            'Breaking_Bad @ Home!!', '--__--', '@', '@@', 'a@b', 'Law & Order: SVU', "Mr. Robot's", '  Mixed Case  ', 'a  -  b',
            'tt0137523', 'TT0137523', 'tt137523', 'tt12345', 'tt0137523x', "tt0137523\n", "tt0137523\n\n", 'tt-01-37-523',
            'tt', 'TTX', ' tt0903747 ', 'imdb:0903747', '0903747', '12345', '1234567', 'tt0903747 ', 'Pokémon', 'Café',
            'Tokyo 東京', "Bad\xffUTF8", "tab\tinside", "new\nline", 'ÉLITE', 'ǅ', '١٢٣٤٥٦٧', '12345٦'];
        $random = new Randomizer(new Mt19937(21));
        $alphabet = 'abcXYZ019 -_@.!:+eEtT';
        for ($i = 0; $i < 400; $i++) {
            $ids[] = $random->getBytesFromString($alphabet, $random->getInt(1, 14));
        }

        return $ids;
    }

    public function test_trakt_service_id_helpers_match_php_and_dispatch(): void
    {
        self::ffi();
        $service = self::bare(TraktService::class);
        foreach (self::ids() as $id) {
            self::assertSame($service->formatImdbIdPhp($id), $service->formatImdbIdNative($id), 'imdb '.var_export($id, true));
            self::assertSame($service->formatMediaIdPhp($id), $service->formatMediaIdNative($id), 'media '.var_export($id, true));
            foreach (['imdb', 'tmdb', 'trakt', 'tvdb'] as $type) {
                self::assertSame($service->isUsableExternalIdPhp($id, $type), $service->isUsableExternalIdNative($id, $type), $type.' '.var_export($id, true));
            }
        }
        foreach ([0, 1, -5, 1399, PHP_INT_MAX, PHP_INT_MIN] as $id) {
            self::assertSame($service->formatMediaIdPhp($id), $service->formatMediaIdNative($id));
        }
        foreach ([null, 0, 7, -1, 1.5, 0.5, true, false, ['x']] as $id) {
            foreach (['imdb', 'tmdb'] as $type) {
                if (\is_array($id) && $type === 'imdb') {
                    continue;
                }
                self::assertSame(@$service->isUsableExternalIdPhp($id, $type), @$service->isUsableExternalIdNative($id, $type));
            }
        }

        $format = new ReflectionMethod(TraktService::class, 'formatMediaId');
        foreach ([null, true, false] as $toggle) {
            $service->useRustFfi = $toggle;
            foreach (self::ids() as $id) {
                self::assertSame($service->formatMediaIdPhp($id), $format->invoke($service, $id), 'dispatch '.var_export($id, true));
            }
            foreach ([0, 1388, -5] as $id) {
                self::assertSame($service->formatMediaIdPhp($id), $format->invoke($service, $id));
            }
        }
        $usable = new ReflectionMethod(TraktService::class, 'isUsableExternalId');
        $imdb = new ReflectionMethod(TraktService::class, 'formatImdbId');
        foreach ([null, true, false] as $toggle) {
            $service->useRustFfi = $toggle;
            self::assertSame('breaking-bad', $format->invoke($service, ' Breaking Bad '));
            self::assertSame('tt0903747', $imdb->invoke($service, '0903747'));
            self::assertTrue($usable->invoke($service, '81189', 'tvdb'));
        }
    }

    public function test_direct_abi_declines(): void
    {
        $ffi = self::ffi();
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_trakt_usable_external_id('1.5', 3, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_trakt_usable_external_id(null, 1, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_trakt_format_media_id('Café', 5, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_trakt_format_imdb_id('tt1', 3, null, 0));
        // An undersized output buffer declines instead of truncating.
        $buf = $ffi->new('char[4]');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_trakt_image_url('walter.trakt.tv', 15, $buf, 4));
    }

    /**
     * php -d ffi.enable=1 -r 'require "tests/bootstrap.php"; Tests\Unit\RustTvProvidersTraktMatchingParityTest::benchmark("/tmp/wave11-tv-trakt.json");'
     * Single calls include packing, dispatch, FFI and PHP fallback. Keep the executor serial.
     */
    public static function benchmark(string $outputPath): void
    {
        $coldStart = hrtime(true);
        self::ffi();
        $resolutionNs = hrtime(true) - $coldStart;
        AbstractTvProvider::setUseRustFfi(true);
        $tmdb = self::bare(TmdbProvider::class);
        $tvmaze = self::bare(TvMazeProvider::class);
        $tvdb = self::bare(TvdbProvider::class);
        $trakt = self::bare(TraktProvider::class);
        $service = self::bare(TraktService::class);
        // A caller frame like the production endpoints' `$this->formatMediaId($movie)`.
        $caller = static fn (?bool $toggle): TraktService => new class($toggle) extends TraktService
        {
            public function __construct(?bool $toggle)
            {
                $this->useRustFfi = $toggle;
            }

            public function mediaId(string $id): ?string
            {
                return $this->formatMediaId($id);
            }
        };
        $dispatchPhp = $caller(false);
        $dispatchDefault = $caller(null);
        $random = new Randomizer(new Mt19937(11011));

        $common = ['The Office', 'Doctor Who', 'Shameless', 'Law and Order SVU', 'Mr Robot', 'House of the Dragon'];
        $similar = ['The Office', 'The Office (US)', 'Office Space', 'The Office Christmas Special', 'Doctor Who', 'Doctor Who (2005)',
            'Doctor Who Confidential', 'Shameless (US)', 'Shameless', 'Law & Order: Special Victims Unit', 'Law & Order',
            'Mr. Robot', 'Robot Wars', 'House of the Dragon', 'House', 'Dragon Ball', 'The Dragon Prince', 'House of Cards'];
        $uncommonNames = [str_repeat('The Very Long Title Of A Documentary Mini Series ', 3).'Part 2', 'Señor Ávila Élite Café',
            'Law & Order: Special Victims Unit (1999)'];
        $build = static function (string $workload, int $i) use ($similar, $random): array {
            $count = $workload === 'common' ? [5, 10, 20, 10, 5, 20][$i % 6] : 60;
            $titles = [];
            for ($j = 0; $j < $count; $j++) {
                $titles[] = $workload === 'common' ? $similar[$random->getInt(0, count($similar) - 1)]
                    : str_repeat($similar[$random->getInt(0, count($similar) - 1)].' ', 4).'Édition '.$j;
            }

            return $titles;
        };
        $fixtures = [];
        foreach (['common' => $common, 'uncommon' => $uncommonNames] as $workload => $queries) {
            foreach ($queries as $i => $query) {
                $titles = $build($workload, $i);
                $aliasCount = $workload === 'common' ? 2 : 6;
                $tmdbSet = $tvmazeSet = $tvdbSet = $traktSet = [];
                foreach ($titles as $j => $title) {
                    $tmdbSet[] = ['id' => $j + 1, 'name' => $title, 'original_name' => $j % 3 ? $title : strtoupper($title),
                        'first_air_date' => (1990 + $j % 30).'-01-0'.($j % 9 + 1), 'origin_country' => [['US', 'GB'][$j % 2]], 'overview' => 'x'];
                    $akas = [];
                    $aliases = [];
                    for ($a = 0; $a < $aliasCount; $a++) {
                        $akas[] = ['name' => $titles[($j + $a + 1) % count($titles)], 'country' => null];
                        $aliases[] = $titles[($j + $a + 2) % count($titles)];
                    }
                    $tvmazeSet[] = new TVShow(['id' => $j + 1, 'name' => $title, 'premiered' => (1990 + $j % 30).'-05-01',
                        'network' => ['name' => 'Net', 'country' => ['code' => 'US']], '_embedded' => ['akas' => $akas]]);
                    $tvdbSet[] = (object) ['tvdb_id' => (string) (70000 + $j), 'name' => $title, 'aliases' => $aliases,
                        'first_air_time' => (1990 + $j % 30).'-05-01', 'year' => (string) (1990 + $j % 30), 'country' => 'usa'];
                    $traktSet[] = ['type' => 'show', 'score' => 1, 'show' => ['title' => $title, 'year' => 1990 + $j % 30, 'ids' => ['trakt' => $j + 1]]];
                }
                $fixtures[$workload][] = ['query' => $query, 'year' => $i % 2 ? 2005 : null, 'country' => $i % 3 ? 'US' : '',
                    'tmdb' => $tmdbSet, 'tvmaze' => $workload === 'common' && $i % 2 ? [$tvmazeSet[0]] : array_slice($tvmazeSet, 0, 10),
                    'tvdb' => $tvdbSet, 'trakt' => $traktSet];
            }
        }
        $images = ['common' => [['full' => 'walter-r2.trakt.tv/images/shows/000/001/388/posters/thumb/abc.jpg.webp'],
            ['walter-r2.trakt.tv/images/shows/000/001/388/fanarts/medium/def.jpg.webp'], ['medium' => 'https://walter.trakt.tv/m.jpg'], null],
            'uncommon' => [['full' => str_repeat('walter.trakt.tv/long/', 40).'a.jpg'], ['thumb' => '////w.tv/'.str_repeat('ü', 80)], ['full' => '']]];
        $idInputs = ['common' => ['tt0903747', '1388', 'breaking-bad', '81189', 'game-of-thrones', 'tt0137523'],
            'uncommon' => ['  Breaking_Bad @ Home: The Movie!! (2019)  ', 'imdb:tt-0903-747-extra', str_repeat('Long Slug Title ', 12), ' +00081189 ', '0903747'],
            // Non-ASCII ids, which Trakt slugs and numeric ids never contain: Rust declines to PHP.
            'fallback' => ['Pokémon Café', 'Tokyo 東京', 'Ñandú', "tab\tinside"]];

        $report = [
            'protocol' => 'seed 11011; 1024 warmups; 5 blocks of 4096 randomized paired single calls; nearest-rank p99; output equality before timing',
            'baseline_commit' => '8fc669876', 'php' => PHP_VERSION, 'os' => php_uname(), 'ffi' => ini_get('ffi.enable'),
            'locale' => setlocale(LC_CTYPE, '0'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'rust_profile' => 'release', 'rustc' => trim((string) shell_exec('rustc --version')),
            'hardware' => trim((string) shell_exec('sysctl -n machdep.cpu.brand_string hw.logicalcpu hw.memsize 2>/dev/null || nproc')),
            'resource_limits' => 'host process; no imposed CPU or memory limit', 'library_resolution_ns' => $resolutionNs,
            'hashes' => [], 'results' => [],
        ];
        foreach (['app/Services/TvProcessing/Providers/TmdbProvider.php', 'app/Services/TvProcessing/Providers/TvMazeProvider.php',
            'app/Services/TvProcessing/Providers/TvdbProvider.php', 'app/Services/TvProcessing/Providers/TraktProvider.php',
            'app/Services/TraktService.php', 'app/Services/TvProcessing/Providers/AbstractTvProvider.php', 'rust/pipeline-ffi/src/categorize.rs',
            'tests/Unit/RustTvProvidersTraktMatchingParityTest.php', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $file) {
            $report['hashes'][$file] = is_file($file) ? hash_file('sha256', $file) : null;
        }
        foreach (['common', 'uncommon', 'fallback'] as $workload) {
            $f = $fixtures[$workload] ?? [];
            $img = $images[$workload] ?? [];
            $ids = $idInputs[$workload];
            $pairs = [
                'tmdbSelectBestShow' => [count($f), fn (int $i) => $tmdb->selectBestShowPhp($f[$i]['tmdb'], $f[$i]['query'], $f[$i]['year'], $f[$i]['country']), fn (int $i) => $tmdb->selectBestShowNative($f[$i]['tmdb'], $f[$i]['query'], $f[$i]['year'], $f[$i]['country'])],
                'tvmazeSelectBestShow' => [count($f), fn (int $i) => $tvmaze->selectBestShowPhp($f[$i]['tvmaze'], $f[$i]['query'], $f[$i]['year'], $f[$i]['country']), fn (int $i) => $tvmaze->selectBestShowNative($f[$i]['tvmaze'], $f[$i]['query'], $f[$i]['year'], $f[$i]['country'])],
                'tvdbSelectBestShow' => [count($f), fn (int $i) => $tvdb->selectBestShowPhp($f[$i]['tvdb'], $f[$i]['query'], $f[$i]['year'], $f[$i]['country']), fn (int $i) => $tvdb->selectBestShowNative($f[$i]['tvdb'], $f[$i]['query'], $f[$i]['year'], $f[$i]['country'])],
                'traktSelectBestShow' => [count($f), fn (int $i) => $trakt->selectBestShowPhp($f[$i]['trakt'], $f[$i]['query'], $f[$i]['year']), fn (int $i) => $trakt->selectBestShowNative($f[$i]['trakt'], $f[$i]['query'], $f[$i]['year'])],
                'traktImageUrl' => [count($img), fn (int $i) => $trakt->imageUrlPhp($img[$i]), fn (int $i) => $trakt->imageUrlNative($img[$i])],
                'formatImdbId' => [count($ids), fn (int $i) => $service->formatImdbIdPhp($ids[$i]), fn (int $i) => $service->formatImdbIdNative($ids[$i])],
                'formatMediaId' => [count($ids), fn (int $i) => $service->formatMediaIdPhp($ids[$i]), fn (int $i) => $service->formatMediaIdNative($ids[$i])],
                'formatMediaIdDispatch' => [count($ids), fn (int $i) => $dispatchPhp->mediaId($ids[$i]), fn (int $i) => $dispatchDefault->mediaId($ids[$i])],
                'isUsableExternalId' => [count($ids), fn (int $i) => $service->isUsableExternalIdPhp($ids[$i], $i % 2 ? 'tvdb' : 'imdb'), fn (int $i) => $service->isUsableExternalIdNative($ids[$i], $i % 2 ? 'tvdb' : 'imdb')],
            ];
            foreach ($pairs as $method => [$n, $phpCall, $rustCall]) {
                if ($n === 0) {
                    continue;
                }
                $calls = [$phpCall, $rustCall];
                for ($i = 0; $i < $n; $i++) {
                    self::assertSame($calls[0]($i), $calls[1]($i), $method.' '.$workload.' '.$i);
                }
                for ($warm = 0; $warm < 1024; $warm++) {
                    $calls[0]($warm % $n);
                    $calls[1]($warm % $n);
                }
                $samples = [[], []];
                $orders = [];
                $indices = [];
                for ($sample = 0; $sample < 20480; $sample++) {
                    $i = $random->getInt(0, $n - 1);
                    $first = $random->getInt(0, 1);
                    $indices[] = $i;
                    $orders[] = $first;
                    foreach ([$first, 1 - $first] as $side) {
                        $start = hrtime(true);
                        $result = $calls[$side]($i);
                        $samples[$side][] = hrtime(true) - $start;
                        unset($result);
                    }
                }
                $stats = static function (array $ns): array {
                    sort($ns);

                    return ['mean_ns' => array_sum($ns) / count($ns), 'p99_ns' => $ns[(int) ceil(count($ns) * 0.99) - 1]];
                };
                $php = $stats($samples[0]);
                $rust = $stats($samples[1]);
                $blocks = [];
                for ($block = 0; $block < 5; $block++) {
                    $p = $stats(array_slice($samples[0], $block * 4096, 4096));
                    $r = $stats(array_slice($samples[1], $block * 4096, 4096));
                    $blocks[] = ['mean_speedup' => $p['mean_ns'] / $r['mean_ns'], 'p99_speedup' => $p['p99_ns'] / $r['p99_ns']];
                }
                $key = $method.'/'.$workload;
                $report['results'][$key] = ['php' => $php, 'rust' => $rust,
                    'mean_speedup' => $php['mean_ns'] / $rust['mean_ns'], 'p99_speedup' => $php['p99_ns'] / $rust['p99_ns'],
                    'blocks' => $blocks, 'samples' => $samples, 'orders' => $orders, 'input_indices' => $indices];
                file_put_contents($outputPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                printf("%-24s %-9s php %8.0f/%8.0f rust %8.0f/%8.0f mean %.2fx p99 %.2fx\n", $method, $workload,
                    $php['mean_ns'], $php['p99_ns'], $rust['mean_ns'], $rust['p99_ns'], $php['mean_ns'] / $rust['mean_ns'], $php['p99_ns'] / $rust['p99_ns']);
            }
        }
        $report['raw_inputs_base64'] = base64_encode(serialize([$fixtures, $images, $idInputs]));
        file_put_contents($outputPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
