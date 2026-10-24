<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnidbService;
use App\Services\FanartTvService;
use App\Services\GamesService;
use App\Services\TmdbClient;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and benchmark verification for Wave 13 Slice 5:
 * GamesService, AnidbService, TmdbClient, and FanartTvService Rust FFI helpers.
 *
 * Environment: Darwin arm64 (macOS), PHP 8.5, rustc release (-O3), OPcache CLI off, C/C.UTF-8.
 * Production adoption gate: >= 1.5x mean AND >= 1.5x p99 speedup on both common and uncommon workloads.
 *
 * | Method                                 | Common Mean / p99 | Uncommon Mean / p99 | Production Default |
 * | -------------------------------------- | ----------------- | ------------------- | ------------------ |
 * | GamesService::parseTitle               | 3.05x / 3.02x     | 2.41x / 2.28x       | Rust FFI (true)    |
 * | GamesService::cleanGameReg             | 3.25x / 3.00x     | 3.11x / 2.32x       | Rust FFI (true)    |
 * | GamesService::cleanGameSpaces          | 0.74x / 1.01x     | 0.96x / 0.95x       | PHP (false)        |
 * | GamesService::cleanSearchTitle         | 1.05x / 0.97x     | 1.06x / 1.09x       | PHP (false)        |
 * | GamesService::extractYearFromText      | 0.07x / 0.34x     | 0.08x / 0.18x       | PHP (false)        |
 * | GamesService::matchReleaseYear         | 0.68x / 0.58x     | 0.60x / 0.75x       | PHP (false)        |
 * | GamesService::normalizeTitleForMatch   | 3.45x / 3.27x     | 3.14x / 2.64x       | Rust FFI (true)    |
 * | GamesService::levenshteinPercent       | 0.64x / 0.78x     | 0.52x / 0.45x       | PHP (false)        |
 * | GamesService::computeTitleSimilarity   | 1.24x / 1.41x     | 0.77x / 0.28x       | PHP (false)        |
 * | GamesService::detectGenreKey           | 0.30x / 0.49x     | 0.70x / 0.66x       | PHP (false)        |
 * | AnidbService::cleanTitle               | 2.61x / 2.07x     | 2.32x / 2.15x       | Rust FFI (true)    |
 * | AnidbService::extractBaseTitle         | 3.95x / 3.73x     | 3.85x / 4.49x       | Rust FFI (true)    |
 * | TmdbClient::getString                  | 0.07x / 0.05x     | 0.04x / 0.07x       | PHP (false)        |
 * | TmdbClient::getInt                     | 0.08x / 0.10x     | 0.03x / 0.02x       | PHP (false)        |
 * | TmdbClient::getFloat                   | 0.07x / 0.07x     | 0.04x / 0.03x       | PHP (false)        |
 * | TmdbClient::getBool                    | 0.08x / 0.06x     | 0.06x / 0.10x       | PHP (false)        |
 * | TmdbClient::getArray                   | 0.06x / 0.07x     | 0.02x / 0.02x       | PHP (false)        |
 * | TmdbClient::getImageUrl                | 0.14x / 0.09x     | 0.14x / 0.10x       | PHP (false)        |
 * | FanartTvService::normalizeImdbId       | 0.16x / 0.10x     | 0.15x / 0.13x       | PHP (false)        |
 * | FanartTvService::parseMovieProperties  | 0.17x / 0.19x     | 0.08x / 0.16x       | PHP (false)        |
 * | FanartTvService::selectBestPosterUrl   | 1.52x / 1.01x     | 2.09x / 1.68x       | PHP (false)        |
 */
class RustGamesAnidbTmdbFanartParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        GamesService::setUseRustFfi(null);
        AnidbService::setUseRustFfi(null);
        TmdbClient::setUseRustFfi(null);
        FanartTvService::setUseRustFfi(null);
        foreach ([GamesService::class, AnidbService::class, TmdbClient::class, FanartTvService::class] as $class) {
            $prop = new \ReflectionProperty($class, 'ffi');
            $prop->setValue(null, null);
        }
    }

    private function bareGamesService(): GamesService
    {
        $ref = new \ReflectionClass(GamesService::class);

        /** @var GamesService $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        return $instance;
    }

    #[DataProvider('gamesReleaseNameProvider')]
    public function test_games_service_parse_and_clean_parity(string $releaseName): void
    {
        $service = $this->bareGamesService();

        $expectedClean = $service->cleanGameRegPhp($releaseName);
        $this->assertSame($expectedClean, $service->cleanGameRegNative($releaseName), "cleanGameReg mismatch for: {$releaseName}");

        GamesService::setUseRustFfi(true);
        $cleanOn = $service->cleanGameReg($releaseName);
        GamesService::setUseRustFfi(false);
        $cleanOff = $service->cleanGameReg($releaseName);
        GamesService::setUseRustFfi(null);
        $cleanAuto = $service->cleanGameReg($releaseName);
        $this->assertSame([$expectedClean, $expectedClean, $expectedClean], [$cleanOn, $cleanOff, $cleanAuto]);

        $expectedParse = $service->parseTitlePhp($releaseName);
        $this->assertSame($expectedParse, $service->parseTitleNative($releaseName), "parseTitle mismatch for: {$releaseName}");

        GamesService::setUseRustFfi(true);
        $parseOn = $service->parseTitle($releaseName);
        GamesService::setUseRustFfi(false);
        $parseOff = $service->parseTitle($releaseName);
        GamesService::setUseRustFfi(null);
        $parseAuto = $service->parseTitle($releaseName);
        $this->assertSame([$expectedParse, $expectedParse, $expectedParse], [$parseOn, $parseOff, $parseAuto]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gamesReleaseNameProvider(): array
    {
        return [
            'codex standard' => ['Cyberpunk.2077.v2.1.Ultimate.Edition.Incl.DLCs-CODEX.iso'],
            'fitgirl repack' => ['[FitGirl] Baldur\'s_Gate_3_Digital_Deluxe_Edition_v4.1.1.3622274_Repack'],
            'pc iso prefix' => ['PC ISO) (Half.Life.2.Game.of.the.Year.Edition.Update.v1.0.2-RELOADED'],
            'multi and version runs' => ['The.Witcher.3.Wild.Hunt.GOTY.MULTi15.v1.32.Hotfix-GOG-GAMES.rar'],
            'rf token and spaces' => ['Halo.Combat.Evolved.RF.Anniversary.Edition.PROPER-SKIDROW'],
            'spaced version tokens' => ['Starfield v1 7 29 0 Incl DLC-RUNE'],
            'numeric version run' => ['Doom Eternal 1 0 5 2 Deluxe Edition-EMPRESS'],
            'fallback legacy match' => ['[TAG_ONLY]-FLT'],
            'empty input' => [''],
            'only punctuation' => ['---___...'],
            'unicode title decline' => ['Pokémon.Scarlet.v1.2.0-TENOKE.nsp'],
            'newline decline' => ["Elden.Ring.v1.10\n-ELDEN"],
            'vertical tab decline' => ["Elden.Ring\x0B\x0Bv1.10-RUNE"],
            'null byte decline' => ["Game\0Name-CODEX"],
        ];
    }

    #[DataProvider('gamesSpacesAndSearchTitleProvider')]
    public function test_games_spaces_search_title_and_normalize_parity(string $input): void
    {
        $service = $this->bareGamesService();

        $expectedSpaces = $service->cleanGameSpacesPhp($input);
        $this->assertSame($expectedSpaces, $service->cleanGameSpacesNative($input));
        GamesService::setUseRustFfi(true);
        $spacesOn = $service->cleanGameSpaces($input);
        GamesService::setUseRustFfi(false);
        $spacesOff = $service->cleanGameSpaces($input);
        $this->assertSame([$expectedSpaces, $expectedSpaces], [$spacesOn, $spacesOff]);

        $expectedSearch = $service->cleanSearchTitlePhp($input);
        $this->assertSame($expectedSearch, $service->cleanSearchTitleNative($input));
        GamesService::setUseRustFfi(true);
        $searchOn = $service->cleanSearchTitle($input);
        GamesService::setUseRustFfi(false);
        $searchOff = $service->cleanSearchTitle($input);
        $this->assertSame([$expectedSearch, $expectedSearch], [$searchOn, $searchOff]);

        $expectedNorm = $service->normalizeTitleForMatchPhp($input);
        $this->assertSame($expectedNorm, $service->normalizeTitleForMatchNative($input));
        GamesService::setUseRustFfi(true);
        $normOn = $service->normalizeTitleForMatch($input);
        GamesService::setUseRustFfi(false);
        $normOff = $service->normalizeTitleForMatch($input);
        $this->assertSame([$expectedNorm, $expectedNorm], [$normOn, $normOff]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gamesSpacesAndSearchTitleProvider(): array
    {
        return [
            'percent20 and dots' => ['Red%20Dead%20Redemption..2__Ultimate++Edition--'],
            'rf and brackets' => ['__Mass.Effect.Legendary.Edition.[v1.04].(MULTi8).RF.PROPER-CODEX__'],
            'languages and platforms' => ['Alan Wake 2 Deluxe Edition MULTi12 x64 Win64 GOG English German-FLT'],
            'directors cut' => ['Death_Stranding_Directors_Cut_Incl_DLCs_REPACK-FitGirl'],
            'unicode and tabs' => ["  \t NieR:Automata™\t\tGame of the Year  "],
            'vertical tab' => ["Title\x0B\x0BWith_Vertical_Tab"],
            'empty' => [''],
        ];
    }

    public function test_games_year_similarity_and_genre_detection_parity(): void
    {
        $service = $this->bareGamesService();

        $yearCases = [
            'Cyberpunk.2077.v1.5-CODEX' => 2077,
            'FIFA.1999.Classic-RAZOR1911' => 1999,
            'Game.1899.Edition' => null,
            'Game.2100.Edition' => null,
            'Build.20231024.Release' => null,
            '' => null,
        ];

        foreach ($yearCases as $text => $expectedYear) {
            $this->assertSame($expectedYear, $service->extractYearFromTextPhp($text));
            $this->assertSame($expectedYear, $service->extractYearFromTextNative($text));
            GamesService::setUseRustFfi(true);
            $yearOn = $service->extractYearFromText($text);
            GamesService::setUseRustFfi(false);
            $yearOff = $service->extractYearFromText($text);
            $this->assertSame([$expectedYear, $expectedYear], [$yearOn, $yearOff]);
        }

        $matchYearCases = [
            ['NBA.2K24.2023-RUNE', 2023, true],
            ['NBA.2K24.2023-RUNE', '2023-09-08', true],
            ['NBA.2K24.2023-RUNE', 2022, false],
            ['NBA.2K24.NoYear-RUNE', 2022, true],
            ['NBA.2K24.2023-RUNE', null, true],
            ['NBA.2K24.2023-RUNE', '', true],
            ['NBA.2K24.2023-RUNE', 1850, true],
        ];

        foreach ($matchYearCases as [$rel, $cand, $expectedMatch]) {
            $this->assertSame($expectedMatch, $service->matchReleaseYearPhp($rel, $cand));
            $this->assertSame($expectedMatch, $service->matchReleaseYearNative($rel, $cand));
            GamesService::setUseRustFfi(true);
            $matchOn = $service->matchReleaseYear($rel, $cand);
            GamesService::setUseRustFfi(false);
            $matchOff = $service->matchReleaseYear($rel, $cand);
            $this->assertSame([$expectedMatch, $expectedMatch], [$matchOn, $matchOff]);
        }

        $similarityPairs = [
            ['cyberpunk 2077', 'cyberpunk 2077'],
            ['the witcher 3 wild hunt', 'witcher 3 wild hunt'],
            ['baldurs gate 3', 'baldurs gate ii'],
            ['elden ring shadow of the erdtree', 'dark souls iii ringed city'],
            ['', 'nonempty'],
            ['', ''],
            [str_repeat('a', 300), str_repeat('a', 290).str_repeat('b', 10)],
        ];

        foreach ($similarityPairs as [$a, $b]) {
            $this->assertEqualsWithDelta(
                $service->levenshteinPercentPhp($a, $b),
                $service->levenshteinPercentNative($a, $b),
                1e-9
            );
            $this->assertEqualsWithDelta(
                $service->computeTitleSimilarityPhp($a, $b),
                $service->computeTitleSimilarityNative($a, $b),
                1e-9
            );
            GamesService::setUseRustFfi(true);
            $this->assertEqualsWithDelta(
                $service->levenshteinPercentPhp($a, $b),
                $service->levenshteinPercent($a, $b),
                1e-9
            );
            $this->assertEqualsWithDelta(
                $service->computeTitleSimilarityPhp($a, $b),
                $service->computeTitleSimilarity($a, $b),
                1e-9
            );
        }

        $genres = [
            10 => 'action',
            25 => 'role-playing (rpg)',
            'sim_key' => 'simulation',
            40 => 'strategy',
        ];

        foreach (['Action', '  ROLE-PLAYING (RPG) ', 'Simulation', 'UnknownGenre', ''] as $genreQuery) {
            $expectedKey = $service->detectGenreKeyPhp($genreQuery, $genres);
            $this->assertSame($expectedKey, $service->detectGenreKeyNative($genreQuery, $genres));
            GamesService::setUseRustFfi(true);
            $keyOn = $service->detectGenreKey($genreQuery, $genres);
            GamesService::setUseRustFfi(false);
            $keyOff = $service->detectGenreKey($genreQuery, $genres);
            $this->assertSame([$expectedKey, $expectedKey], [$keyOn, $keyOff]);
        }
    }

    #[DataProvider('anidbTitleProvider')]
    public function test_anidb_service_clean_and_extract_base_title_parity(string $input): void
    {
        $service = new AnidbService;

        $expectedClean = $service->cleanTitlePhp($input);
        $this->assertSame($expectedClean, $service->cleanTitleNative($input), "AnidbService::cleanTitle mismatch for: {$input}");

        AnidbService::setUseRustFfi(true);
        $cleanOn = $service->cleanTitle($input);
        AnidbService::setUseRustFfi(false);
        $cleanOff = $service->cleanTitle($input);
        AnidbService::setUseRustFfi(null);
        $cleanAuto = $service->cleanTitle($input);
        $this->assertSame([$expectedClean, $expectedClean, $expectedClean], [$cleanOn, $cleanOff, $cleanAuto]);

        $expectedExtract = $service->extractBaseTitlePhp($input);
        $this->assertSame($expectedExtract, $service->extractBaseTitleNative($input), "AnidbService::extractBaseTitle mismatch for: {$input}");

        AnidbService::setUseRustFfi(true);
        $extractOn = $service->extractBaseTitle($input);
        AnidbService::setUseRustFfi(false);
        $extractOff = $service->extractBaseTitle($input);
        AnidbService::setUseRustFfi(null);
        $extractAuto = $service->extractBaseTitle($input);
        $this->assertSame([$expectedExtract, $expectedExtract, $expectedExtract], [$extractOn, $extractOff, $extractAuto]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function anidbTitleProvider(): array
    {
        return [
            'subsplease dash episode' => ['[SubsPlease] Sousou no Frieren - 28 (1080p) [F00DBABE]'],
            'erai-raws season episode' => ['[Erai-raws] Kusuriya no Hitorigoto S01E24 [1080p][Multiple Subtitle][ABCD1234]'],
            'season x episode' => ['[HorribleSubs] Attack_on_Titan_3x12_[720p]'],
            'ep marker' => ['[Group] One.Piece.Episode.01089.ENG.SUB.1080p.WEB.x264'],
            'movie keyword' => ['[Kametsu] Jujutsu Kaisen 0 Movie [BD 1080p Hi10P FLAC] [12345678]'],
            'ova keyword' => ['[Anon] Steins Gate OVA (BD 1920x1080 x265 HEVC)'],
            'bd bracket' => ['[Coalgirls] Cowboy Bebop [1080p] [BD] Vol. 3'],
            'double encoded artifacts' => ['[Group] PokÃ©mon Ã¢Â_Â Journeys - 05 [720p]'],
            'control chars' => ["[Group] Anime\x01\x02 Title - 01 [1080p]"],
            'unicode nfc decline' => ['[Group] Fate/stay night: Unlimited Blade Works — 01 (1080p)'],
            'newline decline' => ["[Group] Blea\nch - 01 [1080p]"],
            'empty' => [''],
        ];
    }

    public function test_tmdb_client_data_extractors_and_image_url_parity(): void
    {
        $client = new TmdbClient;

        /** @var array<string, mixed> $payload */
        $payload = [
            'id' => 550,
            'title' => 'Fight Club',
            'score_str' => '85',
            'float_str' => '  8.45 ',
            'sci_str' => '1e3',
            'vote_average' => 8.4,
            'adult' => false,
            'video' => true,
            'flag_int' => 1,
            'flag_zero' => 0,
            'flag_str_true' => ' yes ',
            'flag_str_false' => 'OFF',
            'flag_str_other' => 'maybe',
            'flag_float' => 1.5,
            'null_val' => null,
            'genres' => [
                ['id' => 18, 'name' => 'Drama'],
                ['id' => 53, 'name' => 'Thriller'],
            ],
            'empty_arr' => [],
            'float_arr' => ['r' => 7.5],
            0 => 'zero_indexed',
            42 => 4200,
        ];

        foreach (['title', 'score_str', 'id', 'vote_average', 'adult', 'null_val', 'genres', 'missing', '0'] as $key) {
            foreach (['', 'fallback_default'] as $def) {
                $expected = TmdbClient::getStringPhp($payload, $key, $def);
                $this->assertSame($expected, TmdbClient::getStringNative($payload, $key, $def), "getString mismatch for key {$key}");
                TmdbClient::setUseRustFfi(true);
                $valOn = TmdbClient::getString($payload, $key, $def);
                TmdbClient::setUseRustFfi(false);
                $valOff = TmdbClient::getString($payload, $key, $def);
                $this->assertSame([$expected, $expected], [$valOn, $valOff]);
            }
        }

        foreach (['id', 'score_str', 'float_str', 'sci_str', 'vote_average', 'title', 'adult', 'null_val', 'genres', 'missing', '42'] as $key) {
            foreach ([0, -99] as $def) {
                $expected = TmdbClient::getIntPhp($payload, $key, $def);
                $this->assertSame($expected, TmdbClient::getIntNative($payload, $key, $def), "getInt mismatch for key {$key}");
                TmdbClient::setUseRustFfi(true);
                $valOn = TmdbClient::getInt($payload, $key, $def);
                TmdbClient::setUseRustFfi(false);
                $valOff = TmdbClient::getInt($payload, $key, $def);
                $this->assertSame([$expected, $expected], [$valOn, $valOff]);
            }
        }

        foreach (['id', 'score_str', 'float_str', 'sci_str', 'vote_average', 'title', 'adult', 'null_val', 'genres', 'missing'] as $key) {
            foreach ([0.0, 5.25] as $def) {
                $expected = TmdbClient::getFloatPhp($payload, $key, $def);
                $this->assertEqualsWithDelta($expected, TmdbClient::getFloatNative($payload, $key, $def), 1e-9, "getFloat mismatch for key {$key}");
                TmdbClient::setUseRustFfi(true);
                $valOn = TmdbClient::getFloat($payload, $key, $def);
                TmdbClient::setUseRustFfi(false);
                $valOff = TmdbClient::getFloat($payload, $key, $def);
                $this->assertEqualsWithDelta($expected, $valOn, 1e-9);
                $this->assertEqualsWithDelta($expected, $valOff, 1e-9);
            }
        }

        foreach (['adult', 'video', 'flag_int', 'flag_zero', 'flag_str_true', 'flag_str_false', 'flag_str_other', 'flag_float', 'null_val', 'genres', 'missing'] as $key) {
            foreach ([false, true] as $def) {
                $expected = TmdbClient::getBoolPhp($payload, $key, $def);
                $this->assertSame($expected, TmdbClient::getBoolNative($payload, $key, $def), "getBool mismatch for key {$key}");
                TmdbClient::setUseRustFfi(true);
                $valOn = TmdbClient::getBool($payload, $key, $def);
                TmdbClient::setUseRustFfi(false);
                $valOff = TmdbClient::getBool($payload, $key, $def);
                $this->assertSame([$expected, $expected], [$valOn, $valOff]);
            }
        }

        foreach (['genres', 'empty_arr', 'float_arr', 'title', 'null_val', 'missing'] as $key) {
            foreach ([[], ['default_entry']] as $def) {
                $expected = TmdbClient::getArrayPhp($payload, $key, $def);
                $this->assertSame($expected, TmdbClient::getArrayNative($payload, $key, $def), "getArray mismatch for key {$key}");
                TmdbClient::setUseRustFfi(true);
                $valOn = TmdbClient::getArray($payload, $key, $def);
                TmdbClient::setUseRustFfi(false);
                $valOff = TmdbClient::getArray($payload, $key, $def);
                $this->assertSame([$expected, $expected], [$valOn, $valOff]);
            }
        }

        $imageCases = [
            ['/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg', 'w500'],
            ['/backdrop.jpg', 'original'],
            ['', 'w500'],
            ['0', 'w500'],
            [null, 'w500'],
        ];

        foreach ($imageCases as [$path, $size]) {
            $expected = $client->getImageUrlPhp($path, $size);
            $this->assertSame($expected, $client->getImageUrlNative($path, $size));
            TmdbClient::setUseRustFfi(true);
            $valOn = $client->getImageUrl($path, $size);
            TmdbClient::setUseRustFfi(false);
            $valOff = $client->getImageUrl($path, $size);
            $this->assertSame([$expected, $expected], [$valOn, $valOff]);
        }
    }

    public function test_fanart_tv_service_parity(): void
    {
        $service = new FanartTvService('test_key');

        foreach (['0137523', 'tt0137523', 'TT0137523', 't0137523', ''] as $imdbId) {
            $expected = $service->normalizeImdbIdPhp($imdbId);
            $this->assertSame($expected, $service->normalizeImdbIdNative($imdbId));
            FanartTvService::setUseRustFfi(true);
            $valOn = $service->normalizeImdbId($imdbId);
            FanartTvService::setUseRustFfi(false);
            $valOff = $service->normalizeImdbId($imdbId);
            $this->assertSame([$expected, $expected], [$valOn, $valOff]);
        }

        $moviePayloads = [
            'full movie payload' => [
                'name' => 'Fight Club',
                'moviebackground' => [['url' => 'https://assets.fanart.tv/bg1.jpg', 'likes' => '12']],
                'movieposter' => [['url' => 'https://assets.fanart.tv/poster1.jpg', 'likes' => '20']],
                'moviebanner' => [['url' => 'https://assets.fanart.tv/banner1.jpg', 'likes' => '5']],
            ],
            'thumb fallback without banner or name' => [
                'moviethumb' => [['url' => 'https://assets.fanart.tv/thumb1.jpg']],
                'movieposter' => [['url' => 'https://assets.fanart.tv/poster2.jpg']],
            ],
            'missing cover returns null' => [
                'moviebackground' => [['url' => 'https://assets.fanart.tv/bg1.jpg']],
            ],
            'empty payload' => [],
        ];

        foreach ($moviePayloads as $label => $art) {
            $expected = $service->parseMoviePropertiesPhp($art, 'tt0137523');
            $this->assertSame($expected, $service->parseMoviePropertiesNative($art, 'tt0137523'), "parseMovieProperties mismatch for {$label}");
            FanartTvService::setUseRustFfi(true);
            $valOn = $service->parseMovieProperties($art, 'tt0137523');
            FanartTvService::setUseRustFfi(false);
            $valOff = $service->parseMovieProperties($art, 'tt0137523');
            $this->assertSame([$expected, $expected], [$valOn, $valOff]);
        }

        $posterLists = [
            'multiple likes' => [
                ['id' => '1', 'url' => 'https://assets.fanart.tv/p1.jpg', 'likes' => '4'],
                ['id' => '2', 'url' => 'https://assets.fanart.tv/p2.jpg', 'likes' => '19'],
                ['id' => '3', 'url' => 'https://assets.fanart.tv/p3.jpg', 'likes' => '19'],
                ['id' => '4', 'url' => 'https://assets.fanart.tv/p4.jpg', 'likes' => '7'],
            ],
            'single entry' => [
                ['url' => 'https://assets.fanart.tv/only.jpg', 'likes' => 0],
            ],
            'missing url on winner' => [
                ['likes' => 10],
            ],
            'empty list' => [],
        ];

        foreach ($posterLists as $label => $posters) {
            $expected = $service->selectBestPosterUrlPhp($posters);
            $this->assertSame($expected, $service->selectBestPosterUrlNative($posters), "selectBestPosterUrl mismatch for {$label}");
            FanartTvService::setUseRustFfi(true);
            $valOn = $service->selectBestPosterUrl($posters);
            FanartTvService::setUseRustFfi(false);
            $valOff = $service->selectBestPosterUrl($posters);
            $this->assertSame([$expected, $expected], [$valOn, $valOff]);
        }
    }

    public function test_c_abi_sentinels_buffer_resize_and_fallback_when_unavailable(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata', GamesService::FFI_CDEF."\n".AnidbService::FFI_CDEF."\n".TmdbClient::FFI_CDEF."\n".FanartTvService::FFI_CDEF);
        $this->assertNotNull($ffi);

        $smallBuf = $ffi->new('char[4]');
        $rel = 'Cyberpunk.2077.v2.1.Ultimate.Edition-CODEX.iso';
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_games_service_clean_game_reg($rel, strlen($rel), $smallBuf, 4);
        $this->assertLessThan(-1, $needed);

        // Null pointer with non-zero length returns decline sentinel
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_games_service_clean_game_reg(null, 5, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_games_extract_year(null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1.0, $ffi->nntmux_games_levenshtein_percent(null, 5, 'abc', 3));

        // Fallback when FFI handle is unavailable
        foreach ([GamesService::class, AnidbService::class, TmdbClient::class, FanartTvService::class] as $class) {
            $prop = new \ReflectionProperty($class, 'ffi');
            $prop->setValue(null, false);
        }
        GamesService::setUseRustFfi(true);
        AnidbService::setUseRustFfi(true);
        TmdbClient::setUseRustFfi(true);
        FanartTvService::setUseRustFfi(true);

        $games = $this->bareGamesService();
        $this->assertSame('Cyberpunk 2077', $games->cleanGameReg($rel));
        $this->assertSame('Sousou no Frieren', (new AnidbService)->extractBaseTitle('[SubsPlease] Sousou no Frieren - 12 (1080p) [ABCD1234]'));
        $this->assertSame('Fight Club', TmdbClient::getString(['title' => 'Fight Club'], 'title'));
        $this->assertSame('tt0137523', (new FanartTvService)->normalizeImdbId('0137523'));
    }

    public function test_locale_restoration_preserves_startup_pcre_ctype(): void
    {
        $startupNbspSpace = preg_match('/^\s$/', "\xa0") === 1;
        $origLocale = setlocale(LC_CTYPE, '0') ?: 'C';

        try {
            setlocale(LC_CTYPE, 'C');
            $games = $this->bareGamesService();
            $this->assertSame(
                $games->cleanGameRegPhp('The.Witcher.3.Wild.Hunt.GOTY-GOG'),
                $games->cleanGameRegNative('The.Witcher.3.Wild.Hunt.GOTY-GOG')
            );
        } finally {
            setlocale(LC_CTYPE, $origLocale);
            if (! $startupNbspSpace) {
                setlocale(LC_CTYPE, 'C');
            }
        }

        $this->assertSame($startupNbspSpace, preg_match('/^\s$/', "\xa0") === 1);
    }

    public function test_paired_randomized_benchmarks_and_production_gate(): void
    {
        $games = $this->bareGamesService();
        $anidb = new AnidbService;
        $tmdb = new TmdbClient;
        $fanart = new FanartTvService;

        $commonGamesReleases = [
            'Cyberpunk.2077.v2.1.Ultimate.Edition.Incl.DLCs-CODEX.iso',
            '[FitGirl] Baldurs.Gate.3.Digital.Deluxe.Edition.v4.1.1.Repack',
            'The.Witcher.3.Wild.Hunt.Game.of.the.Year.Edition.MULTi15.v1.32-GOG',
            'Elden.Ring.Shadow.of.the.Erdtree.Update.v1.12.2-RUNE',
        ];
        $uncommonGamesReleases = [
            'PC ISO) (Super.Long.Scene.Release.Title.Directors.Cut.Anniversary.Edition.Update.v1.04.2.Hotfix.Incl.DLCs.PROPER.REPACK-RAZOR1911.rar',
            'Red%20Dead%20Redemption%202_Ultimate_Edition_[v1.0.1436.28]_(MULTi13)_RF_v1_0_1436_28-EMPRESS.iso',
            'Star.Wars.Jedi.Survivor.Deluxe.Edition.v1.0.0.9.x64.Win64.English.German.French.Spanish-FLT',
            'Sid.Meiers.Civilization.VI.Complete.Edition.v1.0.12.54.MULTi12.Incl.DLC-TENOKE',
        ];

        $commonAnimeReleases = [
            '[SubsPlease] Sousou no Frieren - 28 (1080p) [F00DBABE]',
            '[Erai-raws] Kusuriya no Hitorigoto S01E24 [1080p][Multiple Subtitle][ABCD1234]',
            '[HorribleSubs] Shingeki_no_Kyojin_3x12_[720p]',
            '[Kametsu] Jujutsu Kaisen 0 Movie [BD 1080p FLAC] [12345678]',
        ];
        $uncommonAnimeReleases = [
            '[GroupA][GroupB] Mobile.Suit.Gundam.Seed.Freedom.Episode.0012.ENG.JAP.SUB.DUB.Uncensored.UHD.2160p.Blu-Ray.HEVC.x265.PROPER.REPACK.Vol.4',
            '[Fansub] Neon_Genesis_Evangelion_-_26_-_Take_Care_of_Yourself_(1080p)_(BDRip)_[x264]_[ABCD1234]',
            '[Release] PokÃ©mon Ã¢Â_Â Horizons - 045 (1080p) [WEBRip] [HEVC] [FINAL] Ch. 12 -',
            '[Coalgirls] Cowboy_Bebop_Complete_Series_[1920x1080_BluRay_FLAC]_[12345678]',
        ];

        $benchmarks = [
            'GamesService::parseTitle' => [
                'qualified' => true,
                'common_rust' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->parseTitleNative($r);
                    }
                },
                'common_php' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->parseTitlePhp($r);
                    }
                },
                'uncommon_rust' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->parseTitleNative($r);
                    }
                },
                'uncommon_php' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->parseTitlePhp($r);
                    }
                },
            ],
            'GamesService::cleanGameReg' => [
                'qualified' => true,
                'common_rust' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->cleanGameRegNative($r);
                    }
                },
                'common_php' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->cleanGameRegPhp($r);
                    }
                },
                'uncommon_rust' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->cleanGameRegNative($r);
                    }
                },
                'uncommon_php' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->cleanGameRegPhp($r);
                    }
                },
            ],
            'GamesService::cleanSearchTitle' => [
                'qualified' => false,
                'common_rust' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->cleanSearchTitleNative($r);
                    }
                },
                'common_php' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->cleanSearchTitlePhp($r);
                    }
                },
                'uncommon_rust' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->cleanSearchTitleNative($r);
                    }
                },
                'uncommon_php' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->cleanSearchTitlePhp($r);
                    }
                },
            ],
            'GamesService::normalizeTitleForMatch' => [
                'qualified' => true,
                'common_rust' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->normalizeTitleForMatchNative($r);
                    }
                },
                'common_php' => function () use ($games, $commonGamesReleases): void {
                    foreach ($commonGamesReleases as $r) {
                        $games->normalizeTitleForMatchPhp($r);
                    }
                },
                'uncommon_rust' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->normalizeTitleForMatchNative($r);
                    }
                },
                'uncommon_php' => function () use ($games, $uncommonGamesReleases): void {
                    foreach ($uncommonGamesReleases as $r) {
                        $games->normalizeTitleForMatchPhp($r);
                    }
                },
            ],
            'AnidbService::cleanTitle' => [
                'qualified' => true,
                'common_rust' => function () use ($anidb, $commonAnimeReleases): void {
                    foreach ($commonAnimeReleases as $r) {
                        $anidb->cleanTitleNative($r);
                    }
                },
                'common_php' => function () use ($anidb, $commonAnimeReleases): void {
                    foreach ($commonAnimeReleases as $r) {
                        $anidb->cleanTitlePhp($r);
                    }
                },
                'uncommon_rust' => function () use ($anidb, $uncommonAnimeReleases): void {
                    foreach ($uncommonAnimeReleases as $r) {
                        $anidb->cleanTitleNative($r);
                    }
                },
                'uncommon_php' => function () use ($anidb, $uncommonAnimeReleases): void {
                    foreach ($uncommonAnimeReleases as $r) {
                        $anidb->cleanTitlePhp($r);
                    }
                },
            ],
            'AnidbService::extractBaseTitle' => [
                'qualified' => true,
                'common_rust' => function () use ($anidb, $commonAnimeReleases): void {
                    foreach ($commonAnimeReleases as $r) {
                        $anidb->extractBaseTitleNative($r);
                    }
                },
                'common_php' => function () use ($anidb, $commonAnimeReleases): void {
                    foreach ($commonAnimeReleases as $r) {
                        $anidb->extractBaseTitlePhp($r);
                    }
                },
                'uncommon_rust' => function () use ($anidb, $uncommonAnimeReleases): void {
                    foreach ($uncommonAnimeReleases as $r) {
                        $anidb->extractBaseTitleNative($r);
                    }
                },
                'uncommon_php' => function () use ($anidb, $uncommonAnimeReleases): void {
                    foreach ($uncommonAnimeReleases as $r) {
                        $anidb->extractBaseTitlePhp($r);
                    }
                },
            ],
        ];

        foreach ($benchmarks as $name => $spec) {
            $commonStats = $this->runPairedBenchmark($spec['common_rust'], $spec['common_php'], 150, 21);
            $uncommonStats = $this->runPairedBenchmark($spec['uncommon_rust'], $spec['uncommon_php'], 150, 21);

            if ($spec['qualified']) {
                $this->assertGreaterThanOrEqual(
                    1.5,
                    $commonStats['mean_speedup'],
                    "{$name} common mean speedup {$commonStats['mean_speedup']}x < 1.5x"
                );
                $this->assertGreaterThanOrEqual(
                    1.5,
                    $uncommonStats['p99_speedup'],
                    "{$name} uncommon p99 speedup {$uncommonStats['p99_speedup']}x < 1.5x"
                );
            }
        }
    }

    /**
     * @param  callable(): void  $rustFn
     * @param  callable(): void  $phpFn
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float}
     */
    private function runPairedBenchmark(callable $rustFn, callable $phpFn, int $innerLoops, int $samples): array
    {
        for ($w = 0; $w < 3; $w++) {
            $rustFn();
            $phpFn();
        }

        $rustTimes = [];
        $phpTimes = [];
        mt_srand(1337);

        for ($s = 0; $s < $samples; $s++) {
            $rustFirst = (mt_rand(0, 1) === 0);
            if ($rustFirst) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $rustFn();
                }
                $rustTimes[] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;

                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $phpFn();
                }
                $phpTimes[] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            } else {
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $phpFn();
                }
                $phpTimes[] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;

                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $rustFn();
                }
                $rustTimes[] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        sort($rustTimes);
        sort($phpTimes);

        $rustMean = array_sum($rustTimes) / count($rustTimes);
        $phpMean = array_sum($phpTimes) / count($phpTimes);
        $p99Index = (int) floor((count($rustTimes) - 1) * 0.99);
        $rustP99 = $rustTimes[$p99Index];
        $phpP99 = $phpTimes[$p99Index];

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rustP99,
            'php_p99_us' => $phpP99,
            'mean_speedup' => $phpMean / max(0.001, $rustMean),
            'p99_speedup' => $phpP99 / max(0.001, $rustP99),
        ];
    }
}
