<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Yenc\PhpPayloadDecoder;
use App\Services\YencService;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArticleProcessingBenchmarkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('NNTMUX_ARTICLE_BENCHMARK_FUNCTIONS_ONLY')) {
            define('NNTMUX_ARTICLE_BENCHMARK_FUNCTIONS_ONLY', true);
            require dirname(__DIR__, 2).'/scripts/benchmark-article-processing.php';
        }
    }

    public function test_profile_options_are_bounded_and_keep_default_fixture_family(): void
    {
        $options = articleBenchmarkOptions(['profile' => false, 'native' => '/native.so']);
        self::assertSame([4096, 524288, 1048576], $options['sizes']);
        self::assertSame(7, $options['samples']);
        self::assertSame(30.0, $options['timeout']);
        self::assertSame([64], articleBenchmarkOptions(['native' => '/native.so', 'size' => '64'])['sizes']);
    }

    public function test_invalid_sizes_cannot_escape_metadata_parser_budget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['native' => '/native.so', 'size' => '1048577']);
    }

    public function test_non_numeric_samples_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['native' => '/native.so', 'samples' => 'seven']);
    }

    public function test_native_mode_cannot_silently_fall_back(): void
    {
        $this->expectException(InvalidArgumentException::class);
        articleBenchmarkOptions(['profile' => false]);
    }

    public function test_fixture_lines_and_independent_decoded_bytes_are_consistent(): void
    {
        foreach (['ordinary', 'heavy'] as $density) {
            $fixture = articleBenchmarkFixture(4096, $density);
            self::assertSame(4096, strlen($fixture['data']));
            self::assertSame('Lines: '.substr_count($fixture['body'], "\n")."\r\n", $fixture['headers'][5]);
            self::assertStringEndsWith("\r\n.\r\n", $fixture['wire']);
            $service = new YencService(new PhpPayloadDecoder);
            self::assertSame($fixture['data'], $service->decode($fixture['body']));
        }
    }

    public function test_maximum_heavy_fixture_stays_within_both_native_body_budgets(): void
    {
        $fixture = articleBenchmarkFixture(1048576, 'heavy');
        self::assertLessThanOrEqual(2000000, strlen($fixture['body']));
        self::assertSame(1048576, strlen($fixture['data']));
    }

    public function test_failed_native_initialization_restores_previous_container(): void
    {
        $previous = Container::getInstance();
        try {
            articleBenchmarkWorker('full-analyzer', articleBenchmarkFixture(64, 'ordinary'), '/missing/native-library.so', 1);
            self::fail('Strict native initialization unexpectedly succeeded.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Native yEnc is unavailable', $exception->getMessage());
        }
        self::assertSame($previous, Container::getInstance());
    }

    public function test_exact_result_hash_preserves_array_order_and_value_types(): void
    {
        self::assertNotSame(articleBenchmarkResultHash(['first' => 1, 'second' => 2]), articleBenchmarkResultHash(['second' => 2, 'first' => 1]));
        self::assertNotSame(articleBenchmarkResultHash(['value' => 1]), articleBenchmarkResultHash(['value' => '1']));
        self::assertSame(articleBenchmarkResultHash(['bytes' => "\xff\0"]), articleBenchmarkResultHash(['bytes' => "\xff\0"]));
    }

    public function test_profile_retains_every_failed_worker_without_claiming_an_advantage(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-article-failure-test-'.bin2hex(random_bytes(8));
        ob_start();
        try {
            $options = ['native' => '/missing/native.so', 'samples' => 1, 'size' => 64, 'output-dir' => $directory];
            self::assertSame(2, articleBenchmarkProfile($options, articleBenchmarkOptions($options)));
            $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory.'/observations.jsonl', FILE_IGNORE_NEW_LINES));
            $summary = $rows[array_key_last($rows)];
            self::assertSame('invalid', $summary['status']);
            self::assertSame(14, $summary['invalid_observations']);
            self::assertFalse($summary['performance_advantage_demonstrated']);
            self::assertCount(14, glob($directory.'/*.stderr'));
            self::assertCount(14, glob($directory.'/*.stdout'));
            foreach (glob($directory.'/*.stderr') as $path) {
                self::assertStringContainsString('Native yEnc is unavailable', (string) file_get_contents($path));
            }
        } finally {
            ob_end_clean();
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_real_native_worker_checks_every_phase_and_retains_cpu_observations(): void
    {
        $library = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! is_string($library) || ! is_file($library)) {
            self::markTestSkipped('Set NNTMUX_TEST_NATIVE_LIBRARY on Linux for the real native caller test.');
        }
        $directory = sys_get_temp_dir().'/nntmux-article-worker-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $cases = array_map(static fn (string $phase): array => [$phase, 4096, 'ordinary'], articleBenchmarkPhases());
            $cases[] = ['full-analyzer', 1048576, 'heavy'];
            foreach ($cases as [$phase, $size, $density]) {
                $prefix = $directory.'/'.$phase.'-'.$size;
                file_put_contents($prefix.'.fixture', serialize(articleBenchmarkFixture($size, $density)));
                $child = yencBenchmarkRunChild(array_merge(articleBenchmarkPhpCommand(), [dirname(__DIR__, 2).'/scripts/benchmark-article-processing.php',
                    '--worker='.$phase, '--native='.$library, '--samples=1', '--fixture-path='.$prefix.'.fixture',
                    '--result-output='.$prefix.'.metadata']), $prefix, 30);
                self::assertSame('ok', $child['status'], (string) file_get_contents($child['stderr_path']));
                self::assertFalse($child['stdout_truncated_for_json']);
                $result = $child['result'];
                if ($result['reference_metadata_path'] !== null) {
                    self::assertSame($prefix.'.metadata', $result['reference_metadata_path']);
                    self::assertSame($result['reference_metadata_sha256'], hash_file('sha256', $prefix.'.metadata'));
                    self::assertIsArray(unserialize((string) file_get_contents($prefix.'.metadata'), ['allowed_classes' => false]));
                }
                self::assertSame('diagnostic_only', $result['evidence']);
                self::assertCount(2, $result['observations']);
                foreach ($result['observations'] as $observation) {
                    self::assertTrue($observation['correct']);
                    self::assertSame($result['reference_sha256'], $observation['result_sha256']);
                    self::assertArrayHasKey('ru_utime.tv_usec', $observation['process_cpu_before']);
                    self::assertArrayHasKey('ru_stime.tv_usec', $observation['process_cpu_after']);
                }
            }
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
