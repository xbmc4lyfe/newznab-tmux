<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalProcessingDiagnostics;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

final class RustAdditionalCandidateDiagOrchConsoleNzbW41ParityTest extends TestCase
{
    private const HELPERS = [
        'summarizeCandidateEligibilityBatch' => AdditionalCandidateQuery::class,
        'summarizeDiagnosticCapacityBatch' => AdditionalProcessingDiagnostics::class,
        'summarizeOrchestrationBatch' => AdditionalProcessingOrchestrator::class,
        'summarizeConsoleEventBatch' => ConsoleOutputService::class,
        'summarizeNzbSegmentBatch' => NzbContentParser::class,
    ];

    private const CDEF = 'int32_t nntmux_w416_additional_candidate_diag_orch_console_nzb(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    private const EDGE_INTS = [PHP_INT_MIN, PHP_INT_MIN + 1, -1, 0, 1, 2, 25, 32, 33, 500, 501, 3600, PHP_INT_MAX - 1, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as $class) {
            if (method_exists($class, 'resetW416RustFfi')) {
                $class::resetW416RustFfi();
            }
        }
        parent::tearDown();
    }

    public function test_candidate_reference_contract(): void
    {
        $row = ['size' => 10, 'passwordstatus' => -1, 'haspreview' => -1, 'nzbstatus' => 1];
        $this->assertSame(['total' => 6, 'eligible' => 2, 'too_small' => 1, 'too_large' => 1, 'blocked' => 1, 'invalid' => 1],
            AdditionalCandidateQuery::summarizeCandidateEligibilityBatchPhp([
                $row, array_replace($row, ['size' => 20]), array_replace($row, ['size' => 9]),
                array_replace($row, ['size' => 21]), array_replace($row, ['passwordstatus' => 0]), ['size' => '10'],
            ], 10, 20));
    }

    public function test_diagnostic_reference_contract(): void
    {
        $this->assertSame(['total' => 4, 'stale_claims' => PHP_INT_MAX, 'max_in_flight' => PHP_INT_MAX, 'unsafe_ttl' => 2, 'oversized' => 2, 'invalid' => 1],
            AdditionalProcessingDiagnostics::summarizeDiagnosticCapacityBatchPhp([
                ['stale_claims' => 2, 'batch_size' => 25, 'threads' => 2, 'child_timeout' => 9, 'release_timeout' => 2],
                ['stale_claims' => PHP_INT_MAX, 'batch_size' => PHP_INT_MAX, 'threads' => PHP_INT_MAX, 'child_timeout' => PHP_INT_MAX, 'release_timeout' => PHP_INT_MAX],
                ['stale_claims' => -1, 'batch_size' => 1, 'threads' => 33, 'child_timeout' => 60, 'release_timeout' => 0], null,
            ], 60));
    }

    public function test_orchestration_reference_contract(): void
    {
        $this->assertSame(['total' => 5, 'setup_failed' => 1, 'empty' => 1, 'processing' => 2, 'total_elapsed_ms' => PHP_INT_MAX, 'budget_exhausted' => true, 'invalid' => 1],
            AdditionalProcessingOrchestrator::summarizeOrchestrationBatchPhp([
                ['setup_ok' => false, 'release_count' => 25, 'elapsed_ms' => 10],
                ['setup_ok' => true, 'release_count' => 0, 'elapsed_ms' => -1],
                ['setup_ok' => true, 'release_count' => 1, 'elapsed_ms' => PHP_INT_MAX],
                ['setup_ok' => true, 'release_count' => 2, 'elapsed_ms' => 1], ['setup_ok' => 1],
            ], PHP_INT_MAX));
    }

    public function test_console_reference_contract(): void
    {
        $this->assertSame(['total' => 6, 'warnings' => 1, 'errors' => 1, 'progress' => 1, 'text_bytes' => 10, 'truncated' => true, 'invalid' => 1],
            ConsoleOutputService::summarizeConsoleEventBatchPhp([
                ['kind' => 'warning', 'text' => "a\0b"], ['kind' => 'error', 'text' => 'é'],
                ['kind' => 'progress', 'text' => '12'], ['kind' => 'WARNING', 'text' => 'abc'],
                ['kind' => '', 'text' => ''], ['kind' => 'error', 'text' => 7],
            ], 9));
    }

    public function test_nzb_reference_contract(): void
    {
        $this->assertSame(['total_files' => 5, 'empty_files' => 1, 'total_segments' => PHP_INT_MAX, 'total_bytes' => PHP_INT_MAX, 'invalid_files' => 2, 'exceeds_segment_limit' => false],
            NzbContentParser::summarizeNzbSegmentBatchPhp([
                ['segments' => 0, 'bytes' => 0], ['segments' => PHP_INT_MAX, 'bytes' => PHP_INT_MAX],
                ['segments' => 1, 'bytes' => 1], ['segments' => -1, 'bytes' => 4], ['segments' => '2', 'bytes' => 4],
            ], PHP_INT_MAX));
    }

    public function test_toggles_are_independent_and_preserve_earlier_wave_state(): void
    {
        foreach (self::HELPERS as $class) {
            $class::resetW416RustFfi();
            $this->assertSame($class::W416_RUST_FFI_DEFAULT, $class::usesW416RustFfi());
        }
        foreach (self::HELPERS as $class) {
            $earlier = property_exists($class, 'useRustFfi') ? new ReflectionProperty($class, 'useRustFfi') : null;
            $original = $earlier?->getValue();
            try {
                foreach ([true, false] as $enable) {
                    $earlier?->setValue(null, ! $enable);
                    $class::useW416RustFfi($enable);
                    $this->assertSame($enable, $class::usesW416RustFfi());
                    if ($earlier !== null) {
                        $this->assertSame(! $enable, $earlier->getValue());
                    }
                    foreach (self::HELPERS as $other) {
                        if ($other !== $class) {
                            $this->assertSame($other::W416_RUST_FFI_DEFAULT, $other::usesW416RustFfi());
                        }
                    }
                }
                $class::useW416RustFfi();
                $this->assertTrue($class::usesW416RustFfi());
                $class::resetW416RustFfi();
                $this->assertSame($class::W416_RUST_FFI_DEFAULT, $class::usesW416RustFfi());
                $this->assertNull((new ReflectionProperty($class, 'w416Ffi'))->getValue());
            } finally {
                $earlier?->setValue(null, $original);
            }
        }
    }

    public function test_empty_threshold_and_integer_extreme_parity(): void
    {
        $this->ffiOrSkip();
        foreach (self::EDGE_INTS as $a) {
            foreach (self::EDGE_INTS as $b) {
                foreach ($this->casesFor($a, $b) as $helper => $args) {
                    $this->assertParity($helper, $args);
                }
            }
            foreach (self::HELPERS as $helper => $class) {
                $args = $helper === 'summarizeCandidateEligibilityBatch' ? [[], $a, $a] : [[], $a];
                $this->assertParity($helper, $args);
            }
        }
        $this->assertFalse(AdditionalProcessingOrchestrator::summarizeOrchestrationBatch([], 0)['budget_exhausted']);
        $this->assertFalse(ConsoleOutputService::summarizeConsoleEventBatch([['kind' => 'warning', 'text' => 'ab']], 2)['truncated']);
        $this->assertFalse(NzbContentParser::summarizeNzbSegmentBatch([['segments' => 2, 'bytes' => 0]], 2)['exceeds_segment_limit']);
        $this->assertSame(1, AdditionalProcessingDiagnostics::summarizeDiagnosticCapacityBatch([
            ['stale_claims' => 0, 'batch_size' => 1, 'threads' => 1, 'child_timeout' => 30, 'release_timeout' => 30],
        ], 60)['unsafe_ttl']);
    }

    public function test_malformed_rows_fall_back_before_ffi_resolution(): void
    {
        $object = new class
        {
            public function __toString(): string
            {
                throw new \RuntimeException('Inputs must never be coerced.');
            }
        };
        $valid = $this->casesFor(10, 20);
        foreach ($valid as $helper => $args) {
            $class = self::HELPERS[$helper];
            $row = $args[0][0];
            $badRows = [null, false, 1, 1.5, 'row', $object, []];
            foreach (array_keys($row) as $key) {
                $missing = $row;
                unset($missing[$key]);
                $badRows[] = $missing;
                foreach ([null, false, true, '1', 1.5, INF, NAN, [], $object] as $bad) {
                    // A boolean is a legitimate setup flag; every other row field has a different type.
                    if (($key === 'setup_ok' && is_bool($bad)) || (is_string($row[$key]) && is_string($bad))) {
                        continue;
                    }
                    $badRows[] = array_replace($row, [$key => $bad]);
                }
            }
            if ($helper === 'summarizeCandidateEligibilityBatch') {
                $badRows[] = array_replace($row, ['size' => -1]);
            }
            if ($helper === 'summarizeNzbSegmentBatch') {
                $badRows[] = array_replace($row, ['segments' => -1]);
                $badRows[] = array_replace($row, ['bytes' => -1]);
            }
            foreach ($badRows as $badRow) {
                $class::resetW416RustFfi();
                $args[0] = ['valid' => $row, 42 => $badRow];
                $expected = $class::{$helper.'Php'}(...$args);
                $this->assertSame(1, $expected[$helper === 'summarizeNzbSegmentBatch' ? 'invalid_files' : 'invalid']);
                $this->assertSame($expected, $class::{$helper.'Native'}(...$args));
                $class::useW416RustFfi();
                $this->assertSame($expected, $class::$helper(...$args));
                $this->assertNull((new ReflectionProperty($class, 'w416Ffi'))->getValue(), $helper.' should decline before FFI');
            }
        }
    }

    public function test_unavailable_and_throwing_ffi_fall_back_and_reset_retries(): void
    {
        $this->ffiOrSkip();
        foreach ($this->casesFor(10, 20) as $helper => $args) {
            $class = self::HELPERS[$helper];
            $cache = new ReflectionProperty($class, 'w416Ffi');
            $expected = $class::{$helper.'Php'}(...$args);
            $wrong = RustPipelineLibrary::resolveDomain('w416_test_bad_return', str_replace('int32_t ', 'void ', self::CDEF));
            $this->assertNotNull($wrong);
            foreach ([false, FFI::cdef(''), $wrong] as $unavailable) {
                $cache->setValue(null, $unavailable);
                $class::useW416RustFfi();
                $this->assertSame($expected, $class::{$helper.'Native'}(...$args));
                $this->assertSame($expected, $class::$helper(...$args));
            }
            $class::resetW416RustFfi();
            $this->assertSame($expected, $class::{$helper.'Native'}(...$args));
            $this->assertInstanceOf(FFI::class, $cache->getValue());
        }
    }

    public function test_oversized_batches_use_php_without_ffi(): void
    {
        foreach ($this->casesFor(1, 2) as $helper => $args) {
            $class = self::HELPERS[$helper];
            $class::resetW416RustFfi();
            $args[0] = array_fill(0, 100_001, $args[0][0]);
            $this->assertSame($class::{$helper.'Php'}(...$args), $class::{$helper.'Native'}(...$args));
            $this->assertNull((new ReflectionProperty($class, 'w416Ffi'))->getValue());
        }
    }

    public function test_randomized_valid_and_malformed_fuzz_parity(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4161));
        for ($case = 0; $case < 1_200; $case++) {
            $a = $case % 3 === 0 ? self::EDGE_INTS[$random->getInt(0, count(self::EDGE_INTS) - 1)] : $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
            $b = $random->getInt(0, 50);
            $cases = $this->casesFor($a, $b);
            foreach ($cases as $helper => $args) {
                $rows = [];
                for ($i = $random->getInt(0, 40); $i > 0; $i--) {
                    $rows['row-'.$i] = $args[0][$random->getInt(0, count($args[0]) - 1)];
                }
                if ($helper === 'summarizeConsoleEventBatch') {
                    $rows[] = ['kind' => $random->getBytes($random->getInt(1, 20)), 'text' => $random->getBytes($random->getInt(1, 100))];
                }
                $args[0] = $rows;
                $this->assertParity($helper, $args);
                $this->assertInstanceOf(FFI::class, (new ReflectionProperty(self::HELPERS[$helper], 'w416Ffi'))->getValue());
                $args[0]['malformed'] = [null, 'bad', true, [], ['segments' => '1']][$random->getInt(0, 4)];
                $this->assertParity($helper, $args);
            }
        }
    }

    public function test_export_frames_capacities_and_overlap_never_write_on_negative(): void
    {
        $ffi = $this->ffiOrSkip();
        $operation = 0;
        foreach ($this->casesFor(10, 20) as $helper => $args) {
            $operation++;
            $frame = $this->frame($operation, $args);
            $class = self::HELPERS[$helper];
            $expected = pack('q*', ...array_map(static fn (int|bool $value): int => (int) $value, array_values($class::{$helper.'Php'}(...$args))));
            $required = strlen($expected);
            $out = $ffi->new('char[128]');
            FFI::memset($out, ord('Z'), 128);
            foreach ([0, 1, $required - 1] as $cap) {
                $this->assertSame(-$required, $this->callFfi($ffi, $operation, $frame, strlen($frame), $out, $cap));
                $this->assertSame(str_repeat('Z', 128), FFI::string($out, 128));
            }
            foreach ([0, 6, 255] as $badOp) {
                $this->assertSame(-1, $this->callFfi($ffi, $badOp, $frame, strlen($frame), $out, 128));
            }
            $this->assertSame(-1, $this->callFfi($ffi, $operation, null, strlen($frame), $out, 128));
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $frame, strlen($frame), null, 128));
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $frame, 16_777_217, $out, 128));
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $frame, strlen($frame), $out, 16_777_217));
            for ($len = 0; $len < strlen($frame); $len++) {
                $this->assertSame(-1, $this->callFfi($ffi, $operation, $frame, $len, $out, 128));
            }
            foreach ([$frame.'x', pack('q2V', 0, 0, 100_001), pack('q2V', 0, 0, 0xFFFFFFFF)] as $badFrame) {
                $this->assertSame(-1, $this->callFfi($ffi, $operation, $badFrame, strlen($badFrame), $out, 128));
            }
            $size = strlen($frame) + 128;
            $overlap = $ffi->new('char['.$size.']');
            FFI::memset($overlap, ord('Z'), $size);
            FFI::memcpy($overlap, $frame, strlen($frame));
            $before = FFI::string($overlap, $size);
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $overlap, strlen($frame), $overlap, 128));
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $overlap, strlen($frame), FFI::addr($overlap[1]), 128));
            $this->assertSame(-1, $this->callFfi($ffi, $operation, FFI::addr($overlap[1]), strlen($frame) - 1, $overlap, 128));
            $this->assertSame($before, FFI::string($overlap, $size));
            $this->assertSame(str_repeat('Z', 128), FFI::string($out, 128));
            $this->assertSame($required, $this->callFfi($ffi, $operation, $frame, strlen($frame), $out, $required));
            $this->assertSame($expected, FFI::string($out, $required));
            $this->assertSame(str_repeat('Z', 128 - $required), FFI::string(FFI::addr($out[$required]), 128 - $required));
        }
    }

    public function test_paired_randomized_benchmark(): void
    {
        // Protocol: optimized dylib/so, 25 warmups, three rounds of 1,001 individual
        // calls per implementation/workload. Seed 4162 fixes paired random order.
        // Each timed public dispatcher includes input validation, packing, FFI,
        // output allocation and decoding. Every result is checked outside timing.
        // Common batches: candidates 100, diagnostic samples 16, orchestration 25,
        // console events 64, NZB files 100. Uncommon: 5,000 valid mixed/extreme rows.
        // Malformed fallback costs are covered separately, never credited as Rust.
        // Reproduce with --filter paired_randomized_benchmark; optional raw JSON
        // destination: W416_BENCHMARK_JSON=/tmp/nntmux-w416-benchmark.json.
        // Qualification run 2026-10-25: PHP 8.5.12 NTS, Apple M5 (10 cores,
        // 32 GiB), rustc 1.99.1, opt-level=3, LTO, codegen-units=1. Three rounds:
        // helper       common mean/p99                      uncommon mean/p99
        // candidate    .714/.655, .718/.717, .706/.665        .805/.814, .799/.810, .801/.862
        // diagnostics  1.353/1.328, 1.362/1.344, 1.356/1.373  1.955/1.868, 1.955/1.879, 1.938/1.844
        // orchestration .638/.470, .658/.677, .652/.667       .893/.943, .892/.889, .890/.847
        // console      .778/.635, .782/.799, .781/.793        .901/.924, .912/.903, .928/1.003
        // NZB          1.126/1.123, 1.116/1.098, 1.107/1.125  1.276/1.212, 1.280/1.253, 1.261/1.171
        // None clears all four >=1.5x metrics; all W416 defaults stay false.
        // Raw observations and environment: /tmp/nntmux-w416-benchmark-final.json
        // and /tmp/nntmux-w416-environment.json. Timings qualify these helpers only.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4162));
        $record = ['protocol' => ['seed' => 4162, 'warmups' => 25, 'rounds' => 3, 'samples_per_round' => 1_001,
            'unit' => 'nanoseconds per complete public helper call', 'php' => PHP_VERSION,
            'os' => php_uname(), 'source_sha256' => $this->sourceHashes()], 'results' => []];
        foreach (self::HELPERS as $helper => $class) {
            foreach (['common', 'uncommon'] as $workload) {
                $args = $this->benchmarkWorkload($helper, $workload === 'uncommon');
                $expected = $class::{$helper.'Php'}(...$args);
                $this->assertParity($helper, $args);
                $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w416Ffi'))->getValue());
                for ($w = 0; $w < 25; $w++) {
                    foreach ([false, true] as $enable) {
                        $class::useW416RustFfi($enable);
                        $this->assertSame($expected, $class::$helper(...$args));
                    }
                }
                for ($round = 0; $round < 3; $round++) {
                    $times = ['php' => [], 'rust' => [], 'order' => []];
                    for ($sample = 0; $sample < 1_001; $sample++) {
                        $first = $random->getInt(0, 1) === 1;
                        $times['order'][] = $first ? 'rust,php' : 'php,rust';
                        foreach ([$first, ! $first] as $enable) {
                            $class::useW416RustFfi($enable);
                            $start = hrtime(true);
                            $actual = $class::$helper(...$args);
                            $elapsed = hrtime(true) - $start;
                            $this->assertSame($expected, $actual);
                            $times[$enable ? 'rust' : 'php'][] = $elapsed;
                        }
                    }
                    $phpMean = array_sum($times['php']) / count($times['php']);
                    $rustMean = array_sum($times['rust']) / count($times['rust']);
                    $record['results'][$helper][$workload][] = ['mean_speedup' => $phpMean / max(1, $rustMean),
                        'p99_speedup' => $this->p99($times['php']) / max(1, $this->p99($times['rust'])),
                        'php_mean_ns' => $phpMean, 'rust_mean_ns' => $rustMean,
                        'php_p99_ns' => $this->p99($times['php']), 'rust_p99_ns' => $this->p99($times['rust']), 'raw' => $times];
                }
                $class::resetW416RustFfi();
            }
        }
        $summary = [];
        foreach ($record['results'] as $helper => $workloads) {
            foreach ($workloads as $workload => $rounds) {
                $summary[$helper][$workload] = array_map(static fn (array $round): array => [round($round['mean_speedup'], 3), round($round['p99_speedup'], 3)], $rounds);
            }
            $class = self::HELPERS[$helper];
            $qualified = true;
            foreach ($workloads as $rounds) {
                foreach ($rounds as $round) {
                    $qualified = $qualified && $round['mean_speedup'] >= 1.5 && $round['p99_speedup'] >= 1.5;
                }
            }
            if (! $qualified) {
                $this->assertFalse($this->defaultFor($class), $helper.' has not cleared every 1.5x gate');
            }
        }
        $destination = getenv('W416_BENCHMARK_JSON');
        if (is_string($destination) && $destination !== '') {
            $this->assertNotFalse(file_put_contents($destination, json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
        }
        fwrite(STDERR, "\nW416 speedups [mean,p99] per round: ".json_encode($summary, JSON_THROW_ON_ERROR)."\n");
    }

    /** @return list<mixed> */
    private function benchmarkWorkload(string $helper, bool $uncommon): array
    {
        $count = $uncommon ? 5_000 : match ($helper) {
            'summarizeDiagnosticCapacityBatch' => 16, 'summarizeOrchestrationBatch' => 25,
            'summarizeConsoleEventBatch' => 64, default => 100,
        };
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $extreme = $uncommon && $i % 11 === 0;
            $rows[] = match ($helper) {
                'summarizeCandidateEligibilityBatch' => ['size' => $extreme ? PHP_INT_MAX : $i * 1_048_576,
                    'passwordstatus' => $i % 17 === 0 ? 0 : -1, 'haspreview' => $i % 19 === 0 ? 1 : -1, 'nzbstatus' => $i % 23 === 0 ? 0 : 1],
                'summarizeDiagnosticCapacityBatch' => ['stale_claims' => $extreme ? PHP_INT_MAX : $i % 7,
                    'batch_size' => $extreme ? PHP_INT_MAX : $i % 50, 'threads' => $extreme ? PHP_INT_MIN : $i % 40,
                    'child_timeout' => $i % 1800, 'release_timeout' => $extreme ? PHP_INT_MAX : 60],
                'summarizeOrchestrationBatch' => ['setup_ok' => $i % 7 !== 0, 'release_count' => $i % 26,
                    'elapsed_ms' => $extreme ? PHP_INT_MAX : $i * 100],
                'summarizeConsoleEventBatch' => ['kind' => ['info', 'warning', 'progress', 'error', 'UNKNOWN'][$i % 5],
                    'text' => $uncommon ? str_repeat("binary\0é", $i % 200) : 'Release '.$i.' processing'],
                'summarizeNzbSegmentBatch' => ['segments' => $extreme ? PHP_INT_MAX : $i % 400,
                    'bytes' => $extreme ? PHP_INT_MAX : $i * 700_000],
                default => throw new \InvalidArgumentException('Unknown W416 helper: '.$helper),
            };
        }

        return $helper === 'summarizeCandidateEligibilityBatch' ? [$rows, 1_048_576, 107_374_182_400] : [$rows, match ($helper) {
            'summarizeDiagnosticCapacityBatch' => 3600, 'summarizeOrchestrationBatch' => 60_000,
            'summarizeConsoleEventBatch' => 65536, default => 100_000,
        }];
    }

    /** @param list<int> $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[(int) ceil(count($values) * 0.99) - 1];
    }

    /** @return array<string, string|false> */
    private function sourceHashes(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [__FILE__, $root.'/rust/pipeline-ffi/src/additional.rs', $root.'/composer.lock', $root.'/rust/pipeline-ffi/Cargo.lock'];
        foreach (self::HELPERS as $class) {
            $files[] = (new \ReflectionClass($class))->getFileName();
        }
        $hashes = [];
        foreach ($files as $file) {
            if (is_string($file)) {
                $hashes[substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
            }
        }

        return $hashes;
    }

    /** @param list<mixed> $args */
    private function assertParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        $expected = $class::{$helper.'Php'}(...$args);
        $this->assertSame($expected, $class::{$helper.'Native'}(...$args), $helper);
        foreach ([false, true] as $enable) {
            $class::useW416RustFfi($enable);
            $this->assertSame($expected, $class::$helper(...$args), $helper);
        }
        $class::useW416RustFfi(false);
    }

    /** @return array<string, list<mixed>> */
    private function casesFor(int $a, int $b): array
    {
        $size = max(0, $a);

        return [
            'summarizeCandidateEligibilityBatch' => [[
                ['size' => $size, 'passwordstatus' => -1, 'haspreview' => -1, 'nzbstatus' => 1],
                ['size' => max(0, $b), 'passwordstatus' => $a, 'haspreview' => $a, 'nzbstatus' => $b],
                ['size' => PHP_INT_MAX, 'passwordstatus' => -1, 'haspreview' => -1, 'nzbstatus' => 1],
            ], $a, $b],
            'summarizeDiagnosticCapacityBatch' => [[
                ['stale_claims' => $a, 'batch_size' => $a, 'threads' => $b, 'child_timeout' => $a, 'release_timeout' => $b],
                ['stale_claims' => $b, 'batch_size' => $b, 'threads' => $a, 'child_timeout' => $b, 'release_timeout' => $a],
            ], $a],
            'summarizeOrchestrationBatch' => [[
                ['setup_ok' => true, 'release_count' => $b, 'elapsed_ms' => $a],
                ['setup_ok' => false, 'release_count' => $a, 'elapsed_ms' => $b],
                ['setup_ok' => true, 'release_count' => $a, 'elapsed_ms' => $b],
            ], $a],
            'summarizeConsoleEventBatch' => [[
                ['kind' => 'warning', 'text' => "a\0b"], ['kind' => 'error', 'text' => 'é'],
                ['kind' => 'progress', 'text' => str_repeat('x', max(0, min(512, $b)))], ['kind' => 'WARNING', 'text' => ''],
            ], $a],
            'summarizeNzbSegmentBatch' => [[
                ['segments' => $size, 'bytes' => max(0, $b)], ['segments' => max(0, $b), 'bytes' => $size], ['segments' => 0, 'bytes' => 0],
            ], $a],
        ];
    }

    /** @param list<mixed> $args */
    private function frame(int $operation, array $args): string
    {
        $frame = pack('q2V', $args[1], $operation === 1 ? $args[2] : 0, count($args[0]));
        foreach ($args[0] as $row) {
            $values = match ($operation) {
                1 => [$row['size'], $row['passwordstatus'], $row['haspreview'], $row['nzbstatus']],
                2 => [$row['stale_claims'], $row['batch_size'], $row['threads'], $row['child_timeout'], $row['release_timeout']],
                3 => [(int) $row['setup_ok'], $row['release_count'], $row['elapsed_ms']],
                4 => [match ($row['kind']) {
                    'warning' => 1, 'error' => 2, 'progress' => 3, default => 0
                }, strlen($row['text'])],
                5 => [$row['segments'], $row['bytes']],
                default => throw new \InvalidArgumentException('Unknown W416 operation: '.$operation),
            };
            $frame .= pack('q*', ...$values);
        }

        return $frame;
    }

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w416_additional_candidate_diag_orch_console_nzb(...$args);
    }

    private function defaultFor(string $class): bool
    {
        return (bool) constant($class.'::W416_RUST_FFI_DEFAULT');
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w416_test', self::CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('Wave 41.6 release library is unavailable. Build rust/pipeline-ffi first.');
        }

        return $ffi;
    }
}
