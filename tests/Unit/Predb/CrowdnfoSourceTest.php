<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Sources\CrowdnfoSource;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CrowdnfoSourceTest extends TestCase
{
    #[Test]
    public function captured_public_headings_remain_literal_catalogue_names(): void
    {
        $entries = (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($this->fixture());

        $this->assertCount(20, $entries);
        $this->assertSame('American.Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP', $entries[0]->title);
        $this->assertSame('American.Pickers.Die.Troedelsammler.S09E29.Auf.Kneipentour.German.DOKU.WebRip.x264-ATAX', $entries[19]->title);
        foreach ($entries as $entry) {
            $this->assertSame('crowdnfo.net', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }

    #[Test]
    public function every_card_is_validated_before_the_record_cap(): void
    {
        $source = new CrowdnfoSource('https://crowdnfo.net/', pageSize: 1);
        $this->assertCount(1, $source->parseHtml($this->fixture()));
        $this->expectException(RuntimeException::class);
        $source->parseHtml(str_replace('/release/312863', '/release/editorial', $this->fixture()));
    }

    #[Test]
    #[DataProvider('invalidChanges')]
    public function malformed_or_ambiguous_public_cards_are_rejected(string $original, string $replacement): void
    {
        $this->expectException(RuntimeException::class);
        (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml(str_replace($original, $replacement, $this->fixture()));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidChanges(): array
    {
        $title = 'American.Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP';

        return [
            'missing recent scope' => ['Recent Releases', 'Editorial Releases'],
            'duplicate scope' => ['Recent Releases</h2>', 'Recent Releases</h2><h2>Recent Releases</h2>'],
            'missing heading' => ['class="card-title"', 'class="editorial-title"'],
            'non-numeric detail' => ['/release/312884', '/release/editorial'],
            'duplicate detail identity' => ['/release/312883', '/release/312884'],
            'duplicate literal title' => ['Home.Die.Geschichte.einer.Reise.GERMAN.DOKU.WS.BDRip.XviD-TVP', $title],
            'multiple heading links' => ['href="/release/312884">', 'href="/release/312884"></a><a href="/release/999">'],
            'nested heading markup' => [$title, '<strong>'.$title.'</strong>'],
            'extra heading text' => ['<h5 class="card-title">', '<h5 class="card-title">Editorial title'],
            'extra heading element' => ['<h5 class="card-title">', '<h5 class="card-title"><span>Editorial</span>'],
            'spaced display name' => [$title, 'American Pickers S02E06-TVP'],
            'truncated ellipsis' => [$title, 'American.Pickers...-TVP'],
            'unicode ellipsis' => [$title, 'American.Pickers…-TVP'],
            'empty title' => [$title, ''],
            'overlength title' => [$title, str_repeat('a', 250).'.Release-GRP'],
            'control title' => [$title, 'American.&#x7f;Pickers-TVP'],
            'missing release group suffix' => [$title, 'American.Pickers.S02E06'],
        ];
    }

    #[Test]
    public function supplied_body_limit_is_enforced(): void
    {
        $this->expectException(RuntimeException::class);
        (new CrowdnfoSource('https://crowdnfo.net/', maximumBodyBytes: 100))->parseHtml($this->fixture());
    }

    #[Test]
    public function invalid_utf8_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml("\xff");
    }

    #[Test]
    public function literal_unicode_and_punctuation_are_preserved(): void
    {
        $title = 'Léon_天空.1994.DD+5.1.H.264-MixedGroup';
        $html = str_replace('American.Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP', $title, $this->fixture());

        $entries = (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);

        $this->assertSame($title, $entries[0]->title);
    }

    #[Test]
    public function latest_fetch_uses_the_bounded_transport_and_exact_endpoint(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://crowdnfo.net/' => Http::response($this->fixture())]);

        $entries = (new CrowdnfoSource('https://crowdnfo.net/'))->fetch();

        $this->assertCount(20, $entries);
        Http::assertSentCount(1);
    }

    #[Test]
    public function invalid_page_numbers_are_rejected_without_requesting(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $this->expectException(RuntimeException::class);
        (new CrowdnfoSource('https://crowdnfo.net/'))->fetch(0);
    }

    #[Test]
    public function later_pages_make_no_request(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $this->assertSame([], (new CrowdnfoSource('https://crowdnfo.net/'))->fetch(2));
        Http::assertNothingSent();
    }

    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/predb/crowdnfo-home.html');
    }
}
