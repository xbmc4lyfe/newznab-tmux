<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\Feeds\Sources\CrowdnfoSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CrowdnfoMalformedInputTest extends TestCase
{
    private const TITLE = 'American.Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP';

    #[DataProvider('invalidTitleBytes')]
    public function test_control_bytes_and_invalid_references_cannot_be_removed_from_literal_titles(string $insert): void
    {
        $html = str_replace(self::TITLE, 'American.'.$insert.'Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP', $this->fixture());
        $this->expectException(RuntimeException::class);

        (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function invalidTitleBytes(): array
    {
        return [
            'raw SOH' => ["\x01"],
            'raw form feed' => ["\x0c"],
            'raw BMP noncharacter FFFE' => ["\u{FFFE}"],
            'raw BMP noncharacter FFFF' => ["\u{FFFF}"],
            'decimal NUL reference' => ['&#0;'],
            'hex NUL reference' => ['&#x0;'],
            'decimal control reference' => ['&#1;'],
            'hex control reference' => ['&#x01;'],
            'unterminated control reference' => ['&#0'],
            'surrogate reference' => ['&#xD800;'],
            'decimal BMP noncharacter FFFE' => ['&#65534;'],
            'decimal BMP noncharacter FFFF' => ['&#65535;'],
            'hex BMP noncharacter FFFE' => ['&#xFFFE;'],
            'hex BMP noncharacter FFFF' => ['&#xFFFF;'],
            'out of range reference' => ['&#x110000;'],
            'overflow decimal reference' => ['&#999999999999999999999999999999;'],
        ];
    }

    #[DataProvider('duplicateAttributes')]
    public function test_duplicate_detail_attributes_do_not_choose_a_repaired_identity(string $attribute): void
    {
        $html = str_replace('href="/release/312884"', 'href="/release/312884" '.$attribute, $this->fixture());
        $this->expectException(RuntimeException::class);

        (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function duplicateAttributes(): array
    {
        return ['different detail ID' => ['href="/release/999999"'], 'same detail ID' => ['href="/release/312884"'], 'mixed case attribute' => ['HREF="/release/999999"']];
    }

    public function test_truncated_heading_cannot_be_repaired_into_a_complete_listing(): void
    {
        $fixture = $this->fixture();
        $html = substr($fixture, 0, strpos($fixture, self::TITLE) + strlen(self::TITLE));
        $this->expectException(RuntimeException::class);

        (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);
    }

    public function test_incomplete_scope_is_rejected_even_with_a_document_closing_suffix(): void
    {
        $fixture = $this->fixture();
        $html = substr($fixture, 0, strpos($fixture, self::TITLE) + strlen(self::TITLE)).'</body></html>';
        $this->expectException(RuntimeException::class);

        (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);
    }

    public function test_complete_publisher_page_preserves_literal_numeric_references_and_layout_whitespace(): void
    {
        $html = str_replace(self::TITLE, 'L&#233;on_&#x5929;&#x7A7A;.1994.DD+5.1.H.264-MixedGroup', $this->fixture());

        $entries = (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);

        $this->assertCount(20, $entries);
        $this->assertSame('Léon_天空.1994.DD+5.1.H.264-MixedGroup', $entries[0]->title);
    }

    public function test_tag_like_text_in_comments_and_scripts_does_not_change_listing_boundaries(): void
    {
        $ignored = '<!-- <div><a href="/release/9"> -->'."<script>const ignored = \"<div><a href='/release/9'>\";</script>";
        $html = str_replace('</body>', $ignored.'</body>', $this->fixture());

        $entries = (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);

        $this->assertCount(20, $entries);
        $this->assertSame(self::TITLE, $entries[0]->title);
    }

    #[DataProvider('unterminatedIgnoredContent')]
    public function test_unterminated_non_listing_content_cannot_supply_document_closures(string $opening): void
    {
        $html = str_replace('</body>', $opening.'</body>', $this->fixture());
        $this->expectException(RuntimeException::class);

        (new CrowdnfoSource('https://crowdnfo.net/'))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function unterminatedIgnoredContent(): array
    {
        return ['comment' => ['<!-- unfinished'], 'script' => ['<script>const unfinished = "']];
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/predb/crowdnfo-home.html');
    }
}
