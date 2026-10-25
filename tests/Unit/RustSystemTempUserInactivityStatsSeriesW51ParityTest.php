<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SeriesReleaseService;
use App\Services\SystemMetricsService;
use App\Services\TempWorkspaceService;
use App\Services\UserInactivityEvaluator;
use App\Services\UserStatsService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class RustSystemTempUserInactivityStatsSeriesW51ParityTest extends TestCase
{
    /** Per class: the batch entry and its Rust export. */
    private const TARGETS = [
        SystemMetricsService::class => ['w51HourlyBucketBatch', 'nntmux_w513_metric_hourly_bucket_batch'],
        TempWorkspaceService::class => ['w51ExtractedFileClassBatch', 'nntmux_w513_temp_file_class_batch'],
        UserInactivityEvaluator::class => ['w51PurgePlanBatch', 'nntmux_w513_user_purge_plan_batch'],
        UserStatsService::class => ['w51PerDaySeriesBatch', 'nntmux_w513_user_stats_per_day_batch'],
        SeriesReleaseService::class => ['w51FallbackSeasonBatch', 'nntmux_w513_series_fallback_season_batch'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        SystemMetricsService::class => true,
        TempWorkspaceService::class => true,
        UserInactivityEvaluator::class => true,
        UserStatsService::class => true,
        SeriesReleaseService::class => true,
    ];

    private const STAMPS = ['2024-01-01 10:05:00', '2024-01-01 10:59:59', '2024-01-01T11:00:00', '2024-02-29 23:59:59', '2023-02-29 12:00:00',
        '2024-01-01 24:00:00', '2024-01-01 23:60:00', '2024-01-01 23:59:60', '0000-01-01 00:00:00', '0001-01-01 00:00:00', '9999-12-31 23:59:59',
        '2024-13-01 00:00:00', '2024-00-10 00:00:00', '2024-1-01 00:00:00', "2024-01-01 10:00:00\n", ' 2024-01-01 10:00:00', '2024-01-01 10:00',
        '2024-01-01 10:00:00.123', '2024-01-01', '', 'now', '0', '2024/01/01 10:00:00', "\xff\xfe"];

    private const VALUES = ['12.34', '12.5', '0', '-0', '-0.05', '100', '999999999.99', '1000000000', '.5', '1.', '1.234', '1e3', ' 1', '+1', '--1',
        '', 'abc', '0.00', '-12.34', '7.07'];

    private const PATHS = ['sub/a.part01.rar', 'a.r00', 'a.R123-x', 'a.r1', 'a.r00a', 'x.ZIPX', 'x.zip.bak', 'x.zipxx', 'a.001', "a.01\n", 'a.0001',
        'a.10', 'a.1.sfv', 'b/.', 'b\\..', 'b/...', "b/.\n", 'x.PAR2', "x.par2\n", "x.par2\n\n", 'dir\\info.txt', 'xinfo.TXT', 'a.nfo', 'a.NFO.bak',
        'a.diz', 'a.ofn', 'a.inf', 'cover.JPEG', 'c.jpg', 'c.png', 'c.webp', 'c.gif', 'plain.mkv', 'sample.avi', '', '.', '..', 'a.', 'deep/a/b/c/d.mp3',
        "na\xefve.rar", 'x.z99', 'x.zip', '.rar', 'x.7z', 'x.part1.rar.tmp', 'x.rar!'];

    private const DATES = ['2023-01-01 00:00:00', '2024-01-01 00:00:00', '2024-06-01 12:30:00', '2023-12-31 23:59:59', '2024-01-01T00:00:00',
        '1970-01-01 00:00:00', '9999-12-31 23:59:59', '2024-13-45 99:99:99', '2024-01-01 00:00:00.5', '2024-01-01', 'bad', '', null, null, null];

    private const EXTENSIONS = ['rar', 'r00', 'r01', 'part01.rar', 'zip', 'zipx', '001', 'nfo', 'par2', 'vol00+01.par2', 'jpg', 'png', 'mkv', 'avi',
        'mp3', 'flac', 'txt', 'sfv', 'srr', 'diz', 'webp', 'nzb', 'exe', 'iso'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w51_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW51RustFfi', 'withW51RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W51_RUST_FFI_DEFAULT', 'W51_FFI_CDEF'] as $name) {
                if (! $reflection->hasConstant($name)) {
                    $missing[] = $class.'::'.$name;
                }
            }
            if (! $reflection->hasProperty('useW51RustFfi') || ! $reflection->getProperty('useW51RustFfi')->isPrivate()) {
                $missing[] = $class.'::$useW51RustFfi';
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w51_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW51RustFfi(), $class);
            $this->assertTrue($class::withW51RustFfi(true, static fn (): bool => $class::usesW51RustFfi()));
            $this->assertFalse($class::withW51RustFfi(false, static fn (): bool => $class::usesW51RustFfi()));
            $this->assertTrue($class::withW51RustFfi(false, static fn (): bool => $class::withW51RustFfi(true, static fn (): bool => $class::usesW51RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW51RustFfi(), $class);
            try {
                $class::withW51RustFfi(! $class::usesW51RustFfi(), static fn () => throw new RuntimeException('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW51RustFfi(), $class.' restores after an exception');
        }

        // Each class keeps an independent toggle.
        SystemMetricsService::withW51RustFfi(! SystemMetricsService::usesW51RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[UserStatsService::class], UserStatsService::usesW51RustFfi());
        });
    }

    public function test_w51_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [, $symbol]) {
            $cdef = \constant($class.'::W51_FFI_CDEF');
            $this->assertStringContainsString($symbol, $cdef);
            $this->assertInstanceOf(FFI::class, RustPipelineLibrary::resolveDomain('categorize_w51', $cdef), $class);
        }

        $ffi = RustPipelineLibrary::resolveDomain('categorize_w51', TempWorkspaceService::W51_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[16]');
        // @phpstan-ignore method.notFound
        $this->assertSame(6, $ffi->nntmux_w513_temp_file_class_batch('a.rar', 5, null, 0, 1, $out, 16));
        $this->assertSame(TempWorkspaceService::w51ExtractedFileClassBatchPhp(['a.rar']), array_values((array) unpack('P*', FFI::string($out, 48))));
        // A NUL inside a path, a short buffer, a null output, a null blob and a ragged integer buffer all decline.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w513_temp_file_class_batch("a\0b", 3, null, 0, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-6, $ffi->nntmux_w513_temp_file_class_batch('a.rar', 5, null, 0, 1, $out, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w513_temp_file_class_batch('a.rar', 5, null, 0, 1, null, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w513_temp_file_class_batch(null, 5, null, 0, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w513_temp_file_class_batch('a.rar', 5, '1234567', 7, 1, $out, 16));
    }

    public function test_w51_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        $cases = [
            SystemMetricsService::class => [["2024-01-01 10:00:00\0bad\x0012.5\x001", '', 2, 16], [[0, -1, 2_024_010_110, 1, 1_250, 1_250, 1_250, 1_250, 1, 1]]],
            TempWorkspaceService::class => [["a.rar\0b/.", '', 2, 16], [[16, 1 | 2 << 4 | 1 << 16, 1, 1, 0, 0, 0]]],
            UserInactivityEvaluator::class => [[str_repeat("2023-01-01 00:00:00\0", 5).'2024-01-01 00:00:00', pack('q', 0), 1, 16], [[15 | 0 << 4, 20_230_101_000_000, 1, 0, 20_230_101_000_000]]],
            UserStatsService::class => [["2024-01-01 05:00:00\x002024-01-01", pack('q*', 1, 2, 3), 1, 16], [[20_240_101, 5, 5, 1, 0]]],
            SeriesReleaseService::class => [["1\x002", pack('q', 1), 1, 16], [[1, 1, 1, 1, 1, 1, 0]]],
        ];
        foreach ($cases as $class => [$args, [$expected]]) {
            $this->assertSame($expected, (new ReflectionMethod($class, 'w51Invoke'))->invoke(null, ...$args), $class);
        }
        $this->assertSame($cases[SystemMetricsService::class][1][0], SystemMetricsService::w51HourlyBucketBatchPhp([
            ['recorded_at' => '2024-01-01 10:00:00', 'value' => '12.5'], ['recorded_at' => 'bad', 'value' => '1'],
        ]));
        $this->assertSame($cases[UserStatsService::class][1][0], UserStatsService::w51PerDaySeriesBatchPhp([['stat_hour' => '2024-01-01 05:00:00', 'count' => 3]], '2024-01-01', 1, 2));
    }

    public function test_hourly_bucket_parity(): void
    {
        $this->assertSame([0, 0], SystemMetricsService::w51HourlyBucketBatchPhp([]));
        $this->assertSame([0, 0], SystemMetricsService::w51HourlyBucketBatchNative([]));
        $this->assertSame(
            [0, 0, 1, -1, -1, 2_024_010_110, 2, 3_050, 1_525, 1_050, 2_000, 2_024_010_111, 1, -300, -300, -300, -300, 2, 2],
            SystemMetricsService::w51HourlyBucketBatchPhp([
                ['recorded_at' => '2024-01-01 10:05:00', 'value' => '10.5'],
                ['recorded_at' => '2024-01-01 10:59:59', 'value' => 20],
                ['recorded_at' => '2024-01-01T11:00:00', 'value' => -3.0],
                ['recorded_at' => 'bad', 'value' => '1'],
                ['recorded_at' => '2024-01-01 10:30:00', 'value' => 'x'],
            ]),
        );
        $edge = [];
        foreach (self::STAMPS as $i => $stamp) {
            foreach ([self::VALUES[$i % \count(self::VALUES)], 1.5, 0.1 + 0.2, -2, null, true] as $value) {
                $edge[] = ['recorded_at' => $stamp, 'value' => $value];
            }
        }
        $edge[] = ['value' => '1.00'];
        $edge[] = ['recorded_at' => '2024-01-01 10:00:00'];
        $edge[] = [];
        $this->assertParity(SystemMetricsService::class, [$edge], 'edge rows');
        $this->assertParity(SystemMetricsService::class, [[['recorded_at' => "2024-01-01 10:00:00\0", 'value' => '1']]], 'NUL falls back');
        $this->assertParity(SystemMetricsService::class, [[['recorded_at' => '2024-01-01 10:00:00', 'value' => '-999999999.99'], ['recorded_at' => '2024-01-01 10:00:00', 'value' => '-0.01']]], 'negative average');

        $random = new Randomizer(new Mt19937(5131));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(SystemMetricsService::class, [$this->metricRows($random, $random->getInt(1, 80), $round % 2 === 1)], "round {$round}");
        }
    }

    public function test_extracted_file_class_parity(): void
    {
        $this->assertSame([0, 0, 0, 0, 0], TempWorkspaceService::w51ExtractedFileClassBatchPhp([]));
        $this->assertSame([0, 0, 0, 0, 0], TempWorkspaceService::w51ExtractedFileClassBatchNative([]));
        $this->assertSame(
            [1 << 4 | 1 << 16, 1 | 2 << 4 | 1 << 16, 2 | 4 << 4, 3 | 8 << 4 | 1 << 16, 4 | 16 << 4, 0, 1, 1, 1, 1, 1],
            TempWorkspaceService::w51ExtractedFileClassBatchPhp(['sub/a.part01.rar', 'b/.', "x.PAR2\n", 'dir\\info.txt', 'cover.JPEG', 'plain.mkv']),
        );
        $this->assertParity(TempWorkspaceService::class, [self::PATHS], 'edge paths');
        $this->assertParity(TempWorkspaceService::class, [array_map(static fn (string $p): string => strtoupper($p), self::PATHS)], 'upper-case paths');
        $this->assertParity(TempWorkspaceService::class, [["a.rar\0.nfo", 'b.nfo']], 'NUL falls back');
        $this->assertParity(TempWorkspaceService::class, [[5 => 'a.rar', 9 => 'b.nfo']], 'sparse keys');

        $random = new Randomizer(new Mt19937(5132));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(TempWorkspaceService::class, [$this->tempPaths($random, $random->getInt(1, 80), $round % 2 === 1)], "round {$round}");
        }
    }

    public function test_purge_plan_parity(): void
    {
        $threshold = '2024-01-01 00:00:00';
        $this->assertSame([0, 0, 0], UserInactivityEvaluator::w51PurgePlanBatchPhp([], $threshold));
        $this->assertSame([0, 0, 0], UserInactivityEvaluator::w51PurgePlanBatchNative([], $threshold));
        $old = '2023-01-01 00:00:00';
        $new = '2024-06-01 00:00:00';
        $rows = [
            ['created_at' => $old, 'updated_at' => $old, 'last_login_at' => null, 'api_access_at' => null, 'last_download_at' => null, 'grabs' => 0],
            ['created_at' => $old, 'updated_at' => $new, 'last_login_at' => $old, 'api_access_at' => null, 'last_download_at' => null, 'grabs' => 3],
            ['created_at' => $old, 'updated_at' => null, 'last_login_at' => $new, 'api_access_at' => null, 'last_download_at' => null, 'grabs' => 0],
            ['created_at' => $new, 'updated_at' => null, 'last_login_at' => null, 'api_access_at' => null, 'last_download_at' => 'bad', 'grabs' => '0'],
        ];
        $this->assertSame(
            [0b1111 | 2 << 4, 20_230_101_000_000, 0b0011 | 1 << 4, 20_230_101_000_000, 0b0110 | 2 << 4, 20_240_601_000_000, 2 << 4, 20_240_601_000_000, 1, 3, 20_230_101_000_000],
            UserInactivityEvaluator::w51PurgePlanBatchPhp($rows, $threshold),
        );
        foreach ([$threshold, 'bad', '', '2024-01-01T00:00:00', '9999-12-31 23:59:59', "2024-01-01 00:00:00\0"] as $limit) {
            $this->assertParity(UserInactivityEvaluator::class, [$rows, $limit], "threshold {$limit}");
        }
        $edge = [];
        foreach (self::DATES as $i => $date) {
            foreach (self::DATES as $j => $other) {
                $edge[] = ['created_at' => $date, 'updated_at' => $other, 'last_login_at' => self::DATES[($i + $j) % \count(self::DATES)],
                    'api_access_at' => $j % 3 === 0 ? null : $other, 'last_download_at' => $i % 4 === 0 ? $other : null,
                    'grabs' => [0, 1, '2', '-1', null, 'x', 1.9, true][($i * $j) % 8]];
            }
        }
        $edge[] = ['grabs' => 5];
        $edge[] = [];
        $this->assertParity(UserInactivityEvaluator::class, [$edge, $threshold], 'edge rows');

        $random = new Randomizer(new Mt19937(5133));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(UserInactivityEvaluator::class, $this->purgeArgs($random, $random->getInt(1, 80), $round % 2 === 1), "round {$round}");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $hourly
     * @param  list<int>  $expected
     */
    #[DataProvider('perDaySeriesCases')]
    public function test_per_day_series_cases(array $hourly, string $start, int $days, int $live, array $expected): void
    {
        $this->assertSame($expected, UserStatsService::w51PerDaySeriesBatchPhp($hourly, $start, $days, $live));
        $this->assertParity(UserStatsService::class, [$hourly, $start, $days, $live], 'case');
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string, 2: int, 3: int, 4: list<int>}>
     */
    public static function perDaySeriesCases(): array
    {
        $rows = [
            ['stat_hour' => '2024-02-28 23:00:00', 'count' => 4], ['stat_hour' => '2024-02-29 01:00:00', 'count' => '5'],
            ['stat_hour' => '2024-03-02 00:00:00', 'count' => 6], ['stat_hour' => 'junk', 'count' => 1], ['stat_hour' => '2024-02-29', 'count' => -9],
        ];

        return [
            'leap window' => [$rows, '2024-02-28', 3, 7, [20_240_228, 4, 20_240_229, 5, 20_240_301, 7, 16, 3, 2]],
            'invalid start' => [$rows, '2024-02-30', 3, 7, [0, 0, 5]],
            'zero days' => [$rows, '2024-02-28', 0, 7, [0, 0, 5]],
            'negative days' => [$rows, '2024-02-28', -5, 7, [0, 0, 5]],
            'year end' => [[['stat_hour' => '2025-01-01 00:00:00', 'count' => 2]], '2024-12-31', 2, 0, [20_241_231, 0, 20_250_101, 2, 2, 1, 0]],
            'clamped live' => [[], '2024-01-01', 1, PHP_INT_MAX, [20_240_101, UserStatsService::W51_MAX_COUNT, UserStatsService::W51_MAX_COUNT, 0, 0]],
            'negative live' => [[], '2024-01-01', 1, -4, [20_240_101, 0, 0, 0, 0]],
            'empty start' => [[], '', 1, 1, [0, 0, 0]],
        ];
    }

    public function test_per_day_series_parity(): void
    {
        $this->assertParity(UserStatsService::class, [[], '0001-01-01', 400, 3], 'year one');
        $this->assertParity(UserStatsService::class, [[['stat_hour' => '9999-12-31 01:00:00', 'count' => 1]], '9999-12-30', 800, 3], 'past year 9999');
        $this->assertParity(UserStatsService::class, [[['stat_hour' => '2024-01-01 01:00:00', 'count' => 1]], '2024-01-01', PHP_INT_MAX, 1], 'max days');
        $this->assertParity(UserStatsService::class, [[['stat_hour' => "2024-01-01\0", 'count' => 1]], '2024-01-01', 2, 1], 'NUL falls back');
        $this->assertParity(UserStatsService::class, [[['count' => 3], ['stat_hour' => '2024-01-01 02:00:00'], []], '2024-01-01', 2, 1], 'missing keys');
        $this->assertParity(UserStatsService::class, [[['stat_hour' => '2024-01-01 02:00:00', 'count' => PHP_INT_MAX], ['stat_hour' => '2024-01-01 03:00:00', 'count' => '1e30']], '2024-01-01', 1, PHP_INT_MAX], 'clamped counts');

        $random = new Randomizer(new Mt19937(5134));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(UserStatsService::class, $this->perDayArgs($random, $random->getInt(0, 80), $round % 2 === 1), "round {$round}");
        }
    }

    public function test_fallback_season_parity(): void
    {
        $this->assertSame([0, 0, 0], SeriesReleaseService::w51FallbackSeasonBatchPhp([], [], 1));
        $this->assertSame([0, 0, 0], SeriesReleaseService::w51FallbackSeasonBatchNative([], [], 1));
        $series = [2, '', '1', 2, 0, 'x'];
        $episodes = [5, 3, '3', '0', 1, 2];
        $this->assertSame([0b11_0101, 0b1, 3, 0, 2, 1, 1, 2, 1, 1, 2], SeriesReleaseService::w51FallbackSeasonBatchPhp($series, $episodes, 2));
        $this->assertParity(SeriesReleaseService::class, [$series, $episodes, 2], 'basic');
        $values = [null, '', '0', 0, 1, '1', '01', ' 2', '2 ', '+2', '1e3', '9999999999999999999', '-3', -3, PHP_INT_MAX, PHP_INT_MIN, 'abc', '0x1A', '1.9'];
        $edgeSeries = [];
        $edgeEpisodes = [];
        foreach ($values as $s) {
            foreach ($values as $e) {
                $edgeSeries[] = $s;
                $edgeEpisodes[] = $e;
            }
        }
        foreach ([0, 1, 2, -3, 1000, PHP_INT_MAX, PHP_INT_MIN] as $season) {
            $this->assertParity(SeriesReleaseService::class, [$edgeSeries, $edgeEpisodes, $season], "edge season {$season}");
        }
        $plain = [null, '', '0', 0, 1, '1', '01', '-3', -3, PHP_INT_MAX, PHP_INT_MIN, '+2'];
        $plainSeries = [];
        $plainEpisodes = [];
        foreach ($plain as $s) {
            foreach ($plain as $e) {
                $plainSeries[] = $s;
                $plainEpisodes[] = $e;
            }
        }
        $this->assertParity(SeriesReleaseService::class, [$plainSeries, $plainEpisodes, 1], 'plain integers stay native');
        $this->assertParity(SeriesReleaseService::class, [['1', 2], ['1'], 1], 'short episode column');
        $this->assertParity(SeriesReleaseService::class, [['1'], ['1', '2'], 1], 'long episode column');
        $this->assertParity(SeriesReleaseService::class, [["1\0", 2], [1, 1], 1], 'NUL falls back');
        $this->assertParity(SeriesReleaseService::class, [[3 => 1, 7 => 2], [9 => 1, 2 => 2], 2], 'sparse keys');
        $wide = array_fill(0, 130, 1);
        $this->assertSame([-1, -1, 3, -1, -1, 3, 1, 1, 130, 130, 0], SeriesReleaseService::w51FallbackSeasonBatchPhp($wide, $wide, 1));
        $this->assertParity(SeriesReleaseService::class, [$wide, $wide, 1], 'three bitmap words');

        $random = new Randomizer(new Mt19937(5135));
        for ($round = 0; $round < 150; $round++) {
            $this->assertParity(SeriesReleaseService::class, $this->seasonArgs($random, $random->getInt(1, 200), $round % 2 === 1), "round {$round}");
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including column
        // extraction, joining, packing, the FFI call and decoding (release dylib, 5 warmups, 401 paired samples per
        // helper and workload, 20 iterations per common sample and 10 per uncommon sample). Common = one typical batch
        // (240 per-minute metric rows, 128 extracted paths, 128 users, 168 hourly rows for 7 days, 128 fallback
        // releases); uncommon = 256 long, noisy, malformed and null-heavy rows (500 fallback releases, the
        // FALLBACK_SCAN_LIMIT; 256 hourly rows over a 30-day window).
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5.12 NTS, release dylib, Apple Silicon,
        // shared host at load average ~8-11):
        // - SystemMetricsService:    common mean 7.46-7.84x / p99 5.71-6.99x, uncommon mean 5.68-5.91x / p99 4.22-4.76x
        // - TempWorkspaceService:    common mean 3.60-3.89x / p99 2.41-3.30x, uncommon mean 3.69-3.84x / p99 2.63-3.89x
        // - UserInactivityEvaluator: common mean 5.73-5.96x / p99 5.00-5.83x, uncommon mean 5.30-5.53x / p99 4.07-5.01x
        // - UserStatsService:        common mean 4.01-4.16x / p99 3.28-3.56x, uncommon mean 3.44-3.57x / p99 2.62-2.90x
        // - SeriesReleaseService:    common mean 2.12-2.37x / p99 2.11-2.56x, uncommon mean 2.30-2.44x / p99 1.95-2.11x
        // Every helper clears every gate in every round and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustSystemTempUserInactivityStatsSeriesW51ParityTest.php --filter benchmark
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5136));
        $warmup = 5;
        $samples = 401;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(5137)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(5138)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW51RustFfi($useRust, static fn (): array => $class::$entry(...$args)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustSystemTempUserInactivityStatsSeriesW51 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW51RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}(...$args);
        $this->assertSame($php, $class::{$entry.'Native'}(...$args), $class.' '.$message);
        $this->assertSame($php, $class::withW51RustFfi(true, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
        $this->assertSame($php, $class::withW51RustFfi(false, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
    }

    /**
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            SystemMetricsService::class => [$this->metricRows($random, $uncommon ? 256 : 240, $uncommon)],
            TempWorkspaceService::class => [$this->tempPaths($random, $uncommon ? 256 : 128, $uncommon)],
            UserInactivityEvaluator::class => $this->purgeArgs($random, $uncommon ? 256 : 128, $uncommon),
            UserStatsService::class => $this->perDayArgs($random, $uncommon ? 256 : 168, $uncommon),
            default => $this->seasonArgs($random, $uncommon ? 500 : 128, $uncommon),
        };
    }

    /**
     * Per-minute `system_metrics` rows as the database returns them (decimal strings), sorted; noisy rows mix
     * malformed stamps and values, floats, ints and nulls in random order.
     *
     * @return list<array<string, mixed>>
     */
    private function metricRows(Randomizer $random, int $size, bool $noisy): array
    {
        $base = $random->getInt(1_600_000_000, 1_800_000_000);
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $stamp = gmdate($noisy && $random->getInt(0, 3) === 0 ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s', $base + $i * 60 + ($noisy ? $random->getInt(-7_200, 7_200) : 0));
            $value = number_format($random->getInt(0, 10_000) / 100, 2, '.', '');
            if ($noisy) {
                $stamp = match ($random->getInt(0, 9)) {
                    0 => $this->pick($random, self::STAMPS),
                    1 => null,
                    default => $stamp,
                };
                $value = match ($random->getInt(0, 9)) {
                    0 => $this->pick($random, self::VALUES),
                    1 => $random->getInt(-500, 500) / 4,
                    2 => $random->getInt(-100, 100),
                    3 => null,
                    default => $value,
                };
            }
            $rows[] = ['recorded_at' => $stamp, 'value' => $value];
        }

        return $rows;
    }

    /**
     * Relative paths under a release temp folder; noisy paths are long, mixed-case, back-slashed or newline-ended.
     *
     * @return list<string>
     */
    private function tempPaths(Randomizer $random, int $size, bool $noisy): array
    {
        $paths = [];
        for ($i = 0; $i < $size; $i++) {
            $dirs = $noisy ? $random->getInt(0, 6) : $random->getInt(0, 2);
            $path = '';
            for ($d = 0; $d < $dirs; $d++) {
                $path .= bin2hex($random->getBytes($noisy ? $random->getInt(1, 12) : 4)).($noisy && $random->getInt(0, 3) === 0 ? '\\' : '/');
            }
            $path .= 'Show.Name.S01E02.720p-GRP.'.$this->pick($random, self::EXTENSIONS);
            if ($noisy) {
                $path = match ($random->getInt(0, 7)) {
                    0 => strtoupper($path),
                    1 => $path."\n",
                    2 => $this->pick($random, self::PATHS),
                    3 => $path.'.'.$random->getInt(0, 999),
                    default => $path,
                };
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * `users` rows as the database returns them; noisy rows add malformed stamps, string grabs and nulls.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function purgeArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $now = 1_760_000_000;
        $stamp = function () use ($random, $now, $noisy): ?string {
            if ($random->getInt(0, $noisy ? 2 : 4) === 0) {
                return $noisy && $random->getInt(0, 1) === 0 ? $this->pick($random, self::DATES) : null;
            }

            return gmdate('Y-m-d H:i:s', $now - $random->getInt(0, 400 * 86_400));
        };
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $rows[] = ['created_at' => $stamp() ?? gmdate('Y-m-d H:i:s', $now - 500 * 86_400), 'updated_at' => $stamp(), 'last_login_at' => $stamp(),
                'api_access_at' => $stamp(), 'last_download_at' => $stamp(),
                'grabs' => $noisy ? $this->pick($random, [0, 1, 7, '3', '0', null, -1]) : $random->getInt(0, 3) * $random->getInt(0, 1)];
        }

        return [$rows, gmdate('Y-m-d H:i:s', $now - 180 * 86_400)];
    }

    /**
     * Closed `user_activity_stats_hourly` rows for the window; noisy rows fall outside it, are malformed or carry
     * string counts.
     *
     * @return array{0: list<array<string, mixed>>, 1: string, 2: int, 3: int}
     */
    private function perDayArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $days = $noisy ? 30 : 7;
        $start = 1_760_000_000 - 1_760_000_000 % 86_400 - ($days - 1) * 86_400;
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $hour = $noisy ? $random->getInt(-48, $days * 24 + 48) : $i;
            $stamp = gmdate('Y-m-d H:00:00', $start + $hour * 3_600);
            $count = $random->getInt(0, 500);
            if ($noisy) {
                $stamp = match ($random->getInt(0, 9)) {
                    0 => $this->pick($random, self::STAMPS),
                    1 => null,
                    default => $stamp,
                };
                $count = match ($random->getInt(0, 5)) {
                    0 => (string) $count,
                    1 => null,
                    default => $count,
                };
            }
            $rows[] = ['stat_hour' => $stamp, 'count' => $count];
        }

        return [$rows, gmdate('Y-m-d', $start), $days, $random->getInt(0, 50)];
    }

    /**
     * Hydrated fallback series and episode columns (integers, null when unresolved); noisy rows add numeric strings,
     * zeros, empty values and year seasons.
     *
     * @return array{0: list<int|string|null>, 1: list<int|string|null>, 2: int}
     */
    private function seasonArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $series = [];
        $episodes = [];
        for ($i = 0; $i < $size; $i++) {
            $resolved = $random->getInt(0, 9) !== 0;
            $s = $resolved ? $random->getInt(1, $noisy ? 40 : 6) : null;
            $e = $resolved ? $random->getInt(1, 24) : null;
            if ($noisy) {
                $s = $this->pick($random, [$s, $s, (string) $s, '', 0, '0', null, 2024]);
                $e = $this->pick($random, [$e, $e, (string) $e, '0', 0, '', null]);
            }
            $series[] = $s;
            $episodes[] = $e;
        }

        return [$series, $episodes, $random->getInt(1, 6)];
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionClass($class))->setStaticPropertyValue('useW51RustFfi', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W51_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w51', TempWorkspaceService::W51_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }
}
