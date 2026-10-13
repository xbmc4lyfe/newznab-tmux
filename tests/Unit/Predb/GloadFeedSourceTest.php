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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GloadFeedSourceTest extends TestCase
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
    public function configured_catalogue_preserves_literal_names_and_listing_dates(): void
    {
        $entries = $this->source()->parseXml($this->fixture());

        $this->assertSame([
            'Project.Spark-RUNE',
            'Wild_West_Supermarket_Simulator-Razor1911',
            'Silver.Pines-ElAmigos',
            'Neon.Abyss.2-ElAmigos',
        ], array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries));
        $this->assertSame([
            '2026-10-11 18:37:06',
            '2026-10-11 16:52:59',
            '2026-10-10 00:31:11',
            '2026-10-09 10:39:18',
        ], array_map(static fn (PredbFeedEntry $entry): ?string => $entry->listedAt?->toDateTimeString(), $entries));
        foreach ($entries as $entry) {
            $this->assertSame('gload.to', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
            $this->assertSame($entry->listedAt, $entry->pagingTime());
        }
    }

    #[Test]
    public function only_the_published_latest_feed_is_requested(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([
            'https://gload.to/feed/' => Http::response($this->fixture(), 200, ['Content-Type' => 'text/xml']),
        ]);
        $source = $this->source();

        $this->assertCount(4, $source->fetch());
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://gload.to/feed/');
    }

    #[Test]
    public function editorial_titles_and_other_metadata_are_not_used_to_guess_release_details(): void
    {
        $entries = $this->source()->parseXml('<rss><channel>'
            .'<item><title>Silver Pines-ElAmigos</title></item>'
            .'<item><title>Display.Game-ElAmigos – 1 GB</title></item>'
            .'<item><title>Silver.Pines-ElAmigos</title><category>Windows</category>'
            .'<description>Size: 2.43 GB; release date: 2026-10-10</description></item>'
            .'</channel></rss>');

        $this->assertCount(1, $entries);
        $this->assertSame('Silver.Pines-ElAmigos', $entries[0]->title);
        $this->assertNull($entries[0]->category);
        $this->assertNull($entries[0]->size);
        $this->assertNull($entries[0]->predate);
        $this->assertNull($entries[0]->listedAt);
    }

    #[Test]
    public function catalogue_is_available_only_when_explicitly_selected(): void
    {
        $factory = new PredbFeedSourceFactory;

        $this->assertContains('gload', $factory->availableKeys());
        $this->assertContains('gload', config('predb_feeds.single_page_sources'));
        $this->assertNotContains('gload', config('predb_feeds.sources'));
        $this->assertNotContains('gload', array_map(static fn (PredbFeedSource $source): string => $source->key(), $factory->make()));
        $this->assertSame('gload', $factory->make(['gload'])[0]->key());
    }

    private function source(): RssFeedSource
    {
        $source = (new PredbFeedSourceFactory)->makeOne('gload');
        $this->assertInstanceOf(RssFeedSource::class, $source);

        return $source;
    }

    private function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/gload.xml');
    }
}
