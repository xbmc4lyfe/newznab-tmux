<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\AdminAjaxController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminContentController;
use App\Http\Controllers\Admin\AdminReleaseReportController;
use App\Http\Controllers\Admin\AdminReleasesController;
use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Models\Content;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

final class RustAdminCategoryReleaseContentControllersParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [
        AdminCategoryController::class,
        AdminReleasesController::class,
        AdminReleaseReportController::class,
        AdminAjaxController::class,
        AdminContentController::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
        parent::tearDown();
    }

    public function test_php_helpers_preserve_the_original_controller_rules(): void
    {
        $this->assertSame(
            ['root_categories_id' => null, 'status' => Category::STATUS_ACTIVE, 'description' => 'd', 'disablepreview' => false, 'minsizetoformrelease' => 0, 'maxsizetoformrelease' => 0],
            AdminCategoryController::categoryAttributesPhp('0', 'd')
        );
        $this->assertSame('00', AdminCategoryController::categoryAttributesPhp('00', null)['root_categories_id']);
        $this->assertSame(['error', 'Cannot delete category "Movies" because it has child categories. Remove or reassign them first.'], AdminCategoryController::deleteFlashPhp(AdminCategoryController::DELETE_HAS_CHILDREN, 'Movies'));
        $this->assertSame(['success', 'Category "" deleted successfully.'], AdminCategoryController::deleteFlashPhp(AdminCategoryController::DELETE_DELETED));

        $this->assertSame(AdminReleasesController::REDIRECT_HOME, AdminReleasesController::destroyRedirectTargetPhp('https://x/details/abc', true));
        $this->assertSame(AdminReleasesController::REDIRECT_INTENDED, AdminReleasesController::destroyRedirectTargetPhp('https://x/browse', true));
        $this->assertSame(AdminReleasesController::REDIRECT_RELEASE_LIST, AdminReleasesController::destroyRedirectTargetPhp(null, false));
        $this->assertSame('3 release(s) re-categorised.', AdminReleasesController::bulkCategoryMessagePhp(3));

        $this->assertSame(['dismissed', 'resolved', 'reviewed', 'reviewed', null], array_map(AdminReleaseReportController::bulkStatusPhp(...), ['dismiss', 'resolve', 'reviewed', 'revert', 'delete']));
        $this->assertSame(['deleted', 'dismissed', 'resolved', 'marked as reviewed', 'reverted to reviewed', 'processed'], array_map(AdminReleaseReportController::bulkActionLabelPhp(...), ['delete', 'dismiss', 'resolve', 'reviewed', 'revert', 'x']));
        $this->assertSame('2 report(s) reverted to reviewed successfully.', AdminReleaseReportController::bulkActionMessagePhp(2, 'revert'));
        $this->assertTrue(AdminReleaseReportController::canRevertPhp('dismissed'));
        $this->assertTrue(AdminReleaseReportController::canRevertPhp(true), 'Loose in_array semantics are retained.');
        $this->assertFalse(AdminReleaseReportController::canRevertPhp('reviewed'));
        $this->assertSame(['cleared' => true, 'response' => null, 'response_is_public' => true], AdminReleaseReportController::responseStatePhp(" \t\0 ", false));
        $this->assertSame(['cleared' => false, 'response' => 'ok', 'response_is_public' => false], AdminReleaseReportController::responseStatePhp(' ok ', false));

        $this->assertSame(AdminAjaxController::ACTION_PURGE_GROUP, AdminAjaxController::actionKindPhp('group_edit_purge_single'));
        $this->assertSame(AdminAjaxController::ACTION_BLACKLIST_DELETE, AdminAjaxController::actionKindPhp(true), 'Loose switch semantics are retained.');
        $this->assertSame(AdminAjaxController::ACTION_UNKNOWN, AdminAjaxController::actionKindPhp(0));
        $this->assertSame('release_naming_regexes', AdminAjaxController::regexTablePhp('release_naming_regex_delete'));
        $this->assertSame([1, 2, 0, 1], AdminAjaxController::selectedGroupIdsPhp('[1,"2","x",[3]]'));
        $this->assertNull(AdminAjaxController::selectedGroupIdsPhp('[]'));
        $this->assertNull(AdminAjaxController::selectedGroupIdsPhp('{'));
        $this->assertSame(12, AdminAjaxController::activeStatusFlagPhp(true, ' 12abc'));
        $this->assertSame(0, AdminAjaxController::backfillStatusFlagPhp(true, null, true, '1'));
        $this->assertSame(1, AdminAjaxController::backfillStatusFlagPhp(false, null, true, '1'));
        $this->assertSame('3 group(s) reset successfully', AdminAjaxController::responseMessagePhp(AdminAjaxController::MESSAGE_GROUPS_RESET, 3));

        $this->assertSame(['url' => '/about/'], AdminContentController::normalizeContentUrlDataPhp(['url' => 'about']));
        $this->assertSame(['url' => 'example.com'], AdminContentController::normalizeContentUrlDataPhp(['url' => 'example.com']));
        $this->assertSame(['url' => 'https://x'], AdminContentController::normalizeContentUrlDataPhp(['url' => 'https://x']));
        $this->assertSame([3 => 1, 1 => 2, 2 => 3], AdminContentController::reorderOrdinalsPhp([3, 1, 2], [1, 2, 3]));
        $this->assertNull(AdminContentController::reorderOrdinalsPhp([1, 1], [1, 2]));
        $this->assertSame(Content::STATUS_DISABLED, AdminContentController::toggledStatusPhp(Content::STATUS_ENABLED));
        $this->assertSame(Content::STATUS_ENABLED, AdminContentController::toggledStatusPhp('1'));
    }

    public function test_nullable_toggles_are_independent_from_siblings_and_parent(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class.' must keep the PHP production default.');
        }
        BasePageController::setUseRustFfi(true);
        try {
            foreach (self::CLASSES as $class) {
                $this->assertFalse($class::usesRustFfi());
                $class::setUseRustFfi(true);
                $this->assertTrue($class::usesRustFfi());
                foreach (self::CLASSES as $sibling) {
                    if ($sibling !== $class) {
                        $this->assertFalse($sibling::usesRustFfi());
                    }
                }
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
                $this->assertSame($expected, $class::$native(...$args), $class.'::'.$method);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $class.'::'.$method);
                }
            }
            $class::setUseRustFfi(null);
        }
    }

    public function test_native_exports_answer_without_php_fallback(): void
    {
        $ffi = $this->nativeFfi();
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_admin_ctl_category_parent_is_set('00', 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_admin_ctl_category_parent_is_set('0', 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_admin_ctl_release_destroy_redirect(1, "a/details/\0", 11, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_admin_ctl_report_bulk_status('revert', 6));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_admin_ctl_report_can_revert('dismissed', 9));
        // @phpstan-ignore method.notFound
        $this->assertSame(9, $ffi->nntmux_admin_ctl_ajax_action_kind('toggle_group_backfill', 21));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_admin_ctl_content_toggle_status(1, 1, 0));
        $ids = $ffi->new('int64_t[4]');
        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_admin_ctl_ajax_group_ids(' [1,"22",-3] ', 13, $ids, 4));
        $this->assertSame([1, 22, -3], [$ids[0], $ids[1], $ids[2]]);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_admin_ctl_ajax_group_ids('[1.5]', 5, $ids, 4), 'Floats are left to PHP.');
        $value = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_admin_ctl_ajax_status_flag(0, null, 0, 1, '-12', 3, $value));
        $this->assertSame(-12, $value[0]);
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_admin_ctl_ajax_status_flag(1, '1abc', 4, 0, null, 0, $value), 'PHP casts other strings.');
    }

    public function test_direct_abi_short_buffers_do_not_write_and_exact_buffers_have_no_terminator(): void
    {
        $ffi = $this->nativeFfi();
        $long = str_repeat("é\0\xff", 300);
        $vectors = [
            ['nntmux_admin_ctl_category_delete_message', [2, $long, strlen($long)], 'Cannot delete category "'.$long.'" because it has child categories. Remove or reassign them first.'],
            ['nntmux_admin_ctl_category_delete_message', [1, '', 0], 'Category not found.'],
            ['nntmux_admin_ctl_release_bulk_category_message', [PHP_INT_MIN], PHP_INT_MIN.' release(s) re-categorised.'],
            ['nntmux_admin_ctl_report_action_label', ['reviewed', 8], 'marked as reviewed'],
            ['nntmux_admin_ctl_report_bulk_message', [7, 'dismiss', 7], '7 report(s) dismissed successfully.'],
            ['nntmux_admin_ctl_report_trim_response', [" \0x\0 ", 5], 'x'],
            ['nntmux_admin_ctl_ajax_regex_table', ['collection_regex_delete', 23], 'collection_regexes'],
            ['nntmux_admin_ctl_ajax_message', [5, 42], 'Group 42 reset successfully'],
            ['nntmux_admin_ctl_content_normalize_url', ["a\0b", 3], "/a\0b/"],
        ];
        foreach ($vectors as [$symbol, $args, $expected]) {
            $length = strlen($expected);
            $buffer = $ffi->new('char['.($length + 2).']');
            foreach ([0, 1, $length - 1, $length, $length + 1] as $capacity) {
                FFI::memset($buffer, 0x5A, $length + 2);
                $written = self::callExport($ffi, $symbol, ...[...$args, $buffer, $capacity]);
                if ($capacity < $length) {
                    $this->assertSame($length === 1 ? -2 : -$length, $written, $symbol);
                    $this->assertSame(str_repeat('Z', $length + 2), FFI::string($buffer, $length + 2), $symbol.' must not write a short buffer');
                } else {
                    $this->assertSame($length, $written, $symbol);
                    $this->assertSame($expected.'ZZ', FFI::string($buffer, $length + 2), $symbol);
                }
            }
            $this->assertSame(-1, self::callExport($ffi, $symbol, ...[...$args, null, 64]), $symbol.' must reject a null output buffer');
        }

        $buffer = $ffi->new('char[64]');
        FFI::memset($buffer, 0x5A, 64);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_admin_ctl_category_delete_message(9, '', 0, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_admin_ctl_report_trim_response(null, 1, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_admin_ctl_ajax_message(8, 1, $buffer, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($buffer, 64));

        $ordinals = $ffi->new('int64_t[3]');
        $ordered = $ffi->new('int64_t[3]');
        $expected = $ffi->new('int64_t[3]');
        foreach ([3, 1, 2] as $index => $id) {
            $ordered[$index] = $id;
            $expected[$index] = $index + 1;
            $ordinals[$index] = 99;
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-3, $ffi->nntmux_admin_ctl_content_reorder($ordered, 3, $expected, 3, $ordinals, 2));
        $this->assertSame([99, 99, 99], [$ordinals[0], $ordinals[1], $ordinals[2]]);
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_admin_ctl_content_reorder($ordered, 3, $expected, 3, $ordinals, 3));
        $this->assertSame([1, 2, 3], [$ordinals[0], $ordinals[1], $ordinals[2]]);
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_admin_ctl_content_reorder($ordered, 2, $expected, 2, $ordinals, 3));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_admin_ctl_content_reorder(null, 0, $expected, 0, $ordinals, 3));
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
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => '/tmp/w28-3-missing-library.dylib']);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_cached_load_failure_and_call_time_errors_fall_back_for_every_helper(): void
    {
        $this->nativeFfi();
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library);
        $missingSymbols = FFI::cdef('uint32_t nntmux_pipeline_abi_version(void);', $library);
        foreach ([false, $missingSymbols] as $handle) {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $class::setFfiForTesting($handle);
                $class::setUseRustFfi(true);
                $php = $method.'Php';
                $native = $method.'Native';
                foreach ([$common[0], $uncommon[0]] as $args) {
                    $this->assertSame($class::$php(...$args), $class::$native(...$args), $class.'::'.$method);
                    $this->assertSame($class::$php(...$args), $class::$method(...$args), $class.'::'.$method);
                }
                $class::setFfiForTesting(null);
            }
        }
    }

    public function test_real_category_handlers_keep_attributes_and_delete_messages(): void
    {
        $this->nativeFfi();
        Schema::create('categories', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title');
            $table->integer('root_categories_id')->nullable();
            $table->integer('status')->default(0);
            $table->string('description')->nullable();
            $table->boolean('disablepreview')->default(true);
            $table->integer('minsizetoformrelease')->default(9);
            $table->integer('maxsizetoformrelease')->default(9);
        });
        // CategoryObserver reindexes the edited category's releases after commit.
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('categories_id');
        });
        $controller = (new ReflectionClass(AdminCategoryController::class))->newInstanceWithoutConstructor();
        foreach ([false, true] as $enabled) {
            AdminCategoryController::setUseRustFfi($enabled);
            DB::table('categories')->delete();
            DB::table('categories')->insert([['id' => 2000, 'title' => 'Movies', 'root_categories_id' => null], ['id' => 2010, 'title' => 'Foreign', 'root_categories_id' => 2000]]);

            $response = $controller->create(Request::create('/admin/category-add', 'POST', ['action' => 'submit', 'id' => '2020', 'title' => 'HD', 'root_categories_id' => '0', 'description' => 'High']));
            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame('Category created successfully', $response->getSession()?->get('success'));
            $this->assertEquals(
                (object) ['id' => 2020, 'title' => 'HD', 'root_categories_id' => null, 'status' => Category::STATUS_ACTIVE, 'description' => 'High', 'disablepreview' => 0, 'minsizetoformrelease' => 0, 'maxsizetoformrelease' => 0],
                DB::table('categories')->where('id', 2020)->first()
            );
            $controller->edit(Request::create('/admin/category-edit', 'POST', ['action' => 'submit', 'id' => '2020', 'root_categories_id' => '2000', 'description' => 'Edited']));
            $this->assertSame(2000, (int) DB::table('categories')->where('id', 2020)->value('root_categories_id'));
            $this->assertSame('Edited', DB::table('categories')->where('id', 2020)->value('description'));

            $flash = static function (RedirectResponse $response): array {
                $session = $response->getSession();
                $flash = $session?->has('error') ? ['error', $session->get('error')] : ['success', $session?->get('success')];
                $session?->flush();

                return $flash;
            };
            $this->assertSame(['error', 'No category ID provided.'], $flash($controller->destroy(Request::create('/admin/category-delete', 'POST'))));
            $this->assertSame(['error', 'Category not found.'], $flash($controller->destroy(Request::create('/admin/category-delete', 'POST', ['id' => '9']))));
            $this->assertSame(['error', 'Cannot delete category "Movies" because it has child categories. Remove or reassign them first.'], $flash($controller->destroy(Request::create('/admin/category-delete', 'POST', ['id' => '2000']))));
            $this->assertSame(['success', 'Category "Foreign" deleted successfully.'], $flash($controller->destroy(Request::create('/admin/category-delete', 'POST', ['id' => '2010']))));
            $this->assertFalse(DB::table('categories')->where('id', 2010)->exists());
            $this->app['session.store']->flush();
        }
    }

    public function test_real_release_destroy_redirects_by_referer_and_intended_url(): void
    {
        $this->nativeFfi();
        $controller = (new ReflectionClass(AdminReleasesController::class))->newInstanceWithoutConstructor();
        foreach ([false, true] as $enabled) {
            AdminReleasesController::setUseRustFfi($enabled);
            foreach ([
                ['https://site.test/details/abc', '/admin/intended', route('All'), '/admin/intended'],
                ['https://site.test/browse', '/admin/intended', url('/admin/intended'), null],
                [null, null, route('admin.release-list'), null],
            ] as [$referer, $intended, $target, $remaining]) {
                $request = Request::create('/admin/release-delete', 'GET', [], [], [], $referer === null ? [] : ['HTTP_REFERER' => $referer]);
                $request->setLaravelSession($this->app['session.store']);
                $this->app->instance('request', $request);
                session()->forget('intended_redirect');
                if ($intended !== null) {
                    session()->put('intended_redirect', $intended);
                }
                $response = $controller->destroy(null);
                $this->assertInstanceOf(RedirectResponse::class, $response);
                $this->assertSame($target, $response->getTargetUrl());
                $this->assertSame($remaining, session('intended_redirect'));
            }
        }
    }

    public function test_real_report_handlers_keep_response_state_and_revert_rules(): void
    {
        $this->nativeFfi();
        Schema::create('release_reports', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('releases_id')->nullable();
            $table->string('status');
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('response')->nullable();
            $table->integer('responded_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->boolean('response_is_public')->default(false);
            $table->timestamps();
        });
        $controller = new AdminReleaseReportController;
        foreach ([false, true] as $enabled) {
            AdminReleaseReportController::setUseRustFfi($enabled);
            DB::table('release_reports')->delete();
            $id = (int) DB::table('release_reports')->insertGetId(['status' => 'resolved', 'response_is_public' => false]);

            $response = $controller->updateResponse(Request::create('/r', 'POST', ['response' => " \tNoted\0 ", 'response_is_public' => '0']), $id);
            $this->assertSame('Report response saved successfully.', $response->getSession()?->get('success'));
            $report = DB::table('release_reports')->where('id', $id);
            $this->assertSame('Noted', (clone $report)->value('response'));
            $this->assertSame(0, (int) (clone $report)->value('response_is_public'));

            $response = $controller->updateResponse(Request::create('/r', 'POST', ['response' => " \n ", 'response_is_public' => '0']), $id);
            $this->assertSame('Report response cleared successfully.', $response->getSession()?->get('success'));
            $this->assertNull((clone $report)->value('response'));
            $this->assertNull((clone $report)->value('responded_at'));
            $this->assertSame(1, (int) (clone $report)->value('response_is_public'));

            $response = $controller->revert($id);
            $this->assertSame('Report reverted to reviewed status successfully.', $response->getSession()?->get('success'));
            $this->assertSame('reviewed', DB::table('release_reports')->where('id', $id)->value('status'));
            $response = $controller->revert($id);
            $this->assertSame('Only resolved or dismissed reports can be reverted.', $response->getSession()?->get('error'));
            $this->app['session.store']->flush();
        }
    }

    public function test_real_ajax_handler_routes_actions_and_status_flags(): void
    {
        $this->nativeFfi();
        Schema::create('usenet_groups', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('active')->default(false);
            $table->boolean('backfill')->default(false);
        });
        $controller = (new ReflectionClass(AdminAjaxController::class))->newInstanceWithoutConstructor();
        $json = static function (mixed $response): array {
            self::assertInstanceOf(JsonResponse::class, $response);

            return [$response->getStatusCode(), $response->getData(true)];
        };
        foreach ([false, true] as $enabled) {
            AdminAjaxController::setUseRustFfi($enabled);
            DB::table('usenet_groups')->delete();
            $id = (int) DB::table('usenet_groups')->insertGetId(['name' => 'alt.binaries.test']);

            [$status, $data] = $json($controller->ajaxAction(Request::create('/admin/ajax', 'POST', ['action' => 'toggle_group_backfill', 'group_id' => (string) $id, 'backfill' => '1'])));
            $this->assertSame([200, true, 1], [$status, $data['success'], $data['newStatus']]);
            $this->assertSame(1, (int) DB::table('usenet_groups')->where('id', $id)->value('backfill'));

            [, $data] = $json($controller->ajaxAction(Request::create('/admin/ajax', 'POST', ['action' => 'toggle_group_backfill_status', 'group_id' => (string) $id, 'backfill_status' => '0', 'backfill' => '1'])));
            $this->assertSame(0, $data['newStatus']);
            $this->assertSame(0, (int) DB::table('usenet_groups')->where('id', $id)->value('backfill'));

            [, $data] = $json($controller->ajaxAction(Request::create('/admin/ajax', 'POST', ['action' => 'toggle_group_active_status', 'group_id' => (string) $id, 'group_status' => '1'])));
            $this->assertSame(1, $data['newStatus']);
            $this->assertSame(1, (int) DB::table('usenet_groups')->where('id', $id)->value('active'));

            $this->assertSame([400, ['success' => false, 'message' => 'No groups specified']], $json($controller->ajaxAction(Request::create('/admin/ajax', 'POST', ['action' => 'reset_selected_groups', 'group_ids' => '[]']))));
            $this->assertSame([400, ['success' => false, 'message' => 'Unknown action']], $json($controller->ajaxAction(Request::create('/admin/ajax', 'POST', ['action' => 'Purge_group']))));
            $this->assertSame([400, ['success' => false, 'message' => 'No action specified']], $json($controller->ajaxAction(Request::create('/admin/ajax', 'POST'))));
        }
    }

    public function test_real_content_handlers_reorder_and_toggle(): void
    {
        $this->nativeFfi();
        Schema::create('content', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->integer('contenttype');
            $table->integer('status');
            $table->integer('ordinal');
            $table->timestamp('updated_at')->nullable();
        });
        $controller = (new ReflectionClass(AdminContentController::class))->newInstanceWithoutConstructor();
        $normalize = (new ReflectionClass(AdminContentController::class))->getMethod('normalizeContentUrl');
        foreach ([false, true] as $enabled) {
            AdminContentController::setUseRustFfi($enabled);
            DB::table('content')->delete();
            $ids = [];
            foreach ([1, 2, 3] as $ordinal) {
                $ids[] = (int) DB::table('content')->insertGetId(['contenttype' => Content::TYPE_USEFUL, 'status' => Content::STATUS_ENABLED, 'ordinal' => $ordinal]);
            }
            $other = (int) DB::table('content')->insertGetId(['contenttype' => Content::TYPE_INDEX, 'status' => Content::STATUS_ENABLED, 'ordinal' => 1]);

            $response = $controller->reorder(Request::create('/admin/content-reorder', 'POST', ['contenttype' => Content::TYPE_USEFUL, 'ordered_ids' => [$ids[2], $ids[0], $ids[1]]]));
            $this->assertInstanceOf(JsonResponse::class, $response);
            $this->assertSame([$ids[2] => 1, $ids[0] => 2, $ids[1] => 3], $response->getData(true)['ordinals']);
            $this->assertSame([$ids[0] => 2, $ids[1] => 3, $ids[2] => 1], DB::table('content')->whereIn('id', $ids)->orderBy('id')->pluck('ordinal', 'id')->map(static fn ($ordinal): int => (int) $ordinal)->all());

            $response = $controller->reorder(Request::create('/admin/content-reorder', 'POST', ['contenttype' => Content::TYPE_USEFUL, 'ordered_ids' => [$ids[0], $other, $ids[1]]]));
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(2, (int) DB::table('content')->where('id', $ids[0])->value('ordinal'));

            $response = $controller->toggleStatus(Request::create('/admin/content-toggle', 'POST', ['id' => (string) $ids[0]]));
            $this->assertSame(['success' => true, 'status' => Content::STATUS_DISABLED, 'message' => 'Content disabled'], $response->getData(true));
            $response = $controller->toggleStatus(Request::create('/admin/content-toggle', 'POST', ['id' => (string) $ids[0]]));
            $this->assertSame(['success' => true, 'status' => Content::STATUS_ENABLED, 'message' => 'Content enabled'], $response->getData(true));

            $this->assertSame(['url' => '/faq/'], $normalize->invoke($controller, ['url' => 'faq']));
            $this->assertSame(['url' => 'nzb.example/faq'], $normalize->invoke($controller, ['url' => 'nzb.example/faq']));
        }
    }

    private function nativeFfi(): FFI
    {
        $cdef = implode("\n", array_map(static fn (string $class): string => $class::FFI_CDEF, self::CLASSES));
        $ffi = RustPipelineLibrary::resolveDomain('w28-admin-category-release-content-controllers-test', $cdef, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicit optimized library and Wave 28.3 exports must load; native coverage must not silently skip.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 2048);
        $actions = ['binary_blacklist_delete', 'category_regex_delete', 'collection_regex_delete', 'release_naming_regex_delete', 'group_edit_purge_all', 'purge_all_groups',
            'group_edit_reset_all', 'reset_all_groups', 'group_edit_purge_single', 'purge_group', 'group_edit_reset_single', 'reset_group', 'reset_selected_groups',
            'group_edit_delete_single', 'delete_group', 'toggle_group_active_status', 'toggle_group_backfill_status', 'toggle_group_backfill', 'nope'];
        $reportActions = [['dismiss'], ['resolve'], ['reviewed'], ['revert'], ['delete']];
        $odd = [['Dismiss'], [''], ["dismiss\0"], [$long]];
        $manyIds = range(1, 500);
        $shuffled = array_reverse($manyIds);

        return [
            [AdminCategoryController::class, 'categoryAttributes', [['5', 'desc'], ['', 'd'], [null, null]],
                [['0', "x\0\xff"], ['00', ''], [' ', 'd'], [5, 'int'], [0, 'zero'], [['a'], 'arr'], [$long, $long]]],
            [AdminCategoryController::class, 'deleteFlash', [[0, null], [1, null], [2, 'Movies'], [3, 'Movies']],
                [[2, $long], [3, ''], [3, "A\0\xff"], [0, 'ignored']]],
            [AdminReleasesController::class, 'destroyRedirectTarget', [['https://x/details/abc', true], [null, true], [null, false], ['https://x/admin/release-list', false]],
                [['', true], ['0', false], ["/details/\0", false], [$long, true], [$long.'/details/', false]]],
            [AdminReleasesController::class, 'bulkCategoryMessage', [[1], [25]], [[0], [-1], [PHP_INT_MAX], [PHP_INT_MIN]]],
            [AdminReleaseReportController::class, 'bulkStatus', $reportActions, $odd],
            [AdminReleaseReportController::class, 'bulkActionLabel', $reportActions, $odd],
            [AdminReleaseReportController::class, 'bulkActionMessage', [[3, 'dismiss'], [1, 'delete'], [0, 'revert']], [[PHP_INT_MIN, 'x'], [PHP_INT_MAX, ''], [2, $long]]],
            [AdminReleaseReportController::class, 'canRevert', [['resolved'], ['dismissed'], ['pending'], ['reviewed']],
                [[null], [true], [0], [1], ['Resolved'], ['resolved '], [['resolved']], [$long]]],
            [AdminReleaseReportController::class, 'responseState', [['Thanks', true], ['  spaced  ', false], ['', true]],
                [[" \t\n\r\0\x0B", false], ["\x0Cx\x0C", true], [$long, false], [" \0é\0 ", true]]],
            [AdminAjaxController::class, 'actionKind', array_map(static fn (string $action): array => [$action], $actions),
                [[null], [true], [false], [0], [1], [1.5], [['purge_group']], ['PURGE_GROUP'], ["purge_group\0"], [$long]]],
            [AdminAjaxController::class, 'regexTable', [['category_regex_delete'], ['collection_regex_delete'], ['release_naming_regex_delete']], [['purge_group'], [''], [$long]]],
            [AdminAjaxController::class, 'selectedGroupIds', [['[1,2,3]'], ['["4","5"]'], ['[]']],
                [['[01]'], ['[1.5]'], ['[1e2]'], ['{"a":"7"}'], ['[true,null,[1]]'], ['[-2.9]'], ['"x"'], [''], ['[" 12"]'], ['["12abc"]'], ['[-0]'], ['  [ 7 ]  '], ["[1,\n2]"], [json_encode($manyIds)]]],
            [AdminAjaxController::class, 'activeStatusFlag', [[true, '1'], [true, '0'], [false, null]],
                [[true, null], [true, ''], [true, 'abc'], [true, ' 1'], [true, '1.9'], [true, '1e3'], [true, '+7'], [true, '-0'], [true, '99999999999999999999'], [true, ['x']], [true, 5], [false, '1']]],
            [AdminAjaxController::class, 'backfillStatusFlag', [[true, '1', false, null], [false, null, true, '0'], [false, null, false, null]],
                [[true, '0', true, '1'], [true, null, true, '1'], [false, null, true, 'x'], [false, '1', true, ['a']], [true, '2', true, ['ignored']]]],
            [AdminAjaxController::class, 'responseMessage', [[0, 5], [1, 7], [2, 0], [3, 0], [4, 9], [5, 9], [6, 3], [7, 9]], [[6, 0], [0, PHP_INT_MIN], [7, PHP_INT_MAX]]],
            [AdminContentController::class, 'normalizeContentUrlData', [[['url' => 'about']], [['url' => '/terms/']], [['url' => 'https://example.org/x']], [['url' => 'example.com/page']], [['title' => 'x']]],
                [[['url' => '']], [['url' => null]], [['url' => '/']], [['url' => 'a.b']], [['url' => "\0\xff"]], [['url' => 'HTTP://X']], [['url' => str_repeat('a', 70000)]], [['url' => $long]], [['url' => 'my-site.co']], [['url' => 'x', 'other' => 1]]]],
            [AdminContentController::class, 'reorderOrdinals', [[[3, 1, 2], [1, 2, 3]], [[1, 2], [1, 2, 3]]],
                [[[], []], [[1, 1], [1, 2]], [[1, 2, 2], [1, 1, 2]], [[5 => 10, 6 => 11], [10, 11]], [$shuffled, $manyIds], [[PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MIN]]]],
            [AdminContentController::class, 'toggledStatus', [[1], [0]], [['1'], [null], [2], [-1], [true]]],
        ];
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave28-3-admin-category-release-content-controllers-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 28032026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'uncertainty' => 'Three-round min/max; descriptive, not a confidence interval. 8200 calls/side/round, 24600/cell.',
                'scope' => 'Public helper dispatch including type checks, FFI marshalling, scratch/array allocation and result reconstruction. Excludes app boot, request handling and database I/O. No production throughput claim.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w28-3/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAdminCategoryReleaseContentControllersParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'resource_limits' => self::command(['/bin/sh', '-c', 'ulimit -a']),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'cargo' => self::command(['cargo', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w28-3 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/categorize.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php',
            'app/Http/Controllers/Admin/AdminCategoryController.php', 'app/Http/Controllers/Admin/AdminReleasesController.php', 'app/Http/Controllers/Admin/AdminReleaseReportController.php',
            'app/Http/Controllers/Admin/AdminAjaxController.php', 'app/Http/Controllers/Admin/AdminContentController.php', 'tests/Unit/RustAdminCategoryReleaseContentControllersParityTest.php'] as $file) {
            $report['source_sha256'][$file] = is_file(base_path($file)) ? hash_file('sha256', base_path($file)) : null;
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937(28032026));
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
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
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
                // The production default stays PHP for every helper below the gate.
                $class::setUseRustFfi(null);
                $this->assertFalse($class::usesRustFfi());
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            $report['partial'] = ['helper' => $name ?? null, 'kind' => $kind ?? null, 'round' => $round ?? null, 'pair' => $pair ?? null];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            fwrite(STDERR, "\nWave 28.3 raw benchmark evidence: {$path}\n");
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

    private static function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
