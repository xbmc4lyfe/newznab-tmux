<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\RepairTvReleaseLinks;
use App\Models\TvEpisode;
use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Pipes\AbstractTvProviderPipe;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvProcessingRunState;
use App\Services\TvProcessing\TvShowAdder;
use App\Support\Data\SteamGameData;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Parity, fallback, and benchmark verification for Wave 14 Slice 3:
 * TvShowAdder, TV candidate/run-state/pipe helpers, RepairTvReleaseLinks
 * matchers, and SteamGameData DTO transforms.
 *
 * Benchmarks run only with NNTMUX_TV_ADDER_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_TV_ADDER_BENCH=1 php artisan test --compact tests/Unit/RustTvShowAdderAndMetadataParityTest.php
 * Each method runs 3 repetitions of 41 paired samples x 200 calls per workload, in
 * seeded random order, through the real dispatching caller with the toggle flipped.
 *
 * Environment: Apple M5 (Darwin arm64), PHP 8.5.12 NTS CLI, OPcache CLI off, rustc 1.99.1 release
 * (opt-level 3, LTO, codegen-units 1). Three independent processes reproduced the verdicts (spread <= 0.15x);
 * no Rust path exceeded 0.70x common or 1.05x uncommon.
 * Production adoption gate: >= 1.5x on common mean, common p99, uncommon mean and uncommon p99.
 * Worst repetition shown (speedup = PHP latency / Rust latency).
 *
 * | Method                                      | Common Mean / p99 | Uncommon Mean / p99 | Production Default |
 * | ------------------------------------------- | ----------------- | ------------------- | ------------------ |
 * | TvShowAdder::normalizeId                    | 0.59x / 0.60x     | 0.93x / 0.92x       | PHP (false)        |
 * | TvShowAdder::videoColumnForSource           | 0.38x / 0.36x     | 0.89x / 0.87x       | PHP (false)        |
 * | TvProcessingCandidateQuery::transientDelay  | 0.36x / 0.33x     | 0.35x / 0.32x       | PHP (false)        |
 * | TvProcessingCandidateQuery::episodeDelay    | 0.30x / 0.28x     | 0.29x / 0.29x       | PHP (false)        |
 * | TvProcessingRunState::refreshKey            | 0.38x / 0.39x     | 0.69x / 0.68x       | PHP (false)        |
 * | TvProcessingRunState::firstEpisodeLookup    | 0.37x / 0.39x     | 0.43x / 0.43x       | PHP (false)        |
 * | AbstractTvProviderPipe::truncateTitle       | 0.67x / 0.70x     | 1.02x / 1.00x       | PHP (false)        |
 * | RepairTvReleaseLinks::matchesEpisode        | 0.62x / 0.64x     | 0.38x / 0.36x       | PHP (false)        |
 * | RepairTvReleaseLinks::capped                | 0.44x / 0.47x     | 0.47x / 0.45x       | PHP (false)        |
 * | SteamGameData::toGamesInfoArray             | 0.08x / 0.08x     | 0.05x / 0.05x       | PHP (false)        |
 * | SteamGameData::hasMultiplayer               | 0.59x / 0.58x     | 0.97x / 0.98x       | PHP (false)        |
 * | SteamGameData::getGenresString              | 0.17x / 0.19x     | 0.15x / 0.15x       | PHP (false)        |
 *
 * Every Rust path loses: per-call FFI marshalling (~0.2-0.5us) exceeds these sub-microsecond PHP
 * bodies, and the Steam DTO transforms add json_encode/json_decode on top. All defaults stay PHP.
 */
class RustTvShowAdderAndMetadataParityTest extends TestCase
{
    private const array TOGGLED = [
        TvShowAdder::class,
        TvProcessingCandidateQuery::class,
        TvProcessingRunState::class,
        AbstractTvProviderPipe::class,
        RepairTvReleaseLinks::class,
        SteamGameData::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', TvShowAdder::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(null);
            (new \ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP until its Rust path passes the 1.5x gate");
        }
    }

    // ---------------------------------------------------------------------
    // TvShowAdder
    // ---------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string, 2?: string}
     */
    private static function outcome(Closure $fn): array
    {
        try {
            return ['ok', (string) $fn()];
        } catch (Throwable $e) {
            return ['err', $e::class, $e->getMessage()];
        }
    }

    #[DataProvider('normalizeIdProvider')]
    public function test_tv_show_adder_normalize_id_parity(string $source, string $id): void
    {
        $adder = new TvShowAdder;
        $expected = self::outcome(fn () => $adder->normalizeIdPhp($source, $id));
        $this->assertSame($expected, self::outcome(fn () => $adder->normalizeIdNative($source, $id)), "{$source}:{$id}");

        $dispatch = Closure::bind(fn (string $s, string $i): string => $this->normalizeId($s, $i), $adder, TvShowAdder::class);
        foreach ([true, false, null] as $toggle) {
            TvShowAdder::setUseRustFfi($toggle);
            $this->assertSame($expected, self::outcome(fn () => $dispatch($source, $id)));
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizeIdProvider(): array
    {
        return [
            'imdb tt prefix' => ['imdb', 'tt0944947'],
            'imdb single t upper' => ['imdb', 'T0944947'],
            'imdb TT ten digits' => ['imdb', 'TT1234567890'],
            'imdb eleven digits numeric' => ['imdb', '12345678901'],
            'imdb tt eleven digits' => ['imdb', 'tt12345678901'],
            'imdb four digits prefixed' => ['imdb', 'tt1234'],
            'imdb four digits bare' => ['imdb', '1234'],
            'imdb ttt' => ['imdb', 'ttt12345'],
            'imdb trimmed' => ['imdb', " \t\ntt0944947\0\x0B"],
            'imdb trailing newline inner' => ['imdb', "tt12345\nx"],
            'imdb empty' => ['imdb', ''],
            'imdb unicode digits' => ['imdb', 'tt１２３４５'],
            'imdb letters' => ['imdb', 'nm0000123'],
            'tvdb positive' => ['tvdb', '121361'],
            'tvdb leading zeros' => ['tvdb', '000121'],
            'tvdb all zeros' => ['tvdb', '0000'],
            'tmdb negative' => ['tmdb', '-1399'],
            'tvmaze plus' => ['tvmaze', '+82'],
            'trakt overflow' => ['trakt', '99999999999999999999999'],
            'trakt trimmed' => ['trakt', "  1390 \r\n"],
            'tvdb empty' => ['tvdb', ''],
            'tvdb float' => ['tvdb', '12.5'],
            'unknown source' => ['tvrage', '42'],
        ];
    }

    public function test_tv_show_adder_normalize_id_randomized_parity(): void
    {
        $adder = new TvShowAdder;
        mt_srand(14_03);
        $alphabet = ['t', 'T', '0', '1', '9', '5', ' ', "\t", "\n", "\0", "\x0B", 'x', '-', '+', 'é', "\xff"];
        foreach (['imdb', 'tvdb', 'tmdb', 'tvmaze', 'trakt'] as $source) {
            for ($i = 0; $i < 400; $i++) {
                $id = '';
                $len = mt_rand(0, 14);
                for ($j = 0; $j < $len; $j++) {
                    $id .= mt_rand(0, 2) === 0 ? $alphabet[mt_rand(0, \count($alphabet) - 1)] : (string) mt_rand(0, 9);
                }
                if (mt_rand(0, 3) === 0) {
                    $id = 'tt'.$id;
                }
                $this->assertSame(
                    self::outcome(fn () => $adder->normalizeIdPhp($source, $id)),
                    self::outcome(fn () => $adder->normalizeIdNative($source, $id)),
                    $source.':'.bin2hex($id)
                );
            }
        }
    }

    public function test_tv_show_adder_video_column_parity(): void
    {
        $adder = new TvShowAdder;
        $dispatch = Closure::bind(fn (string $s): string => $this->videoColumnForSource($s), $adder, TvShowAdder::class);
        foreach (['tvdb', 'tvmaze', 'tmdb', 'trakt', 'imdb', 'TVDB', ' tvdb', 'tvrage', '', "imdb\0", 'anidb'] as $source) {
            $expected = self::outcome(fn () => $adder->videoColumnForSourcePhp($source));
            $this->assertSame($expected, self::outcome(fn () => $adder->videoColumnForSourceNative($source)), $source);
            foreach ([true, false] as $toggle) {
                TvShowAdder::setUseRustFfi($toggle);
                $this->assertSame($expected, self::outcome(fn () => $dispatch($source)));
            }
        }
    }

    // ---------------------------------------------------------------------
    // TvProcessingCandidateQuery / TvProcessingRunState
    // ---------------------------------------------------------------------

    public function test_candidate_backoff_parity(): void
    {
        $retryAfters = [0, -1, -900, 1, 299, 300, 301, 5000, 21600, 999_999, PHP_INT_MAX, PHP_INT_MIN];
        foreach ([PHP_INT_MIN, -5, -1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 100, PHP_INT_MAX] as $attempts) {
            foreach ($retryAfters as $retryAfter) {
                $expected = TvProcessingCandidateQuery::transientDelayPhp($attempts, $retryAfter);
                $this->assertSame($expected, TvProcessingCandidateQuery::transientDelayNative($attempts, $retryAfter), "transient {$attempts}/{$retryAfter}");
                TvProcessingCandidateQuery::setUseRustFfi(true);
                $on = TvProcessingCandidateQuery::transientDelay($attempts, $retryAfter);
                TvProcessingCandidateQuery::setUseRustFfi(false);
                $off = TvProcessingCandidateQuery::transientDelay($attempts, $retryAfter);
                $this->assertSame([$expected, $expected], [$on, $off]);
            }
            $this->assertSame(TvProcessingCandidateQuery::transientDelayPhp($attempts), TvProcessingCandidateQuery::transientDelayNative($attempts));

            $expected = TvProcessingCandidateQuery::episodeDelayPhp($attempts);
            $this->assertSame($expected, TvProcessingCandidateQuery::episodeDelayNative($attempts), "episode {$attempts}");
            TvProcessingCandidateQuery::setUseRustFfi(true);
            $on = TvProcessingCandidateQuery::episodeDelay($attempts);
            TvProcessingCandidateQuery::setUseRustFfi(null);
            $auto = TvProcessingCandidateQuery::episodeDelay($attempts);
            $this->assertSame([$expected, $expected], [$on, $auto]);
        }
    }

    public function test_run_state_lookup_key_parity(): void
    {
        $providers = ['TVMaze', 'Tmdb', 'tvdb', 'TRAKT', 'Local DB', '', 'Ünïcödé', 'İstanbul', "\xff\xfe", "a:b\0c"];
        $videoIds = [0, 1, -1, 42, 1_234_567, PHP_INT_MAX, PHP_INT_MIN];
        $episodeKeys = ['1x2', '2024-01-02', '', '0x0', 'S01E01:extra', 'é', str_repeat('k', 600)];
        foreach ($providers as $provider) {
            foreach ($videoIds as $videoId) {
                $this->assertSame(
                    TvProcessingRunState::refreshKeyPhp($provider, $videoId),
                    TvProcessingRunState::refreshKeyNative($provider, $videoId),
                    bin2hex($provider).":{$videoId}"
                );
                foreach ($episodeKeys as $episodeKey) {
                    $this->assertSame(
                        TvProcessingRunState::episodeLookupKeyPhp($provider, $videoId, $episodeKey),
                        TvProcessingRunState::episodeLookupKeyNative($provider, $videoId, $episodeKey)
                    );
                }
            }
        }
    }

    public function test_run_state_first_episode_lookup_dedupes_with_either_backend(): void
    {
        foreach ([true, false] as $toggle) {
            TvProcessingRunState::setUseRustFfi($toggle);
            $state = new TvProcessingRunState;
            $this->assertTrue($state->firstEpisodeLookup('TVMaze', 10, '1x2'));
            $this->assertFalse($state->firstEpisodeLookup('TVMaze', 10, '1x2'));
            $this->assertTrue($state->firstEpisodeLookup('TVMaze', 10, '1x3'));
            $this->assertTrue($state->firstEpisodeLookup('Tmdb', 10, '1x2'));
            $this->assertTrue($state->firstEpisodeLookup('TVMaze', 11, '1x2'));

            $state->markEpisodeListRefreshed('TVMaze', 77);
            $this->assertTrue($state->wasEpisodeListRefreshed('tvmaze', 77));
            $this->assertTrue((new TvProcessingRunState)->wasEpisodeListRefreshed('TVMAZE', 77));
        }
    }

    // ---------------------------------------------------------------------
    // AbstractTvProviderPipe
    // ---------------------------------------------------------------------

    /**
     * @return Closure(string, int=): string the pipe's protected truncateTitle()
     */
    private function truncate(): Closure
    {
        $pipe = new class extends AbstractTvProviderPipe
        {
            public function getName(): string
            {
                return 'Parity';
            }

            protected function process(TvProcessingPassable $passable): TvProcessingResult
            {
                return TvProcessingResult::pending();
            }
        };

        return Closure::bind(fn (string $title, int $maxLength = 45): string => $this->truncateTitle($title, $maxLength), $pipe, AbstractTvProviderPipe::class);
    }

    #[DataProvider('truncateTitleProvider')]
    public function test_pipe_truncate_title_parity(string $title, int $maxLength): void
    {
        $expected = AbstractTvProviderPipe::truncateTitlePhp($title, $maxLength);
        $this->assertSame($expected, AbstractTvProviderPipe::truncateTitleNative($title, $maxLength));
        $truncate = $this->truncate();
        foreach ([true, false, null] as $toggle) {
            AbstractTvProviderPipe::setUseRustFfi($toggle);
            $this->assertSame($expected, $truncate($title, $maxLength));
        }
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function truncateTitleProvider(): array
    {
        return [
            'short ascii' => ['The Office', 45],
            'exact length' => [str_repeat('a', 45), 45],
            'one over' => [str_repeat('a', 46), 45],
            'long scene title' => ['The.Late.Show.with.Stephen.Colbert.2024.01.02.Guest.Name.1080p.WEB.h264-EDITH', 45],
            'error message' => ['cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', 80],
            'multibyte' => [str_repeat('日本語のタイトル', 10), 45],
            'multibyte boundary' => [str_repeat('é', 46), 45],
            'emoji' => [str_repeat('🎬 Show ', 12), 45],
            'empty' => ['', 45],
            'max three' => ['abcdef', 3],
            'max two negative length' => ['abcdef', 2],
            'max zero' => ['abcdef', 0],
            'max negative' => ['abcdef', -10],
            'max negative multibyte' => ['ééééé', -1],
            'invalid utf8' => ["Bad\xff\xfeBytes".str_repeat('x', 60), 45],
            'truncated utf8 lead' => [str_repeat('a', 50)."\xe6\x97", 45],
        ];
    }

    public function test_pipe_truncate_title_randomized_parity(): void
    {
        mt_srand(14_33);
        $pieces = ['a', 'Z', ' ', '.', 'é', '日', '🎬', "\xff", "\xc3", '-'];
        for ($i = 0; $i < 1500; $i++) {
            $title = '';
            $len = mt_rand(0, 90);
            for ($j = 0; $j < $len; $j++) {
                $title .= $pieces[mt_rand(0, \count($pieces) - 1)];
            }
            $max = mt_rand(-5, 90);
            $this->assertSame(
                AbstractTvProviderPipe::truncateTitlePhp($title, $max),
                AbstractTvProviderPipe::truncateTitleNative($title, $max),
                bin2hex($title).":{$max}"
            );
        }
    }

    // ---------------------------------------------------------------------
    // RepairTvReleaseLinks
    // ---------------------------------------------------------------------

    private static function episode(mixed $series, mixed $episode, mixed $firstAired): TvEpisode
    {
        $model = new TvEpisode;
        $model->setRawAttributes(['series' => $series, 'episode' => $episode, 'firstaired' => $firstAired]);

        return $model;
    }

    private static function parsed(?int $season, ?int $episode, ?string $airdate): ParsedTvRelease
    {
        return new ParsedTvRelease('Show', 'show', '', $season, $episode, false, $airdate, null);
    }

    public function test_repair_matches_episode_parity(): void
    {
        $parsedCases = [
            null,
            self::parsed(1, 2, null),
            self::parsed(0, 5, null),
            self::parsed(-1, 2, null),
            self::parsed(1, 0, null),
            self::parsed(null, 2, null),
            self::parsed(1, null, null),
            self::parsed(0, 0, '2024-01-02'),
            self::parsed(null, null, '2024-01-02'),
            self::parsed(1, 2, '2024-01-02'),
            self::parsed(-1, -1, ''),
            self::parsed(null, null, null),
            self::parsed(PHP_INT_MAX, PHP_INT_MAX, null),
        ];
        $episodes = [
            self::episode(1, 2, '2024-01-02'),
            self::episode('1', '2', '2024-01-02 20:00:00'),
            self::episode(0, 5, '2024-01-02'),
            self::episode(0, 0, '2024-01-02'),
            self::episode(null, null, null),
            self::episode('01', '02x', ''),
            self::episode(1, 3, '2024-01-0'),
            self::episode(PHP_INT_MAX, PHP_INT_MAX, '2024-01-02T00:00:00Z'),
        ];
        $command = new RepairTvReleaseLinks;
        $dispatch = Closure::bind(fn (?ParsedTvRelease $p, TvEpisode $e): bool => $this->matchesEpisode($p, $e), $command, RepairTvReleaseLinks::class);
        foreach ($parsedCases as $pi => $parsed) {
            foreach ($episodes as $ei => $episode) {
                $expected = RepairTvReleaseLinks::matchesEpisodePhp($parsed, $episode);
                $this->assertSame($expected, RepairTvReleaseLinks::matchesEpisodeNative($parsed, $episode), "parsed {$pi} / episode {$ei}");
                foreach ([true, false] as $toggle) {
                    RepairTvReleaseLinks::setUseRustFfi($toggle);
                    $this->assertSame($expected, $dispatch($parsed, $episode));
                }
            }
        }
    }

    public function test_repair_capped_parity(): void
    {
        $command = new RepairTvReleaseLinks;
        $limit = new \ReflectionProperty(RepairTvReleaseLinks::class, 'limit');
        $dispatch = Closure::bind(fn (int $c): int => $this->capped($c), $command, RepairTvReleaseLinks::class);
        $values = [PHP_INT_MIN, -10, -1, 0, 1, 5, 1000, PHP_INT_MAX];
        foreach ($values as $count) {
            foreach ($values as $cap) {
                $expected = RepairTvReleaseLinks::cappedPhp($count, $cap);
                $this->assertSame($expected, RepairTvReleaseLinks::cappedNative($count, $cap));
                $limit->setValue($command, $cap);
                foreach ([true, false] as $toggle) {
                    RepairTvReleaseLinks::setUseRustFfi($toggle);
                    $this->assertSame($expected, $dispatch($count));
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // SteamGameData
    // ---------------------------------------------------------------------

    /**
     * @return array<string, SteamGameData>
     */
    private static function steamGames(): array
    {
        return [
            'full' => new SteamGameData(
                steamId: 1_091_500,
                title: 'Cyberpunk 2077',
                description: 'An open-world, action-adventure RPG set in Night City.',
                coverUrl: 'https://cdn.steam/apps/1091500/header.jpg',
                backdropUrl: 'https://cdn.steam/apps/1091500/page_bg.jpg',
                trailerUrl: 'https://cdn.steam/movie_max.mp4',
                publisher: 'CD PROJEKT RED',
                releaseDate: '2020-12-10',
                genres: ['RPG', 'Action'],
                categories: ['Single-player', 'Steam Achievements', 'Full controller support'],
                metacriticScore: 86,
                storeUrl: 'https://store.steampowered.com/app/1091500',
            ),
            'nulls' => new SteamGameData(steamId: 10, title: 'Counter-Strike'),
            'empty strings and zero' => new SteamGameData(
                steamId: 0,
                title: '',
                description: '',
                coverUrl: '0',
                backdropUrl: '',
                trailerUrl: '',
                publisher: '',
                releaseDate: '',
                metacriticScore: 0,
            ),
            'escaping and unicode' => new SteamGameData(
                steamId: 292_030,
                title: "The Witcher® 3: \"Wild Hunt\" \\ GOTY\t\n/",
                description: "Line1\nLine2\u{2028}\u{0001}",
                coverUrl: 'https://x/a b?c=1&d="2"',
                publisher: 'Pokémon 日本 🎮',
                genres: ['Rol', 'Acción', 'Quote"d', 'Back\\slash', "Ctl\x01"],
                categories: ['Online Co-op', 'Local Co-op'],
                metacriticScore: -5,
            ),
            'multiplayer only' => new SteamGameData(steamId: 570, title: 'Dota 2', categories: ['Multi-player', 'Online PvP']),
            'case mismatch' => new SteamGameData(steamId: 1, title: 'X', categories: ['multi-player', 'Online multi-player']),
            // Spatie Data does not enforce element types, so cover off-contract arrays too.
            'non string genres' => self::withArrays(new SteamGameData(steamId: 2, title: 'Y'), [1, 2.5, true], [3, 'Local Co-op']),
            'keyed genres' => self::withArrays(new SteamGameData(steamId: 3, title: 'Z'), [5 => 'Indie', 9 => 'Casual'], ['b' => 'Online Co-op']),
            'invalid utf8' => new SteamGameData(steamId: 4, title: "Bad\xff", genres: ["G\xfe"], categories: ["C\xff", 'Multi-player']),
            'many genres' => new SteamGameData(
                steamId: 5,
                title: str_repeat('Long Title ', 40),
                description: str_repeat('desc ', 400),
                genres: array_map(fn (int $i): string => 'Genre '.$i, range(1, 60)),
                categories: array_map(fn (int $i): string => 'Category '.$i, range(1, 60)),
            ),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $genres
     * @param  array<array-key, mixed>  $categories
     */
    private static function withArrays(SteamGameData $game, array $genres, array $categories): SteamGameData
    {
        (new \ReflectionProperty(SteamGameData::class, 'genres'))->setValue($game, $genres);
        (new \ReflectionProperty(SteamGameData::class, 'categories'))->setValue($game, $categories);

        return $game;
    }

    public function test_steam_game_data_transform_parity(): void
    {
        foreach (self::steamGames() as $label => $game) {
            $expectedInfo = $game->toGamesInfoArrayPhp();
            $this->assertSame($expectedInfo, $game->toGamesInfoArrayNative(), "toGamesInfoArray {$label}");
            $expectedMulti = $game->hasMultiplayerPhp();
            $this->assertSame($expectedMulti, $game->hasMultiplayerNative(), "hasMultiplayer {$label}");
            $expectedGenres = $game->getGenresStringPhp();
            $this->assertSame($expectedGenres, $game->getGenresStringNative(), "getGenresString {$label}");

            foreach ([true, false, null] as $toggle) {
                SteamGameData::setUseRustFfi($toggle);
                $this->assertSame($expectedInfo, $game->toGamesInfoArray());
                $this->assertSame($expectedMulti, $game->hasMultiplayer());
                $this->assertSame($expectedGenres, $game->getGenresString());
            }
        }
    }

    public function test_steam_game_data_is_unaffected_by_static_toggle_properties(): void
    {
        $game = self::steamGames()['full'];
        $this->assertArrayNotHasKey('useRustFfi', $game->toArray());
        $this->assertArrayNotHasKey('ffi', $game->toArray());
        $this->assertSame('Cyberpunk 2077', $game->toArray()['title']);
    }

    // ---------------------------------------------------------------------
    // C-ABI contract
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_rust_symbols_resolve_and_honor_contract_codes(): void
    {
        $adder = RustPipelineLibrary::resolveDomain('metadata', TvShowAdder::FFI_CDEF);
        $this->assertNotNull($adder);
        $span = self::ffiCall($adder, 'nntmux_tv_show_adder_normalize_id', 'imdb', 4, ' tt0944947', 10);
        $this->assertSame([3, 7], [$span >> 32, $span & 0xFFFFFFFF]);
        $this->assertSame(-2, self::ffiCall($adder, 'nntmux_tv_show_adder_normalize_id', 'imdb', 4, 'x', 1));
        $this->assertSame(-3, self::ffiCall($adder, 'nntmux_tv_show_adder_normalize_id', 'tvdb', 4, '0', 1));
        $this->assertSame(-1, self::ffiCall($adder, 'nntmux_tv_show_adder_normalize_id', null, 4, '1', 1));
        $this->assertSame(1, self::ffiCall($adder, 'nntmux_tv_show_adder_video_column', 'tvmaze', 6));
        $this->assertSame(-2, self::ffiCall($adder, 'nntmux_tv_show_adder_video_column', 'tvrage', 6));

        $query = RustPipelineLibrary::resolveDomain('metadata', TvProcessingCandidateQuery::FFI_CDEF);
        $this->assertNotNull($query);
        $this->assertSame(5000, self::ffiCall($query, 'nntmux_tv_candidate_backoff_seconds', 0, 2, 5000));
        $this->assertSame(-1, self::ffiCall($query, 'nntmux_tv_candidate_backoff_seconds', 9, 2, 0));

        $state = RustPipelineLibrary::resolveDomain('metadata', TvProcessingRunState::FFI_CDEF);
        $this->assertNotNull($state);
        $this->assertSame(-1, self::ffiCall($state, 'nntmux_tv_run_state_episode_lookup_key', 0, 'Ü', 2, 1, '', 0, null, 0));
        $this->assertSame(-26, self::ffiCall($state, 'nntmux_tv_run_state_episode_lookup_key', 0, 'TVMaze', 6, 77, '', 0, null, 0));

        $pipe = RustPipelineLibrary::resolveDomain('metadata', AbstractTvProviderPipe::FFI_CDEF);
        $this->assertNotNull($pipe);
        $this->assertSame(-2, self::ffiCall($pipe, 'nntmux_tv_pipe_truncate_title', 'short', 5, 45));
        $this->assertSame(2, self::ffiCall($pipe, 'nntmux_tv_pipe_truncate_title', 'ééééé', 10, 4));
        $this->assertSame(-1, self::ffiCall($pipe, 'nntmux_tv_pipe_truncate_title', "\xff\xfe", 2, 1));

        $repair = RustPipelineLibrary::resolveDomain('metadata', RepairTvReleaseLinks::FFI_CDEF);
        $this->assertNotNull($repair);
        $this->assertSame(1, self::ffiCall($repair, 'nntmux_repair_tv_matches_episode', 4, 0, 0, '2024-01-02', 10, 0, 0, '2024-01-02 10:00', 16));
        $this->assertSame(-1, self::ffiCall($repair, 'nntmux_repair_tv_matches_episode', 4, 0, 0, null, 10, 0, 0, '', 0));
        $this->assertSame(7, self::ffiCall($repair, 'nntmux_repair_tv_capped', 9, 7));

        $steam = RustPipelineLibrary::resolveDomain('metadata', SteamGameData::FFI_CDEF);
        $this->assertNotNull($steam);
        $this->assertSame(1, self::ffiCall($steam, 'nntmux_steam_game_data_transform_json', 1, '["Local Co-op"]', 15, null, 0));
        $this->assertSame(-1, self::ffiCall($steam, 'nntmux_steam_game_data_transform_json', 1, '[1]', 3, null, 0));
        $this->assertSame(-1, self::ffiCall($steam, 'nntmux_steam_game_data_transform_json', 7, '[]', 2, null, 0));
    }

    // ---------------------------------------------------------------------
    // Fallback
    // ---------------------------------------------------------------------

    public function test_native_methods_fall_back_to_php_when_library_unavailable(): void
    {
        foreach (self::TOGGLED as $class) {
            (new \ReflectionProperty($class, 'ffi'))->setValue(null, false);
            $class::setUseRustFfi(true);
        }
        $adder = new TvShowAdder;
        $this->assertSame('0944947', $adder->normalizeIdNative('imdb', 'tt0944947'));
        $this->assertSame(['err', \InvalidArgumentException::class, 'TVDB ID must be a positive integer.'], self::outcome(fn () => $adder->normalizeIdNative('tvdb', '0')));
        $this->assertSame('tmdb', $adder->videoColumnForSourceNative('tmdb'));
        $this->assertSame(900, TvProcessingCandidateQuery::transientDelayNative(2));
        $this->assertSame(86400, TvProcessingCandidateQuery::episodeDelayNative(3));
        $this->assertSame('tv:eps-refreshed:tvmaze:5', TvProcessingRunState::refreshKeyNative('TVMaze', 5));
        $this->assertSame('a:5:1x2', TvProcessingRunState::episodeLookupKeyNative('a', 5, '1x2'));
        $this->assertSame('abcdefg...', AbstractTvProviderPipe::truncateTitleNative('abcdefghijklmnop', 10));
        $this->assertTrue(RepairTvReleaseLinks::matchesEpisodeNative(self::parsed(1, 2, null), self::episode(1, 2, '')));
        $this->assertSame(3, RepairTvReleaseLinks::cappedNative(9, 3));
        $game = self::steamGames()['full'];
        $this->assertSame($game->toGamesInfoArrayPhp(), $game->toGamesInfoArrayNative());
        $this->assertSame('RPG, Action', $game->getGenresStringNative());
        $this->assertFalse($game->hasMultiplayerNative());
    }

    // ---------------------------------------------------------------------
    // Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_TV_ADDER_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_TV_ADDER_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $adder = new TvShowAdder;
        $normalize = Closure::bind(fn (string $s, string $i): string => $this->normalizeId($s, $i), $adder, TvShowAdder::class);
        $column = Closure::bind(fn (string $s): string => $this->videoColumnForSource($s), $adder, TvShowAdder::class);
        $refreshKey = Closure::bind(fn (string $p, int $v): string => $this->refreshKey($p, $v), new TvProcessingRunState, TvProcessingRunState::class);
        $command = new RepairTvReleaseLinks;
        $matches = Closure::bind(fn (?ParsedTvRelease $p, TvEpisode $e): bool => $this->matchesEpisode($p, $e), $command, RepairTvReleaseLinks::class);
        $capped = Closure::bind(fn (int $c): int => $this->capped($c), $command, RepairTvReleaseLinks::class);
        (new \ReflectionProperty(RepairTvReleaseLinks::class, 'limit'))->setValue($command, 500);
        $truncate = $this->truncate();
        $games = self::steamGames();

        $commonEpisodes = [[self::parsed(1, 2, null), self::episode(1, 2, '2024-01-02')], [self::parsed(3, 7, null), self::episode(3, 8, '2024-02-02')]];
        $uncommonEpisodes = [[self::parsed(0, 0, '2024-01-02'), self::episode('0', '0', '2024-01-02 21:00:00')], [self::parsed(null, null, '2023-12-31'), self::episode(null, null, null)]];

        $workloads = [
            'TvShowAdder::normalizeId' => [TvShowAdder::class,
                fn () => [$normalize('imdb', 'tt0944947'), $normalize('tvdb', '121361'), $normalize('tmdb', '1399')],
                fn () => [self::outcome(fn () => $normalize('imdb', " \tTT1234567890\n")), self::outcome(fn () => $normalize('trakt', '0000')), self::outcome(fn () => $normalize('imdb', 'nm0000123'))]],
            'TvShowAdder::videoColumnForSource' => [TvShowAdder::class,
                fn () => [$column('tvdb'), $column('tmdb'), $column('imdb')],
                fn () => [$column('trakt'), self::outcome(fn () => $column('tvrage')), self::outcome(fn () => $column(''))]],
            'TvProcessingCandidateQuery::transientDelay' => [TvProcessingCandidateQuery::class,
                fn () => [TvProcessingCandidateQuery::transientDelay(1), TvProcessingCandidateQuery::transientDelay(2, 0), TvProcessingCandidateQuery::transientDelay(3)],
                fn () => [TvProcessingCandidateQuery::transientDelay(9, 86400), TvProcessingCandidateQuery::transientDelay(-1, -5), TvProcessingCandidateQuery::transientDelay(PHP_INT_MAX, PHP_INT_MAX)]],
            'TvProcessingCandidateQuery::episodeDelay' => [TvProcessingCandidateQuery::class,
                fn () => [TvProcessingCandidateQuery::episodeDelay(1), TvProcessingCandidateQuery::episodeDelay(2), TvProcessingCandidateQuery::episodeDelay(3)],
                fn () => [TvProcessingCandidateQuery::episodeDelay(4), TvProcessingCandidateQuery::episodeDelay(0), TvProcessingCandidateQuery::episodeDelay(PHP_INT_MIN)]],
            'TvProcessingRunState::refreshKey' => [TvProcessingRunState::class,
                fn () => [$refreshKey('TVMaze', 1234), $refreshKey('Tmdb', 81189), $refreshKey('Trakt', 7)],
                fn () => [$refreshKey('Ünïcödé Provider', PHP_INT_MAX), $refreshKey(str_repeat('Provider', 20), PHP_INT_MIN), $refreshKey('', 0)]],
            'TvProcessingRunState::firstEpisodeLookup' => [TvProcessingRunState::class,
                function () {
                    $state = new TvProcessingRunState;

                    return [$state->firstEpisodeLookup('TVMaze', 1234, '1x2'), $state->firstEpisodeLookup('TVMaze', 1234, '1x2'), $state->firstEpisodeLookup('Tmdb', 81189, '2024-01-02')];
                },
                function () {
                    $state = new TvProcessingRunState;

                    return [$state->firstEpisodeLookup('Ünïcödé', PHP_INT_MIN, str_repeat('k', 300)), $state->firstEpisodeLookup('', 0, ''), $state->firstEpisodeLookup('A:B', -1, "x\0y")];
                }],
            'AbstractTvProviderPipe::truncateTitle' => [AbstractTvProviderPipe::class,
                fn () => [$truncate('The.Late.Show.with.Stephen.Colbert.2024.01.02.Guest.1080p.WEB.h264-EDITH'), $truncate('The Office US'), $truncate('cURL error 28: Operation timed out after 30001 milliseconds', 80)],
                fn () => [$truncate(str_repeat('日本語のタイトル', 20)), $truncate(str_repeat('🎬 Show ', 40), 80), $truncate("Bad\xff".str_repeat('x', 60))]],
            'RepairTvReleaseLinks::matchesEpisode' => [RepairTvReleaseLinks::class,
                fn () => array_map(fn (array $pair): bool => $matches($pair[0], $pair[1]), $commonEpisodes),
                fn () => array_map(fn (array $pair): bool => $matches($pair[0], $pair[1]), $uncommonEpisodes)],
            'RepairTvReleaseLinks::capped' => [RepairTvReleaseLinks::class,
                fn () => [$capped(1000), $capped(12), $capped(500)],
                fn () => [$capped(PHP_INT_MAX), $capped(PHP_INT_MIN), $capped(0)]],
            'SteamGameData::toGamesInfoArray' => [SteamGameData::class,
                fn () => [$games['full']->toGamesInfoArray(), $games['nulls']->toGamesInfoArray()],
                fn () => [$games['escaping and unicode']->toGamesInfoArray(), $games['many genres']->toGamesInfoArray()]],
            'SteamGameData::hasMultiplayer' => [SteamGameData::class,
                fn () => [$games['full']->hasMultiplayer(), $games['multiplayer only']->hasMultiplayer()],
                fn () => [$games['escaping and unicode']->hasMultiplayer(), $games['many genres']->hasMultiplayer()]],
            'SteamGameData::getGenresString' => [SteamGameData::class,
                fn () => [$games['full']->getGenresString(), $games['nulls']->getGenresString()],
                fn () => [$games['escaping and unicode']->getGenresString(), $games['many genres']->getGenresString()]],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 1337 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-43s | %.2fx / %.2fx     | %.2fx / %.2fx       | %-18s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Rust FFI (true)' : 'PHP (false)'
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $toggle) {
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
