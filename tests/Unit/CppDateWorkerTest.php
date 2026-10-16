<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\RemainingMetadataBenchmarkFixtures;

final class CppDateWorkerTest extends TestCase
{
    public function test_real_cpp_date_worker_retains_all_four_projections_and_execution_proof(): void
    {
        $library = getenv('NNTMUX_TEST_DATE_CPP_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! extension_loaded('FFI') || ! is_string($library) || $library === '') {
            self::markTestSkipped('Set NNTMUX_TEST_DATE_CPP_LIBRARY on Linux with FFI for the real C++ worker.');
        }
        self::assertFileExists($library);
        $library = realpath($library);
        self::assertIsString($library);
        $libraryHash = hash_file('sha256', $library);
        self::assertIsString($libraryHash);
        $root = dirname(__DIR__, 2);
        if (! defined('NNTMUX_REMAINING_BENCHMARK_FUNCTIONS_ONLY')) {
            define('NNTMUX_REMAINING_BENCHMARK_FUNCTIONS_ONLY', true);
        }
        require_once $root.'/scripts/benchmark-remaining-modules.php';
        $directory = sys_get_temp_dir().'/cpp-date-worker-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        try {
            $strata = remainingBenchmarkStrata('date-cpp');
            self::assertSame(['dates-ordinary', 'dates-folded', 'dates-uncommon-duplicates', 'dates-uncommon-malformed'], array_keys($strata));
            foreach ($strata as $name => $definition) {
                $fixture = RemainingMetadataBenchmarkFixtures::make($name);
                $fixturePath = $directory.'/'.$name.'.fixture.serialized';
                $fixtureBytes = serialize($fixture);
                self::assertSame(strlen($fixtureBytes), file_put_contents($fixturePath, $fixtureBytes));
                $journal = $directory.'/'.$name.'.phases.jsonl';
                // Exercise the public worker CLI once per stratum. Its cold,
                // warmup and warm calls prove correctness, not a speed claim.
                $child = yencBenchmarkRunChild([...articleBenchmarkPhpCommand(), $root.'/scripts/benchmark-remaining-modules.php',
                    '--family=date-cpp', '--worker=cpp', '--source-dir='.$root, '--native='.$library,
                    '--fixture-path='.$fixturePath, '--result-store='.$directory, '--phase-journal='.$journal],
                    $directory.'/'.$name.'.child', 15.0);
                self::assertSame('ok', $child['status'], $child['parse_error'] ?? $child['status']);
                $result = $child['result'];
                self::assertSame('cpp', $result['backend']);
                self::assertSame($libraryHash, $result['library_sha256']);
                self::assertSame(hash('sha256', $fixtureBytes), hash_file('sha256', $fixturePath));
                foreach (['cold', 'warmup', 'warm'] as $phase) {
                    $row = $result['observations'][$phase];
                    self::assertTrue($row['correct'], $name.' '.$phase);
                    self::assertTrue($row['selected_library_cached'], $name.' '.$phase);
                    self::assertSame('nntmux-cpp-nzbget-date-reference', $row['parser_engine']);
                    foreach (['projection', 'full'] as $key) {
                        self::assertSame($row[$key]['sha256'], hash_file('sha256', $row[$key]['path']));
                    }
                    self::assertSame($fixture['expected'], unserialize(file_get_contents($row['projection']['path']), ['allowed_classes' => false]));
                    self::assertSame(RemainingMetadataBenchmarkFixtures::expectedFullDates($fixture),
                        RemainingMetadataBenchmarkFixtures::normalizeFullDates(unserialize(file_get_contents($row['full']['path']), ['allowed_classes' => false])));
                }
                $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($journal, FILE_IGNORE_NEW_LINES));
                self::assertSame(['cold', 'warmup', 'warm'], array_column($rows, 'phase'));
                $snapshot = ['root' => $root, 'files' => $result['runtime_sources']['files']];
                $arguments = ['cpp', $definition['module'], hash('sha256', $fixtureBytes), $libraryHash,
                    $directory, $snapshot, remainingBenchmarkInstrumentation(), $journal];
                self::assertTrue(remainingBenchmarkEligible($child, ...$arguments), $name);
                $changed = $child;
                $changed['result']['observations']['cold']['selected_library_cached'] = false;
                self::assertFalse(remainingBenchmarkEligible($changed, ...$arguments));
                $changed = $child;
                $changed['result']['library_sha256'] = str_repeat('0', 64);
                self::assertFalse(remainingBenchmarkEligible($changed, ...$arguments));
            }
            self::assertSame($libraryHash, hash_file('sha256', $library));
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
