<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Collectors\MetricsCollector;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Services\Monitoring\PrometheusMetricsExporter;
use App\Services\StatusProbes\DiskProbe;
use App\Services\StatusProbes\ProbeResult;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 20 Slice 20.6:
 * BoundedMetricQuery, PrometheusMetricsExporter, DiskProbe, IncidentImpactEnum, and IncidentStatusEnum.
 *
 * Benchmarks run only with NNTMUX_BOUNDED_METRIC_EXPORTER_DISK_INCIDENT_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_BOUNDED_METRIC_EXPORTER_DISK_INCIDENT_BENCH=1 php artisan test --compact tests/Unit/RustBoundedMetricExporterDiskProbeIncidentParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * Apple M5, PHP 8.5.12 NTS, rustc 1.99.1 release profile, base b007d8199.
 *
 * | Method                                                   | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |----------------------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | BoundedMetricQuery::rewriteSql                           | 0.19x / 0.15x       | 0.43x / 0.45x         | No      | PHP (false)  |
 * | PrometheusMetricsExporter::formatPushgatewayUrl          | 0.27x / 0.21x       | 0.28x / 0.26x         | No      | PHP (false)  |
 * | PrometheusMetricsExporter::computeExportDurationSeconds  | 0.24x / 0.22x       | 0.24x / 0.22x         | No      | PHP (false)  |
 * | PrometheusMetricsExporter::truncateErrorBody             | 0.30x / 0.28x       | 0.98x / 0.80x         | No      | PHP (false)  |
 * | DiskProbe::bytesToFreeGb                                 | 0.27x / 0.22x       | 0.26x / 0.27x         | No      | PHP (false)  |
 * | DiskProbe::evaluateThresholds                            | 0.41x / 0.40x       | 0.44x / 0.46x         | No      | PHP (false)  |
 * | IncidentImpactEnum::label                                | 0.23x / 0.26x       | 0.27x / 0.28x         | No      | PHP (false)  |
 * | IncidentImpactEnum::values                               | 0.35x / 0.31x       | 0.35x / 0.31x         | No      | PHP (false)  |
 * | IncidentStatusEnum::label                                | 0.10x / 0.13x       | 0.21x / 0.14x         | No      | PHP (false)  |
 * | IncidentStatusEnum::values                               | 0.36x / 0.35x       | 0.35x / 0.29x         | No      | PHP (false)  |
 *
 * No method qualifies: per-call FFI marshaling overhead exceeds these sub-microsecond PHP
 * operations, so all five classes retain PHP (`$useRustFfi = false`) as the production default.
 */
class RustBoundedMetricExporterDiskProbeIncidentParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        BoundedMetricQuery::class,
        PrometheusMetricsExporter::class,
        DiskProbe::class,
        IncidentImpactEnum::class,
        IncidentStatusEnum::class,
    ];

    private const string FULL_CDEF = BoundedMetricQuery::FFI_CDEF."\n"
        .PrometheusMetricsExporter::FFI_CDEF."\n"
        .DiskProbe::FFI_CDEF."\n"
        .IncidentImpactEnum::FFI_CDEF."\n"
        .IncidentStatusEnum::FFI_CDEF;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('categorize', self::FULL_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    // ---------------------------------------------------------------------
    // 1. BoundedMetricQuery
    // ---------------------------------------------------------------------

    public function test_bounded_metric_query_sql_rewrite_and_execution_parity(): void
    {
        $queries = [
            'select * from `candidates` where `status` = ?',
            'SELECT count(*) as aggregate from `releases`',
            "Select\n`id`, `name` from `groups`",
            "SELECT\t1",
            'SELECT',
            'select_not_a_keyword from `dual`',
            'selective_view',
            '/* leading comment */ select * from `parts`',
            "select\xC3\xA9 from `utf8_boundary`",
            '',
            'select '.str_repeat('`col_name`, ', 60).'`id` from `large_metrics_view` where `active` = 1',
        ];

        foreach ([true, false] as $isMaria) {
            foreach ($queries as $sql) {
                $expected = BoundedMetricQuery::rewriteSqlPhp($sql, $isMaria);
                $actual = BoundedMetricQuery::rewriteSqlNative($sql, $isMaria);
                $this->assertSame($expected, $actual, 'maria='.(int) $isMaria.' sql='.var_export($sql, true));

                foreach ([false, true] as $toggle) {
                    BoundedMetricQuery::setUseRustFfi($toggle);
                    $this->assertSame($expected, BoundedMetricQuery::rewriteSql($sql, $isMaria));
                }
            }
        }

        foreach ([false, true] as $toggle) {
            BoundedMetricQuery::setUseRustFfi($toggle);

            foreach ([
                [true, 'SET STATEMENT max_statement_time=2 FOR select * from `candidates` where `status` = ?'],
                [false, 'select /*+ MAX_EXECUTION_TIME(2000) */ * from `candidates` where `status` = ?'],
            ] as [$isMaria, $expectedSql]) {
                $connection = new class($isMaria) extends MySqlConnection
                {
                    public string $capturedSql = '';

                    /** @var array<int, mixed> */
                    public array $capturedBindings = [];

                    public function __construct(private readonly bool $maria)
                    {
                        parent::__construct(static fn (): PDO => new PDO('sqlite::memory:'));
                    }

                    public function isMaria(): bool
                    {
                        return $this->maria;
                    }

                    /**
                     * @param  string  $query
                     * @param  array<int, mixed>  $bindings
                     * @param  bool  $useReadPdo
                     * @param  array<int, mixed>  $fetchUsing
                     * @return list<object>
                     */
                    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
                    {
                        $this->capturedSql = $query;
                        $this->capturedBindings = $bindings;

                        return [(object) ['id' => 7]];
                    }
                };
                $query = (new Builder($connection, new MySqlGrammar($connection), new Processor))
                    ->from('candidates')
                    ->where('status', 'pending');

                $this->assertSame(7, (new BoundedMetricQuery)->get($query)[0]->id);
                $this->assertSame($expectedSql, $connection->capturedSql);
                $this->assertSame(['pending'], $connection->capturedBindings);
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. PrometheusMetricsExporter
    // ---------------------------------------------------------------------

    public function test_prometheus_metrics_exporter_helpers_and_lifecycle_parity(): void
    {
        $urlCases = [
            ['http://pushgateway.test:9091', 'nntmux'],
            ['https://metrics.internal:9091/prefix', 'nntmux-worker_01.prod~a'],
            ['http://127.0.0.1:9091', 'job with spaces/and:slashes?and=query#hash'],
            ['http://pushgateway.local', "utf8_é_🎉_nul_\0_end"],
            ['', ''],
            ['http://pushgateway.local', str_repeat('segment/job name~1_', 25)],
        ];

        foreach ($urlCases as [$baseUrl, $job]) {
            $expected = PrometheusMetricsExporter::formatPushgatewayUrlPhp($baseUrl, $job);
            $actual = PrometheusMetricsExporter::formatPushgatewayUrlNative($baseUrl, $job);
            $this->assertSame($expected, $actual, "base={$baseUrl} job=".var_export($job, true));

            foreach ([false, true] as $toggle) {
                PrometheusMetricsExporter::setUseRustFfi($toggle);
                $this->assertSame($expected, PrometheusMetricsExporter::formatPushgatewayUrl($baseUrl, $job));
            }
        }

        $durationCases = [
            [1_000_000_000, 1_000_000_000],
            [1_000_000_000, 1_250_000_000],
            [1_000_000_000, 2_500_000_000],
            [5_000_000_000, 4_000_000_000],
            [0, 123_456_789],
            [PHP_INT_MIN, PHP_INT_MAX],
            [PHP_INT_MAX, PHP_INT_MIN],
        ];

        foreach ($durationCases as [$startNs, $endNs]) {
            $expected = PrometheusMetricsExporter::computeExportDurationSecondsPhp($startNs, $endNs);
            $actual = PrometheusMetricsExporter::computeExportDurationSecondsNative($startNs, $endNs);
            $this->assertSame($expected, $actual, "start={$startNs} end={$endNs}");

            foreach ([false, true] as $toggle) {
                PrometheusMetricsExporter::setUseRustFfi($toggle);
                $this->assertSame($expected, PrometheusMetricsExporter::computeExportDurationSeconds($startNs, $endNs));
            }
        }

        $bodyCases = [
            ['', 500],
            ['text format parsing error', 500],
            [str_repeat('a', 500), 500],
            [str_repeat('b', 750), 500],
            [str_repeat('é', 600), 500],
            [str_repeat('🚀', 520), 500],
            ['mixed ASCII and हिंदी and 漢字 '.str_repeat('x', 600), 500],
            ["invalid_utf8_\xFF\xFE_".str_repeat('z', 600), 500],
            ['short', 0],
            ['short', -5],
            ['truncate to ten chars only', 10],
        ];

        foreach ($bodyCases as [$body, $maxChars]) {
            $expected = PrometheusMetricsExporter::truncateErrorBodyPhp($body, $maxChars);
            $actual = PrometheusMetricsExporter::truncateErrorBodyNative($body, $maxChars);
            $this->assertSame($expected, $actual, 'max='.$maxChars.' len='.\strlen($body));

            foreach ([false, true] as $toggle) {
                PrometheusMetricsExporter::setUseRustFfi($toggle);
                $this->assertSame($expected, PrometheusMetricsExporter::truncateErrorBody($body, $maxChars));
            }
        }

        config([
            'monitoring.pushgateway.url' => 'http://pushgateway.test:9091',
            'monitoring.pushgateway.job' => 'nntmux prod',
            'monitoring.pushgateway.timeout' => 5,
        ]);

        $collector = new class implements MetricsCollector
        {
            public function collect(): iterable
            {
                yield MetricFamily::gauge('nntmux_test_up', 'Test metric.')->add(1);
            }
        };

        foreach ([false, true] as $toggle) {
            PrometheusMetricsExporter::setUseRustFfi($toggle);
            Http::fake(['http://pushgateway.test:9091/metrics/job/nntmux%20prod' => Http::response('', 200)]);
            $exporter = new PrometheusMetricsExporter([$collector], new TextFormatter);
            $this->assertTrue($exporter->push());
            Http::assertSent(static fn (Request $req): bool => $req->method() === 'PUT'
                && $req->url() === 'http://pushgateway.test:9091/metrics/job/nntmux%20prod'
                && str_contains($req->body(), 'nntmux_test_up 1')
                && str_contains($req->body(), 'nntmux_metrics_export_duration_seconds '));
        }
    }

    // ---------------------------------------------------------------------
    // 3. DiskProbe
    // ---------------------------------------------------------------------

    public function test_disk_probe_bytes_rounding_and_threshold_evaluation_parity(): void
    {
        $byteValues = [
            0,
            1,
            134_217_728, // exact 0.125 GiB tie -> 0.13
            536_870_912, // 0.50 GiB
            1_073_741_824, // 1.00 GiB
            5_368_709_120, // 5.00 GiB
            10_737_418_240, // 10.00 GiB
            123_456_789_012,
            -134_217_728,
            INF,
        ];

        mt_srand(2006);
        for ($i = 0; $i < 250; $i++) {
            $byteValues[] = mt_rand(0, 500_000) * 10_737_418 + mt_rand(-5, 5);
        }

        foreach ($byteValues as $bytes) {
            $expected = DiskProbe::bytesToFreeGbPhp($bytes);
            $actual = DiskProbe::bytesToFreeGbNative($bytes);
            $this->assertSame($expected, $actual, 'bytes='.var_export($bytes, true));

            foreach ([false, true] as $toggle) {
                DiskProbe::setUseRustFfi($toggle);
                $this->assertSame($expected, DiskProbe::bytesToFreeGb($bytes));
            }
        }
        $this->assertTrue(is_nan(DiskProbe::bytesToFreeGbNative(NAN)));

        $thresholdCases = [
            ['/', 0.0, 5.0, 1.0],
            ['/', -0.0, 5.0, 1.0],
            ['/', -0.001, 5.0, 1.0],
            ['/var/lib/mysql', 0.125, 5.0, 1.0],
            ['/var/lib/mysql', 1.0, 5.0, 1.0],
            ['/var/lib/mysql', 1.01, 5.0, 1.0],
            ['/mnt/nzb', 5.0, 5.0, 1.0],
            ['/mnt/nzb', 5.01, 5.0, 1.0],
            ['/mnt/storage/usenet-spool-primary', 142.35, 10.0, 2.5],
            [str_repeat('/very/deep/mount/point', 15), 2.5, 10.0, 2.5],
            ['/', NAN, 5.0, 1.0],
        ];

        foreach ($thresholdCases as [$mount, $freeGb, $warnGb, $critGb]) {
            $expected = DiskProbe::evaluateThresholdsPhp($mount, $freeGb, $warnGb, $critGb);
            $actual = DiskProbe::evaluateThresholdsNative($mount, $freeGb, $warnGb, $critGb);
            self::assertProbeResultSame($expected, $actual, "mount={$mount} free=".var_export($freeGb, true));

            foreach ([false, true] as $toggle) {
                DiskProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, DiskProbe::evaluateThresholds($mount, $freeGb, $warnGb, $critGb));
            }
        }

        config([
            'status-probes.disk.mount_points' => [sys_get_temp_dir()],
            'status-probes.disk.warning_threshold_gb' => 0.0001,
            'status-probes.disk.critical_threshold_gb' => 0.00001,
        ]);
        $probe = new DiskProbe;
        DiskProbe::setUseRustFfi(false);
        $phpProbe = $probe->probe();
        DiskProbe::setUseRustFfi(true);
        $rustProbe = $probe->probe();
        $this->assertSame($phpProbe->ok, $rustProbe->ok);
        $this->assertSame($phpProbe->impact, $rustProbe->impact);
    }

    private static function assertProbeResultSame(ProbeResult $expected, ProbeResult $actual, string $message = ''): void
    {
        self::assertSame($expected->ok, $actual->ok, $message.' (ok)');
        self::assertSame($expected->responseTimeMs, $actual->responseTimeMs, $message.' (responseTimeMs)');
        self::assertSame($expected->impact, $actual->impact, $message.' (impact)');
        self::assertSame($expected->reason, $actual->reason, $message.' (reason)');
        self::assertSame($expected->metadata, $actual->metadata, $message.' (metadata)');
    }

    // ---------------------------------------------------------------------
    // 4. IncidentImpactEnum & IncidentStatusEnum
    // ---------------------------------------------------------------------

    public function test_incident_impact_and_status_enums_parity(): void
    {
        foreach (IncidentImpactEnum::cases() as $case) {
            $this->assertSame($case->labelPhp(), $case->labelNative(), $case->name);
            foreach ([false, true] as $toggle) {
                IncidentImpactEnum::setUseRustFfi($toggle);
                $this->assertSame($case->labelPhp(), $case->label(), $case->name);
            }
        }

        $this->assertSame(IncidentImpactEnum::valuesPhp(), IncidentImpactEnum::valuesNative());
        $this->assertSame(['none', 'minor', 'major', 'critical'], IncidentImpactEnum::valuesNative());
        IncidentImpactEnum::setUseRustFfi(true);
        $this->assertSame(['none', 'minor', 'major', 'critical'], IncidentImpactEnum::values());

        foreach (IncidentStatusEnum::cases() as $case) {
            $this->assertSame($case->labelPhp(), $case->labelNative(), $case->name);
            foreach ([false, true] as $toggle) {
                IncidentStatusEnum::setUseRustFfi($toggle);
                $this->assertSame($case->labelPhp(), $case->label(), $case->name);
            }
        }

        $this->assertSame(IncidentStatusEnum::valuesPhp(), IncidentStatusEnum::valuesNative());
        $this->assertSame(['investigating', 'identified', 'monitoring', 'resolved'], IncidentStatusEnum::valuesNative());
        IncidentStatusEnum::setUseRustFfi(true);
        $this->assertSame(['investigating', 'identified', 'monitoring', 'resolved'], IncidentStatusEnum::values());
    }

    // ---------------------------------------------------------------------
    // 5. Direct C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_overflow_alignment_and_buffer_contracts(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FULL_CDEF);
        $this->assertNotNull($ffi);

        $buf = $ffi->new('char[128]');
        $small = $ffi->new('char[4]');
        $doubleSlot = $ffi->new('double[1]');
        $int64Slot = $ffi->new('int64_t[1]');
        $sizeSlot = $ffi->new('size_t[1]');

        $rawBytes = $ffi->new('uint8_t[64]');
        $unalignedDouble = $ffi->cast('double*', FFI::addr($rawBytes[1]));
        $unalignedInt64 = $ffi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $unalignedSize = $ffi->cast('size_t*', FFI::addr($rawBytes[1]));

        // nntmux_bounded_metric_rewrite_sql
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_bounded_metric_rewrite_sql', 2, 'select 1', 8, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_bounded_metric_rewrite_sql', 0, null, 8, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_bounded_metric_rewrite_sql', 0, 'select 1', 8, null, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_bounded_metric_rewrite_sql', 1, 'select 1', 8, $small, 4));

        // nntmux_prometheus_exporter_pushgateway_url
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_pushgateway_url', null, 4, 'nntmux', 6, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_pushgateway_url', 'http://a', 8, null, 6, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_pushgateway_url', 'http://a', 8, 'nntmux', 6, null, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_prometheus_exporter_pushgateway_url', 'http://a', 8, 'nntmux', 6, $small, 4));

        // nntmux_prometheus_exporter_duration_seconds
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_duration_seconds', 100, 1_100_000_100, $doubleSlot));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_duration_seconds', 100, 200, null));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_duration_seconds', 100, 200, $unalignedDouble));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_duration_seconds', PHP_INT_MIN, PHP_INT_MAX, $doubleSlot));

        // nntmux_prometheus_exporter_truncate_body
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_truncate_body', null, 5, 500, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_prometheus_exporter_truncate_body', 'hello', 5, 500, null, 128));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_prometheus_exporter_truncate_body', "\xFF\xFE", 2, 500, $buf, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_prometheus_exporter_truncate_body', 'hello world', 11, 10, $small, 4));

        // nntmux_disk_probe_bytes_to_gb
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_disk_probe_bytes_to_gb', 1_073_741_824.0, $doubleSlot));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_bytes_to_gb', 1024.0, null));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_bytes_to_gb', 1024.0, $unalignedDouble));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_bytes_to_gb', INF, $doubleSlot));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_bytes_to_gb', NAN, $doubleSlot));

        // nntmux_disk_probe_evaluate
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_evaluate', null, 1, 10.0, 5.0, 1.0, $int64Slot, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_evaluate', '/', 1, 10.0, 5.0, 1.0, null, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_evaluate', '/', 1, 10.0, 5.0, 1.0, $unalignedInt64, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_evaluate', '/', 1, 10.0, 5.0, 1.0, $int64Slot, null, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_disk_probe_evaluate', '/', 1, NAN, 5.0, 1.0, $int64Slot, $buf, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_disk_probe_evaluate', '/', 1, 10.0, 5.0, 1.0, $int64Slot, $small, 4));

        // nntmux_incident_impact_label & nntmux_incident_impact_values_nul
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_incident_impact_label', null, 4, $buf, 128));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_incident_impact_label', 'unknown', 7, $buf, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_incident_impact_label', 'critical', 8, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_incident_impact_values_nul', null, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_incident_impact_values_nul', $unalignedSize, $buf, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_incident_impact_values_nul', $sizeSlot, $small, 4));

        // nntmux_incident_status_label & nntmux_incident_status_values_nul
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_incident_status_label', null, 4, $buf, 128));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_incident_status_label', 'unknown', 7, $buf, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_incident_status_label', 'investigating', 13, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_incident_status_values_nul', null, $buf, 128));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_incident_status_values_nul', $unalignedSize, $buf, 128));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_incident_status_values_nul', $sizeSlot, $small, 4));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertSame(
            'SET STATEMENT max_statement_time=2 FOR select 1',
            BoundedMetricQuery::rewriteSqlNative('select 1', true)
        );
        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(2000) */ 1',
            BoundedMetricQuery::rewriteSqlNative('select 1', false)
        );

        $this->assertSame(
            'http://pushgateway:9091/metrics/job/nntmux%20job',
            PrometheusMetricsExporter::formatPushgatewayUrlNative('http://pushgateway:9091', 'nntmux job')
        );
        $this->assertSame(0.5, PrometheusMetricsExporter::computeExportDurationSecondsNative(1_000_000_000, 1_500_000_000));
        $this->assertSame('abc', PrometheusMetricsExporter::truncateErrorBodyNative('abcdef', 3));

        $this->assertSame(10.0, DiskProbe::bytesToFreeGbNative(10_737_418_240));
        $verdict = DiskProbe::evaluateThresholdsNative('/data', 0.5, 5.0, 1.0);
        $this->assertFalse($verdict->ok);
        $this->assertSame(IncidentImpactEnum::Critical, $verdict->impact);

        $this->assertSame('Critical', IncidentImpactEnum::Critical->labelNative());
        $this->assertSame(['none', 'minor', 'major', 'critical'], IncidentImpactEnum::valuesNative());

        $this->assertSame('Investigating', IncidentStatusEnum::Investigating->labelNative());
        $this->assertSame(['investigating', 'identified', 'monitoring', 'resolved'], IncidentStatusEnum::valuesNative());
    }

    // ---------------------------------------------------------------------
    // 6. Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_BOUNDED_METRIC_EXPORTER_DISK_INCIDENT_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_BOUNDED_METRIC_EXPORTER_DISK_INCIDENT_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longBody = str_repeat('Prometheus Pushgateway error line with details and UTF-8 Context: é ', 12);

        $workloads = [
            'BoundedMetricQuery::rewriteSql' => [
                BoundedMetricQuery::class,
                static fn () => BoundedMetricQuery::rewriteSql('select * from `candidates` where `status` = ?', true),
                static fn () => BoundedMetricQuery::rewriteSql('SELECT `id`, `name`, `created_at` from `releases` where `nzbstatus` = 1', false),
            ],
            'PrometheusMetricsExporter::formatPushgatewayUrl' => [
                PrometheusMetricsExporter::class,
                static fn () => PrometheusMetricsExporter::formatPushgatewayUrl('http://pushgateway:9091', 'nntmux'),
                static fn () => PrometheusMetricsExporter::formatPushgatewayUrl('https://pushgateway.monitoring.svc.cluster.local:9091', 'nntmux prod/worker 01'),
            ],
            'PrometheusMetricsExporter::computeExportDurationSeconds' => [
                PrometheusMetricsExporter::class,
                static fn () => PrometheusMetricsExporter::computeExportDurationSeconds(1_000_000_000, 1_125_000_000),
                static fn () => PrometheusMetricsExporter::computeExportDurationSeconds(1_000_000_000, 9_876_543_210),
            ],
            'PrometheusMetricsExporter::truncateErrorBody' => [
                PrometheusMetricsExporter::class,
                static fn () => PrometheusMetricsExporter::truncateErrorBody('text format parsing error in line 42', 500),
                static fn () => PrometheusMetricsExporter::truncateErrorBody($longBody, 500),
            ],
            'DiskProbe::bytesToFreeGb' => [
                DiskProbe::class,
                static fn () => DiskProbe::bytesToFreeGb(107_374_182_400),
                static fn () => DiskProbe::bytesToFreeGb(134_217_728),
            ],
            'DiskProbe::evaluateThresholds' => [
                DiskProbe::class,
                static fn () => DiskProbe::evaluateThresholds('/', 48.25, 5.0, 1.0)->reason,
                static fn () => DiskProbe::evaluateThresholds('/var/lib/mysql/data', 0.65, 5.0, 1.0)->reason,
            ],
            'IncidentImpactEnum::label' => [
                IncidentImpactEnum::class,
                static fn () => IncidentImpactEnum::Minor->label(),
                static fn () => IncidentImpactEnum::Critical->label(),
            ],
            'IncidentImpactEnum::values' => [
                IncidentImpactEnum::class,
                static fn () => IncidentImpactEnum::values(),
                static fn () => IncidentImpactEnum::values(),
            ],
            'IncidentStatusEnum::label' => [
                IncidentStatusEnum::class,
                static fn () => IncidentStatusEnum::Investigating->label(),
                static fn () => IncidentStatusEnum::Resolved->label(),
            ],
            'IncidentStatusEnum::values' => [
                IncidentStatusEnum::class,
                static fn () => IncidentStatusEnum::values(),
                static fn () => IncidentStatusEnum::values(),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2006 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-52s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $toggle) {
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
