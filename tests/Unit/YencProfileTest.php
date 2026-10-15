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
    public function test_prepared_fixture_custody_and_hash_verification(): void
    {
        $directory = sys_get_temp_dir().'/yenc-profile-fixture-'.bin2hex(random_bytes(8));
        try {
            $prepared = yencProfilePrepareFixture($directory, 5, 'heavy');
            $this->assertSame('ok', $prepared['status']);
            $fixture = yencProfileLoadFixture($directory, 5, 'heavy');
            $this->assertSame("\xd6\xe0\xe3\x13\xd6", $fixture['data']);
            $article = $fixture['article'];
            $this->assertSame($fixture['data'], (new YencService)->decodeIgnore($article));
            try {
                yencProfilePrepareFixture($directory, 5, 'ordinary');
                $this->fail('Existing fixture directory was overwritten.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($fixture, yencProfileLoadFixture($directory, 5, 'heavy'));
            foreach ([[6, 'heavy'], [5, 'ordinary']] as [$size, $density]) {
                try {
                    yencProfileLoadFixture($directory, $size, $density);
                    $this->fail('Incorrect fixture properties were accepted.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
            foreach (['strict', 'tolerant', 'body'] as $path) {
                $result = yencProfileExecute(new YencService, yencProfileOptions(['--native=/lib.so', '--size=5',
                    '--density=heavy', '--path='.$path, '--iterations=1', '--fixture-dir='.$directory]));
                $this->assertSame('ok', $result['status']);
                $this->assertSame('prepared_files', $result['fixture_source']);
                $this->assertSame(2, $result['verified_calls']);
            }
            unlink($directory.'/data');
            symlink($directory.'/article', $directory.'/data');
            try {
                yencProfileLoadFixture($directory, 5, 'heavy');
                $this->fail('Symlink fixture file was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
            unlink($directory.'/data');
            file_put_contents($directory.'/data', $fixture['data']);
            file_put_contents($directory.'/article', '!'.substr($fixture['article'], 1));
            $this->expectException(RuntimeException::class);
            yencProfileLoadFixture($directory, 5, 'heavy');
        } finally {
            foreach (['data', 'article', 'manifest.json'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_fixture_modes_require_absolute_paths_and_cannot_mix_with_metadata_or_each_other(): void
    {
        foreach ([['--fixture-dir=relative'], ['--prepare-dir=relative'],
            ['--fixture-dir=/a', '--prepare-dir=/b'], ['--path=metadata', '--fixture-dir=/a'],
            ['--path=metadata', '--prepare-dir=/a']] as $arguments) {
            try {
                yencProfileOptions(['--native=/lib.so', ...$arguments]);
                $this->fail('Invalid fixture mode was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_prepare_cli_exits_before_attempting_native_service_construction(): void
    {
        $directory = sys_get_temp_dir().'/yenc-profile-cli-'.bin2hex(random_bytes(8));
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/scripts/profile-yenc.php',
                '--native='.__FILE__, '--size=5', '--density=heavy', '--prepare-dir='.$directory],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
            $this->assertSame('', $stderr);
            $this->assertSame('prepare', json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['mode']);
            $this->assertSame("\xd6\xe0\xe3\x13\xd6", yencProfileLoadFixture($directory, 5, 'heavy')['data']);
        } finally {
            foreach (['data', 'article', 'manifest.json'] as $file) {
                if (is_file($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

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
