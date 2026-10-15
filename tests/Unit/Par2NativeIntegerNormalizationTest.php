<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Fixtures\ArchiveParsingBenchmarkFixtures;

require_once dirname(__DIR__).'/Fixtures/ArchiveParsingBenchmarkFixtures.php';

final class Par2NativeIntegerNormalizationTest extends TestCase
{
    public function test_candidate_preserves_every_value_type_and_key_order_of_the_reference_caller(): void
    {
        $this->withLibrary($this->nativeLibrary(), function (): void {
            $native = new NativeMetadataParser;
            $recursive = new ReflectionMethod(Par2MetadataParser::class, 'normalizeIntegerRangeValues');
            $normalize = new ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges');
            foreach ($this->fixtures() as $name => $bytes) {
                $legacy = $native->parsePar2($bytes);
                $candidate = $native->parsePar2WithIntegerRanges($bytes);
                self::assertNotNull($legacy, $name);
                self::assertNotNull($candidate, $name);
                $count = 0;
                $referenceRecursive = $legacy;
                $recursive->invokeArgs(null, [&$referenceRecursive, &$count]);
                self::assertSame($count, $candidate['unsupported_range_count'], $name);
                self::assertSame(serialize($referenceRecursive), serialize($candidate['metadata']), $name.' recursive normalization');
                $expected = $normalize->invoke(null, $legacy);
                self::assertSame(serialize($expected), serialize((new Par2MetadataParser)->parse($bytes)), $name.' public caller');
            }
        });
    }

    public function test_optional_abi_absence_retains_the_old_native_caller(): void
    {
        $path = getenv('NNTMUX_TEST_OLD_NATIVE_LIBRARY');
        if (! is_string($path) || $path === '') {
            self::markTestSkipped('Set NNTMUX_TEST_OLD_NATIVE_LIBRARY to the frozen pre-capability library.');
        }
        $this->nativeLibrary();
        $this->withLibrary($path, function (): void {
            $native = new NativeMetadataParser;
            $normalize = new ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges');
            foreach ($this->fixtures() as $name => $bytes) {
                self::assertNull($native->parsePar2WithIntegerRanges($bytes), $name);
                $legacy = $native->parsePar2($bytes);
                self::assertNotNull($legacy, $name);
                self::assertSame(serialize($normalize->invoke(null, $legacy)), serialize((new Par2MetadataParser)->parse($bytes)), $name);
            }
        });
    }

    public function test_missing_library_keeps_php_fallback_available(): void
    {
        $this->withLibrary('/nonexistent/par2-normalization-library.so', function (): void {
            self::assertNull((new NativeMetadataParser)->parsePar2WithIntegerRanges(''));
            self::assertSame('php-fallback', (new Par2MetadataParser)->parse('')['parser_engine']);
        });
    }

    public function test_precomputed_recursive_count_retains_independent_php_domain_policy(): void
    {
        $normalize = new ReflectionMethod(Par2MetadataParser::class, 'normalizeNativeIntegerRanges');
        $base = [
            'source_block_count' => 32769,
            'source_block_count_within_current_v2_limit' => true,
            'source_inventory_complete' => true,
            'recovery_to_source_percent' => 20.0,
            'recovery_percentage_basis' => 'unique-valid-recovery-exponents-over-recoverable-source-blocks',
            'recovery_sets' => [['source_block_count' => 32769, 'source_block_count_within_current_v2_limit' => true]],
        ];
        $result = $normalize->invoke(null, $base, 0);
        self::assertSame(32768, $result['source_block_limit']);
        self::assertFalse($result['source_block_count_within_current_v2_limit']);
        self::assertFalse($result['recovery_sets'][0]['source_block_count_within_current_v2_limit']);
        self::assertNull($result['recovery_to_source_percent']);
        self::assertSame('unavailable-source-block-count-exceeds-current-v2-limit', $result['recovery_percentage_basis']);

        $base['source_block_count'] = 4;
        $base['source_file_bytes_overflow'] = true;
        $result = $normalize->invoke(null, $base, 0);
        self::assertNull($result['recovery_to_source_percent']);
        self::assertSame('unavailable-source-file-byte-total-overflow', $result['recovery_percentage_basis']);
        $base['unsupported_integer_range_count'] = 2;
        $base['invalid_packet_count'] = 3;
        $result = $normalize->invoke(null, $base, 5);
        self::assertSame(7, $result['unsupported_integer_range_count']);
        self::assertSame(8, $result['invalid_packet_count']);
        self::assertFalse($result['integer_range_valid']);
        self::assertSame('unavailable-unsupported-integer-range', $result['recovery_percentage_basis']);

        $base['source_file_bytes_overflow'] = false;
        $base['source_inventory_complete'] = false;
        $result = $normalize->invoke(null, $base, 0);
        self::assertNull($result['recovery_to_source_percent']);
        self::assertSame('unavailable-source-block-denominator', $result['recovery_percentage_basis']);
    }

    /** @return iterable<string, string> */
    private function fixtures(): iterable
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge(glob($root.'/tests/Fixtures/par2/*.par2') ?: [], glob($root.'/vendor/dariusiii/rarinfo/tests/fixtures/par2/*.par2') ?: []);
        self::assertNotEmpty($paths);
        foreach ($paths as $path) {
            $bytes = file_get_contents($path);
            self::assertIsString($bytes);
            yield $path => $bytes;
            yield $path.' truncated' => substr($bytes, 0, max(0, strlen($bytes) - 17));
            $bytes[40] = chr(ord($bytes[40]) ^ 1);
            yield $path.' corrupt' => $bytes;
        }
        yield 'empty' => '';
        yield 'non-par2' => 'invalid metadata';
        foreach ([1, 128, 1024] as $files) {
            yield 'synthetic-'.$files => ArchiveParsingBenchmarkFixtures::par2($files);
        }
        $id = str_repeat('F', 16);
        $largest = str_repeat("\xff", 8);
        $main = $this->packet("PAR 2.0\0Main", pack('V2', 4, 0).pack('V', 1).$id);
        $description = $this->packet("PAR 2.0\0FileDesc", $id.str_repeat('H', 32).$largest.'file.bin');
        yield 'unsigned source size' => $main.$description;
        yield 'unsigned block size' => $this->packet("PAR 2.0\0Main", $largest.pack('V', 1).$id).$description;
        yield 'unsigned optional slice index' => $main.$description.$this->packet("PAR 2.0\0FileSlic", $id.$largest.'data');
        yield 'conflicting source definitions' => $main.$description.$this->packet("PAR 2.0\0FileDesc", $id.str_repeat('H', 32).pack('V2', 4, 0).'file.bin');
        yield 'conflicting recovery slices' => $main.$this->packet("PAR 2.0\0RecvSlic", pack('V', 1).'data').$this->packet("PAR 2.0\0RecvSlic", pack('V', 1).'diff');
    }

    private function packet(string $type, string $body): string
    {
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $content = str_repeat('S', 16).str_pad($type, 16, "\0").$body;

        return "PAR2\0PKT".pack('V2', strlen($body) + 64, 0).md5($content, true).$content;
    }

    private function nativeLibrary(): string
    {
        $path = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE !== 8 || ! extension_loaded('FFI') || ! is_string($path) || $path === '') {
            self::markTestSkipped('Set NNTMUX_TEST_NATIVE_LIBRARY on 64-bit Linux with CLI FFI.');
        }
        self::assertFileExists($path);

        return $path;
    }

    private function withLibrary(string $path, callable $test): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => ['native_library' => $path]]));
        Container::setInstance($container);
        try {
            $test();
        } finally {
            Container::setInstance($previous);
        }
    }
}
