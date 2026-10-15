<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\Nima4kSource;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class Nima4kSourceTest extends TestCase
{
    #[Test]
    public function public_listing_preserves_all_ten_printed_names_without_guessing_metadata(): void
    {
        $entries = (new Nima4kSource('https://nima4k.org/'))->parseHtml($this->fixture());

        $this->assertSame([
            'Formel.1.2026.GP.Singapur.Rennen.German.2160p.UHDTV.HDR.HEVC-NIMA4K',
            'The.Furious.2025.German.Atmos.DL.2160p.UHD.BluRay.DV.HDR.HEVC.Remux-NIMA4K',
            'Formel.1.2026.GP.Bahrain.Rennen.German.2160p.UHDTV.HDR.HEVC-NIMA4K',
            'Formel.1.2026.GP.Aserbaidschan.Rennen.German.2160p.UHDTV.HDR.HEVC-NIMA4K',
            'Formel.1.2026.GP.Spanien.Rennen.German.2160p.UHDTV.HDR.HEVC-NIMA4K',
            'Formel.1.2026.GP.Italien.Rennen.German.2160p.UHDTV.HDR.HEVC-NIMA4K',
            'Formel.1.2026.GP.Niederlande.Rennen.German.2160p.UHDTV.HDR.HEVC-NIMA4K',
            'Minions.und.Monster.2026.German.EAC3D.DL.2160p.Hybrid.WEB.DV.HDR10Plus.HEVC-QfG',
            'The.Mandalorian.and.Grogu.2026.IMAX.German.DL.2160p.UHD.BluRay.DV.HDR.HEVC.Remux-NIMA4K',
            'Nuernberg.2025.German.DTSHD.Dubbed.DL.2160p.UHD.BluRay.DV.HDR.HEVC.Hybrid.Remux-QfG',
        ], array_map(static fn (PredbFeedEntry $entry): string => $entry->title, $entries));
        foreach ($entries as $entry) {
            $this->assertSame('nima4k.org', $entry->source);
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->category);
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }

    #[Test]
    public function record_limit_applies_without_rewriting_literals(): void
    {
        $source = new Nima4kSource('https://nima4k.org/', pageSize: 1);
        $entries = $source->parseHtml($this->card('Léon_天空.1994.DD+5.1.H.264-MixedGroup').$this->card('Second.Release-GRP'));

        $this->assertCount(1, $entries);
        $this->assertSame('Léon_天空.1994.DD+5.1.H.264-MixedGroup', $entries[0]->title);
    }

    #[Test]
    #[DataProvider('invalidListings')]
    public function wrong_or_ambiguous_structures_are_rejected(string $html): void
    {
        $this->expectException(RuntimeException::class);
        (new Nima4kSource('https://nima4k.org/'))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function invalidListings(): array
    {
        $card = '<div class="article"><div class="cover-box"><img alt="Exact.Release-GRP"></div><div class="article-right"><h2><span class="subtitle">Exact.Release-GRP</span></h2></div></div>';

        return [
            'error page' => ['<html><body>Service unavailable</body></html>'],
            'global subtitle' => ['<span class="subtitle">Exact.Release-GRP</span>'],
            'missing subtitle' => [str_replace('subtitle', 'editorial', $card)],
            'multiple subtitles' => [str_replace('</h2>', '<span class="subtitle">Other.Release-GRP</span></h2>', $card)],
            'cover disagreement' => [str_replace('alt="Exact.Release-GRP"', 'alt="Other.Release-GRP"', $card)],
            'missing cover' => [str_replace('<img alt="Exact.Release-GRP">', '', $card)],
            'multiple covers' => [str_replace('<img alt="Exact.Release-GRP">', '<img alt="Exact.Release-GRP"><img alt="Exact.Release-GRP">', $card)],
            'human title' => [str_replace('Exact.Release-GRP', 'Exact Release-GRP', $card)],
            'tab title' => [str_replace('Exact.Release-GRP', "Exact\tRelease-GRP", $card)],
            'empty title' => [str_replace('Exact.Release-GRP', '', $card)],
            'control character' => [str_replace('Exact.Release-GRP', 'Exact.&#x7f;Release-GRP', $card)],
            'nul numeric reference repaired before comparison' => [str_replace('Exact.Release-GRP</span>', 'Exact.&#0;Release-GRP</span>', $card)],
            'nested markup' => [str_replace('>Exact.Release-GRP</span>', '><b>Exact.Release-GRP</b></span>', $card)],
        ];
    }

    #[Test]
    #[DataProvider('malformedLiteralCharacters')]
    public function malformed_characters_cannot_be_repaired_into_agreeing_names(string $character, bool $inCover): void
    {
        $card = $this->card('Exact.Release-GRP');
        $html = $inCover
            ? str_replace('alt="Exact.Release-GRP"', 'alt="Exact.'.$character.'Release-GRP"', $card)
            : str_replace('>Exact.Release-GRP</span>', '>Exact.'.$character.'Release-GRP</span>', $card);

        $this->expectException(RuntimeException::class);
        (new Nima4kSource('https://nima4k.org/'))->parseHtml($html);
    }

    /** @return array<string, array{string, bool}> */
    public static function malformedLiteralCharacters(): array
    {
        $characters = [
            'raw nul' => "\0",
            'raw low control' => "\x01",
            'raw backspace' => "\x08",
            'raw vertical tab' => "\x0b",
            'raw form feed fails closed' => "\x0c",
            'raw shift out' => "\x0e",
            'raw unit separator' => "\x1f",
            'raw delete' => "\x7f",
            'raw C1 start' => "\u{80}",
            'raw C1 end' => "\u{9f}",
            'raw noncharacter range start' => "\u{fdd0}",
            'raw noncharacter range end' => "\u{fdef}",
            'raw BMP plane end' => "\u{fffe}",
            'raw BMP final codepoint' => "\u{ffff}",
            'raw supplementary plane end' => "\u{1fffe}",
            'raw final plane end' => "\u{10ffff}",
            'decimal low control' => '&#1;',
            'hex low control' => '&#x1;',
            'padded uppercase control' => '&#X00001;',
            'semicolonless control' => '&#1',
            'numeric backspace' => '&#8;',
            'numeric vertical tab' => '&#11;',
            'numeric form feed fails closed' => '&#12;',
            'numeric shift out' => '&#14;',
            'numeric unit separator' => '&#31;',
            'numeric C1 start' => '&#128;',
            'numeric C1 end' => '&#159;',
            'numeric noncharacter range start' => '&#xFDD0;',
            'numeric noncharacter range end' => '&#xFDEF;',
            'numeric BMP plane end' => '&#xFFFE;',
            'numeric BMP final codepoint' => '&#65535;',
            'numeric supplementary plane end' => '&#x1FFFE;',
            'numeric final plane end' => '&#x10FFFF;',
            'numeric surrogate' => '&#xD800;',
            'numeric above Unicode maximum' => '&#1114112;',
            'numeric integer overflow' => '&#999999999999999999999999999999;',
        ];
        $cases = [];
        foreach ($characters as $label => $character) {
            $cases[$label.' in subtitle'] = [$character, false];
            $cases[$label.' in cover'] = [$character, true];
        }

        return $cases;
    }

    #[Test]
    public function valid_formatting_and_entities_preserve_single_pass_literal_names(): void
    {
        $html = "\t\r\n".$this->card('Léon_天空.&amp;&#1;.DD+5.1-GRP')."\r\n\t";
        $html = str_replace('<h2>', "<h2>\r\n\t", $html);
        $html = str_replace('>Léon_天空.', ">\t\r\nLéon_天空.", $html);
        $html = str_replace('</span>', "\t\r\n</span>", $html);
        $entries = (new Nima4kSource('https://nima4k.org/'))->parseHtml($html);

        $this->assertCount(1, $entries);
        $this->assertSame('Léon_天空.&amp;&#1;.DD+5.1-GRP', $entries[0]->title);
    }

    #[Test]
    #[DataProvider('inertNumericReferenceFragments')]
    public function numeric_looking_raw_text_and_comments_preserve_literal_names(string $fragment): void
    {
        $html = $this->card('Léon_天空.&amp;&#1;.DD+5.1-GRP').$fragment.$this->card('Second.Release-GRP');
        $entries = (new Nima4kSource('https://nima4k.org/', pageSize: 1))->parseHtml($html);

        $this->assertCount(1, $entries);
        $this->assertSame('Léon_天空.&amp;&#1;.DD+5.1-GRP', $entries[0]->title);
    }

    /** @return array<string, array{string}> */
    public static function inertNumericReferenceFragments(): array
    {
        return [
            'script text' => ['<script>const x = "&#1;";</script>'],
            'style text' => ['<style>.example::after { content: "&#x1;"; }</style>'],
            'comment' => ['<!-- &#0; &#xD800; &#1114112; &#xFFFF; -->'],
            'uppercase script and reference' => ['<SCRIPT>const x = "&#X00001;";</SCRIPT>'],
            'quoted greater than in script attribute' => ['<script data-marker=">">const x = "&#1;";</script>'],
            'quoted greater than in style attribute' => ["<style data-marker='>'>.example { content: '&#1;'; }</style>"],
            'script apparent markup stays raw' => ['<script>const x = "<!-- <style> &#1; </style> -->";</script>'],
            'script close name requires a delimiter' => ['<script>const x = "</scripted> &#1;";</script>'],
            'style close name requires a delimiter' => ['<style>.example { content: "</stylesheet> &#1;"; }</style>'],
            'comment apparent markup stays inert' => ['<!-- <script data-marker="&#1;"> &#1; </script> -->'],
            'semicolonless numeric-looking script text' => ['<script>const x = "&#1";</script>'],
        ];
    }

    #[Test]
    #[DataProvider('decodedInvalidReferenceFragments')]
    public function invalid_references_in_decoded_contexts_still_reject_the_whole_listing(string $fragment): void
    {
        $html = $this->card('First.Release-GRP').$fragment.$this->card('Second.Release-GRP');

        $this->expectException(RuntimeException::class);
        (new Nima4kSource('https://nima4k.org/', pageSize: 1))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function decodedInvalidReferenceFragments(): array
    {
        return [
            'ordinary text' => ['<p>&#1;</p>'],
            'ordinary attribute' => ['<div data-marker="&#1;"></div>'],
            'script attribute' => ['<script data-marker="&#1;">const x = "safe";</script>'],
            'style attribute' => ['<style data-marker="&#1;">.example { color: red; }</style>'],
            'quoted fake script opener' => ['<div data-marker="<script>">&#1;</div>'],
            'quoted fake comment opener' => ['<div data-marker="<!--">&#1;</div>'],
            'after actual script close' => ['<script>const x = "safe";</script><p>&#1;</p>'],
            'after actual style close' => ['<style>.example { color: red; }</style><p>&#1;</p>'],
            'after actual comment close' => ['<!-- safe --><p>&#1;</p>'],
            'title RCDATA' => ['<title>&#1;</title>'],
            'textarea RCDATA' => ['<textarea>&#1;</textarea>'],
            'noncharacter in ordinary text' => ['<p>&#xFDD0;</p>'],
            'C1 in ordinary attribute' => ['<div data-marker="&#128;"></div>'],
        ];
    }

    #[Test]
    #[DataProvider('completeParserFragments')]
    public function complete_ordinary_and_safe_depth_listings_preserve_literal_names(string $fragment): void
    {
        $html = $this->card('First.Release-GRP').$fragment.$this->card('Second.Release-GRP');
        $entries = (new Nima4kSource('https://nima4k.org/', pageSize: 1))->parseHtml($html);

        $this->assertCount(1, $entries);
        $this->assertSame('First.Release-GRP', $entries[0]->title);
    }

    /** @return array<string, array{string}> */
    public static function completeParserFragments(): array
    {
        return [
            'ordinary listing' => [''],
            'single safe attribute' => ['<div data-marker="safe"></div>'],
            'safe depth 240' => [str_repeat('<div>', 240).'<p>safe</p>'.str_repeat('</div>', 240)],
        ];
    }

    #[Test]
    #[DataProvider('discardedParserFragments')]
    public function discarded_attributes_or_truncated_tails_reject_the_whole_listing(string $fragment): void
    {
        $html = $this->card('First.Release-GRP').$fragment.$this->card('Second.Release-GRP');

        $this->expectException(RuntimeException::class);
        (new Nima4kSource('https://nima4k.org/', pageSize: 1))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function discardedParserFragments(): array
    {
        $cases = [];
        foreach (['safe' => 'safe', 'C1' => '&#128;', 'noncharacter' => '&#xFDD0;', 'control' => '&#1;', 'nul' => '&#0;', 'surrogate' => '&#xD800;'] as $label => $value) {
            $cases['second duplicate attribute '.$label] = ['<div data-marker="safe" data-marker="'.$value.'"></div>'];
            $cases['first duplicate attribute '.$label] = ['<div data-marker="'.$value.'" data-marker="safe"></div>'];
            $cases['truncated depth 260 '.$label] = [str_repeat('<div>', 260).'<p>'.$value.'</p>'.str_repeat('</div>', 260)];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('wellFormedStructuralTags')]
    public function well_formed_structural_tags_preserve_literal_names(string $tag): void
    {
        $attribute = ' data-marker="safe"';
        $html = '<html'.($tag === 'html' ? $attribute : '').'><head'.($tag === 'head' ? $attribute : '').'></head><body'.($tag === 'body' ? $attribute : '').'>'.$this->card('First.Release-GRP').$this->card('Second.Release-GRP').'</body></html>';
        $entries = (new Nima4kSource('https://nima4k.org/', pageSize: 1))->parseHtml($html);

        $this->assertCount(1, $entries);
        $this->assertSame('First.Release-GRP', $entries[0]->title);
    }

    /** @return array<string, array{string}> */
    public static function wellFormedStructuralTags(): array
    {
        return ['html' => ['html'], 'head' => ['head'], 'body' => ['body']];
    }

    #[Test]
    #[DataProvider('misplacedStructuralFragments')]
    public function misplaced_structural_tags_reject_the_whole_listing(string $fragment): void
    {
        $html = '<html><head></head><body>'.$this->card('First.Release-GRP').$fragment.$this->card('Second.Release-GRP').'</body></html>';

        $this->expectException(RuntimeException::class);
        (new Nima4kSource('https://nima4k.org/', pageSize: 1))->parseHtml($html);
    }

    /** @return array<string, array{string}> */
    public static function misplacedStructuralFragments(): array
    {
        $cases = [];
        foreach (['html', 'body', 'head'] as $tag) {
            foreach (['safe' => 'safe', 'C1' => '&#128;', 'noncharacter' => '&#xFDD0;', 'control' => '&#1;'] as $label => $value) {
                $fragment = '<'.$tag.' data-marker="'.$value.'"></'.$tag.'>';
                $cases[$tag.' repeated '.$label] = [$fragment];
                $cases[$tag.' nested '.$label] = ['<div>'.$fragment.'</div>'];
            }
        }

        return $cases;
    }

    #[Test]
    public function malformed_characters_after_the_record_limit_still_reject_the_listing(): void
    {
        $html = $this->card('First.Release-GRP').str_replace('Second.Release-GRP</span>', 'Second.&#1;Release-GRP</span>', $this->card('Second.Release-GRP'));

        $this->expectException(RuntimeException::class);
        (new Nima4kSource('https://nima4k.org/', pageSize: 1))->parseHtml($html);
    }

    #[Test]
    public function supplied_oversized_html_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new Nima4kSource('https://nima4k.org/', maximumBodyBytes: 100))->parseHtml($this->fixture());
    }

    #[Test]
    public function later_pages_never_fetch_a_listing(): void
    {
        Http::swap((new Factory)->preventStrayRequests());

        $this->assertSame([], (new Nima4kSource('https://nima4k.org/'))->fetch(2));
    }

    #[Test]
    public function streamed_fetch_parses_a_body_at_the_exact_limit_and_closes_it(): void
    {
        $body = $this->card('Exact.Release-GRP');
        $stream = $this->stream($body, 7);
        $this->fakeResponse($stream);
        $entries = (new Nima4kSource('https://nima4k.org/', maximumBodyBytes: strlen($body)))->fetch();

        $this->assertSame('Exact.Release-GRP', $entries[0]->title);
        $this->assertTrue($stream->closed);
    }

    #[Test]
    public function gzip_members_are_joined_before_the_unchanged_catalogue_parser_runs(): void
    {
        $body = $this->card('Compressed.Release-GRP');
        $middle = intdiv(strlen($body), 2);
        $stream = $this->stream(gzencode(substr($body, 0, $middle)).gzencode(substr($body, $middle)), 7);
        $this->fakeResponse($stream, ['Content-Encoding' => 'gzip']);
        $entries = (new Nima4kSource('https://nima4k.org/', maximumBodyBytes: strlen($body)))->fetch();

        $this->assertSame('Compressed.Release-GRP', $entries[0]->title);
        $this->assertTrue($stream->closed);
    }

    #[Test]
    public function an_empty_final_read_that_transitions_to_eof_is_accepted(): void
    {
        $body = $this->card('Final.Release-GRP');
        $stream = $this->stream($body, delayedEof: true);
        $this->fakeResponse($stream);
        $entries = (new Nima4kSource('https://nima4k.org/', maximumBodyBytes: strlen($body)))->fetch();

        $this->assertSame('Final.Release-GRP', $entries[0]->title);
        $this->assertSame(1, $stream->emptyReads);
        $this->assertSame(strlen($body), $stream->bytesRead);
        $this->assertTrue($stream->closed);
    }

    #[Test]
    #[DataProvider('streamLengthHeaders')]
    public function oversized_streams_stop_at_the_cap_without_trusting_content_length(array $headers): void
    {
        $stream = $this->stream(str_repeat('x', 20000));
        $this->fakeResponse($stream, $headers);

        try {
            (new Nima4kSource('https://nima4k.org/', maximumBodyBytes: 1024))->fetch();
            $this->fail('An oversized response must be rejected.');
        } catch (RuntimeException) {
            $this->assertSame(1025, $stream->bytesRead);
            $this->assertTrue($stream->closed);
        }
    }

    #[Test]
    public function stalled_stream_is_rejected_and_closed(): void
    {
        $stream = $this->stream('unread', 0);
        $this->fakeResponse($stream);

        try {
            (new Nima4kSource('https://nima4k.org/'))->fetch();
            $this->fail('An empty read before EOF must be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue($stream->closed);
        }
    }

    #[Test]
    public function error_response_streams_are_closed_including_retried_server_errors(): void
    {
        $streams = [$this->stream(str_repeat('x', 20000)), $this->stream(str_repeat('y', 20000))];
        $attempt = 0;
        $factory = new Factory;
        $factory->fake(static function () use ($streams, &$attempt) {
            return Create::promiseFor(new Response(503, [], $streams[$attempt++]));
        });
        Http::swap($factory);

        try {
            (new Nima4kSource('https://nima4k.org/', maximumBodyBytes: 10))->fetch();
            $this->fail('A failed HTTP response must be rejected.');
        } catch (RequestException) {
            $this->assertSame(2, $attempt);
            foreach ($streams as $stream) {
                $this->assertTrue($stream->closed);
                $this->assertSame(0, $stream->bytesRead);
            }
        }
    }

    #[Test]
    #[DataProvider('unsuccessfulStatuses')]
    public function unsuccessful_responses_never_parse_catalogue_names(int $status): void
    {
        $stream = $this->stream($this->card('Exact.Release-GRP'));
        $this->fakeResponse($stream, status: $status);

        try {
            (new Nima4kSource('https://nima4k.org/'))->fetch();
            $this->fail('Only successful HTTP listings may emit names.');
        } catch (RuntimeException|RequestException) {
            $this->assertTrue($stream->closed);
            $this->assertSame(0, $stream->bytesRead);
        }
    }

    /** @return array<string, array{int}> */
    public static function unsuccessfulStatuses(): array
    {
        return ['redirect without location' => [302], 'client failure' => [404]];
    }

    /** @return array<string, array{array<string, string>}> */
    public static function streamLengthHeaders(): array
    {
        return ['absent length' => [[]], 'understated length' => [['Content-Length' => '12']]];
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/predb/nima4k.html');
    }

    private function card(string $title): string
    {
        $escaped = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<div class="article uk-clearfix"><div class="cover-box"><img alt="'.$escaped.'"></div><div class="article-right"><h2><span class="subtitle uk-visible-large">'.$escaped.'</span></h2></div></div>';
    }

    /** @param array<string, string> $headers */
    private function fakeResponse(StreamInterface $stream, array $headers = [], int $status = 200): void
    {
        $factory = new Factory;
        $factory->fake(static function ($request, array $options) use ($stream, $headers, $status) {
            if (($options['stream'] ?? false) !== true || ($options['read_timeout'] ?? null) !== 15) {
                throw new RuntimeException('The body must be streamed with a bounded read timeout.');
            }

            return Create::promiseFor(new Response($status, $headers, $stream));
        });
        Http::swap($factory);
    }

    private function stream(string $body, int $chunkSize = 8192, bool $delayedEof = false): StreamInterface
    {
        return new class(Utils::streamFor($body), $chunkSize, $delayedEof) implements StreamInterface
        {
            use StreamDecoratorTrait;

            public int $bytesRead = 0;

            public bool $closed = false;

            public int $emptyReads = 0;

            private bool $finished = false;

            public function __construct(private StreamInterface $stream, private readonly int $chunkSize, private readonly bool $delayedEof) {}

            public function __toString(): string
            {
                throw new RuntimeException('Reading the complete body is forbidden.');
            }

            public function getContents(): string
            {
                throw new RuntimeException('Reading the complete body is forbidden.');
            }

            public function read(int $length): string
            {
                if ($this->chunkSize === 0) {
                    return '';
                }
                $result = $this->stream->read(min($length, $this->chunkSize));
                $this->bytesRead += strlen($result);
                if ($result === '') {
                    $this->emptyReads++;
                    $this->finished = $this->stream->eof();
                }

                return $result;
            }

            public function eof(): bool
            {
                return $this->delayedEof ? $this->finished : $this->stream->eof();
            }

            public function close(): void
            {
                $this->closed = true;
                $this->stream->close();
            }
        };
    }
}
