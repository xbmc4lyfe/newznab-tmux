<?php

declare(strict_types=1);

namespace Tests\Unit\Nzb;

use App\Services\Nzb\NzbArticleFingerprintTransportFailure;
use App\Services\Nzb\RustArticleFingerprintWorker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RustArticleFingerprintWorkerTest extends TestCase
{
    private function assertTransportFailure(RustArticleFingerprintWorker $worker): void
    {
        try {
            $worker->value();
            $this->fail('Expected detectable fingerprint transport failure.');
        } catch (NzbArticleFingerprintTransportFailure $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function worker(): RustArticleFingerprintWorker
    {
        $binary = dirname(__DIR__, 3).'/docker/app/nzb-yenc-ffi/target/release/nntmux-article-fingerprint';
        if (! is_executable($binary)) {
            $this->markTestSkipped('Build the Rust fingerprint worker before running this test.');
        }

        return new RustArticleFingerprintWorker($binary);
    }

    #[Test]
    public function real_worker_preserves_golden_identity_and_incremental_duplicates(): void
    {
        $worker = $this->worker();
        $this->assertNull($worker->value());
        $worker->add('a1@x');
        $value = $worker->value();
        $this->assertNotNull($value);
        $this->assertSame('764304775c571be7575ff3978f5ca595beeeb322', sha1($value));
        $this->assertSame($value, $worker->value());
        $worker->add(' "<a1@x>" ');
        $this->assertSame($value, $worker->value());
        $worker->add('a2@x');
        $value = $worker->value();
        $this->assertNotNull($value);
        $this->assertSame('2fd356d1823545ef8a5282116a7a891fdfdaf0a6', sha1($value));
        $this->assertSame(2, unpack('Jcount', $value)['count']);
    }

    #[Test]
    public function invalid_input_permanently_discards_prior_identity(): void
    {
        $worker = $this->worker();
        $worker->add('valid@x');
        $this->assertNotNull($worker->value());
        $worker->add('bad id');
        $this->assertNull($worker->value());
        $worker->add('later@x');
        $this->assertNull($worker->value());
    }

    #[Test]
    public function oversized_raw_input_is_sticky_unknown(): void
    {
        $worker = $this->worker();
        $worker->add('valid@x');
        $this->assertNotNull($worker->value());
        $worker->add(str_repeat(' ', 16 * 1024 * 1024 + 1));
        $worker->add('later@x');
        $this->assertNull($worker->value());
    }

    #[Test]
    public function unavailable_worker_throws_clear_startup_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rust article fingerprint worker is unavailable');
        new RustArticleFingerprintWorker('/missing/nntmux-worker');
    }

    #[Test]
    public function an_unstartable_existing_worker_throws_instead_of_losing_identity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fingerprint-startup-');
        $this->assertIsString($path);
        file_put_contents($path, "#!/bin/sh\nexit 1\n");
        chmod($path, 0700);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Rust article fingerprint worker is unavailable');
            new RustArticleFingerprintWorker($path);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function truncated_process_response_discards_partial_identity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fingerprint-worker-');
        $this->assertIsString($path);
        file_put_contents($path, "#!/bin/sh\ndd bs=1 count=1 >/dev/null 2>&1\nprintf '\\000'\ndd bs=1 count=1 >/dev/null 2>&1\nprintf '\\001partial'\nexit 0\n");
        chmod($path, 0700);
        try {
            $worker = new RustArticleFingerprintWorker($path);
            $worker->add('valid@x');
            $this->assertTransportFailure($worker);
            $worker->add('later@x');
            $this->assertTransportFailure($worker);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function malformed_response_marker_permanently_discards_identity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fingerprint-worker-');
        $this->assertIsString($path);
        file_put_contents($path, "#!/bin/sh\ndd bs=1 count=1 >/dev/null 2>&1\nprintf '\\000'\ndd bs=1 count=1 >/dev/null 2>&1\nprintf 'x\\001".str_repeat('a', 40)."'\ncat >/dev/null\n");
        chmod($path, 0700);
        try {
            $worker = new RustArticleFingerprintWorker($path);
            $this->assertTransportFailure($worker);
            $this->assertTransportFailure($worker);
        } finally {
            unset($worker);
            unlink($path);
        }
    }

    #[Test]
    public function buffered_streaming_handles_many_ids_without_pipe_deadlock(): void
    {
        $worker = $this->worker();
        for ($id = 9999; $id >= 0; $id--) {
            $worker->add('article'.$id.'@example.test');
        }
        for ($id = 0; $id < 10000; $id++) {
            $worker->add('<article'.$id.'@example.test>');
        }
        $value = $worker->value();
        $this->assertNotNull($value);
        $this->assertSame('6680c8aaf57d9432a22b1abf62ee41c12e691fec', sha1($value));
    }
}
