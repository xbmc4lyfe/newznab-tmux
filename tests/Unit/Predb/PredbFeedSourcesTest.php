<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PredbFeedSourcesTest extends TestCase
{
    #[Test]
    public function predb_club_rows_are_normalised(): void
    {
        $entries = (new PredbClubSource('https://predb.club/api/v1/'))->parseRows($this->json('predb_club.json')['data']['rows']);

        $this->assertCount(3, $entries);
        $this->assertSame('WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS', $entries[0]->title);
        $this->assertSame('predb.club', $entries[0]->source);
        $this->assertSame('TV-SD-FR', $entries[0]->category);
        $this->assertSame('457MB', $entries[0]->size);
        $this->assertSame('32', $entries[0]->files);
        $this->assertSame(1791513593, $entries[0]->predate?->getTimestamp());
        $this->assertSame(Predb::PRE_NONUKE, $entries[0]->nuked);
    }

    #[Test]
    public function predb_club_zero_size_and_files_are_unknown_and_nukes_are_mapped(): void
    {
        $entries = (new PredbClubSource('https://predb.club/api/v1/'))->parseRows($this->json('predb_club.json')['data']['rows']);

        $this->assertNull($entries[1]->size);
        $this->assertNull($entries[1]->files);
        $this->assertSame('2150.5MB', $entries[2]->size);
        $this->assertSame(Predb::PRE_NUKED, $entries[2]->nuked);
        $this->assertSame('dupe.2026-10-01', $entries[2]->nukeReason);
    }

    #[Test]
    public function predb_net_rows_are_normalised_with_status_mapping(): void
    {
        $entries = (new PredbNetSource('https://api.predb.net/'))->parseRows($this->json('predb_net.json')['data']);

        $this->assertCount(2, $entries);
        $this->assertSame('predb.net', $entries[0]->source);
        $this->assertSame('TV-WEB-X264', $entries[0]->category);
        $this->assertNull($entries[0]->size);
        $this->assertNull($entries[0]->nukeReason);
        $this->assertSame(Predb::PRE_NUKED, $entries[1]->nuked);
        $this->assertSame('bad.ivtc', $entries[1]->nukeReason);
        $this->assertSame('850MB', $entries[1]->size);
    }

    #[Test]
    public function rss_items_keep_release_titles_and_parse_pub_dates_as_utc(): void
    {
        $source = new RssFeedSource('predb_me', 'predb.me', 'https://predb.me/?rss=1');
        $entries = $source->parseXml((string) file_get_contents($this->fixture('predb_me.xml')));

        $this->assertSame(['Nybble_and_Nibble_CD32_AMIGA-bADkARMA', 'Elsbeth.S04E01.1080p.HDTV.x264-SYNCOPY'], array_map(static fn (PredbFeedEntry $e): string => $e->title, $entries));
        $this->assertNull($entries[0]->predate);
        $this->assertSame('2026-10-09 02:37:37', $entries[1]->predate?->format('Y-m-d H:i:s'));
        $this->assertSame('predb.me', $entries[1]->source);
    }

    #[Test]
    public function malformed_rss_yields_no_entries(): void
    {
        $source = new RssFeedSource('predb_me', 'predb.me', 'https://predb.me/?rss=1');

        $this->assertSame([], $source->parseXml('<html>not rss'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $name): array
    {
        return json_decode((string) file_get_contents($this->fixture($name)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 2).'/Fixtures/predb/'.$name;
    }
}
