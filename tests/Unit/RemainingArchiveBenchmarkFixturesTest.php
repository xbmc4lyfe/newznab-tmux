<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fixtures\RemainingArchiveBenchmarkFixtures as Fixtures;

final class RemainingArchiveBenchmarkFixturesTest extends TestCase
{
    public function test_references_match_independently_constructed_oracles(): void
    {
        foreach (Fixtures::strata() as $name => $definition) {
            $fixture = Fixtures::make($name);
            self::assertSame($fixture, Fixtures::make($name));
            self::assertSame($definition['comparison_scope'], $fixture['comparison_scope']);
            self::assertSame($fixture['oracle'], Fixtures::baseline($fixture), $name);
            self::assertTrue(Fixtures::correct($fixture, $fixture['oracle']), $name);
            $changed = $fixture['oracle'];
            $changed['status'] = 'invented';
            self::assertFalse(Fixtures::correct($fixture, $changed));
            $fixture['bytes'] .= '!';
            self::assertFalse(Fixtures::correct($fixture, $fixture['oracle']));
        }
    }

    public function test_wire_inputs_have_frozen_hashes_and_sizes(): void
    {
        $identities = [
            'iso-copies-equal' => [40960, '0a28c8a22e2beec44d778643fd0665ca9c80aebf982b834231f97a69fcbd6466'],
            'iso-copies-different' => [40960, '8acd2de6e429559010607d835b77ff2b04604ddef5961d4dfb2d04ec38b5914c'],
            'iso-copies-uncommon-limit' => [524288, 'a7c908d518c0368eab3aaf0886c3e7bd3a43e7e55449d81da0034aec85ff5d20'],
            'iso-copies-uncommon-partial' => [36863, '8443a4d48a69c7d0b8bf3b76873f73c82cdb928cbb84dda4d05a0e869339a4a3'],
            'rar-copy-crc' => [50, '81604878b5049a2a6d6d12f30cc2118466c6d40d9554313856e1548b1a6f175c'],
            'rar-copy-blake' => [81, '1e26f85b25deec352fad2cc10d3906e32ae6cb8a8673e769c32a6c0b83750d25'],
            'rar-copy-uncommon-ambiguous' => [56, '48510286e0f7d552a20138bf5326d952d4eea11e034e2d574f03c61276e74b46'],
            'rar-copy-uncommon-truncated' => [50, '507048554a31a2a57aed6e6172cb12b42d1cea256b23a58e5656a74cd66fc110'],
            'rar-quick-open-match' => [81, '359a19bf87cf2c207a5cd5fe8a9d589627f157730d55dd110135f3d2e14d5b75'],
            'rar-quick-open-conflict' => [81, 'afeaa49353540fc6a976189b56b31b8a0a3536fc6838cb6b08344820f633cf06'],
            'rar-quick-open-uncommon-retention' => [1513, '5c8604211fee8bd9dfd6e02e2e37976fcb2167f8fe90b5003049b5504d4ab3b1'],
            'rar-quick-open-uncommon-corrupt' => [103, '09c40634ab6bac1f50eb87d1b431ddb3eecad6eda62a37ef6bad52266a4658b2'],
        ];
        self::assertSame(array_keys($identities), array_keys(Fixtures::strata()));
        foreach ($identities as $name => [$size, $hash]) {
            $fixture = Fixtures::make($name);
            self::assertSame($size, strlen($fixture['bytes']));
            self::assertSame($hash, $fixture['input_sha256']);
        }
    }

    public function test_outer_header_trust_precedes_copy_and_cache_comparison(): void
    {
        $copy = Fixtures::make('rar-copy-crc');
        $copy['bytes'][16] = chr(ord($copy['bytes'][16]) ^ 1);
        $result = Fixtures::baseline($copy);
        self::assertSame('header_checksum_unconfirmed', $result['status']);
        self::assertNull($result['stored_crc32_matches_empty_data']);
        $quick = Fixtures::make('rar-quick-open-uncommon-corrupt');
        $quick['bytes'][35] = chr(ord($quick['bytes'][35]) ^ 1);
        $result = Fixtures::baseline($quick);
        self::assertSame('service_header_untrusted', $result['status']);
        self::assertSame(0, $result['record_count']);
        self::assertSame([], $result['records']);
        self::assertFalse($result['declared_data_fully_scanned']);
    }

    public function test_qo_original_position_is_service_header_minus_backward_distance(): void
    {
        $fixture = Fixtures::make('rar-quick-open-match');
        $fixture['bytes'][57] = "\x0f";
        $fixture['bytes'] = substr_replace($fixture['bytes'], pack('V', crc32(substr($fixture['bytes'], 55, 18))), 51, 4);
        $result = Fixtures::baseline($fixture);
        self::assertSame('complete', $result['status']);
        self::assertSame(20, $result['records'][0]['original_start_offset']);
        self::assertSame('mismatch', $result['records'][0]['comparison_status']);
        self::assertFalse($result['records'][0]['cached_bytes_match_original']);
    }

    public function test_each_module_has_two_common_and_two_uncommon_strata(): void
    {
        $counts = [];
        foreach (Fixtures::strata() as $definition) {
            $module = $definition['module'];
            $category = $definition['category'];
            $counts[$module][$category] = ($counts[$module][$category] ?? 0) + 1;
            self::assertStringContainsString('projection', $definition['comparison_scope']);
        }
        self::assertCount(3, $counts);
        foreach ($counts as $categories) {
            self::assertSame(['common' => 2, 'uncommon' => 2], $categories);
        }
    }

    public function test_bounds_and_error_gates_preserve_evidence(): void
    {
        $iso = Fixtures::baseline(Fixtures::make('iso-copies-uncommon-limit'));
        self::assertSame('scan_limit_reached', $iso['sequence_status']);
        self::assertSame(240, $iso['observed_descriptor_count']);
        self::assertSame(176, $iso['details_omitted_count']);
        self::assertCount(64, $iso['copies']);
        self::assertSame(2047, $iso['first_difference']['descriptor_relative_offset']);
        self::assertFalse($iso['whole_image_validated']);
        $partial = Fixtures::baseline(Fixtures::make('iso-copies-uncommon-partial'));
        self::assertSame('sample_truncated', $partial['sequence_status']);
        self::assertSame(1, $partial['observed_descriptor_count']);
        self::assertNull($partial['all_observed_copies_equal']);
        $copy = Fixtures::baseline(Fixtures::make('rar-copy-blake'));
        self::assertSame('BLAKE2sp', $copy['reference_selected_checksum']);
        self::assertTrue($copy['stored_blake2sp_matches_empty_data']);
        self::assertFalse($copy['target_resolution_performed']);
        foreach (['rar-copy-uncommon-ambiguous', 'rar-copy-uncommon-truncated'] as $name) {
            $copy = Fixtures::baseline(Fixtures::make($name));
            self::assertNull($copy['stored_crc32_matches_empty_data']);
            self::assertNull($copy['reference_selected_checksum']);
        }
        $quick = Fixtures::baseline(Fixtures::make('rar-quick-open-uncommon-retention'));
        self::assertSame(66, $quick['record_count']);
        self::assertSame(2, $quick['observations_omitted']);
        self::assertCount(64, $quick['records']);
        self::assertFalse($quick['observed_original_positions_strictly_increasing']);
        $corrupt = Fixtures::baseline(Fixtures::make('rar-quick-open-uncommon-corrupt'));
        self::assertSame('structure_checksum_invalid', $corrupt['status']);
        self::assertSame(1, $corrupt['record_count']);
        self::assertFalse($corrupt['declared_data_fully_scanned']);
        self::assertNull($corrupt['records'][0]['cached_bytes_match_original']);
    }

    public function test_reference_reads_bytes_instead_of_fixture_labels_or_oracle(): void
    {
        $fixture = Fixtures::make('iso-copies-equal');
        $fixture['name'] = 'irrelevant';
        $fixture['oracle'] = [];
        $fixture['bytes'][34816 + 883] = "\x01";
        $result = Fixtures::baseline($fixture);
        self::assertSame('observed_copies_differ', $result['status']);
        self::assertSame(883, $result['first_difference']['descriptor_relative_offset']);
        $copy = Fixtures::make('rar-copy-crc');
        $copy['bytes'][28] = "\x01";
        $copy['bytes'] = substr_replace($copy['bytes'], pack('V', crc32(substr($copy['bytes'], 20, 22))), 16, 4);
        self::assertFalse(Fixtures::baseline($copy)['stored_crc32_matches_empty_data']);
    }

    public function test_native_failure_is_explicit(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            self::markTestSkipped('Failure branch is deterministic on unsupported macOS; Linux parity is separate.');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parseFormats');
        Fixtures::native(new NativeMetadataParser, Fixtures::make('iso-copies-equal'));
    }

    public function test_real_native_parity_when_explicit_linux_library_is_available(): void
    {
        $path = getenv('NNTMUX_TEST_NATIVE_LIBRARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! is_string($path) || ! is_file($path)) {
            self::markTestSkipped('Requires Linux and NNTMUX_TEST_NATIVE_LIBRARY; no platform spoofing.');
        }
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => ['native_library' => $path]]));
        Container::setInstance($container);
        try {
            $parser = new NativeMetadataParser;
            foreach (Fixtures::strata() as $name => $_) {
                $fixture = Fixtures::make($name);
                $result = Fixtures::native($parser, $fixture);
                self::assertSame('nntmux-rust-ffi', $result['full']['parser_engine']);
                self::assertSame($fixture['oracle'], $result['projection'], $name);
            }
        } finally {
            Container::setInstance($previous);
        }
    }
}
