<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\GabberSource;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GabberSourceTest extends TestCase
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
    public function latest_directory_names_keep_identity_and_unknown_metadata(): void
    {
        $source = new GabberSource('https://gabber.od.ua/0day');
        $entries = $source->parseHtml($this->fixture());

        $this->assertSame('gabber', $source->key());
        $this->assertSame([
            'A-Kriv_-_Concentrate_2-(TWS61)-WEB-FLAC-2026',
            'Eraized_-_Merengue-(GBO188)-WEB-FLAC-2026',
            'ADLBN_-_Now_EP-(BSR036)-WEB-FLAC-2026',
            'Select_and_Dionne_-_Stay_Forever_Tonight-(NTWO172)-WEB-2026',
            'LSB_and_Vektah_-_Penny_Drop___Gin_Pan_Alley-(PLV255)-WEB-FLAC-2026',
        ], $this->titles($entries));
        foreach ($entries as $entry) {
            $this->assertSame('gabber.od.ua', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->pagingTime());
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }

    #[Test]
    public function hrefs_and_older_content_never_supply_release_identity(): void
    {
        $html = preg_replace('/href="[^"]*"/', 'href="#unrelated-slug"', $this->fixture());
        $html = str_replace('Older content is outside the latest scope.', '<a>Invented_Older_Release-WEB-2026</a>', $html);

        $this->assertSame($this->titles((new GabberSource('https://gabber.od.ua/0day'))->parseHtml($this->fixture())), $this->titles((new GabberSource('https://gabber.od.ua/0day'))->parseHtml($html)));
    }

    #[Test]
    public function case_only_duplicates_keep_first_spelling_before_the_record_cap(): void
    {
        $entries = (new GabberSource('https://gabber.od.ua/0day', pageSize: 2))->parseHtml(self::page([
            "DJ_OSkar_-_I'm_Over_You-WEB-2026", "dj_oskar_-_i'm_over_you-web-2026", 'DJ_OSkar_-_Im_Over_You-WEB-2026',
        ]));

        $this->assertSame(["DJ_OSkar_-_I'm_Over_You-WEB-2026", 'DJ_OSkar_-_Im_Over_You-WEB-2026'], $this->titles($entries));
    }

    #[Test]
    public function entities_decode_once_and_unicode_punctuation_need_no_group_suffix(): void
    {
        $titles = ["Léon_天空_(It's_Music)_DD+5.1-WEB-2026", 'Literal_&amp;_Name-WEB-2026'];

        $this->assertSame($titles, $this->titles((new GabberSource('https://gabber.od.ua/0day'))->parseHtml(self::page($titles))));
    }

    #[Test]
    public function latest_scope_is_not_restricted_to_the_research_sample(): void
    {
        $titles = array_map(static fn (int $n): string => 'Music_'.$n.'-WEB-2026', range(1, 42));

        $this->assertCount(42, (new GabberSource('https://gabber.od.ua/0day'))->parseHtml(self::page($titles)));
        $this->assertCount(2, (new GabberSource('https://gabber.od.ua/0day', pageSize: 2))->parseHtml(self::page($titles)));
    }

    #[Test]
    public function malformed_names_beyond_the_record_cap_still_reject_the_listing(): void
    {
        $this->expectException(RuntimeException::class);
        (new GabberSource('https://gabber.od.ua/0day', pageSize: 1))->parseHtml(self::page(['Music-WEB-2026', 'Human album heading']));
    }

    #[Test]
    public function title_limit_counts_unicode_characters_without_truncation(): void
    {
        $title = str_repeat('é', 246).'-WEB-2026';

        $this->assertSame([$title], $this->titles((new GabberSource('https://gabber.od.ua/0day'))->parseHtml(self::page([$title]))));
    }

    #[Test]
    #[DataProvider('invalidListings')]
    public function malformed_or_ambiguous_latest_structures_are_rejected(string $html): void
    {
        $this->expectException(RuntimeException::class);
        (new GabberSource('https://gabber.od.ua/0day'))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function invalidListings(): array
    {
        $page = self::page(['Exact_Title-WEB-2026']);
        $header = '<h3><a><span class="date-display-single">2026-10-10</span></a></h3>';
        $row = substr($page, strpos($page, '<div class="views-row">'), -12);

        return [
            'error page' => ['<html><body>Unavailable</body></html>'],
            'human RSS' => ['<rss><channel><item><title>Artist - Human Album Title</title></item></channel></rss>'],
            'duplicate catalogue' => [$page.$page],
            'missing content' => [str_replace('view-content', 'editorial', $page)],
            'duplicate content' => [str_replace('class="view-id-0day_releases">', 'class="view-id-0day_releases"><div class="view-content"></div>', $page)],
            'missing day' => [str_replace($header, '', $page)],
            'invalid date' => [str_replace('2026-10-10', '2026-02-31', $page)],
            'nested date' => [str_replace('>2026-10-10<', '><b>2026-10-10</b><', $page)],
            'multiple dates' => [str_replace('</a></h3>', '<span class="date-display-single">2026-10-10</span></a></h3>', $page)],
            'extra heading link' => [str_replace('</h3>', '<a>Other</a></h3>', $page)],
            'content before day' => [str_replace($header, '<aside>Editorial</aside>'.$header, $page)],
            'empty day' => ['<div class="view-id-0day_releases"><div class="view-content">'.$header.'</div></div>'],
            'unrecognized latest node' => [str_replace($header, $header.'<aside>Editorial</aside>', $page)],
            'unexpected latest text' => [str_replace($header, $header.'Editorial', $page)],
            'unrecognized row' => [str_replace('class="views-row"', 'class="editorial"', $page)],
            'missing genre' => [str_replace('views-field-field-genre', 'editorial', $page)],
            'duplicate genre field' => [str_replace('<div class="views-field-body">', '<div class="views-field-field-genre"><div class="field-content">Hardcore</div></div><div class="views-field-body">', $page)],
            'duplicate genre row' => [str_replace($row, $row.$row, $page)],
            'unknown genre' => [str_replace('>Hardcore<', '>Requests<', $page)],
            'list disagrees with genre' => [str_replace('0day-hardcore', '0day-hardstyle', $page)],
            'nested genre' => [str_replace('>Hardcore<', '><b>Hardcore</b><', $page)],
            'extra body child' => [str_replace('</ul>', '</ul><a>Hidden_Name-WEB-2026</a>', $page)],
            'extra row child' => [str_replace('<div class="views-field-body">', '<aside>Editorial</aside><div class="views-field-body">', $page)],
            'duplicate directory list' => [str_replace('</ul>', '</ul><ul class="0day-hardcore"><li><a>Other_Name-WEB-2026</a></li></ul>', $page)],
            'empty directory list' => [str_replace('<li><a href="#irrelevant">Exact_Title-WEB-2026</a></li>', '', $page)],
            'unexpected list child' => [str_replace('</ul>', '<div>Hidden_Name-WEB-2026</div></ul>', $page)],
            'extra item link' => [str_replace('</li>', '<a>Other_Name-WEB-2026</a></li>', $page)],
            'extra item text' => [str_replace('</a></li>', '</a>Extra</li>', $page)],
            'nested literal' => [str_replace('>Exact_Title-WEB-2026<', '><b>Exact_Title-WEB-2026</b><', $page)],
            'human literal' => [str_replace('Exact_Title-WEB-2026', 'Human Album Title', $page)],
            'empty literal' => [str_replace('Exact_Title-WEB-2026', '', $page)],
            'leading space' => [str_replace('Exact_Title-WEB-2026', ' Exact_Title-WEB-2026', $page)],
            'internal tab' => [str_replace('Exact_Title-WEB-2026', "Exact\tTitle-WEB-2026", $page)],
            'NBSP' => [str_replace('Exact_Title-WEB-2026', 'Exact&nbsp;Title-WEB-2026', $page)],
            'format character' => [str_replace('Exact_Title-WEB-2026', 'Exact&#x200b;Title-WEB-2026', $page)],
            'DEL' => [str_replace('Exact_Title-WEB-2026', 'Exact&#x7f;Title-WEB-2026', $page)],
            'raw NUL' => [str_replace('Exact_Title-WEB-2026', "Exact\0Title-WEB-2026", $page)],
            'encoded NUL' => [str_replace('Exact_Title-WEB-2026', 'Exact&#0;Title-WEB-2026', $page)],
            'surrogate' => [str_replace('Exact_Title-WEB-2026', 'Exact&#xd800;Title-WEB-2026', $page)],
            'invalid UTF8' => [str_replace('Exact_Title-WEB-2026', "Exact\xc3\x28-WEB-2026", $page)],
            'overlength identity' => [str_replace('Exact_Title-WEB-2026', str_repeat('é', 247).'-WEB-2026', $page)],
        ];
    }

    #[Test]
    public function supplied_html_accepts_exact_body_limit_and_rejects_one_byte_more(): void
    {
        $html = $this->fixture();
        $this->assertCount(5, (new GabberSource('https://gabber.od.ua/0day', maximumBodyBytes: strlen($html)))->parseHtml($html));
        $this->expectException(RuntimeException::class);
        (new GabberSource('https://gabber.od.ua/0day', maximumBodyBytes: strlen($html) - 1))->parseHtml($html);
    }

    #[Test]
    public function fetch_uses_one_bounded_gzip_request_without_a_second_page_or_redirects(): void
    {
        $factory = (new Factory)->preventStrayRequests();
        $factory->fake(function ($request, array $options) {
            $this->assertSame('https://gabber.od.ua/0day', $request->url());
            $this->assertTrue($options['stream']);
            $this->assertFalse($options['decode_content']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('gzip', $request->header('Accept-Encoding')[0]);

            return Create::promiseFor(new Response(200, ['Content-Encoding' => 'gzip'], gzencode($this->fixture())));
        });
        Http::swap($factory);
        $source = new GabberSource('https://gabber.od.ua/0day');

        $this->assertCount(5, $source->fetch());
        $this->assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
    }

    #[Test]
    #[DataProvider('failedStatuses')]
    public function failures_emit_status_only_errors_after_one_attempt(int $status): void
    {
        $factory = (new Factory)->preventStrayRequests();
        $factory->fake(function ($request, array $options) use ($status) {
            $this->assertFalse($options['allow_redirects']);

            return Create::promiseFor(new Response($status, ['Location' => 'https://example.test/private', 'Retry-After' => '0'], 'PRIVATE_PAYLOAD'));
        });
        Http::swap($factory);

        $failure = null;
        try {
            (new GabberSource('https://gabber.od.ua/0day'))->fetch();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertStringNotContainsString('PRIVATE_PAYLOAD', $failure->getMessage());
        $this->assertNotInstanceOf(RequestException::class, $failure);
        if ($status >= 400) {
            $this->assertInstanceOf(RequestException::class, $failure->getPrevious());
            $this->assertStringContainsString((string) $status, $failure->getMessage());
        }
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int}> */
    public static function failedStatuses(): array
    {
        return ['server error' => [503], 'rate limit without command backoff' => [429], 'redirect' => [302]];
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/predb/gabber.html');
    }

    /** @param list<string> $titles */
    private static function page(array $titles): string
    {
        $items = '';
        foreach ($titles as $title) {
            $items .= '<li><a href="#irrelevant">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</a></li>';
        }

        return '<div class="view-id-0day_releases"><div class="view-content"><h3><a><span class="date-display-single">2026-10-10</span></a></h3><div class="views-row"><div class="views-field-field-genre"><div class="field-content">Hardcore</div></div><div class="views-field-body"><div class="field-content"><ul class="0day-hardcore">'.$items.'</ul></div></div></div></div></div>';
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     * @return list<string>
     */
    private function titles(array $entries): array
    {
        return array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries);
    }
}
