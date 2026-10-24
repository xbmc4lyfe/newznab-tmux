<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use Throwable;

/**
 * One logical log entry: a Monolog header line plus its continuation lines, or a single plain line.
 *
 * `offset` and `end` are byte positions in the file; `offset` doubles as the pagination cursor.
 */
final readonly class LogEntry
{
    public function __construct(
        public int $offset,
        public int $end,
        public ?int $lineNumber,
        public ?string $timestamp,
        public ?string $channel,
        public ?string $level,
        public string $message,
        public string $body,
        public bool $truncated,
    ) {}

    /**
     * The entry's timestamp as Unix seconds (read in the application timezone), or null when it has none.
     *
     * Rust is the default: it beat strtotime() by at least 1.5x on every paired metric
     * (RustSearchCursorAndLogIndexParityTest); unsupported shapes fall back to PHP.
     */
    public function loggedAt(bool $useRustFfi = true): ?int
    {
        $supported = false;
        $native = $useRustFfi ? $this->loggedAtNative($supported) : null;

        return $supported ? $native : $this->loggedAtPhp();
    }

    public function loggedAtPhp(): ?int
    {
        if ($this->timestamp === null) {
            return null;
        }

        $seconds = strtotime($this->timestamp);

        return $seconds === false ? null : $seconds;
    }

    /**
     * Rust `strtotime()` for canonical Monolog/plain timestamps in a UTC default zone.
     * `$supported` stays false (PHP decides) for other zones, shapes and overflowing dates.
     *
     * @param-out bool $supported
     */
    public function loggedAtNative(bool &$supported = false): ?int
    {
        $supported = false;
        if ($this->timestamp === null) {
            $supported = true;

            return null;
        }
        if (date_default_timezone_get() !== 'UTC') {
            return null;
        }
        static $ffi, $slot;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_log_entry_logged_at(const char *input, size_t len, uint32_t default_utc, int64_t *out);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $slot ??= $ffi->new('int64_t[1]');
            if ($ffi->nntmux_log_entry_logged_at($this->timestamp, strlen($this->timestamp), 1, $slot) !== 1) {
                return null;
            }
            $supported = true;

            return (int) $slot[0];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     offset: int,
     *     end: int,
     *     line: int|null,
     *     timestamp: string|null,
     *     channel: string|null,
     *     level: string|null,
     *     message: string,
     *     body: string,
     *     truncated: bool,
     *     message_segments: list<array{text: string, hit: bool}>|null,
     *     body_segments: list<array{text: string, hit: bool}>|null
     * }
     */
    public function toArray(?SearchQuery $query = null): array
    {
        $highlight = $query !== null && $query->hasTerm();

        return [
            'offset' => $this->offset,
            'end' => $this->end,
            'line' => $this->lineNumber,
            'timestamp' => $this->timestamp,
            'channel' => $this->channel,
            'level' => $this->level,
            'message' => $this->message,
            'body' => $this->body,
            'truncated' => $this->truncated,
            'message_segments' => $highlight ? $query->segments($this->message) : null,
            'body_segments' => $highlight && $this->body !== '' ? $query->segments($this->body) : null,
        ];
    }
}
