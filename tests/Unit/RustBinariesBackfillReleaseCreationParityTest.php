<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReleaseCreationService;
use App\Services\Runners\BackfillRunner;
use App\Services\Runners\BinariesRunner;
use App\Support\Data\ProcessReleasesSettings;
use App\Support\Data\ReleaseCreationResult;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;
use TypeError;

/**
 * Reproduce: NNTMUX_BINARIES_BACKFILL_RELEASE_CREATION_BENCH=1 php artisan test
 * --compact tests/Unit/RustBinariesBackfillReleaseCreationParityTest.php
 *
 * Protocol: optimized cdylib; 200 warmup and 2,000 randomized single-call pairs
 * per method/workload, including PHP marshaling, allocation and FFI. Fixed seed,
 * identical inputs and correctness gates precede timing. Raw nanoseconds, source
 * and runtime identities, order and summaries are retained in a unique /tmp file.
 * These pure-helper measurements do not establish production pipeline throughput.
 */
final class RustBinariesBackfillReleaseCreationParityTest extends TestCase
{
    private const array CLASSES = [BinariesRunner::class, BackfillRunner::class, ReleaseCreationService::class, ProcessReleasesSettings::class, ReleaseCreationResult::class];

    private FFI $ffi;

    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', BinariesRunner::FFI_CDEF."\n".ProcessReleasesSettings::FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release Rust library; fallback-only runs cannot establish parity.');
        $this->ffi = $ffi;
        $this->random = new Randomizer(new Mt19937(18012026));
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    private function releaseService(): ReleaseCreationService
    {
        // The extracted helpers have no service dependencies or database effects.
        return (new ReflectionClass(ReleaseCreationService::class))->newInstanceWithoutConstructor();
    }

    /** @param list<string> $fields */
    private function frame(array $fields): string
    {
        return implode('', array_map(static fn (string $field): string => pack('V', strlen($field)).$field, $fields));
    }

    private function transform(int $mode, ?string $input, int $len, ?CData $out, int $cap): int
    {
        // @phpstan-ignore method.notFound
        return $this->ffi->nntmux_runner_release_transform($mode, $input, $len, $out, $cap);
    }

    private function values(int $mode, ?CData $input, int $len, ?CData $out, int $cap): int
    {
        // @phpstan-ignore method.notFound
        return $this->ffi->nntmux_release_creation_values($mode, $input, $len, $out, $cap);
    }

    public function test_abi_byte_frames_exact_capacity_and_atomic_errors(): void
    {
        $input = $this->frame(['3']);
        $expected = $this->frame(['ORDER BY name ASC']);
        $out = $this->ffi->new('char[128]');
        FFI::memset($out, 90, 128);
        self::assertSame(-2, $this->transform(2, $input, strlen($input), $out, strlen($expected) - 1));
        self::assertSame(str_repeat('Z', 128), FFI::string($out, 128));
        self::assertSame(strlen($expected), $this->transform(2, $input, strlen($input), $out, strlen($expected)));
        self::assertSame($expected, FFI::string($out, strlen($expected)));
        foreach ([['', 1], ["\1\0", 2], [pack('V', 999).'a', 5]] as [$bad, $len]) {
            self::assertSame(-1, $this->transform(2, $bad === '' ? null : $bad, $len, $out, 128));
        }
        self::assertSame(-1, $this->transform(99, $input, strlen($input), $out, 128));
        self::assertSame(-1, $this->transform(2, $input, strlen($input), null, 128));
        self::assertSame(-1, $this->transform(2, $input, -1, $out, 128));
        self::assertSame(-1, $this->transform(2, $input, strlen($input), $out, -1));
        $overflow = $this->frame(['alt.test', (string) PHP_INT_MAX, '1', (string) PHP_INT_MAX, '2', '1']);
        self::assertSame(-3, $this->transform(4, $overflow, strlen($overflow), $out, 128));
        // Empty round-robin plans accept null pointers for zero-sized regions.
        self::assertSame(0, $this->transform(1, null, 0, null, 0));
        $input = $this->frame(['a#$@%^§¨©Ö'."\0\xff", "'''poster'''"]);
        $expected = $this->frame(["a\0\xff", 'poster']);
        self::assertSame(strlen($expected), $this->transform(5, $input, strlen($input), $out, 128));
        self::assertSame($expected, FFI::string($out, strlen($expected)));
    }

    public function test_all_planner_modes_execute_without_php_fallback(): void
    {
        $cases = [
            [0, ['1', '8', '3', '5', 'alt.test', '100', '200000', '1'], ['5', 'get_range  binaries  alt.test  101  103', "--contiguous\0--repair", '5', 'get_range  binaries  alt.test  104  106', '--contiguous', '5', 'get_range  binaries  alt.test  107  108', '--contiguous', '5', 'part_repair  alt.test', '']],
            [1, ['5', '2', '2', '1'], ['5', '0', '2', '0', '5', '1']],
            [2, ['5'], ['ORDER BY a.last_record DESC']],
            [3, ['2', '31.125'], ['31.125']],
            [4, ['alt.test', '20', '1', '100', '2', '30'], ['get_range  backfill  alt.test  -10  19  1']],
            [5, ["\xC2#\xA7", "'''poster'''"], ['', 'poster']],
            [7, ['2', 'a', '3', 'b', '3', '1', '3', 'a', 'b', 'missing'], ['1', '3']],
            [8, ['1', '42', '-3'], ['42', '0', '1']],
            [9, ['2:1', '1:0', '3', '2:1', '2'], ['2']],
        ];
        $out = $this->ffi->new('char[2048]');
        foreach ($cases as [$mode, $fields, $expected]) {
            $input = $this->frame($fields);
            $expected = $this->frame($expected);
            self::assertSame(strlen($expected), $this->transform($mode, $input, strlen($input), $out, 2048), 'Mode '.$mode);
            self::assertSame($expected, FFI::string($out, strlen($expected)), 'Mode '.$mode);
        }
    }

    public function test_abi_integer_alignment_length_capacity_and_overflow(): void
    {
        $input = $this->ffi->new('int64_t[4]');
        foreach ([101, 1, 2, 1] as $i => $value) {
            $input[$i] = $value;
        }
        $out = $this->ffi->new('int64_t[5]');
        FFI::memset($out, 90, FFI::sizeof($out));
        $before = FFI::string($this->ffi->cast('char *', FFI::addr($out[0])), 40);
        self::assertSame(-2, $this->values(0, $input, 4, $out, 4));
        self::assertSame($before, FFI::string($this->ffi->cast('char *', FFI::addr($out[0])), 40));
        $bytes = $this->ffi->new('char[48]');
        $unaligned = $this->ffi->cast('int64_t *', FFI::addr($bytes[1]));
        self::assertSame(-1, $this->values(0, $unaligned, 4, $out, 5));
        self::assertSame(-1, $this->values(0, $input, 4, $unaligned, 5));
        foreach ([0, 3, 5, PHP_INT_MAX, -1] as $len) {
            self::assertSame(-1, $this->values(0, $input, $len, $out, 5));
        }
        self::assertSame(-1, $this->values(0, null, 4, $out, 5));
        self::assertSame(-1, $this->values(0, $input, 4, null, 5));
        self::assertSame(-1, $this->values(0, $input, 4, $out, PHP_INT_MAX));
        self::assertSame(5, $this->values(0, $input, 4, $out, 5));
        self::assertSame([100, 0, 1, 1, 1], array_map(static fn (int $i): int => (int) $out[$i], range(0, 4)));
        foreach ([[PHP_INT_MAX, 1], [PHP_INT_MIN, -1]] as $values) {
            $input[0] = $values[0];
            $input[1] = $values[1];
            self::assertSame(-3, $this->values(1, $input, 2, $out, 5));
        }
        // Overlap is safe because Rust owns the normalized output before copying.
        $input[0] = 3;
        $input[1] = 7;
        self::assertSame(4, $this->values(1, $input, 2, $input, 4));
        self::assertSame([10, 1, 3, 7], array_map(static fn (int $i): int => (int) $input[$i], range(0, 3)));
    }

    public function test_binaries_planning_round_robin_and_worker_keys(): void
    {
        $runner = new BinariesRunner;
        foreach ([[], [(object) ['id' => 1, 'groupname' => 'alt.test', 'our_last' => 0, 'their_last' => 1, 'total' => 1]], $this->groupRows(6)] as $groups) {
            foreach ([false, true] as $repair) {
                foreach ([[100, 30], [0, 30], [-1, 0], [50000, 20000]] as [$headers, $messages]) {
                    $php = $runner->planSafeBinariesCommandsPhp($groups, $repair, $headers, $messages);
                    self::assertSame($php, $runner->planSafeBinariesCommandsNative($groups, $repair, $headers, $messages));
                    self::assertSame($php, $runner->planSafeBinariesCommands($groups, $repair, $headers, $messages));
                    self::assertSame($runner->interleaveScheduledGroupCommandsPhp($php), $runner->interleaveScheduledGroupCommandsNative($php));
                }
            }
        }
        $duplicates = [(object) ['id' => 1, 'groupname' => 'first', 'our_last' => 0, 'their_last' => 100], (object) ['id' => 1, 'groupname' => 'second', 'our_last' => 0, 'their_last' => 100]];
        self::assertSame($runner->planSafeBinariesCommandsPhp($duplicates, true, 100, 30), $runner->planSafeBinariesCommandsNative($duplicates, true, 100, 30));
        $group = [(object) ['id' => 5, 'groupname' => 'alt.test', 'our_last' => 100, 'their_last' => 200000]];
        $plan = $runner->planSafeBinariesCommands($group, false, 8, 3);
        self::assertSame(['101', '103', '--contiguous', '--repair'], array_slice($plan[5][0], -4));
        self::assertSame(['107', '108', '--contiguous'], array_slice($plan[5][2], -3));
        $plan[2] = [['one']];
        $scheduled = $runner->interleaveScheduledGroupCommands($plan);
        self::assertSame(['5:0', '2:0', '5:1', '5:2'], array_keys($scheduled['commands']));
        (new ReflectionProperty(BinariesRunner::class, 'scheduledGroups'))->setValue($runner, $scheduled['scheduledGroups']);
        foreach (['5:0', '2:0', 'unknown', 0, '0', "5:0\0"] as $key) {
            self::assertSame($runner->workerConcurrencyKeyPhp($key), $runner->workerConcurrencyKeyNative($key));
        }
    }

    /** @return list<object> */
    private function groupRows(int $count): array
    {
        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $groups[] = (object) ['id' => 10 + $i, 'groupname' => 'alt.test.'.$i, 'our_last' => $i % 3 === 0 ? 0 : 100, 'their_last' => 20100 + $i * 50000, 'total' => $i % 2];
        }

        return $groups;
    }

    public function test_backfill_selection_days_and_batch_boundaries(): void
    {
        $runner = new BackfillRunner;
        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, 4, 5, 6, PHP_INT_MAX] as $order) {
            self::assertSame($runner->resolveBackfillOrderSqlPhp($order), $runner->resolveBackfillOrderSqlNative($order));
            foreach (['0', '100.125', '-3.25', "date\0difference"] as $days) {
                self::assertSame($runner->resolveBackfillDaysExpressionPhp($order, $days), $runner->resolveBackfillDaysExpressionNative($order, $days));
            }
        }
        foreach ([[100, 1, 50, 2, 30], [100, 100, 50, 2, 0], [100, 101, 50, 2, 10], [20, 1, 100, 2, 30], [100, 0, -1, 2, 10], [100, 0, 0, 2, 10], [PHP_INT_MAX, PHP_INT_MAX - 4, 2, 1, 2]] as $args) {
            self::assertSame($runner->planSafeBackfillQueuesPhp('alt.test', ...$args), $runner->planSafeBackfillQueuesNative('alt.test', ...$args));
        }
        self::assertSame(['get_range  backfill  alt.test  -10  19  1'], $runner->planSafeBackfillQueues('alt.test', 20, 1, 100, 2, 30));
        for ($i = 0; $i < 100; $i++) {
            $args = ['alt.test.'.$i, $this->random->getInt(1, 100000), $this->random->getInt(0, 1000), $this->random->getInt(0, 100), $this->random->getInt(0, 4), $this->random->getInt(1, 100)];
            self::assertSame($runner->planSafeBackfillQueuesPhp(...$args), $runner->planSafeBackfillQueuesNative(...$args));
        }
        foreach (['planSafeBackfillQueuesPhp', 'planSafeBackfillQueuesNative'] as $method) {
            try {
                $runner->$method('alt.test', 100, 0, 100, 1, 0);
                self::fail('Zero maxMessages must retain DivisionByZeroError.');
            } catch (\DivisionByZeroError $error) {
                self::assertSame(\DivisionByZeroError::class, $error::class);
            }
        }
    }

    public function test_collection_sanitization_metadata_and_crosspost_group_resolution(): void
    {
        $service = $this->releaseService();
        foreach (['', '#@$%^§¨©Ö', "\xC2#\xA7", "\xC2@\xA8", "\xC3%\x96", "a#b\0é", "\xff\xfe\0#", str_repeat('A#§', 10000)] as $subject) {
            foreach (['', "'''", "'''poster'''", " 'poster' ", "\xff\0'"] as $poster) {
                self::assertSame($service->sanitizeCollectionNamesPhp($subject, $poster), $service->sanitizeCollectionNamesNative($subject, $poster));
            }
        }
        foreach ([null, false, true, 'name', [], ['id' => '123', 'predb' => -1], ['id' => PHP_INT_MIN, 'predb' => PHP_INT_MAX, 'properlynamed' => true, 'cleansubject' => ''], ['properlynamed' => 1, 'cleansubject' => 0], ['properlynamed' => null, 'cleansubject' => null]] as $meta) {
            self::assertSame($service->normalizeCollectionReleaseMetaPhp($meta, 'fallback'), $service->normalizeCollectionReleaseMetaNative($meta, 'fallback'));
        }
        $names = [9 => ['unknown' => true], 3 => ['a' => true, 'alias' => true, 'b' => true], 7 => [], 2 => ['b' => true, 'a' => true, '0' => false, "binary\0name" => true]];
        $ids = ['a' => 4, 'alias' => 4, 'b' => 5, '0' => 0, "binary\0name" => 9];
        self::assertSame([3 => [4, 5], 2 => [5, 4, 0, 9]], $service->resolveCollectionGroupIds($names, $ids));
        self::assertSame($service->resolveCollectionGroupIdsPhp($names, $ids), $service->resolveCollectionGroupIdsNative($names, $ids));
        self::assertSame([], $service->resolveCollectionGroupIdsNative([], []));
        self::assertSame(['added' => 0, 'dupes' => 0], $service->createReleases(null, 100, false, []));
    }

    public function test_settings_mutation_live_configuration_and_result_normalization(): void
    {
        foreach ([PHP_INT_MIN, -1, 0, 1, 99, 100, 101, PHP_INT_MAX] as $completion) {
            self::assertSame(min(100, $completion), ProcessReleasesSettings::clampCompletionNative($completion));
            $settings = new ProcessReleasesSettings(completion: $completion);
            self::assertSame(min(100, $completion), $settings->completion);
            $settings->completion = $completion;
            foreach ([-1, 0, 1, PHP_INT_MAX] as $retention) {
                $settings->releaseRetentionDays = $retention;
                $settings->crossPostTime = $retention;
                foreach ([false, true] as $dedupe) {
                    config(['nntmux.release_dedupe_enabled' => $dedupe]);
                    foreach (['hasValidCompletion', 'hasRetentionCleanup', 'hasCrossPostDetection', 'hasCompletionCleanup'] as $method) {
                        self::assertSame($settings->{$method.'Php'}(), $settings->{$method.'Native'}());
                        self::assertSame($settings->{$method.'Php'}(), $settings->$method());
                    }
                }
            }
        }
        foreach ([[0, 0], [3, 4], [-1, 2], [PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, 0]] as [$added, $dupes]) {
            $result = new ReleaseCreationResult($added, $dupes);
            self::assertSame($result->totalPhp(), $result->totalNative());
            self::assertSame($result->hasAddedReleasesPhp(), $result->hasAddedReleasesNative());
            self::assertSame($result->resultArrayPhp(), $result->resultArrayNative());
            self::assertSame($result->resultArrayPhp(), $result->toArray());
        }
        foreach ([[], ['added' => null, 'extra' => 8], ['added' => '12', 'dupes' => '7'], ['added' => -1.5, 'dupes' => false]] as $row) {
            self::assertSame(ReleaseCreationResult::normalizeResultArrayPhp($row), ReleaseCreationResult::normalizeResultArrayNative($row));
        }
        foreach (['totalPhp', 'totalNative', 'total'] as $method) {
            try {
                (new ReleaseCreationResult(PHP_INT_MAX, 1))->$method();
                self::fail('Overflow must retain PHP TypeError.');
            } catch (TypeError $error) {
                self::assertSame(TypeError::class, $error::class);
            }
        }
    }

    public function test_default_dispatch_and_unavailable_ffi_fallback(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse((bool) (new ReflectionClass($class))->getDefaultProperties()['useRustFfi']);
            $class::setUseRustFfi(false);
        }
        foreach ($this->workloads()['common'] as [$php, $native, $dispatch]) {
            self::assertSame($php(), $dispatch());
        }
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, false);
        }
        foreach ($this->workloads() as $cases) {
            foreach ($cases as [$php, $native, $dispatch]) {
                self::assertSame($php(), $native());
                self::assertSame($php(), $dispatch());
            }
        }
        self::assertNull(RustPipelineLibrary::resolveDomain('cleaning', BinariesRunner::FFI_CDEF, '/nonexistent/wave18-library'));
    }

    /** @return array<string, array<string, array{Closure, Closure, Closure}>> */
    private function workloads(): array
    {
        $binaries = new BinariesRunner;
        $backfill = new BackfillRunner;
        $service = $this->releaseService();
        $cases = [];
        foreach (['common' => 3, 'uncommon' => 64] as $kind => $size) {
            $binaries = new BinariesRunner;
            $groups = $this->groupRows($size);
            $perGroup = $binaries->planSafeBinariesCommandsPhp($groups, true, $size * 100, 100);
            $scheduled = $binaries->interleaveScheduledGroupCommandsPhp($perGroup)['scheduledGroups'];
            (new ReflectionProperty(BinariesRunner::class, 'scheduledGroups'))->setValue($binaries, $scheduled);
            $subject = str_repeat('Movie#@§Ö.1080p-', $size * 10);
            $meta = ['id' => '123', 'predb' => -1, 'properlynamed' => true, 'cleansubject' => $subject];
            $names = [];
            $ids = [];
            for ($i = 0; $i < $size; $i++) {
                $names[100 + $i] = ['alt.test.'.$i => true, 'alt.common' => true, 'missing' => true];
                $ids['alt.test.'.$i] = $i;
            }
            $ids['alt.common'] = 1;
            $settings = new ProcessReleasesSettings(completion: $kind === 'common' ? 80 : PHP_INT_MIN, crossPostTime: 2, releaseRetentionDays: 30);
            $result = new ReleaseCreationResult($kind === 'common' ? 12 : PHP_INT_MAX - 4, 4);
            $row = ['added' => (string) $result->added, 'dupes' => $result->dupes, 'unused' => $subject];
            $definitions = [
                'binariesPlan' => [$binaries, 'planSafeBinariesCommands', [$groups, true, $size * 100, 100]],
                'interleave' => [$binaries, 'interleaveScheduledGroupCommands', [$perGroup]],
                'workerKey' => [$binaries, 'workerConcurrencyKey', [$kind === 'common' ? '11:0' : 'missing']],
                'backfillOrder' => [$backfill, 'resolveBackfillOrderSql', [$kind === 'common' ? 1 : PHP_INT_MIN]],
                'backfillDays' => [$backfill, 'resolveBackfillDaysExpression', [2, $kind === 'common' ? '30.125' : '20000.987654321']],
                'backfillQueues' => [$backfill, 'planSafeBackfillQueues', ['alt.test', 1000000, 1, $size * 100, 2, 100]],
                'sanitize' => [$service, 'sanitizeCollectionNames', [$subject, "'''poster'''"]],
                'normalizeMeta' => [$service, 'normalizeCollectionReleaseMeta', [$meta, $subject]],
                'groupIds' => [$service, 'resolveCollectionGroupIds', [$names, $ids]],
                'clampCompletion' => [ProcessReleasesSettings::class, 'clampCompletion', [$kind === 'common' ? 80 : PHP_INT_MAX]],
                'validCompletion' => [$settings, 'hasValidCompletion', []],
                'retention' => [$settings, 'hasRetentionCleanup', []],
                'crossPost' => [$settings, 'hasCrossPostDetection', []],
                'completionCleanup' => [$settings, 'hasCompletionCleanup', []],
                'resultTotal' => [$result, 'total', []],
                'hasAdded' => [$result, 'hasAddedReleases', []],
                'resultArray' => [$result, 'resultArray', []],
                'normalizeResult' => [ReleaseCreationResult::class, 'normalizeResultArray', [$row]],
            ];
            foreach ($definitions as $name => [$target, $method, $args]) {
                $invoke = static function (string $suffix) use ($target, $method, $args): mixed {
                    if ($method === 'workerConcurrencyKey' && $suffix === '') {
                        return (new \ReflectionMethod($target, $method))->invoke($target, ...$args);
                    }

                    return is_string($target) ? $target::{$method.$suffix}(...$args) : $target->{$method.$suffix}(...$args);
                };
                $cases[$kind][$name] = [static fn () => $invoke('Php'), static fn () => $invoke('Native'), static fn () => $invoke('')];
            }
        }

        return $cases;
    }

    public function test_opt_in_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_BINARIES_BACKFILL_RELEASE_CREATION_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_BINARIES_BACKFILL_RELEASE_CREATION_BENCH=1.');
        }
        $artifact = '/tmp/wave18-binaries-backfill-release-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';
        $sourceFiles = ['rust/pipeline-ffi/src/cleaning.rs', 'app/Services/Runners/BinariesRunner.php', 'app/Services/Runners/BackfillRunner.php', 'app/Services/ReleaseCreationService.php', 'app/Support/Data/ProcessReleasesSettings.php', 'app/Support/Data/ReleaseCreationResult.php', 'tests/Unit/RustBinariesBackfillReleaseCreationParityTest.php', 'composer.lock'];
        $sourceHashes = [];
        foreach ($sourceFiles as $path) {
            $sourceHashes[$path] = hash_file('sha256', base_path($path));
        }
        $library = base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report = ['protocol' => ['seed' => 18012026, 'warmup' => 200, 'pairs' => 2000, 'order' => 'paired randomized', 'unit' => 'ns', 'p99TailObservations' => 20, 'uncertainty' => 'Empirical p99 from 2000 calls; scheduler noise precludes a precise confidence claim. Adoption requires all four thresholds.', 'build' => 'cargo build --release', 'correctness' => 'same result before warmup; every timed invocation compared outside timing', 'reproduce' => 'NNTMUX_BINARIES_BACKFILL_RELEASE_CREATION_BENCH=1 php artisan test --compact tests/Unit/RustBinariesBackfillReleaseCreationParityTest.php'], 'runtime' => ['php' => PHP_VERSION, 'os' => php_uname(), 'cpu' => trim((string) shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null')), 'rustc' => trim((string) shell_exec('rustc -V')), 'cargo' => trim((string) shell_exec('cargo -V')), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'librarySha256' => hash_file('sha256', $library), 'sourceSha256' => $sourceHashes, 'opcache' => ini_get('opcache.enable_cli'), 'ffi' => ini_get('ffi.enable'), 'memoryLimit' => ini_get('memory_limit'), 'vendorAutoloadSha256' => hash_file('sha256', base_path('vendor/composer/installed.php'))], 'failures' => [], 'results' => []];
        try {
            foreach ($this->workloads() as $kind => $cases) {
                foreach ($cases as $name => [$php, $native]) {
                    $expected = $php();
                    self::assertSame($expected, $native(), $kind.'/'.$name);
                    for ($i = 0; $i < 200; $i++) {
                        $php();
                        $native();
                    }
                    $raw = [];
                    $latencies = [[], []];
                    for ($i = 0; $i < 2000; $i++) {
                        $first = $this->random->getInt(0, 1);
                        $pair = [];
                        foreach ([$first, 1 - $first] as $index) {
                            $call = $index === 0 ? $php : $native;
                            $start = hrtime(true);
                            $actual = $call();
                            $elapsed = hrtime(true) - $start;
                            self::assertSame($expected, $actual, $kind.'/'.$name.'/'.$i);
                            $latencies[$index][] = $elapsed;
                            $pair[$index === 0 ? 'php' : 'rust'] = $elapsed;
                        }
                        $raw[] = ['first' => $first === 0 ? 'php' : 'rust'] + $pair;
                    }
                    $summary = [];
                    foreach ($latencies as $index => $values) {
                        sort($values);
                        $summary[$index === 0 ? 'php' : 'rust'] = ['mean' => array_sum($values) / count($values), 'p99' => $values[(int) ceil(count($values) * 0.99) - 1]];
                    }
                    $ratios = ['mean' => $summary['php']['mean'] / $summary['rust']['mean'], 'p99' => $summary['php']['p99'] / $summary['rust']['p99']];
                    $report['results'][$kind][$name] = ['summary' => $summary, 'speedup' => $ratios, 'raw' => $raw];
                    fwrite(STDERR, sprintf("\n%s/%s mean %.3fx p99 %.3fx", $kind, $name, $ratios['mean'], $ratios['p99']));
                }
            }
            foreach ($report['results']['common'] as $name => $common) {
                $uncommon = $report['results']['uncommon'][$name];
                $report['eligible'][$name] = min($common['speedup']['mean'], $common['speedup']['p99'], $uncommon['speedup']['mean'], $uncommon['speedup']['p99']) >= 1.5;
            }
        } catch (\Throwable $error) {
            $report['failures'][] = ['class' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            file_put_contents($artifact, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            fwrite(STDERR, "\nWave18 benchmark artifact: ".$artifact."\n");
        }
    }
}
