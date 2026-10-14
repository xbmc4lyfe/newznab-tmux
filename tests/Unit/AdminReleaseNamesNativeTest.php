<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AdminReleaseNames;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdminReleaseNamesNativeTest extends TestCase
{
    public function test_unavailable_scope_fails_closed_without_breaking_the_php_fallback(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=false', '-r',
            'require $argv[1]; echo json_encode(nntmux_render_release_names("{}"));',
            base_path('docker/app/php/release-presentation-preload.php'),
        ]);
        $process->mustRun();
        $this->assertSame('null', $process->getOutput());
    }

    public function test_web_requests_can_render_but_cannot_access_raw_ffi(): void
    {
        $library = base_path('docker/app/nzb-yenc-ffi/target/release/'.(PHP_OS_FAMILY === 'Darwin' ? 'libnzbyenc.dylib' : 'libnzbyenc.so'));
        if (! is_file($library) || ! extension_loaded('FFI')) {
            $this->markTestSkipped('Build the Rust release library to test the web FFI boundary.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $header = tempnam(sys_get_temp_dir(), 'release-names-http-');
        file_put_contents($header, str_replace('/usr/local/lib/nntmux/libnzbyenc.so', $library, (string) file_get_contents(base_path('docker/app/php/release-presentation.h'))));
        $process = new Process([
            PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'ffi.enable=preload', '-d', 'ffi.preload='.$header,
            '-d', 'opcache.preload='.base_path('docker/app/php/release-presentation-preload.php'),
            '-S', $address, base_path('tests/Fixtures/release-names-http.php'),
        ], base_path());
        try {
            $process->start();
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $errorCode, $error, 0.05);
                if ($connection !== false) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20_000);
            }
            $this->assertTrue($ready, $process->getErrorOutput());
            foreach (['first', 'second', '<script>alert(1)</script>'] as $name) {
                $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 5, 'header' => 'Content-Type: application/json', 'content' => json_encode(['url_prefix' => '/details/', 'url_suffix' => '', 'releases' => [['searchname' => $name, 'name' => null, 'guid' => 'abc123']]], JSON_THROW_ON_ERROR)]]);
                $response = json_decode(file_get_contents('http://'.$address, false, $context), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('cli-server', $response['sapi']);
                $this->assertTrue($response['wrapper_loaded']);
                $this->assertTrue($response['header_locked']);
                $this->assertSame(['cdef' => true, 'load' => true, 'scope' => true], $response['blocked']);
                $html = $response['output'];
                $this->assertStringContainsString('href="/details/abc123"', $html[0]);
                $this->assertStringContainsString(e($name), $html[0]);
            }
        } finally {
            $process->stop();
            unlink($header);
        }
    }

    public function test_real_preloaded_renderer_matches_php_for_the_display_corpus(): void
    {
        $library = base_path('docker/app/nzb-yenc-ffi/target/release/'.(PHP_OS_FAMILY === 'Darwin' ? 'libnzbyenc.dylib' : 'libnzbyenc.so'));
        if (! is_file($library) || ! extension_loaded('FFI')) {
            $this->markTestSkipped('Build the Rust release library and enable FFI to exercise native parity.');
        }
        $names = [null, '', 'Movie.2026', '[1/2] "Movie.rar" yEnc (1/20)',
            '[ 01 / 10 ]-"Unknown.File.enc"', '[1/2] - &QUOT;日本語.enc&QuOt;',
            '[1/2] - "0"', '[1/2] - "   "', '[1/2] - "Unknown.enc" extra title',
            '[1/2] - "Unknown.enc#34;', '<img src=x onerror=alert(1)> & " \'',
            "[1/2] \"\0file\0\"", "bad\xffutf8", "line\r\nbreak"];
        $rows = array_map(static fn (?string $name): object => (object) ['guid' => 'abc123', 'searchname' => $name, 'name' => '[1/2] "original.rar" yEnc'], $names);
        $input = array_map(static fn (object $row): array => ['searchname' => $row->searchname, 'name' => $row->name, 'guid' => $row->guid], $rows);
        $header = tempnam(sys_get_temp_dir(), 'release-names-header-');
        try {
            file_put_contents($header, str_replace('/usr/local/lib/nntmux/libnzbyenc.so', $library, (string) file_get_contents(base_path('docker/app/php/release-presentation.h'))));
            $process = new Process([
                PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'ffi.enable=preload', '-d', 'ffi.preload='.$header,
                '-d', 'opcache.preload='.base_path('docker/app/php/release-presentation-preload.php'),
                base_path('tests/Fixtures/release-names-worker.php'),
            ], base_path(), null, json_encode(['url_prefix' => str_replace('abc123', '', route('details', ['guid' => 'abc123'])), 'url_suffix' => '', 'releases' => $input], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), 20);
            $process->mustRun();
            $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($actual);
            $this->assertSame(array_map(strval(...), (new AdminReleaseNames)->render($rows, false)), $actual);
        } finally {
            unlink($header);
        }
    }
}
