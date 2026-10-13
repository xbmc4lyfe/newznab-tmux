<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\PredbFeedEntry;
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

final class ScnlogFeedSourceTest extends TestCase
{
    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 3));
        $container->instance('config', new Repository);
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
    public function observed_feed_preserves_literal_titles_and_only_published_metadata(): void
    {
        $entries = $this->source()->parseXml($this->fixture());

        $this->assertSame([
            'Naza_x_JUL-Pas_Dproblemes-SINGLE-WEB-FR-2026-OND',
            'FOXII_x_Sagaya_x_Mada4bang_x_Oso_J_x_La_Mescla-La_File_Indienne-SINGLE-WEB-FR-2026-OND',
            'Lanterns.S01E03.1080p.AMZN.WEBRip.HEVC.10bit.AAC5.1-LuCY',
            'Boo_Williams-Tribulation-(BMM90)-24BIT-WEB-FLAC-2020-BABAS',
            'Forza.Horizon.3.v1.0.125.2-RUNE',
        ], array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries));
        $this->assertSame([
            '2026-10-11T21:13:00+00:00',
            '2026-10-11T21:11:11+00:00',
            '2026-10-11T21:10:17+00:00',
            '2026-10-11T20:36:11+00:00',
            '2026-10-11T20:35:53+00:00',
        ], array_map(static fn (PredbFeedEntry $entry): ?string => $entry->listedAt?->toIso8601String(), $entries));
        $this->assertSame(['Music', 'Dance Hall', 'HDTV', 'FLAC', 'Games'], array_map(static fn (PredbFeedEntry $entry): ?string => $entry->category, $entries));
        foreach ($entries as $entry) {
            $this->assertSame('scnlog.me', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
            $this->assertSame($entry->listedAt, $entry->pagingTime());
        }
    }

    #[Test]
    public function latest_feed_fetch_uses_the_published_endpoint_and_page_two_makes_no_request(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://scnlog.me/feed/' => Http::response($this->fixture(), 200, ['Content-Type' => 'text/xml'])]);
        $source = $this->source();

        $this->assertSame('scnlog', $source->key());
        $this->assertCount(5, $source->fetch());
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://scnlog.me/feed/');
    }

    private function source(): RssFeedSource
    {
        return new RssFeedSource('scnlog', 'scnlog.me', 'https://scnlog.me/feed/', mapCategory: true, publicationDatesOnly: true);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/scnlog.xml');
    }
}
