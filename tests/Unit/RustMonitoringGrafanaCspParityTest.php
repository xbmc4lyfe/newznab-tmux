<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\MeasureApiPerformance;
use App\Services\Monitoring\GrafanaEmbedService;
use App\Services\Monitoring\GrafanaJwtIssuer;
use App\Services\Monitoring\TmuxMetricsSnapshot;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

/**
 * Wave 16 Slice 6 parity and paired benchmark suite for `TmuxMetricsSnapshot`,
 * `GrafanaEmbedService`, `GrafanaJwtIssuer`, `ContentSecurityPolicy`, and
 * `MeasureApiPerformance` (`rust/pipeline-ffi/src/media.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_MONITORING_GRAFANA_CSP_BENCH=1 php artisan test --compact tests/Unit/RustMonitoringGrafanaCspParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 across 3 reps):
 * - `tmuxAgeAndStale`:       common 0.35x/0.40x, 0.34x/0.40x, 0.37x/0.40x | uncommon 0.36x/0.40x, 0.35x/0.40x, 0.36x/0.40x
 * - `tmuxNumericValues`:     common 0.16x/0.18x, 0.17x/0.19x, 0.17x/0.16x | uncommon 0.18x/0.18x, 0.18x/0.20x, 0.18x/0.21x
 * - `tmuxConnections`:       common 0.19x/0.27x, 0.19x/0.20x, 0.18x/0.19x | uncommon 0.17x/0.10x, 0.18x/0.19x, 0.18x/0.18x
 * - `grafanaDashboardUrl`:   common 0.59x/0.57x, 0.54x/0.50x, 0.54x/0.58x | uncommon 0.55x/0.54x, 0.55x/0.57x, 0.56x/0.61x
 * - `grafanaPanelUrl`:       common 0.54x/0.58x, 0.55x/0.54x, 0.54x/0.54x | uncommon 0.57x/0.57x, 0.57x/0.57x, 0.57x/0.61x
 * - `grafanaFrameOrigin`:    common 0.35x/0.34x, 0.34x/0.29x, 0.33x/0.43x | uncommon 1.14x/1.20x, 1.14x/1.20x, 1.15x/1.20x
 * - `jwtBase64Url`:          common 0.46x/0.46x, 0.46x/0.50x, 0.46x/0.50x | uncommon 0.53x/0.54x, 0.51x/0.52x, 0.52x/0.52x
 * - `jwtSigningInput`:       common 0.86x/0.91x, 0.85x/0.86x, 0.85x/0.86x | uncommon 0.78x/0.78x, 0.80x/0.81x, 0.80x/0.77x
 * - `cspBuildHeader`:        common 0.72x/0.75x, 0.72x/0.73x, 0.72x/0.73x | uncommon 0.91x/0.92x, 0.90x/0.86x, 0.91x/0.92x
 * - `cspParseViteHotUrl`:    common 0.75x/0.80x, 0.76x/0.80x, 0.76x/0.80x | uncommon 0.79x/0.80x, 0.78x/0.80x, 0.77x/0.80x
 * - `apiPerfElapsedMs`:      common 0.34x/0.43x, 0.34x/0.34x, 0.34x/0.34x | uncommon 0.36x/0.50x, 0.33x/0.34x, 0.33x/0.34x
 * - `apiPerfServerTiming`:   common 0.48x/0.54x, 0.51x/0.54x, 0.47x/0.50x | uncommon 0.62x/0.60x, 0.63x/0.63x, 0.61x/0.67x
 * - `apiPerfClassifySlow`:   common 0.48x/0.50x, 0.47x/0.50x, 0.50x/0.50x | uncommon 0.48x/0.40x, 0.50x/0.50x, 0.54x/0.60x
 *
 * All methods are sub-microsecond scalar/small-string operations or require JSON
 * marshaling across the FFI boundary, so single-call FFI overhead exceeds the computation
 * cost. Per `.ai/rules/rust-performance.md`, `$useRustFfi = false` is retained as the
 * production default across all five classes while keeping `*Native` and `*Php` methods
 * tested for 100% parity.
 */
final class RustMonitoringGrafanaCspParityTest extends TestCase
{
    private const string FULL_CDEF = TmuxMetricsSnapshot::FFI_CDEF."\n"
        .GrafanaEmbedService::FFI_CDEF."\n"
        .GrafanaJwtIssuer::FFI_CDEF."\n"
        .ContentSecurityPolicy::FFI_CDEF."\n"
        .MeasureApiPerformance::FFI_CDEF;

    protected function tearDown(): void
    {
        TmuxMetricsSnapshot::setUseRustFfi(false);
        GrafanaEmbedService::setUseRustFfi(false);
        GrafanaJwtIssuer::setUseRustFfi(false);
        ContentSecurityPolicy::setUseRustFfi(false);
        MeasureApiPerformance::setUseRustFfi(false);
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function bare(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('media', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline media domain FFI to be available.');

        return $ffi;
    }

    public function test_tmux_metrics_snapshot_normalization_and_age_staleness_match_php(): void
    {
        self::ffi();
        $snapshot = self::bare(TmuxMetricsSnapshot::class);

        $ageCases = [
            [1_700_000_000, 1_700_000_000, 900],
            [1_700_000_000, 1_700_000_120, 900],
            [1_700_000_000, 1_700_000_900, 900],
            [1_700_000_000, 1_700_000_901, 900],
            [1_700_000_500, 1_700_000_000, 900],
            [1_700_000_000, 1_700_000_001, 0],
            [1_700_000_000, 1_700_000_000, -10],
            [0, 3600, 1800],
        ];

        foreach ($ageCases as [$collectedAt, $now, $maxAge]) {
            $expectedAge = $snapshot->ageSecondsPhp($collectedAt, $now);
            $expectedStale = $snapshot->isStalePhp($collectedAt, $maxAge, $now);

            self::assertSame($expectedAge, $snapshot->ageSecondsNative($collectedAt, $now));
            self::assertSame($expectedStale, $snapshot->isStaleNative($collectedAt, $maxAge, $now));

            foreach ([false, true] as $toggle) {
                TmuxMetricsSnapshot::setUseRustFfi($toggle);
                self::assertSame($expectedAge, $snapshot->ageSeconds($collectedAt, $now));
                self::assertSame($expectedStale, $snapshot->isStale($collectedAt, $maxAge, $now));
            }
        }

        $numericCases = [
            null,
            'not-an-array',
            [],
            [10, 20, 30],
            [
                'processnfo' => 12,
                'releases' => '345',
                'negative_int' => -42,
                'negative_str' => ' -19 ',
                'plus_str' => '+77',
                'float_val' => 0.5,
                'whole_float' => 1.0,
                'float_str' => '12.75',
                'sci_str' => '1e3',
                'leading_dot' => '.25',
                'label' => 'not a number',
                'comma_formatted' => '1,000',
                'empty_str' => '',
                'ws_only' => " \t\n ",
                'bool_true' => true,
                'bool_false' => false,
                'null_val' => null,
                'nested_arr' => ['a' => 1],
                0 => 999,
                1 => '888',
            ],
        ];

        foreach ($numericCases as $idx => $values) {
            $expectedNum = $snapshot->numericValuesPhp($values);
            $expectedTimers = $snapshot->queryTimersPhp($values);

            self::assertSame($expectedNum, $snapshot->numericValuesNative($values), "numeric case {$idx}");
            self::assertSame($expectedTimers, $snapshot->queryTimersNative($values), "timers case {$idx}");

            foreach ([false, true] as $toggle) {
                TmuxMetricsSnapshot::setUseRustFfi($toggle);
                self::assertSame($expectedNum, $snapshot->numericValues($values));
                self::assertSame($expectedTimers, $snapshot->queryTimers($values));
            }
        }

        $connectionCases = [
            null,
            'invalid',
            [],
            [['active' => 1, 'total' => 2]],
            [
                'primary' => ['active' => 4, 'total' => 20],
                'backup' => ['active' => '3', 'total' => '10', 'extra' => 'ignored'],
                'missing_fields' => [],
                'partial_active' => ['active' => 7],
                'partial_total' => ['total' => 15],
                'float_and_bool' => ['active' => 2.9, 'total' => true],
                'null_and_bad_str' => ['active' => null, 'total' => 'abc'],
                'not_array_entry' => 'skip-me',
                0 => ['active' => 5, 'total' => 5],
            ],
        ];

        foreach ($connectionCases as $idx => $connections) {
            $expectedConn = $snapshot->connectionsPhp($connections);
            self::assertSame($expectedConn, $snapshot->connectionsNative($connections), "connections case {$idx}");

            foreach ([false, true] as $toggle) {
                TmuxMetricsSnapshot::setUseRustFfi($toggle);
                self::assertSame($expectedConn, $snapshot->connections($connections));
            }
        }
    }

    public function test_grafana_embed_service_urls_cookie_path_and_frame_origin_match_php(): void
    {
        self::ffi();
        $service = self::bare(GrafanaEmbedService::class);

        $embedCases = [
            ['/grafana', 'nntmux-host', 'nntmux-host', 1, 'now-6h', '1m'],
            ['/grafana/', 'nntmux-processing', 'nntmux-processing', 1, 'now-6h', '1m'],
            ['https://grafana.example:3000/sub///', 'uid_1~test', 'slug with spaces & symbols', 42, 'now-24h', '30s'],
            ['', 'custom-uid', 'custom~slug', 2, '2026-01-01 00:00:00', '5m'],
        ];

        foreach ($embedCases as [$baseUrl, $uid, $slug, $orgId, $from, $refresh]) {
            foreach ([true, false] as $kiosk) {
                $expectedDash = $service->buildDashboardEmbedUrlPhp($baseUrl, $uid, $slug, $orgId, $from, $refresh, $kiosk);
                self::assertSame(
                    $expectedDash,
                    $service->buildDashboardEmbedUrlNative($baseUrl, $uid, $slug, $orgId, $from, $refresh, $kiosk)
                );
                foreach ([false, true] as $toggle) {
                    GrafanaEmbedService::setUseRustFfi($toggle);
                    self::assertSame(
                        $expectedDash,
                        $service->buildDashboardEmbedUrl($baseUrl, $uid, $slug, $orgId, $from, $refresh, $kiosk)
                    );
                }
            }

            foreach ([1, 2, 14, 0, -1] as $panelId) {
                $expectedPanel = $service->buildPanelEmbedUrlPhp($baseUrl, $uid, $slug, $orgId, $from, $refresh, $panelId);
                self::assertSame(
                    $expectedPanel,
                    $service->buildPanelEmbedUrlNative($baseUrl, $uid, $slug, $orgId, $from, $refresh, $panelId)
                );
                foreach ([false, true] as $toggle) {
                    GrafanaEmbedService::setUseRustFfi($toggle);
                    self::assertSame(
                        $expectedPanel,
                        $service->buildPanelEmbedUrl($baseUrl, $uid, $slug, $orgId, $from, $refresh, $panelId)
                    );
                }
            }
        }

        $cookieBases = [
            '/grafana',
            '/grafana/',
            '/grafana///',
            '/',
            '',
            'https://grafana.example:3000/grafana',
            'https://grafana.example:3000/grafana/',
            'https://grafana.example:3000',
            'https://grafana.example:3000/',
            '/nested/monitoring/grafana/',
            'https://grafana.example/path?query=1',
            '//protocol-relative/grafana',
        ];

        foreach ($cookieBases as $base) {
            $expectedCookie = $service->formatCookiePathPhp($base);
            self::assertSame($expectedCookie, $service->formatCookiePathNative($base), "cookie base={$base}");
            foreach ([false, true] as $toggle) {
                GrafanaEmbedService::setUseRustFfi($toggle);
                self::assertSame($expectedCookie, $service->formatCookiePath($base));
            }
        }

        $originCases = [
            ['/grafana', 'https://nntmux.example'],
            ['https://nntmux.example/grafana', 'https://nntmux.example'],
            ['HTTPS://NNTMUX.EXAMPLE/grafana', 'https://nntmux.example/app'],
            ['https://grafana.example/grafana', 'https://nntmux.example'],
            ['HTTPS://Grafana.Example:3000/grafana', 'https://nntmux.example'],
            ['http://nntmux.example:3000', 'http://nntmux.example'],
            ['http://nntmux.example:3000/d', 'http://nntmux.example:3000/app'],
            ['https://[::1]:3000/grafana', 'https://[::1]:8080'],
            ['https://[::1]:3000/grafana', 'https://[::1]:3000'],
            [null, 'https://nntmux.example'],
            ['https://grafana.example/grafana', null],
            ['', ''],
            ['invalid-url', 'https://nntmux.example'],
            ['http://example.com:65536/grafana', 'https://nntmux.example'],
            ['http://user:pass@grafana.example:3000/grafana', 'https://nntmux.example'],
        ];

        foreach ($originCases as [$grafanaUrl, $appUrl]) {
            $expectedOrigin = GrafanaEmbedService::frameOriginPhp($grafanaUrl, $appUrl);
            self::assertSame($expectedOrigin, GrafanaEmbedService::frameOriginNative($grafanaUrl, $appUrl));
            foreach ([false, true] as $toggle) {
                GrafanaEmbedService::setUseRustFfi($toggle);
                self::assertSame($expectedOrigin, GrafanaEmbedService::frameOrigin($grafanaUrl, $appUrl));
            }
        }
    }

    public function test_grafana_jwt_issuer_base64url_claims_and_signing_input_match_php(): void
    {
        self::ffi();
        $issuer = new GrafanaJwtIssuer;

        $b64Inputs = [
            '',
            'a',
            'ab',
            'abc',
            'abcd',
            '{"alg":"RS256","typ":"JWT"}',
            "\x00\xff\xfe\xfd\xfc\xfb\xfa\x80\x7f",
            random_bytes(256),
        ];

        $random = new Randomizer(new Mt19937(16006));
        for ($i = 0; $i < 64; $i++) {
            $b64Inputs[] = $random->getBytes($random->getInt(1, 300));
        }

        foreach ($b64Inputs as $raw) {
            $expected = $issuer->base64UrlPhp($raw);
            self::assertSame($expected, $issuer->base64UrlNative($raw));
            foreach ([false, true] as $toggle) {
                GrafanaJwtIssuer::setUseRustFfi($toggle);
                self::assertSame($expected, $issuer->base64Url($raw));
            }
        }

        $jwtCases = [
            ['admin_user', 'admin@example.test', 'Viewer', 'nntmux', 'grafana', 1_700_000_000, 1_700_000_600, '00000000-0000-4000-8000-000000000001'],
            ['user"quote\\slash/ctrl'."\t\n\x01", 'user+tag@example.test', 'Admin', 'https://nntmux.example/iss', 'grafana/aud', 1_720_000_000, 1_720_000_900, 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
            // Non-ASCII fallback case
            ['étoile_user', 'étoile@example.test', 'Editor', 'nntmux', 'grafana', 1_700_000_000, 1_700_000_900, '11111111-2222-4333-8444-555555555555'],
        ];

        foreach ($jwtCases as [$u, $e, $r, $iss, $aud, $now, $exp, $jti]) {
            $expectedClaims = $issuer->formatClaimsJsonPhp($u, $e, $r, $iss, $aud, $now, $exp, $jti);
            $expectedSigning = $issuer->buildSigningInputPhp($u, $e, $r, $iss, $aud, $now, $exp, $jti);

            self::assertSame($expectedClaims, $issuer->formatClaimsJsonNative($u, $e, $r, $iss, $aud, $now, $exp, $jti));
            self::assertSame($expectedSigning, $issuer->buildSigningInputNative($u, $e, $r, $iss, $aud, $now, $exp, $jti));

            foreach ([false, true] as $toggle) {
                GrafanaJwtIssuer::setUseRustFfi($toggle);
                self::assertSame($expectedClaims, $issuer->formatClaimsJson($u, $e, $r, $iss, $aud, $now, $exp, $jti));
                self::assertSame($expectedSigning, $issuer->buildSigningInput($u, $e, $r, $iss, $aud, $now, $exp, $jti));
            }
        }
    }

    public function test_content_security_policy_header_and_vite_hot_url_match_php(): void
    {
        self::ffi();
        $middleware = new ContentSecurityPolicy;

        $hotUrls = [
            'http://localhost:5173',
            "  http://localhost:5173 \n",
            'https://localhost:5173',
            'http://127.0.0.1:5173',
            'https://127.0.0.1:5174/sub',
            'http://[::1]:5173',
            'https://[::1]:5173',
            'http://localhost',
            'https://assets.example.com:5173',
            'ws://localhost:5173',
            'ftp://localhost:5173',
            'http://192.168.1.10:5173',
            '',
            '   ',
            'not-a-url',
            'http://:5173',
        ];

        foreach ($hotUrls as $rawHot) {
            $expectedHot = $middleware->parseViteHotUrlPhp($rawHot);
            self::assertSame($expectedHot, $middleware->parseViteHotUrlNative($rawHot), "hotUrl={$rawHot}");
            foreach ([false, true] as $toggle) {
                ContentSecurityPolicy::setUseRustFfi($toggle);
                self::assertSame($expectedHot, $middleware->parseViteHotUrl($rawHot));
            }
        }

        $nonces = [
            'a1b2c3d4e5f6g7h8i9j0==',
            base64_encode(str_repeat("\x42", 16)),
        ];
        $viteOptions = [
            null,
            ['asset' => 'http://localhost:5173', 'websocket' => 'ws://localhost:5173'],
            ['asset' => 'https://[::1]:5173', 'websocket' => 'wss://[::1]:5173'],
        ];
        $grafanaOptions = [
            null,
            '',
            'https://grafana.example:3000',
            'http://monitoring.internal:8080',
        ];

        foreach ($nonces as $nonce) {
            foreach ($viteOptions as $vite) {
                foreach ($grafanaOptions as $grafana) {
                    $expectedCsp = $middleware->buildCspHeaderPhp($nonce, $vite, $grafana);
                    self::assertSame($expectedCsp, $middleware->buildCspHeaderNative($nonce, $vite, $grafana));
                    foreach ([false, true] as $toggle) {
                        ContentSecurityPolicy::setUseRustFfi($toggle);
                        self::assertSame($expectedCsp, $middleware->buildCspHeader($nonce, $vite, $grafana));
                    }
                }
            }
        }
    }

    public function test_measure_api_performance_helpers_match_php(): void
    {
        self::ffi();
        $middleware = new MeasureApiPerformance;

        $nsCases = [
            [1_000_000_000, 1_000_000_000],
            [1_000_000_000, 1_012_345_678],
            [1_000_000_000, 1_000_005_000],
            [1_000_000_000, 1_000_015_000],
            [1_000_000_000, 1_000_025_000],
            [1_000_000_000, 1_000_125_000],
            [1_000_000_000, 2_500_789_123],
            [5_000_000_000, 4_987_654_321],
        ];

        foreach ($nsCases as [$start, $end]) {
            $expectedElapsed = $middleware->elapsedMillisecondsPhp($start, $end);
            $actualElapsed = $middleware->elapsedMillisecondsNative($start, $end);
            self::assertEqualsWithDelta($expectedElapsed['raw_ms'], $actualElapsed['raw_ms'], 1e-12);
            self::assertSame($expectedElapsed['rounded_ms'], $actualElapsed['rounded_ms']);

            foreach ([false, true] as $toggle) {
                MeasureApiPerformance::setUseRustFfi($toggle);
                $dispatched = $middleware->elapsedMilliseconds($start, $end);
                self::assertSame($expectedElapsed['rounded_ms'], $dispatched['rounded_ms']);
            }
        }

        $timingCases = [
            [0.0, 0.0, 0],
            [-0.0, -0.0, 0],
            [12.3456, 3.4, 5],
            [0.005, 0.015, 1],
            [0.125, 0.375, 12],
            [0.625, 0.875, 42],
            [99.995, 100.0, 100],
            [1234.5678, 567.891, 250],
        ];

        foreach ($timingCases as [$dur, $qDur, $qCount]) {
            $expectedHeader = $middleware->formatServerTimingHeaderPhp($dur, $qDur, $qCount);
            self::assertSame($expectedHeader, $middleware->formatServerTimingHeaderNative($dur, $qDur, $qCount));
            foreach ([false, true] as $toggle) {
                MeasureApiPerformance::setUseRustFfi($toggle);
                self::assertSame($expectedHeader, $middleware->formatServerTimingHeader($dur, $qDur, $qCount));
            }
        }

        $classifyCases = [
            [100.0, 50.0, 500.0, 250.0],
            [500.0, 50.0, 500.0, 250.0],
            [100.0, 250.0, 500.0, 250.0],
            [600.0, 300.0, 500.0, 250.0],
            [600.0, 300.0, 0.0, 0.0],
            [NAN, 100.0, 500.0, 250.0],
            [100.0, INF, 500.0, 250.0],
        ];

        foreach ($classifyCases as [$d, $q, $td, $tq]) {
            $expectedClass = $middleware->classifySlowRequestPhp($d, $q, $td, $tq);
            self::assertSame($expectedClass, $middleware->classifySlowRequestNative($d, $q, $td, $tq));
            foreach ([false, true] as $toggle) {
                MeasureApiPerformance::setUseRustFfi($toggle);
                self::assertSame($expectedClass, $middleware->classifySlowRequest($d, $q, $td, $tq));
            }
        }

        foreach ([-0.5, 0.0, 0.000001, 0.01, 0.25, 0.5, 1.0, 2.5, NAN, INF, -INF] as $rate) {
            $expectedThresh = $middleware->sampleThresholdPhp($rate);
            self::assertSame($expectedThresh, $middleware->sampleThresholdNative($rate));
            foreach ([false, true] as $toggle) {
                MeasureApiPerformance::setUseRustFfi($toggle);
                self::assertSame($expectedThresh, $middleware->sampleThreshold($rate));
            }
        }
    }

    public function test_direct_c_abi_null_overflow_and_small_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[4]');
        $staleOut = $ffi->new('int32_t');

        // Null output pointer and i64 subtraction overflow on age/staleness
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_snapshot_age_and_stale(100, 200, 60, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_snapshot_age_and_stale(PHP_INT_MIN, PHP_INT_MAX, 60, FFI::addr($staleOut)));

        // Small buffer negative required capacity on Grafana embed URL, base64url, CSP, and Server-Timing
        // @phpstan-ignore method.notFound
        $needUrl = $ffi->nntmux_grafana_embed_url('/grafana', 8, 'host', 4, 'host', 4, 1, 'now-6h', 6, '1m', 2, 0, 1, 0, $small, 4);
        self::assertLessThan(-4, $needUrl);

        // @phpstan-ignore method.notFound
        $needB64 = $ffi->nntmux_grafana_jwt_base64url_encode('{"alg":"RS256","typ":"JWT"}', 27, $small, 4);
        self::assertLessThan(-4, $needB64);

        // @phpstan-ignore method.notFound
        $needCsp = $ffi->nntmux_csp_build_header('nonce123', 8, '', 0, '', 0, '', 0, $small, 4);
        self::assertLessThan(-4, $needCsp);

        // @phpstan-ignore method.notFound
        $needTiming = $ffi->nntmux_api_perf_format_server_timing(12.34, 5.67, 3, $small, 4);
        self::assertLessThan(-4, $needTiming);

        // Non-finite and overflow checks on API performance helpers
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_perf_format_server_timing(NAN, 1.0, 1, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_perf_elapsed_ms(100, 200, null, null));
        $rawOut = $ffi->new('double');
        $roundOut = $ffi->new('double');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_perf_elapsed_ms(PHP_INT_MIN, PHP_INT_MAX, FFI::addr($rawOut), FFI::addr($roundOut)));
    }

    /**
     * Run with:
     * NNTMUX_MONITORING_GRAFANA_CSP_BENCH=1 php artisan test --compact tests/Unit/RustMonitoringGrafanaCspParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_MONITORING_GRAFANA_CSP_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_MONITORING_GRAFANA_CSP_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $snapshot = self::bare(TmuxMetricsSnapshot::class);
        $grafana = self::bare(GrafanaEmbedService::class);
        $jwt = new GrafanaJwtIssuer;
        $csp = new ContentSecurityPolicy;
        $apiPerf = new MeasureApiPerformance;

        $countsCommon = [
            'processnfo' => 42,
            'processrenames' => 15,
            'collections_table' => 700,
            'binaries_table' => 4500,
            'releases' => 125000,
            'total_work' => 57,
            'label' => 'skip',
        ];
        $countsUncommon = [];
        for ($i = 0; $i < 32; $i++) {
            $countsUncommon["metric_counter_{$i}"] = ($i % 3 === 0) ? (string) ($i * 100) : ($i * 42);
        }
        $countsUncommon['non_numeric_label'] = 'not a number';

        $connCommon = [
            'primary' => ['active' => 4, 'total' => 20],
        ];
        $connUncommon = [
            'primary' => ['active' => 18, 'total' => 30],
            'backup_eu' => ['active' => '6', 'total' => '20'],
            'backup_us' => ['active' => 2, 'total' => 15],
            'fill_1' => ['active' => 0, 'total' => 10],
            'fill_2' => ['active' => 1, 'total' => 10],
        ];

        $sigCommon = str_repeat("\x4a\x9f\x12\x8c", 64);
        $sigUncommon = str_repeat("\xde\xad\xbe\xef\x01\x23\x45\x67", 128);

        $viteCommon = null;
        $viteUncommon = ['asset' => 'http://localhost:5173', 'websocket' => 'ws://localhost:5173'];

        $workloads = [
            'tmuxAgeAndStale/common' => [
                static fn () => $snapshot->isStalePhp(1_700_000_000, 900, 1_700_000_120),
                static fn () => $snapshot->isStaleNative(1_700_000_000, 900, 1_700_000_120),
            ],
            'tmuxAgeAndStale/uncommon' => [
                static fn () => $snapshot->isStalePhp(1_700_000_000, 900, 1_700_003_600),
                static fn () => $snapshot->isStaleNative(1_700_000_000, 900, 1_700_003_600),
            ],
            'tmuxNumericValues/common' => [
                static fn () => $snapshot->numericValuesPhp($countsCommon),
                static fn () => $snapshot->numericValuesNative($countsCommon),
            ],
            'tmuxNumericValues/uncommon' => [
                static fn () => $snapshot->numericValuesPhp($countsUncommon),
                static fn () => $snapshot->numericValuesNative($countsUncommon),
            ],
            'tmuxConnections/common' => [
                static fn () => $snapshot->connectionsPhp($connCommon),
                static fn () => $snapshot->connectionsNative($connCommon),
            ],
            'tmuxConnections/uncommon' => [
                static fn () => $snapshot->connectionsPhp($connUncommon),
                static fn () => $snapshot->connectionsNative($connUncommon),
            ],
            'grafanaDashboardUrl/common' => [
                static fn () => $grafana->buildDashboardEmbedUrlPhp('/grafana', 'nntmux-host', 'nntmux-host', 1, 'now-6h', '1m', true),
                static fn () => $grafana->buildDashboardEmbedUrlNative('/grafana', 'nntmux-host', 'nntmux-host', 1, 'now-6h', '1m', true),
            ],
            'grafanaDashboardUrl/uncommon' => [
                static fn () => $grafana->buildDashboardEmbedUrlPhp('https://grafana.monitoring.example:3000/grafana/', 'nntmux-processing-detailed', 'nntmux processing & queues', 42, 'now-24h', '30s', false),
                static fn () => $grafana->buildDashboardEmbedUrlNative('https://grafana.monitoring.example:3000/grafana/', 'nntmux-processing-detailed', 'nntmux processing & queues', 42, 'now-24h', '30s', false),
            ],
            'grafanaPanelUrl/common' => [
                static fn () => $grafana->buildPanelEmbedUrlPhp('/grafana', 'nntmux-processing', 'nntmux-processing', 1, 'now-6h', '1m', 2),
                static fn () => $grafana->buildPanelEmbedUrlNative('/grafana', 'nntmux-processing', 'nntmux-processing', 1, 'now-6h', '1m', 2),
            ],
            'grafanaPanelUrl/uncommon' => [
                static fn () => $grafana->buildPanelEmbedUrlPhp('https://grafana.monitoring.example:3000/grafana/', 'nntmux-host-extended', 'nntmux host & network metrics', 7, 'now-7d', '5m', 14),
                static fn () => $grafana->buildPanelEmbedUrlNative('https://grafana.monitoring.example:3000/grafana/', 'nntmux-host-extended', 'nntmux host & network metrics', 7, 'now-7d', '5m', 14),
            ],
            'grafanaFrameOrigin/common' => [
                static fn () => GrafanaEmbedService::frameOriginPhp('/grafana', 'https://nntmux.example'),
                static fn () => GrafanaEmbedService::frameOriginNative('/grafana', 'https://nntmux.example'),
            ],
            'grafanaFrameOrigin/uncommon' => [
                static fn () => GrafanaEmbedService::frameOriginPhp('https://Grafana.Monitoring.Example:3000/grafana/d/host', 'https://nntmux.example:8443/admin'),
                static fn () => GrafanaEmbedService::frameOriginNative('https://Grafana.Monitoring.Example:3000/grafana/d/host', 'https://nntmux.example:8443/admin'),
            ],
            'jwtBase64Url/common' => [
                static fn () => $jwt->base64UrlPhp($sigCommon),
                static fn () => $jwt->base64UrlNative($sigCommon),
            ],
            'jwtBase64Url/uncommon' => [
                static fn () => $jwt->base64UrlPhp($sigUncommon),
                static fn () => $jwt->base64UrlNative($sigUncommon),
            ],
            'jwtSigningInput/common' => [
                static fn () => $jwt->buildSigningInputPhp('admin_user', 'admin@example.test', 'Viewer', 'nntmux', 'grafana', 1_700_000_000, 1_700_000_900, '00000000-0000-4000-8000-000000000001'),
                static fn () => $jwt->buildSigningInputNative('admin_user', 'admin@example.test', 'Viewer', 'nntmux', 'grafana', 1_700_000_000, 1_700_000_900, '00000000-0000-4000-8000-000000000001'),
            ],
            'jwtSigningInput/uncommon' => [
                static fn () => $jwt->buildSigningInputPhp('monitoring_operator_account_long_username', 'monitoring.operator+alerts@sub.example.test', 'Editor', 'https://nntmux.example/auth/jwt', 'https://grafana.example/login/jwt', 1_700_000_000, 1_700_003_600, 'f47ac10b-58cc-4372-a567-0e02b2c3d479'),
                static fn () => $jwt->buildSigningInputNative('monitoring_operator_account_long_username', 'monitoring.operator+alerts@sub.example.test', 'Editor', 'https://nntmux.example/auth/jwt', 'https://grafana.example/login/jwt', 1_700_000_000, 1_700_003_600, 'f47ac10b-58cc-4372-a567-0e02b2c3d479'),
            ],
            'cspBuildHeader/common' => [
                static fn () => $csp->buildCspHeaderPhp('a1b2c3d4e5f60718293a4b==', $viteCommon, null),
                static fn () => $csp->buildCspHeaderNative('a1b2c3d4e5f60718293a4b==', $viteCommon, null),
            ],
            'cspBuildHeader/uncommon' => [
                static fn () => $csp->buildCspHeaderPhp('a1b2c3d4e5f60718293a4b==', $viteUncommon, 'https://grafana.example:3000'),
                static fn () => $csp->buildCspHeaderNative('a1b2c3d4e5f60718293a4b==', $viteUncommon, 'https://grafana.example:3000'),
            ],
            'cspParseViteHotUrl/common' => [
                static fn () => $csp->parseViteHotUrlPhp("http://localhost:5173\n"),
                static fn () => $csp->parseViteHotUrlNative("http://localhost:5173\n"),
            ],
            'cspParseViteHotUrl/uncommon' => [
                static fn () => $csp->parseViteHotUrlPhp("  https://[::1]:5173  \r\n"),
                static fn () => $csp->parseViteHotUrlNative("  https://[::1]:5173  \r\n"),
            ],
            'apiPerfElapsedMs/common' => [
                static fn () => $apiPerf->elapsedMillisecondsPhp(1_000_000_000, 1_014_250_000),
                static fn () => $apiPerf->elapsedMillisecondsNative(1_000_000_000, 1_014_250_000),
            ],
            'apiPerfElapsedMs/uncommon' => [
                static fn () => $apiPerf->elapsedMillisecondsPhp(1_000_000_000, 4_876_543_210),
                static fn () => $apiPerf->elapsedMillisecondsNative(1_000_000_000, 4_876_543_210),
            ],
            'apiPerfServerTiming/common' => [
                static fn () => $apiPerf->formatServerTimingHeaderPhp(14.25, 3.80, 4),
                static fn () => $apiPerf->formatServerTimingHeaderNative(14.25, 3.80, 4),
            ],
            'apiPerfServerTiming/uncommon' => [
                static fn () => $apiPerf->formatServerTimingHeaderPhp(1425.678, 842.193, 64),
                static fn () => $apiPerf->formatServerTimingHeaderNative(1425.678, 842.193, 64),
            ],
            'apiPerfClassifySlow/common' => [
                static fn () => $apiPerf->classifySlowRequestPhp(24.5, 6.2, 500.0, 250.0),
                static fn () => $apiPerf->classifySlowRequestNative(24.5, 6.2, 500.0, 250.0),
            ],
            'apiPerfClassifySlow/uncommon' => [
                static fn () => $apiPerf->classifySlowRequestPhp(820.0, 410.0, 500.0, 250.0),
                static fn () => $apiPerf->classifySlowRequestNative(820.0, 410.0, 500.0, 250.0),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertSame($phpCall(), $rustCall(), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $phpP99 = $percentile99($samples[0]);
                $rustP99 = $percentile99($samples[1]);
                $reps[] = sprintf('rep%d=%.2fx/%.2fx', $rep + 1, $phpMean / $rustMean, $phpP99 / $rustP99);
            }
            fwrite(STDOUT, sprintf("%-28s %s\n", $name, implode('  ', $reps)));
        }
    }
}
