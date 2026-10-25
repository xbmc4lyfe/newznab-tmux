<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use RuntimeException;

final class RustArticleFingerprintWorker
{
    /** add() closes the worker for a longer Message-ID; value() then reports unsupported input. */
    public const int MAX_MESSAGE_ID_BYTES = 16 * 1024 * 1024;

    /** queue() flushes whenever its write buffer fills to this many bytes. */
    public const int WRITE_BUFFER_BYTES = 65536;

    /**
     * Default for the Wave 43.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbPar2ResultFingerprintStoreWorkerQueueW43ParityTest.php is below 1.5x.
     */
    public const bool W432_RUST_FFI_DEFAULT = false;

    private static ?bool $useW432RustFfi = null;

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
        if (strlen($messageId) > self::MAX_MESSAGE_ID_BYTES) {
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
            $chunk = substr($bytes, $offset, self::WRITE_BUFFER_BYTES - strlen($this->buffer));
            $offset += strlen($chunk);
            $this->buffer .= $chunk;
            if (strlen($this->buffer) === self::WRITE_BUFFER_BYTES && ! $this->flush()) {
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

    /** Force the Wave 43.2 helper onto Rust (true) or PHP (false); null restores W432_RUST_FFI_DEFAULT. */
    public static function useW432RustFfi(?bool $enable = true): void
    {
        self::$useW432RustFfi = $enable;
    }

    public static function usesW432RustFfi(): bool
    {
        return self::$useW432RustFfi ?? self::W432_RUST_FFI_DEFAULT;
    }

    public static function resetW432RustFfi(): void
    {
        self::$useW432RustFfi = null;
    }

    /**
     * Replay add() for these Message-IDs against a worker that starts open, without a process: non-strings are invalid
     * and never queued; strings up to MAX_MESSAGE_ID_BYTES are framed ('A', a 4-byte length, the bytes) while the worker
     * is open; a longer one is oversized and closes it (unsupported when it was still open), after which shorter ones are
     * dropped. frame_bytes is the queued wire size and full_buffers the WRITE_BUFFER_BYTES flushes it fills.
     *
     * @param  array<array-key, mixed>  $messageIds
     * @return array{message_ids: int, invalid: int, framed: int, empty: int, oversized: int, dropped: int, unsupported: bool, frame_bytes: int, full_buffers: int}
     */
    public static function summarizeAddFrames(array $messageIds): array
    {
        return (self::usesW432RustFfi() ? self::summarizeAddFramesNative($messageIds) : null)
            ?? self::summarizeAddFramesPhp($messageIds);
    }

    /**
     * @param  array<array-key, mixed>  $messageIds
     * @return array{message_ids: int, invalid: int, framed: int, empty: int, oversized: int, dropped: int, unsupported: bool, frame_bytes: int, full_buffers: int}
     */
    public static function summarizeAddFramesPhp(array $messageIds): array
    {
        $invalid = $framed = $empty = $oversized = $dropped = $bytes = 0;
        $open = true;
        $unsupported = false;
        foreach ($messageIds as $messageId) {
            if (! \is_string($messageId)) {
                $invalid++;
            } elseif (strlen($messageId) > self::MAX_MESSAGE_ID_BYTES) {
                $oversized++;
                $unsupported = $unsupported || $open;
                $open = false;
            } elseif ($open) {
                $framed++;
                $empty += (int) ($messageId === '');
                $bytes += 5 + strlen($messageId);
            } else {
                $dropped++;
            }
        }

        return ['message_ids' => \count($messageIds), 'invalid' => $invalid, 'framed' => $framed, 'empty' => $empty, 'oversized' => $oversized,
            'dropped' => $dropped, 'unsupported' => $unsupported, 'frame_bytes' => $bytes, 'full_buffers' => intdiv($bytes, self::WRITE_BUFFER_BYTES)];
    }

    /**
     * @param  array<array-key, mixed>  $messageIds
     * @return array{message_ids: int, invalid: int, framed: int, empty: int, oversized: int, dropped: int, unsupported: bool, frame_bytes: int, full_buffers: int}|null
     */
    public static function summarizeAddFramesNative(array $messageIds): ?array
    {
        $slots = [];
        foreach ($messageIds as $messageId) {
            $slots[] = \is_string($messageId) ? strlen($messageId) : -1;
        }
        $values = Par2CandidateRecoveryResult::evaluateW432Native(2, \count($messageIds), $slots, '', 9);
        if ($values === null) {
            return null;
        }
        [$count, $invalid, $framed, $empty, $oversized, $dropped, $unsupported, $bytes, $full] = $values;
        if ($count !== \count($messageIds) || min($values) < 0 || $invalid + $framed + $oversized + $dropped !== $count || $empty > $framed
            || $unsupported > 1 || ($unsupported === 1) !== ($oversized > 0) || $bytes < 5 * $framed || $full !== intdiv($bytes, self::WRITE_BUFFER_BYTES)) {
            return null;
        }

        return ['message_ids' => $count, 'invalid' => $invalid, 'framed' => $framed, 'empty' => $empty, 'oversized' => $oversized,
            'dropped' => $dropped, 'unsupported' => $unsupported === 1, 'frame_bytes' => $bytes, 'full_buffers' => $full];
    }
}
