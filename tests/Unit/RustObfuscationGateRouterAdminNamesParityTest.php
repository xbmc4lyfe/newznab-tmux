<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxResetPostProcessing;
use App\Http\Controllers\Admin\AdminGroupController;
use App\Services\Binaries\PerArticleObfuscationGate;
use App\Services\Binaries\PerArticleObfuscationRouter;
use App\Support\AdminReleaseNames;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustObfuscationGateRouterAdminNamesParityTest extends TestCase
{
    /** Class => [enable, query, reset, FFI property, default constant]. */
    private const TOGGLES = [
        PerArticleObfuscationGate::class => ['useRustFfi', 'usesRustFfi', 'resetRustFfi', 'ffi', 'RUST_FFI_DEFAULT'],
        PerArticleObfuscationRouter::class => ['useRustFfi', 'usesRustFfi', 'resetRustFfi', 'ffi', 'RUST_FFI_DEFAULT'],
        AdminReleaseNames::class => ['useRustFfi', 'usesRustFfi', 'resetRustFfi', 'ffi', 'RUST_FFI_DEFAULT'],
        NntmuxResetPostProcessing::class => ['useW381RustFfi', 'usesW381RustFfi', 'resetW381RustFfi', 'w381Ffi', 'W381_RUST_FFI_DEFAULT'],
        AdminGroupController::class => ['useW381RustFfi', 'usesW381RustFfi', 'resetW381RustFfi', 'w381Ffi', 'W381_RUST_FFI_DEFAULT'],
    ];

    /** Helper name => owning class. */
    private const HELPERS = [
        'summarizeSubjectSample' => PerArticleObfuscationGate::class,
        'summarizeSampleCosts' => PerArticleObfuscationRouter::class,
        'summarizeRenderBatch' => AdminReleaseNames::class,
        'planCategoryReset' => NntmuxResetPostProcessing::class,
        'normalizeBulkAddPlan' => AdminGroupController::class,
    ];

    private const RANDOM_CASES = 1_200;

    private const TOKEN = 'Xk9QmZ2vB7nL4pR8';

    private const SUBJECTS = [
        null, 7, 1.5, true, ['x'], '', ' (', '(', 'a (1/2)', 'a(1/2)', self::TOKEN.' (1/20)', self::TOKEN.' file.rar (01/20)', self::TOKEN.' (1/1)',
        self::TOKEN.' (1/02)', self::TOKEN.' (1/001)', self::TOKEN.'(1/20)', self::TOKEN.' (/20)', self::TOKEN.' (1/20', self::TOKEN.' (1/', self::TOKEN.' (1/',
        'short name (1/20)', '(1/2) x (', "x\x0b(1/9) (2/3)", "x\x0c(1/9)", "x\xa0(1/9)", "x\x85(1/9)", 'Usenet Index Post '.self::TOKEN.' (1/20)',
        ' Usenet Index Post '.self::TOKEN.' (1/20)', self::TOKEN.' (x) (1/99999999999999999999999)', self::TOKEN.' (1/00)', 'ünïcode '.self::TOKEN.' (3/4)',
    ];

    private const ENVIRONMENTS = ['local', 'Local', ' local', 'production', 'testing', '', "local\0"];

    private const FIRST_OPTIONS = [null, 'misc', 'Misc', 'miscs', 'tv', '', ' misc'];

    private const CATEGORIES = ['music', 'console', 'movie', 'game', 'tv', 'adult', 'book', 'misc', 'all', 'ALL', 'tvs', 'foo', '', ' tv', 'misc '];

    private const FILTERS = [
        null, 7, ['x'], '', ' ', "\n", 'abc', "abc\n", "\nabc", "a\n\nb", "a\n \nb", "a\n \r", "a\r\nb", "a\n\x0b", "\xa0", "\x85", "a\n\xa0", "a\n\n",
        'alt.binaries.*', "alt.binaries.(tv|hdtv)\nalt.binaries.movies", "^alt\\.binaries\\.\t\n",
    ];

    private const ACTIONS = ['submit', 'view', '', 'Submit', 'submit ', 'add'];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::TOGGLES as $class => [, , $reset]) {
            $class::$reset();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_follow_the_benchmark_and_toggle_independently(): void
    {
        // Only the router's cost summary cleared 1.5x on every required workload.
        $defaults = [];
        foreach (array_keys(self::TOGGLES) as $class) {
            $defaults[$class] = $this->defaultFor($class);
        }
        $this->assertSame([PerArticleObfuscationGate::class => false, PerArticleObfuscationRouter::class => true, AdminReleaseNames::class => false,
            NntmuxResetPostProcessing::class => false, AdminGroupController::class => false], $defaults);
        foreach (self::TOGGLES as $class => [$use, , $reset, $property]) {
            $default = $defaults[$class];
            $this->assertSame($default, $this->usesFor($class), $class);
            $class::$use(! $default);
            $this->assertSame(! $default, $this->usesFor($class), $class);
            foreach ($defaults as $other => $otherDefault) {
                if ($other !== $class) {
                    $this->assertSame($otherDefault, $this->usesFor($other), $other.' must not follow '.$class);
                }
            }
            $class::$use($default);
            $this->assertSame($default, $this->usesFor($class), $class);
            $class::$use(! $default);
            $class::$reset();
            $this->assertSame([$default, null], [$this->usesFor($class), (new ReflectionProperty($class, $property))->getValue()], $class);
        }

        // The Wave 38.1 toggles are separate from the earlier helpers' toggles on these classes.
        AdminGroupController::useW381RustFfi(true);
        $this->assertFalse(AdminGroupController::usesRustFfi());
        AdminGroupController::setUseRustFfi(true);
        AdminGroupController::useW381RustFfi(false);
        $this->assertTrue(AdminGroupController::usesRustFfi());
        AdminGroupController::setUseRustFfi(null);
        NntmuxResetPostProcessing::useW381RustFfi(true);
        $this->assertNull((new NntmuxResetPostProcessing)->useRustFfi);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(['samples' => 6, 'strings' => 5, 'hits' => 4, 'may_carry_part_counts' => true],
            PerArticleObfuscationGate::summarizeSubjectSample(['a (1/2)', 'b (x', null, 'c(1/2)', ' (', 'x ( y']));
        $this->assertSame(['samples' => 0, 'strings' => 0, 'hits' => 0, 'may_carry_part_counts' => false], PerArticleObfuscationGate::summarizeSubjectSample([]));

        $candidate = self::TOKEN.' file.rar (1/20)';
        $this->assertSame(
            ['no_part_count' => 2, 'part_count' => 0, 'candidates' => 1, 'php_ns' => 1350 + 192 + 210 + 210 + 2, 'rust_ns' => 330 + 118 + 265 + 265 + 5,
                'max_length' => 32, 'predicts_speedup' => false, 'within_subject_limit' => true],
            PerArticleObfuscationRouter::summarizeSampleCosts([$candidate, null, 'plain']),
        );
        $classes = [];
        foreach ([self::TOKEN.' (1/1)', self::TOKEN.' (1/02)', 'x (1/9)', 'Usenet Index Post '.$candidate, ' Usenet Index Post '.$candidate, "{$candidate}\0"] as $subject) {
            $plan = PerArticleObfuscationRouter::summarizeSampleCosts([$subject]);
            $classes[] = array_search(1, [$plan['no_part_count'], $plan['part_count'], $plan['candidates']], true);
        }
        $this->assertSame([1, 2, 1, 0, 2, 2], $classes);
        $this->assertFalse(PerArticleObfuscationRouter::summarizeSampleCosts([str_repeat('a', 513)])['within_subject_limit']);
        $this->assertTrue(PerArticleObfuscationRouter::summarizeSampleCosts(array_fill(0, 32, $candidate))['predicts_speedup']);

        $rows = [['searchname' => 'A.B', 'name' => 'A.B', 'guid' => 'abc-1._~'], ['searchname' => 'A', 'name' => 'B', 'guid' => 'a/b'],
            ['name' => 'B', 'guid' => ''], (object) ['searchname' => 'x', 'name' => 'x', 'guid' => 'x']];
        $this->assertSame(['rows' => 4, 'native_eligible' => false, 'unreserved_guids' => 1, 'invalid_rows' => 2, 'identical_names' => 1, 'name_bytes' => 9],
            AdminReleaseNames::summarizeRenderBatch($rows));
        $this->assertTrue(AdminReleaseNames::summarizeRenderBatch(array_fill(0, AdminReleaseNames::MIN_NATIVE_ROWS, $rows[0]))['native_eligible']);
        $this->assertFalse(AdminReleaseNames::summarizeRenderBatch(array_fill(0, 16, $rows[0]), false)['native_eligible']);

        $refused = ['action' => 'refuse_environment', 'invalid' => [], 'steps' => [], 'reindexes_search' => false];
        $this->assertSame($refused, NntmuxResetPostProcessing::planCategoryReset('production', null, ['tv']));
        $this->assertSame($refused, NntmuxResetPostProcessing::planCategoryReset('production', 'Misc', ['misc']));
        $this->assertSame(['action' => 'categories', 'invalid' => [], 'steps' => ['misc'], 'reindexes_search' => true],
            NntmuxResetPostProcessing::planCategoryReset('production', 'misc', ['misc']));
        $this->assertSame(['action' => 'invalid', 'invalid' => ['foo', 7], 'steps' => [], 'reindexes_search' => false],
            NntmuxResetPostProcessing::planCategoryReset('local', null, ['tv', 'foo', 'all', 7]));
        $this->assertSame(['action' => 'full', 'invalid' => [], 'steps' => [], 'reindexes_search' => true], NntmuxResetPostProcessing::planCategoryReset('local', null, []));
        $this->assertSame('full', NntmuxResetPostProcessing::planCategoryReset('local', null, ['tv', 'all'])['action']);
        $this->assertSame(['action' => 'categories', 'invalid' => [], 'steps' => ['adult', 'book', 'console'], 'reindexes_search' => false],
            NntmuxResetPostProcessing::planCategoryReset('local', null, ['adult', 'book', 'console']));

        $this->assertSame(['submit' => true, 'runs' => true, 'blank_list' => false, 'active' => 1, 'backfill' => 0],
            AdminGroupController::normalizeBulkAddPlan('submit', 'alt.binaries.*', null, 0));
        $this->assertSame(['submit' => true, 'runs' => false, 'blank_list' => true, 'active' => 5, 'backfill' => 1],
            AdminGroupController::normalizeBulkAddPlan('submit', '', 5, null));
        $this->assertSame(['submit' => false, 'runs' => false, 'blank_list' => false, 'active' => 1, 'backfill' => 1],
            AdminGroupController::normalizeBulkAddPlan('view', ['x'], null, null));
        foreach (self::FILTERS as $filter) {
            if (is_string($filter)) {
                $this->assertSame(preg_match('/^\s*$/m', $filter) === 1, AdminGroupController::normalizeBulkAddPlan('submit', $filter, null, null)['blank_list'], bin2hex($filter));
            }
        }
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $this->assertHelperParity('summarizeSubjectSample', [self::SUBJECTS]);
        $this->assertHelperParity('summarizeSampleCosts', [self::SUBJECTS]);
        foreach (self::SUBJECTS as $i => $subject) {
            $this->assertHelperParity('summarizeSubjectSample', [[$subject]]);
            $this->assertHelperParity('summarizeSampleCosts', [[$subject]]);
            $this->assertHelperParity('summarizeSampleCosts', [['k'.$i => $subject, 'x' => str_repeat('a', 500 + $i)]]);
        }
        $this->assertHelperParity('summarizeSubjectSample', [[]]);
        $this->assertHelperParity('summarizeSampleCosts', [[]]);

        $fields = [null, 7, '', 'abc', 'abc-1._~', 'a/b', 'a b', 'ünï', "a\0b", 'A.B', '%41'];
        foreach ($fields as $i => $searchname) {
            foreach ($fields as $j => $guid) {
                $row = ['searchname' => $searchname, 'name' => $fields[($i + $j) % count($fields)], 'guid' => $guid];
                $this->assertHelperParity('summarizeRenderBatch', [[$row, 'x', ['guid' => $guid]], ($i + $j) % 2 === 0]);
            }
        }
        $this->assertHelperParity('summarizeRenderBatch', [[], true]);
        $this->assertHelperParity('summarizeRenderBatch', [array_fill(0, 15, ['searchname' => 'a', 'name' => 'a', 'guid' => 'g']), true]);
        $this->assertHelperParity('summarizeRenderBatch', [array_fill(0, 16, ['searchname' => 'a', 'name' => 'a', 'guid' => 'g']), true]);

        foreach (self::ENVIRONMENTS as $environment) {
            foreach (self::FIRST_OPTIONS as $first) {
                foreach ([[], ['tv'], ['all'], ['misc', 'tv', 'tv'], self::CATEGORIES, ['movie', 7], ['adult', 'book'], ['foo', 'all']] as $normalized) {
                    $this->assertHelperParity('planCategoryReset', [$environment, $first, $normalized]);
                }
            }
        }
        $this->assertHelperParity('planCategoryReset', ['local', null, ['a' => 'tv', 9 => 'movie']]);

        foreach (self::ACTIONS as $action) {
            foreach (self::FILTERS as $i => $filter) {
                $this->assertHelperParity('normalizeBulkAddPlan', [$action, $filter, [null, 0, 1, -1, PHP_INT_MIN, PHP_INT_MAX][$i % 6], [null, PHP_INT_MAX, 0][$i % 3]]);
            }
        }
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3811));
        $pick = fn (array $pool): mixed => $pool[$random->getInt(0, count($pool) - 1)];
        $bytes = fn (int $max): string => ($length = $random->getInt(0, $max)) === 0 ? '' : $random->getBytes($length);
        $int = fn (): int => match ($random->getInt(0, 2)) {
            0 => $pick([PHP_INT_MIN, -1, 0, 1, 2, PHP_INT_MAX]),
            1 => $random->getInt(-5, 5),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $pieces = [' ', "\t", "\n", "\r", "\x0b", "\x0c", "\xa0", "\x85", '(', ')', '/', '0', '1', '2', '9', '00', '20', self::TOKEN, 'abc', 'rar', '.', '-',
            'Usenet Index Post', "\0", 'é'];
        $subject = function () use ($random, $pick, $pieces, $bytes): mixed {
            $kind = $random->getInt(0, 19);
            if ($kind === 0) {
                return $pick([null, 3, 1.5, false]);
            }
            if ($kind === 1) {
                return $bytes(40);
            }
            if ($kind === 2) {
                return str_repeat('a', $random->getInt(500, 530)).' (1/2)';
            }
            $value = '';
            for ($n = $random->getInt(0, 14); $n > 0; $n--) {
                $value .= $pick($pieces);
            }

            return $value;
        };
        $subjects = function () use ($random, $subject): array {
            $list = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $list[] = $subject();
            }

            return $list;
        };
        $field = fn (): mixed => match ($random->getInt(0, 7)) {
            0 => $pick([null, 5, true]),
            1 => $bytes(10),
            2 => $pick(['', 'a', 'A.B', 'abc-1._~', 'a/b', 'ünï', '%20']),
            default => strtr(base64_encode($bytes(18)), ['+' => $pick(['+', '-', '_']), '/' => $pick(['/', '.', '~'])]),
        };
        $filterPieces = ["\n", ' ', "\r", "\t", "\x0b", "\x0c", "\xa0", "\x85", 'a', 'alt.binaries.*', '(tv|hd)', '^', '$', '\\'];

        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $sample = $subjects();
            $this->assertHelperParity('summarizeSubjectSample', [$sample]);
            $this->assertHelperParity('summarizeSampleCosts', [$sample]);

            $rows = [];
            for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                $rows[] = match ($random->getInt(0, 9)) {
                    0 => $pick([null, 'row', 4]),
                    1 => ['guid' => $field()],
                    default => ['searchname' => $value = $field(), 'name' => $random->getInt(0, 2) === 0 ? $value : $field(), 'guid' => $field()],
                };
            }
            $this->assertHelperParity('summarizeRenderBatch', [$rows, (bool) $random->getInt(0, 1)]);

            $normalized = [];
            for ($n = $random->getInt(0, 6); $n > 0; $n--) {
                $normalized[] = $random->getInt(0, 30) === 0 ? $random->getInt(0, 9) : ($random->getInt(0, 9) === 0 ? $bytes(6) : $pick(self::CATEGORIES));
            }
            $this->assertHelperParity('planCategoryReset', [$pick(self::ENVIRONMENTS), $pick(self::FIRST_OPTIONS), $normalized]);

            $filter = '';
            for ($n = $random->getInt(0, 10); $n > 0; $n--) {
                $filter .= $pick($filterPieces);
            }
            $filter = match ($random->getInt(0, 9)) {
                0 => $pick(self::FILTERS),
                1 => $bytes(20),
                default => $filter,
            };
            $this->assertHelperParity('normalizeBulkAddPlan', [$pick(self::ACTIONS), $filter, $random->getInt(0, 2) === 0 ? null : $int(),
                $random->getInt(0, 2) === 0 ? null : $int()]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[64]');
        FFI::memset($out, ord('Z'), 64);
        $input = pack('q2', 2, 5).'a (1)'.pack('q', -1);
        $length = strlen($input);

        $this->assertSame(-32, $this->callFfi($ffi, 1, 0, $input, $length, $out, 31));
        $this->assertSame(-32, $this->callFfi($ffi, 1, 0, $input, $length, $out, 0));
        foreach ([[0, 0], [6, 0], [1, 1], [2, 1], [3, 2], [4, 2], [5, 8]] as [$operation, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $flags, $input, $length, $out, 64), "op {$operation} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 1, 0, null, $length, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 1, 0, $input, $length, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 1, 0, $input, $length - 1, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 1, 0, $input, 16_777_217, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 1, 0, pack('q', PHP_INT_MAX), 8, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 1, 0, pack('q2', 1, -2), 16, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, pack('q3', 1, -1, -1), 24, $out, 64));
        $bulk = pack('q', 6).'submit'.pack('q', 1).'x'.pack('q2', 0, 0);
        $this->assertSame(-1, $this->callFfi($ffi, 5, 0, $bulk, strlen($bulk), $out, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));
        $this->assertSame(40, $this->callFfi($ffi, 5, 1, $bulk, strlen($bulk), $out, 64));
        $this->assertSame(pack('q5', 1, 1, 0, 1, 1), FFI::string($out, 40));

        // A short buffer reports the exact size; retrying with that capacity succeeds without overrunning it.
        $reset = pack('q', 5).'local'.pack('q2', 0, 3).pack('q', 2).'tv'.pack('q', 3).'foo'.pack('q', 6).'xyzzy!';
        FFI::memset($out, ord('Z'), 64);
        $required = $this->callFfi($ffi, 4, 0, $reset, strlen($reset), $out, 8);
        $this->assertSame(-57, $required);
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));
        $large = $ffi->new('char[96]');
        FFI::memset($large, ord('Z'), 96);
        $this->assertSame(57, $this->callFfi($ffi, 4, 0, $reset, strlen($reset), $large, -$required));
        $this->assertSame(pack('q4', 2, 0, 2, 0).pack('q', 3).'foo'.pack('q', 6).'xyzzy!'.str_repeat('Z', 39), FFI::string($large, 96));

        FFI::memset($out, ord('Z'), 64);
        $this->assertSame(32, $this->callFfi($ffi, 1, 0, $input, $length, $out, 64));
        $this->assertSame(pack('q4', 2, 1, 1, 0).str_repeat('Z', 32), FFI::string($out, 64));

        foreach (self::TOGGLES as $class => [$use]) {
            $class::$use(true);
        }
        PerArticleObfuscationGate::summarizeSubjectSample(['a']);
        PerArticleObfuscationRouter::summarizeSampleCosts(['a']);
        AdminReleaseNames::summarizeRenderBatch([]);
        NntmuxResetPostProcessing::planCategoryReset('local', null, ['tv']);
        AdminGroupController::normalizeBulkAddPlan('submit', 'x', null, null);
        foreach (self::TOGGLES as $class => [, , , $property]) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, $property))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $cases = [
            'summarizeSubjectSample' => [['a (1/2)', null, 'b']],
            'summarizeSampleCosts' => [[self::TOKEN.' (1/20)', 'x']],
            'summarizeRenderBatch' => [[['searchname' => 'a', 'name' => 'b', 'guid' => 'c']], true],
            'planCategoryReset' => ['local', null, ['tv', 'movie']],
            'normalizeBulkAddPlan' => ['submit', "a\n\nb", 0, null],
        ];
        foreach (self::TOGGLES as $class => [$use, , , $property]) {
            $class::$use(true);
            (new ReflectionProperty($class, $property))->setValue(null, false);
        }
        foreach ($cases as $helper => $args) {
            $class = self::HELPERS[$helper];
            $this->assertNull($this->helper($class, $helper.'Native')(...$args), $helper);
            $this->assertSame($this->helper($class, $helper.'Php')(...$args), $class::$helper(...$args), $helper);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // frame packing, the FFI call and decoding (release dylib, 5 warmups, 201 samples x 20
        // iterations). Common = a 16-subject gate sample, a 64-subject router sample of 60-200 byte
        // XOVER subjects, a 50-row render batch, a `--category=tv` reset and a one-line bulk filter;
        // uncommon = 128 subjects near the 512-byte limit, a 400-row batch with odd GUIDs and
        // non-string fields, a mixed 40-entry category list and a 4 KiB multi-line filter.
        // Speedup = PHP latency / Rust latency per workload pass, common mean/p99 then uncommon mean/p99
        // (PHP 8.5.12 NTS, Apple M5, release dylib, 3 rounds):
        // - Round 1: gate 0.21x/0.22x, 0.30x/0.36x; router 2.10x/1.79x, 12.03x/10.38x;
        //   names 0.51x/0.54x, 0.57x/0.60x; reset 0.26x/0.31x, 0.44x/0.45x; group 0.21x/0.16x, 0.13x/0.14x
        // - Round 2: gate 0.21x/0.26x, 0.30x/0.33x; router 2.13x/2.13x, 12.14x/11.07x;
        //   names 0.53x/0.60x, 0.57x/0.61x; reset 0.25x/0.31x, 0.44x/0.47x; group 0.20x/0.19x, 0.13x/0.15x
        // - Round 3: gate 0.21x/0.22x, 0.30x/0.32x; router 2.13x/1.93x, 12.03x/10.69x;
        //   names 0.53x/0.55x, 0.59x/0.60x; reset 0.25x/0.46x, 0.44x/0.44x; group 0.21x/0.24x, 0.12x/0.15x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustObfuscationGateRouterAdminNamesParityTest.php --filter benchmark
        // The router's cost summary ports a per-byte subject scan and clears the 1.5x bar in
        // .ai/rules/rust-performance.md on every workload, so PerArticleObfuscationRouter::RUST_FFI_DEFAULT
        // is true. The other four helpers do too little work to repay frame packing and the FFI
        // round trip, so they keep their Wave 38.1 Rust FFI default false.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3812));
        $workloads = ['common' => $this->commonWorkload($random), 'uncommon' => $this->uncommonWorkload($random)];
        $warmup = 5;
        $samples = 201;
        $iterations = 20;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => $class) {
            [$use] = self::TOGGLES[$class];
            foreach ($workloads as $name => $workload) {
                $args = $workload[$helper];
                $this->assertHelperParity($helper, $args);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $class::$use($useRust);
                        $sink += count($class::$helper(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                        $class::$use($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$helper(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::$use(null);
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustObfuscationGateRouterAdminNames speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => $class) {
            [, $uses] = self::TOGGLES[$class];
            $speedup = $speedups[$helper];
            if ($speedup['common']['mean'] < 1.5 || $speedup['common']['p99'] < 1.5 || $speedup['uncommon']['p99'] < 1.5) {
                $this->assertFalse($this->defaultFor($class), $class.' must keep PHP below 1.5x: '.$summary);
            }
            foreach ($speedup as $name => $values) {
                $this->assertGreaterThan(0.0, $values['mean'], $helper.' '.$name);
                $this->assertGreaterThan(0.0, $values['p99'], $helper.' '.$name);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertHelperParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        [$use] = self::TOGGLES[$class];
        $expected = $this->helper($class, $helper.'Php')(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $native = $this->helper($class, $helper.'Native')(...$args);
        // Non-string categories are the only input a Native helper may hand back to PHP.
        if ($native !== null || $helper !== 'planCategoryReset') {
            $this->assertSame($expected, $native, $context);
        }
        foreach ([true, false] as $useRust) {
            $class::$use($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::$use(null);
    }

    private function defaultFor(string $class): bool
    {
        return (bool) constant($class.'::'.self::TOGGLES[$class][4]);
    }

    private function usesFor(string $class): bool
    {
        $uses = self::TOGGLES[$class][1];

        return $class::$uses();
    }

    private function helper(string $class, string $method): Closure
    {
        return $this->closures[$class.'::'.$method] ??= (new ReflectionMethod($class, $method))->getClosure(null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonWorkload(Randomizer $random): array
    {
        $subjects = [];
        for ($i = 0; $i < 64; $i++) {
            $name = bin2hex($random->getBytes($random->getInt(8, 40)));
            $subjects[] = match ($i % 4) {
                0 => '[1/'.$random->getInt(2, 99).'] - "'.$name.'.part'.$i.'.rar" yEnc ('.$random->getInt(1, 50).'/'.$random->getInt(50, 200).')',
                1 => 'Re: '.$name.' discussion thread',
                2 => $name.' yEnc (1/'.$random->getInt(2, 300).')',
                default => strtoupper($name).' - ['.$i.'/64] "'.$name.'.par2" ('.$random->getInt(1, 9).'/9)',
            };
        }
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $name = 'Release.Name.'.$i.'.1080p.WEB-DL.x264-GROUP';
            $rows[] = ['searchname' => $name, 'name' => $i % 3 === 0 ? $name : bin2hex($random->getBytes(16)), 'guid' => bin2hex($random->getBytes(20))];
        }

        return [
            'summarizeSubjectSample' => [array_slice($subjects, 0, 16)],
            'summarizeSampleCosts' => [$subjects],
            'summarizeRenderBatch' => [$rows, true],
            'planCategoryReset' => ['local', 'tv', ['tv']],
            'normalizeBulkAddPlan' => ['submit', 'alt.binaries.teevee', 1, 1],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function uncommonWorkload(Randomizer $random): array
    {
        $subjects = [];
        for ($i = 0; $i < 128; $i++) {
            $subjects[] = match ($i % 5) {
                0 => null,
                1 => str_repeat(self::TOKEN.' ', 28).'('.$i.'/128)',
                2 => 'Usenet Index Post '.bin2hex($random->getBytes(200)).' (1/2)',
                3 => $random->getBytes($random->getInt(400, 512)),
                default => str_repeat('a (b ', 90).self::TOKEN." (1/{$i})",
            };
        }
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = match ($i % 6) {
                0 => ['searchname' => null, 'name' => 'x', 'guid' => 'a/b?c'],
                1 => 'not-a-row',
                2 => ['searchname' => str_repeat('ü', 60), 'name' => str_repeat('ü', 60), 'guid' => '%2F'.$i],
                default => ['searchname' => 'Name.'.$i, 'name' => 'Other.'.$i, 'guid' => bin2hex($random->getBytes(20)).'~._-'],
            };
        }
        $categories = [];
        for ($i = 0; $i < 40; $i++) {
            $categories[] = ['music', 'console', 'movie', 'game', 'tv', 'adult', 'book', 'misc'][$i % 8];
        }
        $filter = '';
        for ($i = 0; $i < 160; $i++) {
            $filter .= 'alt.binaries.group'.$i.'.*'.($i % 40 === 39 ? "\r\n" : ' ');
        }

        return [
            'summarizeSubjectSample' => [array_slice($subjects, 0, 16)],
            'summarizeSampleCosts' => [$subjects],
            'summarizeRenderBatch' => [$rows, true],
            'planCategoryReset' => ['production', 'misc', $categories],
            'normalizeBulkAddPlan' => ['submit', $filter, PHP_INT_MAX, null],
        ];
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

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w381_obfuscation_gate_router_admin_names(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w381', PerArticleObfuscationGate::FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 38.1 gate/router/admin-names export is not available.');
        }

        return $ffi;
    }
}
