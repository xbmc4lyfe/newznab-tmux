<?php

declare(strict_types=1);

namespace Tests\Unit\Nzb;

use App\Services\Nzb\NzbArticleFingerprint;
use PHPUnit\Framework\TestCase;

final class NzbNativeFingerprintTest extends TestCase
{
    public function test_default_identity_works_without_ffi_or_process_spawning(): void
    {
        $this->assertSame('2fd356d1823545ef8a5282116a7a891fdfdaf0a6', $this->child(
            'echo App\\Services\\Nzb\\NzbArticleFingerprint::fromMessageIds(["a1@x", "a2@x"]);',
            archived: false,
            disableProcesses: true,
        ));
    }

    public function test_archived_experimental_missing_library_cannot_silently_disable_identity(): void
    {
        $this->assertSame('unavailable', $this->child(<<<'CODE'
            try {
                new App\Services\Nzb\NzbArticleFingerprint('/missing/nntmux-fingerprint-library.so');
            } catch (App\Services\Nzb\NzbArticleFingerprintUnavailable) {
                echo 'unavailable';
            }
            CODE));
    }

    public function test_archived_experimental_worker_preserves_digest_and_owns_its_state(): void
    {
        $binary = dirname(__DIR__, 3).'/docker/app/nzb-yenc-ffi/target/release/nntmux-article-fingerprint';
        if (! is_executable($binary)) {
            $this->markTestSkipped('Build the archived experimental Rust fingerprint worker.');
        }
        $this->assertSame('owned', $this->child('$binary='.var_export($binary, true).';'.<<<'CODE'
            $fingerprint = new App\Services\Nzb\NzbArticleFingerprint(workerPath: $binary);
            $fingerprint->add('a1@x');
            try {
                clone $fingerprint;
                exit(1);
            } catch (Error) {
            }
            $fingerprint->add(' "<a2@x>" ');
            if ($fingerprint->value() !== '2fd356d1823545ef8a5282116a7a891fdfdaf0a6') exit(2);
            $fingerprint->add('<a1@x>');
            if ($fingerprint->value() !== '2fd356d1823545ef8a5282116a7a891fdfdaf0a6') exit(3);
            $fingerprint->add('invalid id');
            if ($fingerprint->value() !== null) exit(4);
            echo 'owned';
            CODE));
    }

    public function test_archived_experimental_ffi_state_cannot_be_shared_by_a_shallow_clone(): void
    {
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $library = dirname(__DIR__, 3).'/docker/app/nzb-yenc-ffi/target/release/libnzbyenc.'.$suffix;
        if (! extension_loaded('FFI') || ! is_file($library)) {
            $this->markTestSkipped('Build the archived experimental Rust fingerprint library and enable FFI.');
        }
        $this->assertSame('owned', $this->child('$library='.var_export($library, true).';'.<<<'CODE'
            $fingerprint = new App\Services\Nzb\NzbArticleFingerprint(libraryPath: $library);
            $fingerprint->add('a1@x');
            try {
                clone $fingerprint;
                exit(1);
            } catch (Error) {
            }
            if ($fingerprint->value() !== '764304775c571be7575ff3978f5ca595beeeb322') exit(2);
            echo 'owned';
            CODE, ffi: true));
    }

    public function test_native_stream_state_cannot_be_shared_by_a_shallow_clone(): void
    {
        $fingerprint = new NzbArticleFingerprint;
        $fingerprint->add('a1@x');
        $this->assertSame('764304775c571be7575ff3978f5ca595beeeb322', $fingerprint->value());
        $this->expectException(\Error::class);
        $this->assertNotSame($fingerprint, clone $fingerprint);
    }

    private function child(string $code, bool $archived = true, bool $disableProcesses = false, bool $ffi = false): string
    {
        $root = dirname(__DIR__, 3);
        $bootstrap = $archived ? 'require '.var_export($root.'/tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php', true).';' : '';
        $bootstrap .= 'require '.var_export($root.'/vendor/autoload.php', true).';';
        $command = [PHP_BINARY, '-d', 'ffi.enable='.($ffi ? 'true' : 'false')];
        if ($disableProcesses) {
            array_push($command, '-d', 'disable_functions=proc_open');
        }
        array_push($command, '-r', $bootstrap.$code);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);

        return $stdout;
    }
}
