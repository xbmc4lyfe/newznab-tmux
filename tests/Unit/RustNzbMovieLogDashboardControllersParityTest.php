<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\ServiceStatusEnum;
use App\Http\Controllers\Admin\AdminLogViewerController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Api\FileListApiController;
use App\Http\Controllers\BasePageController;
use App\Http\Controllers\GetNzbController;
use App\Http\Controllers\MovieController;
use App\Http\Requests\Admin\AdminLogFileRequest;
use App\Http\Requests\Admin\AdminLogViewerRequest;
use App\Models\Release;
use App\Models\User;
use App\Services\AdminDashboardSnapshotService;
use App\Services\LogViewer\Index\LogIndexer;
use App\Services\LogViewer\Index\LogSearchCoordinator;
use App\Services\LogViewer\LogFile;
use App\Services\LogViewer\LogFileRepository;
use App\Services\MovieService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\SystemMetricsService;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNzbMovieLogDashboardControllersParityTest extends TestCase
{
    private const array CLASSES = [GetNzbController::class, MovieController::class, FileListApiController::class, AdminLogViewerController::class, AdminPageController::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_defaults_nullable_toggles_and_parent_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi());
        }
        BasePageController::setUseRustFfi(true);
        try {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
                $this->assertTrue($class::usesRustFfi());
                $class::setUseRustFfi(null);
                $this->assertFalse($class::usesRustFfi());
            }
        } finally {
            BasePageController::setUseRustFfi(null);
        }
    }

    public function test_boundary_contracts_preserve_existing_php_behavior(): void
    {
        $this->assertSame('ab', GetNzbController::sanitizeNzbIdPhp('a.NZBb.nzb'));
        $this->assertSame(['guids' => ['a', '', ''], 'count' => 3, 'exceeded' => false], GetNzbController::zipDownloadPlanPhp('a,,', 2, 5));
        $this->assertTrue(GetNzbController::zipDownloadPlanPhp('a,,', 3, 5)['exceeded']);
        $this->assertFalse(GetNzbController::zipDownloadPlanPhp('a', PHP_INT_MAX, PHP_INT_MAX)['exceeded']);
        $this->assertSame('guid=a%20b%2F%00%FF&userid=-2&api_token=%26%3D%2B', GetNzbController::failureQueryPhp("a b/\0\xff", -2, '&=+'));
        $this->assertSame('guid=x&userid=2', GetNzbController::failureQueryPhp('x', 2, ''));
        $browse = MovieController::browsePayloadPhp(-1, 'a\\q\\0\\\\\\', "O\\'Brien", '', 10, 'drama', 1902, ['Drama'], 2027);
        $this->assertSame([], $browse['categories']);
        $this->assertSame("aq\0\\", $browse['title']);
        $this->assertSame("O'Brien", $browse['actors']);
        $this->assertSame('', $browse['rating']);
        $this->assertSame('', $browse['genre']);
        $this->assertSame('', $browse['year']);
        $this->assertSame(range(2027, 1903), $browse['years']);
        $this->assertSame(['title' => 'Unknown Title', 'imdbid' => '00123'], MovieController::detailPayloadPhp(['title' => '0', 'imdbid' => 0], '00123'));
        foreach (['', '1e3', '1.0', '-1', '+1', ' 1', "1\n", '１２'] as $id) {
            $this->assertFalse(MovieController::validTrailerIdPhp($id));
        }
        $this->assertTrue(MovieController::validTrailerIdPhp('00123'));
        $this->assertSame(['initialFile' => 'first', 'delete_guard_minutes' => 0, 'max_results_per_file' => 1], AdminLogViewerController::logViewerStatePhp('', 'first', -1, 0));
        $this->assertSame(['daily' => false, 'count' => 24], AdminPageController::metricWindowPhp('30D'));
        $this->assertSame(['daily' => true, 'count' => 30], AdminPageController::metricWindowPhp('30d'));
    }

    public function test_native_helpers_and_real_dispatch_have_exact_serialized_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = serialize($class::$php(...$args));
                // This raw assertion prevents a broken native path being hidden by fallback.
                $this->assertSame($expected, serialize(GetNzbController::transformNative($op, $args)), $method.' raw native');
                $this->assertSame($expected, serialize($class::$native(...$args)), $method.' native');
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, serialize($class::$method(...$args)), $method.' dispatch');
                }
            }
        }
    }

    public function test_abi_short_exact_and_extra_capacity_and_rejected_inputs_never_write(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::rawVectors() as [$op, $args, $expected]) {
            $input = serialize($args);
            $wire = serialize($expected);
            $buffer = $ffi->new('char['.(strlen($wire) + 2).']');
            foreach ([0, 1, strlen($wire) - 1, strlen($wire), strlen($wire) + 1] as $capacity) {
                FFI::memset($buffer, 0x5A, strlen($wire) + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w28_nzb_movie_log_dashboard_controllers($op, $input, strlen($input), $buffer, $capacity);
                if ($capacity < strlen($wire)) {
                    $this->assertSame(-strlen($wire), $written);
                    $this->assertSame(str_repeat('Z', strlen($wire) + 2), FFI::string($buffer, strlen($wire) + 2));
                } else {
                    $this->assertSame(strlen($wire), $written);
                    $this->assertSame($wire.'ZZ', FFI::string($buffer, strlen($wire) + 2));
                }
            }
        }
        $buffer = $ffi->new('char[1024]');
        foreach (['a:0:{}x', 'a:00:{}', 'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{i:0;R:1;}', 'a:1:{i:0;s:100:"a";}', 'a:1:{s:1:"0";s:1:"a";}', 'a:1:{i:0;i:+1;}', 'a:2:{i:0;s:1:"a";i:0;s:1:"b";}'] as $bad) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w28_nzb_movie_log_dashboard_controllers(7, $bad, strlen($bad), $buffer, 1024));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
        $input = serialize(['123']);
        foreach ([[null, 0, $buffer, 1024], [null, 1, $buffer, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 1], [$input, 67108865, $buffer, 1024], [$input, strlen($input), $buffer, 67108865], [$buffer, 20, $buffer, 1024], [FFI::addr($buffer[1]), 20, $buffer, 1024], [$buffer, 20, FFI::addr($buffer[1]), 1023]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w28_nzb_movie_log_dashboard_controllers(7, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
    }

    public function test_transport_declines_unsafe_values_and_missing_library_falls_back(): void
    {
        $this->nativeFfi();
        $object = new class
        {
            public function __serialize(): array
            {
                throw new RuntimeException('Do not serialize objects.');
            }
        };
        $this->assertNull(GetNzbController::transformNative(5, [[$object], '1']));
        $ref = 'x';
        $this->assertNull(GetNzbController::transformNative(7, [&$ref]));
        $cycle = [];
        $cycle[] = &$cycle;
        $this->assertNull(GetNzbController::transformNative(5, [$cycle, '1']));
        $this->assertNull(GetNzbController::transformNative(999, []));
        $this->assertNull(GetNzbController::transformNative(7, [123]));
        $this->assertNull(GetNzbController::transformNative(11, [[['name' => 'incomplete']]]));
        $this->assertSame($object, MovieController::detailPayloadNative(['title' => $object], '1')['title']);
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        putenv('NNTMUX_PIPELINE_FFI_LIB=/tmp/w28-2-missing-'.getmypid().'.dylib');
        try {
            foreach (self::workloads() as [$class, $method, $common]) {
                $php = $method.'Php';
                $class::setUseRustFfi(true);
                foreach ($common as $args) {
                    $this->assertSame(serialize($class::$php(...$args)), serialize($class::$method(...$args)), $method.' fallback');
                }
            }
        } finally {
            $library === false ? putenv('NNTMUX_PIPELINE_FFI_LIB') : putenv('NNTMUX_PIPELINE_FFI_LIB='.$library);
        }
    }

    public function test_real_nzb_request_normalization_and_headers_keep_token_and_metadata_rules(): void
    {
        $this->nativeFfi();
        Carbon::setTestNow('2026-10-24 12:34:56');
        $reflection = new ReflectionClass(GetNzbController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $release = new Release;
        $release->forceFill(['category_name' => 'Movies > HD & More', 'imdbid' => '0012345', 'tvdb' => 99, 'nfostatus' => 1]);
        foreach ([false, true] as $enabled) {
            GetNzbController::setUseRustFfi($enabled);
            $request = Request::create('/getnzb', 'GET', ['id' => 'abc.NZB.nzb']);
            $this->assertSame('abc', $reflection->getMethod('validateAndSanitizeId')->invoke($controller, $request));
            $this->assertSame('abc', $request->input('id'));
            foreach ([false, true] as $session) {
                $request->setUserResolver(static fn () => $session ? new User : null);
                $headers = $reflection->getMethod('buildNzbHeaders')->invoke($controller, $request, 'a b', 7, '&=+', $release);
                $this->assertSame(url('/failed').'?guid=a%20b&userid=7'.($session ? '' : '&api_token=%26%3D%2B'), $headers['X-DNZB-Failure']);
                $this->assertSame('Movies &gt; HD &amp; More', $headers['X-DNZB-Category']);
                $this->assertSame('http://www.imdb.com/title/tt0012345', $headers['X-DNZB-MoreInfo']);
                $this->assertSame(url('/nfo/a b'), $headers['X-DNZB-NFO']);
                $this->assertSame(now()->addYear()->toRfc7231String(), $headers['Expires']);
            }
        }
    }

    public function test_real_movie_trailer_handler_rejects_non_digits_before_service_lookup(): void
    {
        $this->nativeFfi();
        foreach ([false, true] as $enabled) {
            MovieController::setUseRustFfi($enabled);
            $service = $this->createMock(MovieService::class);
            $service->expects($this->once())->method('getMovieInfo')->with('00123')->willReturn(null);
            $reflection = new ReflectionClass(MovieController::class);
            $controller = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('movieService')->setValue($controller, $service);
            foreach (['', '1e3', '1.0', '-1', '+1', ' 1', ['123']] as $id) {
                $response = $controller->showTrailer(Request::create('/trailer', 'GET', ['id' => $id]));
                $this->assertSame(400, $response->getStatusCode());
                $this->assertSame(['message' => 'Invalid movie ID.'], $response->getData(true));
            }
            $response = $controller->showTrailer(Request::create('/trailer', 'GET', ['id' => '00123']));
            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame(['message' => 'There is no trailer for this movie.'], $response->getData(true));
        }
    }

    public function test_real_file_list_handler_preserves_envelope_and_not_found_errors(): void
    {
        $this->nativeFfi();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('releases', static function (Blueprint $table): void {
            $table->integer('id');
            $table->string('guid');
            $table->string('searchname');
        });
        Schema::create('releases_groups', static function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('groups_id')->nullable();
        });
        DB::table('releases')->insert(['id' => 1, 'guid' => 'abc', 'searchname' => 'Movie']);
        $files = [['name' => 'movie.mkv', 'size' => 123, 'parts' => 1]];
        $nzb = $this->createMock(NzbService::class);
        $nzb->method('readNzbContents')->willReturnMap([['abc', '<nzb/>']]);
        $parser = $this->createMock(NzbParserService::class);
        $parser->method('parseNzbFileList')->with('<nzb/>')->willReturn($files);
        $this->app->instance(NzbService::class, $nzb);
        $this->app->instance(NzbParserService::class, $parser);
        foreach ([false, true] as $enabled) {
            FileListApiController::setUseRustFfi($enabled);
            $controller = new FileListApiController;
            $this->assertSame(['release' => ['guid' => 'abc', 'searchname' => 'Movie'], 'files' => $files, 'total' => 1], $controller->getFileList('abc')->getData(true));
            $this->assertSame(404, $controller->getFileList('missing')->getStatusCode());
        }
    }

    public function test_real_log_index_selection_and_active_delete_conflict(): void
    {
        $this->nativeFfi();
        $file = new LogFile('first.log', 'first.log', null, '/tmp/w28-fixture.log', 12, CarbonImmutable::now(), false);
        $logs = $this->createMock(LogFileRepository::class);
        $logs->method('all')->willReturn([$file]);
        $logs->method('find')->willReturn($file);
        $searcher = $this->createMock(LogSearchCoordinator::class);
        $searcher->method('fileStatus')->willReturn([]);
        $searcher->method('engine')->willReturn('php');
        $indexer = $this->createMock(LogIndexer::class);
        $indexer->expects($this->never())->method('forget');
        foreach ([false, true] as $enabled) {
            AdminLogViewerController::setUseRustFfi($enabled);
            $controller = (new ReflectionClass(AdminLogViewerController::class))->newInstanceWithoutConstructor();
            foreach (['' => 'first.log', 'chosen.log' => 'chosen.log'] as $selected => $expected) {
                $request = AdminLogViewerRequest::create('/admin/logs', 'GET', ['file' => $selected, 'q' => 'query']);
                $request->setValidator(validator($request->all(), ['file' => 'nullable|string', 'q' => 'nullable|string']));
                $view = $controller->index($request, $logs, $searcher);
                $this->assertSame($expected, $view->getData()['initialFile']);
                $this->assertSame('query', $view->getData()['initialQuery']);
            }
            config(['nntmux.log_viewer.delete_guard_minutes' => 10]);
            $request = AdminLogFileRequest::create('/admin/logs', 'DELETE', ['file' => 'first.log']);
            $request->setValidator(validator($request->all(), ['file' => 'required|string']));
            $response = $controller->destroy($request, $logs, $indexer);
            $this->assertSame(409, $response->getStatusCode());
            $this->assertSame(['message' => 'This log was written to in the last 10 minutes and is probably still open by a running process. Truncate it instead.'], $response->getData(true));
        }
    }

    public function test_real_dashboard_json_serializes_dates_projections_and_metric_selection(): void
    {
        $this->nativeFfi();
        Carbon::setTestNow('2026-10-24 12:34:56');
        $date = Carbon::parse('2026-10-24 11:00:00');
        $period = (object) ['id' => 1, 'name' => 'Open', 'starts_at' => $date, 'ends_at' => null];
        $activity = (object) ['type' => 'download', 'message' => 'Downloaded', 'icon' => 'arrow', 'icon_bg' => 'blue', 'icon_color' => 'white', 'created_at' => $date, 'username' => 'Alice', 'metadata' => ['release' => 1]];
        $payload = [
            'serviceStatuses' => [(object) ['name' => 'Web', 'slug' => 'web', 'check_type' => 'http', 'status' => ServiceStatusEnum::Operational, 'uptime_percentage' => '99.5']],
            'activeIncidents' => [(object) ['id' => 3, 'title' => 'Maintenance', 'impact' => IncidentImpactEnum::Minor, 'started_at' => null, 'is_auto' => 1, 'services' => collect([(object) ['name' => 'Web']])]],
            'recent_activity' => [$activity],
            'registrationStatus' => ['manual_status' => 'open', 'manual_status_label' => 'Open', 'effective_status' => 'open', 'effective_status_label' => 'Open', 'scheduled_override_active' => 1, 'message' => 'Welcome', 'active_period' => $period],
            'nextRegistrationPeriod' => null, 'generated_at' => now()->toIso8601String(), 'stats' => ['users' => 4], 'userStats' => [], 'systemMetrics' => [],
        ];
        $snapshot = $this->createMock(AdminDashboardSnapshotService::class);
        $snapshot->method('get')->willReturn($payload);
        foreach ([false, true] as $enabled) {
            AdminPageController::setUseRustFfi($enabled);
            $reflection = new ReflectionClass(AdminPageController::class);
            $controller = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('snapshot')->setValue($controller, $snapshot);
            $data = $controller->getDashboardData()->getData(true);
            $this->assertSame('12:34:56', $data['generated_at_time']);
            $this->assertSame('2026-10-24 11:00', $data['registrationStatus']['active_period']['starts_at']);
            $this->assertNull($data['registrationStatus']['active_period']['ends_at']);
            $this->assertNull($data['nextRegistrationPeriod']);
            $this->assertSame(99.5, $data['serviceStatuses'][0]['uptime_percentage']);
            $this->assertSame(['Web'], $data['activeIncidents'][0]['services']);
            $this->assertTrue($data['activeIncidents'][0]['is_auto']);
            $this->assertNull($data['activeIncidents'][0]['started_at']);
            $this->assertSame($date->toIso8601String(), $data['recent_activity'][0]['created_at']);
            $this->assertSame($date->diffForHumans(), $controller->getRecentActivity()->getData(true)['activities'][0]['created_at']);
            foreach (['30d', '24h', 'invalid'] as $range) {
                $metrics = $this->createMock(SystemMetricsService::class);
                $daily = $range === '30d';
                $method = $daily ? 'getDailyMetrics' : 'getHourlyMetrics';
                $metrics->expects($this->exactly(2))->method($method)->willReturnMap([['cpu', $daily ? 30 : 24, [1]], ['ram', $daily ? 30 : 24, [2]]]);
                $reflection->getProperty('systemMetricsService')->setValue($controller, $metrics);
                $this->app->instance('request', Request::create('/metrics', 'GET', ['range' => $range]));
                $this->assertSame(['success' => true, 'cpu_history' => [1], 'ram_history' => [2], 'range' => $range], $controller->getHistoricalMetrics()->getData(true));
            }
        }
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w28-nzb-movie-log-dashboard-controllers-test', GetNzbController::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicitly built optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>, int}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 1024);
        $service = ['name' => 'Web', 'slug' => 'web', 'check_type' => 'http', 'status' => 'operational', 'status_label' => 'Operational', 'uptime_percentage' => 99.9, 'extra' => 'discard'];
        $incident = ['id' => 1, 'title' => 'Maintenance', 'impact' => 'minor', 'impact_label' => 'Minor', 'started_at' => '2026-10-24T12:00:00+00:00', 'started_at_human' => '1 hour ago', 'is_auto' => false, 'services' => ['Web'], 'extra' => null];
        $activity = ['type' => 'download', 'message' => 'A download', 'icon' => 'arrow', 'icon_bg' => 'blue', 'icon_color' => 'white', 'created_at' => '2026-10-24T12:00:00+00:00', 'created_at_human' => '1 hour ago', 'username' => 'Alice', 'metadata' => ['id' => 1, 'ratio' => 1.25], 'extra' => 'discard'];
        $headers = ['expires' => 'Sun, 24 Oct 2027 12:00:00 GMT', 'failure_url' => 'https://example.test/failed?guid=abc&userid=1', 'category' => 'Movies &gt; HD', 'details_url' => 'https://example.test/details/abc', 'imdb_url' => null, 'tvdb_url' => null, 'nfo_url' => null];

        return [
            [GetNzbController::class, 'sanitizeNzbId', [['abc.nzb'], ['abc.NZB']], [[$long.'.nzb.NzB'], ['.nzb'], ['a.nzb.middle.NZB']], 0],
            [GetNzbController::class, 'zipDownloadPlan', [['a,b', 1, 3], ['a', 2, 2]], [['a,,', 2, 5], ['', 0, 0], ['a,b', PHP_INT_MAX, PHP_INT_MAX], [str_repeat('a,', 2000), 0, 2001]], 1],
            [GetNzbController::class, 'injectPoster', [['<file poster="user"><segments/></file>', 42], ['<nzb/>', 0]], [[str_repeat('<file poster="'.$long.'">', 4), PHP_INT_MIN], ['file Poster="x" file poster="a" file poster="b"', -1]], 2],
            [GetNzbController::class, 'buildDownloadHeaders', [[$headers]], [[array_replace($headers, ['imdb_url' => 'http://www.imdb.com/title/tt0012345', 'tvdb_url' => 'other', 'nfo_url' => 'https://example.test/nfo/a'])], [array_replace($headers, ['tvdb_url' => $long, 'category' => $long])]], 3],
            [MovieController::class, 'browsePayload', [[2000, 'Title', 'Actor', 'Director', 9, 'Drama', 2026, ['Drama', 'Comedy'], 2027], [-1, '', '', '', 0, '', 0, [], 2027]], [[-1, 'a\\q\\0\\\\\\', $long, "O\\'Brien", 10, 'drama', 1902, ['Drama', 1, null], 2027], [2040, $long, '', '', 1, '0', 1903, ['0', 'Drama'], 1903], [0, '', '', '', -1, '', 1900, [], 1900]], 4],
            [MovieController::class, 'detailPayload', [[['title' => 'Movie', 'imdbid' => '0012345'], '0012345'], [[], '123']], [[['title' => '0', 'imdbid' => 0, 'plot' => $long], '123'], [['title' => false, 'imdbid' => null, 'other' => INF], '0'], [['title' => [], 'imdbid' => '', 4 => NAN], '123']], 5],
            [MovieController::class, 'coverPayload', [[['imdbid' => '123', 'cover' => 1, 'title' => 'Movie']], [[]]], [[['videos_id' => 2, 'imdbid' => null, 'anidbid' => 0, 'musicinfo_id' => '3', 'bookinfo_id' => 4, 'consoleinfo_id' => false, 'gamesinfo_id' => $long, 'extra' => 1]]], 6],
            [MovieController::class, 'validTrailerId', [['0012345'], ['123']], [[''], ['1.2'], ['1e3'], [" 1\n"], ['-1'], [$long]], 7],
            [FileListApiController::class, 'fileListPayload', [['abc', 'Release', [['name' => 'movie.mkv', 'size' => 123]]], ['abc', '', []]], [[$long, $long, [4 => ['name' => $long, 'parts' => [1, 2]], 'x' => ['name' => null, 'size' => 1.25]]], ['abc', 'large', array_fill(0, 250, ['name' => 'uncommon-file.mkv', 'size' => PHP_INT_MAX])]], 8],
            [AdminLogViewerController::class, 'logViewerState', [['', 'first.log', 10, 100], ['chosen.log', 'first.log', 1, 1]], [['', '', -1, 0], [$long, 'first', PHP_INT_MIN, PHP_INT_MAX]], 9],
            [AdminLogViewerController::class, 'deleteConflictMessage', [[10], [0]], [[PHP_INT_MAX], [-1]], 10],
            [AdminPageController::class, 'serviceRows', [[[$service]], [[]]], [[[7 => array_replace($service, ['name' => $long, 'uptime_percentage' => 0.0])]], [array_fill(0, 32, $service)]], 11],
            [AdminPageController::class, 'incidentRows', [[[$incident]], [[]]], [[[9 => array_replace($incident, ['title' => $long, 'started_at' => null, 'started_at_human' => null, 'is_auto' => true, 'services' => []])]], [array_fill(0, 32, $incident)]], 12],
            [AdminPageController::class, 'activityRows', [[[$activity], false], [[$activity], true]], [[['key' => array_replace($activity, ['message' => $long, 'created_at' => null, 'created_at_human' => null, 'metadata' => [0 => INF, 'float' => -0.0]])], true], [array_fill(0, 32, $activity), false]], 13],
            [AdminPageController::class, 'registrationPeriod', [[null], [['id' => 1, 'name' => 'Open', 'starts_at' => '2026-10-24 12:00', 'ends_at' => '2026-10-25 13:30']]], [[['id' => PHP_INT_MAX, 'name' => $long, 'starts_at' => null, 'ends_at' => null, 'ignored' => true]]], 14],
            [AdminPageController::class, 'metricWindow', [['24h'], ['30d']], [['30D'], [''], [$long]], 15],
            [GetNzbController::class, 'failureQuery', [['abc', 1, 'token'], ['abc', 1, null]], [["a b/\0\xff", PHP_INT_MIN, '&=+'], [$long, 0, $long], ['x', 1, ''], ['x', 1, '0']], 16],
        ];
    }

    /** @return list<array{int, list<mixed>, mixed}> */
    private static function rawVectors(): array
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            foreach ([...$common, ...$uncommon] as $args) {
                $vectors[] = [$op, $args, $class::$php(...$args)];
            }
        }

        return $vectors;
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave28-2-nzb-movie-log-dashboard-controllers-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 28022026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'uncertainty' => 'Three-round min/max; descriptive, not a confidence interval. 8200 calls/side/round, 24600/cell.',
                'scope' => 'Public helper dispatch including scalar normalization, serialization, allocation, FFI and reconstruction. SQL stays unchanged; excludes app boot and database I/O. No production throughput claim.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w28-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustNzbMovieLogDashboardControllersParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'resource_limits' => self::command(['/bin/sh', '-c', 'ulimit -a']),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'cargo' => self::command(['cargo', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w28-2 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php',
            'app/Http/Controllers/GetNzbController.php', 'app/Http/Controllers/MovieController.php', 'app/Http/Controllers/Api/FileListApiController.php', 'app/Http/Controllers/Admin/AdminLogViewerController.php', 'app/Http/Controllers/Admin/AdminPageController.php', 'tests/Unit/RustNzbMovieLogDashboardControllersParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937(28022026));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $class::setUseRustFfi(true);
                        $this->assertSame(end($expected), serialize($class::$method(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'input_base64_php_serialized' => base64_encode(serialize($inputs)), 'rounds' => []];
                    $aggregate = ['php' => [], 'native' => []];
                    for ($round = 0; $round < 3; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        $pairs = [];
                        for ($pair = 0; $pair < 41; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < 200; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            $pairs[] = ['order' => $order, 'input_indices' => $indices];
                            foreach ($order as $side) {
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['pairs' => $pairs, 'raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                        foreach (['php', 'native'] as $side) {
                            array_push($aggregate[$side], ...$raw[$side]);
                        }
                    }
                    $phpStats = self::statistics($aggregate['php']);
                    $nativeStats = self::statistics($aggregate['native']);
                    $cell['aggregate'] = ['php' => $phpStats, 'native' => $nativeStats,
                        'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $report['results'][$name]['qualifies'] = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $class::setUseRustFfi(false);
                $this->assertFalse($class::usesRustFfi());
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            $report['partial'] = ['helper' => $name ?? null, 'kind' => $kind ?? null, 'round' => $round ?? null, 'pair' => $pair ?? null,
                'completed_rounds' => $cell ?? [], 'pairs' => $pairs ?? [], 'raw_ns' => $raw ?? []];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            fwrite(STDERR, "\nWave 28.2 raw benchmark evidence: {$path}\n");
        }
        $this->assertFileExists($path);
    }

    /** @param list<int> $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1],
            'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
