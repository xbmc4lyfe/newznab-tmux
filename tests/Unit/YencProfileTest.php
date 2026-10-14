<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\YencService;
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
}
