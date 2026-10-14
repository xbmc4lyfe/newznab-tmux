<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbArticleFingerprintTransportFailure;
use App\Services\Nzb\RustArticleFingerprintWorker;
use PHPUnit\Framework\TestCase;

final class RustArticleFingerprintTransportTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        $this->paths = [];
        parent::tearDown();
    }

    public function test_failure_during_buffer_flush_is_sticky(): void
    {
        $worker = $this->worker('death');
        $worker->add(str_repeat('a', 70000));
        $this->assertTransportFailure($worker);
        $this->assertTransportFailure($worker);
    }

    public function test_unknown_marker_remains_unknown_on_repeated_values(): void
    {
        $worker = $this->worker('unknown');
        $worker->add('synthetic@example.invalid');
        self::assertNull($worker->value());
        self::assertNull($worker->value());
    }

    public function test_complete_digest_can_be_read_when_worker_exits_after_reply(): void
    {
        $worker = $this->worker('known-exit');
        $worker->add('synthetic@example.invalid');
        self::assertSame(str_repeat('a', 40), $worker->value());
        $this->assertTransportFailure($worker);
        $this->assertTransportFailure($worker);
    }

    public function test_repeated_values_preserve_a_live_known_worker(): void
    {
        $worker = $this->worker('known');
        $worker->add('synthetic@example.invalid');
        self::assertSame(str_repeat('a', 40), $worker->value());
        self::assertSame(str_repeat('a', 40), $worker->value());
    }

    public function test_invalid_marker_is_a_sticky_transport_failure(): void
    {
        $worker = $this->worker('invalid');
        $worker->add('synthetic@example.invalid');
        $this->assertTransportFailure($worker);
        $worker->add(str_repeat('a', 16 * 1024 * 1024 + 1));
        $this->assertTransportFailure($worker);
    }

    public function test_unsupported_oversized_input_remains_unknown(): void
    {
        $worker = $this->worker('known');
        $worker->add(str_repeat('a', 16 * 1024 * 1024 + 1));
        self::assertNull($worker->value());
        self::assertNull($worker->value());
    }

    private function assertTransportFailure(RustArticleFingerprintWorker $worker): void
    {
        try {
            $worker->value();
            self::fail('Expected a detectable transport failure.');
        } catch (NzbArticleFingerprintTransportFailure $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function worker(string $mode): RustArticleFingerprintWorker
    {
        $path = tempnam(sys_get_temp_dir(), 'fingerprint-');
        $fixture = $mode === 'death' ? 'runtime-death.php' : 'runtime-protocol.php';
        $source = file_get_contents(__DIR__.'/../Fixtures/'.$fixture);
        $source = str_replace('$'."mode = basename(__FILE__, '.php');", '$'."mode = '".$mode."';", $source);
        file_put_contents($path, $source);
        chmod($path, 0700);
        $this->paths[] = $path;

        return new RustArticleFingerprintWorker($path);
    }
}
