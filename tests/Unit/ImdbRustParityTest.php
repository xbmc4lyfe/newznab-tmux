<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use App\Support\RustPipelineLibrary;
use GuzzleHttp\Client;
use ReflectionMethod;

final class ImdbRustParityTest extends ImdbScraperTestCase
{
    private ImdbScraper $native;

    private ImdbScraper $php;

    protected function setUp(): void
    {
        parent::setUp();

        $client = new Client(['http_errors' => false]);
        $this->native = new ImdbScraper($client);
        $this->php = new ImdbScraper($client, useNative: false);
    }

    public function test_imdb_domain_library_is_available(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('imdb', <<<'C'
            int32_t nntmux_imdb_is_waf_response(const char *html, size_t html_len);
            ssize_t nntmux_imdb_extract_jsonld_and_languages(const char *html, size_t html_len, char *out_buf, size_t out_cap);
            C);

        $this->assertNotNull($ffi, 'Rust pipeline FFI library with IMDb exports must be available.');
    }

    public function test_fixture_pages_parse_identically(): void
    {
        foreach (['title_jsonld.html', 'example_title.html'] as $fixture) {
            $html = (string) file_get_contents(base_path('tests/Fixtures/imdb/'.$fixture));
            $this->assertParsesIdentically($html, $fixture);
        }

        $parsed = $this->parse($this->native, (string) file_get_contents(base_path('tests/Fixtures/imdb/title_jsonld.html')));
        $this->assertIsArray($parsed);
        $this->assertSame('English, Spanish', $parsed['language']);
    }

    public function test_title_pages_parse_identically_across_corpus(): void
    {
        foreach ($this->titleCorpus() as $name => $html) {
            $this->assertParsesIdentically($html, $name);
        }
    }

    public function test_waf_detection_matches_php_reference(): void
    {
        $bodies = [
            '',
            '<html><body>ordinary</body></html>',
            '<script>window.awsWafCookieDomainList=[];window.gokuProps={};</script>',
            '<SCRIPT SRC="/CHALLENGE.JS"></SCRIPT>',
            'Please solve this CapTcHa',
            'AWSWAF token',
            'window.go'."\u{212A}".'uProps',
            'go'."\u{212A}".' and a captcha',
            "caf\u{e9} \u{2019}quoted\u{2019} page",
            str_repeat('<div>filler text</div>', 4000).'<p>captcha</p>',
            str_repeat('<div>filler text</div>', 4000),
        ];

        $clean = str_repeat('<div>filler text</div>', 4000);
        $bodies[] = $clean;
        $bodies[] = substr($clean, 0, -20).'captcha<p>captch</p>';

        $method = new ReflectionMethod(ImdbScraper::class, 'isWafResponse');
        foreach ($bodies as $index => $body) {
            foreach ([200, 202, 404] as $status) {
                $this->assertSame(
                    $method->invoke($this->php, $status, $body),
                    $method->invoke($this->native, $status, $body),
                    "isWafResponse mismatch for body #{$index} status {$status}"
                );
            }
        }
    }

    public function test_large_page_with_json_ld_parses_identically(): void
    {
        $filler = str_repeat('<div class="ipc-metadata-list-item"><span>Row</span><a href="/x">Link &amp; more</a></div>'."\n", 6000);
        $html = '<!doctype html><html><head><script id="__NEXT_DATA__" type="application/json">{"props":'.str_repeat('{"a":"<li data-testid=\"title-details-languages\"><a>Fake</a></li>"},', 2000).'{}}</script>'
            .$this->jsonLd(['inLanguage' => ['en', 'fr']])
            .'</head><body>'.$filler.'</body></html>';

        $this->assertGreaterThan(400000, strlen($html));
        $this->assertParsesIdentically($html, 'large');

        $parsed = $this->parse($this->native, $html);
        $this->assertIsArray($parsed);
        $this->assertSame('en, fr', $parsed['language']);
    }

    /**
     * @return array<string, string>
     */
    private function titleCorpus(): array
    {
        $languages = '<li role="presentation" data-testid="title-details-languages"><span>Languages</span><div><ul>'
            .'<li><a href="/l/en">English</a></li><li><a href="/l/fr"> French </a></li><li><a href="/l/en">English</a></li></ul></div></li>';

        return [
            'jsonld with nested languages' => $this->page($this->jsonLd(), '<ul>'.$languages.'</ul>'),
            'jsonld with inLanguage skips language list' => $this->page($this->jsonLd(['inLanguage' => 'de']), '<ul>'.$languages.'</ul>'),
            'jsonld without language list' => $this->page($this->jsonLd(), '<p>nothing</p>'),
            'jsonld with single-quoted attribute' => $this->page($this->jsonLd(), "<ul><li data-testid='title-details-languages'><a>Italian</a></li></ul>"),
            'jsonld with unquoted attribute and uppercase tags' => $this->page($this->jsonLd(), '<UL><LI DATA-TESTID=title-details-languages><A>Korean</A></LI></UL>'),
            'languages with entities' => $this->page($this->jsonLd(), '<ul><li data-testid="title-details-languages"><a>Fran&ccedil;ais</a><a>Q&amp;A</a></li></ul>'),
            'languages with multibyte text' => $this->page($this->jsonLd(), '<ul><li data-testid="title-details-languages"><a>Espa'."\u{f1}".'ol</a><a>日本語</a></li></ul>'),
            'languages with nested markup' => $this->page($this->jsonLd(), '<ul><li data-testid="title-details-languages"><a><span>Hindi</span></a></li></ul>'),
            'languages inside a comment' => $this->page($this->jsonLd(), '<!-- '.$languages.' -->'),
            'languages inside a script string' => $this->page($this->jsonLd(), '<script>var h=\'<li data-testid="title-details-languages"><a>Fake</a></li>\';</script>'),
            'language marker on a div' => $this->page($this->jsonLd(), '<div data-testid="title-details-languages"><a>Div</a></div>'),
            'unclosed language list' => $this->page($this->jsonLd(), '<ul><li data-testid="title-details-languages"><a>Open</a>'),
            'jsonld missing director and actors uses DOM credits' => $this->page(
                $this->jsonLd(['director' => null, 'actor' => null]),
                '<ul><li data-testid="title-pc-principal-credit">Director <a href="/n/1">DOM Director</a></li></ul>'
                .'<div data-testid="title-cast-item"><a data-testid="title-cast-item__actor" href="/n/2">DOM Actor</a></div>'.$languages
            ),
            'jsonld missing fields uses DOM fallbacks' => $this->page(
                '<script type="application/ld+json">{"@type":"Movie","name":"Sparse Movie"}</script>',
                '<span data-testid="title-details-releasedate"><a>May 17, 2019</a></span>'
                .'<span data-testid="plot-l">DOM plot &amp; more</span>'
                .'<div data-testid="hero-rating-bar__aggregate-rating__score"><span>8.1</span></div>'
                .'<div data-testid="hero-media__poster"><img src="https://example.com/dom._V1_UX100.jpg"></div>'
                .'<div data-testid="genres"><a>Drama</a><a>Crime</a><a>Drama</a></div>'.$languages
            ),
            'no jsonld uses h1 and og tags' => '<html><head><meta property="og:title" content="OG Movie - IMDb"><meta name="description" content="Meta plot"></head>'
                .'<body><h1>H1 Movie (2020)</h1>'.$languages.'</body></html>',
            'no title anywhere' => '<html><body><p>nothing useful</p></body></html>',
            'invalid json then valid json' => $this->page('<script type="application/ld+json">{not json</script>'.$this->jsonLd(), $languages),
            'entity encoded json' => $this->page('<script type="application/ld+json">'.htmlspecialchars('{"@type":"Movie","name":"Tom & Jerry","description":"It\'s \"fun\""}', ENT_QUOTES).'</script>', ''),
            'mixed case script tag and attributes' => $this->page('<SCRIPT data-a=1 TYPE=\'application/LD+JSON\'>{"@type":"TVSeries","name":"Series Case"}</SCRIPT>', $languages),
            'mismatched quotes on type' => $this->page('<script type="application/ld+json\'>{"@type":"Movie","name":"Quote Mismatch"}</script>', ''),
            'script-prefixed tag name' => $this->page('<scripts type="application/ld+json">{"@type":"Movie","name":"Prefix Tag"}</script>', ''),
            'script tag never closed' => '<html><head><script type="application/ld+json">{"@type":"Movie","name":"Unclosed"}</head><body><h1>Fallback</h1></body></html>',
            'jsonld in a graph' => $this->page('<script type="application/ld+json">{"@graph":[{"@type":"WebSite"},{"@type":"Movie","name":"Graph Movie","datePublished":"2011"}]}</script>', ''),
            'type attribute on a non-script tag' => $this->page('<div type="application/ld+json">x</div>'.$this->jsonLd(['name' => 'After Div']), ''),
            'greater-than inside an attribute value' => $this->page('<script data-x=">" type="application/ld+json">{"@type":"Movie","name":"Gt Attr"}</script>', '<h1>Fallback H1</h1>'),
            'nested script open tags' => $this->page('<script <script type="application/ld+json">{"@type":"Movie","name":"Nested Open"}</script>', ''),
            'plain scripts before json-ld' => $this->page('<script src="a.js"></script><script>var x = 1;</script>'.$this->jsonLd(['name' => 'After Scripts']), ''),
            'two json-ld blocks first without entity' => $this->page('<script type="application/ld+json">{"@type":"WebSite","name":"Site"}</script>'.$this->jsonLd(['name' => 'Second Block']), ''),
            'tv episode type' => $this->page($this->jsonLd(['@type' => 'TVEpisode']), $languages),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function jsonLd(array $overrides = []): string
    {
        $entity = array_filter(array_merge([
            '@context' => 'https://schema.org',
            '@type' => 'Movie',
            'name' => 'Parity Movie',
            'description' => 'A plot, with "quotes" & <tags>.',
            'datePublished' => '2021-03-04',
            'image' => 'https://example.com/poster._V1_UX182.jpg',
            'genre' => ['Action', 'Drama'],
            'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '6.9'],
            'actor' => [['@type' => 'Person', 'name' => 'Actor One'], ['@type' => 'Person', 'name' => 'Actor Two']],
            'director' => [['@type' => 'Person', 'name' => 'Director One']],
        ], $overrides), static fn (mixed $value): bool => $value !== null);

        return '<script type="application/ld+json">'.json_encode($entity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).'</script>';
    }

    private function page(string $head, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Parity - IMDb</title>'.$head.'</head><body>'.$body.'</body></html>';
    }

    private function assertParsesIdentically(string $html, string $label): void
    {
        $expected = $this->parse($this->php, $html);
        $actual = $this->parse($this->native, $html);

        $this->assertSame($expected, $actual, 'parseTitleHtml mismatch for: '.$label);
        $this->assertSame($this->php->wasBlockedByWaf(), $this->native->wasBlockedByWaf(), 'WAF flag mismatch for: '.$label);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function parse(ImdbScraper $scraper, string $html): array|false
    {
        $method = new ReflectionMethod(ImdbScraper::class, 'parseTitleHtml');

        return $method->invoke($scraper, $html, '1234567');
    }
}
