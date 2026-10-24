<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use App\Services\TvProcessing\Providers\BaseVideoProvider;
use App\Support\RustPipelineLibrary;
use ErrorException;
use FFI;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Reproduce single-call paired benchmarks with NNTMUX_IMDB_SEARCH_BENCH=1 and
 * NNTMUX_IMDB_SEARCH_BENCH_OUT=/tmp/wave10-imdb-search.json php artisan test --compact
 * tests/Unit/RustImdbSearchVideoPatternParityTest.php --filter=benchmark.
 * 400 warmups and 10,000 randomized pairs per method/workload; hrtime(true) includes
 * the service wrapper, FFI, shared scratch, output allocation and fallback work.
 * Raw observations, fixture and side order, source hashes and runtime metadata
 * are retained in the JSON. Common corpora include short titles and one-result
 * suggestions; uncommon corpora include Unicode, escaped JSON, nested HTML,
 * long titles/URLs and large page shells. An aggregate gain cannot excuse a
 * failing mean or p99 in either corpus.
 *
 * 2026-10-24: Apple M5 / macOS 27.2 arm64, PHP 8.5.12 (PCRE JIT on,
 * CLI OPcache off), rustc 1.99.1, release opt-level=3/LTO/one codegen unit.
 * Three repetitions of 10,000 pairs per corpus; ranges below describe run
 * variation, not confidence intervals. No candidate clears every 1.5x gate.
 * Production remains PHP; *Native methods are experimental parity candidates.
 * Ratios are PHP/Rust, common mean/p99; uncommon mean/p99:
 *
 * parseSuggestionJson: 1.51-1.54/1.48-1.56; 1.50-1.52/1.31-1.42x.
 * parseSearchHtml: 1.32-1.40/1.38-1.48; 0.78-0.79/0.90-0.91x.
 * extractYearFromText: 0.33-0.34/0.35-0.45; 0.16-0.19/0.17-0.19x.
 * normalizeImageUrl: 0.37-0.37/0.39-0.43; 0.36-0.38/0.32-0.39x.
 * normalizeSearchSlug: 0.45-0.56/0.47-0.80; 1.36-1.46/1.89-2.75x.
 * loosePattern: 1.05-1.11/1.50-2.00; 4.78-4.99/7.60-8.96x.
 * titleWithoutYear: 0.45-0.45/0.53-0.57; 1.12-1.25/1.44-2.45x.
 * normalizeTitleConjunction: 0.31-0.35/0.31-0.38; 0.82-0.99/1.07-1.41x.
 * normalizeTitleSpelling: 0.32-0.33/0.31-0.38; 0.53-0.71/0.62-1.00x.
 * Raw observations and measured source snapshots:
 * /tmp/wave10-imdb-search-verified-{1,2,3}.json.
 * Baseline base: 1daf6378120fd3ae14af916bff0fc0d2088d41c6.
 * The final change after measurement only adds this results comment.
 */
final class RustImdbSearchVideoPatternParityTest extends TestCase
{
    private ImdbScraper $scraper;

    private ImdbScraper $phpScraper;

    private BaseVideoProvider $video;

    private BaseVideoProvider $phpVideo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scraper = new ImdbScraper;
        $this->phpScraper = new ImdbScraper(useNative: false, useRustFfi: false);
        $this->video = new class extends BaseVideoProvider {};
        $this->phpVideo = new class(useRustFfi: false) extends BaseVideoProvider {};
    }

    private function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('media', <<<'C'
            ssize_t nntmux_imdb_search_text(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
            ssize_t nntmux_imdb_suggestion_rows(const char *input, size_t input_len, char *out_buf, size_t out_cap);
            ssize_t nntmux_imdb_search_html_rows(const char *input, size_t input_len, char *out_buf, size_t out_cap);
            C);
        self::assertNotNull($ffi, 'Build the release library with the IMDb search exports.');

        return $ffi;
    }

    public function test_ffi_contract_and_strict_subset_are_exercised(): void
    {
        $ffi = $this->ffi();
        $buf = $ffi->new('char[1024]');
        $json = '{"d":[{"id":"tt12345678","l":" Example ","y":2024,"qid":"tvSeries","i":{"imageUrl":"https://x/a._V1_SX.jpg"}}]}';
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_imdb_suggestion_rows($json, strlen($json), $buf, 1024);
        self::assertSame("12345678\0Example\0"."2024\0", FFI::string($buf, $written));
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen("12345678\0Example\0"."2024\0"), $ffi->nntmux_imdb_suggestion_rows($json, strlen($json), $buf, 1));
        $html = '<a href="/title/tt12345678/">Example</a><span>2024</span>';
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_imdb_search_html_rows($html, strlen($html), $buf, 1024);
        self::assertSame("12345678\0Example\0"."2024\0", FFI::string($buf, $written));
        foreach (['{"d":[{"id":"tt1","l":"\\u00e9"}]}', '{"d":[]} trailing', '{"d":[{"id":"tt1","l":1}]}'] as $body) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_imdb_suggestion_rows($body, strlen($body), $buf, 1024));
        }
        foreach (['<a href="/title/tt1234567/?ref=x">DOM &amp; fallback</a>2020', '<a href="/title/tt1234567/"><b>Nested</b></a>2020'] as $body) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_imdb_search_html_rows($body, strlen($body), $buf, 1024));
        }
    }

    public function test_suggestions_preserve_json_semantics_order_and_limit(): void
    {
        $this->ffi();
        $bodies = [
            '', 'null', '[]', '{}', '{"d":null}', '{"d":[]}',
            '{"d":[null,1,"x",{"id":"nm123","l":"Name"},{"id":"tt","l":"Zero","y":0}]}',
            '{"d":[{"id":"tt1234567","l":"first","l":"last","y":2024}],"d":[]}',
            '{"d":[{"id":"tt1234567","l":"A \\"quote\\" \\/ slash \\\\ and \\t tab","y":" 2020 "}]}',
            '{"d":[{"id":"tt1234567","l":"\\ud83d\\ude00 \\u00e9 \\u0000","y":1e3}]}',
            '{"d":[{"id":"tt1234567","l":"Bad trailing"},]}',
            '{"d":[{"id":"tt1234567","l":"Good"}],"extra":[true,false,null,-1.23e+4,{"a":[]}]}',
            '{"d":[{"id":"tt1234567","l":"Good"}],"extra":01}',
            '{"d":[{"id":"tt1234567","l":"Good"}],"extra":"\\ud800"}',
            "{\"d\":[{\"id\":\"tt1234567\",\"l\":\"invalid\xff\"}]}",
            '{"d":'.str_repeat('[', 520).str_repeat(']', 520).'}',
        ];
        foreach (['null', 'true', 'false', '1', '1.5', '1e30', '-0', '9223372036854775808', '[]', '{}', '""', '"tt1234567"', '" leading \\t "'] as $value) {
            foreach (['id', 'l', 'y', 'qid', 'i'] as $field) {
                $row = ['id' => '"tt01234567"', 'l' => '" Example "', 'y' => '2020', 'qid' => '"movie"', 'i' => '{"imageUrl":"url"}'];
                $row[$field] = $value;
                $parts = [];
                foreach ($row as $key => $raw) {
                    $parts[] = '"'.$key.'":'.$raw;
                }
                $bodies[] = '{"d":[{'.implode(',', $parts).'}]}';
            }
        }
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['id' => 'tt'.sprintf('%07d', $i % 30), 'l' => " Movie {$i} 東京 ", 'y' => 1900 + $i, 'qid' => $i % 2 ? 'movie' : 'tvSeries', 'i' => ['imageUrl' => 'https://x/poster._V1_UX100.jpg']];
        }
        $bodies[] = (string) json_encode(['d' => $rows], JSON_UNESCAPED_UNICODE);
        foreach ($bodies as $body) {
            $this->assertParity($this->phpScraper, $this->scraper, 'parseSuggestionJson', $body);
        }
        $result = $this->invokeHelper($this->scraper, 'parseSuggestionJsonNative', end($bodies));
        self::assertCount(25, $result);
        self::assertSame(['imdbid' => '0000000', 'title' => 'Movie 0 東京', 'year' => '1900'], $result[0]);
    }

    public function test_search_html_matches_pcre_and_dom_fallback(): void
    {
        $this->ffi();
        $bodies = [
            '', '<p>Nothing 2024</p>',
            '<a href="/title/tt1234567/"> Movie </a><span>2020</span>',
            '<A class="title" HREF=\'/TITLE/TT12345678/\'>Case</A> 1999',
            '<a href="/title/tt12345/">Short legacy ID</a> 2000',
            '<a href="/title/tt12345678/?ref_=fn_al_tt_1">DOM only</a> 2001',
            '<a href=/title/tt1234567/>Unquoted</a> 2002',
            '<a href="/title/tt1234567/\'>Mixed quotes</a> 2003',
            '<a data-x=" href=\'/title/tt1234567/\'">Regex attribute</a>2004',
            '<a href="/title/tt1234567/">Tom &amp; Jerry &apos; &#x1F600;</a>2005',
            '<a href="/title/tt1234567/"><span>Nested</span> title</a><span>2006</span>',
            '<!-- <a href="/title/tt1234567/">Inside comment</a> 2007 -->',
            '<script>"<a href="/title/tt1234567/">Inside script</a>2008"</script>',
            '<abbr href="/title/tt1234567/">Tag prefix</a>2009',
            '<a href="/title/tt1234567/">A</a><div data-x="1990">2020</div>',
            '<a href="/title/tt1234567/">A</a><script>var x="<"; 1990;</script>2020',
            '<a href="/title/tt1234567/">   </a>2020',
            "<a href=\"/title/tt1234567/\">bad\0title\xff</a>2020",
            '<a href="/title/tt1234567/">Long context</a>'.str_repeat('x', 199).'<span>1999</span>',
        ];
        $body = '';
        for ($i = 0; $i < 40; $i++) {
            $body .= '<li><a class="title" href="/title/tt'.sprintf('%07d', $i % 18).'/">Movie '.$i.'</a><span>'.(1980 + $i).'</span></li>';
        }
        $bodies[] = $body;
        foreach ($bodies as $html) {
            $this->assertParity($this->phpScraper, $this->scraper, 'parseSearchHtml', $html);
        }
        $result = $this->invokeHelper($this->scraper, 'parseSearchHtmlNative', $body);
        self::assertCount(18, $result);
        self::assertSame('Movie 18', $result[0]['title']);
    }

    public function test_text_helpers_and_video_patterns_match_php(): void
    {
        $this->ffi();
        $strings = ['', 'The Office (2005)', 'Law and Order!', "Peter's Theater", '  a   b  ', '%_\\!\'', "a\tb\nc\rd\ve\ff", " \0A\0 B ", 'İSTANBUL ÉTÉ Σ 東京', "Bad\xff", 'x19001 2099 1899 2100', 'a (0000)', 'a (9999)', ' (2020)', "a\n(2020)", "a (2020)\n", '(2020)', "A\f(2020)", " \f(2020)", "\f(2020)", "A\f \f(2020)"];
        mt_srand(20261024);
        $tokens = ['a', ' ', ' and ', 'AND', 'ER', 'er', '!', "'", '(2020)', '(9999)', "\n", "\f", "\v", "\0", '東京', '%', "\xff"];
        for ($i = 0; $i < 250; $i++) {
            $value = '';
            for ($j = 0, $n = mt_rand(1, 15); $j < $n; $j++) {
                $value .= $tokens[mt_rand(0, count($tokens) - 1)];
            }
            $strings[] = $value;
        }
        foreach ($strings as $text) {
            foreach (['extractYearFromText', 'normalizeSearchSlug', 'normalizeImageUrl'] as $method) {
                $this->assertParity($this->phpScraper, $this->scraper, $method, $text);
            }
            foreach (['loosePattern', 'titleWithoutYear', 'normalizeTitleConjunction', 'normalizeTitleSpelling'] as $method) {
                $this->assertParity($this->phpVideo, $this->video, $method, $text);
            }
        }
        foreach ([null, [], ['url' => ' x._V1_X.jpg '], [['url' => ' y._V1_X.png ']], ['z._V1_X.jpg'], 'x._V1_a.b.jpg?foo.bar', "x._V1_a\nb.jpg", "x._V1_a.b\nx._V1_c.d", '._V1_.', '._V1_'] as $image) {
            $this->assertParity($this->phpScraper, $this->scraper, 'normalizeImageUrl', $image);
        }
        self::assertSame('tom_jerry_2020_', $this->invokeHelper($this->scraper, 'normalizeSearchSlug', '  Tom & Jerry (2020)  '));
    }

    public function test_get_by_title_keeps_lookup_order_and_early_returns(): void
    {
        foreach ([false, true] as $enabled) {
            $provider = new class($enabled) extends BaseVideoProvider
            {
                /** @var list<array{string, string, int, int}> */
                public array $calls = [];

                public int $hitAt = 0;

                public function getTitleExact(string $title, int $type, int $source = 0): int
                {
                    return $this->record('exact', $title, $type, $source);
                }

                public function getAlternativeTitleExact(string $title, int $type, int $source = 0): int
                {
                    return $this->record('alternative', $title, $type, $source);
                }

                public function getTitleLoose(mixed $title, mixed $type, int $source = 0): int
                {
                    return $this->record('loose', (string) $title, (int) $type, $source);
                }

                private function record(string $kind, string $title, int $type, int $source): int
                {
                    $this->calls[] = [$kind, $title, $type, $source];

                    return count($this->calls) === $this->hitAt ? 42 : 0;
                }
            };
            $title = 'Peter and Theater (2020)';
            $expected = [
                ['exact', $title, 1, 3], ['exact', 'Peter and Theater', 1, 3], ['alternative', $title, 1, 3],
                ['exact', 'Peter & Theater (2020)', 1, 3], ['loose', '%Peter%&%Theater%(2020)%', 1, 3],
                ['exact', 'Petre and Theatre (2020)', 1, 3], ['loose', '%Petre%and%Theatre%(2020)%', 1, 3],
            ];
            foreach (range(0, count($expected)) as $hitAt) {
                $provider->calls = [];
                $provider->hitAt = $hitAt;
                self::assertSame($hitAt === 0 ? 0 : 42, $provider->getByTitle($title, 1, 3));
                self::assertSame($hitAt === 0 ? $expected : array_slice($expected, 0, $hitAt), $provider->calls);
            }
            $provider->calls = [];
            $provider->hitAt = 0;
            $provider->getByTitle('A Show', 0);
            self::assertSame([['exact', 'A Show', 0, 0], ['alternative', 'A Show', 0, 0], ['loose', '%A%Show%', 0, 0]], $provider->calls);
            $provider->calls = [];
            $provider->getByTitle('Show', 0);
            self::assertCount(2, $provider->calls);
        }
    }

    public function test_search_uses_the_normalized_slug_for_the_url_and_cache(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"d":[{"id":"tt1234567","l":"Movie","y":2024}]}')]));
        $stack->push(Middleware::history($history));
        $scraper = new class(new Client(['handler' => $stack]), useRustFfi: false) extends ImdbScraper
        {
            public function isScrapingEnabled(): bool
            {
                return true;
            }
        };
        Cache::flush();
        $expected = [['imdbid' => '1234567', 'title' => 'Movie', 'year' => '2024']];
        self::assertSame($expected, $scraper->search('  Tom & Jerry (2020)  '));
        self::assertSame($expected, $scraper->search('Tom---Jerry---2020!'));
        self::assertCount(1, $history);
        self::assertSame('https://v2.sg.media-imdb.com/suggestion/t/tom_jerry_2020_.json', (string) $history[0]['request']->getUri());
        $encoding = mb_internal_encoding();
        try {
            mb_internal_encoding('UTF-16BE');
            $this->assertParity($this->phpScraper, $this->scraper, 'normalizeSearchSlug', 'TeST');
        } finally {
            mb_internal_encoding($encoding);
        }
    }

    public function test_disabled_ffi_candidates_use_php_fallback(): void
    {
        foreach (['extractYearFromText', 'normalizeSearchSlug', 'normalizeImageUrl', 'parseSuggestionJson', 'parseSearchHtml'] as $method) {
            $input = match ($method) {
                'parseSuggestionJson' => '{"d":[{"id":"tt1234567","l":"Movie","y":2020}]}',
                'parseSearchHtml' => '<a href="/title/tt1234567/">Movie</a>2020',
                default => 'Movie (2020)',
            };
            $this->assertParity($this->phpScraper, $this->phpScraper, $method, $input);
        }
        foreach (['loosePattern', 'titleWithoutYear', 'normalizeTitleConjunction', 'normalizeTitleSpelling'] as $method) {
            $this->assertParity($this->phpVideo, $this->phpVideo, $method, 'Theater and Show (2020)');
        }
    }

    public function test_benchmark_paired_single_calls(): void
    {
        if (getenv('NNTMUX_IMDB_SEARCH_BENCH') !== '1') {
            self::markTestSkipped('Opt-in CPU benchmark.');
        }
        $this->ffi();
        $rows = [];
        $html = '';
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['id' => 'tt'.sprintf('%07d', $i), 'l' => 'The Movie Part '.$i, 'y' => 1990 + $i, 'qid' => 'movie', 'i' => ['imageUrl' => 'https://m.media-amazon.com/images/M/poster._V1_QL75_UX182.jpg']];
            $html .= '<li class="find-title-result"><a class="ipc-metadata-list-summary-item__t" href="/title/tt'.sprintf('%07d', $i).'/">The Movie Part '.$i.'</a><span class="year">'.(1990 + $i).'</span></li>';
        }
        $cases = [
            'parseSuggestionJson' => [(string) json_encode(['d' => array_slice($rows, 0, 8), 'q' => 'the movie']), (string) json_encode(['d' => $rows, 'q' => 'the movie', 'metadata' => ['nested' => array_fill(0, 50, ['description' => 'A larger suggestion payload'])]])],
            'parseSearchHtml' => ['<!doctype html><html><body>'.substr($html, 0, (int) strpos($html, '<li', 1000)).'</body></html>', '<!doctype html><html><body>'.str_repeat('<div class="filler">Navigation and page shell</div>', 400).$html.'</body></html>'],
            'extractYearFromText' => ['Released on 21 May 2024', str_repeat('Festival screening: no date. ', 100).'1999-01-02'],
            'normalizeImageUrl' => ['https://m.media-amazon.com/images/M/poster._V1_QL75_UX182.jpg', ' https://m.media-amazon.com/images/M/'.str_repeat('long-image-token', 25).'._V1_QL75_UX182_CR0,0,182,268.jpg?x.y '],
            'normalizeSearchSlug' => [' The Lord of the Rings: Return of the King ', str_repeat('The Strange Title: Part II! ', 15)],
            'loosePattern' => ['The Lord of the Rings: Return of the King!', str_repeat("Peter's Theater and Show! ", 20)],
            'titleWithoutYear' => ['The Office (2005)', str_repeat('A long television title ', 25).'(2024)'],
            'normalizeTitleConjunction' => ['Law and Order', str_repeat('Law and Order AND Another Show ', 20)],
            'normalizeTitleSpelling' => ['Peter and the Theater', str_repeat('Peter and the Theater ', 20)],
        ];
        $cases['parseSuggestionJson'] = [
            [$cases['parseSuggestionJson'][0], (string) json_encode(['d' => array_slice($rows, 0, 1)]), (string) json_encode(['d' => array_slice($rows, 0, 8), 'q' => 'the movie', 'v' => 1])],
            [$cases['parseSuggestionJson'][1], (string) json_encode(['d' => array_replace($rows, [4 => ['id' => 'tt12345678', 'l' => 'Amélie 東京', 'y' => '2001']])]), (string) json_encode(['d' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ];
        $cases['parseSearchHtml'] = [
            [$cases['parseSearchHtml'][0], '<a href="/title/tt1234567/">The Office</a><span>2005</span>', '<a href="/title/tt12345678/">Breaking Bad</a><span>2008</span><a href="/title/tt7654321/">Dexter</a>2006'],
            [$cases['parseSearchHtml'][1], '<a href="/title/tt12345678/?ref=fn">Tom &amp; Jerry</a><span>2021</span>', str_repeat('<div class="filler">Page shell</div>', 100).'<a href="/title/tt1234567/"><b>Nested title</b></a>2024'],
        ];
        $cases['extractYearFromText'] = [[$cases['extractYearFromText'][0], '2005-03-24', 'Unknown release date'], [$cases['extractYearFromText'][1], 'Released in 日本, May ٢٠٢٤', str_repeat('No release date available. ', 80)]];
        $cases['normalizeImageUrl'] = [[$cases['normalizeImageUrl'][0], 'https://m.media-amazon.com/images/M/another._V1_SX300.jpg', ''], [$cases['normalizeImageUrl'][1], "x._V1_a\nb.jpg", 'https://x/poster._V1_.jpg?token=a.b']];
        $cases['normalizeSearchSlug'] = [[$cases['normalizeSearchSlug'][0], ' The Office ', 'Breaking Bad'], [$cases['normalizeSearchSlug'][1], 'ÉTÉ À PARIS 東京', 'Movie—Part II: Director’s Cut']];
        $cases['loosePattern'] = [[$cases['loosePattern'][0], 'The Office', 'Dexter'], [$cases['loosePattern'][1], "L'été à Paris: The Director's Cut! (2024)", "Show  With   Gaps! %_\\\t"]];
        $cases['titleWithoutYear'] = [[$cases['titleWithoutYear'][0], 'The Office', 'Law and Order (1990)'], [$cases['titleWithoutYear'][1], "A title\n(2024)", '東京 (1999)']];
        $cases['normalizeTitleConjunction'] = [[$cases['normalizeTitleConjunction'][0], 'The Office', 'Dexter'], [$cases['normalizeTitleConjunction'][1], 'L’été AND 東京', 'Space And Space and Another Story']];
        $cases['normalizeTitleSpelling'] = [[$cases['normalizeTitleSpelling'][0], 'The Office', 'Dexter'], [$cases['normalizeTitleSpelling'][1], 'Été in the Theater', 'Theatre and Theater: Alternate Versions']];
        $metadata = ['php' => PHP_VERSION, 'os' => php_uname(), 'pcre.jit' => ini_get('pcre.jit'), 'opcache.enable_cli' => ini_get('opcache.enable_cli'), 'warmups' => 400, 'pairs' => 10000, 'seed' => 20261024, 'composer_lock_sha256' => hash_file('sha256', base_path('composer.lock')), 'cargo_lock_sha256' => hash_file('sha256', base_path('rust/pipeline-ffi/Cargo.lock')), 'library_sha256' => hash_file('sha256', base_path('storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')))];
        $metadata['source_sha256'] = [];
        foreach (['app/Services/ImdbScraper.php', 'app/Services/TvProcessing/Providers/BaseVideoProvider.php', 'rust/pipeline-ffi/src/media.rs', 'tests/Unit/RustImdbSearchVideoPatternParityTest.php'] as $path) {
            $metadata['source_sha256'][$path] = hash_file('sha256', base_path($path));
        }
        $report = ['metadata' => $metadata, 'results' => []];
        mt_srand(20261024);
        foreach ($cases as $method => $inputs) {
            $isVideo = in_array($method, ['loosePattern', 'titleWithoutYear', 'normalizeTitleConjunction', 'normalizeTitleSpelling'], true);
            $php = $isVideo ? $this->phpVideo : $this->phpScraper;
            $rust = $isVideo ? $this->video : $this->scraper;
            $phpMethod = new ReflectionMethod($php, $method);
            $rustMethod = new ReflectionMethod($rust, $method.'Native');
            foreach ($inputs as $index => $fixtures) {
                $expected = [];
                foreach ($fixtures as $fixtureIndex => $input) {
                    $expected[$fixtureIndex] = $phpMethod->invoke($php, $input);
                    self::assertSame($expected[$fixtureIndex], $rustMethod->invoke($rust, $input));
                }
                for ($i = 0; $i < 400; $i++) {
                    $input = $fixtures[$i % count($fixtures)];
                    $phpMethod->invoke($php, $input);
                    $rustMethod->invoke($rust, $input);
                }
                $samples = [[], []];
                $fixtureOrder = [];
                $sideOrder = [];
                for ($i = 0; $i < 10000; $i++) {
                    $fixtureIndex = mt_rand(0, count($fixtures) - 1);
                    $input = $fixtures[$fixtureIndex];
                    $order = mt_rand(0, 1) ? [0, 1] : [1, 0];
                    $fixtureOrder[] = $fixtureIndex;
                    $sideOrder[] = $order[0];
                    foreach ($order as $side) {
                        $start = hrtime(true);
                        $value = $side === 0 ? $phpMethod->invoke($php, $input) : $rustMethod->invoke($rust, $input);
                        $samples[$side][] = hrtime(true) - $start;
                        self::assertSame($expected[$fixtureIndex], $value);
                    }
                }
                $means = array_map(static fn (array $x): float => array_sum($x) / count($x), $samples);
                $p99 = array_map(static function (array $x): int {
                    sort($x);

                    return $x[(int) ceil(count($x) * 0.99) - 1];
                }, $samples);
                $name = $method.($index === 0 ? '/common' : '/uncommon');
                $report['results'][$name] = ['fixture_sha256' => array_map(static fn (string $input): string => hash('sha256', $input), $fixtures), 'inputs' => $fixtures, 'mean_ns' => $means, 'p99_ns' => $p99, 'mean_speedup' => $means[0] / $means[1], 'p99_speedup' => $p99[0] / $p99[1], 'samples_ns' => $samples, 'fixture_order' => $fixtureOrder, 'first_side' => $sideOrder];
                fwrite(STDERR, sprintf("%s mean %.3fx p99 %.3fx\n", $name, $means[0] / $means[1], $p99[0] / $p99[1]));
            }
        }
        $out = getenv('NNTMUX_IMDB_SEARCH_BENCH_OUT');
        self::assertIsString($out, 'Set an output path to preserve benchmark observations.');
        self::assertNotFalse(file_put_contents($out, json_encode($report, JSON_THROW_ON_ERROR)));
    }

    private function invokeHelper(object $target, string $method, mixed $input): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, $input);
    }

    /** @return array{value?: mixed, exception?: class-string<Throwable>, message?: string} */
    private function outcome(object $target, string $method, mixed $input): array
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return ['value' => $this->invokeHelper($target, $method, $input)];
        } catch (Throwable $e) {
            return ['exception' => $e::class, 'message' => $e->getMessage()];
        } finally {
            restore_error_handler();
        }
    }

    private function assertParity(object $php, object $rust, string $method, mixed $input): void
    {
        self::assertSame($this->outcome($php, $method, $input), $this->outcome($rust, $method.'Native', $input), $method.' '.bin2hex((string) (is_string($input) ? $input : 'mixed')));
    }
}
