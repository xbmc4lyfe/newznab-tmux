<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\ConsolePipe;
use App\Services\Categorization\Pipes\GroupNamePipe;
use App\Services\Categorization\Pipes\MoviePipe;
use App\Services\NameFixing\DonorMatchSelector;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\Predb\PredbIdentityPolicy;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustCategorizationServiceGroupNameFixerW40ParityTest extends TestCase
{
    /** Every Wave 40.3 target shares the W403 toggle names and keeps its existing toggles separate. */
    private const TARGETS = [
        CategorizationService::class,
        GroupNamePipe::class,
        BookPipe::class,
        ConsolePipe::class,
        MoviePipe::class,
    ];

    /** Production defaults: only the group routing and filename batch helpers clear 1.5x on every measure. */
    private const DEFAULTS = [
        CategorizationService::class => false,
        GroupNamePipe::class => true,
        BookPipe::class => false,
        ConsolePipe::class => true,
        MoviePipe::class => false,
    ];

    /** Helper per target: [class, method]. */
    private const HELPERS = [
        'distribution' => [CategorizationService::class, 'summarizeCategoryDistribution'],
        'routing' => [GroupNamePipe::class, 'summarizeGroupNameRouting'],
        'progress' => [BookPipe::class, 'summarizeNameFixBatchProgress'],
        'filenames' => [ConsolePipe::class, 'summarizeCleanedFilenameBatch'],
        'scores' => [MoviePipe::class, 'summarizeCandidateScoreWindow'],
    ];

    /** Helpers whose native path declines non-string (else non-integer) list items. */
    private const STRING_HELPERS = ['routing', 'progress', 'filenames'];

    /** Strings that exercise trimming, case, separators, paths, extensions, NUL bytes and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\x1F", "\t\n", 'nfo', ' NFO ', 'Files', "par2\0", 'predb', 'PREDB ', 'unfixable', 'unfix', 'x', '-', '.', '_._',
        'alt.binaries.boneless', 'A.B.BONELESS', 'a.b.', 'alt.binaries.', 'alt.binaries.teevee', 'a.b.moovee', 'alt.binaries.sounds.flac',
        'alt.binaries.erotica.tv', 'alt.binaries.hdtv.x264', 'alt.binaries.cd.image', 'comp.lang.php', 'alt.bin', 'de.alt.binaries.tv',
        'Movie.Title.2024.1080p.mkv', 'movie.title.2024.1080p.MKV', 'dir/sub\\Movie.Title.2024.1080p', 'sample.mkv', 'SAMPLE', '00001.jpg',
        '12345', 'ab.mkv', '.hidden', 'archive.part01.rar', 'name.toolong', 'name.', 'name.r00', 'name.é', '/', '\\', 'a/', ' x.y.z ',
        'Ünïcödé.Fïlé.mkv', "bad \xff utf8", "\xc3", "\x0C\x0C\x0C\x0C\x0C\x0C",
    ];

    /** Integers around every boundary the helpers use. */
    private const INTS = [PHP_INT_MIN, -281474976710657, -1000, -1, 0, 1, 4, 5, 6, 10, 20, 49, 50, 51, 499, 500, 501, 849, 850, 851, 999, 1000, 1001,
        1999, 2000, 7999, 8000, 8999, 9000, 281474976710656, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW403RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W403_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW403RustFfi(), $class);
            $class::useW403RustFfi(true);
            $this->assertTrue($class::usesW403RustFfi(), $class);
            $class::useW403RustFfi(false);
            $this->assertFalse($class::usesW403RustFfi(), $class);
            $class::useW403RustFfi(! $default);
            $class::resetW403RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w403RustFfiOverride'))->getValue(), $class);
            $class::useW403RustFfi(! $default);
            $class::useW403RustFfi(null);
            $this->assertSame($default, $class::usesW403RustFfi(), $class);
        }

        // The W403 toggles never touch the classes' existing Rust FFI toggles.
        foreach (self::TARGETS as $class) {
            $class::useW403RustFfi(true);
        }
        $this->assertFalse(CategorizationService::$useRustFfi);
        $this->assertFalse(GroupNamePipe::$useRustFfi);
        $this->assertFalse(GroupNamePipe::usesRustFfi());
    }

    public function test_existing_contracts_are_unchanged(): void
    {
        $this->assertSame('GroupName', GroupNamePipe::pipeName());
        $this->assertSame(5, GroupNamePipe::pipePriority());
        $this->assertSame([], (new FilePrioritizer)->prioritizeForMatching([]));
        $this->assertNull((new DonorMatchSelector(false))->selectCanonical([]));

        // The Wave 40.3 helpers live outside every PreDB identity definition, so those definitions
        // stay untouched and current.
        $policy = new PredbIdentityPolicy;
        foreach ([NameFixingService::class, FilePrioritizer::class, DonorMatchSelector::class, FileNameCleaner::class, PredbMatchSelector::class] as $class) {
            $this->assertTrue($policy->definitionIsCurrent($class), $class);
        }
        $this->assertNotNull($policy->fingerprint());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 18, 'valid' => 14, 'console' => 2, 'movies' => 1, 'audio' => 1, 'pc' => 1, 'tv' => 2, 'xxx' => 1, 'books' => 1, 'other' => 5, 'invalid' => 4],
            CategorizationService::summarizeCategoryDistribution([1000, 1999, 2040, 3010, 4050, 5040, 5070, 6010, 7020, 0, 10, 20, 8010, 8999, 9000, -1, PHP_INT_MIN, PHP_INT_MAX]),
        );
        $this->assertSame(['valid' => 0, 'invalid' => 3], array_intersect_key(CategorizationService::summarizeCategoryDistribution(['5000', 1.0, null]), ['valid' => 0, 'invalid' => 0]));

        $this->assertSame(
            ['total' => 10, 'binary' => 8, 'text_only' => 1, 'boneless' => 1, 'erotica' => 1, 'games' => 1, 'music' => 1, 'tv' => 2, 'movies' => 1, 'unknown' => 3],
            GroupNamePipe::summarizeGroupNameRouting([' Alt.Binaries.Boneless ', 'a.b.teevee', 'alt.binaries.movies.divx', 'alt.binaries.sounds.mp3',
                'alt.binaries.games.xbox360', 'alt.binaries.erotica', 'alt.binaries.misc', 'comp.lang.php', '', 'alt.binaries.hdtv.x264']),
        );
        $this->assertSame(1, GroupNamePipe::summarizeGroupNameRouting([['a.b.tv'], 'a.b.tv'])['unknown']);

        $this->assertSame(
            ['inspected' => 8, 'fixed_nfo' => 2, 'fixed_files' => 1, 'fixed_par2' => 1, 'fixed_predb' => 1, 'fixed_total' => 5, 'unfixable' => 1, 'unknown' => 2, 'limit_reached' => false, 'remaining_budget' => 495, 'max_fixes' => 500],
            BookPipe::summarizeNameFixBatchProgress([BookPipe::OUTCOME_NFO, ' NFO ', 'files', 'par2', 'predb', 'unfixable', 'bogus', '']),
        );
        $this->assertSame(['limit_reached' => true, 'remaining_budget' => 0, 'max_fixes' => 1], array_intersect_key(
            BookPipe::summarizeNameFixBatchProgress(['predb', null], PHP_INT_MIN), ['limit_reached' => 0, 'remaining_budget' => 0, 'max_fixes' => 0]));

        $files = ['Movie.Title.2024.1080p.mkv', 'dir/sub\\movie.title.2024.1080p.MKV', 'sample.mkv', '00001.jpg', 'ab.mkv', '  _Some.Show.S01E01_  ', '.hidden', 'archive.part01.rar', 'name.toolong'];
        $this->assertSame(['total' => 9, 'plausible' => 6, 'too_short' => 1, 'generic_junk' => 2, 'unique_candidates' => 5], ConsolePipe::summarizeCleanedFilenameBatch($files));
        $this->assertSame(['total' => 9, 'plausible' => 0, 'too_short' => 9, 'generic_junk' => 0, 'unique_candidates' => 0], ConsolePipe::summarizeCleanedFilenameBatch($files, PHP_INT_MAX));
        $this->assertSame(['total' => 9, 'plausible' => 7, 'too_short' => 0, 'generic_junk' => 2, 'unique_candidates' => 6], ConsolePipe::summarizeCleanedFilenameBatch($files, PHP_INT_MIN));
        $this->assertSame(1, ConsolePipe::summarizeCleanedFilenameBatch([7, 'x'], 0)['generic_junk']);

        $this->assertSame(
            ['candidates' => 3, 'invalid' => 0, 'above_threshold' => 2, 'best' => 910, 'runner_up' => 860, 'margin' => 50, 'unambiguous' => true, 'threshold' => 850],
            MoviePipe::summarizeCandidateScoreWindow([910, 860, 700]),
        );
        $this->assertFalse(MoviePipe::summarizeCandidateScoreWindow([910, 870])['unambiguous']);
        $this->assertFalse(MoviePipe::summarizeCandidateScoreWindow([900, 900])['unambiguous']);
        $this->assertSame(
            ['candidates' => 1, 'invalid' => 2, 'above_threshold' => 1, 'best' => 900, 'runner_up' => 0, 'margin' => 900, 'unambiguous' => true, 'threshold' => 850],
            MoviePipe::summarizeCandidateScoreWindow([900, '950', 0.95]),
        );
        $this->assertSame(['best' => 1000, 'threshold' => 0], array_intersect_key(
            MoviePipe::summarizeCandidateScoreWindow([PHP_INT_MAX, PHP_INT_MIN], PHP_INT_MIN), ['best' => 0, 'threshold' => 0]));
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $texts = self::TEXTS;
        foreach ($texts as $index => $text) {
            $window = array_slice([...$texts, ...$texts], $index, 1 + $index % 11);
            $this->assertParity('routing', [[$text]]);
            $this->assertParity('routing', [$window]);
            foreach ([PHP_INT_MIN, -1, 0, 1, 2, 5, PHP_INT_MAX] as $max) {
                $this->assertParity('progress', [$window, $max]);
            }
            foreach ([PHP_INT_MIN, -1, 0, 1, 2, 5, 16, PHP_INT_MAX] as $min) {
                $this->assertParity('filenames', [[$text], $min]);
                $this->assertParity('filenames', [$window, $min]);
            }
        }
        foreach (['routing' => [], 'progress' => [500], 'filenames' => [5]] as $helper => $extra) {
            $this->assertParity($helper, [[], ...$extra]);
            $this->assertParity($helper, [$texts, ...$extra]);
            $this->assertParity($helper, [['a' => 'nfo', 'b' => 'alt.binaries.tv', 'c' => 'Movie.mkv'], ...$extra]);
            $this->assertParity($helper, [['nfo', null, 3, ['x']], ...$extra]);
        }

        $ints = self::INTS;
        $this->assertParity('distribution', [[]]);
        $this->assertParity('distribution', [$ints]);
        $this->assertParity('distribution', [['a' => 5000, 'b' => 2000]]);
        $this->assertParity('distribution', [[5000, '5000', null]]);
        foreach ($ints as $index => $value) {
            $this->assertParity('distribution', [[$value]]);
            $window = array_slice([...$ints, ...$ints], $index, 1 + $index % 7);
            foreach ($ints as $threshold) {
                $this->assertParity('scores', [$window, $threshold]);
            }
            $this->assertParity('scores', [[$value], 850]);
        }
        foreach ([[], [0], [0, 0], [850, 800], [850, 801], [1000, 1000, 950], [900, '950', 0.95], ['k' => 900, 'j' => 700]] as $scores) {
            foreach ([PHP_INT_MIN, 0, 1, 849, 850, 851, 1000, PHP_INT_MAX] as $threshold) {
                $this->assertParity('scores', [$scores, $threshold]);
            }
        }
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4031));
        $alphabet = ['a', 'b', 'e', 's', 't', 'v', 'x', 'A', 'B', 'T', 'V', 'X', '0', '1', '2', '4', '6', '9', '.', '/', '\\', '-', '_', ' ', "\t", "\n", "\0", "\x0B", "\x0C", "\x1F", "\xff", "\xc3\xa9"];
        $words = ['alt.binaries.', 'a.b.', 'boneless', 'tv', 'teevee', 'movies', 'moovee', 'x264', 'mp3', 'sounds', 'games', 'xbox', 'erotica', 'sex',
            'nfo', 'files', 'par2', 'predb', 'unfixable', 'sample', 'proof', 'readme', '.mkv', '.rar', '.r00', '.nfo', '.toolong', 'dir/', 'Movie.Title.2024', 'S01E01'];
        $text = static function (int $max = 24) use ($random, $alphabet, $words): string {
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $random->getInt(0, 3) === 0 ? $words[$random->getInt(0, count($words) - 1)] : $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $random->getInt(0, 4) === 0 ? strtoupper($out) : $out;
        };
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $pick(self::INTS),
            1 => $random->getInt(-3, 20),
            2 => $random->getInt(-100, 10_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };

        for ($i = 0; $i < 1000; $i++) {
            $list = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $list[] = $random->getInt(0, 2) === 0 ? $pick(self::TEXTS) : $text();
            }
            $this->assertParity('routing', [$list]);
            $this->assertParity('progress', [$list, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 30)]);
            $this->assertParity('filenames', [$list, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 12)]);

            $ids = [];
            for ($n = $random->getInt(0, 60); $n > 0; $n--) {
                $ids[] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 8999);
            }
            $this->assertParity('distribution', [$ids]);

            $scores = [];
            for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                $scores[] = $random->getInt(0, 4) === 0 ? $int() : $random->getInt(600, 1000);
            }
            $this->assertParity('scores', [$scores, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(700, 950)]);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        foreach ([
            ['nntmux_w403_category_distribution', [pack('P3', 2000, 5000, -5), 24], "3\x1F2\x1F0\x1F1\x1F0\x1F0\x1F1\x1F0\x1F0\x1F0\x1F1"],
            ['nntmux_w403_group_name_routing', ['a.b.tvcomp.os', 13, pack('V2', 6, 7), 8], "2\x1F1\x1F1\x1F0\x1F0\x1F0\x1F0\x1F1\x1F0\x1F1"],
            ['nntmux_w403_name_fix_batch_progress', ['nfoPREDB', 8, pack('V2', 3, 5), 8, 1], "2\x1F1\x1F0\x1F0\x1F1\x1F2\x1F0\x1F0\x1F1\x1F0\x1F1"],
            ['nntmux_w403_cleaned_filename_batch', ['Movie.mkvsample', 15, pack('V2', 9, 6), 8, 5], "2\x1F1\x1F0\x1F1\x1F1"],
            ['nntmux_w403_candidate_score_window', [pack('P2', 800, 990), 16, 850], "2\x1F1\x1F990\x1F800\x1F190\x1F1\x1F850"],
        ] as [$symbol, $args, $expected]) {
            // Tiny capacity reports -required without writing, then a retry at that size succeeds.
            FFI::memset($small, ord('Z'), 4);
            $needed = $this->callFfi($ffi, $symbol, ...[...$args, $small, 2]);
            $this->assertSame(-strlen($expected), $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
            $buffer = $ffi->new('char['.(-$needed).']');
            $this->assertSame(-$needed, $this->callFfi($ffi, $symbol, ...[...$args, $buffer, -$needed]), $symbol);
            $this->assertSame($expected, FFI::string($buffer, -$needed), $symbol);
            // Null output buffers decline instead of crashing.
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, ...[...$args, null, 64]), $symbol);
        }

        $out = $ffi->new('char[256]');
        // Null inputs and inconsistent lengths decline; empty lists succeed.
        foreach ([
            'nntmux_w403_group_name_routing' => [],
            'nntmux_w403_name_fix_batch_progress' => [5],
            'nntmux_w403_cleaned_filename_batch' => [5],
        ] as $symbol => $extra) {
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, null, 0, '', 0, ...[...$extra, $out, 256]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, '', 0, null, 0, ...[...$extra, $out, 256]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 2), 4, ...[...$extra, $out, 256]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'xy', 2, pack('V', 1), 4, ...[...$extra, $out, 256]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 1).'z', 5, ...[...$extra, $out, 256]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 0xFFFFFFFF), 4, ...[...$extra, $out, 256]), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, '', 0, '', 0, ...[...$extra, $out, 256]), $symbol);
        }
        foreach (['nntmux_w403_category_distribution' => [], 'nntmux_w403_candidate_score_window' => [850]] as $symbol => $extra) {
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, null, 0, ...[...$extra, $out, 256]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'abc', 3, ...[...$extra, $out, 256]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, str_repeat("\0", 9), 9, ...[...$extra, $out, 256]), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, '', 0, ...[...$extra, $out, 256]), $symbol);
        }

        // An output buffer overlapping an input declines without writing to it.
        $shared = $ffi->new('char[64]');
        FFI::memcpy($shared, 'sample'.pack('V', 6), 10);
        $input = FFI::addr($shared[0]);
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w403_cleaned_filename_batch', $input, 6, FFI::addr($shared[6]), 4, 5, FFI::addr($shared[3]), 40));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w403_cleaned_filename_batch', $input, 6, FFI::addr($shared[6]), 4, 5, FFI::addr($shared[9]), 40));
        $this->assertSame('sample', FFI::string($shared, 6));
        $this->assertGreaterThan(0, $this->callFfi($ffi, 'nntmux_w403_cleaned_filename_batch', $input, 6, FFI::addr($shared[6]), 4, 5, FFI::addr($shared[10]), 40));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w403_candidate_score_window', $input, 8, 850, FFI::addr($shared[7]), 40));

        foreach (self::TARGETS as $class) {
            $class::useW403RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            $this->assertNotNull($class::{$method.'Native'}(...$this->sampleArgs($method)), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w403RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::TARGETS as $class) {
            (new ReflectionProperty($class, 'w403RustFfiHandle'))->setValue(null, false);
            $class::useW403RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            $args = $this->sampleArgs($method);
            $this->assertNull($class::{$method.'Native'}(...$args), $class);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
        }
        foreach (self::TARGETS as $class) {
            $class::resetW403RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w403RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side type checks, packing, FFI call and decoding (release dylib, 5 warmups, 301
        // samples per helper and workload; 40 iterations per common sample, 8 per uncommon sample).
        // Common = 64 category IDs, 32 newsgroup names, 100 pass outcomes, 24 release filenames and
        // 10 candidate scores; uncommon = 5000 IDs with invalid values, 1000 long hint-free group
        // names, 5000 padded mixed-case outcomes, 2000 deep-path filenames with duplicates and 500
        // extreme scores.
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5.12 NTS, Apple M5):
        // - distribution: common mean 1.21-1.24x / p99 1.03-1.22x, uncommon mean 2.06-2.18x / p99 1.88-2.24x
        // - routing:      common mean 5.03-5.17x / p99 4.04-4.16x, uncommon mean 8.74-8.84x / p99 7.57-8.37x
        // - progress:     common mean 0.98-1.00x / p99 0.91-1.01x, uncommon mean 1.47-1.53x / p99 1.49-1.61x
        // - filenames:    common mean 2.60-2.65x / p99 2.06-2.59x, uncommon mean 3.24-3.43x / p99 2.75-4.13x
        // - scores:       common mean 0.42-0.43x / p99 0.37-0.50x, uncommon mean 1.76-1.89x / p99 1.65-2.39x
        // Routing and filenames default to Rust; the integer and short-label helpers spend more on the
        // PHP-side type checks, packing and FFI round trip than their PHP loops cost on common batches.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php vendor/bin/phpunit tests/Unit/RustCategorizationServiceGroupNameFixerW40ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99,
        // per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4032));
        $warmup = 5;
        $samples = 301;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            foreach (['common' => [$this->commonWorkload($helper), 40], 'uncommon' => [$this->uncommonWorkload($helper), 8]] as $name => [$args, $iterations]) {
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($class, $method, $args, false) + $this->runWorkload($class, $method, $args, true);
                }
                $this->assertSame($class::{$method.'Php'}(...$args), $class::{$method.'Native'}(...$args), $helper.' '.$name);
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += $this->runWorkload($class, $method, $args, $useRust);
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::useW403RustFfi(null);
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustCategorizationServiceGroupNameFixerW40 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W403_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW403RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W403_RUST_FFI_DEFAULT')) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $helper, array $args): void
    {
        [$class, $method] = self::HELPERS[$helper];
        $expected = $class::{$method.'Php'}(...$args);
        $native = $class::{$method.'Native'}(...$args);
        $isString = \in_array($helper, self::STRING_HELPERS, true);
        $nativeEligible = array_filter($args[0], static fn (mixed $value): bool => $isString ? ! is_string($value) : ! is_int($value)) === [];
        if ($nativeEligible) {
            $this->assertNotNull($native, $helper.' declined: '.json_encode($this->describe($args)));
            $this->assertSame($expected, $native, $helper.': '.json_encode($this->describe($args)));
        } else {
            $this->assertNull($native, $helper);
        }
        $class::useW403RustFfi(true);
        $this->assertSame($expected, $class::$method(...$args), $helper.' dispatcher');
        $class::useW403RustFfi(null);
    }

    private function describe(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => bin2hex($value),
            is_array($value) => array_map($this->describe(...), $value),
            default => $value,
        };
    }

    /**
     * @return list<mixed>
     */
    private function sampleArgs(string $method): array
    {
        return match ($method) {
            'summarizeCategoryDistribution' => [[2040, 5040, 9000]],
            'summarizeGroupNameRouting' => [['alt.binaries.teevee', 'a.b.boneless', 'comp.os']],
            'summarizeNameFixBatchProgress' => [['nfo', 'predb', 'unfixable'], 2],
            'summarizeCleanedFilenameBatch' => [['Movie.Title.2024.mkv', 'sample.mkv', 'ab'], 5],
            default => [[910, 860, 700], 850],
        };
    }

    /**
     * @return list<mixed>
     */
    private function commonWorkload(string $helper): array
    {
        $roots = [1000, 2000, 3000, 4000, 5000, 6000, 7000, 0, 8000];

        return match ($helper) {
            'distribution' => [array_map(static fn (int $i): int => $roots[$i % 9] + ($i * 10) % 100, range(1, 64))],
            'routing' => [array_map(static fn (int $i): string => match ($i % 8) {
                0 => 'alt.binaries.teevee',
                1 => 'alt.binaries.moovee',
                2 => 'alt.binaries.boneless',
                3 => 'alt.binaries.sounds.mp3',
                4 => 'alt.binaries.multimedia',
                5 => 'a.b.erotica',
                6 => 'alt.binaries.games.xbox360',
                default => 'alt.binaries.hdtv.x264',
            }, range(1, 32))],
            'progress' => [array_map(static fn (int $i): string => ['predb', 'nfo', 'files', 'par2', 'unfixable', 'unfixable', 'predb'][$i % 7], range(1, 100)), 500],
            'filenames' => [array_map(static fn (int $i): string => match ($i % 6) {
                0 => 'Some.Show.S01E'.sprintf('%02d', $i).'.720p.WEB.h264-GRP.mkv',
                1 => 'some.show.s01e'.sprintf('%02d', $i).'.720p.web.h264-grp.part01.rar',
                2 => 'Sample/sample.mkv',
                3 => 'Movie.Title.'.(1990 + $i).'.1080p.BluRay.x264-GRP.nfo',
                4 => sprintf('%05d', $i).'.jpg',
                default => 'grp-mtt'.$i.'.r'.sprintf('%02d', $i),
            }, range(1, 24)), 5],
            default => [[912, 640, 701, 655, 830, 590, 702, 615, 777, 688], 850],
        };
    }

    /**
     * @return list<mixed>
     */
    private function uncommonWorkload(string $helper): array
    {
        return match ($helper) {
            'distribution' => [array_map(static fn (int $i): int => match ($i % 5) {
                0 => -$i,
                1 => 9000 + $i,
                default => ($i * 37) % 9000,
            }, range(1, 5000))],
            'routing' => [array_map(static fn (int $i): string => '  ALT.BINARIES.'.str_repeat('obfuscated.mirror.', 6).'group'.$i.'  ', range(1, 1000))],
            'progress' => [array_map(static fn (int $i): string => ["  PREDB\t", ' Nfo', 'FILES ', "\0par2", 'Unfixable', 'skipped', 'pending-retry'][$i % 7], range(1, 5000)), 2500],
            'filenames' => [array_map(static fn (int $i): string => ' '.str_repeat('Some/Deep\\Directory.Tree/', 4).'Long.Release.Name.'.($i % 400).'.2160p.UHD.BluRay.REMUX-GRP.mkv ', range(1, 2000)), 8],
            default => [array_map(static fn (int $i): int => match ($i % 4) {
                0 => PHP_INT_MAX - $i,
                1 => PHP_INT_MIN + $i,
                default => ($i * 7919) % 1100,
            }, range(1, 500)), 990],
        };
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function runWorkload(string $class, string $method, array $args, bool $useRust): int
    {
        $class::useW403RustFfi($useRust);

        return count($class::$method(...$args));
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W403_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w403', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 40.3 exports is not available.');
        }

        return $ffi;
    }
}
