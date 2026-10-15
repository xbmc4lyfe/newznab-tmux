<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function Nntmux\Benchmark\StrictArticle\Controller\eligibleObservation;
use function Nntmux\Benchmark\StrictArticle\Controller\expectedFixtureIds;
use function Nntmux\Benchmark\StrictArticle\Controller\gitAtRoot;
use function Nntmux\Benchmark\StrictArticle\Controller\optimizedBuildReceiptMatches;
use function Nntmux\Benchmark\StrictArticle\Controller\run;
use function Nntmux\Benchmark\StrictArticle\Controller\runStatistics;
use function Nntmux\Benchmark\StrictArticle\Controller\scheduledChild;
use function Nntmux\Benchmark\StrictArticle\Controller\statisticsMatches;

final class YencStrictArticleControllerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/benchmark-yenc-strict-article.php';
    }

    public function test_coverage_names_all_common_and_uncommon_cases_before_execution(): void
    {
        $ids = expectedFixtureIds();
        self::assertCount(42, $ids);
        self::assertCount(42, array_unique($ids));
        self::assertContains('single-4096-ordinary-strict', $ids);
        self::assertContains('single-8388608-heavy-body', $ids);
        self::assertContains('multipart-8388608-heavy-strict', $ids);
        self::assertContains('rare-dangling-before-range', $ids);
        self::assertContains('rare-numeric-overflow-fallback', $ids);
    }

    public function test_success_requires_the_matching_role_protocol_library_and_input_contract(): void
    {
        $fixture = ['id' => 'single-4096-ordinary-strict', 'path' => 'strict', 'admission' => 'supported'];
        $protocol = ['protocol_sha256' => str_repeat('a', 64), 'libraries' => ['rust' => ['sha256' => str_repeat('b', 64)]]];
        $observation = ['status' => 'ok', 'exit_code' => 0, 'result' => [
            'status' => 'ok', 'correct' => true, 'input_unchanged' => true, 'input_contract' => 'strict_unchanged',
            'backend' => 'rust', 'id' => $fixture['id'], 'library_sha256' => str_repeat('b', 64),
            'protocol_sha256' => str_repeat('a', 64), 'median_ms' => 1.0,
            'elapsed_ms' => 1.0, 'admission' => 'supported',
        ]];
        self::assertTrue(eligibleObservation($observation, 'rust', $fixture, $protocol));
        foreach ([['backend', 'cpp'], ['id', 'another-fixture'], ['protocol_sha256', str_repeat('c', 64)],
            ['library_sha256', str_repeat('c', 64)], ['correct', false], ['input_unchanged', false],
            ['input_contract', 'tolerant_decoded_in_place'], ['median_ms', 0.0], ['median_ms', INF],
            ['elapsed_ms', 2.0], ['admission', 'fallback'],
        ] as [$key, $value]) {
            $invalid = $observation;
            $invalid['result'][$key] = $value;
            self::assertFalse(eligibleObservation($invalid, 'rust', $fixture, $protocol), $key);
        }
        $observation['status'] = 'timeout';
        self::assertFalse(eligibleObservation($observation, 'rust', $fixture, $protocol));
    }

    public function test_owned_setup_failure_is_retained_without_touching_an_existing_directory(): void
    {
        $root = sys_get_temp_dir().'/nntmux-strict-controller-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $output = $root.'/evidence';
        try {
            ob_start();
            try {
                self::assertSame(2, run(['output-dir' => $output, 'samples' => '1']));
            } finally {
                ob_end_clean();
            }
            self::assertFileExists($output.'/owned.json');
            $failure = json_decode(file_get_contents($output.'/status.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('invalid', $failure['status']);
            self::assertFalse($failure['adoption_qualified']);
            $before = file_get_contents($output.'/status.json');
            ob_start();
            try {
                self::assertSame(2, run(['output-dir' => $output]));
            } finally {
                ob_end_clean();
            }
            self::assertSame($before, file_get_contents($output.'/status.json'));
        } finally {
            if (is_dir($output)) {
                foreach (['owned.json', 'status.json', 'observations.jsonl'] as $name) {
                    if (is_file($output.'/'.$name)) {
                        unlink($output.'/'.$name);
                    }
                }
                rmdir($output);
            }
            rmdir($root);
        }
    }

    public function test_statistics_cannot_qualify_a_subset_or_a_different_input(): void
    {
        $strata = [];
        $rows = [];
        foreach (expectedFixtureIds() as $id) {
            $family = str_starts_with($id, 'rare-') ? 'uncommon' : 'common';
            $count = $family === 'common' ? 31 : 2000;
            $strata[] = ['id' => $id, 'family' => $family, 'invalid_pairs' => 0, 'pairs' => array_fill(0, $count, [])];
            $rows[] = ['id' => $id, 'family' => $family, 'invalid_pairs' => 0, 'pairs' => $count,
                'qualified' => true, 'status' => 'qualified', 'interval' => ['lower' => 0.5, 'upper' => 0.5],
                'minimum_pairs' => $family === 'common' ? 31 : 2000, 'bootstrap_replicates' => 10000,
                'method' => $family === 'common' ? 'paired_bootstrap_median_of_rust_cpp_ratios' : 'paired_index_bootstrap_of_separate_nearest_rank_p99_ratio',
                'rust_over_cpp' => 0.5, 'observed_speedup' => 2.0, 'cpp_p99_ms' => $family === 'common' ? null : 2.0,
                'rust_p99_ms' => $family === 'common' ? null : 1.0];
        }
        $summary = ['protocol_sha256' => str_repeat('a', 64), 'input_sha256' => str_repeat('b', 64),
            'seed' => 42, 'input_complete' => true, 'declared_strata' => 42, 'required_speedup' => 1.5,
            'performance_thresholds_met' => true, 'strata' => $rows, 'schema_version' => 1, 'bootstrap_replicates' => 10000,
            'simultaneous_confidence' => 0.95, 'adoption_qualified' => false, 'per_tail_probability' => 0.025 / 42,
            'multiple_comparison_method' => 'Bonferroni across all declared strata including invalid/insufficient strata',
            'interval_method' => 'percentile; floor/ceil of p*(B-1), zero-based', 'interval_lower_index' => 5, 'interval_upper_index' => 9994,
            'p99_method' => 'nearest rank ceil(99*n/100), independently for CPP and Rust within paired-index resamples',
            'maximum_rust_over_cpp' => 2 / 3, 'rng' => 'local_splitmix64_rejection_indices'];
        self::assertTrue(statisticsMatches($summary, $strata, str_repeat('a', 64), str_repeat('b', 64), 42));
        foreach (['rust_p99_ms' => 100.0, 'observed_speedup' => 99.0] as $key => $value) {
            $contradictory = $summary;
            $contradictory['strata'][41][$key] = $value;
            self::assertFalse(statisticsMatches($contradictory, $strata, str_repeat('a', 64), str_repeat('b', 64), 42), $key);
        }
        foreach ([['cpp_p99_ms' => 5e-324, 'rust_p99_ms' => 1e308], ['rust_over_cpp' => 5e-324],
            ['cpp_p99_ms' => 1e308, 'rust_p99_ms' => 5e-324]] as $values) {
            $contradictory = $summary;
            $contradictory['strata'][41] = array_replace($contradictory['strata'][41], $values);
            self::assertFalse(statisticsMatches($contradictory, $strata, str_repeat('a', 64), str_repeat('b', 64), 42));
        }
        $stale = $summary;
        unset($stale['bootstrap_replicates']);
        self::assertFalse(statisticsMatches($stale, $strata, str_repeat('a', 64), str_repeat('b', 64), 42));
        $stale = $summary;
        $stale['strata'][41]['method'] = 'paired_bootstrap_median_of_rust_cpp_ratios';
        self::assertFalse(statisticsMatches($stale, $strata, str_repeat('a', 64), str_repeat('b', 64), 42));
        self::assertFalse(statisticsMatches($summary, $strata, str_repeat('a', 64), str_repeat('c', 64), 42));
        $summary['strata'][41] = $summary['strata'][0];
        self::assertFalse(statisticsMatches($summary, $strata, str_repeat('a', 64), str_repeat('b', 64), 42));
        $summary['strata'] = $rows;
        $summary['strata'][41]['interval']['upper'] = 0.8;
        self::assertFalse(statisticsMatches($summary, $strata, str_repeat('a', 64), str_repeat('b', 64), 42));
        $summary['strata'] = $rows;
        $summary['strata'][0]['invalid_pairs'] = 1;
        self::assertFalse(statisticsMatches($summary, $strata, str_repeat('a', 64), str_repeat('b', 64), 42));
    }

    public function test_recorded_failed_or_conflicting_builds_are_not_eligible(): void
    {
        $keys = ['RUSTFLAGS', 'CARGO_ENCODED_RUSTFLAGS', 'CARGO_BUILD_RUSTFLAGS', 'CARGO_PROFILE_RELEASE_OPT_LEVEL',
            'CARGO_PROFILE_RELEASE_LTO', 'CARGO_PROFILE_RELEASE_CODEGEN_UNITS', 'CARGO_PROFILE_RELEASE_DEBUG',
            'CARGO_PROFILE_RELEASE_DEBUG_ASSERTIONS', 'CARGO_BUILD_TARGET', 'CARGO_TARGET_DIR', 'RUSTC',
            'RUSTC_WRAPPER', 'RUSTC_WORKSPACE_WRAPPER', 'CARGO_PROFILE_RELEASE_PANIC'];
        $manifest = ['rust_build_receipt' => ['exit_code' => 0, 'argv' => ['cargo', '+1.98.0', 'build', '--release', '--locked',
            '--features', 'experimental-strict-article', '--manifest-path', 'docker/app/nzb-yenc-ffi/Cargo.toml'],
            'environment' => array_fill_keys($keys, null)]];
        self::assertTrue(optimizedBuildReceiptMatches($manifest));
        self::assertFalse(optimizedBuildReceiptMatches([]));
        foreach (['exit_code' => 9, 'argv' => ['cargo', 'build'], 'environment' => []] as $key => $value) {
            $bad = $manifest;
            $bad['rust_build_receipt'][$key] = $value;
            self::assertFalse(optimizedBuildReceiptMatches($bad), $key);
        }
        foreach ($keys as $key) {
            $bad = $manifest;
            $bad['rust_build_receipt']['environment'][$key] = 'conflicting override';
            self::assertFalse(optimizedBuildReceiptMatches($bad), $key);
        }
    }

    public function test_git_identity_cannot_be_redirected_by_inherited_environment(): void
    {
        $root = dirname(__DIR__, 2);
        $expected = trim(gitAtRoot($root, ['rev-parse', 'HEAD']));
        $priorDirectory = getenv('GIT_DIR');
        $priorTree = getenv('GIT_WORK_TREE');
        $priorEnv = $_ENV;
        $priorServer = $_SERVER;
        try {
            putenv('GIT_DIR=/no/such/foreign-git-directory');
            putenv('GIT_WORK_TREE=/no/such/foreign-checkout');
            self::assertSame($expected, trim(gitAtRoot($root, ['rev-parse', 'HEAD'])));
            putenv('GIT_DIR');
            putenv('GIT_WORK_TREE');
            $_ENV['GIT_DIR'] = '/no/such/env-only-git-directory';
            $_SERVER['GIT_WORK_TREE'] = '/no/such/server-only-checkout';
            self::assertSame($expected, trim(gitAtRoot($root, ['rev-parse', 'HEAD'])));
        } finally {
            $_ENV = $priorEnv;
            $_SERVER = $priorServer;
            putenv($priorDirectory === false ? 'GIT_DIR' : 'GIT_DIR='.$priorDirectory);
            putenv($priorTree === false ? 'GIT_WORK_TREE' : 'GIT_WORK_TREE='.$priorTree);
        }
    }

    public function test_statistics_timeout_keeps_diagnostics_and_exit_receipt(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-statistics-timeout-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $process = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT,"ready"); fwrite(STDERR,"diagnostic"); usleep(5000000);']);
            $process->setTimeout(1);
            try {
                runStatistics($process, $directory);
                self::fail('Expected owned statistics child timeout.');
            } catch (ProcessTimedOutException) {
                self::assertSame('ready', file_get_contents($directory.'/statistics.stdout'));
                self::assertSame('diagnostic', file_get_contents($directory.'/statistics.stderr'));
                $receipt = json_decode(file_get_contents($directory.'/statistics-process.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertTrue($receipt['timed_out']);
            }
        } finally {
            foreach (['statistics.stdout', 'statistics.stderr', 'statistics-process.json'] as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            rmdir($directory);
        }
    }

    public function test_statistics_start_failure_keeps_original_error_and_receipt(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-statistics-start-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $process = new Process([PHP_BINARY, '-r', 'echo "unused";'], $directory.'/missing');
            try {
                runStatistics($process, $directory);
                self::fail('Expected original process start failure.');
            } catch (\Symfony\Component\Process\Exception\RuntimeException $failure) {
                $receipt = json_decode(file_get_contents($directory.'/statistics-process.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertFalse($receipt['started']);
                self::assertSame($failure::class, $receipt['error_class']);
                self::assertSame($failure->getMessage(), $receipt['error']);
                self::assertSame('', file_get_contents($directory.'/statistics.stdout'));
                self::assertSame('', file_get_contents($directory.'/statistics.stderr'));
            }
        } finally {
            foreach (['statistics.stdout', 'statistics.stderr', 'statistics-process.json'] as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            rmdir($directory);
        }
    }

    public function test_one_worker_timeout_prevents_all_later_worker_launches(): void
    {
        $stop = null;
        $deadline = hrtime(true) + 10_000_000_000;
        $timedOut = ['status' => 'timeout', 'timed_out' => true, 'stdout_path' => 'retained.stdout'];
        self::assertSame($timedOut, scheduledChild(static fn (): array => $timedOut, [], 'first', 30, $deadline, $stop));
        self::assertSame('worker_timeout', $stop);
        $mustNotRun = static fn (): array => throw new \RuntimeException('A later worker was launched after timeout.');
        self::assertNull(scheduledChild($mustNotRun, [], 'second', 30, $deadline, $stop));
        self::assertNull(scheduledChild($mustNotRun, [], 'another-stratum', 30, $deadline, $stop));
        $stop = null;
        self::assertNull(scheduledChild($mustNotRun, [], 'expired-budget', 30, hrtime(true) - 1, $stop));
        self::assertSame('worker_scheduling_budget_exhausted', $stop);
    }
}
