<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YencBenchmarkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY')) {
            define('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY', true);
            require dirname(__DIR__, 2).'/scripts/benchmark-yenc.php';
        }
    }

    public function test_matching_correct_pairs_distinguish_useful_improvement_regression_and_noise(): void
    {
        $this->assertSame('useful_improvement', yencBenchmarkDecision(array_fill(0, 31, 0.8), 0, 5, 5, 123)['decision']);
        $this->assertSame('regression', yencBenchmarkDecision(array_fill(0, 31, 1.2), 0, 5, 5, 123)['decision']);
        $this->assertSame('inconclusive', yencBenchmarkDecision([0.7, 0.9, 1.0, 1.1, 1.3], 0, 5, 5, 123)['decision']);
        $this->assertSame('inconclusive', yencBenchmarkDecision(array_fill(0, 31, 1.03), 0, 5, 5, 123)['decision']);
    }

    public function test_failure_cannot_be_hidden_by_fast_surviving_pairs(): void
    {
        $result = yencBenchmarkDecision(array_fill(0, 30, 0.5), 1, 5, 5, 123);
        $this->assertSame('invalid', $result['decision']);
        $this->assertSame(30, $result['eligible_pairs']);
        $this->assertSame(1, $result['invalid_pairs']);
        $this->assertArrayNotHasKey('median_rust_over_cpp', $result);
    }

    /**
     * Execute the production finalizer with real decision summaries in a subprocess,
     * keeping native timing workers outside this deterministic exit-contract test.
     *
     * @param  list<float>  $ratios
     * @param  list<int>  $invalidPairs
     */
    #[DataProvider('runDecisionCases')]
    public function test_run_exit_requires_every_stratum_to_improve_and_retains_the_report(array $ratios, array $invalidPairs, int $expectedExit, string $expectedStatus, bool $expectedAdvantage): void
    {
        $directory = sys_get_temp_dir().'/nntmux-benchmark-gate-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $script = <<<'PHP'
define('NNTMUX_YENC_BENCHMARK_FUNCTIONS_ONLY', true);
require $argv[1];
$source = file_get_contents($argv[1]);
$start = strpos($source, '        $invalid = array_any($summaries,');
$end = $start === false ? false : strpos($source, '    } catch (Throwable $exception) {', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Benchmark run finalization block not found');
}
$cases = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
$summaries = [];
$log = fopen($argv[2].'/observations.jsonl', 'x');
$emit = static function (array $record) use ($log): void {
    $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
    fwrite($log, $line);
    fflush($log);
    echo $line;
};
foreach ($cases['ratios'] as $index => $ratio) {
    $summary = ['type' => 'stratum_summary', 'stratum' => $index]
        + yencBenchmarkDecision(array_fill(0, 31, $ratio), $cases['invalid_pairs'][$index], 5, 5, 123, count($cases['ratios']));
    $summaries[] = $summary;
    $emit($summary);
}
$protocol = ['claim_limit' => 'Deterministic decision-contract fixture; no measured performance claim'];
$finalize = static function () use ($summaries, $emit, $protocol, $source, $start, $end): int {
    return eval(substr($source, $start, $end - $start));
};
$code = $finalize();
fclose($log);
exit($code);
PHP;
        try {
            $process = yencBenchmarkRunChild([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/scripts/benchmark-yenc.php', $directory,
                json_encode(['ratios' => $ratios, 'invalid_pairs' => $invalidPairs], JSON_THROW_ON_ERROR)], $directory.'/process', 10);
            $this->assertFalse($process['timed_out']);
            $this->assertSame('', file_get_contents($process['stderr_path']));
            $this->assertSame(file_get_contents($directory.'/observations.jsonl'), file_get_contents($process['stdout_path']));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/observations.jsonl', FILE_IGNORE_NEW_LINES));
            $this->assertCount(count($ratios) + 1, $rows);
            foreach (array_slice($rows, 0, -1) as $index => $row) {
                $this->assertSame('stratum_summary', $row['type']);
                $this->assertSame(31, $row['eligible_pairs']);
                $this->assertSame($invalidPairs[$index], $row['invalid_pairs']);
            }
            $summary = $rows[array_key_last($rows)];
            $this->assertSame('run_summary', $summary['type']);
            $this->assertSame($expectedStatus, $summary['status']);
            $this->assertSame($expectedAdvantage, $summary['rust_speed_advantage_demonstrated_for_every_stratum']);
            $this->assertSame(count($ratios), $summary['strata']);
            $this->assertSame('Deterministic decision-contract fixture; no measured performance claim', $summary['claim_limit']);
            $this->assertSame($expectedExit, $process['exit_code']);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /** @return array<string, array{list<float>, list<int>, int, string, bool}> */
    public static function runDecisionCases(): array
    {
        return [
            'every stratum improves' => [[0.8, 0.8], [0, 0], 0, 'useful_improvement', true],
            'valid inconclusive pairs fail the gate' => [[1.03], [0], 1, 'inconclusive', false],
            'one improvement cannot hide an inconclusive stratum' => [[0.8, 1.03], [0, 0], 1, 'inconclusive', false],
            'exact five percent improvement remains inconclusive' => [[0.95], [0], 1, 'inconclusive', false],
            'regression fails the gate' => [[1.2], [0], 1, 'regression', false],
            'invalid pairs retain invalid exit status' => [[0.8], [1], 2, 'invalid', false],
            'invalidity takes precedence over regression' => [[1.2, 0.8], [0, 1], 2, 'invalid', false],
        ];
    }

    public function test_php_baseline_selects_the_real_php_worker_without_a_cpp_library(): void
    {
        $this->assertSame(['baseline' => 'php', 'library_options' => ['rust' => 'rust-native']],
            yencBenchmarkBackends(['php-baseline' => false, 'rust-native' => '/rust.so']));
        $this->assertSame(['baseline' => 'cpp', 'library_options' => ['cpp' => 'cpp-native', 'rust' => 'rust-native']],
            yencBenchmarkBackends(['cpp-native' => '/cpp.so', 'rust-native' => '/rust.so']));
    }

    public function test_conflicting_php_and_cpp_baselines_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        yencBenchmarkBackends(['php-baseline' => false, 'cpp-native' => '/cpp.so', 'rust-native' => '/rust.so']);
    }

    public function test_php_comparison_names_its_denominator_and_preserves_invalidity(): void
    {
        $result = yencBenchmarkDecision(array_fill(0, 31, 0.8), 0, 5, 5, 123, 6, 'php');
        $this->assertSame('useful_improvement', $result['decision']);
        $this->assertSame(0.8, $result['median_rust_over_php']);
        $this->assertArrayNotHasKey('median_rust_over_cpp', $result);
        $invalid = yencBenchmarkDecision(array_fill(0, 30, 0.5), 1, 5, 5, 123, 6, 'php');
        $this->assertSame('invalid', $invalid['decision']);
        $this->assertArrayNotHasKey('median_rust_over_php', $invalid);
    }

    public function test_php_pairing_retains_native_failures_and_cannot_compare_php_to_itself(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-php-paired-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/not-a-library.so', 'deliberately invalid native library');
        ob_start();
        try {
            $code = runPairedYencBenchmark(['php-baseline' => false,
                'rust-native' => $directory.'/not-a-library.so', 'size' => 64, 'order-seed' => 123,
                'output-dir' => $directory.'/paired'], 3);
            $this->assertSame(2, $code);
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/paired/observations.jsonl', FILE_IGNORE_NEW_LINES));
            $this->assertSame('php', $rows[0]['baseline']);
            $this->assertSame(['rust'], array_keys($rows[0]['libraries']));
            $php = array_values(array_filter($rows, static fn (array $row): bool => ($row['type'] ?? null) === 'observation' && $row['backend'] === 'php'));
            $this->assertCount(18, $php);
            foreach ($php as $row) {
                $this->assertTrue($row['eligible']);
                $this->assertNull($row['result']['library_sha256']);
                $this->assertNull($row['result']['decoder_version']);
                foreach ($row['result']['observations'] as $sample) {
                    $this->assertArrayHasKey('ru_utime.tv_usec', $sample['process_cpu_before']);
                    $this->assertArrayHasKey('ru_stime.tv_usec', $sample['process_cpu_after']);
                }
            }
            $this->assertSame('invalid', $rows[array_key_last($rows)]['status']);
            $this->assertFalse($rows[array_key_last($rows)]['rust_speed_advantage_demonstrated_for_every_stratum']);
            $this->assertCount(36, glob($directory.'/paired/*.stderr'));
        } finally {
            ob_end_clean();
            foreach (glob($directory.'/paired/*') as $path) {
                unlink($path);
            }
            if (is_dir($directory.'/paired')) {
                rmdir($directory.'/paired');
            }
            unlink($directory.'/not-a-library.so');
            rmdir($directory);
        }
    }

    public function test_paired_order_and_bootstrap_are_reproducible_without_global_rng(): void
    {
        $first = 20261013;
        $second = 20261013;
        $orders = [];
        for ($pair = 0; $pair < 31; $pair++) {
            $orders[] = yencBenchmarkRandom($first) & 1;
            $this->assertSame($first, yencBenchmarkRandom($second));
        }
        $this->assertContains(0, $orders);
        $this->assertContains(1, $orders);
        $ratios = [0.91, 0.92, 0.93, 0.94, 0.96];
        $this->assertSame(yencBenchmarkDecision($ratios, 0, 5, 5, 123), yencBenchmarkDecision($ratios, 0, 5, 5, 123));
    }

    public function test_fewer_than_three_matching_pairs_cannot_produce_a_performance_claim(): void
    {
        $result = yencBenchmarkDecision([0.5, 0.6], 0, 5, 5, 123);
        $this->assertSame('invalid', $result['decision']);
        $this->assertSame('insufficient_matching_pairs', $result['invalid_reason']);
    }

    public function test_timeout_and_child_failure_preserve_raw_outputs(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-benchmark-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $failed = yencBenchmarkRunChild([PHP_BINARY, '-r', 'fwrite(STDERR, "failure evidence"); exit(7);'], $directory.'/failed', 1);
            $this->assertSame('child_failed', $failed['status']);
            $this->assertSame(7, $failed['exit_code']);
            $this->assertSame('failure evidence', file_get_contents($failed['stderr_path']));
            $timedOut = yencBenchmarkRunChild([PHP_BINARY, '-r', 'echo "partial evidence"; usleep(2000000);'], $directory.'/timeout', 0.5);
            $this->assertSame('timeout', $timedOut['status']);
            $this->assertTrue($timedOut['timed_out']);
            $this->assertSame('partial evidence', file_get_contents($timedOut['stdout_path']));
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
