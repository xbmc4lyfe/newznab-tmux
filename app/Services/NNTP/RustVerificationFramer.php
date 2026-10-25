<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\RegexService;
use Closure;
use FFI;
use RuntimeException;
use Throwable;

/** Owns only a framing subprocess; NNTP sockets, commands and fallback belong to the caller. */
final class RustVerificationFramer
{
    private const int MAX_METADATA_BYTES = 65536;

    private const int MAX_INIT_BYTES = 131072;

    private const int MAX_FEED_BYTES = 65536;

    private const int MAX_BODY_BYTES = 16777216;

    private const int MAX_BUFFER_BYTES = 67108864;

    private const int IPC_TIMEOUT_SECONDS = 30;

    /** A Unicode space, control or angle bracket; preg_match() fails on invalid UTF-8. */
    private const string INVALID_ID_PATTERN = '~(*UCP)[\\s\\p{Cc}<>]~u';

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private readonly Closure $heartbeat;

    /** @var array<int, array<int, true>> */
    private array $completed = [];

    /** @var array<int, true> */
    private array $terminal = [];

    private bool $feeding = false;

    private int $returnedBytes = 0;

    /** @param list<array{ids: list<string>, mode: string}> $connections */
    public function __construct(string $binary, private readonly array $connections,
        private readonly int $maximumBytes, private readonly int $bufferBytes, callable $heartbeat)
    {
        $this->heartbeat = Closure::fromCallable($heartbeat);
        $initialization = $this->initialization();
        if (! is_file($binary) || ! is_readable($binary) || ! is_executable($binary) || ! function_exists('proc_open')) {
            throw new RuntimeException('Rust verification framer is unavailable.');
        }
        try {
            $process = @proc_open([$binary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Rust verification framer could not start.');
            }
            $this->process = $process;
            $this->pipes = $pipes;
            foreach ($this->pipes as $pipe) {
                if (! stream_set_blocking($pipe, false)) {
                    throw new RuntimeException('Rust verification framer pipe could not become nonblocking.');
                }
            }
            $deadline = $this->deadline();
            $this->write(pack('N', strlen($initialization)).$initialization, $deadline);
            if ($this->read(1, $deadline) !== "\0") {
                throw new RuntimeException('Rust verification framer rejected initialization.');
            }
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }

    private function __clone() {}

    /** @return array{done: bool, error: ?string, results: list<array{index: int, status: string, body: string}>} */
    public function feed(int $index, string $chunk): array
    {
        if ($this->process === null || $this->feeding) {
            throw new RuntimeException('Rust verification framer is closed or already handling a feed.');
        }
        if (! isset($this->connections[$index]) || isset($this->terminal[$index]) || strlen($chunk) > self::MAX_FEED_BYTES) {
            throw new RuntimeException('Invalid Rust verification framer feed.');
        }
        $this->feeding = true;
        try {
            $deadline = $this->deadline();
            $this->write(chr($index).pack('N', strlen($chunk)).$chunk, $deadline);
            $length = unpack('Nlength', $this->read(4, $deadline))['length'];
            if ($length < 1 || $length > self::MAX_METADATA_BYTES) {
                throw new RuntimeException('Rust verification framer metadata exceeds its limit.');
            }
            $metadata = json_decode($this->read($length, $deadline), true, 32, JSON_THROW_ON_ERROR);
            $this->validateReply($index, $metadata);
            $results = [];
            foreach ($metadata['results'] as $result) {
                $results[] = ['index' => $result['index'], 'status' => $result['status'],
                    'body' => $this->read($result['length'], $deadline)];
                $this->completed[$index][$result['index']] = true;
                $this->returnedBytes += $result['length'];
            }
            if ($metadata['done'] || $metadata['error'] !== null) {
                $this->terminal[$index] = true;
            }

            return ['done' => $metadata['done'], 'error' => $metadata['error'], 'results' => $results];
        } catch (Throwable $error) {
            // No restart or retry here: the caller may already have transmitted a BODY command.
            $this->close();

            throw $error instanceof RuntimeException ? $error : new RuntimeException('Rust verification framer failed.', previous: $error);
        } finally {
            $this->feeding = false;
        }
    }

    private function initialization(): string
    {
        if ($this->maximumBytes < 1 || $this->maximumBytes > self::MAX_BODY_BYTES || $this->bufferBytes < $this->maximumBytes
            || $this->bufferBytes > self::MAX_BUFFER_BYTES
            || ! array_is_list($this->connections) || count($this->connections) < 1 || count($this->connections) > 8) {
            throw new RuntimeException('Invalid Rust verification framer limits.');
        }
        foreach ($this->connections as $connection) {
            $this->validateConnection($connection);
        }
        $json = json_encode(['connections' => $this->connections, 'maximum_bytes' => $this->maximumBytes,
            'buffer_bytes' => $this->bufferBytes], JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_INIT_BYTES) {
            throw new RuntimeException('Rust verification framer initialization exceeds its metadata limit.');
        }

        return $json;
    }

    private function validateConnection(mixed $connection): void
    {
        if (! is_array($connection) || ! isset($connection['ids'], $connection['mode'])
            || ! is_array($connection['ids']) || ! array_is_list($connection['ids']) || count($connection['ids']) > 16
            || ! in_array($connection['mode'], ['body', 'stat'], true)) {
            throw new RuntimeException('Invalid Rust verification framer connection.');
        }
        foreach ($connection['ids'] as $id) {
            if (! is_string($id) || $id === '' || strlen($id) > self::MAX_INIT_BYTES
                || preg_match(self::INVALID_ID_PATTERN, $id) !== 0) {
                throw new RuntimeException('Invalid Rust verification framer article ID.');
            }
        }
    }

    /**
     * Validate every descriptor before allocating any of the reported bodies.
     *
     * @phpstan-assert array{done: bool, error: ?string, results: list<array{index: int, status: string, length: int}>} $metadata
     */
    private function validateReply(int $index, mixed $metadata): void
    {
        if (! is_array($metadata) || ! isset($metadata['done'], $metadata['results']) || ! is_bool($metadata['done'])
            || ! array_key_exists('error', $metadata) || ($metadata['error'] !== null && ! is_string($metadata['error']))
            || ! is_array($metadata['results']) || ! array_is_list($metadata['results'])) {
            throw new RuntimeException('Invalid Rust verification framer metadata.');
        }
        $positions = $this->completed[$index] ?? [];
        $bytes = $this->returnedBytes;
        foreach ($metadata['results'] as $result) {
            if (! is_array($result) || ! isset($result['index'], $result['status'], $result['length'])
                || ! is_int($result['index']) || ! is_int($result['length'])
                || $result['index'] !== count($positions) || $result['index'] >= count($this->connections[$index]['ids'])
                || isset($positions[$result['index']]) || $result['length'] < 0
                || $result['length'] > min(self::MAX_BODY_BYTES, $this->maximumBytes)
                || ! in_array($result['status'], ['body', 'present', 'missing'], true)
                || ($result['status'] !== 'body' && $result['length'] !== 0)
                || ($result['status'] === 'body' && $this->connections[$index]['mode'] !== 'body')
                || ($result['status'] === 'present' && $this->connections[$index]['mode'] !== 'stat')
                || $result['length'] > $this->bufferBytes - $bytes) {
                throw new RuntimeException('Invalid Rust verification framer result or body budget.');
            }
            $positions[$result['index']] = true;
            $bytes += $result['length'];
        }
        if ($metadata['done'] && $metadata['error'] === null && count($positions) !== count($this->connections[$index]['ids'])) {
            throw new RuntimeException('Rust verification framer finished before all article results.');
        }
    }

    private function deadline(): float
    {
        return hrtime(true) / 1e9 + self::IPC_TIMEOUT_SECONDS;
    }

    private function write(string $bytes, float $deadline): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $this->ready(true, $deadline);
            $written = @fwrite($this->pipes[0], substr($bytes, $offset, self::MAX_FEED_BYTES));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Rust verification framer input pipe closed.');
            }
            $offset += $written;
        }
    }

    private function read(int $length, float $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->ready(false, $deadline);
            $chunk = @fread($this->pipes[1], min(self::MAX_FEED_BYTES, $length - strlen($bytes)));
            if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
                throw new RuntimeException('Rust verification framer output ended before a complete reply.');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** Poll frequently even when the child is silent, so a lease loss can abort IPC promptly. */
    private function ready(bool $writing, float $deadline): void
    {
        while (true) {
            ($this->heartbeat)();
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($this->process === null || $remaining <= 0) {
                throw new RuntimeException('Rust verification framer IPC timed out or closed.');
            }
            $read = $writing ? [] : [$this->pipes[1]];
            $write = $writing ? [$this->pipes[0]] : [];
            $except = [];
            $selected = @stream_select($read, $write, $except, 0, max(1, (int) (min(0.2, $remaining) * 1000000)));
            if ($selected === false) {
                throw new RuntimeException('Rust verification framer IPC polling failed.');
            }
            if ($selected > 0) {
                return;
            }
        }
    }

    /** Close only this parser child; it never owns any NNTP sockets or finite verification jobs. */
    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
        if ($this->process === null) {
            return;
        }
        $process = $this->process;
        $this->process = null;
        foreach ([null, 15, 9] as $signal) {
            if ($signal !== null && proc_get_status($process)['running']) {
                proc_terminate($process, $signal);
            }
            $deadline = hrtime(true) + 100_000_000;
            do {
                if (! proc_get_status($process)['running']) {
                    proc_close($process);

                    return;
                }
                usleep(1000);
            } while (hrtime(true) < $deadline);
        }
        // Never call the blocking proc_close() until the owned child has actually exited.
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Disabled: five paired runs measured 2.13-2.20x common mean but only 1.46-1.95x common p99
     * (2.47-2.56x and 2.31-2.80x uncommon mean and p99; see the W46 parity test), below the 1.5x rule
     * in one run.
     */
    public const bool W461_RUST_FFI_DEFAULT = false;

    public const string W461_FFI_CDEF = <<<'C'
        ssize_t nntmux_w461_framer_article_id_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W461_SUMMARY_KEYS = ['total_ids', 'empty_id_count', 'valid_count', 'oversize_count', 'invalid_utf8_count', 'space_count', 'control_count', 'bracket_count'];

    private static ?bool $w461UseRustFfi = null;

    private static FFI|false|null $w461Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW461RustFfi(?bool $enable = true): void
    {
        self::$w461UseRustFfi = $enable;
    }

    public static function usesW461RustFfi(): bool
    {
        return self::$w461UseRustFfi ?? self::W461_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW461RustFfi(): void
    {
        self::$w461UseRustFfi = null;
        self::$w461Ffi = null;
    }

    /**
     * Summarizes article IDs the way validateConnection() checks each one: IDs it accepts, IDs over
     * MAX_INIT_BYTES, invalid UTF-8, and IDs holding a Unicode space, a control character or an angle bracket.
     * Entries come from strings, integers, or the first non-null id, message_id array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeArticleIdBatch(array $rows): array
    {
        return self::usesW461RustFfi() ? self::summarizeArticleIdBatchNative($rows) : self::summarizeArticleIdBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeArticleIdBatchNative(array $rows): array
    {
        // Only the length of an ID over MAX_INIT_BYTES matters, so its bytes are never copied.
        $values = RegexService::w461Invoke(self::$w461Ffi, self::W461_FFI_CDEF, 'nntmux_w461_framer_article_id_batch', $rows, ['id', 'message_id'], \count(self::W461_SUMMARY_KEYS), '', self::MAX_INIT_BYTES);

        return $values === null ? self::summarizeArticleIdBatchPhp($rows) : array_combine(self::W461_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeArticleIdBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W461_SUMMARY_KEYS, 0);
        foreach (RegexService::w461Entries($rows, ['id', 'message_id']) as $id) {
            $summary['total_ids']++;
            if ($id === '') {
                $summary['empty_id_count']++;

                continue;
            }
            if (strlen($id) > self::MAX_INIT_BYTES) {
                $summary['oversize_count']++;

                continue;
            }
            $invalid = preg_match(self::INVALID_ID_PATTERN, $id);
            if ($invalid === 0) {
                $summary['valid_count']++;

                continue;
            }
            if ($invalid === false) {
                $summary['invalid_utf8_count']++;

                continue;
            }
            $summary['space_count'] += (int) preg_match('~(*UCP)\\s~u', $id);
            $summary['control_count'] += (int) preg_match('~\\p{Cc}~u', $id);
            $summary['bracket_count'] += (int) (strpbrk($id, '<>') !== false);
        }

        return $summary;
    }
}
