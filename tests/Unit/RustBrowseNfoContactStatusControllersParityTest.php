<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ServiceStatusEnum;
use App\Http\Controllers\BasePageController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\BrowseGroupController;
use App\Http\Controllers\ContactUsController;
use App\Http\Controllers\NfoController;
use App\Http\Controllers\StatusPageController;
use App\Http\Requests\ContactContactURequest;
use App\Jobs\SendContactUsEmail;
use App\Models\Category;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\SiteStatusService;
use App\Support\Configuration\SiteConfigurationData;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustBrowseNfoContactStatusControllersParityTest extends TestCase
{
    public function test_category_resolution_preserves_all_and_missing_categories(): void
    {
        $this->assertSame(2000, BrowseController::resolveCategoryIdPhp('All', 2000, 2040));
        $this->assertSame(-1, BrowseController::resolveCategoryIdPhp('HD', 2000, null));
        $this->assertSame(2040, BrowseController::resolveCategoryIdPhp('HD', 2000, 2040));
    }

    public function test_group_meta_preserves_php_empty_zero_search(): void
    {
        $this->assertSame('Browse Groups', BrowseGroupController::browseMetaPhp('0')['meta_title']);
        $this->assertSame('Browse Groups - Search: alt.binaries', BrowseGroupController::browseMetaPhp('alt.binaries')['meta_title']);
    }

    public function test_modal_nfo_omits_full_page_meta(): void
    {
        $this->assertSame(['modal' => true], NfoController::nfoContextPhp('Release', true));
        $this->assertSame('View NFO - Release', NfoController::nfoContextPhp('Release', false)['meta_title']);
    }

    public function test_contact_filters_tokens_and_preserves_existing_newline_check(): void
    {
        $this->assertSame(['mailBody' => "Values submitted from contact form: useremail : a@example.org\nmessage : hello\n", 'send' => true, 'msg' => "<h2 style='text-align:center;'>Thank you for getting in touch with Index.</h2>"], ContactUsController::contactSubmissionPhp(['useremail' => 'a@example.org', '_token' => 'secret', 'message' => 'hello', 'cf-turnstile-response' => 'captcha'], 'a@example.org', 'Index', "\n"));
        $this->assertFalse(ContactUsController::canSendContactEmailPhp("a@example.org\nBcc: other@example.org"));
    }

    public function test_status_date_unknown_fallback_and_meta(): void
    {
        $this->assertSame('unknown', StatusPageController::incidentGroupDatePhp(null));
        $this->assertSame('2026-10-24', StatusPageController::incidentGroupDatePhp('2026-10-24'));
        $this->assertSame('Service status', StatusPageController::pageMetaPhp()['meta_title']);
    }

    private const array CLASSES = [BrowseController::class, BrowseGroupController::class, NfoController::class, ContactUsController::class, StatusPageController::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_nullable_toggles_are_independent_from_siblings_and_parent(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
        BasePageController::setUseRustFfi(true);
        try {
            foreach (self::CLASSES as $class) {
                $this->assertFalse($class::usesRustFfi());
                $class::setUseRustFfi(true);
                $this->assertTrue($class::usesRustFfi());
                $class::setUseRustFfi(null);
                $this->assertFalse($class::usesRustFfi());
            }
        } finally {
            BasePageController::setUseRustFfi(null);
        }
    }

    public function test_native_and_dispatch_parity_including_binary_and_uncommon_inputs(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = $class::$php(...$args);
                $this->assertSame($expected, $class::$native(...$args), $method);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $method);
                }
            }
        }
        foreach (self::rawVectors() as [$op, $args, $expected]) {
            $this->assertSame($expected, BrowseController::transformNative($op, $args), 'Native operation '.$op.' must succeed without fallback');
        }
    }

    public function test_direct_abi_short_buffers_do_not_write_and_exact_buffers_have_no_terminator(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::rawVectors() as [$op, $args, $expected]) {
            $input = serialize($args);
            $wire = serialize($expected);
            $buffer = $ffi->new('char['.(strlen($wire) + 2).']');
            foreach ([0, 1, strlen($wire) - 1, strlen($wire), strlen($wire) + 1] as $capacity) {
                FFI::memset($buffer, 0x5A, strlen($wire) + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w27_browse_nfo_contact_status_controllers($op, $input, strlen($input), $buffer, $capacity);
                if ($capacity < strlen($wire)) {
                    $this->assertSame(-strlen($wire), $written);
                    $this->assertSame(str_repeat('Z', strlen($wire) + 2), FFI::string($buffer, strlen($wire) + 2));
                } else {
                    $this->assertSame(strlen($wire), $written);
                    $this->assertSame($wire.'ZZ', FFI::string($buffer, strlen($wire) + 2));
                }
            }
        }
        foreach (['a:0:{}x', 'a:00:{}', 'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{i:0;R:1;}', 'a:1:{i:0;s:100:"a";}', 'a:1:{s:1:"0";s:1:"a";}'] as $bad) {
            $buffer = $ffi->new('char[1024]');
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w27_browse_nfo_contact_status_controllers(4, $bad, strlen($bad), $buffer, 1024));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
    }

    public function test_bridge_rejects_unsafe_values_and_contact_keeps_php_string_conversion(): void
    {
        $this->nativeFfi();
        $object = new class
        {
            public function __serialize(): array
            {
                throw new RuntimeException('The transport must not serialize objects.');
            }

            public function __toString(): string
            {
                return 'text';
            }
        };
        $this->assertNull(BrowseController::transformNative(4, [$object]));
        $ref = 'ref';
        $args = [&$ref];
        $this->assertNull(BrowseController::transformNative(4, $args));
        $cyclic = [];
        $cyclic[] = &$cyclic;
        $this->assertNull(BrowseController::transformNative(4, $cyclic));
        $this->assertNull(BrowseController::transformNative(99, []));
        $this->assertNull(BrowseController::transformNative(0, ['All', '2000', null]));
        $fields = ['message' => $object, '_token' => $object, 'zero' => 0, 'false' => false, 'null' => null, 'float' => 1.25];
        $expected = ['mailBody' => "Values submitted from contact form: message : text\nzero : 0\nfalse : \nnull : \nfloat : 1.25\n", 'send' => true, 'msg' => "<h2 style='text-align:center;'>Thank you for getting in touch with Index.</h2>"];
        $this->assertSame($expected, ContactUsController::contactSubmissionNative($fields, 'a@example.org', 'Index', "\n"));
        // A large output exercises buffer growth through the actual native bridge.
        $large = str_repeat("é\0\xff", 90_000);
        $this->assertSame(BrowseGroupController::browseMetaPhp($large), BrowseController::transformNative(1, [$large]));
    }

    public function test_missing_library_and_disabled_extension_fall_back_in_fresh_processes(): void
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common]) {
            $vectors[] = [$class, $method, $common[0]];
        }
        $encoded = base64_encode(serialize($vectors));
        $script = 'require "tests/bootstrap.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class,$method,$args]) {'
            .'$class::setUseRustFfi(true); $php=$method."Php"; $native=$method."Native";'
            .'if ($class::$php(...$args)!==$class::$native(...$args) || $class::$php(...$args)!==$class::$method(...$args)) throw new RuntimeException($method);'
            .'} echo "fallback verified";';
        foreach ([['-d', 'ffi.enable=0'], []] as $options) {
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => '/tmp/w27-2-missing-library.dylib']);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_cached_load_failure_and_call_time_errors_fall_back_for_every_helper(): void
    {
        $this->nativeFfi();
        $property = (new ReflectionClass(RustPipelineLibrary::class))->getProperty('domainLibraries');
        $saved = $property->getValue();
        $this->assertIsArray($saved);
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library);
        $key = 'w27-browse-nfo-contact-status-controllers|'.$library.'|'.hash('xxh3', BrowseController::FFI_CDEF);
        $missingSymbol = FFI::cdef('uint32_t nntmux_pipeline_abi_version(void);', $library);
        try {
            foreach ([false, $missingSymbol] as $handle) {
                $property->setValue(null, [$key => $handle] + $saved);
                foreach (self::workloads() as [$class, $method, $common]) {
                    $class::setUseRustFfi(true);
                    $php = $method.'Php';
                    $native = $method.'Native';
                    $this->assertSame($class::$php(...$common[0]), $class::$native(...$common[0]));
                    $this->assertSame($class::$php(...$common[0]), $class::$method(...$common[0]));
                }
            }
        } finally {
            $property->setValue(null, $saved);
        }
    }

    public function test_real_contact_handler_retains_job_body_queue_and_injection_suppression(): void
    {
        $this->nativeFfi();
        config(['app.name' => 'Index', 'mail.from.address' => 'owner@example.org']);
        $controller = (new ReflectionClass(ContactUsController::class))->newInstanceWithoutConstructor();
        foreach ([false, true] as $enabled) {
            ContactUsController::setUseRustFfi($enabled);
            foreach ([false, true] as $injected) {
                Bus::fake([SendContactUsEmail::class]);
                $email = $injected ? "a@example.org\nBcc: bad@example.org" : 'a@example.org';
                $request = ContactContactURequest::create('/contact-us', 'POST', ['useremail' => $email, 'comment' => 'hello there', '_token' => 'secret']);
                $view = $controller->contact($request);
                $this->assertInstanceOf(View::class, $view);
                $this->assertSame('contact.index', $view->name());
                $this->assertSame("<h2 style='text-align:center;'>Thank you for getting in touch with Index.</h2>", $view->getData()['msg']);
                if ($injected) {
                    Bus::assertNotDispatched(SendContactUsEmail::class);
                } else {
                    Bus::assertDispatched(SendContactUsEmail::class, static function (SendContactUsEmail $job): bool {
                        $r = new ReflectionClass($job);

                        return $job->queue === 'contactemail'
                            && $r->getProperty('email')->getValue($job) === 'a@example.org'
                            && $r->getProperty('mailTo')->getValue($job) === 'owner@example.org'
                            && $r->getProperty('mailBody')->getValue($job) === 'Values submitted from contact form: useremail : a@example.org'.PHP_EOL.'comment : hello there'.PHP_EOL;
                    });
                }
            }
            Bus::fake([SendContactUsEmail::class]);
            $view = $controller->contact(ContactContactURequest::create('/contact-us', 'GET'));
            $this->assertSame('', $view->getData()['msg']);
            Bus::assertNotDispatched(SendContactUsEmail::class);
        }
    }

    public function test_real_status_handler_preserves_local_date_groups_and_incident_identity(): void
    {
        $this->nativeFfi();
        $first = new ServiceIncident;
        $first->resolved_at = Carbon::parse('2026-10-24 00:10:00', 'Europe/Madrid');
        $second = new ServiceIncident;
        $second->resolved_at = Carbon::parse('2026-10-24 14:00:00', 'Europe/Madrid');
        $unknown = new ServiceIncident;
        $services = new EloquentCollection;
        $active = new EloquentCollection;
        $resolved = new EloquentCollection([$first, $second, $unknown]);
        foreach ([false, true] as $enabled) {
            StatusPageController::setUseRustFfi($enabled);
            $service = $this->createMock(SiteStatusService::class);
            $service->expects($this->once())->method('getPublicEnabledServices')->willReturn($services);
            $service->expects($this->once())->method('getOverallPublicStatus')->willReturn(ServiceStatusEnum::Operational);
            $service->expects($this->once())->method('getActiveIncidentsForPublic')->willReturn($active);
            $service->expects($this->once())->method('getRecentResolvedIncidentsForPublic')->with(30)->willReturn($resolved);
            $r = new ReflectionClass(StatusPageController::class);
            $controller = $r->newInstanceWithoutConstructor();
            $r->getProperty('siteStatusService')->setValue($controller, $service);
            $view = $controller->showStatusPage();
            $data = $view->getData();
            $this->assertSame('status.index', $view->name());
            $this->assertSame('Service status', $data['meta_title']);
            $this->assertSame($services, $data['services']);
            $this->assertSame($active, $data['activeIncidents']);
            $this->assertSame(['2026-10-24', 'unknown'], $data['recentResolvedGrouped']->keys()->all());
            $this->assertSame([$first, $second], $data['recentResolvedGrouped']['2026-10-24']->all());
            $this->assertSame([$unknown], $data['recentResolvedGrouped']['unknown']->all());
        }
    }

    public function test_real_browse_handler_resolves_root_child_and_missing_categories(): void
    {
        $this->nativeFfi();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'nntmux.items_per_page' => 20]);
        DB::purge('sqlite');
        Schema::create('root_categories', static function (Blueprint $table): void {
            $table->integer('id');
            $table->string('title');
        });
        Schema::create('categories', static function (Blueprint $table): void {
            $table->integer('id');
            $table->string('title');
            $table->integer('root_categories_id');
        });
        DB::table('root_categories')->insert(['id' => Category::MOVIE_ROOT, 'title' => 'Movies']);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'HD', 'root_categories_id' => Category::MOVIE_ROOT]);
        foreach ([false, true] as $enabled) {
            BrowseController::setUseRustFfi($enabled);
            foreach ([['Movies', 'All', Category::MOVIE_ROOT], ['Movies', 'HD', 2040], ['Movies', 'Missing', -1], ['Unknown', 'All', -1]] as [$parent, $id, $expected]) {
                $service = $this->createMock(ReleaseBrowseService::class);
                $service->method('getBrowseOrdering')->willReturn([]);
                $service->expects($this->once())->method('getBrowseRange')->with(1, [$expected], 0, 20, '', -1, [], -1)->willReturn([]);
                $r = new ReflectionClass(BrowseController::class);
                $controller = $r->newInstanceWithoutConstructor();
                $r->getProperty('releaseBrowseService')->setValue($controller, $service);
                $controller->userdata = new User;
                $controller->userdata->categoryexclusions = [];
                $view = $controller->show(Request::create('/browse/'.$parent.'/'.$id), $parent, $id);
                $this->assertInstanceOf(View::class, $view);
                $this->assertSame('browse.index', $view->name());
                $this->assertSame($expected, $view->getData()['category']);
                $this->assertSame($expected === -1 ? 'All' : $id, $view->getData()['catname']);
                $this->assertSame($expected === -1 ? '' : 'movies', $view->getData()['covgroup']);
            }
        }
    }

    public function test_real_nfo_handler_keeps_cp437_content_modal_meta_and_not_found_errors(): void
    {
        $this->nativeFfi();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::connection()->getPdo()->sqliteCreateFunction('UNCOMPRESS', static fn (string $value): string|false => gzuncompress($value), 1);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->integer('id');
            $table->string('guid');
            $table->string('searchname');
        });
        Schema::create('releases_groups', static function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('groups_id');
        });
        Schema::create('release_nfos', static function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->binary('nfo');
        });
        DB::table('releases')->insert([['id' => 1, 'guid' => 'valid-guid', 'searchname' => 'Release'], ['id' => 2, 'guid' => 'no-nfo', 'searchname' => 'Missing']]);
        DB::table('release_nfos')->insert(['releases_id' => 1, 'nfo' => gzcompress("NFO\x82")]);
        $controller = (new ReflectionClass(NfoController::class))->newInstanceWithoutConstructor();
        foreach ([false, true] as $enabled) {
            NfoController::setUseRustFfi($enabled);
            foreach ([false, true] as $modal) {
                $request = Request::create('/nfo/valid-guid', 'GET', $modal ? ['modal' => '0'] : []);
                $view = $controller->showNfo($request, 'valid-guid');
                $this->assertInstanceOf(View::class, $view);
                $data = $view->getData();
                $this->assertSame('nfo.view', $view->name());
                $this->assertSame('NFOé', $data['nfo']['nfoUTF']);
                $this->assertSame('valid-guid', $data['rel']['guid']);
                $this->assertSame($modal, $data['modal']);
                $this->assertSame(! $modal, array_key_exists('meta_title', $data));
            }
            foreach (['' => 'Invalid request', 'absent' => 'Release does not exist', 'no-nfo' => 'NFO does not exist'] as $guid => $message) {
                try {
                    $controller->showNfo(Request::create('/nfo'), $guid);
                    $this->fail('Expected 404');
                } catch (HttpException $error) {
                    $this->assertSame(404, $error->getStatusCode());
                    $this->assertSame($message, $error->getMessage());
                }
            }
        }
    }

    public function test_real_browse_group_handler_preserves_search_results_and_empty_zero_meta(): void
    {
        $this->nativeFfi();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'nntmux.items_per_page' => 20]);
        DB::purge('sqlite');
        Schema::create('usenet_groups', static function (Blueprint $table): void {
            $table->integer('id');
            $table->string('name');
            $table->string('description');
            foreach (['first_record_postdate', 'last_record_postdate', 'last_updated'] as $field) {
                $table->string($field)->nullable();
            }
            foreach (['active', 'backfill', 'minfilestoformrelease', 'minsizetoformrelease', 'backfill_target'] as $field) {
                $table->integer($field)->default(0);
            }
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.movies0', 'description' => 'Movie fixture', 'active' => 1]);
        foreach ([false, true] as $enabled) {
            BrowseGroupController::setUseRustFfi($enabled);
            $controller = (new ReflectionClass(BrowseGroupController::class))->newInstanceWithoutConstructor();
            $controller->siteConfiguration = SiteConfigurationData::defaultsPhp();
            foreach (['' => 'Browse Groups', '0' => 'Browse Groups', 'movies' => 'Browse Groups - Search: movies'] as $search => $title) {
                $view = $controller->show(Request::create('/browsegroup', 'GET', ['search' => (string) $search]));
                $this->assertInstanceOf(View::class, $view);
                $this->assertSame('layouts.main', $view->name());
                $this->assertSame($title, $view->getData()['meta_title']);
                $this->assertStringContainsString('Movie fixture', $view->getData()['content']);
            }
        }
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w27-browse-nfo-contact-status-controllers-test', BrowseController::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicit optimized library and Wave 27.2 export must load; native coverage must not silently skip.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 2048);
        $fields = ['useremail' => 'a@example.org', 'username' => 'Alice', 'comment' => 'Hello, indexer', '_token' => 'secret', 'submit' => 'Send', 'g-recaptcha-response' => 'captcha', 'cf-turnstile-response' => 'captcha'];

        return [
            [BrowseController::class, 'resolveCategoryId', [['All', 2000, null], ['HD', 2000, 2040], ['missing', null, null]], [['all', PHP_INT_MIN, PHP_INT_MAX], ['All', null, PHP_INT_MAX], [$long, 0, 0]]],
            [BrowseGroupController::class, 'browseMeta', [[''], ['alt.binaries.movies']], [['0'], ['00'], [$long]]],
            [NfoController::class, 'nfoContext', [['Release.1080p', false], ['Release.1080p', true]], [[$long, false], [$long, true], ['', false]]],
            [ContactUsController::class, 'contactSubmission', [[$fields, 'a@example.org', 'Index', PHP_EOL]], [[['comment' => $long, 'null' => null, 0 => 'zero', 'bool' => true, '_token' => 'secret'], "a\nb", $long, "\r\n"], [[], "a\rb", '', '']]],
            [ContactUsController::class, 'canSendContactEmail', [['a@example.org']], [["a\r\nb"], ["a\rb"], [$long]]],
            [ContactUsController::class, 'thankYouHtml', [['Index']], [[$long], ['']]],
            [StatusPageController::class, 'incidentGroupDate', [['2026-10-24']], [[null], [''], [$long]]],
            [StatusPageController::class, 'pageMeta', [[]], [[]]],
        ];
    }

    /** @return list<array{int, list<mixed>, mixed}> */
    private static function rawVectors(): array
    {
        return [
            [0, ['All', 2000, 2040], 2000], [0, ['HD', 2000, null], -1], [0, ['HD', null, PHP_INT_MAX], PHP_INT_MAX],
            [1, ['0'], ['meta_title' => 'Browse Groups', 'meta_keywords' => 'browse,groups,description,details', 'meta_description' => 'Browse groups']],
            [1, ["a\0\xff"], ['meta_title' => "Browse Groups - Search: a\0\xff", 'meta_keywords' => 'browse,groups,description,details', 'meta_description' => "Browse groups search results for a\0\xff"]],
            [2, ['Name', true], ['modal' => true]],
            [2, ['Name', false], ['modal' => false, 'meta_title' => 'View NFO - Name', 'meta_keywords' => 'view,nzb,nfo,description,details', 'meta_description' => 'View NFO File for Name']],
            [3, [[['submit', 'skip'], ['_token', 'skip'], ['g-recaptcha-response', 'skip'], ['cf-turnstile-response', 'skip'], ['message', "a\0\xff"]], "a\nb", 'Index', "\r\n"], ['mailBody' => "Values submitted from contact form: message : a\0\xff\r\n", 'send' => false, 'msg' => "<h2 style='text-align:center;'>Thank you for getting in touch with Index.</h2>"]],
            [4, ["a\nb"], false], [4, ["a\rb"], true], [4, ["\0\xff"], true],
            [5, ['Index'], "<h2 style='text-align:center;'>Thank you for getting in touch with Index.</h2>"],
            [6, [null], 'unknown'], [6, [''], ''], [6, ["a\0\xff"], "a\0\xff"],
            [7, [], ['meta_title' => 'Service status', 'meta_description' => 'Current status of site services, incidents, and uptime.', 'title' => 'Service status']],
        ];
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave27-2-browse-nfo-contact-status-controllers-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 27022026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'uncertainty' => 'Three-round min/max; descriptive, not a confidence interval. 8200 calls/side/round, 24600/cell.',
                'scope' => 'Public helper dispatch including scalar normalization, serialization, allocation, FFI and reconstruction. SQL stays unchanged; excludes app boot and database I/O. No production throughput claim.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w27-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustBrowseNfoContactStatusControllersParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'resource_limits' => self::command(['/bin/sh', '-c', 'ulimit -a']),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'cargo' => self::command(['cargo', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w27-2 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php',
            'app/Http/Controllers/BrowseController.php', 'app/Http/Controllers/BrowseGroupController.php', 'app/Http/Controllers/NfoController.php', 'app/Http/Controllers/ContactUsController.php', 'app/Http/Controllers/StatusPageController.php', 'tests/Unit/RustBrowseNfoContactStatusControllersParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937(27022026));
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
            fwrite(STDERR, "\nWave 27.2 raw benchmark evidence: {$path}\n");
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
