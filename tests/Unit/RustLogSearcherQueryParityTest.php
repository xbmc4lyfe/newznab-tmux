<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\Index\LogIndexQuery;
use App\Services\LogViewer\LogFileRepository;
use App\Services\LogViewer\LogReader;
use App\Services\LogViewer\LogSearcher;
use App\Services\LogViewer\SearchQuery;
use App\Support\RustPipelineLibrary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Parity and paired benchmarks for the log viewer's Rust paths (`nntmux_log_*` in `rust/pipeline-ffi/src/media.rs`).
 *
 * Paired randomized benchmark (`LOG_SEARCH_BENCHMARK_PAIRS=200` interleaved pairs in random order, warmups
 * untimed, release dylib `opt-level=3, lto=true, codegen-units=1`, PHP 8.5.12 NTS, Apple M5 macOS 27.2;
 * nearest-rank p99; `LOG_SEARCH_BENCHMARK_REPORT=1` prints raw results with source hashes). The `search()` rows
 * time the whole php-engine search of one file (open, scan, window, entry resolution, highlighting; limit 50);
 * the grep rows time `grepHits`' 16 KiB chunk buffering plus parse and hit window, without the grep process.
 *
 * | Path / Workload | PHP Mean / p99 (ms) | Rust FFI Mean / p99 (ms) | Mean / p99 Speedup | Production Path |
 * | --- | --- | --- | --- | --- |
 * | `search()` php engine, `needle` case-insensitive, 4 MiB | 5.905 / 6.397 | 2.346 / 2.673 | 2.52x / 2.39x | Adopted Rust FFI |
 * | `search()` php engine, `Needle` case-sensitive, 4 MiB | 6.693 / 12.586 | 2.498 / 5.325 | 2.68x / 2.36x | Adopted Rust FFI |
 * | `search()` php engine, level-only `error,critical`, 4 MiB | 16.214 / 30.257 | 2.336 / 4.442 | 6.94x / 6.81x | Adopted Rust FFI |
 * | `search()` php engine, dense `e` (every line), 24 MiB | 53.257 / 72.364 | 12.633 / 16.234 | 4.22x / 4.46x | Adopted Rust FFI |
 * | grep output buffering + parse + window, 20k hits | 2.767 / 3.547 | 0.421 / 0.605 | 6.58x / 5.87x | Adopted Rust FFI |
 * | grep output buffering + parse + window, 200k hits | 29.852 / 44.258 | 4.597 / 7.244 | 6.49x / 6.11x | Adopted Rust FFI |
 * | `SearchQuery::plainRanges` x2000, 111 B message | 0.324 / 0.475 | 0.716 / 0.917 | 0.45x / 0.52x | Retained PHP (`< 1.5x`) |
 * | `SearchQuery::plainRanges` x2000, 4 KiB trace body | 9.293 / 9.785 | 4.687 / 5.067 | 1.98x / 1.93x | Retained PHP (message path fails) |
 * | `LogIndexQuery::matchExpression` x2000, ASCII term | 0.814 / 1.137 | 0.477 / 0.650 | 1.71x / 1.75x | Retained PHP (Unicode path fails) |
 * | `LogIndexQuery::matchExpression` x2000, Unicode term (declined) | 0.821 / 1.134 | 1.216 / 1.553 | 0.68x / 0.73x | Retained PHP (`< 1.5x`) |
 *
 * Highlighting runs per rendered entry and is dominated by short messages, and Rust declines non-ASCII index
 * terms, so both retained helpers miss the gate on a required workload; they stay as parity-tested `*Native`
 * helpers. Regular-expression searches keep PHP's PCRE (and grep -P) unchanged.
 */
final class RustLogSearcherQueryParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_log_parse_grep_lines(const char *input, size_t input_len, int64_t before, size_t keep, int64_t *window);
        ssize_t nntmux_log_scan_chunk_hits(const char *chunk, size_t chunk_len, size_t start, uint32_t is_final, uint32_t mode, const char *term, size_t term_len, uint32_t level_mask, int64_t base_offset, int64_t line_number, int64_t before, size_t keep, int64_t *window, int64_t *state_out);
        ssize_t nntmux_log_plain_ranges(const char *text, size_t text_len, const char *term, size_t term_len, uint32_t case_sensitive, size_t max_ranges, char *out_buf, size_t out_cap);
        ssize_t nntmux_log_index_match_expression(const char *term, size_t term_len, size_t min_infix, char *out_buf, size_t out_cap);
        C;

    private const array LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', 'TRACE', 'Error'];

    private const array WORDS = ['needle', 'Needle', 'NEEDLE', 'needl', 'haystack', 'App\\Jobs\\ReindexReleaseJob', 'é', "\xff", 'ünïcödé', ':', ']', '[', 'x.ERROR:', '  ', "\t", 'e'];

    private const array TERMS = ['needle', 'Needle', 'NEEDLE', 'ne', 'e', 'Jobs\\Reindex', 'ünï', "\xff", 'ERROR:', '] local', 'zzz-absent', 'É', 'a b'];

    private string $directory;

    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();

        if (RustPipelineLibrary::resolveDomain('log_searcher_parity', self::CDEF) === null) {
            self::markTestSkipped('libnntmux_pipeline with the log viewer exports is not built.');
        }

        $this->directory = storage_path('framework/testing/log-parity-'.Str::uuid()->toString());
        File::ensureDirectoryExists($this->directory);
        config(['nntmux.log_viewer.path' => $this->directory, 'nntmux.log_viewer.search_timeout' => 120]);
        $this->random = new Randomizer(new Mt19937(20261024));
    }

    protected function tearDown(): void
    {
        LogSearcher::setUseRustFfi(true);
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function the_rust_paths_are_taken_instead_of_the_php_fallbacks(): void
    {
        $searcher = $this->searcher();
        $phpCollects = 0;
        $countingCollect = static function () use (&$phpCollects): void {
            $phpCollects++;
        };

        [, $window, $result] = (new ReflectionMethod(LogSearcher::class, 'hitWindow'))->invoke($searcher, null, 4);
        $this->assertNotNull($window['ring']);
        (new ReflectionMethod(LogSearcher::class, 'collectGrepLines'))->invoke($searcher, "1:2:x\n3:4:y\n5:6:z", $countingCollect, $window);
        $this->assertSame(3, $window['ring'][2], 'grep output is parsed into the native window');
        $this->assertSame([3, 3, [[2, 1], [4, 3], [6, 5]]], $result());

        $this->writeLog("[2026-10-01 10:00:00] local.ERROR: needle\n#0 needle\nplain\n");
        $file = app(LogFileRepository::class)->find('app.log');
        $this->assertNotNull($file);
        $phpHits = new ReflectionMethod(LogSearcher::class, 'phpHits');

        foreach ([new SearchQuery('NEEDLE'), new SearchQuery('needle', caseSensitive: true), new SearchQuery('', levels: ['error'])] as $query) {
            [, $window, $result] = (new ReflectionMethod(LogSearcher::class, 'hitWindow'))->invoke($searcher, null, 4);
            $status = $phpHits->invoke($searcher, $query, $file, microtime(true) + 60, $countingCollect, $window);
            $this->assertSame(['timed_out' => false, 'error' => null], $status);
            $this->assertGreaterThan(0, $window['ring'][2], 'the chunk scanner collects into the native window');
            $this->assertGreaterThan(0, $result()[0]);
        }

        $this->assertSame(0, $phpCollects);
    }

    #[Test]
    public function php_engine_search_results_match_with_and_without_rust(): void
    {
        config(['nntmux.log_viewer.grep_binary' => '']);
        $this->assertSame('php', $this->searcher()->engine());

        for ($round = 0; $round < 60; $round++) {
            $this->writeLog($this->randomLog(1 + $this->random->getInt(0, 400)));

            foreach ($this->randomQueries(6) as $query) {
                $this->assertPagedParity($query, 1 + $this->random->getInt(0, 12));
            }
        }
    }

    #[Test]
    public function php_engine_handles_chunk_boundaries_long_lines_and_deferred_level_lines(): void
    {
        config(['nntmux.log_viewer.grep_binary' => '']);
        $header = '[2026-10-01 10:00:00] local.ERROR: ';
        $long = str_repeat('x', 1_048_576 + 37);
        $logs = [
            // A line longer than the 1 MiB chunk, around chunk boundaries, with CR LF and no final newline.
            $header."needle {$long}\r\n".str_repeat($header."needle\n", 20_000).$header.'needle tail',
            // Lines straddling the boundary at every alignment.
            str_repeat("[2026-10-01 10:00:00] local.INFO: needle one two three\r\n#0 trace needle\n", 15_000),
            // A level token long enough to defer to PHP's PCRE, then normal headers.
            '[x] '.str_repeat('a.', 3000)."ERROR: deferred\n".$header."after\n".'[x] '.str_repeat('b', 5000).".INFO\n",
            "\n\n\r\n",
            '',
            'needle',
        ];

        foreach ($logs as $log) {
            $this->writeLog($log);

            foreach ([new SearchQuery('needle'), new SearchQuery('NEEDLE', caseSensitive: true), new SearchQuery('', levels: ['error']), new SearchQuery('', channels: ['local']), new SearchQuery('ERROR: deferred')] as $query) {
                $this->assertPagedParity($query, 25);
            }
        }
    }

    #[Test]
    public function an_expensive_level_line_still_reports_the_pcre_error(): void
    {
        config(['nntmux.log_viewer.grep_binary' => '']);
        $this->writeLog("[2026-10-01 10:00:00] local.ERROR: before\n".'[x] '.str_repeat('a.', 600_000)."x\n[2026-10-01 10:00:01] local.ERROR: after\n");
        $query = new SearchQuery('', levels: ['error']);

        $native = $this->searchLog($query, 10, null, true);
        $this->assertSame($this->searchLog($query, 10, null, false), $native);
        $this->assertStringStartsWith('Regular expression is too expensive', (string) $native['results'][0]['error']);
    }

    #[Test]
    public function grep_engine_search_results_match_with_and_without_rust(): void
    {
        $binary = (new ExecutableFinder)->find('ggrep') ?? (new ExecutableFinder)->find('grep');
        config(['nntmux.log_viewer.grep_binary' => (string) $binary]);

        if ($binary === null || $this->searcher()->engine() !== 'grep') {
            self::markTestSkipped('GNU grep is not available on this host.');
        }

        for ($round = 0; $round < 25; $round++) {
            $this->writeLog($this->randomLog(1 + $this->random->getInt(0, 3000)));

            foreach ($this->randomQueries(4) as $query) {
                $this->assertPagedParity($query, 1 + $this->random->getInt(0, 40));
            }
        }
    }

    #[Test]
    public function grep_output_parsing_matches_php_including_malformed_lines(): void
    {
        $lines = ['1:0:x', '12:345:a:b:c', '7:9:', 'noise', '', ' 3:4:lead', '5: 6:space', '8:1e3:exp', ':5:empty', '9::empty',
            '99999999999999999999:1:big', '3:-4:neg', '4:+5:plus', "6:7:\xff\r", '2:3'];

        for ($round = 0; $round < 2000; $round++) {
            $output = '';

            for ($i = $this->random->getInt(0, 40); $i > 0; $i--) {
                $output .= ($this->random->getInt(0, 4) === 0
                    ? $lines[$this->random->getInt(0, count($lines) - 1)]
                    : $this->random->getInt(1, 1_000_000).':'.$this->random->getInt(0, 1 << 40).':'.$this->randomWords(3))."\n";
            }

            $output = $this->random->getInt(0, 1) === 0 ? rtrim($output, "\n") : $output;
            $before = [null, -1, 0, $this->random->getInt(0, 1 << 40), $this->random->getInt(0, 1 << 40)][$this->random->getInt(0, 4)];
            $keep = $this->random->getInt(1, 12);

            LogSearcher::setUseRustFfi(false);
            $php = $this->collectGrep($output, $before, $keep);
            LogSearcher::setUseRustFfi(true);
            $this->assertSame($php, $this->collectGrep($output, $before, $keep), $output);
        }
    }

    #[Test]
    public function plain_ranges_native_helper_matches_php(): void
    {
        $plainRanges = new ReflectionMethod(SearchQuery::class, 'plainRanges');

        for ($round = 0; $round < 3000; $round++) {
            $query = new SearchQuery(self::TERMS[$this->random->getInt(0, count(self::TERMS) - 1)], caseSensitive: $this->random->getInt(0, 1) === 1);
            $text = $this->randomWords($this->random->getInt(0, 400));

            $this->assertSame($plainRanges->invoke($query, $text), $query->plainRangesNative($text), $query->term.' in '.$text);
        }

        $this->assertNull((new SearchQuery(''))->plainRangesNative('text'));
        $this->assertCount(200, (array) (new SearchQuery('a'))->plainRangesNative(str_repeat('a', 500)));
    }

    #[Test]
    public function match_expression_native_helper_matches_php_for_ascii_and_declines_unicode(): void
    {
        $supported = false;
        $alphabet = ['a', 'B', 'z', '0', '9', '_', ' ', '.', '\\', '-', ':', '"', '*', "\t", 'Jobs', 'ReindexRel', 'ab'];

        for ($round = 0; $round < 5000; $round++) {
            $term = '';

            for ($i = $this->random->getInt(0, 12); $i > 0; $i--) {
                $term .= $alphabet[$this->random->getInt(0, count($alphabet) - 1)];
            }

            $native = LogIndexQuery::matchExpressionNative($term, $supported);
            $this->assertTrue($supported);
            $this->assertSame(LogIndexQuery::matchExpression($term), $native, $term);
        }

        $this->assertNull(LogIndexQuery::matchExpressionNative('Fehlerprüfung', $supported));
        $this->assertFalse($supported);
    }

    #[Test]
    public function adopted_paths_meet_the_speedup_gate(): void
    {
        $pairs = $this->pairs();
        config(['nntmux.log_viewer.grep_binary' => '']);
        $results = [];

        foreach ([
            'php engine needle ci 4 MiB' => [4, new SearchQuery('needle'), 3],
            'php engine Needle cs 4 MiB' => [4, new SearchQuery('Needle', caseSensitive: true), 3],
            'php engine level-only 4 MiB' => [4, new SearchQuery('', levels: ['error', 'critical']), 3],
            'php engine dense e 24 MiB' => [24, new SearchQuery('e'), 2],
        ] as $workload => [$mebibytes, $query, $warmups]) {
            $this->writeLog($this->benchmarkLog($mebibytes));
            $expected = $this->searchLog($query, 50, null, false);
            $this->assertSame($expected, $this->searchLog($query, 50, null, true));

            $results[$workload] = $this->paired($pairs, $warmups, fn (bool $rust): array => $this->searchLog($query, 50, null, $rust), $expected);
        }

        foreach (['grep parse 20k hits' => 20_000, 'grep parse 200k hits' => 200_000] as $workload => $hits) {
            $chunks = str_split($this->grepOutput($hits), 16_384);
            $expected = $this->streamGrep($chunks, false);
            $results[$workload] = $this->paired($pairs, 3, fn (bool $rust): array => $this->streamGrep($chunks, $rust), $expected);
        }

        $this->report($results);

        foreach ($results as $workload => $result) {
            $this->assertGreaterThanOrEqual(1.5, $result['mean_speedup'], $workload.' mean');
            $this->assertGreaterThanOrEqual(1.5, $result['p99_speedup'], $workload.' p99');
        }
    }

    #[Test]
    public function retained_helpers_are_measured_against_php(): void
    {
        $pairs = $this->pairs();
        $plainRanges = new ReflectionMethod(SearchQuery::class, 'plainRanges');
        $query = new SearchQuery('needle');
        $message = 'App\\Jobs\\ReindexReleaseJob failed for release 12345: needle not found in haystack after 3 attempts (needle=abc)';
        $body = str_repeat("#12 /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(441): needle->handle()\n", 48);
        $results = [];

        foreach (['plainRanges message' => $message, 'plainRanges body' => $body] as $workload => $text) {
            $expected = $plainRanges->invoke($query, $text);
            $results[$workload] = $this->paired($pairs, 3, static function (bool $rust) use ($plainRanges, $query, $text): mixed {
                $result = null;

                for ($i = 0; $i < 2000; $i++) {
                    $result = $rust ? $query->plainRangesNative($text) : $plainRanges->invoke($query, $text);
                }

                return $result;
            }, $expected);
        }

        // As production would call it: Rust first, PHP when Rust declines a non-ASCII term.
        foreach (['matchExpression ascii' => 'App\\Jobs\\ReindexRel', 'matchExpression unicode' => 'Überprüfung fehlgeschlagen'] as $workload => $term) {
            $results[$workload] = $this->paired($pairs, 3, static function (bool $rust) use ($term): ?string {
                $result = null;
                $supported = false;

                for ($i = 0; $i < 2000; $i++) {
                    if (! $rust) {
                        $result = LogIndexQuery::matchExpression($term);

                        continue;
                    }

                    $result = LogIndexQuery::matchExpressionNative($term, $supported);
                    $result = $supported ? $result : LogIndexQuery::matchExpression($term);
                }

                return $result;
            }, LogIndexQuery::matchExpression($term));
        }

        $this->report($results);
        $this->assertCount(4, $results);
    }

    private function searcher(): LogSearcher
    {
        return new LogSearcher(app(LogReader::class));
    }

    private function writeLog(string $contents): void
    {
        File::put($this->directory.'/app.log', $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function searchLog(SearchQuery $query, int $limit, ?int $before, bool $rust): array
    {
        LogSearcher::setUseRustFfi($rust);
        $file = app(LogFileRepository::class)->find('app.log');
        $this->assertNotNull($file);
        $result = $this->searcher()->search($query, [$file], $before, $limit);
        unset($result['elapsed_ms']);

        return $result;
    }

    private function assertPagedParity(SearchQuery $query, int $limit): void
    {
        $before = null;

        for ($page = 0; $page < 4; $page++) {
            $php = $this->searchLog($query, $limit, $before, false);
            $this->assertSame($php, $this->searchLog($query, $limit, $before, true), json_encode([$query->term, $query->caseSensitive, $query->levels, $before], JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            $before = $php['results'][0]['before'];

            if ($before === null) {
                return;
            }
        }
    }

    /**
     * @return list<SearchQuery>
     */
    private function randomQueries(int $count): array
    {
        $levelNames = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $queries = [];

        for ($i = 0; $i < $count; $i++) {
            $levels = $this->random->getInt(0, 2) === 0 ? array_values(array_intersect($levelNames, [$levelNames[$this->random->getInt(0, 7)], $levelNames[$this->random->getInt(0, 7)]])) : [];
            $queries[] = match ($this->random->getInt(0, 4)) {
                0 => new SearchQuery('', levels: $levels !== [] ? $levels : ['error']),
                1 => new SearchQuery('', channels: ['local']),
                2 => new SearchQuery('need\w+', regex: true, levels: $levels),
                default => new SearchQuery(self::TERMS[$this->random->getInt(0, count(self::TERMS) - 1)], caseSensitive: $this->random->getInt(0, 1) === 1, levels: $levels),
            };
        }

        return $queries;
    }

    private function randomLog(int $lines): string
    {
        $log = '';

        for ($i = 0; $i < $lines; $i++) {
            $kind = $this->random->getInt(0, 9);
            $line = match (true) {
                $kind <= 4 => '[2026-10-01 10:'.sprintf('%02d', $i % 60).':00]'.($this->random->getInt(0, 9) === 0 ? "\t" : ' ')
                    .($this->random->getInt(0, 9) === 0 ? 'a-b.c' : 'local').'.'.self::LEVELS[$this->random->getInt(0, count(self::LEVELS) - 1)].': '.$this->randomWords(6),
                $kind <= 7 => '#'.$i.' '.$this->randomWords(5),
                $kind === 8 => '',
                default => '[x] '.$this->randomWords(3),
            };
            $log .= $line.($this->random->getInt(0, 9) === 0 ? "\r\n" : "\n");
        }

        return $this->random->getInt(0, 3) === 0 ? rtrim($log, "\n") : $log;
    }

    private function randomWords(int $count): string
    {
        $words = [];

        for ($i = 0; $i < $count; $i++) {
            $words[] = self::WORDS[$this->random->getInt(0, count(self::WORDS) - 1)];
        }

        return implode($this->random->getInt(0, 5) === 0 ? '' : ' ', $words);
    }

    private function benchmarkLog(int $mebibytes): string
    {
        $levels = ['INFO', 'INFO', 'INFO', 'DEBUG', 'WARNING', 'ERROR', 'NOTICE'];
        $lines = [];
        $size = 0;

        for ($i = 0; $size < $mebibytes * 1_048_576; $i++) {
            $line = $i % 9 === 4
                ? '#'.($i % 30).' /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php('.$i.'): App\\Jobs\\ProcessRelease->handle()'
                : '[2026-10-01 10:00:00] production.'.$levels[$i % 7].': Processed release '.$i.' in group alt.binaries.test'.($i % 211 === 0 ? ' needle' : '').($i % 977 === 0 ? ' Needle' : '');
            $lines[] = $line;
            $size += strlen($line) + 1;
        }

        return implode("\n", $lines)."\n";
    }

    private function grepOutput(int $hits): string
    {
        $output = '';
        $offset = 0;

        for ($i = 1; $i <= $hits; $i++) {
            $line = '[2026-10-01 10:00:00] production.INFO: Processed release '.$i.' in group alt.binaries.test needle';
            $output .= ($i * 3).':'.$offset.':'.$line."\n";
            $offset += strlen($line) * 3;
        }

        return $output;
    }

    /**
     * Mirrors `LogSearcher::grepHits` buffering over the given output chunks with `searchFile`'s hit window.
     *
     * @param  list<string>  $chunks
     * @return array{int, int, list<array{int, int}>}
     */
    private function streamGrep(array $chunks, bool $rust): array
    {
        LogSearcher::setUseRustFfi($rust);
        $searcher = $this->searcher();
        $method = new ReflectionMethod(LogSearcher::class, 'collectGrepLines');
        [$collect, $window, $result] = (new ReflectionMethod(LogSearcher::class, 'hitWindow'))->invoke($searcher, null, 200);
        $buffer = '';

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            $lastNewline = strrpos($buffer, "\n");

            if ($lastNewline === false) {
                continue;
            }

            $method->invoke($searcher, substr($buffer, 0, $lastNewline), $collect, $window);
            $buffer = substr($buffer, $lastNewline + 1);
        }

        $method->invoke($searcher, $buffer, $collect, $window);

        return $result();
    }

    /**
     * Feeds the output in a few pieces, so the native window persists across calls and PHP fallbacks interleave.
     *
     * @return array{int, int, list<array{int, int}>}
     */
    private function collectGrep(string $output, ?int $before, int $keep): array
    {
        $searcher = $this->searcher();
        $method = new ReflectionMethod(LogSearcher::class, 'collectGrepLines');
        [$collect, $window, $result] = (new ReflectionMethod(LogSearcher::class, 'hitWindow'))->invoke($searcher, $before, $keep);
        $lines = explode("\n", $output);

        foreach (array_chunk($lines, 1 + $this->random->getInt(0, 9)) as $piece) {
            $method->invoke($searcher, implode("\n", $piece), $collect, $window);
        }

        return $result();
    }

    private function pairs(): int
    {
        return max(40, min(2000, (int) (getenv('LOG_SEARCH_BENCHMARK_PAIRS') ?: 40)));
    }

    /**
     * @param  callable(bool): mixed  $call
     * @return array{pairs: int, php_mean_ms: float, rust_mean_ms: float, php_p99_ms: float, rust_p99_ms: float, mean_speedup: float, p99_speedup: float}
     */
    private function paired(int $pairs, int $warmups, callable $call, mixed $expected): array
    {
        for ($i = 0; $i < $warmups; $i++) {
            $this->assertSame($expected, $call(false));
            $this->assertSame($expected, $call(true));
        }

        $times = ['php' => [], 'rust' => []];

        for ($pair = 0; $pair < $pairs; $pair++) {
            $order = $this->random->getInt(0, 1) === 0 ? [false, true] : [true, false];

            foreach ($order as $rust) {
                $start = hrtime(true);
                $result = $call($rust);
                $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1e6;
                $this->assertSame($expected, $result);
                unset($result);
            }
        }

        LogSearcher::setUseRustFfi(true);
        sort($times['php']);
        sort($times['rust']);
        $p99 = (int) ceil($pairs * 0.99) - 1;
        $phpMean = array_sum($times['php']) / $pairs;
        $rustMean = array_sum($times['rust']) / $pairs;

        return [
            'pairs' => $pairs,
            'php_mean_ms' => round($phpMean, 3),
            'rust_mean_ms' => round($rustMean, 3),
            'php_p99_ms' => round($times['php'][$p99], 3),
            'rust_p99_ms' => round($times['rust'][$p99], 3),
            'mean_speedup' => round($phpMean / $rustMean, 2),
            'p99_speedup' => round($times['php'][$p99] / $times['rust'][$p99], 2),
        ];
    }

    /**
     * @param  array<string, array<string, float|int>>  $results
     */
    private function report(array $results): void
    {
        if (getenv('LOG_SEARCH_BENCHMARK_REPORT') === false) {
            return;
        }

        fwrite(STDOUT, json_encode([
            'php_version' => PHP_VERSION,
            'host' => php_uname(),
            'source_sha256' => [
                'log_searcher' => hash_file('sha256', base_path('app/Services/LogViewer/LogSearcher.php')),
                'search_query' => hash_file('sha256', base_path('app/Services/LogViewer/SearchQuery.php')),
                'log_index_query' => hash_file('sha256', base_path('app/Services/LogViewer/Index/LogIndexQuery.php')),
                'media_rs' => hash_file('sha256', base_path('rust/pipeline-ffi/src/media.rs')),
                'harness' => hash_file('sha256', __FILE__),
            ],
            'results' => $results,
        ], JSON_PRETTY_PRINT)."\n");
    }
}
