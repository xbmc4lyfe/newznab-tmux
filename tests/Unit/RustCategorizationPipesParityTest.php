<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\ConsolePipe;
use App\Services\Categorization\Pipes\MoviePipe;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\Pipes\TvPipe;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;

/**
 * Wave 27.3 parity and paired benchmark suite for the `BookPipe`, `ConsolePipe`, `MoviePipe`,
 * `MusicPipe` and `TvPipe` specification helpers (`categorization_pipes_spec` in
 * `rust/pipeline-ffi/src/categorize.rs`).
 *
 * Reproduce the paired randomized benchmark:
 *   CARGO_TARGET_DIR=/tmp/cargo-w27-3 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_CATEGORIZATION_PIPES_SPEC_BENCH=1 NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w27-3/release/libnntmux_pipeline.dylib \
 *     php artisan test --compact tests/Unit/RustCategorizationPipesParityTest.php --filter=test_paired_randomized_benchmark
 *
 * Paired randomized benchmark evidence (base commit a6c7ccd9d plus this slice, release profile,
 * rustc 1.99.1, PHP 8.5.12 NTS, Apple M5 Darwin; 2 runs x 3 rounds x 101 paired samples x
 * 200 iterations, each iteration calling `pipeName()` + `evaluateResult()` on all five pipes;
 * warmup 8 x 50 iterations per mode; seeded random order (mt_srand(273)); p99 is the
 * 100th-of-101 sample; speedup = PHP latency / Rust latency):
 *
 * - Common (in-domain matches): median 0.22x-0.26x, p99 0.21x-0.47x
 *   (PHP median 0.169-0.215 ms, Rust median 0.702-0.886 ms).
 * - Uncommon (no-match, boundary, non-finite, long UTF-8 and hash results): median 0.23x-0.25x,
 *   p99 0.19x-0.42x (PHP median 0.184-0.212 ms, Rust median 0.728-0.879 ms).
 * - Overall: per-call FFI marshaling dominates these constant lookups and scalar predicates, so
 *   Rust misses the 1.5x threshold on every metric. PHP stays the production default
 *   (`$useRustFfi = false`) for all five pipes.
 */
final class RustCategorizationPipesParityTest extends TestCase
{
    /**
     * @var list<class-string<BookPipe|ConsolePipe|MoviePipe|MusicPipe|TvPipe>>
     */
    private const CLASSES = [
        BookPipe::class,
        ConsolePipe::class,
        MoviePipe::class,
        MusicPipe::class,
        TvPipe::class,
    ];

    /**
     * `class => [name, priority, category root]` as declared before Wave 27.3.
     */
    private const EXPECTED = [
        BookPipe::class => ['Book', 45, Category::BOOKS_ROOT],
        ConsolePipe::class => ['Console', 35, Category::GAME_ROOT],
        MoviePipe::class => ['Movie', 25, Category::MOVIE_ROOT],
        MusicPipe::class => ['Music', 40, Category::MUSIC_ROOT],
        TvPipe::class => ['TV', 20, Category::TV_ROOT],
    ];

    /**
     * Real release names that exercise each categorizer, plus non-matching noise.
     */
    private const RELEASES = [
        'Some.Author-Some.Title.2021.RETAIL.EPUB-GRP',
        'Batman.Issue.001.2016.Digital.CBR-Empire',
        'National.Geographic.Magazine.March.2022.PDF',
        'Game.Title.USA.NDS-PLAZA',
        'Game.Title.PS4-DUPLEX',
        'Game.Title.XBOX360-COMPLEX',
        'Movie.Title.2019.1080p.BluRay.x264-SPARKS',
        'Movie.Title.2021.2160p.UHD.BluRay.x265-TERMiNAL',
        'Movie.Title.2004.DVDRip.XviD-DiAMOND',
        'Artist-Album-2020-FLAC-WEB-GRP',
        'Artist-Album-WEB-2019-320kbps-MP3-GRP',
        'Show.Name.S01E01.1080p.WEB-DL.DD5.1.H.264-GRP',
        'Show.Name.S02E05.720p.HDTV.x264-KILLERS',
        'Show.Name.S03E10.2160p.WEB.H265-GGEZ',
        'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
        '',
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            $class::setPipeSpecFfiForTesting(null);
        }

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to the PHP implementation");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_pipe_toggles_are_isolated_from_each_other_and_the_base_pipe(): void
    {
        BookPipe::setUseRustFfi(true);
        $this->assertTrue(BookPipe::usesRustFfi());
        foreach ([ConsolePipe::class, MoviePipe::class, MusicPipe::class, TvPipe::class] as $class) {
            $this->assertFalse($class::usesRustFfi());
        }
        $this->assertFalse((new \ReflectionProperty(AbstractCategorizationPipe::class, 'useRustFfi'))->getValue());
    }

    public function test_pipe_spec_parity_with_original_declarations(): void
    {
        $this->requireFfi();
        foreach (self::EXPECTED as $class => [$name, $priority]) {
            $this->assertSame($name, $class::pipeNamePhp());
            $this->assertSame($name, $class::pipeNameNative());
            $this->assertSame($priority, $class::pipePriorityPhp());
            $this->assertSame($priority, $class::pipePriorityNative());
            foreach ([false, true] as $useRust) {
                $class::setUseRustFfi($useRust);
                $pipe = new $class;
                $this->assertSame($name, $class::pipeName());
                $this->assertSame($priority, $class::pipePriority());
                $this->assertSame($name, $pipe->getName());
                $this->assertSame($priority, $pipe->getPriority());
            }
        }
    }

    /**
     * @return iterable<string, array{int, float, string}>
     */
    public static function resultProvider(): iterable
    {
        $categories = [
            PHP_INT_MIN, -1, 0, Category::OTHER_MISC, Category::OTHER_HASHED, 999,
            Category::GAME_ROOT, Category::GAME_NDS, Category::GAME_OTHER, Category::MOVIE_ROOT, Category::MOVIE_UHD,
            Category::MOVIE_OTHER, Category::MUSIC_ROOT, Category::MUSIC_PODCAST, Category::MUSIC_OTHER, 4000,
            4999, Category::TV_ROOT, Category::TV_ANIME, Category::TV_OTHER, 6000, Category::BOOKS_ROOT,
            Category::BOOKS_EBOOK, 7999, 8000, PHP_INT_MAX,
        ];
        $confidences = [-INF, -1.0, -0.0, 0.0, 1.0E-300, 0.5, 0.9499999, 0.95, 0.9500001, 1.0, INF, NAN];
        $matchedBy = ['no_match', 'none', '', "no_match\0", 'NO_MATCH', 'tv_hd_webdl', 'ünïcode_🙂'];
        foreach ($categories as $c) {
            foreach ($confidences as $ci => $confidence) {
                foreach ($matchedBy as $mi => $m) {
                    yield "{$c}_{$ci}_{$mi}" => [$c, $confidence, $m];
                }
            }
        }
    }

    public function test_evaluate_result_parity_with_edge_cases(): void
    {
        $this->requireFfi();
        foreach (self::resultProvider() as $label => [$categoryId, $confidence, $matchedBy]) {
            foreach (self::CLASSES as $class) {
                $this->assertEvaluationParity($class, $categoryId, $confidence, $matchedBy, "{$class} {$label}");
            }
        }
    }

    public function test_evaluate_result_matches_original_result_semantics(): void
    {
        foreach (self::resultProvider() as $label => [$categoryId, $confidence, $matchedBy]) {
            $result = new CategorizationResult($categoryId, $confidence, $matchedBy);
            foreach (self::EXPECTED as $class => [, , $root]) {
                $mask = $class::evaluateResultPhp($categoryId, $confidence, $matchedBy);
                $this->assertSame($result->isSuccessfulPhp(), ($mask & $class::RESULT_SUCCESSFUL) !== 0, $label);
                $this->assertSame(intdiv($categoryId, 1000) === intdiv($root, 1000) && $categoryId >= 0, ($mask & $class::RESULT_IN_DOMAIN) !== 0, $label);

                $passable = new CategorizationPassable(new ReleaseContext('x', 1));
                $passable->bestResult = $result;
                $this->assertSame($passable->shouldStopProcessingPhp(), ($mask & $class::RESULT_TERMINAL) !== 0, $label);
            }
        }
    }

    public function test_evaluate_result_randomized_parity(): void
    {
        $this->requireFfi();
        mt_srand(2731);
        $words = ['no_match', 'none', 'ebook', 'tv_', 'movie_', 'music_', 'console_', "\0", 'ü', ''];
        for ($i = 0; $i < 4000; $i++) {
            $categoryId = match (mt_rand(0, 3)) {
                0 => mt_rand(-20, 9000),
                1 => mt_rand(0, 7) * 1000 + mt_rand(-1, 1000),
                2 => mt_rand(0, 1) === 1 ? Category::OTHER_MISC : Category::OTHER_HASHED,
                default => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            };
            $confidence = match (mt_rand(0, 3)) {
                0 => mt_rand(0, 100) / 100,
                1 => 0.95 + (mt_rand(-5, 5) * 1.0E-9),
                2 => mt_rand(-10, 10) / 10,
                default => mt_rand() / mt_getrandmax(),
            };
            $matchedBy = '';
            for ($w = mt_rand(0, 3); $w > 0; $w--) {
                $matchedBy .= $words[mt_rand(0, \count($words) - 1)];
            }
            foreach (self::CLASSES as $class) {
                $this->assertEvaluationParity($class, $categoryId, $confidence, $matchedBy, json_encode([$class, $categoryId, $confidence, $matchedBy]) ?: '');
            }
        }
    }

    public function test_real_pipeline_handle_is_unchanged_by_the_toggle(): void
    {
        $this->requireFfi();
        foreach (self::CLASSES as $class) {
            foreach (self::RELEASES as $release) {
                $snapshots = [];
                foreach ([false, true] as $useRust) {
                    $class::setUseRustFfi($useRust);
                    $passable = new CategorizationPassable(new ReleaseContext($release, 1, 'alt.binaries.test'), true);
                    $out = (new $class)->handle($passable, static fn (CategorizationPassable $p): CategorizationPassable => $p);
                    $best = $out->bestResult;
                    $snapshots[] = [
                        $best->categoryId,
                        $best->confidence,
                        $best->matchedBy,
                        array_keys($out->allResults),
                        $class::evaluateResult($best->categoryId, $best->confidence, $best->matchedBy),
                    ];
                }
                $this->assertSame($snapshots[0], $snapshots[1], "{$class} {$release}");
            }
        }
    }

    public function test_all_five_pipes_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        foreach (self::EXPECTED as $class => [$name, $priority, $root]) {
            $class::setPipeSpecFfiForTesting(false);
            $class::setUseRustFfi(true);
            $this->assertSame($name, $class::pipeNameNative());
            $this->assertSame($name, (new $class)->getName());
            $this->assertSame($priority, $class::pipePriorityNative());
            $this->assertSame(
                $class::evaluateResultPhp($root + 10, 0.97, 'x'),
                $class::evaluateResult($root + 10, 0.97, 'x')
            );
            $this->assertSame(
                $class::RESULT_SUCCESSFUL | $class::RESULT_IN_DOMAIN | $class::RESULT_TERMINAL,
                $class::evaluateResultNative($root + 10, 0.97, 'x')
            );
        }
    }

    public function test_direct_c_abi_rejects_null_invalid_and_undersized_buffers(): void
    {
        $ffi = $this->requireFfi();
        $buf = $ffi->new('char[32]');
        $small = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $small[$i] = 'Z';
        }

        // Names: valid kinds, unknown kind, null buffer, oversized capacity, then undersized capacity.
        foreach (array_values(self::EXPECTED) as $kind => [$name]) {
            $this->assertSame(\strlen($name), self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', $kind, $buf, 32));
            $this->assertSame($name, FFI::string($buf, \strlen($name)));
        }
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', 5, $buf, 32));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', 255, $buf, 32));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', 0, null, 32));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', 0, $buf, -1));
        $this->assertSame(-7, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', 1, $small, 4));
        $this->assertSame(-5, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', 2, $small, 0));
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $this->assertSame(2, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_name', 4, $small, 2));
        $this->assertSame('TVZZ', FFI::string($small, 4));

        // Priorities.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_priority', 5));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_priority', 255));

        // Evaluation: unknown kind, null input, oversized input.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_evaluate_result', 5, 7020, 1.0, 'x', 1));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_evaluate_result', 0, 7020, 1.0, null, 0));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_evaluate_result', 0, 7020, 1.0, 'x', -1));
        $this->assertSame(7, self::callFfi($ffi, 'nntmux_categorize_pipes_spec_evaluate_result', 0, 7020, 1.0, 'x', 1));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_CATEGORIZATION_PIPES_SPEC_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_CATEGORIZATION_PIPES_SPEC_BENCH=1 to run the paired benchmark.');
        }
        $ffi = $this->requireFfi();
        foreach (self::CLASSES as $class) {
            $class::setPipeSpecFfiForTesting($ffi);
        }

        // Each iteration evaluates one release across all five pipes, as `handle()` would:
        // the pipe name for `updateBestResult()` plus the category-result evaluation. Common
        // inputs are ordinary in-domain matches; uncommon inputs are long, non-ASCII,
        // no-match, boundary and non-finite results.
        mt_srand(2732);
        $common = [];
        $uncommon = [];
        for ($i = 0; $i < 256; $i++) {
            $root = [1000, 2000, 3000, 5000, 7000][mt_rand(0, 4)];
            $common[] = [$root + 10 * mt_rand(1, 9), mt_rand(50, 100) / 100, ['tv_hd', 'movie_bluray', 'music_flac', 'ebook', 'console_ps4'][mt_rand(0, 4)]];
            $uncommon[] = match (mt_rand(0, 3)) {
                0 => [Category::OTHER_MISC, 0.0, 'no_match'],
                1 => [[999, 1999, 2999, 7999, 8000][mt_rand(0, 4)], [NAN, INF, 0.9499999][mt_rand(0, 2)], ''],
                2 => [mt_rand(PHP_INT_MIN, PHP_INT_MAX), mt_rand(0, 100) / 100, str_repeat('ünïcode_match_', mt_rand(8, 64))],
                default => [Category::OTHER_HASHED, 1.0, 'hash_'.bin2hex(random_bytes(16))],
            };
        }

        $workloads = ['common' => $common, 'uncommon' => $uncommon];
        $measure = static function (array $inputs, bool $rust, int $iterations): float {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
            }
            $count = \count($inputs);
            $t0 = hrtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                [$categoryId, $confidence, $matchedBy] = $inputs[$i % $count];
                foreach (self::CLASSES as $class) {
                    $class::pipeName();
                    $class::evaluateResult($categoryId, $confidence, $matchedBy);
                }
            }

            return (hrtime(true) - $t0) / 1_000_000;
        };

        $rounds = 3;
        $samples = 101;
        $iterations = 200;
        $summary = [];
        mt_srand(273);
        foreach ($workloads as $name => $inputs) {
            for ($w = 0; $w < 8; $w++) {
                $measure($inputs, false, 50);
                $measure($inputs, true, 50);
            }
            for ($round = 1; $round <= $rounds; $round++) {
                $phpTimes = [];
                $rustTimes = [];
                for ($s = 0; $s < $samples; $s++) {
                    if (mt_rand(0, 1) === 1) {
                        $rustTimes[] = $measure($inputs, true, $iterations);
                        $phpTimes[] = $measure($inputs, false, $iterations);
                    } else {
                        $phpTimes[] = $measure($inputs, false, $iterations);
                        $rustTimes[] = $measure($inputs, true, $iterations);
                    }
                }
                sort($phpTimes);
                sort($rustTimes);
                $p99Index = (int) ceil(0.99 * $samples) - 1;
                $phpMedian = $phpTimes[intdiv($samples, 2)];
                $rustMedian = $rustTimes[intdiv($samples, 2)];
                $summary[] = sprintf(
                    '%s round %d: PHP median=%.3f ms p99=%.3f ms, Rust median=%.3f ms p99=%.3f ms, median speedup=%.2fx, p99 speedup=%.2fx',
                    $name,
                    $round,
                    $phpMedian,
                    $phpTimes[$p99Index],
                    $rustMedian,
                    $rustTimes[$p99Index],
                    $phpMedian / max(0.0001, $rustMedian),
                    $phpTimes[$p99Index] / max(0.0001, $rustTimes[$p99Index])
                );
            }
        }

        fwrite(STDERR, "\n[Wave 27.3 Benchmark]\n".implode("\n", $summary)."\n");
        $this->assertCount($rounds * \count($workloads), $summary);
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @param  class-string<BookPipe|ConsolePipe|MoviePipe|MusicPipe|TvPipe>  $class
     */
    private function assertEvaluationParity(string $class, int $categoryId, float $confidence, string $matchedBy, string $message): void
    {
        $php = $class::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        $this->assertSame($php, $class::evaluateResultNative($categoryId, $confidence, $matchedBy), $message);
        foreach ([false, true] as $useRust) {
            $class::setUseRustFfi($useRust);
            $this->assertSame($php, $class::evaluateResult($categoryId, $confidence, $matchedBy), $message);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'categorize',
            BookPipe::PIPE_SPEC_FFI_CDEF,
            \is_string($path) && $path !== '' ? $path : null
        );
        if ($ffi === null) {
            $this->markTestSkipped('Rust categorize FFI library is not built or available.');
        }

        return $ffi;
    }
}
