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

    private function say(string $line): void
    {
        if (! $this->silent) {
            echo '['.date('r').'] ['.$this->format.'] '.$line.PHP_EOL;
        }
    }
}
