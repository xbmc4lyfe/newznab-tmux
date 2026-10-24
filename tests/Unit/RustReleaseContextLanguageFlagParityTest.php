<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Wave 10 slice 3: ReleaseContext marker predicates, release_flag(), getSimilarName()
 * and imdb_id_is_valid() against the Rust exports in rust/pipeline-ffi/src/categorize.rs.
 *
 * Admission: two final 10,000-pair runs per cell on Apple M5 (Mac17,3, 10 CPUs, 32 GiB),
 * PHP 8.5.12/PCRE 10.49 JIT, LC_CTYPE C.UTF-8, Cargo 1.99.1 release (opt-level=3, LTO,
 * codegen-units=1). Mean / p99 speedups, run 1 then run 2 (run 2 under host load):
 * getSimilarName common 1.73/2.00, 1.76/1.91; long 7.29/13.08, 7.46/13.24;
 * Unicode 2.38/2.74, 2.23/2.18. Adopted (libc isalpha() keeps str_word_count() parity).
 * Retained PHP, native helpers kept for parity:
 * hasAdultMarkers common 2.23/2.00, 2.27/2.20; long 4.97/4.57, 4.86/5.69; Unicode
 * 2.36/2.59, 2.23/2.27; mixed 1.70/1.78, 1.74/1.78; but adult-only names 1.15/1.75,
 * 1.19/1.67 (PHP's first regex hits early studio names below the FFI floor).
 * release_flag search 2.31-4.29 mean in every cell, but browse common 1.32/1.16,
 * 1.29/1.28 and Unicode 1.34/1.12, 1.32/1.16 (asset() dominates; no production callers).
 * hasStandaloneSeasonToken 0.61-0.93, hasOnlyFansMarker 0.55-0.89 and imdb_id_is_valid
 * 0.63-0.67 mean: PHP finishes in 60-110 ns, below the ~100 ns FFI wrapper floor.
 * These host/fixture measurements do not qualify other architectures; Linux glibc C.UTF-8
 * classifies no byte >= 0x80, so the high-byte probe path never triggers there.
 */
final class RustReleaseContextLanguageFlagParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        int32_t nntmux_release_context_has_standalone_season_token(const char *name, size_t len);
        int32_t nntmux_release_context_has_only_fans_marker(const char *name, size_t len);
        int32_t nntmux_release_context_has_adult_markers(const char *name, size_t len);
        int32_t nntmux_release_flag_case(const char *text, size_t len);
        int64_t nntmux_similar_name_spans(const char *name, size_t len);
        int32_t nntmux_imdb_id_is_valid(const char *id, size_t len);
        C;

    private const array PREDICATES = ['hasStandaloneSeasonToken', 'hasOnlyFansMarker', 'hasAdultMarkers'];

    /** Every alternative, case-mixed by the generator, with its delimiters and resolutions. */
    private const array TOKENS = [
        'S01', 's1', 'S123', 'S1234', 'S01E02', 'Only', 'Fans', 'Only-Fans', 'only_fans', 'OnlyFans', 'OF.', 'Of.',
        'XXX', 'Porn', 'Anal', 'Brazzers', 'BangBros', 'NaughtyAmerica', 'RealityKings', 'Tushy', 'Vixen', 'Blacked',
        'MetArt', 'JoyMii', 'Creampie', 'MP4-XXX', 'PureTaboo', 'Lady', 'Lyne', 'Lady.Lyne', 'LadyLyne', 'TeamSkeet',
        'GirlsWay', 'EvilAngel', 'Kink', 'FakeHub', 'FakeTaxi', 'SexArt', 'Nubiles', 'Defloration', 'Deeper', 'Bellesa',
        'Twistys', 'Mofos', 'MissaX', 'LegalPorno', 'AnalVids', 'JAV', 'Hentai', 'RoccoSiffredi', 'DivineBitches',
        'Device', 'Bondage', 'Hogtied', 'Wired', 'Pussy', 'Fucking', 'Machines', 'Ultimate', 'Surrender', 'Public',
        'Disgrace', 'Sex', 'And', 'Submission', 'Bound', 'Gang', 'Bangs', 'Electro', 'Sluts', 'Whipped', 'Ass', 'TS',
        'Seduction', 'Infernal', 'Restraints', 'Sexually', 'Broken', 'Fuck', 'Fucked', 'Cock', 'Dick', 'Cum', 'Cumshot',
        'Blowjob', 'Handjob', 'MILF', 'Teen', 'Lesbian', 'Threesome', 'Gangbang', 'Hardcore', 'Interracial',
        '720p', '1080p', '2160p', '4k', 'mp4', 'x264', 'Arabic', 'Cantonese', 'Chinese', 'Mandarin', 'ch', 'cn', 'Czech',
        'Danish', 'Finnish', 'Flemish', 'Dutch', 'nl', 'NlSub', 'French', 'Vostfr', 'Multi', 'German', 'Germanbed', 'ger',
        'Greek', 'Hebrew', 'Yiddish', 'Hindi', 'Hungarian', 'hun', 'Italian', 'ita', 'Japanese', 'jp', 'Korean', 'kr',
        'Norwegian', 'Polish', 'Portuguese', 'Romanian', 'Spanish', 'Swedish', 'Swesub', 'Tagalog', 'Filipino', 'Thai',
        'Turkish', 'Russian', 'Vietnamese', 'Show', 'Title', "O'Brien", "'", '0', '7', '00', 'tt0137523', '番組', 'ÉTÉ',
        "\xff", "\xe9", "\xb5", "\x80", "\0", '.', '_', ' ', '-', "\n", "\t", '[', ']',
    ];

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('release_context_language_flag_parity', self::CDEF);
        self::assertNotNull($ffi, 'The release library must contain all six Wave 10 slice 3 exports.');

        return $ffi;
    }

    /** @return list<string> */
    private static function corpus(int $seed, int $count): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $subjects = ['', "\n", 'S01', "S01\n", "S01\n\n", 'S01x', 'OF.', 'OF', "'", "''a", "'a 'b", 'a\'b c', str_repeat('9', 400)];
        for ($byte = 0; $byte <= 255; $byte++) {
            $c = chr($byte);
            foreach (['XXX', 'S01', 'OnlyFans', 'French', 'ch', 'Teen.1080p', 'Show.Name'] as $token) {
                $subjects[] = $c.$token;
                $subjects[] = $token.$c;
                $subjects[] = 'A.'.$token.$c.'B';
            }
        }
        $tokenCount = count(self::TOKENS);
        for ($i = 0; $i < $count; $i++) {
            $subject = '';
            for ($j = 0, $parts = $random->getInt(0, 9); $j < $parts; $j++) {
                $token = self::TOKENS[$random->getInt(0, $tokenCount - 1)];
                $token = match ($random->getInt(0, 3)) {
                    0 => strtoupper($token),
                    1 => strtolower($token),
                    default => $token,
                };
                $subject .= $token.(['', '.', '_', ' ', '-', ''][$random->getInt(0, 5)]);
            }
            $subjects[] = $subject;
        }

        return $subjects;
    }

    public function test_exports_and_characterization_contract(): void
    {
        $ffi = self::ffi();
        self::assertTrue((new ReleaseContext('Show.S01.1080p', 1))->hasStandaloneSeasonTokenNative());
        self::assertTrue((new ReleaseContext('OF.Some.Model', 1))->hasOnlyFansMarkerNative());
        self::assertTrue((new ReleaseContext('Some.Teen.Clip.1080p', 1))->hasAdultMarkersNative());
        self::assertFalse((new ReleaseContext('Some.Teen.Drama', 1))->hasAdultMarkersNative());
        self::assertSame('FR', release_flag_native('Movie.2024.FRENCH.1080p', 'search'));
        self::assertFalse(release_flag_native('Movie.2024.1080p', 'search'));
        self::assertSame("O'Brien Show", getSimilarNameNative("O'Brien.Show-Title"));
        self::assertTrue(imdb_id_is_valid_native('tt0137523'));
        self::assertFalse(imdb_id_is_valid_native('0000000'));
        self::assertNull(imdb_id_is_valid_native(137523), 'Integers stay on the PHP path.');
        self::assertNull(imdb_id_is_valid_native(str_repeat('9', 309)), '309+ digits may overflow to INF.');
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(-1, $ffi->nntmux_release_flag_case(null, 1));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(0, $ffi->nntmux_release_flag_case(null, 0));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(0x101, $ffi->nntmux_release_context_has_adult_markers("XXX\xff", 4));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(-1, $ffi->nntmux_similar_name_spans(str_repeat('a', 0x8000), 0x8000));
        // @phpstan-ignore method.notFound (domain C ABI)
        self::assertSame(3 << 15 | 4 << 30 | 4 << 45, $ffi->nntmux_similar_name_spans('Abc.Defg', 8));
    }

    public function test_release_context_predicates_match_php(): void
    {
        self::ffi();
        foreach (self::corpus(10301, 12000) as $subject) {
            $context = new ReleaseContext($subject, 1);
            foreach (self::PREDICATES as $method) {
                $expected = $context->{$method.'Php'}();
                // Startup PCRE tables are ASCII, so the native path never declines here.
                self::assertSame($expected, $context->{$method.'Native'}(), $method.' '.bin2hex($subject));
                self::assertSame($expected, $context->{$method}(), $method.' '.bin2hex($subject));
            }
        }
    }

    public function test_release_flag_matches_php_for_every_page(): void
    {
        self::ffi();
        foreach (self::corpus(10302, 12000) as $subject) {
            foreach (['search', 'browse', 'other'] as $page) {
                $expected = release_flag_php($subject, $page);
                self::assertSame($expected, release_flag_native($subject, $page), $page.' '.bin2hex($subject));
                self::assertSame($expected, release_flag($subject, $page), $page.' '.bin2hex($subject));
            }
        }
    }

    public function test_similar_name_and_imdb_match_php(): void
    {
        self::ffi();
        $subjects = self::corpus(10303, 12000);
        foreach (['tt0137523', ' 0137523 ', '00000000', '0', '', '-5', '1e5', "0\n0", str_repeat('0', 400).'1', str_repeat('9', 308), '１２３'] as $id) {
            $subjects[] = $id;
        }
        foreach ($subjects as $subject) {
            self::assertSame(getSimilarNamePhp($subject), getSimilarNameNative($subject), bin2hex($subject));
            self::assertSame(getSimilarNamePhp($subject), getSimilarName($subject), bin2hex($subject));
            $expected = imdb_id_is_valid_php($subject);
            $native = imdb_id_is_valid_native($subject);
            if ($native !== null) {
                self::assertSame($expected, $native, bin2hex($subject));
            }
            self::assertSame($expected, imdb_id_is_valid($subject), bin2hex($subject));
        }
        foreach ([null, 0, 1, -7, 137523] as $id) {
            self::assertNull(imdb_id_is_valid_native($id));
            self::assertSame(imdb_id_is_valid_php($id), imdb_id_is_valid($id));
        }
    }

    /**
     * An explicit setlocale() rebuilds PHP's PCRE tables (macOS C.UTF-8 classifies Latin-1
     * letters as \w), so high-byte subjects must decline unless the live tables are ASCII.
     */
    #[RunInSeparateProcess]
    public function test_explicit_locale_tables_decline_or_match(): void
    {
        self::ffi();
        foreach (['C.UTF-8', 'C', 'POSIX', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $locale) {
            if (setlocale(LC_CTYPE, $locale) === false) {
                continue;
            }
            foreach (self::corpus(10304, 1500) as $subject) {
                $context = new ReleaseContext($subject, 1);
                foreach (self::PREDICATES as $method) {
                    $native = $context->{$method.'Native'}();
                    if ($native !== null) {
                        self::assertSame($context->{$method.'Php'}(), $native, $locale.' '.$method.' '.bin2hex($subject));
                    }
                }
                $flag = release_flag_native($subject, 'search');
                if ($flag !== null) {
                    self::assertSame(release_flag_php($subject, 'search'), $flag, $locale.' '.bin2hex($subject));
                }
                self::assertSame(getSimilarNamePhp($subject), getSimilarNameNative($subject), $locale.' '.bin2hex($subject));
                $imdb = imdb_id_is_valid_native($subject);
                if ($imdb !== null) {
                    self::assertSame(imdb_id_is_valid_php($subject), $imdb, $locale.' '.bin2hex($subject));
                }
            }
            if (! \in_array($locale, ['C', 'POSIX', 'C.UTF-8'], true)) {
                self::assertNull((new ReleaseContext('Show.XXX', 1))->hasAdultMarkersNative(), $locale);
                self::assertNull(release_flag_native('French', 'search'), $locale);
            }
            if (preg_match('/\w/', "\xe9") === 1) {
                self::assertNull((new ReleaseContext("\xe9XXX", 1))->hasAdultMarkersNative(), $locale);
            }
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        self::ffi();
        $property = new ReflectionProperty(ReleaseContext::class, 'nativeFfi');
        $old = $property->getValue();
        try {
            $property->setValue(null, false);
            $context = new ReleaseContext('Show.S01.XXX.1080p', 1);
            foreach (self::PREDICATES as $method) {
                self::assertNull($context->{$method.'Native'}());
                self::assertSame($context->{$method.'Php'}(), $context->{$method}());
            }
        } finally {
            $property->setValue(null, $old);
        }
    }

    /**
     * Admission protocol: 10,000 pairs/cell, identical inputs, seeded random order, one
     * complete PHP caller per observation, 400 warmup calls/path. p99 is the nearest rank of
     * individual calls. Raw order, input indices, timings and file hashes are retained.
     * Run: php artisan test --compact tests/Unit/RustReleaseContextLanguageFlagParityTest.php --group=benchmark
     */
    #[Group('benchmark')]
    public function test_paired_randomized_caller_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            self::markTestSkipped('Opt-in admission experiment; run this file with --group=benchmark.');
        }
        self::ffi();
        $random = new Randomizer(new Mt19937(10302026));
        $callers = [
            'hasStandaloneSeasonToken' => [static fn (ReleaseContext $c): bool => $c->hasStandaloneSeasonTokenPhp(), static fn (ReleaseContext $c): bool => $c->hasStandaloneSeasonTokenNative() ?? $c->hasStandaloneSeasonTokenPhp()],
            'hasOnlyFansMarker' => [static fn (ReleaseContext $c): bool => $c->hasOnlyFansMarkerPhp(), static fn (ReleaseContext $c): bool => $c->hasOnlyFansMarkerNative() ?? $c->hasOnlyFansMarkerPhp()],
            'hasAdultMarkers' => [static fn (ReleaseContext $c): bool => $c->hasAdultMarkersPhp(), static fn (ReleaseContext $c): bool => $c->hasAdultMarkersNative() ?? $c->hasAdultMarkersPhp()],
            'flag_search' => [static fn (string $t): bool|string => release_flag_php($t, 'search'), static fn (string $t): bool|string => release_flag_native($t, 'search') ?? release_flag_php($t, 'search')],
            'flag_browse' => [static fn (string $t): bool|string => release_flag_php($t, 'browse'), static fn (string $t): bool|string => release_flag_native($t, 'browse') ?? release_flag_php($t, 'browse')],
            'similar' => [getSimilarNamePhp(...), getSimilarName(...)],
            'imdb' => [imdb_id_is_valid_php(...), static fn (string $id): bool => imdb_id_is_valid_native($id) ?? imdb_id_is_valid_php($id)],
        ];
        $fixtures = [];
        for ($i = 0; $i < 32; $i++) {
            $show = 'Some.Show.'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26);
            $common = [$show.'.S01E02.1080p.WEB-DL.DDP5.1.H.264-GROUP', 'Movie.Title.'.(1990 + $i).'.1080p.BluRay.x264-GRP', $show.'.S02.720p.HDTV.x264-GRP', 'Artist-Album-'.(2000 + $i).'-FLAC'][$i % 4];
            $long = str_repeat('Long.Title-', 24).$common;
            $unicode = '番組.ÉTÉ.'.$common;
            $adult = ['Brazzers.24.01.15.Some.Girl.1080p.MP4-KTR', 'OF.Some.Model.'.$i, 'Some.Teen.Clip.1080p', 'Studio.Lady.Lyne.Scene'][$i % 4];
            foreach (self::PREDICATES as $method) {
                $fixtures[$method.'/common'][] = new ReleaseContext($common, 1);
                $fixtures[$method.'/uncommon_long'][] = new ReleaseContext($long, 1);
                $fixtures[$method.'/uncommon_unicode'][] = new ReleaseContext($unicode, 1);
                $fixtures[$method.'/uncommon_marked'][] = new ReleaseContext($i % 2 === 0 ? $adult : $show.'.S03.Complete', 1);
                $fixtures[$method.'/uncommon_adult'][] = new ReleaseContext($adult, 1);
            }
            $lang = $show.'.'.['FRENCH', 'German', 'Italian', 'NLSub', 'MULTi', 'Swedish', 'Spanish', 'Russian'][$i % 8].'.1080p.WEB-DL-GRP';
            foreach (['flag_search', 'flag_browse'] as $method) {
                $fixtures[$method.'/common'][] = $i % 2 === 0 ? $lang : $common;
                $fixtures[$method.'/uncommon_no_match_long'][] = $long;
                $fixtures[$method.'/uncommon_unicode'][] = $i % 2 === 0 ? '番組.ÉTÉ.'.$lang : $unicode;
                $fixtures[$method.'/uncommon_late_case'][] = $show.'.Vietnamese.Subs.1080p';
            }
            $fixtures['similar/common'][] = $common;
            $fixtures['similar/uncommon_long'][] = str_repeat('1080p.', 40).$common;
            $fixtures['similar/uncommon_unicode'][] = 'ÉTÉ.Café.'.$common;
            $fixtures['imdb/common'][] = ['0137523', '1375666', '14688458', 'tt0111161'][$i % 4];
            $fixtures['imdb/uncommon'][] = ['0000000', ' tt 0 0 7 ', '', str_repeat('0', 64).'1'][$i % 4];
        }
        $summary = $samples = $startup = [];
        $gates = array_fill_keys(array_keys($callers), true);
        foreach ($fixtures as $cell => $inputs) {
            [$method] = explode('/', $cell);
            [$php, $rust] = $callers[$method];
            foreach ([$php, $rust] as $path => $call) {
                $start = hrtime(true);
                $call($inputs[0]);
                $startup[$cell][$path] = hrtime(true) - $start;
                for ($warmup = 0; $warmup < 400; $warmup++) {
                    $call($inputs[$warmup % 32]);
                }
            }
            foreach ($inputs as $input) {
                self::assertSame($php($input), $rust($input), $cell.' correctness gate');
            }
            $raw = ['php' => [], 'rust' => [], 'order' => [], 'input' => []];
            for ($pair = 0; $pair < 10000; $pair++) {
                $index = $random->getInt(0, 31);
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                $raw['input'][] = $index;
                $raw['order'][] = $order[0];
                foreach ($order as $path) {
                    $call = $path === 'php' ? $php : $rust;
                    $start = hrtime(true);
                    $call($inputs[$index]);
                    $raw[$path][] = hrtime(true) - $start;
                }
            }
            $stats = [];
            foreach (['php', 'rust'] as $path) {
                $sorted = $raw[$path];
                sort($sorted, SORT_NUMERIC);
                $stats[$path] = [
                    'mean_ns' => array_sum($sorted) / 10000,
                    'p99_ns' => $sorted[9899],
                    // Binomial rank approximation: np +/- 1.96 sqrt(np(1-p)).
                    'p99_rank_95_percent_interval_ns' => [$sorted[9880], $sorted[9919]],
                ];
            }
            $mean = $stats['php']['mean_ns'] / $stats['rust']['mean_ns'];
            $p99 = $stats['php']['p99_ns'] / $stats['rust']['p99_ns'];
            $summary[$cell] = $stats + ['mean_speedup' => $mean, 'p99_speedup' => $p99];
            $gates[$method] = $gates[$method] && $mean >= 1.5 && $p99 >= 1.5;
            $samples[$cell] = $raw;
        }
        $hashes = [];
        foreach (['app/Services/Categorization/ReleaseContext.php', 'app/Extensions/helper/helpers.php', 'tests/Unit/RustReleaseContextLanguageFlagParityTest.php', 'rust/pipeline-ffi/src/categorize.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')] as $file) {
            $hashes[$file] = hash_file('sha256', __DIR__.'/../../'.$file);
        }
        $fixtureText = array_map(static fn (array $inputs): array => array_map(static fn (ReleaseContext|string $input): string => $input instanceof ReleaseContext ? $input->releaseName : $input, $inputs), $fixtures);
        $artifact = sys_get_temp_dir().'/nntmux-wave10-slice3-'.getmypid().'.json';
        file_put_contents($artifact, json_encode([
            'protocol' => ['baseline_commit' => '1daf63781', 'seed' => 10302026, 'pairs_per_cell' => 10000, 'warmup_calls_per_path' => 400, 'calls_per_observation' => 1, 'clock' => 'hrtime', 'failures_or_timeouts' => [], 'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml', 'profile' => 'opt-level=3 lto=true codegen-units=1', 'reproduce' => 'php artisan test --compact tests/Unit/RustReleaseContextLanguageFlagParityTest.php --group=benchmark'],
            'runtime' => ['php' => PHP_VERSION, 'pcre' => PCRE_VERSION, 'pcre_jit' => ini_get('pcre.jit'), 'ctype' => setlocale(LC_CTYPE, '0'), 'os' => php_uname(), 'resource_limits' => ['php_memory' => ini_get('memory_limit'), 'cpu' => 'host scheduler; no explicit CPU quota'], 'files_sha256' => $hashes],
            'first_observation_ns_not_startup_admission' => $startup, 'fixtures' => $fixtureText, 'summary' => $summary, 'admitted_methods' => $gates, 'raw_samples' => $samples,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        fwrite(STDERR, "\nWave 10 slice 3 raw benchmark: ".$artifact."\n");
        foreach ($summary as $cell => $stats) {
            fwrite(STDERR, sprintf("%s: php %.0f/%.0f ns, rust %.0f/%.0f ns, mean %.2fx, p99 %.2fx\n", $cell, $stats['php']['mean_ns'], $stats['php']['p99_ns'], $stats['rust']['mean_ns'], $stats['rust']['p99_ns'], $stats['mean_speedup'], $stats['p99_speedup']));
        }
        fwrite(STDERR, json_encode($gates, JSON_THROW_ON_ERROR)."\n");
        self::assertCount(count($fixtures), $summary);
        self::assertTrue($gates['similar'], 'getSimilarName must qualify in every measured cell; raw data: '.$artifact);
    }
}
