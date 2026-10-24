<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\Sources\Nima4kSource;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * Differential parity between the Rust NIMA4K fast path and the dual-DOM PHP parser: every input
 * must produce identical entries or the identical exception message, whether Rust accepts the
 * document or declines it to PHP.
 */
final class RustNima4kSourceParityTest extends TestCase
{
    private const CDEF = <<<'C'
        ssize_t nntmux_predb_nima4k_parse_html(const char *html, size_t html_len, size_t page_size, size_t max_body_bytes, char *out_buf, size_t out_cap);
        C;

    private const TITLES = ['Some.Release.2026.German.2160p.WEB.H265-GRP', 'Other.Release-GRP', 'A&amp;B.Release-GRP', 'A&apos;B.Release-GRP', 'Leon_&#x5929;.1994-GRP', 'Spaced Release-GRP', ''];

    private const TEXTS = ['Eingetragen am ', 'Minions & Monster', '&amp;', '&lt;b&gt;', '&quot;&#39;', '&#46;', '&#x41;', '&#039;', '&#10;', '&#9;', "\r\n", "\t\t", 'Nürnberg', '&quot;&apos;', '&nbsp;', '&#x80;', '&#13;', '&#12;', '&#0;', '&#xD800;', '&#xFFFE;', '&amp', '&copy;', '1 < 2', '&', '&#', '>'];

    private const COMMENTS = ['<!-- note -->', '<!---->', '<!-- a -- b -->', '<!--->', '<!-->', '<!-- x --!>', '<!-- <!-- -->', '<![CDATA[x]]>', '<?php x ?>', '<!x>'];

    private const FLOW = ['div', 'section', 'nav', 'header', 'footer', 'main', 'aside', 'figure', 'article', 'a', 'p', 'h1', 'h2', 'h3', 'span', 'strong', 'b', 'em', 'small', 'ul', 'ol', 'li', 'img', 'br', 'hr', 'table', 'tr', 'td', 'form', 'button', 'select', 'option', 'dl', 'dd', 'dt', 'nobr', 'font', 'image', 'svg', 'math', 'template', 'textarea', 'script', 'style', 'iframe', 'noscript', 'pre', 'blockquote', 'center', 'object', 'title', 'meta', 'body', 'html', 'head', 'xmp', 'plaintext'];

    private const CLASSES = ['article', 'cover-box', 'article-right', 'subtitle', 'uk-clearfix', 'article-left', 'meta'];

    private Nima4kSource $source;

    /** Clean documents only draw from the subset Rust is expected to accept. */
    private bool $clean = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (RustPipelineLibrary::resolveDomain('predb_nima4k', self::CDEF) === null) {
            self::markTestSkipped('libnntmux_pipeline with the NIMA4K export is not built.');
        }
        $this->source = new Nima4kSource('https://nima4k.org/', pageSize: 7);
    }

    #[Test]
    public function the_captured_listing_takes_the_rust_path_with_identical_entries(): void
    {
        $html = (string) file_get_contents(__DIR__.'/../Fixtures/predb/nima4k.html');
        $this->assertNotNull($this->nativeTitles($this->source, $html));
        $this->assertSame($this->outcome(fn () => $this->source->parseHtmlPhp($html)), $this->outcome(fn () => $this->source->parseHtml($html)));
        $this->assertCount(7, $this->source->parseHtml($html));
    }

    /** @param array<string, string> $replacements */
    #[Test]
    #[DataProvider('fixtureMutations')]
    public function fixture_mutations_match_php(array $replacements): void
    {
        $html = strtr((string) file_get_contents(__DIR__.'/../Fixtures/predb/nima4k.html'), $replacements);
        $this->assertParity($html);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function fixtureMutations(): array
    {
        return [
            'unchanged' => [[]],
            'latin1 meta' => [['charset="UTF-8"' => 'charset="ISO-8859-1"']],
            'utf8 alias meta' => [['charset="UTF-8"' => 'charset="utf8"']],
            'content type meta' => [['<meta charset="UTF-8">' => '<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">']],
            'script title' => [['<title>NIMA4K</title>' => '<script>document.write("<div class=\'article\'>")</script>']],
            'self closing title' => [['<title>NIMA4K</title>' => '<title/>NIMA4K</title>']],
            'entity title' => [['<title>NIMA4K</title>' => '<title>&lt;div&gt;</title>']],
            'apos entity title' => [['Nuernberg.2025' => 'Nuernberg&apos;s.2025']],
            'cdata' => [['<!-- Reduced' => '<![CDATA[ x ]]><!-- Reduced']],
            'svg' => [['<!-- Reduced' => '<svg><span class="subtitle">X</span></svg><!-- Reduced']],
            'missing body close' => [['</body></html>' => '']],
            'unclosed p' => [['</p></div></div>' => '</div></div>']],
            'stray close' => [['</h2>' => '</h2></span>']],
            'duplicate class' => [['<h2 class=' => '<h2 class="x" class=']],
            'control reference' => [['Rennen</a>' => 'Rennen&#x85;</a>']],
            'cr reference' => [['Rennen</a>' => 'Rennen&#13;</a>']],
            'nul reference' => [['HEVC-NIMA4K</span>' => 'HEVC-&#0;NIMA4K</span>']],
            'nbsp entity' => [['Rennen</a>' => 'Rennen&nbsp;</a>']],
            'mismatched alt' => [['alt="The.Furious' => 'alt="The.Angry']],
            'spaced subtitle' => [['>Nuernberg.2025' => '>Nuernberg 2025']],
            'nested subtitle markup' => [['>Nuernberg.2025.German' => '><b>Nuernberg</b>.2025.German']],
            'extra subtitle' => [['Eingetragen am <span>' => 'Eingetragen am <span class="subtitle">']],
            'second cover' => [['</a>'."\n".'								</div></div>' => '</a><img alt="x">'."\n".'								</div></div>']],
            'no cards' => [['class="article ' => 'class="post ']],
            'nested card' => [['<div class="article-left">' => '<div class="article-left"><div class="article">']],
            'table' => [['<p class="meta' => '<table><tr><td>x</td></tr></table><p class="meta']],
            'p block child' => [['Eingetragen am' => '<div>Eingetragen</div> am']],
            'uppercase tags' => [['<span class="subtitle' => '<SPAN class="subtitle']],
            'self closing div' => [['<div class="article-left">' => '<div class="article-left"><div/>']],
            'bom' => [['<!DOCTYPE html>' => "\u{FEFF}<!DOCTYPE html>"]],
            'forbidden literal' => [['Rennen</a>' => "Rennen\u{FDD0}</a>"]],
            'invalid utf8' => [['Rennen</a>' => "Rennen\xC3</a>"]],
        ];
    }

    #[Test]
    public function randomized_documents_match_php(): void
    {
        mt_srand(0x4E494D41);
        $accepted = 0;
        $iterations = 2500;
        for ($i = 0; $i < $iterations; $i++) {
            $this->clean = $this->chance(50);
            $html = $this->randomDocument();
            if ($this->nativeTitles($this->source, $html) !== null) {
                $accepted++;
            }
            $this->assertParity($html);
        }
        // The generator must exercise both the Rust path and the PHP fallback.
        $this->assertGreaterThan($iterations / 10, $accepted);
        $this->assertLessThan($iterations, $accepted);
    }

    #[Test]
    public function body_limit_and_page_size_match_php(): void
    {
        $html = (string) file_get_contents(__DIR__.'/../Fixtures/predb/nima4k.html');
        foreach ([1, 3, 10, 100] as $pageSize) {
            $source = new Nima4kSource('https://nima4k.org/', pageSize: $pageSize);
            $this->assertSame($this->outcome(fn () => $source->parseHtmlPhp($html)), $this->outcome(fn () => $source->parseHtml($html)));
        }
        foreach ([strlen($html) - 1, strlen($html)] as $limit) {
            $source = new Nima4kSource('https://nima4k.org/', maximumBodyBytes: $limit);
            $this->assertSame($this->outcome(fn () => $source->parseHtmlPhp($html)), $this->outcome(fn () => $source->parseHtml($html)));
        }
        $this->assertSame('RuntimeException: NIMA4K returned invalid HTML.', $this->outcome(fn () => $this->source->parseHtml('')));
    }

    private function assertParity(string $html): void
    {
        $this->assertSame(
            $this->outcome(fn () => $this->source->parseHtmlPhp($html)),
            $this->outcome(fn () => $this->source->parseHtml($html)),
            $html,
        );
    }

    /** @return list<string>|string */
    private function outcome(callable $parse): array|string
    {
        try {
            return array_map(static fn (PredbFeedEntry $entry): string => $entry->source.'|'.$entry->title, $parse());
        } catch (Throwable $exception) {
            return $exception::class.': '.$exception->getMessage();
        }
    }

    /** @return list<string>|null */
    private function nativeTitles(Nima4kSource $source, string $html): ?array
    {
        /** @var list<string>|null */
        return (new ReflectionMethod($source, 'nativeTitles'))->invoke($source, $html);
    }

    /**
     * @param  list<mixed>  $values
     * @param  int  $safe  leading values that clean documents may use
     */
    private function pick(array $values, int $safe = 0): mixed
    {
        $last = $this->clean && $safe > 0 ? $safe - 1 : count($values) - 1;

        return $values[mt_rand(0, $last)];
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /** A malformed or out-of-subset variation, never taken by clean documents. */
    private function noise(int $percent): bool
    {
        return ! $this->clean && $this->chance($percent);
    }

    private function randomDocument(): string
    {
        $head = $this->chance(85) ? '<meta charset="'.$this->pick(['UTF-8', 'utf-8', 'utf8', 'latin1', ' utf-8', 'UTF-8"'], 2).'">' : '';
        if ($this->chance(70)) {
            $head .= '<title>'.$this->pick(['NIMA4K', 'Nürnberg', '', 'A &amp; B', 'x < y', '</ti'], 3).'</title>';
        }
        if ($this->chance(20)) {
            $head .= $this->pick(['<link rel="icon" href="/f.ico">', '<link rel=stylesheet href=/a.css>', '<!-- head -->', $this->pick(self::COMMENTS), '<div>', 'x', '<meta http-equiv="content-type" content="text/html; charset=utf-8">'], 3);
        }
        $body = '';
        $cards = mt_rand(0, 5);
        for ($c = 0; $c < $cards; $c++) {
            $body .= $this->chance(30) ? $this->randomNodes(2, false) : '';
            $body .= $this->randomCard();
            $body .= $this->pick(["\n", '', "\n\t\t"]);
        }
        $body .= $this->chance(30) ? $this->randomNodes(2, false) : '';
        $doctype = $this->pick(['<!DOCTYPE html>', '<!doctype html>', '', '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN">'], 3);
        $html = $doctype.$this->pick(['', "\n"]).'<html'.$this->pick(['', ' lang="de"']).'><head>'.$head.'</head>'.$this->pick(['', "\n"]).'<body>'.$this->pick(['', "\n"]).$body.'</body></html>'.$this->pick(['', "\n"]);
        if ($this->noise(8)) {
            $html = $this->pick([str_replace('</body></html>', '', $html), str_replace('<head>', '', $html), str_replace('</head>', '', $html), str_replace('<body>', '<body><!-- -->', $html)]);
        }

        return $html;
    }

    private function randomCard(): string
    {
        $title = $this->pick(self::TITLES, 3);
        $alt = $this->noise(15) ? $this->pick(self::TITLES) : $title;
        $class = fn (string $base): string => $this->noise(10) ? $this->pick(self::CLASSES) : $base.$this->pick(['', ' uk-clearfix', ' x']);
        $filler = fn (): string => $this->chance(25) ? $this->randomNodes(1, true) : '';
        $cover = '<div class="'.$class('cover-box').'">'.$filler().$this->pick(["\n\t", '']).'<a href="/release/1/x">'.'<img src="/c.jpg" alt="'.$alt.'"'.$this->pick(['', ' /', '/']).'>'.$filler().'</a></div>';
        if ($this->noise(5)) {
            $cover .= '<img alt="'.$alt.'">';
        }
        $subtitle = '<span class="'.$class('subtitle uk-visible-large').'">'.$this->pick(['', ' ', "\n"]).($this->noise(8) ? '<b>'.$title.'</b>' : $title).$this->pick(['', ' ', '&#10;']).'</span>';
        $heading = $this->pick(['h2', 'h2', 'h2', 'h3', 'div'], 1);
        $right = '<div class="'.$class('article-right').'"><'.$heading.' class="uk-margin">'.$filler().'<a href="/release/1/x" class="release-details">'.$this->pick(self::TEXTS, 13).'</a>'.$this->pick(["\n", ' ']).$subtitle.'</'.$heading.'><p class="meta">'.$this->pick(self::TEXTS, 13).'<span>11.10.2026</span>'.$filler().'</p></div>';
        $left = '<div class="article-left">'.$cover.'</div>';
        $inner = $this->noise(15) ? $right.$left : $left.$right;

        return '<div class="'.$class('article uk-clearfix').'">'.$inner.$filler().'</div>';
    }

    /** Phrasing-only nodes go inside cards, where block content could close a paragraph. */
    private function randomNodes(int $depth, bool $phrasing): string
    {
        $out = '';
        $count = mt_rand(1, 3);
        for ($n = 0; $n < $count; $n++) {
            $roll = mt_rand(1, 100);
            if ($roll <= 30 || $depth <= 0) {
                $out .= $this->pick(self::TEXTS, 13);
            } elseif ($roll <= 38) {
                $out .= $this->pick(self::COMMENTS, 2);
            } else {
                $tag = $this->noise(30) ? $this->pick(self::FLOW) : ($phrasing ? $this->pick(['span', 'strong', 'b', 'em', 'small', 'br']) : $this->pick(array_slice(self::FLOW, 0, 23)));
                if ($this->clean && in_array($tag, ['ul', 'ol', 'li', 'a'], true)) {
                    $tag = 'div';
                }
                $attributes = $this->chance(40) ? ' class="'.$this->pick(self::CLASSES, 1).'"' : '';
                if ($this->chance(15)) {
                    $attributes .= $this->pick([' data-x=1', ' href="/x"', ' title="a&amp;b"', ' id=a_b', ' alt="Other.Release-GRP"', ' class="y"', ' href=/x', ' DATA="1"'], 4);
                }
                $children = $this->randomNodes($depth - 1, $phrasing || in_array($tag, ['p', 'h1', 'h2', 'h3', 'span', 'strong', 'b', 'em', 'small'], true));
                $close = $this->noise(8) ? $this->pick(['', '</div>', '</span>', '</p>']) : '</'.$tag.'>';
                $out .= in_array($tag, ['img', 'br', 'hr', 'meta'], true) ? '<'.$tag.$attributes.'>' : '<'.$tag.$attributes.'>'.$children.$close;
            }
        }

        return $out;
    }
}
