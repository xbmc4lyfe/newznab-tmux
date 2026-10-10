<?php

declare(strict_types=1);

namespace App\Services\Predb\Stream;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use InvalidArgumentException;
use RuntimeException;

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

    public function run(): never
    {
        $this->client->connect();
        $this->say('connected');
        $connectedAt = time();

        while (true) {
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

    /**
     * Turn one pushed message into feed entries; heartbeats and unknown messages yield none.
     *
     * @return list<PredbFeedEntry>
     */
    public function entries(string $message): array
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
