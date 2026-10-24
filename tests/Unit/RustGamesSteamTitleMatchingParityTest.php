<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GamesTitleParser;
use App\Services\SteamService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Wave 9.2 parity and reproducible caller benchmark (base 6c2c99036ead3418158f6a4d79ea1846524f3f79).
 * Apple M5, macOS 27.2 arm64, PHP 8.5.12 CLI, pcre.jit=1, opcache.enable_cli=0,
 * rustc 1.99.1, cargo release opt-level=3/LTO/codegen-units=1; no imposed CPU limit,
 * shared-host load average about 4.2. Warm steady-state calls; no production capacity claim.
 * Three fresh-process repetitions of 3,200 individual latency samples/side/workload;
 * ranges below show observed run-to-run uncertainty, PHP/Rust mean and p99 ratios:
 *
 * Method                  Common mean / p99         Uncommon mean / p99
 * cleanVersionTokens      0.98-1.03 / 1.08-1.20      1.99-2.06 / 1.65-2.89
 * fallbackParse           1.08-1.14 / 0.88-1.22      1.09-1.14 / 1.06-1.21
 * generateQueryVariants   1.35-1.45 / 1.35-1.63      1.44-1.50 / 1.13-1.48
 * tokenize                0.83-0.85 / 0.88-1.00      1.71-1.78 / 2.04-2.29
 * buildLikePattern        1.09-1.15 / 1.08-1.15      1.18-1.21 / 1.25-1.29
 * computeSimilarity       1.95-1.97 / 2.11-2.11      32.70-33.25 / 29.15-30.72
 *
 * Only computeSimilarity qualifies for production; the other five keep PHP and expose
 * Native candidates for parity. Similarity measures its actual production dispatch;
 * retained candidates measure Native plus PHP fallback against the previous PHP body.
 * Steam's previous PHP bodies already used Rust normalization/edition primitives.
 * Raw timings, input/order, lockfile/vendor/library/source hashes: /tmp/wave9-92-final-{1,2,3}.json.
 * All timed fixture pairs passed correctness; no missing/failed observations. Reproduce
 * with the command on test_benchmark_paired_callers, three times in separate processes.
 * Declines: titles over 1 KiB; Steam variant/LIKE non-ASCII, NUL, CR/LF/VT; tokenizer
 * non-Latin-1/invalid UTF-8 or NUL. Games helpers otherwise preserve arbitrary bytes.
 */
final class RustGamesSteamTitleMatchingParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_games_clean_version_tokens(const char *, size_t, char *, size_t);
        ssize_t nntmux_games_fallback_parse(const char *, size_t, char *, size_t);
        double nntmux_games_compute_similarity(const char *, size_t, const char *, size_t);
        ssize_t nntmux_steam_generate_query_variants_nul(const char *, size_t, char *, size_t);
        ssize_t nntmux_steam_tokenize_nul(const char *, size_t, char *, size_t);
        ssize_t nntmux_steam_build_like_pattern(const char *, size_t, char *, size_t);
        C;

    private GamesTitleParser $games;

    private SteamService $steam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->games = new GamesTitleParser;
        $this->steam = (new ReflectionClass(SteamService::class))->newInstanceWithoutConstructor();
    }

    public function test_abi_capacity_and_decline_protocol(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('games_steam_test', self::CDEF);
        self::assertNotNull($ffi, 'Build and install the release library before running parity tests.');
        $buffer = $ffi->new('char[64]');
        foreach (['nntmux_games_clean_version_tokens', 'nntmux_games_fallback_parse', 'nntmux_steam_generate_query_variants_nul', 'nntmux_steam_tokenize_nul', 'nntmux_steam_build_like_pattern'] as $symbol) {
            self::assertSame(PHP_INT_MIN, $this->abiStringCall($ffi, $symbol, null, 1, $buffer, 64));
            $required = $this->abiStringCall($ffi, $symbol, 'Halo', 4, null, 0);
            self::assertLessThan(0, $required);
            FFI::memset($buffer, 0x7F, 64);
            self::assertSame(-$required, $this->abiStringCall($ffi, $symbol, 'Halo', 4, $buffer, -$required));
            self::assertSame("\x7f", $buffer[-$required], 'No implicit terminator or write past capacity.');
        }
        // @phpstan-ignore method.notFound
        self::assertSame(-1.0, $ffi->nntmux_games_compute_similarity(null, 1, '', 0));
        // @phpstan-ignore method.notFound
        self::assertSame(100.0, $ffi->nntmux_games_compute_similarity(null, 0, null, 0));
        self::assertNull(SteamService::tokenizeNative("A\0B"));
        self::assertNull(SteamService::generateQueryVariantsNative('東京'));
        self::assertNull(SteamService::buildLikePatternNative("Bad\xff"));
        self::assertNull(GamesTitleParser::fallbackParseNative(str_repeat('a', 1025)));
        self::assertNull(GamesTitleParser::computeSimilarityNative(str_repeat('a', 1025), 'b'));
    }

    public function test_games_and_steam_parity_through_callers(): void
    {
        $covered = array_fill_keys(['cleanVersionTokens', 'fallbackParse', 'generateQueryVariants', 'tokenize', 'buildLikePattern'], 0);
        foreach ($this->titles() as $title) {
            foreach ($covered as $method => $count) {
                $service = in_array($method, ['cleanVersionTokens', 'fallbackParse'], true) ? $this->games : $this->steam;
                $expected = $service->{$method.'Php'}($title);
                $candidate = $service->{$method.'Native'}($title);
                if ($candidate !== null) {
                    self::assertSame($expected, $candidate, $method.' '.bin2hex($title));
                    $covered[$method]++;
                }
                self::assertSame($expected, (new ReflectionMethod($service, $method))->invoke($service, $title));
            }
            $clean = (new ReflectionMethod($this->games, 'cleanReleaseName'))->invoke($this->games, $title);
            $expected = $clean === '' ? $this->games->fallbackParsePhp($title) : ['title' => $clean, 'release' => $title];
            self::assertSame($expected, $this->games->parse($title), 'parse '.bin2hex($title));
        }
        foreach ($covered as $method => $count) {
            self::assertGreaterThan(500, $count, $method.' must exercise Rust, not just fallback.');
        }
    }

    public function test_byte_similarity_parity(): void
    {
        $titles = $this->titles();
        foreach ([63, 64, 65, 255, 256, 1024] as $length) {
            $titles[] = str_repeat('ab', intdiv($length, 2)).($length % 2 === 1 ? 'x' : '');
        }
        foreach ($titles as $i => $a) {
            foreach ([$a, $titles[($i * 7 + 13) % count($titles)], substr($a, 1)] as $b) {
                $expected = $this->games->computeSimilarityPhp($a, $b);
                $actual = GamesTitleParser::computeSimilarityNative($a, $b);
                self::assertNotNull($actual);
                self::assertEqualsWithDelta($expected, $actual, 1e-12, bin2hex($a).' / '.bin2hex($b));
                self::assertEqualsWithDelta($expected, $this->games->computeSimilarity($a, $b), 1e-12);
            }
        }
    }

    public function test_callers_fall_back_when_the_domain_library_is_unavailable(): void
    {
        foreach ([$this->games, $this->steam] as $service) {
            $class = new ReflectionClass($service);
            $ffi = $class->getProperty('titleMatchingFfi');
            $resolved = $class->getProperty('titleMatchingResolved');
            $saved = [$ffi->getValue(), $resolved->getValue()];
            try {
                $ffi->setValue(null, null);
                $resolved->setValue(null, true);
                $methods = $service instanceof GamesTitleParser ? ['cleanVersionTokens', 'fallbackParse'] : ['generateQueryVariants', 'tokenize', 'buildLikePattern'];
                foreach ($methods as $method) {
                    foreach (['The Witcher III: Wild Hunt GOTY', "Bad\xff\0Title", str_repeat('Long Game ', 150)] as $title) {
                        self::assertNull($service->{$method.'Native'}($title));
                        self::assertSame($service->{$method.'Php'}($title), (new ReflectionMethod($service, $method))->invoke($service, $title));
                    }
                }
                if ($service instanceof GamesTitleParser) {
                    self::assertNull(GamesTitleParser::computeSimilarityNative('Game', 'Different Game'));
                    self::assertSame($service->computeSimilarityPhp('Game', 'Different Game'), $service->computeSimilarity('Game', 'Different Game'));
                    self::assertSame(100.0, $service->computeSimilarity('', ''));
                }
            } finally {
                $ffi->setValue(null, $saved[0]);
                $resolved->setValue(null, $saved[1]);
            }
        }
    }

    /**
     * Opt-in experiment: NNTMUX_GAMES_BENCH=1 NNTMUX_GAMES_BENCH_OUT=/tmp/wave9-92.json
     * php artisan test --compact tests/Unit/RustGamesSteamTitleMatchingParityTest.php --filter=benchmark
     *
     * Three fresh-process repetitions, 200 warmup pairs, 200 randomized paired runs x 16
     * individually timed calls per side/workload (3,200 samples, no block-averaged p99).
     * Both sides use identical inputs/order. Correctness checks are outside timed regions.
     * The candidate includes the production-shaped dispatch, FFI, allocation, decoding and fallback.
     */
    public function test_benchmark_paired_callers(): void
    {
        if (getenv('NNTMUX_GAMES_BENCH') !== '1') {
            self::markTestSkipped('Opt-in paired benchmark.');
        }
        $g = $this->games;
        $s = $this->steam;
        $common = ['The Witcher III: Wild Hunt GOTY', 'Elden Ring Deluxe Edition', 'Halo 3', 'Forza Horizon 5', 'Game v1 2 3 Title'];
        $uncommon = ['The Legend of Heroes: Trails of Cold Steel IV (Digital Deluxe Edition) Complete Edition', str_repeat('Game Chapter IV : Worlds ', 6).'v2 12 345 42 43 44', "Long\tGame\tGame\tTitle\t(Expansion Pack)\tUltimate Edition"];
        $workloads = [];
        foreach (['cleanVersionTokens', 'fallbackParse', 'generateQueryVariants', 'tokenize', 'buildLikePattern'] as $method) {
            $service = in_array($method, ['cleanVersionTokens', 'fallbackParse'], true) ? $g : $s;
            foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                // One outer frame on each side, as in the original service and proposed dispatch.
                $php = static fn (string $v): mixed => $service->{$method.'Php'}($v);
                $rust = static fn (string $v): mixed => $service->{$method.'Native'}($v) ?? $service->{$method.'Php'}($v);
                $workloads[$method.'/'.$kind] = [$php, $rust, $inputs];
            }
        }
        foreach ([
            'common' => [['halo 3', 'halo 3'], ['the witcher 3 wild hunt', 'witcher 3 wild hunt'], ['elden ring', 'elden ring deluxe edition'], ['forza horizon 5', 'forza motorsport']],
            'uncommon' => [[str_repeat('Game Chapter IV Worlds ', 5), str_repeat('Game Worlds Chapter V ', 5)], [str_repeat('ab', 120), str_repeat('ba', 120)], ['The Legend of Heroes Trails of Cold Steel IV', 'The Legend of Heroes Trails of Cold Steel V']],
        ] as $kind => $inputs) {
            $workloads['computeSimilarity/'.$kind] = [
                static fn (array $v): float => $g->computeSimilarityPhp(...$v),
                static fn (array $v): float => $g->computeSimilarity(...$v),
                $inputs,
            ];
        }
        $p99 = static function (array $v): float {
            sort($v);

            return (float) $v[(int) ceil(count($v) * 0.99) - 1];
        };
        $artifact = ['php' => PHP_VERSION, 'os' => php_uname(), 'jit' => ini_get('pcre.jit'), 'opcache' => ini_get('opcache.enable_cli'), 'seed' => 9022026, 'warmup_pairs' => 200, 'runs' => 200, 'samples_per_run' => 16, 'source' => [], 'workloads' => []];
        foreach (['rust/pipeline-ffi/src/categorize.rs', 'app/Services/GamesTitleParser.php', 'app/Services/SteamService.php', 'tests/Unit/RustGamesSteamTitleMatchingParityTest.php', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.json', 'storage/app/nntmux-native/libnntmux_pipeline.dylib'] as $file) {
            if (is_file(base_path($file))) {
                $artifact['source'][$file] = hash_file('sha256', base_path($file));
            }
        }
        mt_srand(9022026);
        foreach ($workloads as $name => [$php, $rust, $inputs]) {
            foreach ($inputs as $v) {
                self::assertEquals($php($v), $rust($v), $name);
            }
            for ($i = 0; $i < 200; $i++) {
                $php($inputs[$i % count($inputs)]);
                $rust($inputs[$i % count($inputs)]);
            }
            $samples = [[], []];
            $orders = [];
            for ($run = 0; $run < 200; $run++) {
                $order = mt_rand(0, 1) === 0 ? [0, 1] : [1, 0];
                $indices = array_map(static fn (): int => mt_rand(0, count($inputs) - 1), range(1, 16));
                $orders[] = [$order, $indices];
                foreach ($order as $side) {
                    $call = $side === 0 ? $php : $rust;
                    foreach ($indices as $index) {
                        $t0 = hrtime(true);
                        $call($inputs[$index]);
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
            }
            $mean = [array_sum($samples[0]) / count($samples[0]), array_sum($samples[1]) / count($samples[1])];
            $tails = [$p99($samples[0]), $p99($samples[1])];
            $artifact['workloads'][$name] = ['inputs' => $inputs, 'orders' => $orders, 'samples_ns' => $samples, 'means_ns' => $mean, 'p99_ns' => $tails, 'mean_speedup' => $mean[0] / $mean[1], 'p99_speedup' => $tails[0] / $tails[1]];
            fwrite(STDOUT, sprintf("BENCH %s n=%d mean=%.2fx p99=%.2fx php=%.0f/%.0fns rust=%.0f/%.0fns\n", $name, count($samples[0]), $mean[0] / $mean[1], $tails[0] / $tails[1], $mean[0], $tails[0], $mean[1], $tails[1]));
        }
        $destination = getenv('NNTMUX_GAMES_BENCH_OUT');
        if (is_string($destination) && $destination !== '') {
            self::assertNotFalse(file_put_contents($destination, json_encode($artifact, JSON_THROW_ON_ERROR)));
        }
    }

    private function abiStringCall(FFI $ffi, string $symbol, ?string $input, int $length, ?CData $out, int $capacity): int
    {
        return $ffi->$symbol($input, $length, $out, $capacity);
    }

    /** @return list<string> */
    private function titles(): array
    {
        $titles = ['', '0', '!!!', 'Halo 3', 'Game v1 2 3 Title 4 5', 'Game 1 2 3 End', 'Halo-English', 'Notenglish', ' RF Halo RF End ', ' PC ISO) (Game', "\0Halo\0", "Halo\tMulti2\nWorld", "One\tTwo", "A\x0cB", "X\x1cY", "A\r\nB", 'Multi123', '(Game)', 'The.Witcher.III:Wild.Hunt.GOTY-FLT', 'Game (Deluxe Edition) : Chapter IV', 'Game ((foo) bar)', 'GoldEdITION', 'Pokémon', 'Ærø', 'İstanbul', 'ΟΣ', '東京', "\xff", "a\xc3", "É\u{00a0}É", "a\u{0085}b", "a\u{2003}b", "\0\0", "a\0b"];
        $tokens = ['The', 'Halo', 'Game', 'RF', 'Multi2', 'v1', 'V2', '1', '2', '3', '42', 'III', 'ii', 'v20', 'Deluxe Edition', 'GOTY', 'COLLECTORS', 'HD Remaster', 'english', 'German', 'PC', 'Patch', 'CODEX', 'Special', 'Anniversary', 'Edition', 'Game of the Year'];
        $separators = [' ', '  ', '.', '_', '-', ':', '(', ')', '[', ']', "\t", "\r", "\n", "\x0b", "\x0c", "\0"];
        mt_srand(9022026);
        for ($i = 0; $i < 1800; $i++) {
            $s = '';
            for ($j = 0, $n = mt_rand(1, 10); $j < $n; $j++) {
                $s .= $tokens[mt_rand(0, count($tokens) - 1)].$separators[mt_rand(0, count($separators) - 1)];
            }
            $titles[] = $s;
        }
        foreach (range(0, 255) as $byte) {
            $titles[] = 'Game'.chr($byte).'v1 2 3 English';
        }

        return $titles;
    }
}
