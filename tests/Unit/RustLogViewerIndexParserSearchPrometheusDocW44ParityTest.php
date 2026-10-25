<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\Index\LogIndexQuery;
use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\SearchQuery;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use stdClass;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W445_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, release dylib. Timings include PHP-side filtering and framing, the FFI call and counter
 * decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every common and uncommon mean and p99 ratio reaches 1.5x. The
 * default run only checks that Rust-default helpers are not slower than PHP, so slower CI hosts do not
 * flake; `NNTMUX_W445_BENCH=1` enforces the full 1.5x gate for every Rust default.
 */
class RustLogViewerIndexParserSearchPrometheusDocW44ParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        LogIndexQuery::class,
        LogEntryParser::class,
        SearchQuery::class,
        TextFormatter::class,
        ReleaseSearchIndexDocument::class,
    ];

    /**
     * Benchmark-qualified defaults. Five 300-sample and three 1000-sample runs (Apple Silicon, PHP 8.5 CLI,
     * release dylib) gave min common mean/p99 and uncommon mean/p99 speedups of: LogIndexQuery
     * 3.42/2.73/7.33/4.68, LogEntryParser 3.02/2.34/2.56/1.74, ReleaseSearchIndexDocument 2.42/2.28/2.21/1.67,
     * SearchQuery 3.61/2.58/2.02/1.38 (uncommon p99 1.49x, 1.38x and 1.40x in three runs: framing ~2 MB of
     * lines competes with memchr-backed strpos) and TextFormatter 1.96/1.21/3.88/2.65 (common p99 1.47x and
     * 1.21x on a ~3 us batch). SearchQuery and TextFormatter keep PHP.
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        LogIndexQuery::class => true,
        LogEntryParser::class => true,
        SearchQuery::class => false,
        TextFormatter::class => false,
        ReleaseSearchIndexDocument::class => true,
    ];

    private const RANDOM_ROUNDS = 1500;

    private const ALPHABET = ['a', 'B', 'z', 'Q', '0', '7', '_', '-', '.', '/', '\\', ':', '[', ']', '"', "'", ' ', "\t", "\0", "\n", "\r", "\x0b", "\x0c", "\x85", "\xc3", "\xa9", "\xff"];

    private const LOG_PAGE = [
        '[2026-10-06 18:11:29] production.ERROR: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away {"exception":"[object] (PDOException(code: HY000))"}',
        '#0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(587): PDOStatement->execute()',
        '#1 /app/app/Services/Releases/ReleaseCreationService.php(212): Illuminate\\Database\\Connection->insert()',
        '[2026-10-06 18:11:30] production.INFO: Processed 412 releases in 3.2s',
        '[2026-10-06T18:11:31.482913+00:00] horizon.WARNING: Job App\\Jobs\\ReindexRelease exceeded 120s',
        '2026-10-06 18:11:32 Horizon started successfully.',
        '',
        '[2026-10-06 18:11:33] queue.DEBUG: nntp 430 No such article',
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW445RustFfi();
        }
        (new ReflectionClass(ReleaseSearchIndexDocument::class))->setStaticPropertyValue('w445Ffi', null);

        parent::tearDown();
    }

    public function test_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW445RustFfi', 'usesW445RustFfi', 'resetW445RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), "{$class}::{$method}");
            }
            $default = (new ReflectionClassConstant($class, 'W445_RUST_FFI_DEFAULT'))->getValue();
            $this->assertSame(self::DEFAULTS[$class], $default, "{$class} default must match the benchmark evidence");
            $this->assertSame($default, $class::usesW445RustFfi(), "{$class} must start at its default");
            $class::useW445RustFfi(true);
            $this->assertTrue($class::usesW445RustFfi());
            $class::useW445RustFfi(null);
            $this->assertSame($default, $class::usesW445RustFfi(), "{$class}: null restores the default");
            $class::useW445RustFfi(false);
            $this->assertFalse($class::usesW445RustFfi());
            $class::useW445RustFfi();
            $this->assertTrue($class::usesW445RustFfi());
            $class::resetW445RustFfi();
            $this->assertSame($default, $class::usesW445RustFfi(), "{$class}: reset restores the default");
        }

        $this->assertTrue((new ReflectionClass(SearchQuery::class))->isReadOnly());
        $this->assertSame([], (new ReflectionClass(SearchQuery::class))->getStaticProperties());

        // Toggles are independent of each other.
        foreach (self::CLASSES as $class) {
            $class::useW445RustFfi(true);
            foreach (self::CLASSES as $other) {
                if ($other !== $class) {
                    $this->assertSame($this->defaultOf($other), $other::usesW445RustFfi(), "{$class} leaked into {$other}");
                }
            }
            $class::resetW445RustFfi();
        }
    }

    public function test_match_expression_batch_examples(): void
    {
        $summary = $this->both(LogIndexQuery::class, static fn () => LogIndexQuery::summarizeMatchExpressionBatch(['Jobs\\ReindexRel', 'ab', 'error', 'error', 5, '', 'ab cdef', ' error ']));
        $this->assertSame(['terms' => 8, 'invalid' => 1, 'supported' => 5, 'phrases' => 1, 'tokens' => 6, 'dropped' => 2, 'prefix_wildcards' => 3, 'suffix_wildcards' => 4, 'max_tokens' => 2, 'distinct_expressions' => 4], $summary);

        // Non-ASCII terms decline the Rust batch and run in PHP with Unicode classes.
        $unicode = ['café au lait', "\xff\xfe", 'Ünïcödé'];
        $this->assertSame(LogIndexQuery::summarizeMatchExpressionBatchPhp($unicode), LogIndexQuery::summarizeMatchExpressionBatchNative($unicode));
        $this->assertSame(['terms' => 0, 'invalid' => 0, 'supported' => 0, 'phrases' => 0, 'tokens' => 0, 'dropped' => 0, 'prefix_wildcards' => 0, 'suffix_wildcards' => 0, 'max_tokens' => 0, 'distinct_expressions' => 0], $this->both(LogIndexQuery::class, static fn () => LogIndexQuery::summarizeMatchExpressionBatch([])));
    }

    public function test_log_line_batch_examples(): void
    {
        $parser = new LogEntryParser;
        $lines = [...self::LOG_PAGE, '[2026-10-06 18:11:29] queue.TRACE: x', "  \t", null, '[2026-10-06 18:11:29]local.ERROR: no space'];
        $summary = $this->both(LogEntryParser::class, static fn () => $parser->summarizeLogLineBatch($lines));
        $this->assertSame([
            'lines' => 12, 'invalid' => 1, 'headers' => 5, 'debug' => 1, 'info' => 1, 'notice' => 0, 'warning' => 1, 'error' => 1,
            'critical' => 0, 'alert' => 0, 'emergency' => 0, 'unknown_level' => 1, 'plain_timestamps' => 2, 'blank' => 2,
            'distinct_channels' => 3, 'max_message_bytes' => 116,
        ], $summary);
    }

    public function test_line_match_batch_examples(): void
    {
        $lines = ['AAAAa', 'b', 1, str_repeat('a', 500)];
        $insensitive = new SearchQuery('aa');
        $this->assertSame(
            ['lines' => 4, 'invalid' => 1, 'matched' => 2, 'highlights' => 202, 'highlighted_bytes' => 404, 'max_highlights' => 200, 'capped_lines' => 1],
            $this->both(SearchQuery::class, static fn () => $insensitive->summarizeLineMatchBatch($lines)),
        );
        $sensitive = new SearchQuery('A', caseSensitive: true);
        $this->assertSame(4, $this->both(SearchQuery::class, static fn () => $sensitive->summarizeLineMatchBatch($lines))['highlights']);

        // Regex and level-only queries stay in PHP.
        foreach ([new SearchQuery('a+', regex: true), new SearchQuery('', levels: ['error'])] as $query) {
            $this->assertSame($query->summarizeLineMatchBatchPhp([...self::LOG_PAGE, 'aaa']), $query->summarizeLineMatchBatchNative([...self::LOG_PAGE, 'aaa']));
        }
    }

    public function test_escape_batch_examples(): void
    {
        $formatter = new TextFormatter;
        $summary = $this->both(TextFormatter::class, static fn () => $formatter->summarizeEscapeBatch(["a\\b\"c\nd", 'plain', 'plain', 7, '"']));
        $this->assertSame(['values' => 5, 'invalid' => 1, 'label_escaped' => 2, 'help_escaped' => 1, 'backslashes' => 1, 'quotes' => 2, 'newlines' => 1, 'input_bytes' => 18, 'label_bytes' => 22, 'help_bytes' => 20, 'distinct_values' => 3], $summary);
    }

    public function test_plain_search_name_batch_examples(): void
    {
        $rows = [
            ['searchname' => 'Show.Name_S01-E01.720p'],
            ['searchname' => 'Already Plain', 'plainsearchname' => 'x'],
            ['searchname' => '._-'],
            ['searchname' => 1.5],
            ['searchname' => [1]],
            ['plainsearchname' => null],
            'not a row',
        ];
        $summary = $this->both(ReleaseSearchIndexDocument::class, static fn () => ReleaseSearchIndexDocument::summarizePlainSearchNameBatch($rows));
        $this->assertSame(['rows' => 7, 'invalid' => 2, 'explicit_plain' => 1, 'derived' => 4, 'changed' => 3, 'empty' => 2, 'separator_runs' => 6, 'plain_bytes' => 25, 'max_plain_bytes' => 22, 'distinct_plain' => 3], $summary);

        $plain = (new ReflectionClass(ReleaseSearchIndexDocument::class))->getMethod('plainSearchName');
        $this->assertSame('Show Name S01 E01 720p', $plain->invoke(null, 'Show.Name_S01-E01.720p'));
        $this->assertSame('Show Name S01 E01 720p', ReleaseSearchIndexDocument::normalize(['searchname' => 'Show.Name_S01-E01.720p'])['plainsearchname']);
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame([1, 0, 1, 0, 1, 1, 1, 1], ReleaseSearchIndexDocument::evaluateW445Native(1, ['3', 'abc'], 8));
        $this->assertSame([1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 1, 1], ReleaseSearchIndexDocument::evaluateW445Native(2, ['[2026-10-06 18:11:29] a.ERROR: x'], 14));
        $this->assertSame([1, 2, 2, 2, 1], ReleaseSearchIndexDocument::evaluateW445Native(3, ['a', '0', '2', 'AaA'], 5));
        $this->assertSame([1, 1, 1, 0, 0, 2, 3, 3, 1], ReleaseSearchIndexDocument::evaluateW445Native(4, ['a\\'], 9));
        $this->assertSame([1, 0, 1, 3, 3, 1], ReleaseSearchIndexDocument::evaluateW445Native(5, ['a.b'], 6));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0], ReleaseSearchIndexDocument::evaluateW445Native(4, [], 9));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(0, ['x'], 9));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(6, ['x'], 9));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(4, ['x'], 8));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(4, ['x'], 33));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(1, ['03', 'abc'], 8));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(1, ['3', "caf\xc3\xa9"], 8));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(1, [], 8));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(3, ['', '1', '2', 'x'], 5));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(3, ['a', '2', '2', 'x'], 5));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(3, ['a', '1', '0', 'x'], 5));
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(3, ['a', '1'], 5));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(ReleaseSearchIndexDocument::class))->setStaticPropertyValue('w445Ffi', false);
        $this->assertNull(ReleaseSearchIndexDocument::evaluateW445Native(4, ['x'], 9));

        $this->assertSame(LogIndexQuery::summarizeMatchExpressionBatchPhp(['error', 1]), LogIndexQuery::summarizeMatchExpressionBatchNative(['error', 1]));
        $parser = new LogEntryParser;
        $this->assertSame($parser->summarizeLogLineBatchPhp([...self::LOG_PAGE, 1]), $parser->summarizeLogLineBatchNative([...self::LOG_PAGE, 1]));
        $query = new SearchQuery('error');
        $this->assertSame($query->summarizeLineMatchBatchPhp([...self::LOG_PAGE, 1]), $query->summarizeLineMatchBatchNative([...self::LOG_PAGE, 1]));
        $formatter = new TextFormatter;
        $this->assertSame($formatter->summarizeEscapeBatchPhp(['a"b', 1]), $formatter->summarizeEscapeBatchNative(['a"b', 1]));
        $rows = [['searchname' => 'a.b'], 1];
        $this->assertSame(ReleaseSearchIndexDocument::summarizePlainSearchNameBatchPhp($rows), ReleaseSearchIndexDocument::summarizePlainSearchNameBatchNative($rows));
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();
        $small = $ffi->new('int64_t[4]');
        $big = $ffi->new('int64_t[32]');
        $call = static function (int $mode, string $input, mixed $out, int $cap) use ($ffi): int {
            // @phpstan-ignore method.notFound
            return $ffi->nntmux_w445_log_index_parser_search_prometheus_doc($mode, $input, \strlen($input), $out, $cap);
        };

        $packet = self::packet(['a"b', 'c']);
        $this->assertSame(9, $call(4, $packet, $big, 32));
        $this->assertSame(-9, $call(4, $packet, $small, 4));
        $this->assertSame(0, $small[0]);
        $this->assertSame(-9, $call(4, $packet, null, 0));
        $this->assertSame(-1, $call(4, $packet, null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $packet, $big, 32));
        }
        $this->assertSame(-1, $call(4, '', $big, 32));
        $this->assertSame(-1, $call(4, "\x01\x00\x00", $big, 32));
        $this->assertSame(-1, $call(4, "\x01\x00\x00\x00\x09\x00\x00\x00", $big, 32));
        $this->assertSame(-1, $call(4, $packet."\x00", $big, 32));
        $this->assertSame(-1, $call(4, "\xff\xff\xff\xff", $big, 32));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();
        mt_srand(4405);
        $parser = new LogEntryParser;
        $formatter = new TextFormatter;
        $pick = static fn (array $items): mixed => $items[mt_rand(0, \count($items) - 1)];
        $termAlphabet = ['a', 'B', 'zz', 'Rel', '0', '9', '_', ' ', '\\', '.', '-', '[', ']', '"', '*', "\t", 'x1', 'Jobs'];
        $lineAlphabet = ['a', 'A', 'aa', 'b', 'B', ' ', 'rel', 'REL', "\0", "\xff", "\n"];

        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $terms = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $term = '';
                for ($j = mt_rand(0, 8); $j > 0; $j--) {
                    $term .= $pick($termAlphabet);
                }
                $terms[] = mt_rand(0, 15) === 0 ? $pick([7, null, ['x'], "caf\xc3\xa9", "\xff"]) : $term;
            }
            $this->assertParity(LogIndexQuery::class, static fn () => LogIndexQuery::summarizeMatchExpressionBatch($terms), $terms);
            $asciiTerms = array_values(array_filter($terms, static fn (mixed $term): bool => \is_string($term) && mb_check_encoding($term, 'ASCII')));
            $this->assertNotNull(ReleaseSearchIndexDocument::evaluateW445Native(1, ['3', ...$asciiTerms], 8));

            $lines = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $lines[] = mt_rand(0, 15) === 0 ? $pick([1, null, 2.5]) : self::randomLogLine();
            }
            $this->assertParity(LogEntryParser::class, static fn () => $parser->summarizeLogLineBatch($lines), $lines);

            $term = '';
            for ($j = mt_rand(1, 3); $j > 0; $j--) {
                $term .= $pick($lineAlphabet);
            }
            $query = new SearchQuery($term, caseSensitive: mt_rand(0, 1) === 1);
            $haystacks = [];
            for ($i = mt_rand(0, 5); $i > 0; $i--) {
                $haystack = '';
                for ($j = mt_rand(0, mt_rand(0, 1) === 1 ? 20 : 400); $j > 0; $j--) {
                    $haystack .= $pick($lineAlphabet);
                }
                $haystacks[] = mt_rand(0, 15) === 0 ? 42 : $haystack;
            }
            $this->assertParity(SearchQuery::class, static fn () => $query->summarizeLineMatchBatch($haystacks), [$term, $query->caseSensitive, $haystacks]);

            $values = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $values[] = mt_rand(0, 15) === 0 ? $pick([1, null, false]) : self::randomBytes(12);
            }
            $this->assertParity(TextFormatter::class, static fn () => $formatter->summarizeEscapeBatch($values), $values);

            $rows = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $rows[] = match (mt_rand(0, 9)) {
                    0 => $pick(['row', 3, null]),
                    1 => ['searchname' => self::randomBytes(10), 'plainsearchname' => $pick([null, 'set', ''])],
                    2 => ['searchname' => $pick([12, 1.25, true, false, null, ['x'], new stdClass])],
                    3 => [],
                    default => ['searchname' => self::randomBytes(16)],
                };
            }
            $this->assertParity(ReleaseSearchIndexDocument::class, static fn () => ReleaseSearchIndexDocument::summarizePlainSearchNameBatch($rows), $rows);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();
        mt_srand(4451);
        $strict = getenv('NNTMUX_W445_BENCH') === '1';
        $samples = $strict ? 1000 : 300;

        $parser = new LogEntryParser;
        $formatter = new TextFormatter;
        $commonTerms = ['ReindexRelease', 'SQLSTATE[HY000]', 'Jobs\\ProcessRelease', 'timeout', 'nntp 430', 'gone away', 'App\\Jobs', 'error', 'Horizon', 'x264'];
        $commonLines = [];
        for ($i = 0; $i < 100; $i++) {
            $commonLines[] = self::LOG_PAGE[$i % \count(self::LOG_PAGE)];
        }
        $commonQuery = new SearchQuery('release');
        $commonValues = ['default', 'releases', 'nntp-primary', 'scheduler', 'indexer', 'horizon', 'web', 'redis', 'manticore', 'mariadb', 'api/v1', 'GET', '200', 'ok', 'partial "retry"'];
        $commonRows = [];
        for ($i = 0; $i < 100; $i++) {
            $commonRows[] = ['id' => $i, 'searchname' => 'Show.Name.S01E'.sprintf('%02d', $i % 24).'.1080p.WEB-DL.DDP5.1.H.264-GROUP', 'postdate' => '2026-10-06 18:11:29'];
        }

        $levels = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', 'TRACE'];
        $bigTerms = [];
        $bigLines = [];
        $bigHaystacks = [];
        $bigValues = [];
        $bigRows = [];
        for ($i = 0; $i < 5000; $i++) {
            $bigTerms[] = $i % 7 === 0 ? 'ab' : implode($i % 3 === 0 ? '\\' : ' ', array_fill(0, 1 + $i % 6, 'Token'.($i % 97)));
            $bigLines[] = match ($i % 5) {
                0, 1 => sprintf('[2026-10-%02d %02d:%02d:%02d.%06d+02:00] channel%d.%s: %s', 1 + $i % 28, $i % 24, $i % 60, $i % 60, $i, $i % 40, $levels[$i % 9], str_repeat('message ', 1 + $i % 60)),
                2 => '#'.($i % 50).' /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php('.$i.'): handle()',
                3 => sprintf('  [2026-10-06 18:%02d:%02d] console output %d', $i % 60, $i % 60, $i),
                default => $i % 10 === 4 ? '' : str_repeat(' ', $i % 4),
            };
            if ($i < 1000) {
                $bigHaystacks[] = str_repeat('Release processed; ', 20 + $i % 200).($i % 3 === 0 ? 'RELEASE' : '');
            }
            $bigValues[] = $i % 4 === 0 ? "path\\to\\\"queue\"\nline{$i}" : 'label_value_'.($i % 500);
            $bigRows[] = $i % 9 === 0
                ? ['searchname' => 'x', 'plainsearchname' => 'x']
                : ['searchname' => str_repeat('Long.Release_Name-', 1 + $i % 8).'S01E'.($i % 99).'..1080p', 'size' => $i];
        }
        $bigQuery = new SearchQuery('RELEASE', caseSensitive: true);

        $workloads = [
            LogIndexQuery::class => [
                static fn () => LogIndexQuery::summarizeMatchExpressionBatch($commonTerms),
                static fn () => LogIndexQuery::summarizeMatchExpressionBatch($bigTerms),
            ],
            LogEntryParser::class => [
                static fn () => $parser->summarizeLogLineBatch($commonLines),
                static fn () => $parser->summarizeLogLineBatch($bigLines),
            ],
            SearchQuery::class => [
                static fn () => $commonQuery->summarizeLineMatchBatch($commonLines),
                static fn () => $bigQuery->summarizeLineMatchBatch($bigHaystacks),
            ],
            TextFormatter::class => [
                static fn () => $formatter->summarizeEscapeBatch($commonValues),
                static fn () => $formatter->summarizeEscapeBatch($bigValues),
            ],
            ReleaseSearchIndexDocument::class => [
                static fn () => ReleaseSearchIndexDocument::summarizePlainSearchNameBatch($commonRows),
                static fn () => ReleaseSearchIndexDocument::summarizePlainSearchNameBatch($bigRows),
            ],
        ];

        foreach ($workloads as $class => [$common, $uncommon]) {
            $class::useW445RustFfi(false);
            $expected = [$common(), $uncommon()];
            $class::useW445RustFfi(true);
            $this->assertSame($expected, [$common(), $uncommon()], "{$class} benchmark workloads must agree");

            $results = [];
            foreach (['common' => [$common, 20], 'uncommon' => [$uncommon, 1]] as $name => [$workload, $calls]) {
                for ($w = 0; $w < 20; $w++) {
                    foreach ([false, true] as $rust) {
                        $class::useW445RustFfi($rust);
                        $workload();
                    }
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $rust) {
                        $class::useW445RustFfi($rust);
                        $t0 = hrtime(true);
                        for ($i = 0; $i < $calls; $i++) {
                            $workload();
                        }
                        $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
                    }
                }
                $class::resetW445RustFfi();

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, sprintf(
                "\n[Wave 44.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $this->assertSame(self::DEFAULTS[$class], $class::usesW445RustFfi());
            if (! self::DEFAULTS[$class]) {
                continue;
            }
            $ratios = [$results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']];
            if ($strict) {
                $this->assertGreaterThanOrEqual(1.5, min($ratios), "{$class} defaults to Rust without clearing every 1.5x mean/p99 gate");
            } else {
                $this->assertGreaterThanOrEqual(1.0, min($results['common']['mean'], $results['uncommon']['mean']), "{$class} defaults to Rust but Rust is slower than PHP");
            }
        }
        fwrite(STDERR, "\n");
    }

    /**
     * Runs $plan with PHP then Rust, asserts identical results and returns the PHP result.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): T  $plan
     * @return T
     */
    private function both(string $class, callable $plan): mixed
    {
        $class::useW445RustFfi(false);
        $php = $plan();
        $class::useW445RustFfi(true);
        $rust = $plan();
        $class::resetW445RustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW445RustFfi(false);
        $php = $plan();
        $class::useW445RustFfi(true);
        $rust = $plan();
        $class::resetW445RustFfi();
        $this->assertSame($php, $rust, bin2hex(serialize(array_map(static fn (mixed $arg): mixed => \is_object($arg) ? $arg::class : $arg, $args))));
    }

    /**
     * @param  class-string  $class
     */
    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClassConstant($class, 'W445_RUST_FFI_DEFAULT'))->getValue();
    }

    /**
     * A Monolog-like line with random corruption of each header component.
     */
    private static function randomLogLine(): string
    {
        $pick = static fn (array $items): string => $items[mt_rand(0, \count($items) - 1)];
        if (mt_rand(0, 5) === 0) {
            return $pick(['', ' ', "\t\0", '#0 trace', ' 2026-10-06 18:11:29 x', '[2026-10-06T18:11:29', '[[2026-10-06 18:11:29', "\x0c2026-10-06 18:11:2"]).self::randomBytes(6);
        }

        return $pick(['[', '[', '[', '', ' ['])
            .$pick(['2026', '2026', '202x', '20261'])
            .'-10-'.$pick(['06', '06', '6'])
            .$pick([' ', 'T', ' ', '_'])
            .'18:11:'.$pick(['29', '29', '2'])
            .$pick(['', '', '.123456', '.', '.9'])
            .$pick(['', '', '+02:00', '-0530', 'Z', '+02:0', '+2:00', '+02:', 'z'])
            .$pick([']', ']', ']', ''])
            .$pick([' ', ' ', "\t ", "\x0b", "\x0c", '', "\n", "\xa0"])
            .$pick(['local', 'a.b-c', 'production', '', '.', 'q_1', 'x y', 'ch.'])
            .$pick(['.', '.', '.', '..', ''])
            .$pick(['ERROR', 'DEBUG', 'INFO', 'NOTICE', 'WARNING', 'CRITICAL', 'ALERT', 'EMERGENCY', 'TRACE', 'Error', '', 'ERROR.X'])
            .$pick([':', ':', ':', '', ': '])
            .$pick([' ', '', '  ', "\n", "\r"])
            .self::randomBytes(12);
    }

    private static function randomBytes(int $maxLength): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $out .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
        }

        return $out;
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        return pack('V*', \count($parts), ...array_map(\strlen(...), $parts)).implode('', $parts);
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w445', ReleaseSearchIndexDocument::W445_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
