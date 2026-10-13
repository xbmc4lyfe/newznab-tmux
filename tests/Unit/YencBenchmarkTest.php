<?php

declare(strict_types=1);

namespace Tests\Unit;

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
