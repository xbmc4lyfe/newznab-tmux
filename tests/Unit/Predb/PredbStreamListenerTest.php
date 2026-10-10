<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Stream\PredbStreamListener;
use App\Services\Predb\Stream\WebSocketClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Messages are real pushes captured from wss://predb.club/api/v1/ws on 2026-10-10.
 */
final class PredbStreamListenerTest extends TestCase
{
    #[Test]
    public function predb_club_inserts_and_updates_become_entries(): void
    {
        $listener = $this->listener('predb_club');

        $insert = $listener->entries('{"action":"insert","row":{"id":15545958,"name":"MASH.S02E20.1974.DUBBED.CZECH.1080p.WEB.x264-OMG","team":"OMG","cat":"TV-1080P-CZ","size":0,"files":0,"preAt":1791630534,"nuke":null}}');
        $update = $listener->entries('{"action":"update","row":{"id":15545957,"name":"Beard2Beard_and_Seventh_Soul-Mira-(CA156)-WEB-2026-PTC","team":"PTC","cat":"MP3","size":49,"files":4,"preAt":1791630519,"nuke":null}}');

        $this->assertSame('MASH.S02E20.1974.DUBBED.CZECH.1080p.WEB.x264-OMG', $insert[0]->title);
        $this->assertSame('TV-1080P-CZ', $insert[0]->category);
        $this->assertSame(1791630534, $insert[0]->predate?->getTimestamp());
        $this->assertNull($insert[0]->size);
        $this->assertSame('49MB', $update[0]->size);
        $this->assertSame('4', $update[0]->files);
        $this->assertSame('predb.club', $update[0]->source);
    }

    #[Test]
    public function predb_net_messages_become_entries(): void
    {
        $entries = $this->listener('predb_net')->entries('{"message":{"pretime":1791630534,"release":"MASH.S02E20.1974.DUBBED.CZECH.1080p.WEB.x264-OMG","section":"TV-X264-HD","group":"OMG"}}');

        $this->assertCount(1, $entries);
        $this->assertSame('predb.net', $entries[0]->source);
        $this->assertSame('TV-X264-HD', $entries[0]->category);
        $this->assertSame(1791630534, $entries[0]->predate?->getTimestamp());
    }

    #[Test]
    public function heartbeats_and_unknown_messages_are_ignored(): void
    {
        $this->assertSame([], $this->listener('predb_club')->entries('hb'));
        $this->assertSame([], $this->listener('predb_club')->entries('{"action":"delete","row":{"name":"X-GRP"}}'));
        $this->assertSame([], $this->listener('predb_net')->entries('{"status":"ok"}'));
    }

    #[Test]
    public function unknown_formats_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->listener('srrdb');
    }

    private function listener(string $format): PredbStreamListener
    {
        return new PredbStreamListener(new WebSocketClient('wss://example.invalid/ws'), $format, $this->createStub(PredbFeedImporter::class), silent: true);
    }
}
