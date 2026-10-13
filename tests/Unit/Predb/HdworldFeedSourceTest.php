<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HdworldFeedSourceTest extends TestCase
{
    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 3));
        $container->instance('config', new Repository(['predb_feeds' => require dirname(__DIR__, 3).'/config/predb_feeds.php']));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::clearResolvedInstance('http');
    }

    protected function tearDown(): void
    {
        Http::clearResolvedInstance('http');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    public function catalogue_requires_explicit_selection_and_keeps_the_six_default_sources(): void
    {
        $factory = new PredbFeedSourceFactory;

        $this->assertContains('hdworld', $factory->availableKeys());
        $this->assertContains('hdworld', config('predb_feeds.single_page_sources'));
        $this->assertSame(['predb_club', 'predb_net', 'predb_me', 'predatabase', 'xrel', 'xrel_p2p'], array_map(static fn (PredbFeedSource $source): string => $source->key(), $factory->make()));
        $this->assertSame('hdworld', $factory->make(['hdworld'])[0]->key());
    }

    #[Test]
    public function configured_catalogue_preserves_literal_german_names_and_utc_listing_dates(): void
    {
        $entries = $this->source()->parseXml($this->fixture());

        $this->assertSame([
            'Die.Puppe.S01.Complete.German.EAC3.5.1.DL.2160p.NetflixUHD.DV.HDR.HEVC-4SF',
            'Die.Brueder.Karamasow.Teil.3.1969.German.1080p.BluRay.x264-PL3X',
            'Eine.Geschichte.aus.zwei.Staedten.S01.Complete.German.DL.1080p.WEB.h264-SAUERKRAUT',
            'Die.Simpsons.S37.Complete.German.DL.720p.WEB.h264-WvF',
        ], array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries));
        $this->assertSame([
            '2026-10-11T16:44:15+00:00',
            '2026-10-11T14:22:09+00:00',
            '2026-10-11T14:11:41+00:00',
            '2026-10-11T12:28:07+00:00',
        ], array_map(static fn (PredbFeedEntry $entry): ?string => $entry->listedAt?->toIso8601String(), $entries));
        foreach ($entries as $entry) {
            $this->assertSame('hd-world.cc', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }

    #[Test]
    public function only_the_public_latest_feed_is_requested_and_page_two_is_empty(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://hd-world.cc/feed/' => Http::response($this->fixture(), 200, ['Content-Type' => 'application/rss+xml'])]);
        $source = $this->source();

        $this->assertCount(4, $source->fetch());
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
    }

    #[Test]
    public function display_titles_and_unzoned_metadata_are_not_used_to_guess_release_details(): void
    {
        $entries = $this->source()->parseXml('<rss><channel>'
            .'<item><title>German Film-GRP</title></item>'
            .'<item><title>German.Film-GRP – 1 GB</title></item>'
            .'<item><title>German.Film-GRP</title><category>Filme</category><pubDate>2026-10-11 12:00:00</pubDate>'
            .'<description>Size: 2 GB; files: 4; PRE: 2026-10-10</description></item>'
            .'</channel></rss>');

        $this->assertCount(1, $entries);
        $this->assertSame('German.Film-GRP', $entries[0]->title);
        $this->assertNull($entries[0]->category);
        $this->assertNull($entries[0]->size);
        $this->assertNull($entries[0]->files);
        $this->assertNull($entries[0]->predate);
        $this->assertNull($entries[0]->listedAt);
    }

    private function source(): RssFeedSource
    {
        $source = (new PredbFeedSourceFactory)->makeOne('hdworld');
        $this->assertInstanceOf(RssFeedSource::class, $source);

        return $source;
    }

    private function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/hdworld.xml');
    }
}
