<?php

declare(strict_types=1);

namespace App\Services\Predb\Stream;

use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Stores PREs pushed over the predb.club and predb.net WebSocket feeds.
 *
 * Message shapes (checked against live traffic):
 *  - predb_club: `{"action":"insert"|"update","row":{name,team,cat,size,files,preAt,nuke}}`, plus `hb` heartbeats
 *  - predb_net:  `{"message":{pretime,release,section,group}}`
 *
 * Rows go through PredbFeedImporter, so they follow the same insert/fill-in rules as the JSON feeds.
 * Returns (by throwing) when the connection drops or goes quiet, so a supervisor can reconnect.
 */
class PredbStreamListener
{
    public const FORMATS = ['predb_club', 'predb_net'];

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_predb_stream_extract_entries_json(const char *message, size_t message_len, uint32_t format, char *out_buf, size_t out_cap);
        C;

    /**
     * entries() runs on Rust: 1.9-2.1x mean and 1.6-2.1x p99 on common and uncommon messages (see
     * RustPredbFeedsAndStreamParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = true;

    private static FFI|false|null $ffi = null;

    /**
     * Seconds without any frame before sending our own ping.
     */
    private const IDLE_PING_SECONDS = 30;

    /**
     * Seconds without any frame (heartbeat, ping or row) before the connection counts as dead.
     */
    private const STALE_SECONDS = 180;

    public function __construct(
        private readonly WebSocketClient $client,
        private readonly string $format,
        private readonly PredbFeedImporter $importer,
        private readonly bool $silent = false,
    ) {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown PreDB stream format [{$format}]. Available: ".implode(', ', self::FORMATS));
        }
    }

    public function run(): void
    {
        $this->client->connect();
        $this->say('connected');
        $connectedAt = time();

        while (true) {
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                $this->client->close();

                return;
            }
            $message = $this->client->receive(self::IDLE_PING_SECONDS);

            if ($message === null) {
                if (time() - max($connectedAt, $this->client->lastFrameAt()) >= self::STALE_SECONDS) {
                    $this->client->close();
                    throw new RuntimeException('No data for '.self::STALE_SECONDS.'s; reconnecting.');
                }
                $this->client->ping();

                continue;
            }

            foreach ($this->entries($message) as $entry) {
                $result = $this->importer->import([$entry]);
                if ($result['skipped'] === 0) {
                    $this->say(($result['inserted'] > 0 ? 'Added Pre ' : 'Updated Pre').' ['.$entry->title.']'.($entry->category !== null ? ' ['.$entry->category.']' : ''));
                }
            }
        }
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('predb', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Turn one pushed message into feed entries; heartbeats and unknown messages yield none.
     *
     * @return list<PredbFeedEntry>
     */
    public function entries(string $message): array
    {
        if (self::$useRustFfi) {
            $native = $this->entriesNative($message);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->entriesPhp($message);
    }

    /**
     * Rust entries(): validates the message as json_decode() would and extracts its row with the
     * PredbClubSource/PredbNetSource rules; null when PHP must decode it.
     *
     * @return list<PredbFeedEntry>|null
     */
    public function entriesNative(string $message): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $length = strlen($message);
        $format = $this->format === 'predb_club' ? 0 : 1;
        $capacity = 0;
        try {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 64, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_stream_extract_entries_json($message, $length, $format, $scratch, $capacity);
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_predb_stream_extract_entries_json($message, $length, $format, $scratch, $capacity);
            }
            if (! is_int($written) || $written < 1) {
                return null;
            }
            if ($written === 1) {
                return [];
            }
            $fields = explode("\x1F", substr(FFI::string($scratch, $written), 1));
        } catch (Throwable) {
            return null;
        }
        $count = count($fields);
        if (($count - 1) % 7 !== 0) {
            return null;
        }

        $label = $format === 0 ? 'predb.club' : 'predb.net';
        $fast = ! CarbonImmutable::hasTestNow();
        $entries = [];
        for ($i = 1; $i < $count; $i += 7) {
            $time = $fields[$i + 4];
            $size = $fields[$i + 2];
            $entries[] = new PredbFeedEntry(
                $fields[$i],
                $label,
                $fields[$i + 1] === '' ? null : $fields[$i + 1],
                // "~" carries a value near a rounding tie for PHP's own formatting.
                $size === '' ? null : ($size[0] === '~' ? PredbFeedEntry::sizeFromMegabytes((float) substr($size, 1)) : $size),
                $fields[$i + 3] === '' ? null : $fields[$i + 3],
                $time === '' ? null : PredatabaseSource::nativeTime($time, $fast),
                (int) $fields[$i + 5],
                $fields[$i + 6] === '' ? null : $fields[$i + 6],
            );
        }

        return $entries;
    }

    /**
     * @return list<PredbFeedEntry>
     */
    public function entriesPhp(string $message): array
    {
        $data = json_decode($message, true);
        if (! is_array($data)) {
            return [];
        }

        if ($this->format === 'predb_club') {
            $row = $data['row'] ?? null;

            return is_array($row) && in_array($data['action'] ?? null, ['insert', 'update'], true)
                ? (new PredbClubSource(''))->parseRows([$row])
                : [];
        }

        $row = $data['message'] ?? null;

        return is_array($row) ? (new PredbNetSource(''))->parseRows([$row]) : [];
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W47 parity test). */
    public const bool W47_FRAME_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_predb_stream_frame_batch(const char *input, size_t len, size_t count, char *out, size_t cap);
        C;

    private const array W47_HEARTBEAT_EVENTS = ['hb', 'heartbeat', 'ping'];

    private static ?bool $w47FrameRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark default; setUseRustFfi() is untouched. */
    public static function useW47FrameRustFfi(?bool $enabled = true): void
    {
        self::$w47FrameRustFfi = $enabled;
    }

    public static function usesW47FrameRustFfi(): bool
    {
        return self::$w47FrameRustFfi ?? self::W47_FRAME_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW47FrameRustFfi(): void
    {
        self::$w47FrameRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Classifies pushed frames, two ints each. A frame whose first non-blank byte is `{` is a
     * WebSocket JSON message; anything else is parsed as one SSE block (CR, LF or CRLF lines,
     * `:` comments, `event`/`data`/`id`/`retry` fields). The first int is
     * `class | data lines << 8 | comments << 16 | id << 24 | retry << 25 | event << 26 |
     * websocket << 27 | ignored fields << 32` (counts capped at 255; class 0 none, 1 heartbeat,
     * 2 insert, 3 update, 4 predb.net message, 5 other), the second the retry delay or -1.
     *
     * @param  list<string>  $frames
     * @return list<int>
     */
    public static function classifyFrames(array $frames): array
    {
        return self::usesW47FrameRustFfi() ? self::classifyFramesNative($frames) : self::classifyFramesPhp($frames);
    }

    /**
     * @param  list<string>  $frames
     * @return list<int>
     */
    public static function classifyFramesPhp(array $frames): array
    {
        $out = [];
        foreach ($frames as $frame) {
            $trimmed = ltrim($frame, " \t\n\r");
            if ($trimmed !== '' && $trimmed[0] === '{') {
                $out[] = self::w47ClassifyPayload($trimmed) | 1 << 8 | 1 << 27;
                $out[] = -1;

                continue;
            }
            $event = null;
            $data = [];
            $comments = $ignored = 0;
            $hasId = false;
            $retry = -1;
            foreach (preg_split('/\r\n|\r|\n/', $frame) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                if ($line[0] === ':') {
                    $comments++;

                    continue;
                }
                $colon = strpos($line, ':');
                if ($colon === false) {
                    $field = $line;
                    $value = '';
                } else {
                    $field = substr($line, 0, $colon);
                    $value = substr($line, $colon + 1);
                    if ($value !== '' && $value[0] === ' ') {
                        $value = substr($value, 1);
                    }
                }
                if ($field === 'event') {
                    $event = $value;
                } elseif ($field === 'data') {
                    $data[] = $value;
                } elseif ($field === 'id') {
                    $hasId = true;
                } elseif ($field === 'retry') {
                    if ($value !== '' && \strlen($value) <= 18 && ctype_digit($value)) {
                        $retry = (int) $value;
                    }
                } else {
                    $ignored++;
                }
            }
            $lines = \count($data);
            $class = \in_array($event, self::W47_HEARTBEAT_EVENTS, true) ? 1 : ($lines === 0 ? 0 : self::w47ClassifyPayload(implode("\n", $data)));
            $out[] = $class | min($lines, 255) << 8 | min($comments, 255) << 16 | ($hasId ? 1 << 24 : 0)
                | ($retry >= 0 ? 1 << 25 : 0) | ($event !== null ? 1 << 26 : 0) | min($ignored, 255) << 32;
            $out[] = $retry;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a frame holds a NUL byte.
     *
     * @param  list<string>  $frames
     * @return list<int>
     */
    public static function classifyFramesNative(array $frames): array
    {
        $count = \count($frames);
        if ($count === 0) {
            return [];
        }
        $ffi = self::$w47Ffi instanceof FFI ? self::$w47Ffi : self::w47Ffi();
        $joined = implode("\0", $frames);
        if ($ffi === null || substr_count($joined, "\0") !== $count - 1) {
            return self::classifyFramesPhp($frames);
        }
        $bytes = $count * 16;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::classifyFramesPhp($frames);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_predb_stream_frame_batch($joined, \strlen($joined), $count, $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::classifyFramesPhp($frames);
    }

    private static function w47Ffi(): ?FFI
    {
        self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w47', self::W47_FFI_CDEF) ?? false;

        return self::$w47Ffi === false ? null : self::$w47Ffi;
    }

    /**
     * 1 heartbeat, 2 insert, 3 update, 4 predb.net message, 5 any other payload.
     */
    private static function w47ClassifyPayload(string $payload): int
    {
        $text = ltrim($payload, " \t\n\r");
        if ($text === '' || $text[0] !== '{') {
            return 5;
        }
        $at = self::w47ValueAt($text, '"action"');
        if ($at >= 0) {
            $value = substr($text, $at, 8);
            if ($value === '"insert"') {
                return 2;
            }
            if ($value === '"update"') {
                return 3;
            }
        }
        $at = self::w47ValueAt($text, '"message"');
        if ($at >= 0 && ($text[$at] ?? '') === '{') {
            return 4;
        }

        return str_contains($text, '"hb"') ? 1 : 5;
    }

    /**
     * Offset after the first `$key`, a colon and surrounding JSON whitespace; -1 without a colon.
     */
    private static function w47ValueAt(string $text, string $key): int
    {
        $at = strpos($text, $key);
        if ($at === false) {
            return -1;
        }
        $at += \strlen($key);
        $at += strspn($text, " \t\n\r", $at);
        if (($text[$at] ?? '') !== ':') {
            return -1;
        }

        return $at + 1 + strspn($text, " \t\n\r", $at + 1);
    }

    private function say(string $line): void
    {
        if (! $this->silent) {
            echo '['.date('r').'] ['.$this->format.'] '.$line.PHP_EOL;
        }
    }
}
