<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\NativeSpotCheckPlanner;
use App\Services\Releases\Verification\SpotCheckPlanner;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\SamplePlanningBenchmarkFixtures;

final class SamplePlanningBenchmarkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('NNTMUX_SAMPLE_BENCHMARK_FUNCTIONS_ONLY')) {
            define('NNTMUX_SAMPLE_BENCHMARK_FUNCTIONS_ONLY', true);
            require dirname(__DIR__, 2).'/scripts/benchmark-sample-planning.php';
        }
    }

    public function test_fixture_properties_cover_real_php_caller_boundaries_duplicates_terminals_and_budgets(): void
    {
        $native = new class extends NativeSpotCheckPlanner
        {
            /** @param array<string,mixed> $manifest @return null */
            public function select(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): ?array
            {
                return null;
            }
        };
        $planner = new SpotCheckPlanner($native);
        foreach (SamplePlanningBenchmarkFixtures::strata() as $name => $stratum) {
            foreach ([0, 7] as $variant) {
                $fixture = SamplePlanningBenchmarkFixtures::make($name, $variant);
                self::assertSame($stratum['articles'], array_sum(array_map(static fn (array $file): int => count($file['segments']), $fixture['manifest']['files'])));
                $sample = $planner->select($fixture['manifest'], $fixture['maximum_articles'], $fixture['maximum_header_bytes'], $fixture['maximum_article_bytes']);
                self::assertTrue(SamplePlanningBenchmarkFixtures::correct($fixture, $sample), $name);
                $mutant = $sample;
                $mutant[0]['number'] = 0;
                self::assertFalse(SamplePlanningBenchmarkFixtures::correct($fixture, $mutant));
                $mutant = $sample;
                $mutant[1] = $mutant[0];
                self::assertFalse(SamplePlanningBenchmarkFixtures::correct($fixture, $mutant));
            }
        }
    }

    public function test_tail_gate_compares_p99_to_p99_and_cannot_be_won_by_an_average(): void
    {
        $php = array_fill(0, 1000, 10.0);
        $native = array_fill(0, 1000, 2.0);
        for ($index = 980; $index < 1000; $index++) {
            $native[$index] = 20.0;
        }
        $tail = sampleBenchmarkDecision($php, $native, 0, 'uncommon', 'qualification');
        self::assertSame('p99_latency', $tail['metric']);
        self::assertSame(0.5, $tail['php_over_native']);
        self::assertFalse($tail['qualifies']);
        self::assertTrue(sampleBenchmarkDecision($php, $native, 0, 'common', 'qualification')['qualifies']);
    }

    public function test_small_screen_samples_never_qualify_despite_large_apparent_gain(): void
    {
        self::assertFalse(sampleBenchmarkDecision(array_fill(0, 1000, 10.0), array_fill(0, 1000, 1.0), 0, 'uncommon', 'screen')['qualifies']);
        self::assertFalse(sampleBenchmarkDecision(array_fill(0, 31, 10.0), array_fill(0, 31, 1.0), 0, 'uncommon', 'qualification')['qualifies']);
        self::assertNull(sampleBenchmarkQuantile(array_fill(0, 31, 1.0), 0.99, 0.05 / 16)['interval'][1]);
        $large = sampleBenchmarkQuantile(array_fill(0, 1000, 1.0), 0.99, 0.05 / 16);
        self::assertSame([1.0, 1.0], $large['interval']);
        self::assertGreaterThan(970, $large['ranks'][0]);
        self::assertLessThanOrEqual(1000, $large['ranks'][1]);
    }

    public function test_order_statistic_intervals_do_not_underflow_for_large_median_samples(): void
    {
        $result = sampleBenchmarkQuantile(array_fill(0, 10000, 5.0), 0.5, 0.05 / 16);
        self::assertSame([5.0, 5.0], $result['interval']);
        self::assertGreaterThan(4700, $result['ranks'][0]);
        self::assertLessThan(5300, $result['ranks'][1]);
    }

    public function test_failures_and_mismatched_pair_counts_block_qualification(): void
    {
        self::assertSame('invalid', sampleBenchmarkDecision(array_fill(0, 1000, 10.0), array_fill(0, 1000, 1.0), 1, 'common', 'qualification')['status']);
        self::assertSame('invalid', sampleBenchmarkDecision([10.0], [], 0, 'common', 'qualification')['status']);
    }

    public function test_build_receipt_must_bind_every_native_and_caller_source(): void
    {
        $sources = ['app/Services/Releases/Verification/SpotCheckPlanner.php' => 'caller', 'docker/app/nzb-yenc-ffi/src/nested.rs' => 'rust'];
        sampleBenchmarkVerifyManifest(['candidate_library_sha256' => 'library', 'candidate_source_sha256' => $sources], $sources, 'library');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Build receipt requires source identity: docker/app/nzb-yenc-ffi/src/nested.rs');
        sampleBenchmarkVerifyManifest(['candidate_library_sha256' => 'library', 'candidate_source_sha256' => ['app/Services/Releases/Verification/SpotCheckPlanner.php' => 'caller']], $sources, 'library');
    }

    public function test_worker_cannot_count_php_fallback_as_native_execution(): void
    {
        $directory = sys_get_temp_dir().'/sample-worker-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            file_put_contents($directory.'/invalid.so', 'invalid native library');
            $fixture = SamplePlanningBenchmarkFixtures::make('boundary-1024', 0);
            $result = sampleBenchmarkWorker('native', $fixture, $directory.'/invalid.so', dirname(__DIR__, 2), $directory.'/result');
            foreach ($result['observations'] as $row) {
                self::assertTrue($row['correct']);
                self::assertSame(1, $row['native_attempts']);
                self::assertSame(0, $row['native_successes']);
            }
            self::assertSame($result['result_sha256'], hash_file('sha256', $directory.'/result'));
            $child = ['status' => 'ok', 'stdout_truncated_for_json' => false, 'result' => $result];
            $snapshot = ['root' => dirname(__DIR__, 2), 'files' => $result['runtime_sources']['files'], 'git_head' => null, 'git_tree' => null];
            self::assertFalse(sampleBenchmarkEligible($child, 'native', true, $result['fixture_sha256'], $result['library_sha256'], $directory.'/result', $snapshot, $result['instrumentation']));
        } finally {
            unlink($directory.'/invalid.so');
            if (is_file($directory.'/result')) {
                unlink($directory.'/result');
            }
            rmdir($directory);
        }
    }

    public function test_qualification_rejects_insufficient_samples_before_starting_workers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('qualification needs at least 1000 pairs');
        sampleBenchmarkRun(['mode' => 'qualification', 'samples' => 31]);
    }

    public function test_real_native_worker_has_exact_public_caller_output_and_execution_proof(): void
    {
        $library = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! is_string($library) || ! is_file($library)) {
            self::markTestSkipped('Set NNTMUX_TEST_NATIVE_LIBRARY on Linux for actual native execution.');
        }
        $directory = sys_get_temp_dir().'/sample-native-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            foreach (SamplePlanningBenchmarkFixtures::strata() as $name => $stratum) {
                $fixture = SamplePlanningBenchmarkFixtures::make($name, 7);
                $php = sampleBenchmarkWorker('php', $fixture, $library, dirname(__DIR__, 2), $directory.'/php');
                $native = sampleBenchmarkWorker('native', $fixture, $library, dirname(__DIR__, 2), $directory.'/native');
                self::assertSame(file_get_contents($directory.'/php'), file_get_contents($directory.'/native'), $name);
                foreach ($native['observations'] as $row) {
                    self::assertTrue($row['correct']);
                    self::assertSame($stratum['native'] ? 1 : 0, $row['native_successes'], $name);
                }
                self::assertSame($php['result_sha256'], $native['result_sha256']);
            }
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
