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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MovieblogRssTransportTest extends TestCase
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
    public function observed_leading_line_feed_preserves_factual_titles_and_publication_dates(): void
    {
        $this->assertStringStartsWith("\n<?xml", $this->fixture());
        $entries = $this->source()->parseXml($this->fixture());

        $this->assertSame([
            'Wildauge.The.Midwife.2015.German.720p.BluRay.x264-TiG',
            'Wildauge.The.Midwife.2015.German.BDRip.x264-TiG',
            'Camp.Rock.3.2026.German.DL.1080p.DV.HDR.WEBRip.AV1-WOTT',
        ], array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries));
        $this->assertSame([
            '2026-10-11T14:02:24+00:00',
            '2026-10-11T14:02:21+00:00',
            '2026-10-11T10:40:37+00:00',
        ], array_map(static fn (PredbFeedEntry $entry): ?string => $entry->listedAt?->toIso8601String(), $entries));
        foreach ($entries as $entry) {
            $this->assertSame('movieblog.to', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }

    #[Test]
    #[DataProvider('xmlWhitespacePrefixes')]
    public function ascii_xml_transport_whitespace_before_the_declaration_is_accepted(string $prefix): void
    {
        $entries = $this->source()->parseXml($prefix.substr($this->fixture(), 1));

        $this->assertCount(3, $entries);
        $this->assertSame('Wildauge.The.Midwife.2015.German.720p.BluRay.x264-TiG', $entries[0]->title);
    }

    /** @return array<string, array{string}> */
    public static function xmlWhitespacePrefixes(): array
    {
        return [
            'none' => [''],
            'space' => [' '],
            'tab' => ["\t"],
            'carriage return' => ["\r"],
            'line feed' => ["\n"],
            'mixed XML whitespace' => [" \t\r\n\n\t "],
        ];
    }

    #[Test]
    #[DataProvider('invalidDeclarationPrefixes')]
    public function non_xml_whitespace_or_content_before_the_declaration_remains_invalid(string $prefix): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('movieblog.to returned an invalid RSS document.');

        $this->source()->parseXml($prefix.substr($this->fixture(), 1));
    }

    /** @return array<string, array{string}> */
    public static function invalidDeclarationPrefixes(): array
    {
        return [
            'NUL' => ["\0"],
            'vertical tab' => ["\x0B"],
            'form feed' => ["\x0C"],
            'nonbreaking space' => ["\xC2\xA0"],
            'whitespace then NUL' => [" \n\0"],
            'text' => ['unexpected'],
            'comment' => ['<!-- unexpected prefix -->'],
        ];
    }

    #[Test]
    public function transport_normalization_does_not_rewrite_entities_unicode_or_literal_names(): void
    {
        $entries = $this->source()->parseXml("\n<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss><channel>"
            .'<item><title><![CDATA[Rock_&amp;_Roll-MiXeD]]></title></item>'
            .'<item><title>Été_&amp;_Österreich-GRP</title></item>'
            .'</channel></rss>');

        $this->assertSame(['Rock_&amp;_Roll-MiXeD', 'Été_&_Österreich-GRP'], array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries));
        $this->assertNull($entries[0]->listedAt);
        $this->assertNull($entries[1]->listedAt);
    }

    #[Test]
    public function descriptions_and_categories_do_not_supply_release_identity_or_other_metadata(): void
    {
        $entries = $this->source()->parseXml("\n<?xml version=\"1.0\"?><rss><channel>"
            .'<item><title>Editorial movie heading</title><description>Release Name: Guessed.Film-GRP</description></item>'
            .'<item><title>Film_With_Underscores-MiXeD</title><category>Movies</category>'
            .'<description>Size: 3.1 GB; release date: 2001-01-01</description>'
            .'<pubDate>Sun, 11 Oct 2026 14:02:24 +0000</pubDate></item>'
            .'</channel></rss>');

        $this->assertCount(1, $entries);
        $this->assertSame('Film_With_Underscores-MiXeD', $entries[0]->title);
        $this->assertSame('2026-10-11T14:02:24+00:00', $entries[0]->listedAt?->toIso8601String());
        $this->assertNull($entries[0]->category);
        $this->assertNull($entries[0]->size);
        $this->assertNull($entries[0]->files);
        $this->assertNull($entries[0]->predate);
    }

    #[Test]
    public function latest_feed_fetch_uses_one_published_endpoint_and_page_two_makes_no_request(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://movieblog.to/feed/' => Http::response($this->fixture(), 200, ['Content-Type' => 'text/xml'])]);
        $source = $this->source();

        $this->assertCount(3, $source->fetch());
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://movieblog.to/feed/');
    }

    #[Test]
    public function configured_page_size_limits_the_latest_literal_items(): void
    {
        $entries = $this->source(pageSize: 1)->parseXml($this->fixture());

        $this->assertCount(1, $entries);
        $this->assertSame('Wildauge.The.Midwife.2015.German.720p.BluRay.x264-TiG', $entries[0]->title);
    }

    private function source(int $pageSize = 100): RssFeedSource
    {
        return new RssFeedSource('movieblog', 'movieblog.to', 'https://movieblog.to/feed/', $pageSize, publicationDatesOnly: true);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/movieblog.xml');
    }
}
