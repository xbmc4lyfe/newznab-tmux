<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\AbstractCategorizer;
use App\Services\Categorization\Categorizers\TvCategorizer;
use App\Services\Categorization\Categorizers\XxxCategorizer;
use App\Services\ConsoleService;
use App\Services\GamesService;
use App\Services\GamesTitleParser;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

final class RustCategorizersTvXxxConsoleGamesServiceTitleParserW61ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        TvCategorizer::class => 'w61TvCategorizePlanBatch',
        XxxCategorizer::class => 'w61XxxCategorizePlanBatch',
        ConsoleService::class => 'w61ConsoleLookupPlanBatch',
        GamesService::class => 'w61GameLookupPlanBatch',
        GamesTitleParser::class => 'w61GameTitleParsePlanBatch',
    ];

    /** Class => Wave 61.2 export. */
    private const SYMBOLS = [
        TvCategorizer::class => 'nntmux_w612_tv_categorize_plan_batch',
        XxxCategorizer::class => 'nntmux_w612_xxx_categorize_plan_batch',
        ConsoleService::class => 'nntmux_w612_console_lookup_plan_batch',
        GamesService::class => 'nntmux_w612_games_lookup_plan_batch',
        GamesTitleParser::class => 'nntmux_w612_game_title_parse_plan_batch',
    ];

    /** Class => class-distinct C declaration constant. */
    private const CDEFS = [
        TvCategorizer::class => 'W61_TV_CATEGORIZER_FFI_CDEF',
        XxxCategorizer::class => 'W61_XXX_CATEGORIZER_FFI_CDEF',
        ConsoleService::class => 'W61_CONSOLE_SERVICE_FFI_CDEF',
        GamesService::class => 'W61_GAMES_SERVICE_FFI_CDEF',
        GamesTitleParser::class => 'W61_GAMES_TITLE_PARSER_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        TvCategorizer::class => 32,
        XxxCategorizer::class => 35,
        ConsoleService::class => 30,
        GamesService::class => 25,
        GamesTitleParser::class => 32,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        TvCategorizer::class => 'W61_TV_CATEGORIZER_TAIL',
        XxxCategorizer::class => 'W61_XXX_CATEGORIZER_TAIL',
        ConsoleService::class => 'W61_CONSOLE_SERVICE_TAIL',
        GamesService::class => 'W61_GAMES_SERVICE_TAIL',
        GamesTitleParser::class => 'W61_GAMES_TITLE_PARSER_TAIL',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 61, 60
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 600 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 7-8). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        TvCategorizer::class => [8.752, 6.202, 10.712, 8.712],
        XxxCategorizer::class => [9.098, 6.783, 9.573, 7.547],
        ConsoleService::class => [7.814, 5.408, 6.877, 6.457],
        GamesService::class => [5.682, 4.021, 4.330, 4.051],
        GamesTitleParser::class => [5.463, 4.408, 4.505, 4.058],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 60;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const WORDS = ['the', 'great', 'night', 'big', 'game', 'tool', 'pro', 'saga', 'old', 'new', 'dark', 'star', 'wild', 'hunt', 'city', 'last'];

    private const TV_TAGS = ['720p', '1080p', '2160p', '480p', 'HDTV', 'WEB-DL', 'WEBRip', 'WEB.DL', 'BluRay', 'x264', 'x265', 'HEVC', 'XviD', 'AMZN', 'NF',
        'DSNP', 'PROPER', 'REPACK', 'German', 'FRENCH', 'Complete', 'DVDRip', 'DSR', 'UHD', '4K', 'Docu', 'SDTV'];

    private const XXX_TAGS = ['XXX', '1080p', '720p', '2160p', '4K', 'MP4', 'mkv', 'WMV', 'x264', 'HEVC', 'XviD', 'DVDR', 'DVD9', 'ImageSet', 'Pack', 'SiteRip',
        'OnlyFans', 'VR180', 'Oculus', 'WEB-DL', 'SD', 'HD', 'Anal', 'MILF', 'POV', 'Teen', 'Hardcore', 'SDX264XXX'];

    private const STUDIOS = ['Brazzers', 'Vixen', 'Blacked', 'Tushy', 'RealityKings', 'NaughtyAmerica', 'MetArt', 'TeamSkeet', 'Private', 'EvilAngel', 'WankzVR',
        'BadoinkVR', 'Hegre', 'Tiny4K', 'FakeTaxi'];

    private const PLATFORMS = ['XBOX360', 'X360', 'XBOXONE', 'PS2', 'PS3', 'PS4', 'PS5', 'PSP', 'PSVITA', 'Vita', 'WII', 'WiiU', 'NDS', '3DS', 'NGC', 'N64', 'SNES',
        'NES', 'XBLA', 'WiiWare', 'PSX', 'PSX2PSP', 'Switch', 'NSW', 'X-BOX', 'GC'];

    private const REGIONS = ['PAL', 'NTSC', 'USA', 'EUR', 'JPN', 'JAP', 'ASIA', 'AUS', 'RF', 'RegionFree', 'NTSC-J', 'KOR'];

    private const CONSOLE_FLAGS = ['MULTi5', 'MULTI', 'PROPER', 'REPACK', 'RETAIL', 'DEMO', 'DLC', 'PATCHED', 'NFOFIX', 'READNFO', 'DVD9', 'FULLDVD', 'v1.02', 'UPDATE',
        'iSO'];

    private const SCENE = ['CODEX', 'PLAZA', 'GOG', 'CPY', 'EMPRESS', 'RUNE', 'TENOKE', 'FLT', 'RELOADED', 'SKIDROW', 'PROPHET', 'DOGE', 'ElAmigos', 'FitGirl',
        'DODI', 'I_KnoW', 'TiNYiSO', 'DARKSiDERS', 'KaOs', '3DMGAME', 'P2P', 'RAZOR1911'];

    private const GAME_TAGS = ['Deluxe.Edition', 'GOTY', 'Ultimate', 'Definitive.Edition', 'Remastered', 'Complete', 'DLC', 'Unlocker', 'Update.5', 'Patch',
        'Hotfix', 'Crack', 'CrackFix', 'REPACK', 'PROPER', 'MULTi12', 'MULTI', 'iSO', 'v1.2.3', 'Build.1234', 'Build15', 'Linux', 'MacOS', 'x64', 'Win64', 'Incl',
        'READNFO', 'RETAIL'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW61RustFfi', 'withW61RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w61RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame($class, $state->getDeclaringClass()->getName(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertSame(['enabled', 'ffi', 'out', 'capacity'], array_keys($state->getStaticVariables()), $class.' keeps Wave 61 state in function-local statics');
            self::assertTrue($reflection->hasConstant('W61_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W61_RUST_FFI_DEFAULT'), $class);
            $default = $reflection->getReflectionConstant('W61_RUST_FFI_DEFAULT');
            self::assertInstanceOf(ReflectionClassConstant::class, $default, $class);
            self::assertTrue($default->isPublic(), $class);
            self::assertSame($class, $default->getDeclaringClass()->getName(), $class);
            self::assertSame($class, $reflection->getReflectionConstant(self::CDEFS[$class])?->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w61', strtolower((string) $property), 'Wave 61 state lives in w61RustFfiState().');
            }
        }
        // The CDEF names are class-distinct, so no class shadows another's declaration.
        self::assertCount(5, array_unique(self::CDEFS));
        foreach ([TvCategorizer::class, XxxCategorizer::class] as $class) {
            self::assertSame(AbstractCategorizer::class, get_parent_class($class), $class);
            self::assertFalse((new ReflectionClass(AbstractCategorizer::class))->hasConstant('W61_RUST_FFI_DEFAULT'));
            self::assertSame(AbstractCategorizer::W60_RUST_FFI_DEFAULT, (new ReflectionClass($class))->getConstant('W60_RUST_FFI_DEFAULT'), $class);
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            TvCategorizer::class => ['getName', 'shouldSkip', 'shouldSkipPhp', 'categorize', 'categorizePhp', 'useW433RustFfi', 'usesW433RustFfi',
                'resetW433RustFfi', 'summarizeTvContentBatch', 'summarizeTvContentBatchPhp', 'summarizeTvContentBatchNative', 'usesW60RustFfi', 'withW60RustFfi',
                'w60CategorizerGateBatch', 'usesAbstractCategorizerW60RustFfi', 'withAbstractCategorizerW60RustFfi'],
            XxxCategorizer::class => ['getName', 'categorize', 'categorizePhp', 'useW433RustFfi', 'usesW433RustFfi', 'resetW433RustFfi', 'summarizeXxxFormatBatch',
                'summarizeXxxFormatBatchPhp', 'summarizeXxxFormatBatchNative', 'usesW60RustFfi', 'withW60RustFfi', 'w60CategorizerGateBatch'],
            ConsoleService::class => ['useW404RustFfi', 'usesW404RustFfi', 'resetW404RustFfi', 'summarizeConsolePlatformBatch', 'summarizeConsolePlatformBatchPhp',
                'setUseRustFfi', 'getConsoleInfo', 'getConsoleInfoByName', 'getConsoleRange', 'processConsoleReleases', 'parseTitle', 'parseTitleNative',
                'replacePlatform', 'replacePlatformNative'],
            GamesService::class => ['useW404RustFfi', 'usesW404RustFfi', 'resetW404RustFfi', 'summarizePcGameLookupBatch', 'summarizePcGameLookupBatchPhp',
                'setUseRustFfi', 'usesRustFfi', 'parseTitle', 'parseTitleNative', 'parseTitlePhp', 'cleanGameReg', 'cleanGameRegPhp', 'cleanSearchTitle',
                'extractYearFromText', 'matchReleaseYear', 'normalizeTitleForMatch', 'levenshteinPercent', 'computeTitleSimilarity', 'detectGenreKey',
                'processGamesReleases', 'matchGenreName'],
            GamesTitleParser::class => ['parse', 'cleanReleaseNamePhp', 'cleanVersionTokensPhp', 'fallbackParsePhp', 'normalizeForMatch', 'normalizeForMatchPhp',
                'computeSimilarity', 'computeSimilarityPhp', 'cleanVersionTokensNative', 'fallbackParseNative', 'computeSimilarityNative', 'useW434RustFfi',
                'usesW434RustFfi', 'resetW434RustFfi', 'summarizeGameReleaseBatch', 'summarizeGameReleaseBatchNative', 'summarizeGameReleaseBatchPhp'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        // The earlier-wave helpers answer the same whichever way the Wave 61 toggle points.
        $parser = new GamesTitleParser;
        $earlier = static fn (): array => [
            $parser->parse('Portal.2.v1.0-GOG'), $parser->computeSimilarityPhp('portal 2', 'portal two'), GamesTitleParser::summarizeGameReleaseBatchPhp(['Portal.2-GOG']),
            ConsoleService::summarizeConsolePlatformBatchPhp(['Halo.3.PAL.XBOX360-GRP']), GamesService::summarizePcGameLookupBatchPhp(['Portal.2-GOG']),
            TvCategorizer::summarizeTvContentBatchPhp(['Show.S01E01.720p']), XxxCategorizer::summarizeXxxFormatBatchPhp(['Brazzers.XXX.1080p']),
            AbstractCategorizer::w60CategorizerGateBatch(['Show.S01E01|grp|50|0.9|5040']),
        ];
        $baseline = $earlier();
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([true, false] as $enabled) {
                self::assertEquals($baseline, $class::withW61RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW61RustFfi(), $class);
            if (min($speedups) < 1.5) {
                self::assertFalse($class::usesW61RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW61RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW61RustFfi(! $enabled, static fn (): bool => $class::usesW61RustFfi());

                    return [$class::usesW61RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW61RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW61RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW61RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW61RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW61RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 61 toggles leave the earlier switches (including the inherited Wave 60 ones) untouched.
        $earlier = [
            TvCategorizer::class => ['usesW433RustFfi', 'usesW60RustFfi', 'usesAbstractCategorizerW60RustFfi'],
            XxxCategorizer::class => ['usesW433RustFfi', 'usesW60RustFfi', 'usesAbstractCategorizerW60RustFfi'],
            ConsoleService::class => ['usesW404RustFfi'],
            GamesService::class => ['usesW404RustFfi', 'usesRustFfi'],
            GamesTitleParser::class => ['usesW434RustFfi'],
        ];
        foreach ($earlier as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW61RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class);
        }
        // AbstractCategorizer's Wave 60 toggle (through the base or either subclass) leaves the Wave 61 toggles alone.
        foreach ([AbstractCategorizer::class, TvCategorizer::class, XxxCategorizer::class] as $switch) {
            foreach ([true, false] as $enabled) {
                $inside = $switch::withW60RustFfi($enabled, static fn (): array => [
                    AbstractCategorizer::usesW60RustFfi(), TvCategorizer::usesW61RustFfi(), XxxCategorizer::usesW61RustFfi(),
                ]);
                self::assertSame([$enabled, $this->default(TvCategorizer::class), $this->default(XxxCategorizer::class)], $inside, $switch);
            }
            self::assertSame(AbstractCategorizer::W60_RUST_FFI_DEFAULT, AbstractCategorizer::usesW60RustFfi(), $switch);
        }
        foreach ([TvCategorizer::class, XxxCategorizer::class] as $class) {
            foreach ([true, false] as $enabled) {
                $inside = $class::withW61RustFfi($enabled, static fn (): array => [AbstractCategorizer::usesW60RustFfi(), $class::usesW60RustFfi(), $class::usesW61RustFfi()]);
                self::assertSame([AbstractCategorizer::W60_RUST_FFI_DEFAULT, AbstractCategorizer::W60_RUST_FFI_DEFAULT, $enabled], $inside, $class);
            }
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6100));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW61RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes and keyed or non-sequential columns keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["Show\0.S01E01.720p\tgrp", "Halo.3.XBOX360-GRP\tHalo 3\t2007", 'Brazzers.XXX.1080p']);
            $this->assertParity($class, ['k' => "Game.v1.2-CODEX\tGame", 'j' => 'Name.S01E02.HDTV']);
            $this->assertParity($class, [1 => "Title.PAL.PS3\tx", 0 => str_repeat('a', 40)."\t\t1"]);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            $words = 1 + self::TAIL[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 64]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 64]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 64]), $class);
        }
    }

    public function test_tv_categorize_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Some.Show.S01E02.720p.HDTV.x264-GRP\talt.binaries.teevee", "[SubsPlease] Frieren - 12 (1080p) [ABCD1234]\talt.binaries.anime",
            "NFL.2023.09.10.Game.720p\t", "Show.1x02.WEB-DL\t", "Docu.Series.S03.Complete.German.WEB.DL\t", 'Show.E05.BluRay', 'Movie.2019.1080p', 'Show s01 e03 xvid',
            '', "x\tAnime"];
        $this->assertFields(TvCategorizer::class, $rows, 0xFFFFFFFFFFFF, [
            2 | 1 << 2 | 1 << 6 | 1 << 17 | 1 << 20 | 2 << 30 | 4 << 44, 2 | 1 << 9 | 7 << 44, 2 | 66 << 9 | 4 << 17 | 123 << 20 | 298 << 30 | 6 << 44,
            2 << 2 | 1 << 16 | 3 << 17 | 1 << 20 | 2 << 30 | 1 << 44, 2 << 2 | 28 << 9 | 1 << 16 | 2 << 17 | 3 << 20 | 8 << 44, 4 << 2 | 5 << 17 | 5 << 30 | 4 << 44,
            2 | 64 << 9, 4 << 6 | 1 << 17 | 1 << 20 | 3 << 30 | 3 << 44, 0, 1 << 9 | 7 << 44,
        ], [2, 1, 0, 1, 2, 0, 1, 2, 1, 0, 6, 0, 4, 0, 4, 2, 1, 1, 1, 1, 2, 1, 1, 1, 1, 0, 2, 2, 6, 1, 3]);
        $word = TvCategorizer::w61TvCategorizePlanBatchPhp(['Some.Show.S01E02.720p'])[0];
        self::assertSame([4, 21], [$word >> 48 & 0xFF, $word >> 56 & 0x7F]);
        self::assertSame(Category::TV_ANIME, TvCategorizer::W61_TV_CATEGORIES[7]);
        $this->assertRandomParity(TvCategorizer::class, 6110);
    }

    public function test_xxx_categorize_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Brazzers.23.05.12.Name.XXX.1080p.MP4-KTR\t", "OnlyFans.Name.Pack\t", "WankzVR.Name.Oculus\t", "Some.Movie.XXX.DVDR\t", "Name Imageset XXX\t",
            "Clip.wmv\tanon@Y.com", 'Normal.Show.S01E01.720p', 'XXX SDX264XXX'];
        $this->assertFields(XxxCategorizer::class, $rows, 0x7FFFFFFF, [
            2 | 1 << 6 | 515 << 10 | 11 << 27, 40 << 10 | 1 << 27, 128 << 10, 65 << 10 | 9 << 27, 17 << 10 | 3 << 27, 8 << 2 | 4096 << 10 | 6 << 27, 2 | 1024 << 10,
            2049 << 10 | 7 << 27,
        ], [2, 1, 0, 1, 0, 0, 1, 1, 0, 1, 0, 1, 0, 0, 6, 0, 2, 0, 4, 1, 0, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 6, 0, 1]);
        $word = XxxCategorizer::w61XxxCategorizePlanBatchPhp(['MILF.Anal.POV.Teen.720p'])[0];
        self::assertSame([4, 12, 5], [$word >> 23 & 15, $word >> 27 & 15, $word >> 31 & 0xFF]);
        self::assertSame(Category::XXX_ONLYFANS, XxxCategorizer::W61_XXX_CATEGORIES[1]);
        $this->assertRandomParity(XxxCategorizer::class, 6120);
    }

    public function test_console_lookup_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ['Halo.3.PAL.XBOX360-GRP', 'Rock.Band.DLC.XBLA-XYZ', 'Game.Title.USA.PS3-Group_1', 'Mario.Kart.MULTI5.NDS', 'PS3.Only', 'Title.Only', 'Some.X-BOX.Title',
            'Game.v1.02.PSP-iSO'];
        $this->assertFields(ConsoleService::class, $rows, (1 << 37) - 1, [
            17 | 1 << 5 | 1 << 7 | 1 << 12 | 2 << 30 | 2 << 32, 1 | 1 << 5 | 1 << 7 | 32 << 18 | 3 << 30 | 2 << 32, 7 | 2 << 5 | 6 << 7 | 2 << 12 | 2 << 30 | 2 << 32,
            22 | 3 << 5 | 3 << 7 | 1 << 18 | 2 << 30 | 2 << 32, 7 | 2 << 5 | 6 << 7, 1 << 30 | 2 << 32, 19 | 1 << 5 | 2 << 30 | 1 << 32,
            12 | 2 << 5 | 8 << 7 | 2560 << 18 | 2 << 30 | 1 << 32,
        ], [1, 1, 5, 1, 1, 3, 3, 1, 1, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 1, 6, 4, 12]);
        $word = ConsoleService::w61ConsoleLookupPlanBatchPhp(['Halo.3.PAL.XBOX360-GRP'])[0];
        self::assertSame([6, 1, crc32('halo 3') & 0x1FFFF], [$word >> 37 & 0xFF, $word >> 45 & 1, $word >> 46 & 0x1FFFF]);
        // Lookup codes name the replacePlatform() targets.
        $names = ['Xbox 360', 'Xbox One', 'Nintendo DS', 'Nintendo 3DS', 'PlayStation2', 'PlayStation 3', 'PlayStation 4', 'Sony PSP', 'PlayStation Vita', 'PlayStation',
            'Nintendo Wii U', 'Nintendo Wii', 'GameCube', 'Nintendo 64', 'Nintendo NES', 'SNES'];
        $replace = ['X360' => 'Xbox 360', 'XBOXONE' => 'Xbox One', 'NDS' => 'Nintendo DS', '3DS' => 'Nintendo 3DS', 'PS2' => 'PlayStation2', 'PS3' => 'PlayStation 3',
            'PS4' => 'PlayStation 4', 'PSP' => 'Sony PSP', 'PSVITA' => 'PlayStation Vita', 'PSX' => 'PlayStation', 'WIIU' => 'Nintendo Wii U', 'WII' => 'Nintendo Wii',
            'NGC' => 'GameCube', 'N64' => 'Nintendo 64', 'NES' => 'Nintendo NES', 'SNES' => 'SNES', 'XBOX360' => 'Xbox 360', 'PSX2PSP' => 'PlayStation'];
        foreach ($replace as $platform => $target) {
            $lookup = ConsoleService::w61ConsoleLookupPlanBatchPhp(['Title.'.$platform])[0] >> 7 & 31;
            self::assertSame($target, $names[$lookup - 1] ?? null, $platform);
        }
        $this->assertRandomParity(ConsoleService::class, 6130);
    }

    public function test_game_lookup_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Cyberpunk.2077.Ultimate.Edition-CODEX\tCyberpunk 2077\t2020", "The.Witcher.3.Wild.Hunt.GOTY-GOG\tThe Witcher 3 Wild Hunt\t2015",
            "Portal.2.v1.0-XYZ\tPortal 2\t", "Game.2019-I_KnoW\tGame\t2021", "\t\t"];
        $this->assertFields(GamesService::class, $rows, (1 << 42) - 1, [
            1 | 513 << 2 | 2 << 13 | 1 << 15 | 66 << 17 | 1 << 24 | 1 << 30 | 2 << 36, 1 | 1 << 2 | 3 << 15 | 100 << 17 | 5 << 24 | 5 << 30 | 5 << 36,
            2 | 128 << 2 | 3 << 15 | 100 << 17 | 2 << 24 | 2 << 30 | 2 << 36, 1 | 512 << 2 | 2 << 13 | 1 << 15 | 100 << 17 | 1 << 24 | 1 << 30 | 1 << 36, 0,
        ], [1, 2, 0, 2, 1, 3, 1, 2, 0, 0, 0, 0, 0, 0, 1, 0, 2, 0, 3, 0, 2, 0, 200, 3]);
        $word = GamesService::w61GameLookupPlanBatchPhp(["Game.2019-CODEX\tGame\t2020"])[0];
        self::assertSame([3, 3, 119, 15], [$word >> 13 & 3, $word >> 15 & 3, $word >> 42 & 0xFF, $word >> 50 & 0x1FFF]);
        $this->assertRandomParity(GamesService::class, 6140);
    }

    public function test_game_title_parse_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["The.Witcher.3.Wild.Hunt.v1.32.1.MULTi12-CODEX.iso\tThe Witcher 3 Wild Hunt", "[PC] Portal 2 (2011)-GOG\tportal 2",
            "Game.Update.5.Build.1234-PLAZA\tGame", "\tx", 'Some.Game.2019.REPACK-FitGirl'];
        $this->assertFields(GamesTitleParser::class, $rows, (1 << 34) - 1, [
            4 | 285 << 4 | 3 << 17 | 1 << 19 | 1 << 20 | 100 << 21 | 5 << 28, 1038 << 4 | 3 << 17 | 1 << 19 | 1 << 20 | 100 << 21 | 2 << 28,
            108 << 4 | 3 << 17 | 1 << 19 | 1 << 20 | 100 << 21 | 1 << 28, 0, 1164 << 4 | 1 << 17 | 2 << 28,
        ], [4, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 1, 1, 4, 4, 1, 1, 1, 1, 1, 0, 2, 0, 0, 1, 1, 0, 3, 3, 4, 300]);
        $word = GamesTitleParser::w61GameTitleParsePlanBatchPhp(['The.Witcher.3.Wild.Hunt-CODEX'])[0];
        self::assertSame([23, crc32('the witcher 3 wild hunt') & 0x1FFFFF], [$word >> 34 & 0xFF, $word >> 42 & 0x1FFFFF]);
        $this->assertRandomParity(GamesTitleParser::class, 6150);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $items): void
    {
        $this->requireBindings();
        $this->assertParity($class, $items);
        // NUL items cannot be framed (the export refuses them and the entry point falls back).
        if ($items !== [] && ! str_contains(implode('', $items), "\0")) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        $shared = ['', ' ', "\t", "\t\t\t\t", "\x0b", '-', '.', '[', ']', '[]', '-a', 'a-', str_repeat('-', 30), str_repeat("\t", 5000).'1', 'S', 'E', 'V', 'X', '0',
            '9999', '1969', '1970', '1899', '1900', '2099', '2100', 'multi', 'MULTI1', 'multi123', 'v', 'v1', 'v12345678', 'v1234567890', 'build', 'build0',
            "\xC9\xE1", "x\xA0box", 'A-B-C-D', 'a_b-c_d', str_repeat('x', 21).'-'.str_repeat('y', 21), 'end-'.str_repeat('Z', 20), 'end-'.str_repeat('Z', 21)];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge and binary' => [$class, [$long, $binary, $long."\t".$long, $long."\t".$binary."\t".$long, ...$shared]];
        }
        yield 'tv bounds' => [TvCategorizer::class, [
            's1e1', 's001e0001', 's0001e1', 's1e00001', 'S1E', 'SE1', 's1', 's999', 's1000', 's01.e01', 's01 e1234', 's01.e12345', 's01.ep01', 'x01', '1x1', '1x01', '12x123',
            '123x12', '1x1234', '2023.12.31', '2023.13.01', '2023.12.32', '2023.00.10', '2023.1.01', '1899.01.01', '2100.01.01', 'e1', 'e12345', 'ep1', 'ep12345',
            'ep.5', 'Episode 12', 'episode.12345', 'Episode', 'ep', 'web.dl', 'web-rip', 'WEB', 'webdl', 'web.dlx', 'Show.S01E01-ANIME', "x\tanime", "x\tAnImE",
            "x\t ani me", "NFL\t", 'NFL.Game', 'NFL.Game.2023', 'NFL.Game.S01E01', 'Docu.2160p', 'docuseries.s01', 'German.Show', 'SD.TV', 'sdtv.s01',
            'Show.S01E01.DVD', 'Show.S01E01.XviD', 'Show.S01E01', 'Show.S01E01.2160p.WEB-DL', 'Show.S01E01.1080p.WEB-DL', 'Show.S01E01.BluRay',
            "Show.S01E01\t\xff\xfe group ", "Show.S01E01\tGroup", "Show.S01E01\tgroup", "Show.S01E01\t\x0bgroup\x0b", "Show.HDTV\t", 'PDTV', 'Proper.S01.Repack',
            str_repeat('a.', 300).'S01E01', 'S01E01.'.str_repeat('Name.', 300), str_repeat('Z', 200).'.S99E9999',
        ]];
        yield 'xxx bounds' => [XxxCategorizer::class, [
            'XXX', 'xXx.720p', 'XXX.2160p', 'XXX.UHD', 'XXX.4K.Pack', 'Brazzers.2023.12.31.x', 'Brazzers.23.12.31.x', 'Brazzers.23.13.31', 'Brazzers.23.12.32',
            'Brazzers.123.12.31', 'Brazzers.1899.01.01', 'S01E01.XXX', 'S1E1', 'S001E01', 'S01E001', 'SE01', 'abcvr', 'abvr', '1abcvr', 'ABCDVR.720p',
            'VR.XXX', 'vr180.Anal', 'XXX.ImageSet.mp4', 'XXX.ImageSet', 'XXX.Pics.x264', 'XXX.Pack.WMV', 'XXX.wmv', 'XXX.DVD', 'XXX.DVD.XviD', 'XXX.DVDRip',
            'XXX.x264', 'XXX.HEVC', 'XXX.HD', 'XXX.SD', 'XXX.mov', 'Anal.MILF.POV.Teen.Hardcore.Lesbian.Creampie.BBW.BDSM.Fetish.Facial.Squirt.Porn.Sex.Adult.JAV',
            "Clip\tANON@Y.COM", "Clip\tx md-hobbys.com x", "Clip\toz@lot.co", "Clip\t oz@lot.com ", "XXX\t ", "XXX\tPoster", "XXX\tposter", "XXX\t\x0bposter",
            'SDPORN', 'sdx264xxx.720p', 'OnlyFans', 'fansly.vr', 'Private.Pics', 'Studio.Only.Brazzers', 'WEB-DL.XXX', 'TeamSkeet.23.01.02.Name.1080p',
        ]];
        yield 'console bounds' => [ConsoleService::class, [
            'PS3', 'Title.PS3', 'Title.PS 3', 'Title.ps3.PAL', 'Title.X-Box', 'Title.X.BOX', 'Title.X BOX360', 'Title.XBOX', 'Title.X', 'Title.XBLA', 'Title.DLC.XBLA',
            'Title.XBLA.DLC', 'Title.DLC', 'Title.MULTi', 'Title.MULTi12', 'Title.MULTi123', 'Title.v1', 'Title.v1234567', 'Title.v12345678', 'Title.V',
            'Title.PAL.NTSC.JPN.ASIA.AUS.RF', 'Title.RegionFree.Switch', 'Title.NSW', 'Title.GC.NGC', 'Title.WiiWare', 'Title.PSX2PSP', 'Title.Vita.PSVita',
            'Title-', 'Title-A', 'Title-A_b', 'Title-A.b', 'Title-'.str_repeat('Q', 20), 'Title-'.str_repeat('Q', 21), 'Title-Grp-PS3', '-PS3', 'PS3-',
            'A.Very.Long.Title.With.Many.Words.Before.The.Platform.Tag.That.Exceeds.Thirty.One.Title.Tokens.One.Two.Three.Four.Five.Six.Seven.Eight.Nine.Ten.PS4',
            str_repeat('Word.', 80).'PS4', 'Title.UPDATE.PS4', 'Title.Patch.PSP', 'Title.ISO.PS2', 'Title.READNFO.NDS', 'Title.FullDVD.XBOX360', 'Title.US.PS3',
        ]];
        yield 'games bounds' => [GamesService::class, [
            "Game-CODEX\tGame\t", "Game-codex\tgame\t", "Game-UNKNOWN\tGame\t", "Game-\tGame\t", "Game-A.B\tGame\t", "Game-I_KnoW\tGame\t", "Game-GOG-GAMES\tGame\t",
            "Game.2019\tGame\t2019", "Game.2019\tGame\t2018", "Game.2019\tGame\t2020", "Game.2019\tGame\t2021", "Game.2019\tGame\t 2019 ", "Game.2019\tGame\t19",
            "Game.2019\tGame\t1969", "Game.2019\tGame\t2100", "Game.1969\tGame\t1969", "Game.2100\tGame\t2100", "Game\tGame\t20190", "Game\t\t", "\tGame\t",
            "A.B.C.D\tA B\t", "A.B.C.D\tA B C D E F\t", "A.A.A\tA\t", "Game.v1\tGame\t", "Game.v12345678\tGame\t", "Game.Build\tGame\t", "Game.Build1\tGame\t",
            "Game.Build1234567890\tGame\t", "Game.MULTi\tGame\t", "Game.MULTi123\tGame\t", "Game.Mac.Linux.OSX\tGame\t", "Game.Edition.DLC.Patch.Crack.Rip.iSO\tGame\t",
            "Core.Game\tCore Game\t", "Game\tGame\tx\ty", str_repeat('Long.', 100)."\t".str_repeat('Long ', 100)."\t2000", "0.00.000\t0 00 000\t",
        ]];
        yield 'title bounds' => [GamesTitleParser::class, [
            'Game.zip', 'Game.ZIP', 'Game.7z', 'Game.tar', 'Game.', '.iso', '.', 'Game.iso.rar', '[PC]Game', '[PC Game', '[]Game', '[[x]]Game', ' [PC]Game', '[PC]-CODEX',
            'Game-CODEX.iso', 'Game.v1.2.3.4', 'Game.v1.2.Repack.3', 'Game.Build.12.13', 'Game.Build12.13', 'Game.Update.Patch.5', 'Game.Update12.5', 'Game.Hotfix.1.v2',
            'Game.MULTi12', 'Game.2019.2020', 'Game.x64.Win64.Incl.DLC.DLCs', 'Game.Crack.CrackFix', "Game\tGame", "Game\tgame", "Game\tGame Two", "Game Two\tGame",
            "A.B\tB A", "A.A.B\tA B", "\t\t", "Game-I_KnoW\tGame", "Game-GOG-GAMES\tGame", "Game.Update\t", "v1.2.3\tv1", '2019.2020.2021', 'Game.V2', 'Game.v',
            "Game.1.2\tGame 1 2", str_repeat('Name.', 300).'iso', '[x]'.str_repeat('a', 200).'-CODEX.zip',
        ]];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical rows, Uncommon batches 320-640
     * malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every round's
     * samples; per-round medians are reported too. Every pair must agree; raw pairs are written
     * outside the checkout. A class must default to Rust exactly when all four metrics reach 1.5x.
     * Gated behind NNTMUX_W612_BENCH=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W612_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W612_BENCH=1 to run the Wave 61.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(61));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $items = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW61RustFfi($native, fn (): array => $this->timed($class, $items));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][$round][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $rounds) {
                $php = $rust = $roundMeans = $roundP99s = [];
                foreach ($rounds as $pairs) {
                    $roundPhp = array_column($pairs, 0);
                    $roundRust = array_column($pairs, 1);
                    $roundMeans[] = array_sum($roundPhp) / array_sum($roundRust);
                    $roundP99s[] = $this->p99($roundPhp) / $this->p99($roundRust);
                    array_push($php, ...$roundPhp);
                    array_push($rust, ...$roundRust);
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'round_median_mean_speedup' => round($this->median($roundMeans), 3), 'round_median_p99_speedup' => round($this->median($roundP99s), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w612-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'summary' => $summary, 'records' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW61.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW61RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() changes neither implementation
     * and the exports keep running. Declared last: once a script sets a locale, later regexes
     * keep the rebuilt tables.
     */
    public function test_parity_holds_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $cases = [
                    TvCategorizer::class => ["SH\xD6W.S01E01.HDTV\tI.ANIME", "\xC9PISODE.12.WEB-DL\t", "INFO.S\xC901E01\tGROUP"],
                    XxxCategorizer::class => ["BRAZZERS.XXX.\xC9.1080P\tANON@Y.COM", "I.XXX.IMAGESET\t\xDD", 'FISTVR.XXX'],
                    ConsoleService::class => ["TITL\xC9.PAL.XBOX360-GR\xDDP", 'I.TITLE.MULTI5.PS3-I', "\xC9\xE1.PS3"],
                    GamesService::class => ["GAME.I.EDITION-CODEX\tgame i\t2020", "\xC9GAME-I_KNOW\tGAME\t2020"],
                    GamesTitleParser::class => ["[PC]GAME.I.V1.2-CODEX.ISO\tgame i", "GAM\xC9.ISO\tGAM"],
                ];
                foreach ($cases as $class => $items) {
                    $this->assertParity($class, $items);
                    self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
                }
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * Documented per-row fields (`word & $mask`) and summary words (without the digest) from both
     * implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $rows
     * @param  list<int>  $fields
     * @param  list<int>  $tail
     */
    private function assertFields(string $class, array $rows, int $mask, array $fields, array $tail): void
    {
        $this->assertParity($class, $rows);
        foreach (['Php', 'Native'] as $suffix) {
            $words = $this->call($class, $suffix, $rows);
            self::assertSame($fields, array_map(static fn (int $word): int => $word & $mask, \array_slice($words, 0, \count($rows))), $class.'::'.$suffix);
            self::assertSame($tail, \array_slice($words, \count($rows), -1), $class.'::'.$suffix);
        }
        self::assertSame($this->call($class, 'Php', $rows), $this->exported($class, $rows), $class);
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $items): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $items);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W61_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $items): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix]($items);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items): void
    {
        $php = $class::withW61RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW61RustFfi(true, fn (): array => $this->call($class, '', $items));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $items), $class);
        self::assertSame($php, $this->call($class, 'Native', $items), $class);
        self::assertCount(\count($items) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 320 rows each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $batch = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $batch);
                self::assertSame($this->call($class, 'Php', $batch), $this->exported($class, $batch), $class);
                $items += \count($batch);
            }
        }
        self::assertGreaterThanOrEqual(300 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     * @return list<int>
     */
    private function exported(string $class, array $items): array
    {
        $ffi = $this->ffi($class);
        $count = \count($items);
        $cap = $count + 64;
        $out = $ffi->new('int64_t['.$cap.']');
        $blob = implode("\0", $items);
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $count, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w61RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w61 binding');

        return $ffi;
    }

    /** Each Wave 61.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W61 C declaration.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<string>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $items = [];
        for ($i = $uncommon ? $random->getInt(320, 640) : 320; $i > 0; $i--) {
            $items[] = match ($class) {
                TvCategorizer::class => $this->tvRow($random, $uncommon),
                XxxCategorizer::class => $this->xxxRow($random, $uncommon),
                ConsoleService::class => $this->consoleRow($random, $uncommon),
                GamesService::class => $this->gamesRow($random, $uncommon),
                GamesTitleParser::class => $this->titleRow($random, $uncommon),
            };
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-[]() \t", $length);
    }

    /** Case-flips, pads or corrupts a field the way hand-edited or truncated data does. */
    private function mangle(Randomizer $random, string $value): string
    {
        return match ($random->getInt(0, 6)) {
            0 => strtoupper($value),
            1 => ucfirst(strtolower($value)),
            2 => $this->pick($random, [' ', "\t", "\r\n", "\x0b", '']).$value.$this->pick($random, [' ', "\r\n", "\x0b", '']),
            3 => substr($value, 0, $random->getInt(0, max(0, \strlen($value) - 1))),
            4 => $value.$this->noise($random, 3),
            5 => str_replace(['a', 'e', 'o', '.'], ["\xE1", "\xC9", '0', $this->pick($random, ['_', ' ', '-', '..'])], $value),
            default => $value,
        };
    }

    /** 1-4 capitalised words joined by $separator. */
    private function title(Randomizer $random, string $separator): string
    {
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }

        return implode($separator, $words);
    }

    private function tvRow(Randomizer $random, bool $uncommon): string
    {
        $separator = $this->pick($random, ['.', '.', '.', '_', ' ']);
        $marker = match ($random->getInt(0, 7)) {
            0, 1, 2 => \sprintf('S%02dE%02d', $random->getInt(1, 30), $random->getInt(1, 40)),
            3 => \sprintf('S%02d', $random->getInt(1, 20)).$separator.$this->pick($random, ['Complete', 'COMPLETE', '1080p']),
            4 => \sprintf('%dx%02d', $random->getInt(1, 15), $random->getInt(1, 30)),
            5 => \sprintf('%d%s%02d%s%02d', $random->getInt(1995, 2025), $separator, $random->getInt(1, 12), $separator, $random->getInt(1, 28)),
            6 => \sprintf('E%02d', $random->getInt(1, 900)),
            default => (string) $random->getInt(1990, 2025),
        };
        $parts = [$this->title($random, $separator), $marker];
        for ($t = $random->getInt(1, 4); $t > 0; $t--) {
            $parts[] = $this->pick($random, self::TV_TAGS);
        }
        $name = implode($separator, $parts).'-'.$this->pick($random, ['GRP', 'NTb', 'FLUX', 'CAKES', 'SubsPlease', 'TrollHD']);
        $name = match ($random->getInt(0, 9)) {
            0 => '[SubsPlease] '.$this->title($random, ' ').' - '.$random->getInt(1, 1100).' (1080p) ['.strtoupper(bin2hex($random->getBytes(4))).']',
            1 => $this->pick($random, ['NFL', 'NBA', 'UFC', 'WWE', 'Formula1', 'Soccer']).$separator.$name,
            default => $name,
        };
        $group = $this->pick($random, ['alt.binaries.teevee', 'alt.binaries.hdtv.x264', 'alt.binaries.anime', 'alt.binaries.multimedia.anime.highspeed', '']);
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $name = $this->mangle($random, $name),
                1 => $name = $this->noise($random, 60, true),
                2 => $name = $this->noise($random, 50),
                3 => $name = str_replace($marker, $this->pick($random, ['S1000E1', 'S01E12345', 's01e', 'SE01', '2023.13.40', 'E123456', 'Ep.', '00x000', 'S1.E1']), $name),
                4 => $group = $this->mangle($random, $group).$this->pick($random, ['', "\tExtra"]),
                5 => $name = str_repeat($this->title($random, '.').'.', $random->getInt(10, 60)).$name,
                6 => $name = '',
                7 => $group = $this->noise($random, 30, true),
                default => $name = $this->mangle($random, $name),
            };
        }

        return $uncommon && $random->getInt(0, 20) === 0 ? $name : $name."\t".$group;
    }

    private function xxxRow(Randomizer $random, bool $uncommon): string
    {
        $separator = $this->pick($random, ['.', '.', ' ', '_']);
        $parts = [$this->pick($random, self::STUDIOS)];
        if ($random->getInt(0, 1) === 0) {
            $parts[] = \sprintf('%02d%s%02d%s%02d', $random->getInt(15, 25), $separator, $random->getInt(1, 12), $separator, $random->getInt(1, 28));
        }
        $parts[] = $this->title($random, $separator);
        for ($t = $random->getInt(1, 4); $t > 0; $t--) {
            $parts[] = $this->pick($random, self::XXX_TAGS);
        }
        $name = implode($separator, $parts).'-'.$this->pick($random, ['KTR', 'GUSH', 'XLeech', 'NBQ', 'WRB', 'P2P']);
        $poster = $this->pick($random, ['anon@y.com', 'poster@example.com', 'Uploader <up@md-hobbys.com>', '', 'oz@lot.com']);
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $name = $this->mangle($random, $name),
                1 => $name = $this->noise($random, 60, true),
                2 => $name = $this->noise($random, 50),
                3 => $name = $this->title($random, '.').'.S'.$random->getInt(1, 99).'E'.$random->getInt(1, 999).'.720p',
                4 => $poster = $this->mangle($random, $poster).$this->pick($random, ['', "\tExtra"]),
                5 => $name = str_repeat($this->pick($random, self::XXX_TAGS).'.', $random->getInt(10, 60)).$name,
                6 => $name = '',
                7 => $poster = $this->noise($random, 30, true),
                default => $name = $this->mangle($random, $name),
            };
        }

        return $uncommon && $random->getInt(0, 20) === 0 ? $name : $name."\t".$poster;
    }

    private function consoleRow(Randomizer $random, bool $uncommon): string
    {
        $separator = $this->pick($random, ['.', '.', '_', ' ']);
        $parts = [$this->title($random, $separator)];
        if ($random->getInt(0, 2) === 0) {
            $parts[] = $this->pick($random, self::CONSOLE_FLAGS);
        }
        $parts[] = $this->pick($random, self::REGIONS);
        $parts[] = $this->pick($random, self::PLATFORMS);
        if ($random->getInt(0, 3) === 0) {
            $parts[] = $this->pick($random, self::CONSOLE_FLAGS);
        }
        $name = implode($separator, $parts).($random->getInt(0, 4) === 0 ? '' : '-'.$this->pick($random, ['GRP', 'iND', 'VENOM', 'Caravan', 'ABSTRAKT', 'Real_Group']));
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $name = $this->mangle($random, $name),
                1 => $name = $this->noise($random, 60, true),
                2 => $name = $this->noise($random, 50),
                3 => $name = $this->pick($random, self::PLATFORMS).$separator.$name,
                4 => $name .= '-'.$this->noise($random, 25),
                5 => $name = str_repeat($this->title($random, '.').'.', $random->getInt(10, 40)).$name,
                6 => $name = $this->title($random, $separator),
                7 => $name = str_replace('-', $this->pick($random, ['', '--', '- ', '-.']), $name),
                default => $name = $this->mangle($random, $name),
            };
        }

        return $name;
    }

    private function gamesRow(Randomizer $random, bool $uncommon): string
    {
        $separator = $this->pick($random, ['.', '.', '_', ' ']);
        $title = $this->title($random, ' ');
        $year = $random->getInt(1995, 2025);
        $parts = [str_replace(' ', $separator, $title)];
        if ($random->getInt(0, 2) === 0) {
            $parts[] = (string) $year;
        }
        for ($t = $random->getInt(0, 3); $t > 0; $t--) {
            $parts[] = $this->pick($random, self::GAME_TAGS);
        }
        $release = implode($separator, $parts).($random->getInt(0, 5) === 0 ? '' : '-'.$this->pick($random, self::SCENE));
        $candidate = match ($random->getInt(0, 4)) {
            0 => $this->title($random, ' '),
            1 => $title.': '.$this->pick($random, ['Remastered', 'The Game', 'Deluxe Edition']),
            default => $title,
        };
        $candidateYear = (string) ($random->getInt(0, 3) === 0 ? $random->getInt(1995, 2025) : $year + $random->getInt(-1, 1));
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $release = $this->mangle($random, $release),
                1 => $release = $this->noise($random, 60, true),
                2 => $candidate = $this->noise($random, 40),
                3 => $candidateYear = $this->pick($random, ['', 'x', '1969', '2100', ' 2020 ', '20200', '-1']),
                4 => $candidate = $this->mangle($random, $candidate),
                5 => $release = str_repeat($this->title($random, '.').'.', $random->getInt(10, 40)).$release,
                6 => $release = '',
                7 => $candidateYear .= "\t".$this->noise($random, 10),
                default => $release .= '-'.$this->noise($random, 25),
            };
        }

        return implode("\t", $uncommon && $random->getInt(0, 20) === 0 ? [$release] : [$release, $candidate, $candidateYear]);
    }

    private function titleRow(Randomizer $random, bool $uncommon): string
    {
        $separator = $this->pick($random, ['.', '.', '_', ' ']);
        $title = $this->title($random, ' ');
        $parts = [str_replace(' ', $separator, $title)];
        for ($t = $random->getInt(0, 3); $t > 0; $t--) {
            $parts[] = $this->pick($random, [...self::GAME_TAGS, (string) $random->getInt(1995, 2025)]);
        }
        $release = implode($separator, $parts).($random->getInt(0, 5) === 0 ? '' : '-'.$this->pick($random, self::SCENE));
        $release = match ($random->getInt(0, 5)) {
            0 => '['.$this->pick($random, ['PC', 'GOG', 'MULTi']).'] '.$release,
            1 => $release.'.'.$this->pick($random, ['zip', 'rar', 'ISO', '7z', 'nfo', 'exe', 'part1']),
            default => $release,
        };
        $compare = match ($random->getInt(0, 4)) {
            0 => $this->title($random, ' '),
            1 => '',
            default => $title,
        };
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $release = $this->mangle($random, $release),
                1 => $release = $this->noise($random, 60, true),
                2 => $compare = $this->noise($random, 40),
                3 => $release = '['.$this->noise($random, 10).$release,
                4 => $compare = $this->mangle($random, $compare),
                5 => $release = str_repeat($this->title($random, '.').'.', $random->getInt(10, 40)).$release,
                6 => $release = '',
                7 => $release .= '.v'.$random->getInt(0, 99).'.'.$random->getInt(0, 9999).'.'.$random->getInt(0, 99),
                default => $release .= '-'.$this->noise($random, 25),
            };
        }

        return $uncommon && $random->getInt(0, 20) === 0 ? $release : $release."\t".$compare;
    }
}
