<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\Par2MetadataParser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\ArchiveParsingBenchmarkFixtures;

require_once dirname(__DIR__).'/Fixtures/ArchiveParsingBenchmarkFixtures.php';
if (! defined('NNTMUX_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY')) {
    define('NNTMUX_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY', true);
}
require_once dirname(__DIR__, 2).'/scripts/benchmark-archive-parsing.php';
define('NNTMUX_PAR2_NORMALIZATION_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__, 2).'/scripts/benchmark-par2-normalization.php';

final class ArchiveParsingBenchmarkTest extends TestCase
{
    public function test_normalization_comparison_requires_full_types_order_and_all_phases(): void
    {
        $original = array_fill_keys(['cold', 'warmup', 'warm'], ['a' => 1, 'b' => 1.0]);
        self::assertTrue(par2NormalizationFullParity($original, $original));
        $candidate = $original;
        $candidate['warm'] = ['b' => 1.0, 'a' => 1];
        self::assertFalse(par2NormalizationFullParity($original, $candidate));
        $candidate['warm'] = ['a' => 1, 'b' => 1];
        self::assertFalse(par2NormalizationFullParity($original, $candidate));
        unset($candidate['warmup']);
        self::assertFalse(par2NormalizationFullParity($original, $candidate));
        self::assertFalse(par2NormalizationFullParity(null, $original));
    }

    public function test_public_fixture_identity_and_independent_inventory(): void
    {
        $fixture = ArchiveParsingBenchmarkFixtures::make('public-rename-index', dirname(__DIR__, 2));
        self::assertSame(ArchiveParsingBenchmarkFixtures::PUBLIC_SHA256, $fixture['input_sha256']);
        self::assertSame(6444, strlen($fixture['bytes']));
        self::assertSame(22, $fixture['oracle']['packet_count']);
        self::assertSame(10, $fixture['oracle']['source_file_count']);
        self::assertSame(200, $fixture['oracle']['source_block_count']);
        self::assertSame(1048576, $fixture['oracle']['source_file_bytes']);
    }

    public function test_synthetic_metadata_describes_real_payload_and_padded_slice_checksums(): void
    {
        $fixture = ArchiveParsingBenchmarkFixtures::make('synthetic-128', dirname(__DIR__, 2));
        $data = str_repeat('fixture payload ', 1200);
        $records = md5(substr($data, 0, 16384), true).pack('V', crc32(substr($data, 0, 16384)));
        $tail = substr($data, 16384).str_repeat("\0", 16384 - (strlen($data) - 16384));
        $records .= md5($tail, true).pack('V', crc32($tail));
        self::assertSame(19200 * 128, $fixture['oracle']['source_file_bytes']);
        self::assertSame(256, $fixture['oracle']['source_block_count']);
        foreach ($fixture['oracle']['source_files'] as $source) {
            self::assertSame(19200, $source['file_length']);
            self::assertSame(md5($data), $source['md5']);
            self::assertSame(md5(substr($data, 0, 16384)), $source['md5_first_16k']);
            self::assertNotSame($source['md5'], $source['md5_first_16k']);
        }
        foreach ($fixture['oracle']['input_file_slice_checksums'] as $ifsc) {
            self::assertSame(2, $ifsc['slice_count']);
            self::assertSame(hash('sha256', $records), $ifsc['checksum_records_sha256']);
        }
    }

    public function test_actual_php_fallback_matches_independent_oracle_for_all_paired_strata(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => ['native_library' => '']]));
        Container::setInstance($container);
        try {
            foreach (ArchiveParsingBenchmarkFixtures::strata() as $name => $definition) {
                if (! $definition['paired']) {
                    continue;
                }
                $fixture = ArchiveParsingBenchmarkFixtures::make($name, dirname(__DIR__, 2));
                $result = (new Par2MetadataParser)->parse($fixture['bytes']);
                self::assertSame('php-fallback', $result['parser_engine']);
                self::assertSame($fixture['oracle'], ArchiveParsingBenchmarkFixtures::projection($result), $name);
                self::assertTrue(ArchiveParsingBenchmarkFixtures::correct($fixture, $result));
                $result['source_files'][0]['md5'] = str_repeat('0', 32);
                self::assertFalse(ArchiveParsingBenchmarkFixtures::correct($fixture, $result));
            }
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_packet_type_spelling_projection_is_narrow_and_collision_safe(): void
    {
        $fixture = ArchiveParsingBenchmarkFixtures::make('synthetic-128', dirname(__DIR__, 2));
        $native = $fixture['oracle'];
        $native['packet_type_counts'] = [];
        foreach ($fixture['oracle']['packet_type_counts'] as $type => $count) {
            $native['packet_type_counts'][str_replace("\0", '\\0', $type)] = $count;
        }
        self::assertSame($fixture['oracle'], ArchiveParsingBenchmarkFixtures::projection($native));
        $native['packet_type_counts']["PAR 2.0\0Main"] = 1;
        self::assertFalse(ArchiveParsingBenchmarkFixtures::correct($fixture, $native));
        $native['packet_type_counts']['PAR 2.0\\0Unknown'] = 1;
        self::assertFalse(ArchiveParsingBenchmarkFixtures::correct($fixture, $native));
    }

    public function test_packet_mutation_is_rejected_independently(): void
    {
        $bytes = ArchiveParsingBenchmarkFixtures::par2(2);
        $bytes[80] = chr(ord($bytes[80]) ^ 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('packet MD5');
        ArchiveParsingBenchmarkFixtures::par2Oracle($bytes);
    }

    public function test_rehashed_invalid_file_id_is_still_rejected(): void
    {
        $bytes = ArchiveParsingBenchmarkFixtures::par2(1);
        $offset = unpack('V', substr($bytes, 8, 4))[1];
        $length = unpack('V', substr($bytes, $offset + 8, 4))[1];
        $bytes[$offset + 64] = chr(ord($bytes[$offset + 64]) ^ 1);
        $bytes = substr_replace($bytes, md5(substr($bytes, $offset + 32, $length - 32), true), $offset + 16, 16);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('derived file ID');
        ArchiveParsingBenchmarkFixtures::par2Oracle($bytes);
    }

    public function test_truncation_is_rejected(): void
    {
        $bytes = substr(ArchiveParsingBenchmarkFixtures::par2(1), 0, -1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extent');
        ArchiveParsingBenchmarkFixtures::par2Oracle($bytes);
    }

    public function test_inputs_are_stable_and_diagnostics_never_offer_a_baseline(): void
    {
        $paired = 0;
        foreach (ArchiveParsingBenchmarkFixtures::strata() as $name => $definition) {
            $first = ArchiveParsingBenchmarkFixtures::make($name, dirname(__DIR__, 2));
            $second = ArchiveParsingBenchmarkFixtures::make($name, dirname(__DIR__, 2));
            self::assertSame(serialize($first), serialize($second));
            self::assertLessThan(400000, strlen($first['bytes']));
            self::assertSame(hash('sha256', $first['bytes']), $first['input_sha256']);
            if ($definition['paired']) {
                $paired++;
                self::assertSame('par2', $definition['operation']);
            } else {
                self::assertSame('diagnostic', $definition['category']);
                self::assertFalse(ArchiveParsingBenchmarkFixtures::correct($first, null));
                $fake = $first['oracle'];
                self::assertTrue(ArchiveParsingBenchmarkFixtures::correct($first, $fake));
                $first['bytes'] .= 'mutated';
                self::assertFalse(ArchiveParsingBenchmarkFixtures::correct($first, $fake));
            }
        }
        self::assertSame(4, $paired);
    }

    public function test_diagnostic_oracles_include_valid_crc_truncation_and_final_padding(): void
    {
        $root = dirname(__DIR__, 2);
        $seven = ArchiveParsingBenchmarkFixtures::make('seven-zip', $root);
        self::assertSame(crc32(substr($seven['bytes'], 12, 20)), unpack('V', substr($seven['bytes'], 8, 4))[1]);
        self::assertSame(crc32(substr($seven['bytes'], 32)), unpack('V', substr($seven['bytes'], 28, 4))[1]);
        $corrupt = ArchiveParsingBenchmarkFixtures::make('seven-zip-corrupt', $root);
        self::assertFalse($corrupt['oracle']['headers'][0]['next_header_checksum_valid']);
        self::assertNotSame(crc32(substr($corrupt['bytes'], 32)), unpack('V', substr($corrupt['bytes'], 28, 4))[1]);
        $truncated = ArchiveParsingBenchmarkFixtures::make('seven-zip-truncated', $root);
        self::assertSame(16, strlen($truncated['bytes']));
        $par2 = ArchiveParsingBenchmarkFixtures::make('par2-ranges-complete', $root);
        self::assertSame(2, $par2['oracle']['slices'][0]['zero_padding_bytes']);
        self::assertSame(md5("offline source\0\0"), $par2['oracle']['slices'][0]['expected_md5']);
    }

    public function test_nonfinite_failure_screen_and_tail_decision_gates(): void
    {
        foreach ([INF, NAN, -INF, 0.0, -1.0, '1.0', null] as $invalid) {
            self::assertFalse(archiveBenchmarkLatency($invalid));
        }
        self::assertSame('invalid', archiveBenchmarkDecision([INF], [1.0], 0, 'common', 'qualification')['status']);
        self::assertSame('invalid', archiveBenchmarkDecision([3.0], [1.0], 1, 'common', 'qualification')['status']);
        self::assertFalse(archiveBenchmarkDecision(array_fill(0, 31, 3.0), array_fill(0, 31, 1.0), 0, 'common', 'screen')['qualifies']);
        self::assertFalse(archiveBenchmarkDecision(array_fill(0, 31, 3.0), array_fill(0, 31, 1.0), 0, 'uncommon', 'qualification')['qualifies']);
        self::assertTrue(archiveBenchmarkDecision(array_fill(0, 1000, 3.0), array_fill(0, 1000, 1.0), 0, 'uncommon', 'qualification')['qualifies']);
        self::assertFalse(archiveBenchmarkDecision(array_fill(0, 1000, 1.4), array_fill(0, 1000, 1.0), 0, 'common', 'qualification')['qualifies']);
    }

    public function test_content_addressed_results_deduplicate_and_detect_tampering(): void
    {
        $directory = sys_get_temp_dir().'/archive-benchmark-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $value = ['parser_engine' => 'example', 'values' => [1, 2, 3]];
            $reference = archiveBenchmarkStore(serialize($value), $directory.'/results', 'serialized');
            self::assertSame($reference, archiveBenchmarkStore(serialize($value), $directory.'/results', 'serialized'));
            self::assertCount(1, glob($directory.'/results/*'));
            $receipt = array_fill_keys(['cold', 'warmup', 'warm'], $reference);
            $path = $directory.'/observation.result.serialized';
            file_put_contents($path, serialize($receipt));
            self::assertSame(array_fill_keys(['cold', 'warmup', 'warm'], $value), archiveBenchmarkReadResults($path));
            self::assertTrue(archiveBenchmarkStoresStable($directory));
            $receipt['warm']['path'] = '/outside-owned-result-store';
            file_put_contents($path, serialize($receipt));
            self::assertNull(archiveBenchmarkReadResults($path));
            $receipt['warm'] = $reference;
            file_put_contents($path, serialize($receipt));
            file_put_contents($reference['path'], 'mutated');
            self::assertNull(archiveBenchmarkReadResults($path));
            self::assertFalse(archiveBenchmarkStoresStable($directory));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('artifact changed');
            archiveBenchmarkStore(serialize($value), $directory.'/results', 'serialized');
        } finally {
            foreach (glob($directory.'/results/*') as $file) {
                unlink($file);
            }
            if (is_file($directory.'/observation.result.serialized')) {
                unlink($directory.'/observation.result.serialized');
            }
            rmdir($directory.'/results');
            rmdir($directory);
        }
    }

    public function test_semantic_diff_preserves_missing_null_and_nested_differences(): void
    {
        $diff = archiveBenchmarkDifferences(['null' => null, 'nested' => ['a' => 1]], ['nested' => ['a' => 2], 'extra' => null]);
        self::assertSame('/null', $diff[0]['path']);
        self::assertTrue($diff[0]['php_present']);
        self::assertFalse($diff[0]['native_present']);
        self::assertSame(['path' => '/nested/a', 'php' => 1, 'native' => 2], $diff[1]);
        self::assertSame('/extra', $diff[2]['path']);
    }
}
