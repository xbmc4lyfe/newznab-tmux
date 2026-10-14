<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use RuntimeException;

final class RustArticleFingerprintWorker
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';

    private bool $unsupportedInput = false;

    public function __construct(string $binary)
    {
        if (! is_file($binary) || ! is_readable($binary) || ! is_executable($binary) || ! function_exists('proc_open')) {
            throw new RuntimeException('Rust article fingerprint worker is unavailable: '.$binary);
        }
        $process = @proc_open([$binary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Rust article fingerprint worker is unavailable: '.$binary);
        }
        $this->process = $process;
        $this->pipes = $pipes;
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->queue('V');
        if (! $this->flush() || $this->read(1) !== "\0") {
            $this->close();
            throw new RuntimeException('Rust article fingerprint worker is unavailable: '.$binary);
        }
    }

    private function __clone() {}

    public function __destruct()
    {
        $this->close();
    }

    public function add(string $messageId): void
    {
        if (strlen($messageId) > 16 * 1024 * 1024) {
            $this->unsupportedInput = $this->unsupportedInput || $this->process !== null;
            $this->close();
        } elseif ($this->process !== null) {
            $this->queue('A'.pack('N', strlen($messageId)));
            $this->queue($messageId);
        }
    }

    public function value(): ?string
    {
        if ($this->unsupportedInput) {
            return null;
        }
        if ($this->process === null) {
            throw new NzbArticleFingerprintTransportFailure('Fingerprint worker stopped during accumulation.');
        }
        $this->queue('V');
        if (! $this->flush()) {
            throw new NzbArticleFingerprintTransportFailure('Fingerprint worker request failed.');
        }
        $marker = $this->read(1);
        if ($marker === "\0") {
            return null;
        }
        if ($marker !== "\1") {
            $this->close();
            throw new NzbArticleFingerprintTransportFailure('Fingerprint worker returned an incomplete or invalid response.');
        }
        $digest = $this->read(40);
        if ($digest === null) {
            throw new NzbArticleFingerprintTransportFailure('Fingerprint worker returned an incomplete digest.');
        }

        return $digest;
    }

    private function queue(string $bytes): void
    {
        for ($offset = 0; $this->process !== null && $offset < strlen($bytes);) {
            $chunk = substr($bytes, $offset, 65536 - strlen($this->buffer));
            $offset += strlen($chunk);
            $this->buffer .= $chunk;
            if (strlen($this->buffer) === 65536 && ! $this->flush()) {
                return;
            }
        }
    }

    private function flush(): bool
    {
        $deadline = microtime(true) + 30;
        while ($this->process !== null && $this->buffer !== '') {
            if (! $this->ready(true, $deadline) || ($written = @fwrite($this->pipes[0], $this->buffer)) === false || $written === 0) {
                $this->close();

                return false;
            }
            $this->buffer = substr($this->buffer, $written);
        }

        return $this->process !== null;
    }

    private function read(int $length): ?string
    {
        $bytes = '';
        $deadline = microtime(true) + 30;
        while (strlen($bytes) < $length) {
            if (! $this->ready(false, $deadline) || ($chunk = @fread($this->pipes[1], $length - strlen($bytes))) === false || $chunk === '') {
                $this->close();

                return null;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function ready(bool $writing, float $deadline): bool
    {
        if ($this->process === null || ($writing && ! proc_get_status($this->process)['running']) || ($remaining = $deadline - microtime(true)) <= 0) {
            return false;
        }
        $read = $writing ? [] : [$this->pipes[1]];
        $write = $writing ? [$this->pipes[0]] : [];
        $except = [];

        return @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000)) > 0;
    }

    private function close(): void
    {
        $this->buffer = '';
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        if ($this->process !== null) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
            $this->process = null;
        }
    }
}
