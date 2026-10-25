<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Binaries\MissedPartHandler;
use App\Services\Binaries\PartHandler;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustBinariesTransactionMissedPartBackfillW42ParityTest extends TestCase
{
    private const CLASSES = [
        HeaderStorageTransaction::class,
        MissedPartHandler::class,
        PartHandler::class,
        BackfillConfig::class,
        BackfillService::class,
    ];

    /** Helper name => owning class. */
    private const HELPERS = [
        'summarizeCleanupPlanBatch' => HeaderStorageTransaction::class,
        'summarizeMissedPartBatch' => MissedPartHandler::class,
        'summarizePartRowBatch' => PartHandler::class,
        'summarizeBackfillSettingsBatch' => BackfillConfig::class,
        'summarizeBackfillChunkPlanBatch' => BackfillService::class,
    ];

    private const RANDOM_CASES = 1_000;

    private const INTS = [
        null, true, false, 0, 1, 2, 3, 5, 10, 16, -1, 100, 101, 499, 500, 501, 1_000, 20_000, 20_001, PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MIN, 1.0, 99.5,
        '', '0', '1', '2', '3', '10', '-1', '-0', '007', '1.0', ' 1', '1 ', '+1', '1e3', '0x1', '999999999999999999', '1234567890123456789', '-', 'abc', [],
    ];

    private const STRINGS = [
        null, 5, 1.5, true, [], '', ' ', " \t\n\r\0\x0B", "\x0C", "\x7F", 'A', '<a@b>', ' <a@b> ', "<a\x01b>", '<a b>', "\t<abc@example.com>\n", '<ä@b>',
        "\xff\xfe", 'update', 'backfill', 'Update', 'BACKFILL', ' update', 'backfill ', '2012-08-14', '2024-02-29', '2023-02-29', '1900-02-29', '2000-02-29',
        '2023-04-31', '2023-13-01', '2023-00-10', '2023-01-00', '0000-01-01', '9999-12-31', '2023-1-01', '2023/01/01', "2023-01-01\n", ' 2023-01-01',
        '２０２３-01-01', '2023-01-01T00:00', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
        .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
        .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW421RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_follow_the_benchmark_and_toggle_independently(): void
    {
        // Only the cleanup plan helper cleared 1.5x on every required workload; see the benchmark below.
        $defaults = [];
        foreach (self::CLASSES as $class) {
            $defaults[$class] = $this->rustDefault($class);
        }
        $this->assertSame([HeaderStorageTransaction::class => true, MissedPartHandler::class => false, PartHandler::class => false,
            BackfillConfig::class => false, BackfillService::class => false], $defaults);
        foreach (self::CLASSES as $class) {
            $default = $this->rustDefault($class);
            $this->assertSame($default, $class::usesW421RustFfi(), $class);
            $class::useW421RustFfi(! $default);
            $this->assertSame(! $default, $class::usesW421RustFfi(), $class);
            foreach ($defaults as $other => $otherDefault) {
                if ($other !== $class) {
                    $this->assertSame($otherDefault, $other::usesW421RustFfi(), $other.' must not follow '.$class);
                }
            }
            $class::useW421RustFfi($default);
            $this->assertSame($default, $class::usesW421RustFfi(), $class);
            $class::useW421RustFfi();
            $this->assertTrue($class::usesW421RustFfi(), $class);
            $class::resetW421RustFfi();
            $this->assertSame([$default, null], [$class::usesW421RustFfi(), $this->ffiSlot($class)], $class);
        }

        // The Wave 42.1 toggles are separate from the earlier helpers' toggles on these classes.
        $earlier = [HeaderStorageTransaction::class => HeaderStorageTransaction::usesRustFfi(), MissedPartHandler::class => MissedPartHandler::usesRustFfi(),
            PartHandler::class => PartHandler::usesRustFfi(), BackfillService::class => BackfillService::usesRustFfi()];
        foreach ($earlier as $class => $before) {
            $class::useW421RustFfi(! $before);
            $this->assertSame($before, $class::usesRustFfi(), $class);
            $class::setUseRustFfi(! $before);
            $this->assertSame(! $before, $class::usesW421RustFfi(), $class);
            $class::setUseRustFfi($before);
            $class::resetW421RustFfi();
            $this->assertSame($before, $class::usesRustFfi(), $class);
        }
        $configEarlier = (new ReflectionMethod(BackfillConfig::class, 'rustEnabled'))->getClosure(null);
        $before = $configEarlier();
        BackfillConfig::useW421RustFfi(! $before);
        $this->assertSame($before, $configEarlier());
        BackfillConfig::useRustFfi(! $before);
        $this->assertSame(! $before, BackfillConfig::usesW421RustFfi());
        BackfillConfig::useRustFfi($before);
        $this->assertSame($before, $configEarlier());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'batches' => 7, 'invalid_rows' => 1, 'bad_counts' => 2, 'inserted' => 1, 'all' => 1, 'hashes' => 1, 'noise' => 1, 'statements' => 19,
        ], HeaderStorageTransaction::summarizeCleanupPlanBatch([
            ['binaries' => 1_000, 'inserted' => 501, 'all' => 900, 'hashes' => 3],
            ['binaries' => '0', 'inserted' => 0, 'all' => '1', 'hashes' => 9],
            ['binaries' => 0, 'inserted' => 0, 'all' => 0, 'hashes' => 2],
            ['binaries' => 0, 'inserted' => 0, 'all' => 0, 'hashes' => 0],
            ['binaries' => -1, 'inserted' => 0, 'all' => 0, 'hashes' => 0],
            ['binaries' => 1.0, 'inserted' => 0, 'all' => 0, 'hashes' => 0],
            'row',
        ]));
        $this->assertSame(PHP_INT_MAX, HeaderStorageTransaction::summarizeCleanupPlanBatch([['binaries' => PHP_INT_MAX, 'inserted' => PHP_INT_MAX,
            'all' => 0, 'hashes' => 0]], 0)['statements']);
        $this->assertSame(3, HeaderStorageTransaction::summarizeCleanupPlanBatch([['binaries' => 0, 'inserted' => 0, 'all' => 0, 'hashes' => 0]])['statements']);

        $this->assertSame([
            'parts' => 7, 'invalid_rows' => 1, 'bad_number' => 1, 'bad_group' => 1, 'bad_attempts' => 2, 'exhausted' => 2, 'retryable' => 2, 'duplicates' => 1,
            'min_number' => 7, 'max_number' => PHP_INT_MAX,
        ], MissedPartHandler::summarizeMissedPartBatch([
            ['numberid' => 100, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => '100', 'groups_id' => '1', 'attempts' => '3'],
            ['numberid' => 100, 'groups_id' => 2, 'attempts' => 5],
            ['numberid' => 0, 'groups_id' => 1, 'attempts' => -1],
            ['numberid' => 7, 'attempts' => 1.0],
            ['numberid' => PHP_INT_MAX, 'groups_id' => 1, 'attempts' => 2],
            null,
        ], 3));
        $this->assertSame([0, 0], array_values(array_slice(MissedPartHandler::summarizeMissedPartBatch([['numberid' => -5, 'groups_id' => 1,
            'attempts' => 0]], 0), 8)));

        $this->assertSame([
            'parts' => 7, 'invalid_rows' => 1, 'bad_binary' => 1, 'bad_part_number' => 1, 'bad_message_id' => 3, 'bad_size' => 2, 'duplicate_keys' => 1,
            'unsorted' => 1, 'total_size' => PHP_INT_MAX,
        ], PartHandler::summarizePartRowBatch([
            ['binaries_id' => 2, 'partnumber' => 1, 'messageid' => '<a@b>', 'size' => 100],
            ['binaries_id' => 1, 'partnumber' => 5, 'messageid' => ' <c@d> ', 'size' => '20'],
            ['binaries_id' => '1', 'partnumber' => '5', 'messageid' => '', 'size' => -1],
            ['binaries_id' => 0, 'partnumber' => 3, 'size' => 1.0],
            ['binaries_id' => 3, 'partnumber' => 1, 'messageid' => "<\x01>", 'size' => PHP_INT_MAX],
            ['binaries_id' => 3, 'partnumber' => 0, 'messageid' => '<e@f>', 'size' => 0],
            7,
        ]));

        $this->assertSame([
            'configs' => 6, 'invalid_rows' => 1, 'bad_date' => 3, 'update_mode' => 1, 'backfill_mode' => 2, 'bad_mode' => 2, 'compressed' => 2, 'disable_group' => 3,
        ], BackfillConfig::summarizeBackfillSettingsBatch([
            ['safeBackFillDate' => '2012-08-14', 'safePartRepair' => 'backfill', 'compressedHeaders' => true, 'disableBackfillGroup' => false],
            ['safeBackFillDate' => '2012-02-30', 'safePartRepair' => 'update', 'compressedHeaders' => '0', 'disableBackfillGroup' => '7'],
            ['safePartRepair' => 'Update', 'disableBackfillGroup' => 1.0],
            ['safeBackFillDate' => '1999-12-31', 'safePartRepair' => 1, 'compressedHeaders' => -3, 'disableBackfillGroup' => 1],
            ['safeBackFillDate' => '2012-08-14 ', 'safePartRepair' => 'backfill', 'compressedHeaders' => 'x', 'disableBackfillGroup' => '1'],
            false,
        ]));

        $big = intdiv(PHP_INT_MAX, 100) + 1;
        $this->assertSame([
            'groups' => 8, 'invalid_rows' => 1, 'bad_bounds' => 2, 'exhausted' => 2, 'runnable' => 3, 'total_chunks' => 5 + 1 + $big, 'total_articles' => PHP_INT_MAX,
            'max_chunks' => $big,
        ], BackfillService::summarizeBackfillChunkPlanBatch([
            ['first_record' => 1_000, 'target' => 500, 'oldest' => 0],
            ['first_record' => '1000', 'target' => '0', 'oldest' => '950'],
            ['first_record' => 1_000, 'target' => 1_000, 'oldest' => 0],
            ['first_record' => 1_000, 'target' => 10, 'oldest' => 1_000],
            ['first_record' => -1, 'target' => 0, 'oldest' => 0],
            ['first_record' => PHP_INT_MAX, 'target' => 0, 'oldest' => 0],
            ['first_record' => 5, 'oldest' => 0],
            'x',
        ], 100));
        $this->assertSame(3, BackfillService::summarizeBackfillChunkPlanBatch([['first_record' => 3, 'target' => 0, 'oldest' => 0]], -5)['total_chunks']);
    }

    public function test_chunk_plan_matches_the_backfill_chunk_walk(): void
    {
        foreach ([[1_000, 500, 0, 100], [1_000, 0, 950, 100], [1_001, 0, 0, 100], [20, 3, 7, 1], [50_000, 1, 0, 20_000]] as [$first, $target, $oldest, $buffer]) {
            $effective = BackfillService::clampDatePostPhp($target, $oldest);
            [$start] = BackfillService::backfillChunkPhp($first, $effective, $buffer);
            for ($steps = 1; $start !== $effective; $steps++) {
                [$start] = BackfillService::backfillChunkPhp($start, $effective, $buffer);
            }
            $summary = BackfillService::summarizeBackfillChunkPlanBatchPhp([['first_record' => $first, 'target' => $target, 'oldest' => $oldest]], $buffer);
            $this->assertSame([$steps, $first - $effective], [$summary['total_chunks'], $summary['total_articles']]);
        }
        foreach ([[0, 0, 0], [5, 0, 0], [0, 5, 0], [0, 0, 5], [3, 3, 3]] as [$inserted, $all, $hashes]) {
            $summary = HeaderStorageTransaction::summarizeCleanupPlanBatchPhp([['binaries' => 0, 'inserted' => $inserted, 'all' => $all, 'hashes' => $hashes]]);
            $this->assertSame(1, $summary[HeaderStorageTransaction::cleanupStrategyPhp($inserted, $all, $hashes)]);
        }
        $header = ['matches' => [2 => 1]];
        foreach (self::STRINGS as $messageId) {
            if (is_string($messageId)) {
                $summary = PartHandler::summarizePartRowBatchPhp([['binaries_id' => 1, 'partnumber' => 1, 'messageid' => $messageId, 'size' => 1]]);
                $this->assertSame(PartHandler::validationErrorPhp($header + ['Message-ID' => $messageId]) !== null, $summary['bad_message_id'] === 1, bin2hex($messageId));
            }
        }
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $ints = count(self::INTS);
        $strings = count(self::STRINGS);

        $plans = [];
        foreach (self::INTS as $i => $binaries) {
            foreach (self::INTS as $j => $inserted) {
                $plans[] = ['binaries' => $binaries, 'inserted' => $inserted, 'all' => self::INTS[($i + $j) % $ints], 'hashes' => self::INTS[($i * 3 + $j) % $ints]];
                $plans[] = ['binaries' => $inserted, 'inserted' => 0, 'all' => $binaries, 'hashes' => self::INTS[($i + $j * 5) % $ints]];
            }
        }
        $plans[] = 'row';
        $plans[] = [];
        foreach ([500, 0, -1, 1, 7, PHP_INT_MAX, PHP_INT_MIN] as $max) {
            $this->assertHelperParity('summarizeCleanupPlanBatch', [$plans, $max]);
        }
        $this->assertHelperParity('summarizeCleanupPlanBatch', [[], 500]);

        $missed = [];
        foreach (self::INTS as $i => $number) {
            foreach (self::INTS as $j => $group) {
                $missed[] = ['numberid' => $number, 'groups_id' => $group, 'attempts' => self::INTS[($i + $j) % $ints]];
            }
        }
        $missed = [...$missed, ...$missed, null, ['numberid' => 5]];
        foreach ([3, 0, -1, 1, PHP_INT_MAX, PHP_INT_MIN] as $tries) {
            $this->assertHelperParity('summarizeMissedPartBatch', [$missed, $tries]);
        }
        $this->assertHelperParity('summarizeMissedPartBatch', [['a' => ['numberid' => 1, 'groups_id' => 2, 'attempts' => 0], 9 => 'z'], 3]);

        $parts = [];
        foreach (self::STRINGS as $i => $messageId) {
            foreach (self::INTS as $j => $binary) {
                $parts[] = ['binaries_id' => $binary, 'partnumber' => self::INTS[($i * 3 + $j) % $ints], 'messageid' => $messageId,
                    'size' => self::INTS[($i + $j * 7) % $ints]];
            }
        }
        $parts = [...$parts, ...array_reverse($parts), 7];
        $this->assertHelperParity('summarizePartRowBatch', [$parts]);
        $this->assertHelperParity('summarizePartRowBatch', [[]]);

        $settings = [];
        foreach (self::STRINGS as $i => $date) {
            foreach (self::STRINGS as $j => $mode) {
                $settings[] = ['safeBackFillDate' => $date, 'safePartRepair' => $mode, 'compressedHeaders' => self::INTS[($i + $j) % $ints],
                    'disableBackfillGroup' => self::INTS[($i * 5 + $j) % $ints]];
            }
        }
        $settings[] = 'x';
        $settings[] = ['compressedHeaders' => self::STRINGS[3]];
        $this->assertHelperParity('summarizeBackfillSettingsBatch', [$settings]);
        $this->assertHelperParity('summarizeBackfillSettingsBatch', [[]]);

        $groups = [];
        foreach (self::INTS as $i => $first) {
            foreach (self::INTS as $j => $target) {
                $groups[] = ['first_record' => $first, 'target' => $target, 'oldest' => self::INTS[($i + $j) % $ints]];
                $groups[] = ['first_record' => $first, 'target' => $target, 'oldest' => self::STRINGS[($i + $j) % $strings]];
            }
        }
        $groups[] = null;
        foreach ([20_000, 0, -1, 1, 7, PHP_INT_MAX, PHP_INT_MIN] as $buffer) {
            $this->assertHelperParity('summarizeBackfillChunkPlanBatch', [$groups, $buffer]);
        }
        $this->assertHelperParity('summarizeBackfillChunkPlanBatch', [[], 20_000]);
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4211));
        $pick = fn (array $pool): mixed => $pool[$random->getInt(0, count($pool) - 1)];
        $bytes = fn (int $max): string => ($length = $random->getInt(0, $max)) === 0 ? '' : $random->getBytes($length);
        $int = fn (): mixed => match ($random->getInt(0, 3)) {
            0 => $pick(self::INTS),
            1 => $random->getInt(-5, 200),
            2 => (string) $random->getInt(-5, 2_000_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $pieces = ['a', 'Z', '0', '9', '12', '-', ' ', "\t", "\n", "\0", "\x0B", "\x7F", '<', '>', '@', '.', "\xff", 'Ä', 'update', 'backfill', '2012', '02', '29'];
        $text = function (int $max) use ($random, $pick, $pieces): string {
            $value = '';
            for ($n = $random->getInt(0, $max); $n > 0; $n--) {
                $value .= $pick($pieces);
            }

            return $value;
        };
        $string = fn (): mixed => match ($random->getInt(0, 5)) {
            0 => $pick(self::STRINGS),
            1 => $bytes(40),
            2 => '<'.bin2hex($bytes(12)).'@news.example.com>',
            3 => str_repeat('x', $random->getInt(250, 260)),
            default => $text(12),
        };
        $date = fn (): string => sprintf('%04d-%02d-%02d', $random->getInt(0, 2_100), $random->getInt(0, 13), $random->getInt(0, 32));
        $rows = function (Closure $row) use ($random): array {
            $list = [];
            for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                $list[] = $random->getInt(0, 19) === 0 ? null : $row();
            }

            return $list;
        };

        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $this->assertHelperParity('summarizeCleanupPlanBatch', [$rows(fn (): array => ['binaries' => $random->getInt(0, 1) === 0 ? $random->getInt(0, 3_000) : $int(),
                'inserted' => $pick([0, 0, 1, '2', 499, 500, 501, $int()]), 'all' => $pick([0, 0, 3, '1000', -1, $int()]), 'hashes' => $pick([0, 1, '7', 2_500, $int()])]),
                $pick([500, 1, 7, 0, -1, PHP_INT_MAX])]);
            $this->assertHelperParity('summarizeMissedPartBatch', [$rows(fn (): array => ['numberid' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 60) : $int(),
                'groups_id' => $pick([1, 2, '1', '2', 0, -1, $int()]), 'attempts' => $random->getInt(0, 1) === 0 ? $random->getInt(-1, 5) : $int()]),
                $pick([3, 0, 1, 5, -1, PHP_INT_MAX])]);
            $this->assertHelperParity('summarizePartRowBatch', [$rows(fn (): array => ['binaries_id' => $random->getInt(0, 1) === 0 ? $random->getInt(-1, 4) : $int(),
                'partnumber' => $random->getInt(0, 1) === 0 ? $random->getInt(-1, 8) : $int(), 'messageid' => $string(),
                'size' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 800_000) : $int()])]);
            $this->assertHelperParity('summarizeBackfillSettingsBatch', [$rows(fn (): array => ['safeBackFillDate' => $random->getInt(0, 1) === 0 ? $date() : $string(),
                'safePartRepair' => $pick(['update', 'backfill', 'Update', '', null, $string()]), 'compressedHeaders' => $pick([true, false, 0, 1, '1', '0', $int()]),
                'disableBackfillGroup' => $pick([true, false, 0, 1, '1', '0', $int()])])]);
            $this->assertHelperParity('summarizeBackfillChunkPlanBatch', [$rows(function () use ($random, $int): array {
                $first = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 9_000_000_000);
                $near = fn (): mixed => is_int($first) && $first > 0 ? $first - $random->getInt(-50, min($first, 500_000)) : $int();

                return ['first_record' => $first, 'target' => $random->getInt(0, 3) === 0 ? $int() : $near(), 'oldest' => $random->getInt(0, 3) === 0 ? $int() : $near()];
            }), $pick([20_000, 1, 7, 0, -1, PHP_INT_MAX])]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[80]');
        FFI::memset($out, ord('Z'), 80);
        $input = pack('q2', 1, 0);
        $length = strlen($input);

        // A short buffer reports the exact size without writing.
        $this->assertSame(-72, $this->callFfi($ffi, 3, 0, $input, $length, $out, 71));
        $this->assertSame(-72, $this->callFfi($ffi, 3, 0, $input, $length, $out, 0));
        $this->assertSame(-64, $this->callFfi($ffi, 4, 0, $input, $length, $out, 63));
        foreach ([[0, 0], [6, 0], [3, 1], [1, 2]] as [$operation, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $flags, $input, $length, $out, 80), "op {$operation} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, null, $length, $out, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, $length, null, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, $length - 1, $out, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 16_777_217, $out, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, pack('q', PHP_INT_MAX), 8, $out, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, pack('q3', 2, 1, 2), 24, $out, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, pack('q2', 1, 0).'x', 17, $out, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q2', 1, 0), 16, $out, 80));
        $this->assertSame(-1, $this->callFfi($ffi, 4, 0, pack('q4', 3, 1, 1, 5), 32, $out, 80));
        $this->assertSame(str_repeat('Z', 80), FFI::string($out, 80));
        $this->assertSame(72, $this->callFfi($ffi, 3, 0, $input, $length, $out, 80));
        $this->assertSame(pack('q9', 0, 0, 0, 0, 0, 0, 0, 0, 0).str_repeat('Z', 8), FFI::string($out, 80));

        // The PHP frame packer produces frames the export accepts.
        $words = [100, 2];
        $blob = [];
        BinariesService::w411PackRow($words, $blob, [1_000, '500', 0]);
        BinariesService::w411PackRow($words, $blob, [1.5, null, 0]);
        $frame = pack('q', count($words)).pack('q*', ...$words).implode('', $blob);
        $this->assertSame(64, $this->callFfi($ffi, 5, 0, $frame, strlen($frame), $out, 80));
        $this->assertSame(pack('q8', 2, 0, 1, 0, 1, 5, 500, 5), FFI::string($out, 64));

        foreach (self::CLASSES as $class) {
            $class::useW421RustFfi(true);
        }
        HeaderStorageTransaction::summarizeCleanupPlanBatch([['binaries' => 1]]);
        MissedPartHandler::summarizeMissedPartBatch([['numberid' => 1]]);
        PartHandler::summarizePartRowBatch([['binaries_id' => 1]]);
        BackfillConfig::summarizeBackfillSettingsBatch([['safePartRepair' => 'update']]);
        BackfillService::summarizeBackfillChunkPlanBatch([['first_record' => 2]]);
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, $this->ffiSlot($class), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_and_oversized_frames_fall_back_to_php(): void
    {
        $cases = [
            'summarizeCleanupPlanBatch' => [[['binaries' => 600, 'inserted' => 0, 'all' => 2, 'hashes' => 1]], 500],
            'summarizeMissedPartBatch' => [[['numberid' => 9, 'groups_id' => 1, 'attempts' => 3]], 3],
            'summarizePartRowBatch' => [[['binaries_id' => 1, 'partnumber' => 2, 'messageid' => '<a@b>', 'size' => 9]]],
            'summarizeBackfillSettingsBatch' => [[['safeBackFillDate' => '2012-08-14', 'safePartRepair' => 'update', 'compressedHeaders' => true]]],
            'summarizeBackfillChunkPlanBatch' => [[['first_record' => 50_000, 'target' => 10, 'oldest' => 5]], 20_000],
        ];
        foreach (self::CLASSES as $class) {
            $class::useW421RustFfi(true);
            $this->setFfiSlot($class, false);
        }
        foreach ($cases as $helper => $args) {
            $class = self::HELPERS[$helper];
            $this->assertNull($class::{$helper.'Native'}(...$args), $helper);
            $this->assertSame($class::{$helper.'Php'}(...$args), $class::$helper(...$args), $helper);
        }

        // Frames past the 16 MiB export limit never reach Rust and fall back to PHP.
        $this->ffiOrSkip();
        foreach (self::CLASSES as $class) {
            $class::resetW421RustFfi();
            $class::useW421RustFfi(true);
        }
        $huge = [['binaries_id' => 1, 'partnumber' => 1, 'messageid' => str_repeat('a', 16_777_216), 'size' => 1]];
        $this->assertNull(PartHandler::summarizePartRowBatchNative($huge));
        $this->assertSame(PartHandler::summarizePartRowBatchPhp($huge), PartHandler::summarizePartRowBatch($huge));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // frame packing, the FFI call and decoding (release dylib, 5 warmups, 201 samples x 10
        // iterations). Common = 500 cleanup plans, a 15,000-row repair queue, a 500-part flush, 200
        // settings rows and 500 group plans; uncommon = 5,000 string-valued cleanup plans, 20,000
        // queue rows with string ids and duplicates, 10,000 parts with long or padded message ids,
        // 5,000 settings rows with malformed dates and 10,000 group plans with string bounds.
        // Speedup = PHP latency / Rust latency per workload pass, common mean/p99 then uncommon mean/p99
        // (PHP 8.5 NTS, Apple Silicon, release dylib, 3 rounds):
        // - Round 1: cleanup 1.89x/1.64x, 2.13x/1.76x; missed 1.18x/1.14x, 1.82x/1.59x; parts 1.44x/1.47x, 1.54x/1.40x;
        //   settings 1.34x/1.26x, 1.98x/1.82x; chunks 1.14x/0.98x, 1.64x/1.38x
        // - Round 2: cleanup 1.88x/1.58x, 2.13x/1.92x; missed 1.16x/1.19x, 1.88x/1.69x; parts 1.44x/1.41x, 1.59x/1.41x;
        //   settings 1.32x/1.34x, 1.98x/1.97x; chunks 1.19x/1.18x, 1.70x/1.58x
        // - Round 3: cleanup 1.94x/1.82x, 2.28x/2.24x; missed 1.17x/1.15x, 1.94x/1.62x; parts 1.41x/1.47x, 1.61x/1.74x;
        //   settings 1.29x/1.14x, 1.97x/1.77x; chunks 1.14x/1.11x, 1.73x/1.84x
        // Only HeaderStorageTransaction's cleanup plan summary clears the 1.5x bar in .ai/rules/rust-performance.md on
        // every workload in every round, so it alone defaults to Rust. The other four miss on the common batch: a few
        // int checks per row (plus one trim/PCRE match for parts and checkdate for settings) is too little PHP work to
        // repay frame packing and the FFI round trip, so their W421_RUST_FFI_DEFAULT stays false.
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustBinariesTransactionMissedPartBackfillW42ParityTest.php --filter benchmark
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4212));
        $workloads = ['common' => $this->commonWorkload($random), 'uncommon' => $this->uncommonWorkload($random)];
        $warmup = 5;
        $samples = 201;
        $iterations = 10;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => $class) {
            foreach ($workloads as $name => $workload) {
                $args = $workload[$helper];
                $this->assertHelperParity($helper, $args);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $class::useW421RustFfi($useRust);
                        $sink += count($class::$helper(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                        $class::useW421RustFfi($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$helper(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::resetW421RustFfi();
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustBinariesTransactionMissedPartBackfillW42 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => $class) {
            $speedup = $speedups[$helper];
            if (min($speedup['common']['mean'], $speedup['common']['p99'], $speedup['uncommon']['mean'], $speedup['uncommon']['p99']) < 1.5) {
                $this->assertFalse($this->rustDefault($class), $class.' must keep PHP below 1.5x: '.$summary);
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
        $expected = $class::{$helper.'Php'}(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $this->assertSame($expected, $class::{$helper.'Native'}(...$args), $context);
        foreach ([true, false] as $useRust) {
            $class::useW421RustFfi($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::resetW421RustFfi();
    }

    /** Reads a class's Wave 42.1 default at runtime so the toggle checks also hold if a later benchmark flips it. */
    private function rustDefault(string $class): bool
    {
        $value = constant($class.'::W421_RUST_FFI_DEFAULT');
        $this->assertIsBool($value, $class);

        return $value;
    }

    private function ffiSlot(string $class): mixed
    {
        if ($class === BackfillConfig::class) {
            return $this->configSlot()();
        }

        return (new ReflectionProperty($class, 'w421Ffi'))->getValue();
    }

    private function setFfiSlot(string $class, FFI|false|null $value): void
    {
        if ($class === BackfillConfig::class) {
            $slot = &$this->configSlot()();
            $slot = $value;

            return;
        }
        (new ReflectionProperty($class, 'w421Ffi'))->setValue(null, $value);
    }

    private function configSlot(): Closure
    {
        // BackfillConfig is a readonly class, so its Wave 42.1 library handle lives in a static local returned by reference.
        return $this->closures['config'] ??= (new ReflectionMethod(BackfillConfig::class, 'w421FfiSlot'))->getClosure(null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonWorkload(Randomizer $random): array
    {
        $plans = [];
        for ($i = 0; $i < 500; $i++) {
            $plans[] = ['binaries' => 200 + $i % 400, 'inserted' => [120, 0, 0, 0][$i % 4], 'all' => [120, 80, 0, 0][$i % 4], 'hashes' => [40, 40, 30, 0][$i % 4]];
        }
        $missed = [];
        for ($i = 0; $i < 15_000; $i++) {
            $missed[] = ['id' => $i + 1, 'numberid' => 4_000_000_000 + $i * 3, 'groups_id' => 1 + $i % 4, 'attempts' => $i % 4];
        }
        $parts = [];
        for ($i = 0; $i < 500; $i++) {
            $parts[] = ['binaries_id' => 10_000 + intdiv($i, 50), 'number' => 4_000_000_000 + $i, 'messageid' => '<'.bin2hex($random->getBytes(8)).'@example.com>',
                'partnumber' => 1 + $i % 50, 'size' => 750_000];
        }
        $settings = [];
        for ($i = 0; $i < 200; $i++) {
            $settings[] = ['compressedHeaders' => true, 'echoCli' => false, 'safeBackFillDate' => sprintf('20%02d-%02d-%02d', 10 + $i % 15, 1 + $i % 12, 1 + $i % 28),
                'safePartRepair' => ['backfill', 'update'][$i % 2], 'disableBackfillGroup' => $i % 3 === 0];
        }
        $groups = [];
        for ($i = 0; $i < 500; $i++) {
            $first = 4_000_000_000 + $i * 1_000_000;
            $groups[] = ['id' => $i + 1, 'name' => 'alt.binaries.group'.$i, 'first_record' => $first, 'target' => $first - [100_000, 2_000_000, -5, 0][$i % 4],
                'oldest' => 3_000_000_000];
        }

        return [
            'summarizeCleanupPlanBatch' => [$plans, 500],
            'summarizeMissedPartBatch' => [$missed, 3],
            'summarizePartRowBatch' => [$parts],
            'summarizeBackfillSettingsBatch' => [$settings],
            'summarizeBackfillChunkPlanBatch' => [$groups, 20_000],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function uncommonWorkload(Randomizer $random): array
    {
        $plans = [];
        for ($i = 0; $i < 5_000; $i++) {
            $plans[] = $i % 500 === 499 ? 'row' : ['binaries' => (string) ($i * 7), 'inserted' => (string) [0, 3, -1, 0][$i % 4], 'all' => (string) ($i % 900),
                'hashes' => ['x', '5', null, '0'][$i % 4]];
        }
        $missed = [];
        for ($i = 0; $i < 20_000; $i++) {
            $missed[] = $i % 1_000 === 999 ? null : ['numberid' => (string) (9_000_000_000 - $i % 12_000), 'groups_id' => (string) (1 + $i % 3),
                'attempts' => (string) ($i % 6 - 1)];
        }
        $parts = [];
        for ($i = 0; $i < 10_000; $i++) {
            $parts[] = ['binaries_id' => (string) (1 + $random->getInt(0, 400)), 'partnumber' => (string) (1 + $i % 300),
                'messageid' => $i % 25 === 0 ? str_repeat('m', 260) : " \t<".bin2hex($random->getBytes(40)).'@news.example.net> ', 'size' => (string) $random->getInt(-5, 900_000)];
        }
        $settings = [];
        for ($i = 0; $i < 5_000; $i++) {
            $settings[] = $i % 700 === 0 ? 'x' : ['safeBackFillDate' => sprintf('%04d-%02d-%02d', $random->getInt(0, 2_100), $random->getInt(0, 13), $random->getInt(0, 32)),
                'safePartRepair' => ['backfill', 'Update', 'update', ''][$i % 4], 'compressedHeaders' => (string) ($i % 2), 'disableBackfillGroup' => ['0', '1', 'x', null][$i % 4]];
        }
        $groups = [];
        for ($i = 0; $i < 10_000; $i++) {
            $first = $random->getInt(0, 9_000_000_000);
            $groups[] = $i % 1_000 === 999 ? null : ['first_record' => [(string) $first, $first, 'x', (string) $first][$i % 4],
                'target' => (string) max(0, $first - $random->getInt(-50, 3_000_000)), 'oldest' => (string) max(0, $first - $random->getInt(0, 5_000_000))];
        }

        return [
            'summarizeCleanupPlanBatch' => [$plans, 500],
            'summarizeMissedPartBatch' => [$missed, 3],
            'summarizePartRowBatch' => [$parts],
            'summarizeBackfillSettingsBatch' => [$settings],
            'summarizeBackfillChunkPlanBatch' => [$groups, 20_000],
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
        return $ffi->nntmux_w421_binaries_transaction_missed_part_backfill(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w421', HeaderStorageTransaction::W421_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 42.1 binaries transaction/missed part/backfill export is not available.');
        }

        return $ffi;
    }
}
