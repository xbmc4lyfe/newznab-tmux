<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\VendorArchiveBenchmarkFixtures;

require_once dirname(__DIR__).'/Fixtures/VendorArchiveBenchmarkFixtures.php';
if (! defined('NNTMUX_VENDOR_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY')) {
    define('NNTMUX_VENDOR_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY', true);
}
require_once dirname(__DIR__, 2).'/scripts/benchmark-vendor-archive.php';

final class VendorArchiveBenchmarkTest extends TestCase
{
    public function test_all_vendor_archive_native_callers_match_independent_oracles_with_execution_proof(): void
    {
        $library = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! extension_loaded('FFI') || ! is_string($library) || $library === '') {
            self::markTestSkipped('Set NNTMUX_TEST_NATIVE_LIBRARY on Linux for actual native execution.');
        }
        self::assertFileExists($library);
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/vendor-native-unit-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            foreach (VendorArchiveBenchmarkFixtures::strata() as $name => $definition) {
                $fixture = VendorArchiveBenchmarkFixtures::make($name, $root);
                $path = $directory.'/'.$name.'.fixture';
                file_put_contents($path, serialize($fixture));
                $output = $directory.'/'.$name.'.result';
                $command = [...articleBenchmarkPhpCommand(), '-d', 'ffi.enable=true', $root.'/scripts/benchmark-vendor-archive.php',
                    '--worker=native', '--source-dir='.$root, '--native='.$library, '--fixture-path='.$path, '--result-output='.$output];
                $child = yencBenchmarkRunChild($command, $directory.'/'.$name, 30);
                self::assertSame('ok', $child['status'], $name.': '.json_encode($child));
                self::assertSame(hash_file('sha256', $library), $child['result']['library_sha256']);
                self::assertTrue($child['result']['loaded_runtime_stable']);
                $retained = archiveBenchmarkReadResults($output);
                self::assertNotNull($retained);
                foreach (['cold', 'warmup', 'warm'] as $phase) {
                    self::assertTrue($child['result']['observations'][$phase]['correct'], $name.' '.$phase);
                    self::assertTrue($child['result']['observations'][$phase]['backend_proven']);
                    self::assertTrue($child['result']['observations'][$phase]['native_library_loaded']);
                    self::assertSame('nntmux-rust-ffi', $retained[$phase]['parser_engine']);
                    self::assertSame($fixture['oracle'], VendorArchiveBenchmarkFixtures::projection($retained[$phase], $definition['format']));
                }
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    public function test_locked_vendor_apis_match_independent_header_oracles_for_all_eight_strata(): void
    {
        $counts = ['common' => 0, 'uncommon' => 0];
        foreach (VendorArchiveBenchmarkFixtures::strata() as $name => $definition) {
            $counts[$definition['category']]++;
            $fixture = VendorArchiveBenchmarkFixtures::make($name, dirname(__DIR__, 2));
            $result = VendorArchiveBenchmarkFixtures::parseVendor($fixture['format'], $fixture['bytes']);
            self::assertSame($definition['sha256'], hash('sha256', $fixture['bytes']), $name);
            self::assertTrue($result['success'], $name);
            self::assertArrayNotHasKey('error', $result['summary'], $name);
            self::assertSame($fixture['oracle'], VendorArchiveBenchmarkFixtures::projection($result, $fixture['format']), $name);
            self::assertTrue(VendorArchiveBenchmarkFixtures::correct($fixture, $result), $name);
            self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, null), $name);
            $result['success'] = false;
            self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, $result), $name);
        }
        self::assertSame(['common' => 4, 'uncommon' => 4], $counts);
    }

    public function test_large_rar5_reads_complete_archive_instead_of_default_one_megabyte_prefix(): void
    {
        $fixture = VendorArchiveBenchmarkFixtures::make('rar5-mixed', dirname(__DIR__, 2));
        $result = VendorArchiveBenchmarkFixtures::parseVendor('rar5', $fixture['bytes']);
        self::assertSame(4195430, $result['summary']['data_size']);
        self::assertSame(4, $result['summary']['file_count']);
        self::assertSame(['testdir/4mb.txt', 'testdir', 'compressed.txt', 'bar.txt'], array_column($result['summary']['file_list'], 'name'));
        $result['summary']['data_size'] = 1048576;
        self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, $result));
    }

    public function test_oracle_checks_header_checksums_independently_of_vendor_success(): void
    {
        foreach (['rar4-stored' => 10, 'rar5-mixed' => 15, 'seven-stored' => 28] as $name => $offset) {
            $fixture = VendorArchiveBenchmarkFixtures::make($name, dirname(__DIR__, 2));
            $bytes = $fixture['bytes'];
            $bytes[$offset] = chr(ord($bytes[$offset]) ^ 1);
            try {
                VendorArchiveBenchmarkFixtures::oracle($fixture['format'], $bytes);
                self::fail('Header mutation must fail independent CRC validation: '.$name);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('checksum', $exception->getMessage());
            }
        }
    }

    public function test_projection_rejects_wrong_field_types_and_preserves_backend_extras(): void
    {
        $fixture = VendorArchiveBenchmarkFixtures::make('zip-aes', dirname(__DIR__, 2));
        $result = VendorArchiveBenchmarkFixtures::parseVendor('zip', $fixture['bytes']);
        $before = serialize($result);
        $projection = VendorArchiveBenchmarkFixtures::projection($result, 'zip');
        self::assertSame(99, $projection['method']);
        self::assertSame(0, $projection['crc32']);
        self::assertSame($before, serialize($result));
        self::assertCount(3, $result['records']);
        self::assertArrayHasKey('file_list', $result['summary']);
        $result['records'][0]['method'] = '99';
        self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, $result));
        $result['records'][0]['method'] = 99;
        $result['summary']['error'] = 'test error';
        self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, $result));
    }

    public function test_native_projection_needs_complete_checked_header_and_matching_input_identity(): void
    {
        $fixture = VendorArchiveBenchmarkFixtures::make('rar4-solid', dirname(__DIR__, 2));
        $result = ['parser_engine' => 'nntmux-rust-ffi', 'candidate_scan_truncated_formats' => [],
            'sample_prefix_identity' => ['md5' => md5(substr($fixture['bytes'], 0, 16384))],
            'headers' => [['format' => 'rar4', 'offset' => 0, 'main_header_type' => 115, 'main_header_flags' => 8,
                'main_header_size' => 13, 'main_header_available' => true, 'main_header_checksum_valid' => true]]];
        self::assertTrue(VendorArchiveBenchmarkFixtures::correct($fixture, $result));
        $result['headers'][0]['main_header_checksum_valid'] = false;
        self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, $result));
        $result['headers'][0]['main_header_checksum_valid'] = true;
        $result['headers'][] = $result['headers'][0];
        self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, $result));
        array_pop($result['headers']);
        $result['sample_prefix_identity']['md5'] = str_repeat('0', 32);
        self::assertFalse(VendorArchiveBenchmarkFixtures::correct($fixture, $result));
    }

    public function test_frozen_vendor_receipt_and_sources_include_actual_readers_and_all_fixture_hashes(): void
    {
        $root = dirname(__DIR__, 2);
        $receipt = vendorArchiveBenchmarkVendorReceipt($root);
        self::assertSame('dariusiii/rarinfo', $receipt['package']);
        self::assertSame('v3.1', $receipt['version']);
        self::assertContains('BSD-3-Clause', $receipt['license']);
        $sources = vendorArchiveBenchmarkSources($root);
        foreach (['ArchiveReader', 'RarInfo', 'ZipInfo', 'SzipInfo'] as $class) {
            $path = 'vendor/dariusiii/rarinfo/'.$class.'.php';
            self::assertSame(hash_file('sha256', $root.'/'.$path), $sources[$path]);
            self::assertSame($sources[$path], $receipt['files'][$path]);
        }
        foreach (VendorArchiveBenchmarkFixtures::strata() as $definition) {
            self::assertSame($definition['sha256'], $sources[$definition['path']]);
        }
        self::assertArrayHasKey('composer.lock', $receipt['files']);
        self::assertArrayHasKey('vendor/composer/installed.json', $receipt['files']);
    }

    public function test_decision_requires_all_samples_and_uses_32_marginals_for_both_phases(): void
    {
        $baseline = array_fill(0, 1000, 3.0);
        $candidate = array_fill(0, 1000, 1.0);
        $decision = vendorArchiveBenchmarkDecision($baseline, $candidate, 0, 'uncommon', 'qualification');
        self::assertSame('p99_latency', $decision['metric']);
        self::assertSame(0.05 / 32, $decision['marginal_alpha']);
        self::assertTrue($decision['qualifies']);
        self::assertSame('median_latency', vendorArchiveBenchmarkDecision($baseline, $candidate, 0, 'common', 'qualification')['metric']);
        self::assertFalse(vendorArchiveBenchmarkDecision($baseline, $candidate, 0, 'uncommon', 'screen')['qualifies']);
        self::assertFalse(vendorArchiveBenchmarkDecision(array_slice($baseline, 1), array_slice($candidate, 1), 0, 'uncommon', 'qualification')['qualifies']);
        self::assertFalse(vendorArchiveBenchmarkDecision($baseline, $candidate, 1, 'uncommon', 'qualification')['qualifies']);
        self::assertFalse(vendorArchiveBenchmarkDecision([NAN], [1.0], 0, 'common', 'qualification')['qualifies']);
        self::assertFalse(vendorArchiveBenchmarkDecision([1.0], [0.0], 0, 'common', 'qualification')['qualifies']);
    }

    public function test_eligibility_rechecks_retained_outputs_and_rejects_vendor_output_claimed_as_native(): void
    {
        $directory = sys_get_temp_dir().'/vendor-archive-unit-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $fixture = VendorArchiveBenchmarkFixtures::make('zip-stored', dirname(__DIR__, 2));
        $actual = VendorArchiveBenchmarkFixtures::parseVendor('zip', $fixture['bytes']);
        $encoded = serialize($actual);
        $reference = archiveBenchmarkStore($encoded, $directory.'/results', 'serialized');
        $path = $directory.'/receipt.serialized';
        file_put_contents($path, serialize(array_fill_keys(['cold', 'warmup', 'warm'], $reference)));
        $runtime = ['root' => '/frozen', 'files' => ['source.php' => 'digest'], 'git_head' => null, 'git_tree' => null];
        $row = ['elapsed_ms' => 1.0, 'correct' => true, 'backend_proven' => true,
            'native_library_loaded' => false, 'parser_engine' => null, 'result_sha256' => hash('sha256', $encoded)];
        $child = ['status' => 'ok', 'stdout_truncated_for_json' => false, 'result' => [
            'backend' => 'php', 'fixture_sha256' => hash('sha256', serialize($fixture)), 'instrumentation' => [],
            'loaded_runtime_stable' => true, 'runtime_sources' => $runtime, 'runtime_sources_before' => $runtime,
            'result_path' => $path, 'result_sha256' => hash_file('sha256', $path),
            'observations' => array_fill_keys(['cold', 'warmup', 'warm'], $row),
        ]];
        try {
            self::assertTrue(vendorArchiveBenchmarkEligible($child, 'php', $fixture, 'library-hash', $path, $runtime, []));
            $falseNative = $child;
            $falseNative['result']['backend'] = 'native';
            $falseNative['result']['library_sha256'] = 'library-hash';
            $falseNative['result']['library_sha256_before'] = 'library-hash';
            foreach (['cold', 'warmup', 'warm'] as $phase) {
                $falseNative['result']['observations'][$phase]['native_library_loaded'] = true;
                $falseNative['result']['observations'][$phase]['parser_engine'] = 'nntmux-rust-ffi';
            }
            self::assertFalse(vendorArchiveBenchmarkEligible($falseNative, 'native', $fixture, 'library-hash', $path, $runtime, []));
            $child['result']['observations']['warm']['backend_proven'] = false;
            self::assertFalse(vendorArchiveBenchmarkEligible($child, 'php', $fixture, 'library-hash', $path, $runtime, []));
            $child['result']['observations']['warm']['backend_proven'] = true;
            file_put_contents($reference['path'], 'tampered retained result');
            self::assertFalse(vendorArchiveBenchmarkEligible($child, 'php', $fixture, 'library-hash', $path, $runtime, []));
        } finally {
            unlink($reference['path']);
            unlink($path);
            rmdir($directory.'/results');
            rmdir($directory);
        }
    }

    public function test_qualification_rejects_too_few_pairs_before_creating_artifacts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1000');
        vendorArchiveBenchmarkRun(['mode' => 'qualification', 'samples' => 999]);
    }
}
