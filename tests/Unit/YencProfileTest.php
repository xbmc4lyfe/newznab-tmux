<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\YencService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class YencProfileTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('NNTMUX_YENC_PROFILE_FUNCTIONS_ONLY')) {
            define('NNTMUX_YENC_PROFILE_FUNCTIONS_ONLY', true);
            require dirname(__DIR__, 2).'/scripts/profile-yenc.php';
        }
    }

    public function test_arguments_require_native_and_reject_unbounded_or_ambiguous_work(): void
    {
        foreach ([[], ['--native=relative.so'], ['--native=/lib.so', '--size=0'],
            ['--native=/lib.so', '--size=8388609'], ['--native=/lib.so', '--iterations=10001'],
            ['--native=/lib.so', '--iterations=1.5'], ['--native=/lib.so', '--iterations=-1'],
            ['--native=/lib.so', '--path=unknown'], ['--native=/lib.so', '--density=random'],
            ['--native=/lib.so', '--production'], ['--native=/a.so', '--native=/b.so']] as $arguments) {
            try {
                yencProfileOptions($arguments);
                $this->fail('Invalid workload was accepted: '.json_encode($arguments));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $options = yencProfileOptions(['--native=/lib.so', '--size=8388608', '--iterations=10000', '--path=body', '--density=heavy']);
        $this->assertSame(8388608, $options['size']);
        $this->assertSame(10000, $options['iterations']);
    }

    public function test_fixtures_match_benchmark_bytes_and_real_service_paths(): void
    {
        foreach (['ordinary' => "\x00\x01\x02\x03\x04", 'heavy' => "\xd6\xe0\xe3\x13\xd6"] as $density => $expected) {
            foreach (['strict', 'tolerant', 'body'] as $path) {
                $options = yencProfileOptions(['--native=/lib.so', '--size=5', '--iterations=2', '--path='.$path, '--density='.$density]);
                $fixture = yencProfileFixture(5, $density);
                $this->assertSame($expected, $fixture['data']);
                $result = yencProfileExecute(new YencService, $options);
                $this->assertSame('ok', $result['status']);
                $this->assertSame(3, $result['verified_calls']);
                $this->assertSame(hash('sha256', $expected), $result['output_sha256']);
                $this->assertSame(hash('sha256', $fixture['article']), $result['input_sha256']);
            }
        }
    }

    public function test_incorrect_iteration_is_fatal_after_successful_warmup(): void
    {
        $decoder = new class extends YencService
        {
            private int $calls = 0;

            public function decode(string &$text, bool $ignore = false): string|false
            {
                return $this->calls++ === 0 ? parent::decode($text, $ignore) : 'incorrect';
            }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('iteration 0');
        yencProfileExecute($decoder, yencProfileOptions(['--native=/lib.so', '--size=5', '--iterations=2', '--path=strict']));
    }

    public function test_incorrect_warmup_cannot_produce_a_successful_profile(): void
    {
        $decoder = new class extends YencService
        {
            public function decodeIgnore(string &$text): string
            {
                return 'incorrect';
            }
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('warmup');
        yencProfileExecute($decoder, yencProfileOptions(['--native=/lib.so', '--size=5', '--iterations=2', '--path=tolerant']));
    }

    public function test_metadata_uses_fixed_literal_fixture_and_rejects_decoder_strata_options(): void
    {
        $options = yencProfileOptions(['--native=/rust.so', '--path=metadata', '--iterations=2']);
        $this->assertNull($options['size']);
        $this->assertNull($options['density']);
        $fixture = yencProfileMetadataFixture();
        $this->assertSame('526172211a070100c51a333203010000af35bf2f2902011b02000001000000000101781a031f01000000020000000300000004000000050000000600000019b23a3503050000', bin2hex($fixture['data']));
        $this->assertSame($fixture['data'], (new YencService)->decodeIgnore($fixture['article']));
        foreach (['--size=524288', '--density=ordinary'] as $argument) {
            try {
                yencProfileOptions(['--native=/rust.so', '--path=metadata', $argument]);
                $this->fail('Metadata accepted a decoder fixture option.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_metadata_rejects_real_analyzer_fallback_instead_of_reporting_native_success(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['yenc' => ['native_library' => '/nonexistent/profile-fixture.so']]));
        Container::setInstance($container);
        try {
            $fixture = yencProfileMetadataFixture();
            $analysis = (new NzbFingerprintAnalyzer(new YencService))->analyzeArticleAndDecodedBytes($fixture['headers'], $fixture['article']);
            $this->assertSame($fixture['data'], $analysis['decoded_bytes']);
            $this->expectException(RuntimeException::class);
            yencProfileVerifyMetadata($analysis, $fixture['data']);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function test_metadata_literal_timestamp_gate_rejects_wrong_values_even_with_native_engine_labels(): void
    {
        $time = ['interpretation_status' => 'validated_header_declaration', 'source_payload_offset' => 37,
            'times' => [
                ['base_value' => 1, 'nanoseconds' => 4, 'filetime_epoch_relationship_status' => 'not_applicable_unix_seconds'],
                ['base_value' => 2, 'nanoseconds' => 5, 'filetime_epoch_relationship_status' => 'not_applicable_unix_seconds'],
                ['base_value' => 3, 'nanoseconds' => 6, 'filetime_epoch_relationship_status' => 'not_applicable_unix_seconds'],
            ]];
        $metadata = [
            'decoded_format_metadata' => ['parser_engine' => 'nntmux-rust-ffi', 'headers' => [
                ['visible_block_walk' => ['blocks' => [[], ['file_header' => ['file_extra_records' => [
                    'time_record_count' => 1, 'base_header_mtime_seconds_matches' => true,
                    'mtime_comparison_status' => 'declared_base_seconds_consistency_only', 'time' => $time,
                ]]]]]],
            ]],
            'yenc_framing_metadata' => ['parser_engine' => 'nntmux-rust-ffi'],
            'nntp_header_metadata' => ['parser_engine' => 'nntmux-rust-nntp-headers'],
            'nntp_body_metadata' => ['parser_engine' => 'nntmux-rust-nntp-body'],
            'nntp_lines_body_relationship' => ['parser_engine' => 'nntmux-rust-nntp-body'],
        ];
        $this->assertSame([1, 2, 3], yencProfileVerifyMetadata(['decoded_bytes' => 'sample', 'metadata' => $metadata], 'sample')['base_values']);
        foreach (['base_value', 'nanoseconds', 'filetime_epoch_relationship_status'] as $field) {
            $wrong = $metadata;
            $wrong['decoded_format_metadata']['headers'][0]['visible_block_walk']['blocks'][1]['file_header']['file_extra_records']['time']['times'][0][$field] = 'wrong';
            try {
                yencProfileVerifyMetadata(['decoded_bytes' => 'sample', 'metadata' => $wrong], 'sample');
                $this->fail('Incorrect timestamp field was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
