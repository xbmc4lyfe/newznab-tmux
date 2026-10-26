<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Cloudflare\CloudflareIpRangeService;
use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\GrafanaJwtIssuer;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\Monitoring\PrometheusMetricsExporter;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustMonitoringBoundedQueryGrafanaJwtMetricFamilyExporterCloudflareW60ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        BoundedMetricQuery::class => 'boundedQueryPlanBatch',
        GrafanaJwtIssuer::class => 'jwtClaimPlanBatch',
        MetricFamily::class => 'familySummaryBatch',
        PrometheusMetricsExporter::class => 'scrapePlanBatch',
        CloudflareIpRangeService::class => 'ipRangeClassifyBatch',
    ];

    /** Output words per row, then rollup words after the rows. */
    private const WORDS = [
        BoundedMetricQuery::class => [7, 0],
        GrafanaJwtIssuer::class => [7, 0],
        MetricFamily::class => [6, 9],
        PrometheusMetricsExporter::class => [7, 14],
        CloudflareIpRangeService::class => [6, 8],
    ];

    /** Class-distinct private CDEF constant => export it declares. */
    private const CDEFS = [
        BoundedMetricQuery::class => ['W60_BOUNDED_METRIC_QUERY_FFI_CDEF', 'nntmux_w601_bounded_metric_query_plan_batch'],
        GrafanaJwtIssuer::class => ['W60_GRAFANA_JWT_FFI_CDEF', 'nntmux_w601_grafana_jwt_claim_plan_batch'],
        MetricFamily::class => ['W60_METRIC_FAMILY_FFI_CDEF', 'nntmux_w601_metric_family_summary_batch'],
        PrometheusMetricsExporter::class => ['W60_PROMETHEUS_EXPORTER_FFI_CDEF', 'nntmux_w601_prometheus_exporter_scrape_plan_batch'],
        CloudflareIpRangeService::class => ['W60_CLOUDFLARE_IP_RANGE_FFI_CDEF', 'nntmux_w601_cloudflare_ip_range_classify_batch'],
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns) from
     * test_paired_randomized_common_and_uncommon_benchmarks (NNTMUX_W601_BENCH=1): seed 60,
     * ROUNDS interleaved pairs per workload after WARMUPS, ROWS rows per batch, randomized call
     * order, each side the fastest of REPEATS back-to-back calls, release Rust, PHP 8.5 CLI
     * without opcache, Apple Silicon macOS. Timings include dispatch, implode(), the FFI call and
     * unpack('q*'). Per-metric minimum of four runs. A class defaults to Rust only when
     * all four reach 1.5x.
     */
    private const QUALIFICATION = [
        BoundedMetricQuery::class => [4.059, 4.517, 3.044, 2.848],
        GrafanaJwtIssuer::class => [5.76, 4.829, 4.484, 2.386],
        MetricFamily::class => [5.091, 4.889, 4.185, 3.895],
        PrometheusMetricsExporter::class => [4.39, 4.793, 4.348, 2.677],
        CloudflareIpRangeService::class => [14.382, 14.085, 13.021, 12.163],
    ];

    private const ROUNDS = 60;

    private const WARMUPS = 8;

    private const ROWS = 320;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 60;

    private const JUNK = ['', ' ', 'x', '-', '+1', '1.5', "\u{e9}", '%20', 'a b', '&=?', "\xff", '-0', '00', '01', ' 1 ', "\x0b1\r", "\n", '9999999999999999999', '-999999999999999999', '999999999999999999', 'NULL', '0x1A', '__', '- -',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '/', '::', '#', ','];

    private const SQLS = [
        'select count(*) as aggregate from `releases` where `adddate` > ?',
        'select `categories_id`, count(*) as total from `releases` group by `categories_id`',
        'select max(`id`) as id from `binaries` limit 1',
        'select `name`, `last_updated` from `usenet_groups` where `active` = ? limit 500',
        'with recent as (select id from releases order by id desc limit 100) select count(*) from recent',
        'SHOW GLOBAL STATUS LIKE ?',
        'explain select * from collections',
    ];

    private const ODD_SQLS = ['', '   ', "\n\tselect 1", 'SELECT/* x */1;', 'select 1; drop table releases', 'delete from releases where id = ?', 'update `settings` set `value` = 1', 'insert into t values (1)', 'replace into t select 1',
        'select /*+ MAX_EXECUTION_TIME(2000) */ 1', 'SET STATEMENT max_statement_time=2 FOR select 1', 'select 1;;  ', 'select CREATEd_at from t', 'select created from t', 'call proc()', '(select 1)', '123 select', 'Select', 'selectx from t',
        "select caf\u{e9} from t", "select \xff from t", 'select 1 LIMIT', 'grant all', 'truncate t', 'alter table x', 'select `drop` from t', 'select a_drop_b from t'];

    private const COLLECTORS = ['App\\Services\\Monitoring\\Collectors\\QueueCollector', 'App\\Services\\Monitoring\\Collectors\\ReleaseCollector', 'App\\Services\\Monitoring\\Collectors\\TmuxCollector', 'App\\Services\\Monitoring\\Collectors\\BackfillCollector',
        'App\\Services\\Monitoring\\Collectors\\DatabaseCollector'];

    private const ODD_COLLECTORS = ['', 'Collector', 'XCollector', 'queueCollector', 'Queue', 'App\\', '\\', '\\\\QueueCollector', 'Queue_Collector', "Caf\u{e9}Collector", 'QueueCollector2', 'A\\B\\C', 'QUEUECOLLECTOR', 'Queue Collector'];

    private const STATUSES = ['ok', 'success', '0', 'error', 'failed', 'fail', 'exception', '1', 'timeout', 'timed_out', 'skipped', 'skip', 'disabled', 'OK', ' Error ', 'TimeOut', 'unknown', '2', '', 'okay'];

    private const METRIC_NAMES = ['nntmux_queue_jobs', 'nntmux_releases_total', 'nntmux_backfill_groups', 'nntmux_metrics_collector_errors', 'nntmux_tmux_panes', 'nntmux_binaries_processed_total'];

    private const ODD_NAMES = ['', '1abc', 'a-b', '__internal', 'a:b:c', ':colon', "name\n", "name\n\n", 'gauge_total', 'x', "caf\u{e9}", 'ab cd', '_', '_total', 'A_total', 'a.b'];

    private const TYPES = ['gauge', 'counter', 'histogram', 'summary', 'untyped', 'Gauge', ' counter ', 'COUNTER', 'unknown', '', 'gauges'];

    private const LABELS = ['queue', 'state', 'collector', 'group', 'job', 'le', '__name', '1bad', 'a-b', '', "x\n", 'queue', 'Q'];

    private const CIDRS = ['10.0.0.0/8', '192.168.0.0/16', '172.16.0.0/12', '127.0.0.1/32', '169.254.0.0/16', '100.64.0.0/10', '224.0.0.0/4', '240.0.0.0/4', '0.0.0.0/0', '192.0.2.0/24', '198.51.100.7/24', '203.0.113.0/24',
        '::1/128', '::/0', 'fc00::/7', 'fd12:3456::/48', 'fe80::/10', 'ff02::1/128', '::ffff:10.0.0.1/128', '2001:db8::/32', '2001:4860::/32', '1::9.8.7.6/128', '2400:cb00:1::/48', '2c0f:f248::/31'];

    private const ODD_CIDRS = ['', '   ', '# comment', '#', 'REMOTE_ADDR', 'PRIVATE_SUBNETS', 'private_ranges', ' REMOTE_ADDR ', 'remote_addr', '1.2.3.4', '1.2.3.4/', '1.2.3.4/33', '1.2.3.4/0032', '1.2.3.4/-1', '1.2.3.4/+8', '1.2.3.4/ 8',
        '1.2.3.4 /8', '01.2.3.4/8', '256.0.0.0/8', '1.2.3/8', '::1/129', '::1/0128', '1:2:3:4:5:6:7:8/64', '1:2:3:4:5:6:7::/112', '1::2::3/8', ':1::/8', 'g::/8', '1.2.3.4/8/9', "1.2.3.4\t/8", "\x0b10.0.0.0/8\r", '::ffff:1.2.3.4/96',
        '2001:DB8::/32', '/8', '1.2.3.4/1a', "caf\u{e9}/8", "\xff/8", '12345::/8', '1:2:3:4:5:6:7:1.2.3.4/8', '1:2:3:4:5:6:1.2.3.4/8', '173.245.48.1/20', '173.245.48.0/19', '104.31.255.255/32', '2a06:98c7:ffff::/48'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW60RustFfi', 'withW60RustFfi', 'w60RustFfiState', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW60RustFfi', 'withW60RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w60RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            $constant = $reflection->getReflectionConstant('W60_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPublic(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            [$cdef, $symbol] = self::CDEFS[$class];
            $cdefConstant = $reflection->getReflectionConstant($cdef);
            self::assertNotFalse($cdefConstant, $class.'::'.$cdef);
            self::assertTrue($cdefConstant->isPrivate(), $class.'::'.$cdef);
            self::assertStringContainsString($symbol, (string) $cdefConstant->getValue());
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                self::assertStringNotContainsStringIgnoringCase('w60', $property->getName(), $class.' keeps Wave 60 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
            if (min($speedups) < 1.5) {
                self::assertFalse($this->uses($class), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_w60_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW60RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW60RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW60RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
        // Toggles are independent per class.
        $others = static fn (): array => array_map(static fn (string $class): bool => $class::usesW60RustFfi(), [GrafanaJwtIssuer::class, MetricFamily::class, PrometheusMetricsExporter::class, CloudflareIpRangeService::class]);
        $before = $others();
        self::assertSame($before, BoundedMetricQuery::withW60RustFfi(! BoundedMetricQuery::usesW60RustFfi(), $others));
    }

    public function test_toggle_routes_entry_point(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $rows = $this->fixture($class, new Randomizer(new Mt19937(self::SEED + 2)), false, 12);
            $call = $this->entry($class);
            $php = $class::{self::ENTRIES[$class].'Php'}($rows);
            self::assertSame($php, $class::withW60RustFfi(true, static fn (): mixed => $call($rows)), $class);
            self::assertSame($php, $class::withW60RustFfi(false, static fn (): mixed => $call($rows)), $class);
        }
    }

    public function test_earlier_toggles_are_untouched(): void
    {
        self::assertFalse(BoundedMetricQuery::usesRustFfi());
        self::assertFalse(PrometheusMetricsExporter::usesRustFfi());
        self::assertFalse(GrafanaJwtIssuer::$useRustFfi);
        self::assertFalse(MetricFamily::$useRustFfi);
        self::assertTrue(BoundedMetricQuery::usesW49RustFfi());
        self::assertTrue(GrafanaJwtIssuer::usesW49RustFfi());
        self::assertTrue(MetricFamily::usesW49RustFfi());
        self::assertTrue(PrometheusMetricsExporter::usesW49RustFfi());
        self::assertTrue(CloudflareIpRangeService::usesW47CidrMatchRustFfi());
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            $class::withW60RustFfi(! $default, static function (): void {
                self::assertTrue(BoundedMetricQuery::usesW49RustFfi());
                self::assertTrue(CloudflareIpRangeService::usesW47CidrMatchRustFfi());
                self::assertFalse(BoundedMetricQuery::usesRustFfi());
            });
        }
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<string>, 2: list<int>}>
     */
    public static function knownCodes(): iterable
    {
        $q = BoundedMetricQuery::class;
        yield 'query select' => [$q, ["  SELECT a FROM t LIMIT 5\t300\t0\t0\t10\t60"],
            [1 | $q::W60_SQL_READ_ONLY | $q::W60_SQL_HAS_LIMIT | $q::W60_RUNNABLE | $q::W60_TIMEOUT_DEFAULTED | $q::W60_LIMIT_DEFAULTED, 300, 15, 2000, 1000, 50, 6]];
        yield 'query write multi' => [$q, ["delete from t; select 1;;\t1\t99999\t200000\t-1\t0"],
            [5 | $q::W60_SQL_MULTI | $q::W60_WINDOW_CLAMPED | $q::W60_TIMEOUT_CLAMPED | $q::W60_LIMIT_CLAMPED | $q::W60_CACHE_DISABLED | $q::W60_FUTURE_AGE, 60, 15, 30000, 100000, 0, 5]];
        yield 'query hinted stale' => [$q, ["select /*+ MAX_EXECUTION_TIME(2000) */ 1\t86400\t2000\t50\t60\t60"],
            [1 | $q::W60_SQL_READ_ONLY | $q::W60_SQL_HINTED | $q::W60_RUNNABLE | $q::W60_STALE, 86400, 360, 2000, 50, 0, 4]];
        yield 'query malformed' => [$q, ["select 1\t1\t1\t1\t1"], [$q::W60_MALFORMED, 0, 0, 0, 0, 0, 0]];

        $j = GrafanaJwtIssuer::class;
        $claims = '{"sub":"alice","email":"a@b.co","name":"alice","role":"Viewer","org":1,"iat":1000,"nbf":970,"exp":1900}';
        yield 'jwt fresh viewer' => [$j, ["alice\ta@b.co\tViewer\t\t900\t1000\t30\t0"],
            [2 | $j::W60_ROLE_EXACT | $j::W60_SUB_VALID | $j::W60_EMAIL_VALID | $j::W60_ORG_DEFAULTED | $j::W60_PRIOR_NONE | $j::W60_REFRESH | $j::W60_CLAIM_SAFE | $j::W60_ISSUABLE, 1, 900, 970, 1900, 0, \strlen($claims)]];
        yield 'jwt active prior' => [$j, ["bob\t\t admin \t7\t30\t1000\t500\t900"],
            [4 | $j::W60_SUB_VALID | $j::W60_EMAIL_EMPTY | $j::W60_TTL_CLAMPED | $j::W60_SKEW_CLAMPED | $j::W60_PRIOR_ACTIVE | $j::W60_CLAIM_SAFE | $j::W60_ISSUABLE, 7, 60, 700, 1060, 260, 94]];
        yield 'jwt unsafe' => [$j, ["a\"b\tx@y\tRoot\t0\t-1\t0\t0\t5"],
            [$j::W60_ORG_INVALID | $j::W60_TTL_DEFAULTED | $j::W60_PRIOR_NOT_YET | $j::W60_REFRESH, 0, 900, 0, 900, 0, -1]];
        yield 'jwt malformed' => [$j, ["a\tb\tc\td\t1\t1\t1\tx"], [$j::W60_MALFORMED, 0, 0, 0, 0, 0, 0]];

        $f = MetricFamily::class;
        yield 'family rollup' => [$f, ["jobs\tcounter\ta\\b\tqueue,state,queue\t7\t5", "up_total\tGauge\t \t\t-3\t0", "x\t1"], [
            2 | $f::W60_TYPE_EXACT | $f::W60_TYPE_SUPPORTED | $f::W60_NAME_VALID | $f::W60_COUNTER_NO_TOTAL | $f::W60_HELP_ESCAPED | $f::W60_LABEL_DUPLICATE | $f::W60_CARDINALITY_EXCEEDED | $f::W60_DEFINITION_VALID, 3, -1, 4, 7, -2,
            1 | $f::W60_NAME_VALID | $f::W60_NAME_TOTAL | $f::W60_GAUGE_TOTAL | $f::W60_HELP_EMPTY | $f::W60_SERIES_CLAMPED, 0, -1, 1, 0, -1,
            $f::W60_MALFORMED, 0, 0, 0, 0, 0,
            3, 1, 3, 7, 1, 7, 1, 1, 1,
        ]];
        yield 'family empty batch tail' => [$f, [], array_fill(0, 9, 0)];

        $e = PrometheusMetricsExporter::class;
        $s = $e::W60_CLASS_SHIFT;
        yield 'scrape aggregate' => [$e, ["App\\QueueCollector\t OK \t2\t50\t1000000\t0\t10", "x\tTIMEOUT\t0\t0\t9000000\t1000000\t-1", "x\tok"], [
            1 | 1 << $s | $e::W60_NAME_CONVENTIONAL | $e::W60_NAMESPACED | $e::W60_BUDGET_DEFAULTED, 14, 2, 50, 25, 20, 10,
            3 | 4 << $s | $e::W60_CLAMPED | $e::W60_OVER_BUDGET | $e::W60_RETRY, 1, 0, 0, 0, 900, 0,
            $e::W60_MALFORMED, 0, 0, 0, 0, 0, 0,
            3, 1, 0, 1, 0, 0, 1, 2, 50, 10, 10000000, 1, 900, 1,
        ]];
        yield 'scrape empty batch tail' => [$e, [], array_fill(0, 14, 0)];

        $c = CloudflareIpRangeService::class;
        $cs = $c::W60_CLASS_SHIFT;
        yield 'cidr classify' => [$c, [' 104.16.1.2/13', '2a06:98c0::/29', '::1/129', 'REMOTE_ADDR', '# x', '1.2.3', '10.1.0.0'], [
            1 | $c::W60_TRIMMED | $c::W60_CIDR_VALID | $c::W60_HOST_BITS | $c::W60_CF_MEMBER, 13, 19, (104 << 24) | (16 << 16), 0, 11,
            2 | $c::W60_CIDR_VALID | $c::W60_CF_MEMBER, 29, 99, 0x2A0698C000000000, 0, 20,
            2 | 2 << $cs | $c::W60_PREFIX_INVALID, -1, 0, 0, 1, -1,
            $c::W60_PROXY_TOKEN, 0, 0, 0, 0, -1,
            $c::W60_SKIPPED, 0, 0, 0, 0, -1,
            $c::W60_INVALID_ADDRESS, 0, 0, 0, 0, -1,
            1 | 1 << $cs | $c::W60_PREFIX_IMPLIED, 32, 0, (10 << 24) | (1 << 16), 0, -1,
            7, 2, 2, 2, 2, 2, 1, 2,
        ]];
        yield 'cidr negative ipv6 halves' => [$c, ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff/127'], [2 | 5 << $cs | $c::W60_CIDR_VALID | $c::W60_HOST_BITS, 127, 1, -1, -2, -1, 1, 0, 1, 0, 0, 0, 1, 1]];
        yield 'cidr empty batch tail' => [$c, [], array_fill(0, 8, 0)];
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $rows
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCodes')]
    public function test_known_codes(string $class, array $rows, array $expected): void
    {
        self::assertSame($expected, $this->assertParity($class, $rows));
        $this->assertRustAccepts($class, $rows, $expected);
    }

    public function test_batch_words_agree_with_existing_methods(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 4));

        // MetricFamily: definition-valid matches definitionCheckBatchPhp() status 0.
        $definitions = $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $name = $this->pick($random, [...self::METRIC_NAMES, ...self::ODD_NAMES]);
            $type = $this->pick($random, self::TYPES);
            $labels = array_map(fn (): string => $this->pick($random, array_values(array_filter(self::LABELS, static fn (string $label): bool => $label !== ''))), range(1, $random->getInt(1, 3)));
            $definitions[] = [$name, $type, $labels];
            $rows[] = "{$name}\t{$type}\thelp\t".implode(',', $labels)."\t1\t0";
        }
        $words = MetricFamily::familySummaryBatch($rows);
        foreach (MetricFamily::definitionCheckBatchPhp($definitions) as $i => $check) {
            self::assertSame($check[0] === 0, ($words[$i * 6] & MetricFamily::W60_DEFINITION_VALID) !== 0, json_encode($definitions[$i]) ?: '');
            self::assertSame($check[3], $words[$i * 6 + 2], json_encode($definitions[$i]) ?: '');
            self::assertSame($check[2], $words[$i * 6 + 1]);
        }

        // CloudflareIpRangeService: prefix, host bits and family match matchAddressesPhp()'s range words.
        $entries = [...self::CIDRS, ...array_values(array_filter(self::ODD_CIDRS, static fn (string $cidr): bool => $cidr === trim($cidr) && str_contains($cidr, '/')))];
        $ranges = CloudflareIpRangeService::matchAddressesPhp([], $entries);
        $words = CloudflareIpRangeService::ipRangeClassifyBatch($entries);
        foreach ($entries as $i => $entry) {
            $flags = $words[$i * 6];
            $valid = ($flags & CloudflareIpRangeService::W60_CIDR_VALID) !== 0;
            self::assertSame($ranges[$i] >= 0, $valid, $entry);
            if ($valid) {
                $packed = $words[$i * 6 + 1] | (($flags & CloudflareIpRangeService::W60_HOST_BITS) !== 0 ? 256 : 0) | (($flags & 3) === 2 ? 512 : 0);
                self::assertSame($ranges[$i], $packed, $entry);
            }
        }
        // Every published Cloudflare range is a member of itself.
        $words = CloudflareIpRangeService::ipRangeClassifyBatch(CloudflareIpRangeService::W60_CLOUDFLARE_RANGES);
        foreach (CloudflareIpRangeService::W60_CLOUDFLARE_RANGES as $i => $range) {
            self::assertSame($i, $words[$i * 6 + 5], $range);
            self::assertSame(0, $words[$i * 6] & CloudflareIpRangeService::W60_HOST_BITS, $range);
        }

        // PrometheusMetricsExporter: the basename length matches class_basename().
        foreach ([...self::COLLECTORS, ...self::ODD_COLLECTORS] as $collector) {
            if (str_ends_with($collector, '\\')) {
                continue;
            }
            $words = PrometheusMetricsExporter::scrapePlanBatch(["{$collector}\tok\t1\t1\t1\t1\t1"]);
            self::assertSame(\strlen(class_basename($collector)), $words[1], $collector);
        }

        // BoundedMetricQuery: a hinted plan flags the statement rewriteSqlPhp() produces.
        foreach (self::SQLS as $sql) {
            foreach ([true, false] as $maria) {
                if (! $maria && ! str_starts_with($sql, 'select')) {
                    continue;
                }
                $words = BoundedMetricQuery::boundedQueryPlanBatch([BoundedMetricQuery::rewriteSqlPhp($sql, $maria)."\t60\t1\t1\t0\t0"]);
                self::assertNotSame(0, $words[0] & BoundedMetricQuery::W60_SQL_HINTED, $sql);
            }
        }
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<string>}>
     */
    public static function edgeBatches(): iterable
    {
        $shared = ['', ' ', "\t", "\t\t", "\t\t\t", "\t\t\t\t", "\t\t\t\t\t", "\t\t\t\t\t\t", "\t\t\t\t\t\t\t", "\n", "\r\n", "\x0b", "\xff", "caf\u{e9}", '-', '0', '1', '-0', str_repeat('9', 19), str_repeat("\t", 12)];
        $big = str_repeat('9', 18);
        $classes = [
            BoundedMetricQuery::class => [
                ...array_map(static fn (string $sql): string => "{$sql}\t300\t2000\t1000\t0\t60", self::ODD_SQLS),
                "select 1\t-{$big}\t-{$big}\t-{$big}\t-{$big}\t-{$big}", "select 1\t{$big}\t{$big}\t{$big}\t{$big}\t{$big}", "select 1\t59\t30001\t100001\t5\t5", "select 1\t60\t30000\t100000\t4\t5", "select 1\t239\t1\t1\t-1\t-1",
                "select 1\t3840\t1\t1\t0\t1", "select 1\t+1\t1\t1\t1\t1", "select 1\t1\t1\t1\t1\t1\t1", "select 1\t1\t1\t1\t1", "\t1\t1\t1\t1\t1", "select 1\t 1\t1\t1\t1\t1"],
            GrafanaJwtIssuer::class => ["alice\ta@b.c\tViewer\t1\t900\t1000\t30\t1000", "a\t@b.c\tviewer\t1\t900\t0\t0\t0", "a\ta@.c\tVIEWER\t1\t900\t0\t0\t0", "a\ta@b.\tEditor\t1\t900\t0\t0\t0", "a\ta@b@c.d\tAdmin\t1\t900\t0\t0\t0", "a\ta @b.cd\tNone\t1\t900\t0\t0\t0",
                "a\ta@b.c.d\t none\t1\t900\t0\t0\t0", "a\ta@b..\tnone \t1\t900\t0\t0\t0", "a\ta@...\tGrafanaAdmin\t1\t900\t0\t0\t0", str_repeat('a', 255)."\t\tViewer\t1\t900\t0\t0\t0", str_repeat('a', 256)."\t\tViewer\t1\t900\t0\t0\t0", "\t\tViewer\t\t900\t0\t0\t0",
                "a+b@c.d-e_f\t\tViewer\t01\t900\t0\t0\t0", "a\t\tViewer\t-1\t900\t0\t0\t0", "a\t\tViewer\t0\t900\t0\t0\t0", "a\t\tViewer\t{$big}\t900\t0\t0\t0", "a\t\tViewer\t+1\t900\t0\t0\t0", "a\t\tViewer\t1.0\t900\t0\t0\t0",
                "a\t\tViewer\t1\t59\t0\t301\t0", "a\t\tViewer\t1\t86401\t0\t-1\t0", "a\t\tViewer\t1\t-{$big}\t{$big}\t{$big}\t{$big}", "a\t\tViewer\t1\t{$big}\t-{$big}\t-{$big}\t-{$big}", "a\t\tViewer\t1\t900\t1000\t30\t70", "a\t\tViewer\t1\t900\t1000\t30\t69",
                "a\t\tViewer\t1\t900\t1000\t30\t1030", "a\t\tViewer\t1\t900\t1000\t30\t1031", "a\t\tViewer\t1\t900\t1000\t30\t280", "a\t\tViewer\t1\t900\t1000\t30\t281", "a\\b\t\tViewer\t1\t900\t0\t0\t0", "a\tb\x7f\tViewer\t1\t900\t0\t0\t0",
                "caf\u{e9}\t\tViewer\t1\t900\t0\t0\t0", "a/b\tc/d@e.f\tView/er\t1\t900\t0\t0\t0", "a\t\tViewer\t1\t900\t0\t0", "a\t\tViewer\t1\t900\t0\t0\t0\t0"],
            MetricFamily::class => [
                ...array_map(static fn (string $name): string => "{$name}\tgauge\thelp\t\t1\t0", self::ODD_NAMES),
                ...array_map(static fn (string $type): string => "m\t{$type}\thelp\tqueue\t1\t1", self::TYPES),
                "m\tgauge\t\t\t0\t0", "m\tgauge\ta\\\\b\nc\t,\t1\t1", "m\tgauge\th\t,,\t1\t1", "m\tgauge\th\tqueue,QUEUE\t1\t1", "m\tgauge\th\t1,1\t1\t1", "m\tgauge\th\tqueue,01,1\t1\t1", "m\tgauge\th\tlabel\n\t1\t1",
                "m\tgauge\th\tlabel\n\n\t1\t1", "m\tgauge\th\t__x,a,b\t1\t1", "m\tgauge\th\ta\t-{$big}\t-{$big}", "m\tgauge\th\ta\t{$big}\t{$big}", "m\tgauge\th\ta\t1000000000\t999999999", "m\tgauge\th\ta\t1000000001\t1",
                "m\tgauge\th\ta\t1\t+1", "m\tgauge\th\ta\t1", "m\tgauge\th\ta\t1\t1\t1", "m\tgauge\th\t".implode(',', array_map(strval(...), range(1, 40)))."\t1\t1"],
            PrometheusMetricsExporter::class => [
                ...array_map(static fn (string $name): string => "{$name}\tok\t1\t1\t1\t1\t1", self::ODD_COLLECTORS),
                ...array_map(static fn (string $status): string => "QueueCollector\t{$status}\t1\t1\t1\t1\t1", self::STATUSES),
                "Q\tok\t0\t0\t0\t0\t0", "Q\tok\t-{$big}\t-{$big}\t-{$big}\t-{$big}\t-{$big}", "Q\tok\t{$big}\t{$big}\t{$big}\t{$big}\t{$big}", "Q\tok\t1\t10001\t24\t100\t1", "Q\tok\t1\t10000\t25\t100\t1", "Q\tok\t1\t1\t74\t100\t1",
                "Q\tok\t1\t1\t75\t100\t1", "Q\tok\t1\t1\t100\t100\t1", "Q\tok\t1\t1\t101\t100\t1", "Q\tok\t1\t1\t3600000000\t1\t1", "Q\tok\t1000000000\t1000000000000\t3600000000\t3600000000\t1000000000000", "Q\tok\t1\t1\t1\t1", "Q\tok\t1\t1\t1\t1\t1\t1",
                "Q\tok\t1\t1\t1\t+1\t1"],
            CloudflareIpRangeService::class => [...self::CIDRS, ...self::ODD_CIDRS, ...CloudflareIpRangeService::W60_CLOUDFLARE_RANGES],
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
        [$perRow, $tail] = self::WORDS[$class];
        self::assertCount(\count($rows) * $perRow + $tail, $result, $class);
        $this->assertRustAccepts($class, $rows, $result);
        foreach ($rows as $row) {
            $this->assertParity($class, [$row]);
        }
    }

    public function test_keyed_rows_and_nul_rows_fall_back_to_php(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            [$perRow, $tail] = self::WORDS[$class];
            $rows = $this->fixture($class, new Randomizer(new Mt19937(self::SEED + 3)), false, 4);
            $keyed = ['k' => $rows[0], 7 => $rows[1], 'z' => $rows[2], 'q' => $rows[3]];
            self::assertSame($this->assertParity($class, $rows), $this->assertParity($class, $keyed), $class);
            $nul = [$rows[0], "a\0b", "\0", $rows[1]];
            self::assertCount(4 * $perRow + $tail, $this->assertParity($class, $nul), $class);
        }
    }

    /** At least 300 randomized rows per class and workload, checked as batches and one row at a time. */
    public function test_randomized_dataset_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        foreach (array_keys(self::ENTRIES) as $class) {
            [$perRow, $tail] = self::WORDS[$class];
            foreach ([false, true] as $uncommon) {
                $rows = $this->fixture($class, $random, $uncommon, 320);
                $words = $this->assertParity($class, $rows);
                self::assertIsArray($words);
                self::assertCount(320 * $perRow + $tail, $words, $class);
                $this->assertRustAccepts($class, $rows, $words);
                for ($i = 0; $i < 40; $i++) {
                    $this->assertParity($class, [$rows[$i]]);
                }
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W601_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W601_BENCH=1 to run the Wave 60.1 paired benchmark.');
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW60RustFfi($native, static function () use ($call, $rows): array {
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
        $artifact = sys_get_temp_dir().'/w601-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'warmups' => self::WARMUPS, 'rows' => self::ROWS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW60.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW60RustFfi'];

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
        $php = $class::withW60RustFfi(false, static fn (): mixed => $call($rows));
        $native = $class::withW60RustFfi(true, static fn (): mixed => $call($rows));
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
                BoundedMetricQuery::class => $this->queryRow($random, $uncommon),
                GrafanaJwtIssuer::class => $this->jwtRow($random, $uncommon),
                MetricFamily::class => $this->familyRow($random, $uncommon),
                PrometheusMetricsExporter::class => $this->scrapeRow($random, $uncommon),
                default => $this->cidrRow($random, $uncommon),
            };
        }

        return $rows;
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::CDEFS as $class => [, $symbol]) {
            $buffer = (new ReflectionMethod($class, 'w60RustFfiState'))->invoke(null, 'out', 4);
            self::assertIsArray($buffer, $class.' must load its Wave 60 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            [$perRow, $tail] = self::WORDS[$class];
            $out = $ffi->new('int64_t[64]');
            $rows = "a\tb\0c";
            self::assertSame(2, $this->invoke($ffi, $symbol, [$rows, 5, 2, $out, 64]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [$rows, 5, 2, $out, 2 * $perRow + $tail - 1]), $symbol.' short capacity');
            self::assertSame(-1, $this->invoke($ffi, $symbol, [$rows, 5, 3, $out, 64]), $symbol.' row count mismatch');
            self::assertSame(0, $this->invoke($ffi, $symbol, ['', 0, 0, $out, 64]), $symbol.' empty batch');
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
        [$perRow, $tail] = self::WORDS[$class];
        $words = \count($rows) * $perRow + $tail;
        $buffer = (new ReflectionMethod($class, 'w60RustFfiState'))->invoke(null, 'out', $words);
        self::assertIsArray($buffer, $class);
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        self::assertSame(\count($rows), $this->invoke($ffi, self::CDEFS[$class][1], [$joined, \strlen($joined), \count($rows), $out, $cap]), $class.' Rust must accept the batch');
        self::assertSame($expected, array_values((array) unpack('q*', FFI::string($out, $words * 8))), $class);
    }

    /**
     * Calls an export declared by a class's Wave 60 CDEF.
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

    /** Trims, recases or pads a token. */
    private function noisy(Randomizer $random, string $token): string
    {
        return match ($random->getInt(0, 4)) {
            0 => strtoupper($token),
            1 => $this->pick($random, [' ', "\n", "\x0b", "\r", '']).$token.$this->pick($random, [' ', '', "\r\n"]),
            2 => ucfirst($token),
            3 => $token.$this->pick($random, ['x', 's', "\u{e9}", '1']),
            default => $token,
        };
    }

    /**
     * Replaces 1-3 random fields with junk or out-of-range numbers and occasionally changes the field count.
     *
     * @param  list<string>  $fields
     */
    private function mutate(Randomizer $random, array $fields, int $from): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $slot = $random->getInt($from, \count($fields) - 1);
            $fields[$slot] = match ($random->getInt(0, 5)) {
                0 => $this->pick($random, self::JUNK),
                1 => (string) $random->getInt(-999_999_999_999_999_999, 999_999_999_999_999_999),
                2 => (string) $random->getInt(-100, 100),
                3 => '-'.$fields[$slot],
                4 => (string) $random->getInt(0, 5_000_000_000),
                default => $fields[$slot],
            };
        }
        $roll = $random->getInt(0, 14);
        if ($roll === 0) {
            array_pop($fields);
        } elseif ($roll === 1) {
            $fields[] = '1';
        }

        return implode("\t", $fields);
    }

    private function queryRow(Randomizer $random, bool $uncommon): string
    {
        $sql = $this->pick($random, self::SQLS);
        if ($uncommon) {
            $sql = match ($random->getInt(0, 4)) {
                0 => $this->pick($random, self::ODD_SQLS),
                1 => BoundedMetricQuery::rewriteSqlPhp($sql, $random->getInt(0, 1) === 0),
                2 => $sql.$this->pick($random, ['; drop table x', ';', ' ;; ', ' for update', ' limit 10']),
                3 => str_repeat($sql.' union all ', $random->getInt(1, 6)).$sql,
                default => $this->noisy($random, $sql),
            };
        }
        $fields = [$sql, $this->pick($random, ['300', '3600', '900', '86400']), $this->pick($random, ['2000', '0', '5000']), $this->pick($random, ['1000', '500', '1']), (string) $random->getInt(0, 600), $this->pick($random, ['60', '300', '0'])];

        return $uncommon ? $this->mutate($random, $fields, 1) : implode("\t", $fields);
    }

    private function jwtRow(Randomizer $random, bool $uncommon): string
    {
        $user = $this->pick($random, ['admin', 'alice', 'bob.smith', 'ops-team', 'grafana_viewer', 'user'.$random->getInt(1, 99999)]);
        $now = $random->getInt(1_700_000_000, 1_800_000_000);
        $fields = [$user, $user.'@'.$this->pick($random, ['example.com', 'nntmux.local', 'mail.co.uk']), $this->pick($random, ['Viewer', 'Viewer', 'Editor', 'Admin']), $this->pick($random, ['', '1', '2']),
            $this->pick($random, ['900', '3600', '600']), (string) $now, '30', $random->getInt(0, 2) === 0 ? '0' : (string) ($now - $random->getInt(0, 1200))];
        if (! $uncommon) {
            return implode("\t", $fields);
        }
        $slot = $random->getInt(0, 3);
        $fields[$slot] = match ($random->getInt(0, 4)) {
            0 => $this->pick($random, self::JUNK),
            1 => $this->noisy($random, $fields[$slot]),
            2 => $this->pick($random, ['None', 'viewer', ' ADMIN ', 'GrafanaAdmin', 'a"b', 'x\\y', 'a@b', '@b.c', 'a@b.', 'a b@c.d', "x@y\x7f.z", str_repeat('u', $random->getInt(200, 300))]),
            3 => (string) $random->getInt(-5, 5),
            default => $fields[$slot].$this->pick($random, ["\u{e9}", '/', ' ', '+tag']),
        };

        return $this->mutate($random, $fields, $random->getInt(0, 1) === 0 ? 4 : 3);
    }

    private function familyRow(Randomizer $random, bool $uncommon): string
    {
        $labels = array_map(fn (): string => $this->pick($random, ['queue', 'state', 'collector', 'group', 'job']), range(1, $random->getInt(0, 3)));
        $fields = [$this->pick($random, self::METRIC_NAMES), $this->pick($random, ['gauge', 'counter']), $this->pick($random, ['Jobs waiting in each queue.', 'Releases indexed.', 'Whether a collector failed.']),
            implode(',', array_unique($labels)), (string) $random->getInt(1, 200), $this->pick($random, ['0', '1000', '5000'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }
        $slot = $random->getInt(0, 3);
        $fields[$slot] = match ($slot) {
            0 => $random->getInt(0, 1) === 0 ? $this->pick($random, self::ODD_NAMES) : $this->noisy($random, $fields[0]),
            1 => $this->pick($random, self::TYPES),
            2 => $this->pick($random, ['', ' ', "line\nbreak", 'back\\slash', "both\\\n", str_repeat('help ', $random->getInt(10, 60))]),
            default => implode(',', array_map(fn (): string => $this->pick($random, self::LABELS), range(1, $random->getInt(1, 12)))),
        };

        return $this->mutate($random, $fields, 4);
    }

    private function scrapeRow(Randomizer $random, bool $uncommon): string
    {
        $families = $random->getInt(1, 20);
        $fields = [$this->pick($random, self::COLLECTORS), $random->getInt(0, 9) < 9 ? 'ok' : 'error', (string) $families, (string) ($families * $random->getInt(1, 400)), (string) $random->getInt(1000, 2_000_000), '5000000', (string) $random->getInt(100, 200_000)];
        if (! $uncommon) {
            return implode("\t", $fields);
        }
        $fields[0] = $random->getInt(0, 2) === 0 ? $this->pick($random, self::ODD_COLLECTORS) : $fields[0];
        $fields[1] = $this->pick($random, self::STATUSES);
        $fields[4] = (string) $random->getInt(0, 20_000_000);
        $fields[5] = $this->pick($random, ['0', '-1', '1000000', '5000000', '99999999999']);

        return $this->mutate($random, $fields, 2);
    }

    private function cidrRow(Randomizer $random, bool $uncommon): string
    {
        if (! $uncommon) {
            return $random->getInt(0, 1) === 0
                ? $this->pick($random, CloudflareIpRangeService::W60_CLOUDFLARE_RANGES)
                : implode('.', [$random->getInt(1, 223), $random->getInt(0, 255), $random->getInt(0, 255), 0]).'/'.$random->getInt(8, 32);
        }

        return match ($random->getInt(0, 5)) {
            0 => $this->pick($random, self::ODD_CIDRS),
            1 => $this->pick($random, self::CIDRS),
            2 => implode(':', array_map(static fn (int $group): string => dechex($group), [$random->getInt(0, 0xFFFF), $random->getInt(0, 0xFFFF), $random->getInt(0, 0xFFFF)])).'::/'.$random->getInt(0, 140),
            3 => long2ip($random->getInt(0, 0xFFFFFFFF)).'/'.$random->getInt(0, 40),
            4 => $this->pick($random, [' ', '', "\t", "\n"]).$this->pick($random, [...self::CIDRS, ...CloudflareIpRangeService::W60_CLOUDFLARE_RANGES]).$this->pick($random, ['', ' ', "\r\n"]),
            default => $this->pick($random, self::JUNK),
        };
    }
}
