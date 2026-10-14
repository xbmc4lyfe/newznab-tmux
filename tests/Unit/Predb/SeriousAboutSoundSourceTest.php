<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Sources\SeriousAboutSoundSource;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeriousAboutSoundSourceTest extends TestCase
{
    private const ENDPOINT = 'https://seriousaboutsound.net/forum/48-releases/?sortby=start_date&sortdirection=desc';

    public function test_factual_listing_subset_preserves_canonical_music_headings_only(): void
    {
        $entries = (new SeriousAboutSoundSource(self::ENDPOINT))->parseHtml($this->fixture());
        self::assertSame([
            'Nerutto_-_Elements-(DP26)-SINGLE-WEB-2022-iDC',
            'Stretch_And_Vern_X_SEVEK-Im_Alive_(Extended_Mix)-(ARMAS3875)-SINGLE-WEB-2026-AOV',
            'CJ_Page_and_ataege-Brekete-(CVIP1061)-WEB-2026-PTC',
        ], array_column($entries, 'title'));
        foreach ($entries as $entry) {
            self::assertSame('seriousaboutsound.net', $entry->source);
            self::assertNull($entry->predate);
            self::assertNull($entry->listedAt);
            self::assertNull($entry->category);
            self::assertNull($entry->size);
            self::assertNull($entry->files);
        }
    }

    public function test_whole_scope_is_validated_before_cap(): void
    {
        $source = new SeriousAboutSoundSource(self::ENDPOINT, pageSize: 1);
        self::assertCount(1, $source->parseHtml($this->fixture()));
        $this->expectException(RuntimeException::class);
        $source->parseHtml(str_replace('data-rowid="53220"', 'data-rowid="53257"', $this->fixture()));
    }

    #[DataProvider('invalidMutations')]
    public function test_invalid_or_ambiguous_literal_listing_is_rejected(string $from, string $to): void
    {
        $this->expectException(RuntimeException::class);
        (new SeriousAboutSoundSource(self::ENDPOINT))->parseHtml(str_replace($from, $to, $this->fixture()));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidMutations(): array
    {
        $title = 'Nerutto_-_Elements-(DP26)-SINGLE-WEB-2022-iDC';

        return [
            'wrong scope' => ['data-role="tableRows"', 'data-role="editorial"'],
            'duplicate scope' => ['</ol>', '</ol><ol class="cForumTopicTable" data-role="tableRows"></ol>'],
            'missing row identity' => ['data-rowid="53257"', 'data-rowid=""'],
            'wrong numeric link association' => ['/topic/53257-', '/topic/999-'],
            'foreign link' => ['https://seriousaboutsound.net/topic/53257-', 'https://other.example/topic/53257-'],
            'query on detail' => ['dp26-single-web-2022-idc/', 'dp26-single-web-2022-idc/?preview=1'],
            'wrong author identity' => ['profile/1294-rls-bot/', 'profile/999-other/'],
            'wrong author label' => ['>RLS Bot</a>', '>Other Publisher</a>'],
            'missing author scope' => ['class="ipsDataItem_meta"', 'class="editorial_meta"'],
            'wrong heading role' => ['ipsDataItem_title ipsContained_container', 'editorial_title'],
            'empty extra heading element' => ['<h4 class="ipsDataItem_title ipsContained_container">', '<h4 class="ipsDataItem_title ipsContained_container"><span></span>'],
            'unknown pinned discussion' => ['data-rowid="1374"', 'data-rowid="999"'],
            'unexpected pinned release' => ['<h4 class="ipsDataItem_title ipsContained_container">', '<h4 class="ipsDataItem_title ipsContained_container"><span class="ipsBadge" title="Pinned"></span>'],
            'additional heading text' => ['<h4 class="ipsDataItem_title ipsContained_container">', '<h4 class="ipsDataItem_title ipsContained_container">Editorial'],
            'multiple heading links' => ['<span>'.$title.'</span></a>', '<span>'.$title.'</span></a><a href="https://seriousaboutsound.net/topic/999-other/">Other-GRP</a>'],
            'inline editorial markup' => ['<span>'.$title.'</span>', '<span><strong>'.$title.'</strong></span>'],
            'spaced editorial title' => [$title, 'Nerutto Elements 2022-iDC'],
            'missing group' => [$title, 'Nerutto_Elements_WEB_2022'],
            'ellipsis' => [$title, 'Nerutto...-iDC'],
            'unicode ellipsis' => [$title, 'Nerutto…-iDC'],
            'control reference' => [$title, 'Nerutto_&#x0;Elements-iDC'],
            'unicode control' => [$title, 'Nerutto_&#x200b;Elements-iDC'],
            'invalid reference' => [$title, 'Nerutto_&#x110000;Elements-iDC'],
            'overlength' => [$title, str_repeat('A', 256).'-iDC'],
            'empty name' => [$title, ''],
            'case duplicate title' => ['CJ_Page_and_ataege-Brekete-(CVIP1061)-WEB-2026-PTC', strtolower($title)],
            'duplicate attribute' => ['data-rowid="53257"', 'data-rowid="53257" data-rowid="999"'],
            'truncated document' => ['</body></html>', ''],
            'truncated row' => ['</h4>', ''],
            'invalid UTF8' => [$title, "Nerutto_\xff-iDC"],
        ];
    }

    public function test_literal_unicode_punctuation_is_not_transliterated(): void
    {
        $name = 'Rosalía_天空-(CAT1)-WEB-2026-GRP';
        $entries = (new SeriousAboutSoundSource(self::ENDPOINT))->parseHtml(str_replace('Nerutto_-_Elements-(DP26)-SINGLE-WEB-2022-iDC', $name, $this->fixture()));
        self::assertSame($name, $entries[0]->title);
    }

    public function test_fetch_requests_only_advertised_listing_and_later_pages_are_empty(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([self::ENDPOINT => Http::response($this->fixture())]);
        $source = new SeriousAboutSoundSource(self::ENDPOINT);
        self::assertSame('seriousaboutsound', $source->key());
        self::assertCount(3, $source->fetch());
        self::assertSame([], $source->fetch(2));
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request) => $request->url() === self::ENDPOINT);
    }

    public function test_invalid_page_makes_no_request(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        try {
            (new SeriousAboutSoundSource(self::ENDPOINT))->fetch(0);
            self::fail('Expected invalid page rejection.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public function test_body_is_bounded_before_parsing(): void
    {
        $this->expectException(RuntimeException::class);
        (new SeriousAboutSoundSource(self::ENDPOINT, maximumBodyBytes: 100))->parseHtml($this->fixture());
    }

    private function fixture(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/seriousaboutsound-releases.html');
    }
}
