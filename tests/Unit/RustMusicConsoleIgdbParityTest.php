<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConsoleService;
use App\Services\IGDB\Models\Game;
use App\Services\IGDBService;
use App\Services\MusicService;
use App\Support\RustPipelineLibrary;
use FFI;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Parity, C ABI and paired benchmark suite for the Rust title helpers in `rust/pipeline-ffi/src/predb.rs`:
 * - `MusicService::parseArtist` (`nntmux_music_parse_artist`)
 * - `ConsoleService::parseTitle` / `replacePlatform` (`nntmux_console_parse_title`, `nntmux_console_replace_platform`)
 * - `IGDBService::normalizeForMatch` / `extractBaseTitle` / `computeSimilarity` / `findBestMatch`
 *   (`nntmux_igdb_normalize_for_match`, `nntmux_igdb_extract_base_title`, `nntmux_igdb_compute_similarity`,
 *   `nntmux_igdb_score_candidates_batch_nul`)
 *
 * Paired randomized benchmark (`test_benchmark_paired_callers`; Apple M5, macOS 27.2 arm64, PHP 8.5.12 CLI with
 * pcre.jit=1 and opcache off, rustc 1.99.1 `cargo build --release` with LTO, base 2069af11e, host load average
 * 4-7). Three repetitions of 200 paired runs x 16 samples per side (3,200 samples per side and repetition); each
 * sample is the per-call mean of an 8-call block. Ranges are min-max across repetitions of PHP/Rust latency ratios:
 * - `MusicService::parseArtist`: common 1.92-1.94x mean / 1.84-1.91x p99, uncommon 2.03-2.06x / 2.12-2.52x (adopted).
 * - `ConsoleService::parseTitle`: common 2.15-2.20x / 2.03-2.78x, uncommon 2.89-2.99x / 2.98-4.07x (adopted).
 * - `ConsoleService::replacePlatform`: common 0.49-0.51x / 0.52x, uncommon 0.58-0.62x / 0.57-0.65x; the PHP `match`
 *   costs less than one FFI call (retained native PHP; `replacePlatformNative` parity-tested).
 * - `IGDBService::normalizeForMatch`: common 2.03-2.09x / 2.05-3.43x, uncommon 2.49-2.54x / 2.63-3.84x (adopted).
 * - `IGDBService::extractBaseTitle`: common 1.61-1.63x / 1.54-1.63x, uncommon 1.95-2.01x / 1.93-2.02x (adopted; the
 *   common-path margin is thin because the FFI call is about half of the Rust-side cost).
 * - `IGDBService::computeSimilarity`: common 3.04-3.05x / 3.18-4.03x, uncommon 2.09-2.19x / 1.90-2.22x (adopted).
 * - `scoreCandidatesNative` batch vs the PHP normalize+similarity loop: 10 names 3.48-3.50x / 2.93-3.25x, 60 names
 *   2.55-2.58x / 2.37-2.68x. `findBestMatch` keeps its native PHP loop: its matched path writes a debug log line
 *   (~21 us of a ~38 us call), which caps any scoring speedup below 1.5x (with the batch wired in, earlier per-call
 *   runs measured 1.36-1.40x mean / 1.27-1.46x p99). With the adopted primitives, `findBestMatch` measures
 *   1.28x / 1.15-1.39x for 10 games and 1.87-1.88x / 1.69-2.04x for 10 games x 6 names.
 * Correctness: every Rust path declines (falls back to PHP) on inputs where PCRE newline handling, `mb_strtolower`
 * ASCII mappings (U+0130, U+212A), invalid UTF-8, NUL bytes, or PCRE limits (> 1 KiB release names) could diverge.
 */
final class RustMusicConsoleIgdbParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        const char *nntmux_music_parse_artist(const char *input, size_t input_len);
        const char *nntmux_console_parse_title(const char *input, size_t input_len);
        int32_t nntmux_console_replace_platform(const char *input, size_t input_len);
        const char *nntmux_igdb_normalize_for_match(const char *input, size_t input_len);
        const char *nntmux_igdb_extract_base_title(const char *input, size_t input_len);
        double nntmux_igdb_compute_similarity(const char *a, size_t a_len, const char *b, size_t b_len);
        ssize_t nntmux_igdb_score_candidates_batch_nul(const char *query, size_t query_len, const char *candidates, size_t candidates_len, size_t count, double *out_scores);
        C;

    private const array MUSIC_TOKENS = [
        'VA', 'Va', 'Artist', 'The', 'Album', 'Name', 'Bootleg', 'Boxset', 'Clean', 'Version', 'Compiled by', '2CD',
        'Digipak', 'DIRFIX', 'DVBS', 'FLAC', 'Ltd', 'Deluxe', 'Limited', 'Special', 'Edition', 'Promo', 'PROOF',
        'Reissue', 'Remastered', 'REPACK', 'RETAIL', 'UK', 'SACD', 'Sampler', 'SAT', 'Summer', 'Mag', 'Import',
        'VINYL', 'WEB', 'ab12cd34', 'xy99', 'abc12', '3FM', 'B00ABC', 'BRC482012', 'H056', 'UXM1DW086', '4WCD',
        'ATL', 'bigFM', 'MSOne', 'QEDCD', 'ZYX', 'A', 'b', '12345678', '(12345678)', 'CABLE', 'FREEWEB', 'LINE',
        'MCD', 'YMRSMILES', '(ab12)', '(ost)', 'DAB', 'DE', 'DVBC', 'EP', 'FIX', 'IT', 'Jap', 'NL', 'PL', 'Pure',
        'FM', 'SSL', 'VLS', 'CD', 'CDA', 'CDEP', 'CDM', 'CDR', 'CDS', 'en', 'abc', '12', '1 2', '12 34', '1999',
        '2005', '2019', '1899', '(2010)', '20101', '12345', '67890', 'GRP', '|', '_', '-', ' ', '  ', "\t", 'Ä',
    ];

    private const array CONSOLE_TOKENS = [
        'Halo', 'Game', 'Name', 'Rock', 'Band', 'Network', 'DLC', 'dlc', 'Pack', 'Super', 'Mario', 'Multi',
        'Multi5', 'abgx360EFNet', 'EFNet FULL', 'FULL abgxEFNet', 'abgx FULL', 'abgxbox360EFNet',
        'illuminatenboard org', 'Place2home.net', 'Place2use.net', 'united-forums co uk', 'united-forum co uk',
        '(123)', 'v1.0', 'v.2.1', 'PAL', 'NTSC', 'NTSC-J', 'EUR', 'USA', 'JP', 'JPN', 'ASIA', 'AUS', 'MULTI.5',
        'MULTI12', 'PATCHED', 'FULLDVD', 'DVD5', 'DVDRIP', 'PROPER', 'REPACK', 'RETAIL', 'DEMO', 'DISTRIBUTION',
        'REGIONFREE', 'RF', ' RF ', '-RF-', '_RF_', 'READ.NFO', 'NFOFIX', 'PSX', 'PSX2PSP', 'PS2', 'PS 3', 'PS4',
        'PSP', 'PSVITA', 'WIIU', 'WII', 'WiiWARE', 'XBOX', 'X-BOX', 'XBOX360', 'XBOXONE', 'XBLA', 'X360', '3DS',
        'NDS', 'N64', 'NGC', 'GC', 'SNES', 'NES', '(JAPAN)', 'Disc 1', 'Disk 2', '%20', '[', ']', '0', 'GRP', '.',
        '-', '_', ' ', "\t", 'é',
    ];

    private const array IGDB_TOKENS = [
        'The', 'Witcher', 'Game of the Year', 'GOTY', 'Definitive Edition', 'Deluxe Edition', 'Complete Edition',
        'Remastered', 'HD Remaster', 'Directors Cut', 'Director Cut', 'Anniversary Edition', 'Update', 'Patch',
        'Hotfix', 'Incl DLC', 'Including DLCs', 'DLC', 'Repack', 'Rip', 'ISO', 'Crackfix', 'Beta', 'Alpha', 'PC',
        'GOG', 'Steam', 'x64', 'Win32', 'Multi12', 'MULTy3', 'Mult', 'ENG', 'English', 'FR', 'German', 'PTBR', 'RU',
        'JP', 'CHS', 'Korean', 'Episode', 'Chapter', 'Part', 'Vol', 'Vol.', 'Volume', 'Volumes', '1', '2', '12',
        'II', ':', ' - ', '-', ': ', '_', '.', ' ', '  ', "\t", "\x0B", "\x0C", 'Pokémon', 'É', 'İ', 'K', "\xff",
        '™', '&', "'",
    ];

    private FFI $ffi;

    private MusicService $music;

    private ConsoleService $console;

    private IGDBService $igdb;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('music_console_igdb_parity_test', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->ffi = $ffi;
        $this->music = (new ReflectionClass(MusicService::class))->newInstanceWithoutConstructor();
        $this->console = (new ReflectionClass(ConsoleService::class))->newInstanceWithoutConstructor();
        $this->igdb = (new ReflectionClass(IGDBService::class))->newInstanceWithoutConstructor();
        $this->setRust(true);
    }

    protected function tearDown(): void
    {
        $this->setRust(true);
        parent::tearDown();
    }

    public function test_c_abi_string_decline_and_false_contracts(): void
    {
        $release = 'Pink_Floyd-The_Dark_Side_Of_The_Moon-Remastered-1973-GRP';
        // @phpstan-ignore method.notFound
        self::assertSame('1973Pink Floyd The Dark Side Of The Moon', $this->ffi->nntmux_music_parse_artist($release, strlen($release)));
        // @phpstan-ignore method.notFound
        self::assertSame('', $this->ffi->nntmux_music_parse_artist('No year', 7));
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_music_parse_artist("Line\nBreak 1999", 15));
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_music_parse_artist("Artist\0Name 1999", 16));
        $long = str_repeat('Artist ', 200).'1999';
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_music_parse_artist($long, strlen($long)));

        $console = 'Halo.3.PAL.XBOX360-GRP';
        // @phpstan-ignore method.notFound
        self::assertSame("0Halo 3\x1FXBOX360\x1F".$console, $this->ffi->nntmux_console_parse_title($console, strlen($console)));
        // @phpstan-ignore method.notFound
        self::assertSame('', $this->ffi->nntmux_console_parse_title('Nothing', 7));
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_console_parse_title("Halo\0.PAL.X360", 14));
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_console_parse_title("Halo\x1F.PAL.X360", 14));

        // @phpstan-ignore method.notFound
        self::assertSame(1, $this->ffi->nntmux_console_replace_platform('xboxone', 7));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $this->ffi->nntmux_console_replace_platform('Atari', 5));

        // @phpstan-ignore method.notFound
        self::assertSame('halo', $this->ffi->nntmux_igdb_normalize_for_match('HALO GOTY', 9));
        // @phpstan-ignore method.notFound
        self::assertSame('', $this->ffi->nntmux_igdb_normalize_for_match('GOTY', 4));
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_igdb_normalize_for_match("bad \xff", 5));
        // @phpstan-ignore method.notFound
        self::assertSame('Batman', $this->ffi->nntmux_igdb_extract_base_title('  Batman: Arkham', 16));
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_igdb_extract_base_title("A\nB", 3));
        // @phpstan-ignore method.notFound
        self::assertNull($this->ffi->nntmux_igdb_extract_base_title("A\0B", 3));

        $scores = $this->ffi->new('double[2]');
        // @phpstan-ignore method.notFound
        self::assertSame(2, $this->ffi->nntmux_igdb_score_candidates_batch_nul('Halo', 4, "Halo\0Halo 2", 11, 2, $scores));
        self::assertSame(100.0, $scores[0]);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_igdb_score_candidates_batch_nul('Halo', 4, "Halo\0Halo 2", 11, 1, $scores));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_igdb_score_candidates_batch_nul('Halo', 4, "\xC4\xB0", 2, 1, $scores));
    }

    public function test_music_parse_artist_parity(): void
    {
        $php = new ReflectionMethod($this->music, 'parseArtistPhp');
        $cases = [
            'Pink_Floyd-The_Dark_Side_Of_The_Moon-Remastered-1973-GRP',
            'VA-Summer_Hits_2015-2CD-2015-GRP',
            'Artist_Name-Album_Title-(Deluxe_Edition)-WEB-2019-GRP',
            'Some_Artist-Some_Album-Ltd_Limited_Edition_Box-2CD-2012-GRP',
            'Band-Live_At_Wembley_12_34_1999-FM-1999-GRP',
            'Artist-Album_Clean_Radio_Version-RETAIL_DE_UK-2008-GRP',
            'DJ_Someone-Mix_(B00ABCDEF)-SAT-04-15-2010-GRP',
            'Artist-Album-(12345678)-CDM-2001-GRP',
            'Group-Track_Title-Compiled_by_Someone-2003-GRP',
            'NOVA_Band-Album-VLS-2004-abc',
            'Artist-Title-ab12cd34-2011',
            'Artist - Title (OST) 1999',
            'Short-2010-GRP',
            'No year here at all',
            'Artist_Name-Album_Name-12345 67890-2015',
            'Artist Name Album Name A 2016',
            'Ärtist_Ñame-Albüm-2018-GRP',
            '',
            "Line\nBreak Artist Name 1999",
        ];
        foreach ($this->fuzz(self::MUSIC_TOKENS, 1500, 8, true) as $case) {
            $cases[] = $case;
        }
        $compared = 0;
        foreach ($cases as $case) {
            $expected = $php->invoke($this->music, $case);
            $native = MusicService::parseArtistNative($case);
            if ($native !== null) {
                self::assertSame($expected, $native, json_encode($case, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
                $compared++;
            }
            $this->setRust(true);
            self::assertSame($expected, $this->music->parseArtist($case));
            $this->setRust(false);
            self::assertSame($expected, $this->music->parseArtist($case));
            $this->setRust(true);
        }
        self::assertGreaterThan(1400, $compared);
        self::assertNull(MusicService::parseArtistNative("Line\nBreak Artist Name 1999"));
    }

    public function test_console_parse_title_and_replace_platform_parity(): void
    {
        $php = new ReflectionMethod($this->console, 'parseTitlePhp');
        $cases = [
            'Halo.3.PAL.XBOX360-GRP',
            'Rock.Band.DLC.Pack.XBLA.X360-GRP',
            'Rock.Band.Network.DLC.Song.Pack.XBLA-GRP',
            'Some.Game.DLC-Pack.Two.PS3-GRP',
            'Game.Name.Multi5.NTSC-J.PS2-GRP',
            'abgx360EFNet FULL Game.Title.PAL.XBOX360-GRP',
            'www.Place2home.net - Game_Title_[USA]_PSP-GRP',
            'united-forums co uk Game Title EUR WII-GRP',
            '(1234) Game.Title.Disc.2.USA.NGC-GRP',
            'Game.Title.v1.0.READ.NFO.3DS-GRP',
            'Game%20Title.RF.WiiWARE-GRP',
            'Game.Title.Disc 1.JAP.PS 3-GRP',
            'Super.Mario.64.N64-GRP',
            'Game.Title.PAL.GC-GRP',
            'Game.Title.PSX2PSP-GRP',
            '0.PAL.XBOX360',
            'Nothing.To.See',
            "Halo\n3.PAL.XBOX360",
            'Pokémon.Édition.EUR.NDS-GRP',
        ];
        foreach ($this->fuzz(self::CONSOLE_TOKENS, 1500, 10) as $case) {
            $cases[] = $case;
        }
        $compared = 0;
        foreach ($cases as $case) {
            $expected = $php->invoke($this->console, $case);
            $native = ConsoleService::parseTitleNative($case);
            if ($native !== null) {
                self::assertSame($expected, $native, json_encode($case, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
                $compared++;
            }
            self::assertSame($expected, $this->console->parseTitle($case));
        }
        self::assertGreaterThan(1500, $compared);
        self::assertNull(ConsoleService::parseTitleNative("Halo\n3.PAL.XBOX360"));
        self::assertSame($php->invoke($this->console, "Halo\x1F3.PAL.XBOX360"), $this->console->parseTitle("Halo\x1F3.PAL.XBOX360"));

        $platforms = ['X360', 'xbox360', 'XBOXONE', 'Xbox One', 'DSi', 'nds', '3ds', 'ps2', 'PS3', 'ps4', 'psp',
            'PSVita', 'psx', 'PSX2PSP', 'WiiU', 'wii', 'ngc', 'N64', 'nes', 'Super Nintendo', 'nintendo super nes',
            'snes', 'GC', 'PS 3', 'Atari', '', 'XBOX  ONE', 'éPS3', str_repeat('x', 40)];
        foreach ($platforms as $platform) {
            self::assertSame($this->console->replacePlatform($platform), ConsoleService::replacePlatformNative($platform), $platform);
        }
    }

    public function test_igdb_normalize_extract_and_similarity_parity(): void
    {
        $normalizePhp = new ReflectionMethod($this->igdb, 'normalizeForMatchPhp');
        $normalize = new ReflectionMethod($this->igdb, 'normalizeForMatch');
        $extractPhp = new ReflectionMethod($this->igdb, 'extractBaseTitlePhp');
        $extract = new ReflectionMethod($this->igdb, 'extractBaseTitle');
        $similarityPhp = new ReflectionMethod($this->igdb, 'computeSimilarityPhp');

        $cases = [
            'The Witcher 3: Wild Hunt - Game of the Year Edition',
            'DOOM.Eternal.Deluxe.Edition-MULTi12_x64',
            'DOOM Eternal Deluxe Edition MULTi12 x64',
            'Tales from the Borderlands Episode 2 Atlas Mugged',
            'Naruto Ultimate Ninja Vol. 3 Extra',
            'Batman: Arkham City GOTY',
            'Half-Life 2',
            'Pokémon Sword',
            'İstanbul Kelvin',
            "Bad \xff UTF-8",
            "Line\nBreak - Title 2",
            '',
            '   ',
        ];
        foreach ($this->fuzz(self::IGDB_TOKENS, 1500, 10) as $case) {
            $cases[] = $case;
        }
        foreach ($cases as $i => $case) {
            $label = json_encode($case, JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
            $expectedNorm = $normalizePhp->invoke($this->igdb, $case);
            $native = IGDBService::normalizeForMatchNative($case);
            if ($native !== null) {
                self::assertSame($expectedNorm, $native, $label);
            }
            self::assertSame($expectedNorm, $normalize->invoke($this->igdb, $case), $label);

            $expectedBase = $extractPhp->invoke($this->igdb, $case);
            $nativeBase = IGDBService::extractBaseTitleNative($case);
            if ($nativeBase !== null) {
                self::assertSame($expectedBase, $nativeBase, $label);
            }
            self::assertSame($expectedBase, $extract->invoke($this->igdb, $case), $label);

            $other = $cases[($i * 7 + 3) % count($cases)];
            $otherNorm = $normalizePhp->invoke($this->igdb, $other);
            self::assertSame($similarityPhp->invoke($this->igdb, $expectedNorm, $otherNorm), IGDBService::computeSimilarityNative($expectedNorm, $otherNorm), $label);
            self::assertSame($similarityPhp->invoke($this->igdb, $case, $other), IGDBService::computeSimilarityNative($case, $other), $label);
        }
        self::assertNull(IGDBService::normalizeForMatchNative('İstanbul'));
        self::assertNull(IGDBService::extractBaseTitleNative("Line\nBreak"));
        self::assertSame($extractPhp->invoke($this->igdb, "Halo\0 2"), $extract->invoke($this->igdb, "Halo\0 2"));
        $long = str_repeat('abcdefghij', 9);
        self::assertSame($similarityPhp->invoke($this->igdb, $long, strrev($long)), IGDBService::computeSimilarityNative($long, strrev($long)));
    }

    public function test_igdb_find_best_match_batch_parity(): void
    {
        $findBestMatch = new ReflectionMethod($this->igdb, 'findBestMatch');
        mt_srand(20261024);
        for ($round = 0; $round < 150; $round++) {
            $query = $this->fuzz(self::IGDB_TOKENS, 1, 6)[0];
            $games = [];
            for ($g = 0, $n = mt_rand(0, 10); $g < $n; $g++) {
                $attributes = ['id' => $g, 'name' => mt_rand(0, 2) === 0 ? $query.' '.self::IGDB_TOKENS[mt_rand(0, 20)] : $this->fuzz(self::IGDB_TOKENS, 1, 6)[0]];
                if (mt_rand(0, 1) === 1) {
                    $attributes['aggregated_rating_count'] = mt_rand(0, 2000);
                }
                if (mt_rand(0, 1) === 1) {
                    $attributes['cover'] = ['image_id' => 'x'];
                }
                if (mt_rand(0, 1) === 1) {
                    $attributes['alternative_names'] = array_map(fn (): array => ['name' => mt_rand(0, 3) === 0 ? $query : $this->fuzz(self::IGDB_TOKENS, 1, 5)[0]], range(0, mt_rand(0, 4)));
                }
                $games[] = new Game($attributes);
            }
            foreach ([null, 'X360'] as $hint) {
                $this->setRust(false);
                $expected = $findBestMatch->invoke($this->igdb, collect($games), $query, $hint);
                $this->setRust(true);
                $actual = $findBestMatch->invoke($this->igdb, collect($games), $query, $hint);
                self::assertSame($expected?->id, $actual?->id, json_encode($query, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            }
        }

        $names = ['Halo', 'Halo 2', 'Halo: Combat Evolved', 'Pokémon Sword'];
        $normalizePhp = new ReflectionMethod($this->igdb, 'normalizeForMatchPhp');
        $similarityPhp = new ReflectionMethod($this->igdb, 'computeSimilarityPhp');
        $query = $normalizePhp->invoke($this->igdb, 'Halo GOTY');
        $expected = array_map(fn (string $name): float => $similarityPhp->invoke($this->igdb, $query, $normalizePhp->invoke($this->igdb, $name)), $names);
        self::assertSame($expected, IGDBService::scoreCandidatesNative('Halo GOTY', $names));
        self::assertNull(IGDBService::scoreCandidatesNative('Halo', ["Embedded\0NUL"]));
        self::assertNull(IGDBService::scoreCandidatesNative('Halo', ['İstanbul']));
    }

    /**
     * Reproduce: NNTMUX_MCI_BENCH=1 php artisan test --compact tests/Unit/RustMusicConsoleIgdbParityTest.php --filter=benchmark
     * (`NNTMUX_MCI_BENCH_RUNS` paired runs, default 60, x 16 samples per side in random side order after 400 warmup
     * calls; each sample is the per-call mean of an 8-call block, which keeps macOS's ~42 ns `hrtime` tick from
     * quantizing sub-microsecond p99s; nearest-rank p99 over samples). Protected IGDB methods run through the
     * `IgdbMatchingProbe` subclass, so both sides pay the same in-class call cost.
     */
    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_MCI_BENCH') !== '1') {
            self::markTestSkipped('Opt-in paired microbenchmark.');
        }
        $runs = max(20, (int) (getenv('NNTMUX_MCI_BENCH_RUNS') ?: 60));

        $probe = (new ReflectionClass(IgdbMatchingProbe::class))->newInstanceWithoutConstructor();
        $normalize = $probe->normalize(...);
        $extract = $probe->baseTitle(...);
        $similarity = static fn (array $pair): float => $probe->similarity($pair[0], $pair[1]);
        $findBestMatch = static fn (array $args): ?Game => $probe->bestMatch(...$args);
        $scorePhp = static fn (array $args): array => $probe->scorePhp($args[0], $args[1]);
        $console = $this->console;
        $rust = fn (): bool => $this->rustEnabled;

        $witcher = ['The Witcher 3: Wild Hunt', 'The Witcher 3: Wild Hunt - Blood and Wine', 'The Witcher 2: Assassins of Kings',
            'The Witcher', 'The Witcher Adventure Game', 'Thronebreaker: The Witcher Tales', 'The Witcher: Enhanced Edition',
            'The Witcher 3: Wild Hunt - Hearts of Stone', 'Gwent: The Witcher Card Game', 'The Witcher: Monster Slayer'];
        $ff7 = [];
        foreach (range(1, 10) as $i) {
            $ff7[] = 'Final Fantasy VII Remake Intergrade Deluxe Edition Part '.$i;
            foreach (range(1, 5) as $j) {
                $ff7[] = "FF7R Intergrade Alternate Regional Title {$i}-{$j} Ultimate Edition";
            }
        }
        $commonGames = collect(array_map(static fn (string $name): Game => new Game(['id' => 1, 'name' => $name, 'cover' => ['image_id' => 'x']]), $witcher));
        $uncommonGames = collect(array_map(static fn (int $i): Game => new Game([
            'id' => $i,
            'name' => 'Final Fantasy VII Remake Intergrade Deluxe Edition Part '.$i,
            'aggregated_rating_count' => 500,
            'alternative_names' => array_map(static fn (int $j): array => ['name' => "FF7R Intergrade Alternate Regional Title {$i}-{$j} Ultimate Edition"], range(1, 5)),
        ]), range(1, 10)));

        $parseArtist = fn (string $name): array|false => $this->music->parseArtist($name);
        $parseTitle = fn (string $name): array|false => $this->console->parseTitle($name);
        $replacePlatform = static fn (string $p): ?string => $rust() ? ConsoleService::replacePlatformNative($p) : $console->replacePlatform($p);
        $score = static fn (array $args): ?array => $rust() ? IGDBService::scoreCandidatesNative($args[0], $args[1]) : $scorePhp($args);

        $workloads = [
            'parseArtist/common' => [$parseArtist, ['Pink_Floyd-The_Dark_Side_Of_The_Moon-Remastered-1973-GRP', 'Artist_Name-Album_Title-(Deluxe_Edition)-WEB-2019-GRP', 'VA-Summer_Hits_2015-2CD-2015-GRP']],
            'parseArtist/uncommon' => [$parseArtist, ['Various_Artists-Ministry_Of_Sound_The_Annual_Ltd_Special_Collectors_Edition_Box-3CD-Remastered-RETAIL_UK_Import-ab12cd34-12_34_2016-GRP', str_repeat('Long_Artist_Name_', 8).'No_Year_At_All-GRP']],
            'parseTitle/common' => [$parseTitle, ['Halo.3.PAL.XBOX360-GRP', 'Super.Mario.Galaxy.2.USA.WII-GRP', 'Gran.Turismo.5.EUR.PS3-GRP']],
            'parseTitle/uncommon' => [$parseTitle, ['www.Place2home.net - Some_Really_Long_Game_Title_Of_The_Year_Collectors_Edition_Rock_Band_DLC_Pack_[USA]_Multi5_XBLA-GRP', str_repeat('Long.Game.Title.', 10).'No.Tag.Here']],
            'replacePlatform/common' => [$replacePlatform, ['X360', 'PS3', 'WII']],
            'replacePlatform/uncommon' => [$replacePlatform, ['Nintendo Super NES', 'Atari Jaguar']],
            'normalizeForMatch/common' => [$normalize, ['The Witcher 3: Wild Hunt', 'DOOM.Eternal-GOG', 'Halo Infinite']],
            'normalizeForMatch/uncommon' => [$normalize, ['Cyberpunk.2077.Ultimate.Edition.v2.1.Incl.DLCs.MULTi18.x64-GOG Repack Crackfix English French German Spanish', 'Pokémon Légendes Arceus Édition Définitive']],
            'extractBaseTitle/common' => [$extract, ['Batman: Arkham City', 'Halo 2', 'Tales from the Borderlands Episode 2']],
            'extractBaseTitle/uncommon' => [$extract, [str_repeat('Very Long Title Without Separators ', 6).'Volume 12 Extra', str_repeat('Words ', 30)]],
            'computeSimilarity/common' => [$similarity, [['the witcher 3 wild hunt', 'the witcher 3 wild hunt blood and wine'], ['halo', 'halo 2']]],
            'computeSimilarity/uncommon' => [$similarity, [[str_repeat('final fantasy vii remake ', 3), str_repeat('final fantasy 7 rebirth ', 3)]]],
            'scoreCandidates/common10' => [$score, [['The.Witcher.3.Wild.Hunt.GOTY-GOG', $witcher]]],
            'scoreCandidates/uncommon60' => [$score, [['Final.Fantasy.VII.Remake.Intergrade.MULTi12-RePack', $ff7]]],
            'findBestMatch(adopted primitives)/common10' => [$findBestMatch, [[$commonGames, 'The.Witcher.3.Wild.Hunt.GOTY-GOG', null]]],
            'findBestMatch(adopted primitives)/uncommon10x6' => [$findBestMatch, [[$uncommonGames, 'Final.Fantasy.VII.Remake.Intergrade.MULTi12-RePack', 'PS4']]],
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

    private bool $rustEnabled = true;

    private function setRust(bool $enabled): void
    {
        $this->rustEnabled = $enabled;
        MusicService::setUseRustFfi($enabled);
        ConsoleService::setUseRustFfi($enabled);
        IGDBService::setUseRustFfi($enabled);
    }

    /**
     * Seeded token-soup release names with occasional byte mutations.
     *
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    private function fuzz(array $tokens, int $count, int $maxTokens, bool $withYear = false): array
    {
        static $seeded = false;
        if (! $seeded) {
            mt_srand(20261024);
            $seeded = true;
        }
        $seps = ['.', ' ', '-', '_', '', ' - '];
        $alphabet = "aZ09 .-_|()[]:%\t";
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $s = '';
            for ($t = 0, $n = mt_rand(1, $maxTokens); $t < $n; $t++) {
                $s .= $tokens[mt_rand(0, count($tokens) - 1)].$seps[mt_rand(0, count($seps) - 1)];
            }
            if ($withYear && mt_rand(0, 1) === 1) {
                $s .= ['-', ' ', '_', '(', '-('][mt_rand(0, 4)].(mt_rand(0, 1) ? '19' : '20').mt_rand(0, 2).mt_rand(0, 9).(mt_rand(0, 1) ? ')' : '').$tokens[mt_rand(0, count($tokens) - 1)];
            }
            if (mt_rand(0, 3) === 0) {
                for ($j = 0, $m = mt_rand(1, 3); $j < $m; $j++) {
                    $s = substr_replace($s, $alphabet[mt_rand(0, strlen($alphabet) - 1)], mt_rand(0, strlen($s)), mt_rand(0, 1));
                }
            }
            $out[] = $s;
        }

        return $out;
    }
}

/**
 * Exposes `IGDBService`'s protected matching helpers to the paired benchmark.
 */
final class IgdbMatchingProbe extends IGDBService
{
    public function normalize(string $title): string
    {
        return $this->normalizeForMatch($title);
    }

    public function baseTitle(string $title): string
    {
        return $this->extractBaseTitle($title);
    }

    public function similarity(string $a, string $b): float
    {
        return $this->computeSimilarity($a, $b);
    }

    public function bestMatch(mixed $results, string $title, ?string $platformHint = null): ?Game
    {
        return $this->findBestMatch($results, $title, $platformHint);
    }

    /**
     * Native PHP equivalent of `scoreCandidatesNative()`.
     *
     * @param  array<int, string>  $names
     * @return array<int, float>
     */
    public function scorePhp(string $title, array $names): array
    {
        $query = $this->normalizeForMatchPhp($title);

        return array_map(fn (string $name): float => $this->computeSimilarityPhp($query, $this->normalizeForMatchPhp($name)), $names);
    }
}
