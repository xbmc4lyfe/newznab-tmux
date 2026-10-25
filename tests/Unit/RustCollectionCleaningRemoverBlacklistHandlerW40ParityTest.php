<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\CollectionHandler;
use App\Services\BlacklistService;
use App\Services\CollectionCleanupService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseRemoverService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustCollectionCleaningRemoverBlacklistHandlerW40ParityTest extends TestCase
{
    private const CLASSES = [
        CollectionCleanupService::class,
        ReleaseCleaningService::class,
        ReleaseRemoverService::class,
        BlacklistService::class,
        CollectionHandler::class,
    ];

    /** Helper name => owning class. */
    private const HELPERS = [
        'summarizeCleanupRetentionBatch' => CollectionCleanupService::class,
        'summarizeCleanerPassTelemetry' => ReleaseCleaningService::class,
        'summarizeRemovalCandidateBatch' => ReleaseRemoverService::class,
        'summarizeBlacklistEvaluationWindow' => BlacklistService::class,
        'summarizeCollectionUpsertBatch' => CollectionHandler::class,
    ];

    private const RANDOM_CASES = 1_200;

    private const NOW = 1_792_931_696;

    private const INTS = [
        null, true, false, 0, 1, 2, 3, 5, 10, 15, 16, -1, 100, 101, 1_048_575, 1_048_576, PHP_INT_MAX, PHP_INT_MIN, 1.0, 99.5, '', '0', '1', '2', '3', '10', '-1',
        '-0', '007', '1.0', ' 1', '1 ', '+1', '1e3', '0x1', '999999999999999999', '1234567890123456789', '-', 'abc', [],
    ];

    private const DATES = [
        null, 0, 5, -1, self::NOW, self::NOW - 3_600, self::NOW - 172_800, self::NOW - 172_799, self::NOW + 60, PHP_INT_MAX, 1.5, '', '1970-01-01 00:00:00',
        '1969-12-31', '0000-00-00 00:00:00', '2026-10-25 12:34:56', '2026-10-23 12:34:56', '2026-10-23 12:34:57', '2024-02-31', '2026-13-01', '2026-10-32',
        '2026-10-25T12:34:56', '2026-10-25 24:00:00', '2026/10/25', '2026-1-251', '2026-10-25 12:34', '9999-12-31 23:59:59', true,
    ];

    private const STRINGS = [
        null, 5, 1.5, true, [], '', ' ', " \t\n\r\0\x0B", "\x0C", 'A', 'a', 'Same', 'sAME', 'Release.Name.1080p', '[1/2] - "A.rar" yEnc', 'abcdefghij1234',
        'abcdefghij12345', 'abcdefghij12345 ', "abcdefghij12345\n", 'x.abcdefghijklmnopqrstuvwxy.z', 'x.abcdefghijklmnopqrstuvwx.z', '0123456789abcdef0123456789',
        'ÄÄÄÄÄÄÄÄÄÄÄÄÄÄÄÄ', "\xff\xfe", 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW401RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_follow_the_benchmark_and_toggle_independently(): void
    {
        // Only the cleanup retention summary cleared 1.5x on every required workload; see the benchmark below.
        $defaults = [];
        foreach (self::CLASSES as $class) {
            $defaults[$class] = $class::W401_RUST_FFI_DEFAULT;
        }
        $this->assertSame([CollectionCleanupService::class => true, ReleaseCleaningService::class => false, ReleaseRemoverService::class => false,
            BlacklistService::class => false, CollectionHandler::class => false], $defaults);
        foreach (self::CLASSES as $class) {
            $default = $defaults[$class];
            $this->assertSame($default, $class::usesW401RustFfi(), $class);
            $class::useW401RustFfi(! $default);
            $this->assertSame(! $default, $class::usesW401RustFfi(), $class);
            foreach ($defaults as $other => $otherDefault) {
                if ($other !== $class) {
                    $this->assertSame($otherDefault, $other::usesW401RustFfi(), $other.' must not follow '.$class);
                }
            }
            $class::useW401RustFfi($default);
            $this->assertSame($default, $class::usesW401RustFfi(), $class);
            $class::useW401RustFfi(! $default);
            $class::resetW401RustFfi();
            $this->assertSame([$default, null], [$class::usesW401RustFfi(), (new ReflectionProperty($class, 'w401Ffi'))->getValue()], $class);
        }

        // The Wave 40.1 toggles are separate from the earlier helpers' toggles on these classes.
        foreach ([CollectionCleanupService::class, CollectionHandler::class] as $class) {
            $class::useW401RustFfi(true);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $class::useW401RustFfi(false);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::setUseRustFfi(false);
        }
        $remover = new ReflectionProperty(ReleaseRemoverService::class, 'useRustFfi');
        ReleaseRemoverService::useW401RustFfi(true);
        $this->assertFalse($remover->getValue());
        ReleaseRemoverService::useRustFfi(true);
        ReleaseRemoverService::useW401RustFfi(false);
        $this->assertTrue($remover->getValue());
        ReleaseRemoverService::useRustFfi(false);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'rows' => 7, 'invalid_rows' => 1, 'expired' => 1, 'ready_protected' => 2, 'active_window' => 1, 'invalid_timestamp' => 2,
        ], CollectionCleanupService::summarizeCleanupRetentionBatch([
            ['dateadded' => self::NOW - 3_600, 'filecheck' => 5],
            ['dateadded' => self::NOW - 48 * 3_600, 'filecheck' => '5'],
            ['dateadded' => '2020-01-01 00:00:00', 'filecheck' => '3'],
            ['dateadded' => '2020-01-01', 'filecheck' => 10],
            ['dateadded' => 'bogus', 'filecheck' => 5],
            'row',
            ['filecheck' => 0],
        ], self::NOW));
        // A non-positive retention keeps nothing in the active window; a negative clock reads as the epoch.
        $this->assertSame(1, CollectionCleanupService::summarizeCleanupRetentionBatch([['dateadded' => 0, 'filecheck' => 3.0]], -5, 0)['expired']);
        $this->assertSame(1, CollectionCleanupService::summarizeCleanupRetentionBatch([['dateadded' => 0]], self::NOW, PHP_INT_MAX)['active_window']);

        $this->assertSame([
            'pairs' => 6, 'invalid_pairs' => 2, 'changed' => 3, 'unchanged' => 1, 'empty_output' => 1, 'bytes_stripped' => 19,
        ], ReleaseCleaningService::summarizeCleanerPassTelemetry([
            ['before' => '[1/2] - "A.rar" yEnc', 'after' => 'A'],
            ['before' => 'Same', 'after' => 'Same'],
            ['before' => 'x', 'after' => " \t\0"],
            ['before' => '', 'after' => 'grow'],
            ['before' => 'x'],
            5,
        ]));

        $this->assertSame([
            'candidates' => 5, 'invalid_rows' => 1, 'undersized' => 1, 'gibberish' => 1, 'passworded' => 2, 'eligible' => 3,
        ], ReleaseRemoverService::summarizeRemovalCandidateBatch([
            ['size' => 10, 'searchname' => 'Release.Name', 'passwordstatus' => 0],
            ['size' => '2000000', 'searchname' => 'abcdefghijklmnopqrstuvwxyz', 'passwordstatus' => '1'],
            ['size' => 1.0, 'passwordstatus' => 2],
            ['size' => 2_000_000, 'searchname' => 'Fine.Name-GRP', 'passwordstatus' => -1],
            null,
        ]));
        $this->assertSame(0, ReleaseRemoverService::summarizeRemovalCandidateBatch([['searchname' => "abcdefghij12345\n"]])['gibberish']);
        $this->assertSame(1, ReleaseRemoverService::summarizeRemovalCandidateBatch([['searchname' => 'x.abcdefghijklmnopqrstuvwxy.z']])['gibberish']);

        $this->assertSame([
            'checked' => 8, 'invalid_rows' => 1, 'whitelisted' => 2, 'blacklisted' => 3, 'skipped_disabled' => 1, 'unmatched' => 1, 'distinct_rules' => 2,
        ], BlacklistService::summarizeBlacklistEvaluationWindow([
            ['status' => 1, 'optype' => 1, 'rule_id' => 7],
            ['status' => '1', 'optype' => '1', 'rule_id' => '7'],
            ['status' => 1, 'optype' => 2, 'rule_id' => 9],
            ['status' => 1, 'optype' => 2, 'rule_id' => 101],
            ['status' => 0, 'optype' => 1, 'rule_id' => 3],
            ['status' => 1, 'optype' => 3, 'rule_id' => 4],
            ['status' => 1, 'optype' => 1, 'rule_id' => 0],
            'x',
        ], 100));

        $this->assertSame([
            'collections' => 6, 'invalid_rows' => 1, 'valid' => 1, 'oversized_parts' => 1, 'missing_subject' => 2, 'total_expected_parts' => 116,
        ], CollectionHandler::summarizeCollectionUpsertBatch([
            ['subject' => 'Subject', 'totalfiles' => 10],
            ['subject' => " \n", 'totalfiles' => 5],
            ['subject' => 'Big', 'totalfiles' => '101'],
            ['totalfiles' => 0],
            ['subject' => 'Zero', 'totalfiles' => 'x'],
            'x',
        ], 100));
        $this->assertSame(PHP_INT_MAX, CollectionHandler::summarizeCollectionUpsertBatch([['subject' => 'a', 'totalfiles' => PHP_INT_MAX],
            ['subject' => 'b', 'totalfiles' => 1]], PHP_INT_MAX)['total_expected_parts']);
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $rows = [];
        foreach (self::DATES as $i => $date) {
            foreach ([0, 7, 13, 21] as $shift) {
                $rows[] = ['dateadded' => $date, 'filecheck' => self::INTS[($i + $shift) % count(self::INTS)]];
            }
        }
        $rows[] = 'x';
        $rows[] = [];
        foreach ([[self::NOW, 48], [self::NOW, 0], [self::NOW, -5], [-1, 48], [0, 1], [PHP_INT_MAX, 1], [self::NOW, intdiv(PHP_INT_MAX, 3_600)],
            [self::NOW, intdiv(PHP_INT_MAX, 3_600) + 1], [self::NOW, PHP_INT_MAX]] as [$now, $hours]) {
            $this->assertHelperParity('summarizeCleanupRetentionBatch', [$rows, $now, $hours]);
        }
        $this->assertHelperParity('summarizeCleanupRetentionBatch', [[], self::NOW, 48]);

        $pairs = [];
        foreach (self::STRINGS as $before) {
            foreach (self::STRINGS as $after) {
                $pairs[] = ['before' => $before, 'after' => $after];
            }
        }
        $pairs[] = null;
        $pairs[] = ['after' => 'x'];
        $this->assertHelperParity('summarizeCleanerPassTelemetry', [$pairs]);
        $this->assertHelperParity('summarizeCleanerPassTelemetry', [[]]);
        $this->assertHelperParity('summarizeCleanerPassTelemetry', [['a' => ['before' => 'x', 'after' => 'y'], 9 => 'z']]);

        $candidates = [];
        foreach (self::STRINGS as $i => $name) {
            foreach (self::INTS as $j => $size) {
                $candidates[] = ['size' => $size, 'searchname' => $name, 'passwordstatus' => self::INTS[($i * 3 + $j) % count(self::INTS)]];
            }
        }
        $candidates[] = 7;
        foreach ([1_048_576, 0, -1, 1, PHP_INT_MAX, PHP_INT_MIN] as $min) {
            $this->assertHelperParity('summarizeRemovalCandidateBatch', [$candidates, $min]);
        }

        $outcomes = [];
        foreach (self::INTS as $i => $status) {
            foreach (self::INTS as $j => $optype) {
                $outcomes[] = ['status' => $status, 'optype' => $optype, 'rule_id' => self::INTS[($i + $j) % count(self::INTS)]];
            }
        }
        $outcomes[] = false;
        foreach ([1_000_000, 0, -1, 1, 3, PHP_INT_MAX] as $max) {
            $this->assertHelperParity('summarizeBlacklistEvaluationWindow', [$outcomes, $max]);
        }

        $collections = [];
        foreach (self::STRINGS as $subject) {
            foreach (self::INTS as $parts) {
                $collections[] = ['subject' => $subject, 'totalfiles' => $parts];
            }
        }
        $collections[] = 'row';
        foreach ([50_000, 0, -1, 1, 100, PHP_INT_MAX, PHP_INT_MIN] as $max) {
            $this->assertHelperParity('summarizeCollectionUpsertBatch', [$collections, $max]);
        }
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4011));
        $pick = fn (array $pool): mixed => $pool[$random->getInt(0, count($pool) - 1)];
        $bytes = fn (int $max): string => ($length = $random->getInt(0, $max)) === 0 ? '' : $random->getBytes($length);
        $int = fn (): mixed => match ($random->getInt(0, 3)) {
            0 => $pick(self::INTS),
            1 => $random->getInt(-5, 200),
            2 => (string) $random->getInt(-5, 2_000_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $pieces = ['a', 'Z', '0', '9', 'abcde', '12345', 'QWERTYUIOP', ' ', "\t", "\n", "\0", "\x0B", '.', '-', '_', '"', "\xff", 'Ä', '[1/2]', 'yEnc'];
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
            2 => bin2hex($bytes(20)),
            3 => base64_encode($bytes(24)),
            default => $text(10),
        };
        $date = fn (): mixed => match ($random->getInt(0, 4)) {
            0 => $pick(self::DATES),
            1 => self::NOW - $random->getInt(-10_000, 400_000),
            2 => gmdate('Y-m-d H:i:s', self::NOW - $random->getInt(-10_000, 400_000)),
            3 => gmdate('Y-m-d', $random->getInt(0, 4_102_444_800)),
            default => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $random->getInt(1960, 2100), $random->getInt(0, 13), $random->getInt(0, 32), $random->getInt(0, 25),
                $random->getInt(0, 61), $random->getInt(0, 61)),
        };
        $rows = function (Closure $row) use ($random): array {
            $list = [];
            for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                $list[] = $random->getInt(0, 19) === 0 ? null : $row();
            }

            return $list;
        };

        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $this->assertHelperParity('summarizeCleanupRetentionBatch', [$rows(fn (): array => ['dateadded' => $date(), 'filecheck' => $random->getInt(0, 1) === 0
                ? $pick([0, 1, 2, 3, 4, 5, 10, 15, 16, '3', '10', '16', null]) : $int()]), self::NOW + $random->getInt(-5, 5), $pick([48, 24, 1, 0, -1, PHP_INT_MAX])]);
            $this->assertHelperParity('summarizeCleanerPassTelemetry', [$rows(function () use ($random, $string, $text): array {
                $before = $string();

                return ['before' => $before, 'after' => match ($random->getInt(0, 3)) {
                    0 => $before,
                    1 => is_string($before) ? trim(substr($before, 0, $random->getInt(0, strlen($before)))) : $before,
                    2 => $text(3),
                    default => $string(),
                }];
            })]);
            $this->assertHelperParity('summarizeRemovalCandidateBatch', [$rows(fn (): array => ['size' => $random->getInt(0, 1) === 0 ? $random->getInt(0, 4_000_000) : $int(),
                'searchname' => $string(), 'passwordstatus' => $pick([-1, '-1', 0, 1, 2, null, '1', '2', 1.0])]), $pick([1_048_576, 0, 1, -1, PHP_INT_MAX])]);
            $this->assertHelperParity('summarizeBlacklistEvaluationWindow', [$rows(fn (): array => ['status' => $pick([1, 1, 1, '1', 0, '0', 2, null]),
                'optype' => $pick([1, 2, '1', '2', 3, 0, null]), 'rule_id' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 40) : $int()]), $pick([1_000_000, 20, 1, 0, -1])]);
            $this->assertHelperParity('summarizeCollectionUpsertBatch', [$rows(fn (): array => ['subject' => $string(), 'totalfiles' => $random->getInt(0, 1) === 0
                ? $random->getInt(-2, 60_000) : $int()]), $pick([50_000, 100, 0, -1, PHP_INT_MAX])]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[64]');
        FFI::memset($out, ord('Z'), 64);
        $input = pack('q2', 1, 0);
        $length = strlen($input);

        // A short buffer reports the exact size without writing.
        $this->assertSame(-48, $this->callFfi($ffi, 2, 0, $input, $length, $out, 47));
        $this->assertSame(-48, $this->callFfi($ffi, 2, 0, $input, $length, $out, 0));
        $this->assertSame(-56, $this->callFfi($ffi, 4, 0, pack('q3', 2, 5, 0), 24, $out, 55));
        foreach ([[0, 0], [6, 0], [2, 1], [1, 2]] as [$operation, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $flags, $input, $length, $out, 64), "op {$operation} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, null, $length, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, $input, $length, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, $input, $length - 1, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, $input, 16_777_217, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q', PHP_INT_MAX), 8, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q3', 2, 1, 2), 24, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q2', 1, 0).'x', 17, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 1, 0, pack('q2', 1, 0), 16, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q5', 4, 1, 1, 5, -1), 40, $out, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));
        $this->assertSame(48, $this->callFfi($ffi, 2, 0, $input, $length, $out, 64));
        $this->assertSame(pack('q6', 0, 0, 0, 0, 0, 0).str_repeat('Z', 16), FFI::string($out, 64));

        // The PHP frame packer produces frames the export accepts.
        $words = [10, 2];
        $blob = [];
        CollectionCleanupService::w401PackRow($words, $blob, [1, 1, 7]);
        CollectionCleanupService::w401PackRow($words, $blob, ['1', '2', 3.5]);
        $frame = pack('q', count($words)).pack('q*', ...$words).implode('', $blob);
        $this->assertSame(56, $this->callFfi($ffi, 4, 0, $frame, strlen($frame), $out, 64));
        $this->assertSame(pack('q7', 2, 0, 1, 1, 0, 0, 1), FFI::string($out, 56));

        foreach (self::CLASSES as $class) {
            $class::useW401RustFfi(true);
        }
        CollectionCleanupService::summarizeCleanupRetentionBatch([['dateadded' => 1]], self::NOW);
        ReleaseCleaningService::summarizeCleanerPassTelemetry([['before' => 'a', 'after' => 'b']]);
        ReleaseRemoverService::summarizeRemovalCandidateBatch([['size' => 1]]);
        BlacklistService::summarizeBlacklistEvaluationWindow([['status' => 1]]);
        CollectionHandler::summarizeCollectionUpsertBatch([['subject' => 'a']]);
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w401Ffi'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $cases = [
            'summarizeCleanupRetentionBatch' => [[['dateadded' => 1, 'filecheck' => 3]], self::NOW, 48],
            'summarizeCleanerPassTelemetry' => [[['before' => 'ab', 'after' => 'a']]],
            'summarizeRemovalCandidateBatch' => [[['size' => 5, 'searchname' => 'abcdefghij12345', 'passwordstatus' => 1]], 1_048_576],
            'summarizeBlacklistEvaluationWindow' => [[['status' => 1, 'optype' => 1, 'rule_id' => 4]], 1_000_000],
            'summarizeCollectionUpsertBatch' => [[['subject' => 'a', 'totalfiles' => 3]], 50_000],
        ];
        foreach (self::CLASSES as $class) {
            $class::useW401RustFfi(true);
            (new ReflectionProperty($class, 'w401Ffi'))->setValue(null, false);
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
        // frame packing, the FFI call and decoding (release dylib, 5 warmups, 201 samples x 10
        // iterations). Common = a 1,000-row cleanup page, 1,000 subject-cleaning pairs, a 500-row
        // removal page, 2,000 blacklist outcomes and a 500-collection upsert batch; uncommon = 10,000
        // cleanup rows with string fields and bad dates, 5,000 long subject pairs, 5,000 candidates
        // with long hashed names, 20,000 outcomes over many rules and 5,000 collections with 255-byte
        // subjects and string part counts.
        // Speedup = PHP latency / Rust latency per workload pass, common mean/p99 then uncommon mean/p99
        // (PHP 8.5 NTS, Apple Silicon, release dylib, 3 rounds):
        // - Round 1: retention 3.69x/3.39x, 2.08x/1.95x; telemetry 0.61x/0.63x, 0.46x/0.47x; removal 1.03x/1.02x, 1.57x/1.56x;
        //   blacklist 0.60x/0.57x, 1.20x/1.16x; upsert 0.73x/0.71x, 0.88x/0.81x
        // - Round 2: retention 3.65x/3.58x, 2.09x/2.05x; telemetry 0.60x/0.78x, 0.42x/0.42x; removal 1.04x/1.06x, 1.56x/1.60x;
        //   blacklist 0.69x/0.71x, 1.20x/1.14x; upsert 0.76x/0.77x, 0.92x/0.89x
        // - Round 3: retention 3.63x/3.54x, 2.06x/1.78x; telemetry 0.58x/0.57x, 0.43x/0.51x; removal 1.00x/1.00x, 1.54x/1.55x;
        //   blacklist 0.62x/0.64x, 1.18x/1.17x; upsert 0.72x/0.71x, 0.91x/0.87x
        // Only the cleanup retention summary clears the 1.5x bar in .ai/rules/rust-performance.md on every
        // workload (PHP's per-row date parsing dominates it), so CollectionCleanupService::W401_RUST_FFI_DEFAULT
        // is true. The other four do too little per-row work (strlen/trim, one PCRE match, an int switch) to
        // repay frame packing and the FFI round trip on their common workloads, so they keep PHP as the default.
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustCollectionCleaningRemoverBlacklistHandlerW40ParityTest.php --filter benchmark
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4012));
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
                        $class::useW401RustFfi($useRust);
                        $sink += count($class::$helper(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                        $class::useW401RustFfi($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$helper(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::useW401RustFfi(null);
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustCollectionCleaningRemoverBlacklistHandlerW40 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => $class) {
            $speedup = $speedups[$helper];
            if (min($speedup['common']['mean'], $speedup['common']['p99'], $speedup['uncommon']['mean'], $speedup['uncommon']['p99']) < 1.5) {
                $this->assertFalse($class::W401_RUST_FFI_DEFAULT, $class.' must keep PHP below 1.5x: '.$summary);
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
        $expected = $this->helper($class, $helper.'Php')(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $this->assertSame($expected, $this->helper($class, $helper.'Native')(...$args), $context);
        foreach ([true, false] as $useRust) {
            $class::useW401RustFfi($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::useW401RustFfi(null);
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
        $rows = [];
        for ($i = 0; $i < 1_000; $i++) {
            $rows[] = ['id' => 50_000 + $i, 'dateadded' => gmdate('Y-m-d H:i:s', self::NOW - $i * 400), 'filecheck' => [0, 1, 2, 3, 4, 5, 15, 16][$i % 8]];
        }
        $pairs = [];
        for ($i = 0; $i < 1_000; $i++) {
            $file = 'Some.Show.S01E'.($i % 30).'.1080p.WEB.h264-GRP';
            $before = '[01/'.($i % 50 + 1).'] - "'.$file.'.mkv" yEnc';
            $pairs[] = ['before' => $before, 'after' => match ($i % 5) {
                0 => $before,
                1 => '',
                default => $file,
            }];
        }
        $candidates = [];
        for ($i = 0; $i < 500; $i++) {
            $candidates[] = ['id' => $i, 'guid' => bin2hex($random->getBytes(20)), 'size' => $random->getInt(1, 5_000_000_000), 'passwordstatus' => [-1, 0, 0, 1][$i % 4],
                'searchname' => $i % 7 === 0 ? bin2hex($random->getBytes(16)) : 'Some.Movie.'.(1990 + $i % 30).'.1080p.BluRay.x264-GRP'];
        }
        $outcomes = [];
        for ($i = 0; $i < 2_000; $i++) {
            $outcomes[] = ['status' => $i % 11 === 0 ? 0 : 1, 'optype' => $i % 5 === 0 ? 2 : 1, 'rule_id' => 1 + $i % 40];
        }
        $collections = [];
        for ($i = 0; $i < 500; $i++) {
            $collections[] = ['subject' => 'Some.Show.S01E'.($i % 30).'.1080p.WEB.h264-GRP - "file'.$i.'.part01.rar" yEnc', 'fromname' => 'poster@example.com',
                'date' => self::NOW - $i, 'xref' => 'alt.binaries.teevee:'.$i, 'groups_id' => 7, 'totalfiles' => [50, 100, 0, 75_000][$i % 4],
                'collectionhash' => sha1((string) $i, true), 'collection_regexes_id' => 0, 'dateadded' => gmdate('Y-m-d H:i:s', self::NOW)];
        }

        return [
            'summarizeCleanupRetentionBatch' => [$rows, self::NOW, 48],
            'summarizeCleanerPassTelemetry' => [$pairs],
            'summarizeRemovalCandidateBatch' => [$candidates, 1_048_576],
            'summarizeBlacklistEvaluationWindow' => [$outcomes, 1_000_000],
            'summarizeCollectionUpsertBatch' => [$collections, 50_000],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function uncommonWorkload(Randomizer $random): array
    {
        $rows = [];
        for ($i = 0; $i < 10_000; $i++) {
            $rows[] = $i % 500 === 499 ? 'row' : ['dateadded' => [gmdate('Y-m-d H:i:s', self::NOW - $i * 60), '0000-00-00 00:00:00', null, (string) (self::NOW - $i)][$i % 4],
                'filecheck' => (string) [0, 3, 5, 10, 16][$i % 5]];
        }
        $pairs = [];
        for ($i = 0; $i < 5_000; $i++) {
            $hash = bin2hex($random->getBytes(64));
            $before = '[0001/0'.($i % 900 + 100).'] - "'.$hash.'.part'.$i.'.rar" yEnc (1/'.($i % 300 + 1).') '.str_repeat('padding ', 20);
            $pairs[] = ['before' => $before, 'after' => [$hash, " \t ", $before, substr($before, 10)][$i % 4]];
        }
        $candidates = [];
        for ($i = 0; $i < 5_000; $i++) {
            $hash = bin2hex($random->getBytes(32));
            $candidates[] = ['size' => (string) $random->getInt(1, 8_000_000), 'passwordstatus' => (string) ($i % 3 - 1),
                'searchname' => [$hash, 'a.'.$hash.'.b', str_repeat('Some.Long.Release.Name-', 8), base64_encode($random->getBytes(30))][$i % 4]];
        }
        $outcomes = [];
        for ($i = 0; $i < 20_000; $i++) {
            $outcomes[] = $i % 1_000 === 999 ? null : ['status' => (string) ($i % 7 === 0 ? 0 : 1), 'optype' => (string) ($i % 3 + 1), 'rule_id' => (string) $random->getInt(1, 5_000)];
        }
        $collections = [];
        for ($i = 0; $i < 5_000; $i++) {
            $collections[] = ['subject' => $i % 50 === 0 ? '   ' : substr(str_repeat(bin2hex($random->getBytes(16)).' - ', 8), 0, 255),
                'totalfiles' => (string) [10, 60_000, 0, 999][$i % 4]];
        }

        return [
            'summarizeCleanupRetentionBatch' => [$rows, self::NOW, 24],
            'summarizeCleanerPassTelemetry' => [$pairs],
            'summarizeRemovalCandidateBatch' => [$candidates, 1_048_576],
            'summarizeBlacklistEvaluationWindow' => [$outcomes, 1_000_000],
            'summarizeCollectionUpsertBatch' => [$collections, 50_000],
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
        return $ffi->nntmux_w401_collection_cleaning_remover_blacklist(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w401', CollectionCleanupService::W401_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 40.1 collection cleanup/cleaning/remover/blacklist export is not available.');
        }

        return $ffi;
    }
}
