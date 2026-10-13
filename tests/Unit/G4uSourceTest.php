<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\G4uSource;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class G4uSourceTest extends TestCase
{
    private ?Application $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);
        Http::clearResolvedInstance('http');
    }

    protected function tearDown(): void
    {
        Http::clearResolvedInstance('http');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    #[Test]
    public function factual_today_cards_preserve_literal_release_names_and_unknown_metadata(): void
    {
        $entries = (new G4uSource('https://g4u.to/'))->parseHtml($this->fixture());

        $this->assertSame([
            'Granblue.Fantasy.Versus.Rising.Deluxe.Edition.v2.61.MULTi9-ElAmigos',
            'Hot.Wheels.Infinite.Rush.Ultimate.Edition.v1859562.MULTi12-ElAmigos',
            'ilomilo.plus.INTERNAL-RUNE',
            'Halo.Wars.2-RUNE',
            'Forza.Motorsport.7.v1.174.4791.2-RUNE',
            'High_On_Life_Complete_Edition_v134592-Razor1911',
            'Forgive_Me_Father_2_Deluxe_Edition_v1.1.2.16-Razor1911',
            'Endling_Extinction_is_Forever_v0.17.19-Razor1911',
            'Gears.of.War.4.v14.4.0.2-RUNE',
            'Forza.Horizon.3.v1.0.125.2-RUNE',
        ], array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries));
        foreach ($entries as $entry) {
            $this->assertSame('g4u.to', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->pagingTime());
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }

    #[Test]
    public function next_heading_ends_the_today_section_before_older_or_unrecognized_cards(): void
    {
        $html = $this->page($this->card('Today.Release-GRP'), 1);
        $html = str_replace('</div></body>', '<div><h2>Updates from yesterday, 2026-10-10</h2></div><a>Invalid older card</a></div></body>', $html);

        $entries = (new G4uSource('https://g4u.to/'))->parseHtml($html);

        $this->assertCount(1, $entries);
        $this->assertSame('Today.Release-GRP', $entries[0]->title);
    }

    #[Test]
    public function configured_record_limit_is_not_restricted_to_the_research_sample(): void
    {
        $cards = '';
        for ($number = 1; $number <= 12; $number++) {
            $cards .= $this->card('Game.'.$number.'-GRP');
        }
        $html = $this->page($cards, 12);

        $this->assertCount(12, (new G4uSource('https://g4u.to/', pageSize: 50))->parseHtml($html));
        $this->assertCount(2, (new G4uSource('https://g4u.to/', pageSize: 2))->parseHtml($html));
    }

    #[Test]
    public function invalid_cards_after_the_record_limit_still_reject_the_listing(): void
    {
        $this->expectException(RuntimeException::class);
        (new G4uSource('https://g4u.to/', pageSize: 1))->parseHtml($this->page($this->card('Good.Release-GRP').$this->card('Editorial Game Name'), 2));
    }

    #[Test]
    public function unicode_entities_and_punctuation_are_preserved_without_metadata_inference(): void
    {
        $card = $this->card('Léon_天空.DD+5.1.H.264-MixedGroup');
        $card = str_replace('Léon_天空', 'L&#233;on_&#x5929;&#x7a7a;', $card);
        $card = str_replace('</li></ul>', '<div><span class="w3-opacity-min">Windows; 3 GB; 2023</span></div></li></ul>', $card);

        $entries = (new G4uSource('https://g4u.to/'))->parseHtml($this->page($card, 1));

        $this->assertSame('Léon_天空.DD+5.1.H.264-MixedGroup', $entries[0]->title);
        $this->assertNull($entries[0]->category);
        $this->assertNull($entries[0]->size);
        $this->assertNull($entries[0]->predate);
    }

    #[Test]
    public function entities_are_decoded_once(): void
    {
        $entries = (new G4uSource('https://g4u.to/'))->parseHtml($this->page($this->card('Literal.&amp;.Name-GRP'), 1));

        $this->assertSame('Literal.&amp;.Name-GRP', $entries[0]->title);
    }

    #[Test]
    public function maximum_title_length_counts_unicode_characters_without_truncation(): void
    {
        $title = str_repeat('é', 251).'-GRP';
        $entries = (new G4uSource('https://g4u.to/'))->parseHtml($this->page($this->card($title), 1));

        $this->assertSame($title, $entries[0]->title);
    }

    #[Test]
    #[DataProvider('invalidListings')]
    public function unrecognized_or_ambiguous_listing_structures_are_rejected(string $html): void
    {
        $this->expectException(RuntimeException::class);
        (new G4uSource('https://g4u.to/'))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function invalidListings(): array
    {
        $header = '<div><h1>Updates from today, 2026-10-11</h1><span class="w3-right">1</span></div>';
        $card = '<a href="/en/1-editorial-game-name"><ul class="mgame"><li class="mtitel"><b>Editorial Game Name</b><div class="w3-opacity-min">Exact.Release-GRP</div></li></ul></a>';
        $page = '<html><body><div class="w3-content w3-center w3-light-grey">'.$header.$card.'</div></body></html>';

        return [
            'error page' => ['<html><body>Service unavailable</body></html>'],
            'missing today heading' => [str_replace('<h1>Updates from today, 2026-10-11</h1>', '<h2>Other section</h2>', $page)],
            'duplicate today heading' => [str_replace($header, $header.$header, $page)],
            'invalid date' => [str_replace('2026-10-11', '2026-02-31', $page)],
            'missing count' => [str_replace('<span class="w3-right">1</span>', '', $page)],
            'ambiguous count' => [str_replace('<span class="w3-right">1</span>', '<span class="w3-right">1</span><span class="w3-right">1</span>', $page)],
            'count mismatch' => [str_replace('<span class="w3-right">1</span>', '<span class="w3-right">2</span>', $page)],
            'unrecognized card' => [str_replace('class="mgame"', 'class="editorial"', $page)],
            'outside game link' => [str_replace('/en/1-editorial-game-name', 'https://example.test/advertisement', $page)],
            'missing title container' => [str_replace('class="mtitel"', 'class="editorial"', $page)],
            'multiple title containers' => [str_replace('</ul>', '<li class="mtitel"><div class="w3-opacity-min">Other.Release-GRP</div></li></ul>', $page)],
            'missing literal' => [str_replace('<div class="w3-opacity-min">Exact.Release-GRP</div>', '', $page)],
            'multiple literals' => [str_replace('</li>', '<div class="w3-opacity-min">Other.Release-GRP</div></li>', $page)],
            'nested markup' => [str_replace('>Exact.Release-GRP</div>', '><b>Exact.Release-GRP</b></div>', $page)],
            'human title' => [str_replace('Exact.Release-GRP', 'Editorial Game Name', $page)],
            'empty literal' => [str_replace('Exact.Release-GRP', '', $page)],
            'leading whitespace' => [str_replace('Exact.Release-GRP', ' Exact.Release-GRP', $page)],
            'trailing control' => [str_replace('Exact.Release-GRP', 'Exact.Release-GRP&#x0b;', $page)],
            'tab title' => [str_replace('Exact.Release-GRP', "Exact\tRelease-GRP", $page)],
            'control character' => [str_replace('Exact.Release-GRP', 'Exact.&#x7f;Release-GRP', $page)],
            'raw NUL' => [str_replace('Exact.Release-GRP', "Exact.\0Release-GRP", $page)],
            'encoded NUL' => [str_replace('Exact.Release-GRP', 'Exact.&#0;Release-GRP', $page)],
            'encoded surrogate' => [str_replace('Exact.Release-GRP', 'Exact.&#xd800;Release-GRP', $page)],
            'format character' => [str_replace('Exact.Release-GRP', 'Exact.&#x200b;Release-GRP', $page)],
            'invalid UTF8' => [str_replace('Exact.Release-GRP', "Exact.\xc3\x28-GRP", $page)],
            'oversized title' => [str_replace('Exact.Release-GRP', str_repeat('A', 252).'-GRP', $page)],
            'unexpected section content' => [str_replace($card, '<aside>Advertisement</aside>'.$card, $page)],
        ];
    }

    #[Test]
    public function provided_html_accepts_the_exact_body_limit_and_rejects_one_byte_more(): void
    {
        $html = $this->fixture();
        $this->assertCount(10, (new G4uSource('https://g4u.to/', maximumBodyBytes: strlen($html)))->parseHtml($html));
        $this->expectException(RuntimeException::class);
        (new G4uSource('https://g4u.to/', maximumBodyBytes: strlen($html) - 1))->parseHtml($html);
    }

    #[Test]
    public function public_homepage_fetch_is_latest_only(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://g4u.to/' => Http::response($this->fixture(), 200)]);
        $source = new G4uSource('https://g4u.to/');

        $this->assertCount(10, $source->fetch());
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://g4u.to/');
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/predb/g4u.html');
    }

    private function page(string $cards, int $count): string
    {
        return '<html><body><div class="w3-content w3-center w3-light-grey"><div><h1>Updates from today, 2026-10-11</h1><span class="w3-right">'.$count.'</span></div>'.$cards.'</div></body></html>';
    }

    private function card(string $title): string
    {
        return '<a href="/en/1-editorial-game-name"><ul class="mgame"><li class="mtitel"><b>Editorial Game Name</b><div class="w3-opacity-min">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</div></li></ul></a>';
    }
}
