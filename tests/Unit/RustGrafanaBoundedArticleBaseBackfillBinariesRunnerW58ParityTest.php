<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Monitoring\GrafanaEmbedService;
use App\Services\NNTP\BoundedArticleResult;
use App\Services\Runners\BackfillRunner;
use App\Services\Runners\BaseRunner;
use App\Services\Runners\BinariesRunner;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustGrafanaBoundedArticleBaseBackfillBinariesRunnerW58ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        GrafanaEmbedService::class => 'embedUrlBatch',
        BoundedArticleResult::class => 'articleSummaryBatch',
        BaseRunner::class => 'dispatchPlanBatch',
        BackfillRunner::class => 'backfillWorkBatch',
        BinariesRunner::class => 'binariesWorkBatch',
    ];

    /** Output words per row. */
    private const WORDS_PER_ROW = [
        GrafanaEmbedService::class => 3,
        BoundedArticleResult::class => 3,
        BaseRunner::class => 4,
        BackfillRunner::class => 4,
        BinariesRunner::class => 4,
    ];

    /** Class-distinct CDEF constant => export it declares. */
    private const CDEFS = [
        GrafanaEmbedService::class => ['W58_GRAFANA_EMBED_FFI_CDEF', 'nntmux_w581_grafana_embed_batch'],
        BoundedArticleResult::class => ['W58_BOUNDED_ARTICLE_FFI_CDEF', 'nntmux_w581_bounded_article_batch'],
        BaseRunner::class => ['W58_BASE_RUNNER_FFI_CDEF', 'nntmux_w581_runner_dispatch_batch'],
        BackfillRunner::class => ['W58_BACKFILL_RUNNER_FFI_CDEF', 'nntmux_w581_backfill_work_batch'],
        BinariesRunner::class => ['W58_BINARIES_RUNNER_FFI_CDEF', 'nntmux_w581_binaries_work_batch'],
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns) from
     * test_paired_randomized_common_and_uncommon_benchmarks (NNTMUX_W581_BENCH=1): seed 58,
     * ROUNDS interleaved pairs per workload after WARMUPS, ROWS rows per batch, randomized call
     * order, each side the fastest of REPEATS back-to-back calls, release Rust, PHP 8.5 CLI
     * without opcache, Apple Silicon macOS. Timings include dispatch, implode(), the FFI call and
     * unpack('q*'). Per-metric minimum of four runs. A class defaults to Rust only when
     * all four reach 1.5x.
     */
    private const QUALIFICATION = [
        GrafanaEmbedService::class => [3.933, 3.272, 3.85, 3.0],
        BoundedArticleResult::class => [3.321, 2.653, 2.923, 2.284],
        BaseRunner::class => [3.77, 2.681, 2.398, 2.419],
        BackfillRunner::class => [4.786, 4.453, 3.868, 2.558],
        BinariesRunner::class => [3.804, 3.077, 3.35, 2.812],
    ];

    private const ROUNDS = 60;

    private const WARMUPS = 8;

    private const ROWS = 320;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 58;

    private const WORDS = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet', 'kilo', 'lima', 'mike', 'november'];

    private const GROUPS = ['alt.binaries.teevee', 'alt.binaries.movies', 'alt.binaries.boneless', 'alt.binaries.multimedia', 'alt.binaries.hdtv.x264', 'a.b.erotica', 'alt.binaries.e-book', 'alt.binaries.sounds.mp3+flac'];

    private const ERRORS = ['430 No Such Article', '423 No article with that number', '420 No current article', 'Read timed out after 30s', 'Connection reset by peer', 'Article body exceeds 4194304 byte limit',
        'yEnc CRC mismatch', '480 Authentication required', '500 Command not recognized', 'socket closed: EOF', 'unexpected response', 'Body too large', 'Unable to decode part'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW58RustFfi', 'withW58RustFfi', 'w58RustFfiState', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW58RustFfi', 'withW58RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            foreach (['usesW58RustFfi', 'withW58RustFfi', 'w58RustFfiState'] as $method) {
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.' declares its own '.$method);
            }
            $state = $reflection->getMethod('w58RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            $constant = $reflection->getReflectionConstant('W58_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPublic(), $class);
            self::assertSame($class, $constant->getDeclaringClass()->getName(), $class.' declares its own default');
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            [$cdef, $symbol] = self::CDEFS[$class];
            self::assertSame($class, $reflection->getReflectionConstant($cdef) !== false ? $reflection->getReflectionConstant($cdef)->getDeclaringClass()->getName() : null, $class.'::'.$cdef);
            self::assertStringContainsString($symbol, (string) $reflection->getConstant($cdef));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w58', $property->getName(), $class.' keeps Wave 58 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
            if (min($speedups) < 1.5) {
                self::assertFalse($this->uses($class), $class.' retains PHP by default');
            }
        }
        self::assertTrue((new ReflectionClass(BoundedArticleResult::class))->isReadOnly());
        self::assertSame(BaseRunner::class, (new ReflectionMethod(BackfillRunner::class, 'usesBaseRunnerW58RustFfi'))->getDeclaringClass()->getName());
        self::assertSame(BaseRunner::usesW58RustFfi(), BaseRunner::usesBaseRunnerW58RustFfi());
    }

    public function test_with_w58_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW58RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW58RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW58RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
    }

    public function test_parent_and_child_runner_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [
            BaseRunner::usesW58RustFfi(),
            BaseRunner::usesBaseRunnerW58RustFfi(),
            BackfillRunner::usesW58RustFfi(),
            BackfillRunner::usesBaseRunnerW58RustFfi(),
            BinariesRunner::usesW58RustFfi(),
            BinariesRunner::usesBaseRunnerW58RustFfi(),
        ];
        [$base, , $backfill, , $binaries] = $snapshot();
        foreach ([true, false] as $enabled) {
            self::assertSame([$enabled, $enabled, $backfill, $enabled, $binaries, $enabled], BaseRunner::withW58RustFfi($enabled, $snapshot));
            self::assertSame([$enabled, $enabled, $backfill, $enabled, $binaries, $enabled], BaseRunner::withBaseRunnerW58RustFfi($enabled, $snapshot));
            self::assertSame([$base, $base, $enabled, $base, $binaries, $base], BackfillRunner::withW58RustFfi($enabled, $snapshot));
            self::assertSame([$base, $base, $backfill, $base, $enabled, $base], BinariesRunner::withW58RustFfi($enabled, $snapshot));
            self::assertSame([$enabled, $enabled, ! $enabled, $enabled, $binaries, $enabled], BackfillRunner::withBaseRunnerW58RustFfi(
                $enabled,
                static fn (): array => BackfillRunner::withW58RustFfi(! $enabled, $snapshot),
            ));
        }
        self::assertSame([$base, $base, $backfill, $base, $binaries, $base], $snapshot());

        // The inherited dispatch batch follows BaseRunner's routing, never a subclass toggle.
        $rows = ["get_range\talt.test\t10\t5\t2", "releases\t\t\t3\t1"];
        $php = BaseRunner::dispatchPlanBatchPhp($rows);
        foreach ([true, false] as $enabled) {
            self::assertSame($php, BackfillRunner::withW58RustFfi($enabled, static fn (): array => BackfillRunner::dispatchPlanBatch($rows)));
            self::assertSame($php, BinariesRunner::withBaseRunnerW58RustFfi($enabled, static fn (): array => BinariesRunner::dispatchPlanBatch($rows)));
        }
    }

    public function test_toggle_routes_entry_point(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $rows = $this->fixture($class, new Randomizer(new Mt19937(self::SEED + 2)), false, 12);
            $call = $this->entry($class);
            $php = $class::{self::ENTRIES[$class].'Php'}($rows);
            self::assertSame($php, $class::withW58RustFfi(true, static fn (): mixed => $call($rows)), $class);
            self::assertSame($php, $class::withW58RustFfi(false, static fn (): mixed => $call($rows)), $class);
        }
    }

    public function test_earlier_toggles_are_untouched(): void
    {
        self::assertFalse(GrafanaEmbedService::$useRustFfi);
        self::assertFalse(BaseRunner::usesRustFfi());
        self::assertFalse(BackfillRunner::usesRustFfi());
        self::assertFalse(BinariesRunner::usesRustFfi());
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: list<int>}>
     */
    public static function knownCodes(): iterable
    {
        $url = '/grafana/d/nntmux-ops/ops?orgId=1&from=now-6h&to=now&refresh=1m&kiosk';
        $valid = GrafanaEmbedService::W58_EMBED_UID_VALID | GrafanaEmbedService::W58_EMBED_SLUG_VALID | GrafanaEmbedService::W58_EMBED_ORG_VALID
            | GrafanaEmbedService::W58_EMBED_PANEL_VALID | GrafanaEmbedService::W58_EMBED_RANGE_VALID | GrafanaEmbedService::W58_EMBED_REFRESH_VALID;
        yield 'embed dashboard' => [GrafanaEmbedService::class, "/grafana/\tnntmux-ops\tops\t1\tnow-6h\t1m\t0",
            [GrafanaEmbedService::W58_EMBED_DASHBOARD | $valid | GrafanaEmbedService::W58_EMBED_TRAILING_SLASH | \strlen($url) << GrafanaEmbedService::W58_EMBED_LENGTH_SHIFT, crc32($url), 37]];
        $panel = 'https://g.example/d-solo/a%20b/X?orgId=0&from=now%2B1&to=now&refresh=&panelId=7';
        yield 'embed panel encoded' => [GrafanaEmbedService::class, "https://g.example\ta b\tX\tx\tnow+1\t\t7",
            [GrafanaEmbedService::W58_EMBED_PANEL | GrafanaEmbedService::W58_EMBED_PANEL_VALID | GrafanaEmbedService::W58_EMBED_REFRESH_VALID | GrafanaEmbedService::W58_EMBED_ABSOLUTE
                | \strlen($panel) << GrafanaEmbedService::W58_EMBED_LENGTH_SHIFT, crc32($panel), \strlen($panel) - (int) strpos($panel, '?') - 1]];
        yield 'embed short row' => [GrafanaEmbedService::class, "a\tb", [0, 0, 0]];

        $class = static fn (int $error): int => $error << BoundedArticleResult::W58_ARTICLE_ERROR_SHIFT;
        yield 'article success exceeds' => [BoundedArticleResult::class, "100\t50\t0\t1", [BoundedArticleResult::W58_ARTICLE_SUCCESS | BoundedArticleResult::W58_ARTICLE_IS_SUCCESS | BoundedArticleResult::W58_ARTICLE_METADATA | BoundedArticleResult::W58_ARTICLE_EXCEEDS, 100, 0]];
        yield 'article missing' => [BoundedArticleResult::class, "-\t50\t1\t0\t430 No Such Article", [BoundedArticleResult::W58_ARTICLE_OVERSIZED | $class(BoundedArticleResult::W58_ERROR_MISSING), -1, 430]];
        yield 'article truncated timeout' => [BoundedArticleResult::class, "10\t50\t1\t0\tRead TIMEOUT", [BoundedArticleResult::W58_ARTICLE_OVERSIZED | BoundedArticleResult::W58_ARTICLE_TRUNCATED | $class(BoundedArticleResult::W58_ERROR_TIMEOUT), 10, 0]];
        yield 'article empty error' => [BoundedArticleResult::class, "-\t0\t0\t0\t", [$class(BoundedArticleResult::W58_ERROR_EMPTY), -1, 0]];
        yield 'article auth status' => [BoundedArticleResult::class, "-\t0\t0\t0\t481\tbad", [$class(BoundedArticleResult::W58_ERROR_AUTH), -1, 481]];
        yield 'article malformed' => [BoundedArticleResult::class, "-1\t0\t0\t0", [BoundedArticleResult::W58_ARTICLE_MALFORMED, -1, 0]];

        $dispatch = BaseRunner::W58_DISPATCH_GROUP_VALID | BaseRunner::W58_DISPATCH_GROUP_NORMALIZED | BaseRunner::W58_DISPATCH_COMMAND_NORMALIZED
            | BaseRunner::W58_DISPATCH_LIMIT_CLAMPED | BaseRunner::W58_DISPATCH_NEEDS_GROUP | BaseRunner::W58_DISPATCH_DISPATCHABLE;
        yield 'dispatch normalized' => [BaseRunner::class, " Get_Range \talt.binaries.TEST\t500000\t10\t3", [4 | $dispatch | 17 << BaseRunner::W58_DISPATCH_GROUP_SHIFT, 100000, 3, 4]];
        yield 'dispatch defaults' => [BaseRunner::class, "releases\t\tx\t0\t99", [6 | BaseRunner::W58_DISPATCH_LIMIT_DEFAULTED | BaseRunner::W58_DISPATCH_PROCESSES_CLAMPED, 100, 0, 0]];
        yield 'dispatch short row' => [BaseRunner::class, 'bogus', [0, 0, 0, 0]];

        $backfill = BackfillRunner::W58_BACKFILL_NEEDED | BackfillRunner::W58_BACKFILL_COMPRESSED | BackfillRunner::W58_BACKFILL_GROUP_VALID;
        yield 'backfill needed' => [BackfillRunner::class, "alt.test\t100000\t40000\t10000\t4\t20000\t10\t30\t1", [$backfill | 4 << BackfillRunner::W58_BACKFILL_THREADS_SHIFT, 2, 60000, 20_060_000]];
        yield 'backfill reached' => [BackfillRunner::class, "g\t1\t5\t1\t99\t0\t40\t30\t0", [BackfillRunner::W58_BACKFILL_TARGET_REACHED | BackfillRunner::W58_BACKFILL_THREADS_CLAMPED | BackfillRunner::W58_BACKFILL_MESSAGES_ADJUSTED | BackfillRunner::W58_BACKFILL_GROUP_VALID | 32 << BackfillRunner::W58_BACKFILL_THREADS_SHIFT, 0, 1, 0]];
        yield 'backfill malformed' => [BackfillRunner::class, "g\t1", [BackfillRunner::W58_BACKFILL_MALFORMED, 0, 0, 0]];

        $binaries = BinariesRunner::W58_BINARIES_ACTIVE | BinariesRunner::W58_BINARIES_HEADERS_ADJUSTED | BinariesRunner::W58_BINARIES_BEHIND | BinariesRunner::W58_BINARIES_GROUP_VALID;
        yield 'binaries ranges' => [BinariesRunner::class, "7\talt.test\t1\t1000\t200000\t0\t20000", [BinariesRunner::W58_BINARIES_RANGES | $binaries | 9 << BinariesRunner::W58_BINARIES_RANGES_SHIFT, 9, 180000, 199000]];
        yield 'binaries first run' => [BinariesRunner::class, "7\talt.test\t1\t0\t200000\t0\t20000", [BinariesRunner::W58_BINARIES_UPDATE_HEADERS | $binaries | 1 << BinariesRunner::W58_BINARIES_RANGES_SHIFT, 1, 0, 200000]];
        yield 'binaries inactive' => [BinariesRunner::class, "7\talt.test\t0\t10\t5\t10\t10", [BinariesRunner::W58_BINARIES_INACTIVE | BinariesRunner::W58_BINARIES_GROUP_VALID, 0, 10, 0]];
        yield 'binaries malformed' => [BinariesRunner::class, "x\tg\t1\t1\t1\t1\t1", [BinariesRunner::W58_BINARIES_MALFORMED, 0, 0, 0]];
    }

    /**
     * @param  class-string  $class
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCodes')]
    public function test_known_codes(string $class, string $row, array $expected): void
    {
        self::assertSame($expected, $this->assertParity($class, [$row]));
    }

    public function test_batch_words_agree_with_existing_instance_methods(): void
    {
        $service = (new ReflectionClass(GrafanaEmbedService::class))->newInstanceWithoutConstructor();
        $dashboard = $service->buildDashboardEmbedUrlPhp('/grafana/', 'abc', 'ops-x', 2, 'now-1h', '30s');
        $panel = $service->buildPanelEmbedUrlPhp('https://g/', 'abc', 'ops-x', 2, 'now-1h', '30s', 9);
        $words = GrafanaEmbedService::embedUrlBatch(["/grafana/\tabc\tops-x\t2\tnow-1h\t30s\t0", "https://g/\tabc\tops-x\t2\tnow-1h\t30s\t9"]);
        self::assertSame([crc32($dashboard), crc32($panel)], [$words[1], $words[4]]);

        $success = BoundedArticleResult::success('body');
        $failure = BoundedArticleResult::failure('430 gone', true);
        $words = BoundedArticleResult::articleSummaryBatch(["4\t3\t0\t0", "-\t3\t1\t0\t430 gone"]);
        self::assertSame(['success', 'oversized'], [$success->outcome(), $failure->outcome()]);
        self::assertSame([BoundedArticleResult::W58_ARTICLE_SUCCESS, BoundedArticleResult::W58_ARTICLE_OVERSIZED], [$words[0] & 3, $words[3] & 3]);
        self::assertSame(BoundedArticleResult::exceedsByteLimit('body', 3), ($words[0] & BoundedArticleResult::W58_ARTICLE_EXCEEDS) !== 0);

        $backfill = (new ReflectionClass(BackfillRunner::class))->newInstanceWithoutConstructor();
        $queues = $backfill->planSafeBackfillQueuesPhp('alt.test', 100000, 40000, 10000, 4, 20000);
        $words = BackfillRunner::backfillWorkBatch(["alt.test\t100000\t40000\t10000\t4\t20000\t0\t0\t0"]);
        self::assertSame(\count($queues), $words[1]);
        self::assertStringContainsString('  '.$words[2].'  ', $queues[\count($queues) - 1]);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<string>}>
     */
    public static function edgeBatches(): iterable
    {
        $shared = ['', ' ', "\t", "\t\t", "\t\t\t\t", "\t\t\t\t\t\t", "\t\t\t\t\t\t\t\t", "\n", "\r\n", "\x0b", "\xff", "caf\u{e9}", '-', '0', '1', '-0', str_repeat('9', 19), str_repeat("\t", 12)];
        $classes = [
            GrafanaEmbedService::class => ["/grafana\tuid\tslug\t1\tnow\t\t0", "/grafana//\tUID_x-1\ta-b-c\t001\tnow-999999y\t9999d\t000", "\t\t\t\t\t\t", "///\t\t\t\t\t\t", "http:/\tu\ts\t1\tnow\t1m\t1",
                "HTTP://x/\tu\ts\t-1\tnow-0s\t0s\t-1", "https:\tu\ts\t1\tnow-1\t1\t1", "https://\tu\t-s\t1\tnow-1234567d\t12345s\t1", "/g\t".str_repeat('u', 40)."\ts--s\t1\t1234567890\t1x\t1", "/g\t".str_repeat('u', 41)."\ts-\t1\t12345678901234\t\t0",
                "/g\tu~v\ts\t1\t123456789\t\t".str_repeat('9', 18), "/g\tu\ts\t".str_repeat('9', 19)."\tnow\t\t1", "/g\t\u{e9}\t\u{e9}\t1\tnow-6h\t1m\t2", "/g\ta&b=c\t?x#y\t1\tfrom now\tr e\t2", "/g\tu\ts\t+1\t+1\t%20\t+1",
                "/g\tu\ts\t1\tnow-6h\t1m\t1\textra", "/g\tu\ts\t1\tNOW\t1M\t1", "/g\tu\ts\t 1\tnow-6M\t1h\t 1", "/g\tu\ts\t1\tnow-6w\t1d\t0 "],
            BoundedArticleResult::class => ["0\t0\t0\t0", "-\t0\t0\t0", "-\t0\t0\t0\t", "5\t4\t1\t1", "5\t5\t1\t1", "5\t-1\t0\t0\t\t", "-\t0\t0\t0\t430", "-\t0\t0\t0\t430x", "-\t0\t0\t0\t43", "-\t0\t0\t0\t4301 x", "-\t0\t0\t0\t999 timeout",
                "-\t0\t0\t0\t482 Timeout", "-\t0\t0\t0\tTIMED OUT\tsocket", "-\t0\t0\t0\tNo Such ARTICLE", "-\t0\t0\t0\tEOF", "-\t0\t0\t0\tlimits", "-\t0\t0\t0\tCRC", "-\t0\t0\t0\tAUTHINFO", "-\t0\t0\t0\tdecoded \u{e9}", "-\t0\t0\t0\tx",
                str_repeat('9', 18)."\t".str_repeat('9', 18)."\t0\t0", str_repeat('9', 19)."\t0\t0\t0", "+5\t0\t0\t0", "5\t+0\t0\t0", "5\t0\t2\t0", "5\t0\t0\t-1", "5\t0\t0", " 5\t0\t0\t0", "-\t-0\t1\t1\ta\tb\tc"],
            BaseRunner::class => ["backfill\talt.test\t1\t1\t1", "BACKFILL_ALL_QUANTITY\tALT.TEST\t-5\t-5\t-5", "\x0b pp_tv \r\t g \t 100001 \t 65 \t 64", "pp_nfo\t\t100000\t1000000000000000000\t0", "part_repair\t-bad\t\t\t",
                "update_group_headers\t".str_repeat('g', 255)."\t0\t7\t2", "update_per_group\t".str_repeat('g', 256)."\t1\t7\t2", "get_range\tg\u{e9}\t1\t1\t1", "backfill_all_quick\t.g\t1\t1\t1", "pp_movie\ta+b_c-d.e\t+5\t+5\t+5", "pp_additional\tx\t5\t3\t".str_repeat('9', 18),
                "releases\tx\t".str_repeat('9', 19)."\t1\t1", "unknown\tx\t1\t1\t1", "get_range\tx\t1\t1", "get_range\tx\t1\t1\t1\t1"],
            BackfillRunner::class => ["g\t0\t0\t0\t0\t0\t0\t0\t0", "g\t1\t0\t1\t1\t1\t0\t0\t1", "g\t-5\t-10\t-1\t-1\t-1\t-1\t-1\t0", "\t5\t1\t1\t1\t1\t1\t2\t1", "g\t".str_repeat('9', 18)."\t-".str_repeat('9', 18)."\t".str_repeat('9', 18)."\t".str_repeat('9', 18)."\t".str_repeat('9', 18)."\t".str_repeat('9', 18)."\t".str_repeat('9', 18)."\t1",
                "g\t5\t1\t1\t1\t1000001\t36501\t40000\t0", "g\t5\t1\t1\t1\t1\t1\t1\t2", "g\t5\t1\t1\t1\t1\t1\t1\t", "g\t5\t1\t1\t1\t1\t1\t+1\t1", "g\t5\t1\t1\t1\t1\t1\t1\t1\t1", "g\u{e9}\t5\t1\t1\t1\t1\t1\t2\t1", "g\t".str_repeat('9', 19)."\t1\t1\t1\t1\t1\t1\t1"],
            BinariesRunner::class => ["1\tg\t1\t0\t0\t0\t0", "1\tg\t1\t1\t20001\t1\t1", "1\tg\t1\t1\t20004\t1\t1", "1\tg\t1\t1\t20004\t1\t2", "1\tg\t1\t-".str_repeat('9', 18)."\t".str_repeat('9', 18)."\t".str_repeat('9', 18)."\t".str_repeat('9', 18),
                "-1\t\t1\t5\t1\t-5\t-5", "1\tg\t2\t1\t1\t1\t1", "1\tg\t1\t1\t1\t1", "1\tg\t1\t1\t1\t1\t1\t1", "1\tg\t0\t-1\t5\t1\t1", "1\tg\t1\t1\t5000000\t1000000001\t1000001", "1\tg\t1\t+1\t1\t1\t1", "1\tg\u{e9}\t1\t1\t1\t1\t1", "1\tg\t1\t100\t300000\t70000\t1"],
        ];
        foreach ($classes as $class => $rows) {
            yield $class.' mixed edges' => [$class, [...$shared, ...$rows]];
            yield $class.' single empty row' => [$class, ['']];
            yield $class.' empty batch' => [$class, []];
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $rows
     */
    #[DataProvider('edgeBatches')]
    public function test_edge_batch_parity(string $class, array $rows): void
    {
        $result = $this->assertParity($class, $rows);
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result), $class);
        self::assertCount(\count($rows) * self::WORDS_PER_ROW[$class], $result, $class);
        $this->assertRustAccepts($class, $rows, $result);
        foreach ($rows as $row) {
            $this->assertParity($class, [$row]);
        }
    }

    public function test_keyed_rows_and_nul_rows_fall_back_to_php(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $rows = $this->fixture($class, new Randomizer(new Mt19937(self::SEED + 3)), false, 4);
            $keyed = ['k' => $rows[0], 7 => $rows[1], 'z' => $rows[2], 'q' => $rows[3]];
            self::assertSame($this->assertParity($class, $rows), $this->assertParity($class, $keyed), $class);
            $nul = [$rows[0], "a\0b", "\0", $rows[1]];
            self::assertCount(4 * self::WORDS_PER_ROW[$class], $this->assertParity($class, $nul), $class);
        }
    }

    /** At least 300 randomized rows per class and workload, checked as batches and one row at a time. */
    public function test_randomized_dataset_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $rows = $this->fixture($class, $random, $uncommon, 320);
                $words = $this->assertParity($class, $rows);
                self::assertIsArray($words);
                self::assertCount(320 * self::WORDS_PER_ROW[$class], $words, $class);
                $this->assertRustAccepts($class, $rows, $words);
                for ($i = 0; $i < 40; $i++) {
                    $this->assertParity($class, [$rows[$i]]);
                }
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W581_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W581_BENCH=1 to run the Wave 58.1 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $call = $this->entry($class);
            foreach (['common', 'uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'uncommon', self::ROWS);
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::ROUNDS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $rows = $fixtures[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW58RustFfi($native, static function () use ($call, $rows): array {
                            $result = null;
                            $best = PHP_INT_MAX;
                            for ($r = 0; $r < self::REPEATS; $r++) {
                                $start = hrtime(true);
                                $result = $call($rows);
                                $best = min($best, hrtime(true) - $start);
                            }

                            return [$result, $best];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0], $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(\count($php) * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / max(1, array_sum($rust)), 3),
                    'p99_speedup' => round($php[$p99] / max(1, $rust[$p99]), 3),
                    'php_mean_ns' => intdiv(array_sum($php), \count($php)), 'rust_mean_ns' => intdiv(array_sum($rust), \count($rust)),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $artifact = sys_get_temp_dir().'/w581-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'warmups' => self::WARMUPS, 'rows' => self::ROWS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW58.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW58RustFfi'];

        return $uses();
    }

    private function entry(string $class): Closure
    {
        /** @var callable $entry */
        $entry = [$class, self::ENTRIES[$class]];

        return Closure::fromCallable($entry);
    }

    /**
     * @param  array<array-key, string>  $rows
     */
    private function assertParity(string $class, array $rows): mixed
    {
        $call = $this->entry($class);
        $php = $class::withW58RustFfi(false, static fn (): mixed => $call($rows));
        $native = $class::withW58RustFfi(true, static fn (): mixed => $call($rows));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}($rows), $class);
        self::assertSame($php, $class::{$method.'Native'}($rows), $class);

        return $php;
    }

    /** @return list<string> */
    private function fixture(string $class, Randomizer $random, bool $uncommon, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = match ($class) {
                GrafanaEmbedService::class => $this->embedRow($random, $uncommon),
                BoundedArticleResult::class => $this->articleRow($random, $uncommon),
                BaseRunner::class => $this->dispatchRow($random, $uncommon),
                BackfillRunner::class => $this->backfillRow($random, $uncommon),
                default => $this->binariesRow($random, $uncommon),
            };
        }

        return $rows;
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::CDEFS as $class => [, $symbol]) {
            $buffer = (new ReflectionMethod($class, 'w58RustFfiState'))->invoke(null, 'out', 4);
            self::assertIsArray($buffer, $class.' must load its Wave 58 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $perRow = self::WORDS_PER_ROW[$class];
            $out = $ffi->new('int64_t[32]');
            $rows = "a\tb\0c";
            self::assertSame(2, $this->invoke($ffi, $symbol, [$rows, 5, 2, $out, 32]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [$rows, 5, 2, $out, 2 * $perRow - 1]), $symbol.' short capacity');
            self::assertSame(-1, $this->invoke($ffi, $symbol, [$rows, 5, 3, $out, 32]), $symbol.' row count mismatch');
            self::assertSame(0, $this->invoke($ffi, $symbol, ['', 0, 0, $out, 32]), $symbol.' empty batch');
        }
    }

    /**
     * Calls the export directly so parity cannot pass through a silent PHP fallback.
     *
     * @param  list<string>  $rows
     * @param  list<int>  $expected
     */
    private function assertRustAccepts(string $class, array $rows, array $expected): void
    {
        if ($rows === []) {
            return;
        }
        $words = \count($rows) * self::WORDS_PER_ROW[$class];
        $buffer = (new ReflectionMethod($class, 'w58RustFfiState'))->invoke(null, 'out', $words);
        self::assertIsArray($buffer, $class);
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        self::assertSame(\count($rows), $this->invoke($ffi, self::CDEFS[$class][1], [$joined, \strlen($joined), \count($rows), $out, $cap]), $class.' Rust must accept the batch');
        self::assertSame($expected, array_values((array) unpack('q*', FFI::string($out, $words * 8))), $class);
    }

    /**
     * Calls an export declared by a class's Wave 58 CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param list<string> $values */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function words(Randomizer $random, int $min, int $max, string $glue = '-'): string
    {
        $parts = [];
        for ($i = $random->getInt($min, $max); $i > 0; $i--) {
            $parts[] = $this->pick($random, self::WORDS);
        }

        return implode($glue, $parts);
    }

    private function junk(Randomizer $random): string
    {
        return $this->pick($random, ['', ' ', 'x', '-', '+1', '1.5', "\u{e9}", '%20', 'a b', '&=?', "\xff", str_repeat('9', 19), '-'.str_repeat('9', 18), "\r\n", 'NOW', '0x1A']);
    }

    private function embedRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [
            $this->pick($random, ['/grafana', '/grafana/', 'https://grafana.example.com', 'http://mon.local:3000/grafana/', 'https://nntmux.example.org/monitoring/grafana']),
            $this->words($random, 1, 3).'-'.$random->getInt(1, 99),
            $this->words($random, 1, 4),
            (string) $random->getInt(1, 3),
            $this->pick($random, ['now-6h', 'now-24h', 'now-7d', 'now-1h', 'now-30m', 'now']),
            $this->pick($random, ['1m', '30s', '5m', '']),
            (string) ($random->getInt(0, 1) === 0 ? 0 : $random->getInt(1, 40)),
        ];
        if ($uncommon) {
            for ($n = $random->getInt(1, 3); $n > 0; $n--) {
                $slot = $random->getInt(0, 6);
                $fields[$slot] = match ($random->getInt(0, 5)) {
                    0 => $this->junk($random),
                    1 => strtoupper($fields[$slot]),
                    2 => $fields[$slot].$this->pick($random, ['/', '//', ' ', '~', "\u{2014}", '?a=b&c']),
                    3 => (string) $random->getInt(-50, 1_700_000_000_000),
                    4 => str_repeat($this->pick($random, ['u', '_', '-', 'é']), $random->getInt(1, 60)),
                    default => $this->words($random, 1, 6, $this->pick($random, [' ', '.', '%', '+'])),
                };
            }
            $roll = $random->getInt(0, 9);
            if ($roll === 0) {
                array_pop($fields);
            } elseif ($roll === 1) {
                $fields[] = 'extra';
            }
        }

        return implode("\t", $fields);
    }

    private function articleRow(Randomizer $random, bool $uncommon): string
    {
        $limit = $this->pick($random, ['4194304', '1048576', '8388608']);
        $fields = match ($random->getInt(0, 3)) {
            0, 1 => [(string) $random->getInt(1, 4_000_000), $limit, '0', (string) $random->getInt(0, 1)],
            2 => ['-', $limit, '0', '0', $this->pick($random, self::ERRORS)],
            default => ['-', $limit, '1', '0', 'Article body exceeds '.$limit.' byte limit'],
        };
        if ($uncommon) {
            for ($n = $random->getInt(1, 2); $n > 0; $n--) {
                $slot = $random->getInt(0, 4);
                $value = match ($random->getInt(0, 6)) {
                    0 => $this->junk($random),
                    1 => (string) $random->getInt(0, 9_000_000),
                    2 => strtoupper($this->pick($random, self::ERRORS)).' '.$this->words($random, 0, 8, ' '),
                    3 => $this->pick($random, ['0', '1', '-']),
                    4 => $random->getInt(100, 599).$this->pick($random, ['', ' ', "\t", 'x', '0']).$this->words($random, 0, 3, ' '),
                    5 => '',
                    default => $this->words($random, 2, 12, ' ').$this->pick($random, [' timeout', ' EOF', ' crc', ' auth', ' limit', "\u{e9}"]),
                };
                if ($slot === 4 && \count($fields) === 4) {
                    $fields[] = $value;
                } else {
                    $fields[min($slot, \count($fields) - 1)] = $value;
                }
            }
            if ($random->getInt(0, 9) === 0) {
                $fields = \array_slice($fields, 0, $random->getInt(1, 3));
            }
        }

        return implode("\t", $fields);
    }

    private function dispatchRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [
            $this->pick($random, BaseRunner::W58_RUNNER_COMMANDS),
            $this->pick($random, self::GROUPS),
            (string) $random->getInt(1, 20000),
            (string) $random->getInt(0, 5000),
            (string) $random->getInt(1, 16),
        ];
        if ($uncommon) {
            for ($n = $random->getInt(1, 3); $n > 0; $n--) {
                $slot = $random->getInt(0, 4);
                $fields[$slot] = match ($random->getInt(0, 5)) {
                    0 => $this->junk($random),
                    1 => strtoupper($fields[$slot]),
                    2 => $this->pick($random, [' ', "\n", "\x0b", "\r"]).$fields[$slot].$this->pick($random, [' ', '', "\0"]),
                    3 => (string) $random->getInt(-1000, 2_000_000),
                    4 => $this->pick($random, ['bogus', 'pp_', 'get-range', 'RELEASES ']),
                    default => str_repeat('g', $random->getInt(250, 260)),
                };
            }
            if ($random->getInt(0, 9) === 0) {
                array_pop($fields);
            }
        }

        return str_replace("\0", '', implode("\t", $fields));
    }

    private function backfillRow(Randomizer $random, bool $uncommon): string
    {
        $our = $random->getInt(1_000_000, 900_000_000);
        $fields = [
            $this->pick($random, self::GROUPS),
            (string) $our,
            (string) ($our - $random->getInt(-5000, 5_000_000)),
            (string) $random->getInt(1000, 50000),
            (string) $random->getInt(1, 16),
            (string) $this->pick($random, ['20000', '10000', '50000']),
            (string) $random->getInt(0, 4000),
            (string) $random->getInt(0, 4000),
            (string) $random->getInt(0, 1),
        ];
        if ($uncommon) {
            for ($n = $random->getInt(1, 3); $n > 0; $n--) {
                $slot = $random->getInt(0, 8);
                $fields[$slot] = match ($random->getInt(0, 4)) {
                    0 => $this->junk($random),
                    1 => (string) $random->getInt(-999_999_999_999_999_999, 999_999_999_999_999_999),
                    2 => (string) $random->getInt(-100, 100),
                    3 => '-'.$fields[$slot],
                    default => (string) $random->getInt(0, 2_000_000),
                };
            }
            if ($random->getInt(0, 9) === 0) {
                array_pop($fields);
            }
        }

        return implode("\t", $fields);
    }

    private function binariesRow(Randomizer $random, bool $uncommon): string
    {
        $our = $random->getInt(0, 4) === 0 ? 0 : $random->getInt(1_000_000, 900_000_000);
        $fields = [
            (string) $random->getInt(1, 5000),
            $this->pick($random, self::GROUPS),
            (string) ($random->getInt(0, 5) === 0 ? 0 : 1),
            (string) $our,
            (string) ($our + $random->getInt(-1000, 3_000_000)),
            (string) $this->pick($random, ['0', '1000000', '500000', '250000']),
            (string) $this->pick($random, ['20000', '10000', '50000', '0']),
        ];
        if ($uncommon) {
            for ($n = $random->getInt(1, 3); $n > 0; $n--) {
                $slot = $random->getInt(0, 6);
                $fields[$slot] = match ($random->getInt(0, 4)) {
                    0 => $this->junk($random),
                    1 => (string) $random->getInt(-999_999_999_999_999_999, 999_999_999_999_999_999),
                    2 => (string) $random->getInt(-100, 100),
                    3 => (string) $random->getInt(1_000_000_000, 2_000_000_000),
                    default => (string) $random->getInt(0, 2),
                };
            }
            if ($random->getInt(0, 9) === 0) {
                $fields[] = '1';
            }
        }

        return implode("\t", $fields);
    }
}
