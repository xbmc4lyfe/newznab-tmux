<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminAjaxController;
use App\Http\Controllers\Admin\AdminGroupController;
use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Requests\Admin\AdminGroupListRequest;
use App\Models\Collection;
use App\Models\Content;
use App\Models\MissedPart;
use App\Models\UsenetGroup;
use App\Services\Releases\ReleaseManagementService;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Spatie\Permission\Middleware\RoleMiddleware;
use Tests\TestCase;

class AdminGroupControllerTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-admin-group-test.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        $this->createSchema();
        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_create_bulk_casts_string_flags_before_calling_add_bulk(): void
    {
        $request = Request::create('/admin/group-bulk', 'POST', [
            'action' => 'submit',
            'groupfilter' => " \n",
            'active' => '1',
            'backfill' => '0',
        ]);

        $response = app(AdminGroupController::class)->createBulk($request);

        $this->assertInstanceOf(View::class, $response);
        $this->assertSame('admin.groups.bulk', $response->name());
        $this->assertSame('Bulk Add Newsgroups', $response->getData()['title']);
        $this->assertSame('No group list provided.', $response->getData()['groupmsglist']);
    }

    public function test_edit_submit_converts_min_size_unit_to_bytes(): void
    {
        $this->createUsenetGroupsTable();
        $groupId = DB::table('usenet_groups')->insertGetId([
            'name' => 'alt.binaries.test',
            'description' => 'Test group',
            'active' => 1,
            'backfill' => 1,
            'minsizetoformrelease' => null,
            'minfilestoformrelease' => null,
        ]);

        $request = Request::create('/admin/group-edit', 'POST', [
            'action' => 'submit',
            'id' => (string) $groupId,
            'name' => 'alt.binaries.test',
            'description' => 'Test group',
            'backfill_target' => '1',
            'first_record' => '0',
            'last_record' => '0',
            'active' => '1',
            'backfill' => '1',
            'minsizetoformrelease' => '2',
            'minsizetoformrelease_unit' => 'GB',
            'minfilestoformrelease' => '1',
        ]);

        $response = app(AdminGroupController::class)->edit($request);

        $this->assertTrue($response->isRedirect());
        $this->assertSame(2147483648, (int) DB::table('usenet_groups')->where('id', $groupId)->value('minsizetoformrelease'));
    }

    public function test_edit_submit_keeps_blank_min_size_as_null(): void
    {
        $this->createUsenetGroupsTable();
        $groupId = DB::table('usenet_groups')->insertGetId([
            'name' => 'alt.binaries.test',
            'description' => 'Test group',
            'active' => 1,
            'backfill' => 1,
            'minsizetoformrelease' => 1048576,
            'minfilestoformrelease' => null,
        ]);

        $request = Request::create('/admin/group-edit', 'POST', [
            'action' => 'submit',
            'id' => (string) $groupId,
            'name' => 'alt.binaries.test',
            'description' => 'Test group',
            'backfill_target' => '1',
            'first_record' => '0',
            'last_record' => '0',
            'active' => '1',
            'backfill' => '1',
            'minsizetoformrelease' => '',
            'minsizetoformrelease_unit' => 'MB',
            'minfilestoformrelease' => '1',
        ]);

        app(AdminGroupController::class)->edit($request);

        $this->assertNull(DB::table('usenet_groups')->where('id', $groupId)->value('minsizetoformrelease'));
    }

    public function test_edit_view_exposes_group_min_size_split_into_value_and_unit(): void
    {
        $this->createUsenetGroupsTable();
        $groupId = DB::table('usenet_groups')->insertGetId([
            'name' => 'alt.binaries.test',
            'description' => 'Test group',
            'active' => 1,
            'backfill' => 1,
            'minsizetoformrelease' => 2147483648,
            'minfilestoformrelease' => null,
        ]);

        $request = Request::create('/admin/group-edit', 'GET', ['id' => (string) $groupId]);

        $response = app(AdminGroupController::class)->edit($request);

        $this->assertInstanceOf(View::class, $response);
        $this->assertSame(['value' => 2, 'unit' => 'GB'], $response->getData()['groupMinSize']);
        $this->assertSame(['MB', 'GB'], $response->getData()['sizeUnits']);
    }

    public function test_group_list_counts_each_groups_releases(): void
    {
        $this->createUsenetGroupsTable();
        $this->createReleasesTable();
        $busyId = DB::table('usenet_groups')->insertGetId(['name' => 'alt.binaries.boneless', 'active' => 1]);
        $quietId = DB::table('usenet_groups')->insertGetId(['name' => 'alt.binaries.boneless.nl', 'active' => 1]);
        $emptyId = DB::table('usenet_groups')->insertGetId(['name' => 'alt.binaries.empty', 'active' => 1]);
        DB::table('releases')->insert([
            ['groups_id' => $busyId], ['groups_id' => $busyId], ['groups_id' => $busyId], ['groups_id' => $quietId],
        ]);

        $groups = collect(UsenetGroup::getGroupsRange('', null, true)->items())->keyBy('id');

        $this->assertSame(3, $groups[$busyId]->num_releases);
        $this->assertSame(1, $groups[$quietId]->num_releases);
        $this->assertSame(0, $groups[$emptyId]->num_releases);
        $this->assertNull(UsenetGroup::getGroupsRange()->items()[0]->num_releases);
    }

    /**
     * @param  list<string>  $ascending
     * @param  list<string>  $descending
     */
    #[DataProvider('columnSorts')]
    public function test_group_list_sorts_all_data_columns_before_pagination(string $sort, array $ascending, array $descending): void
    {
        $this->seedListGroups();
        config(['nntmux.items_per_page' => 2]);

        foreach (['asc' => $ascending, 'desc' => $descending] as $direction => $expected) {
            $firstPage = $this->groupListView(['sort' => $sort, 'direction' => $direction]);
            $secondPage = $this->groupListView(['sort' => $sort, 'direction' => $direction, 'page' => 2]);
            $actual = array_values([
                ...array_map(static fn (UsenetGroup $group): string => (string) $group->name, $firstPage->getData()['grouplist']->items()),
                ...array_map(static fn (UsenetGroup $group): string => (string) $group->name, $secondPage->getData()['grouplist']->items()),
            ]);

            $this->assertSame($expected, $actual, $sort.' '.$direction);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function columnSorts(): array
    {
        $numericAscending = ['alt.binaries.c', 'alt.binaries.b', 'alt.binaries.a'];
        $numericDescending = ['alt.binaries.a', 'alt.binaries.b', 'alt.binaries.c'];

        return [
            'group' => ['name', $numericDescending, $numericAscending],
            'first post' => ['first_post', $numericAscending, $numericDescending],
            'last post' => ['last_post', $numericAscending, $numericDescending],
            'last updated' => ['last_updated', $numericAscending, $numericDescending],
            'status' => ['active', ['alt.binaries.c', 'alt.binaries.a', 'alt.binaries.b'], $numericDescending],
            'backfill' => ['backfill', ['alt.binaries.c', 'alt.binaries.a', 'alt.binaries.b'], $numericDescending],
            'releases' => ['releases', $numericAscending, $numericDescending],
            'min files' => ['min_files', $numericAscending, $numericDescending],
            'min size' => ['min_size', $numericAscending, $numericDescending],
            'backfill days' => ['backfill_days', $numericAscending, $numericDescending],
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @param  list<string>  $expected
     */
    #[DataProvider('columnFilters')]
    public function test_group_list_filters_each_data_column(array $filters, array $expected): void
    {
        $this->seedListGroups();

        $page = $this->groupListView($filters)->getData()['grouplist'];

        $this->assertSame($expected, array_map(static fn (UsenetGroup $group): string => (string) $group->name, $page->items()));
        $this->assertSame(count($expected), $page->total());
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: list<string>}>
     */
    public static function columnFilters(): array
    {
        $middle = ['alt.binaries.b'];

        return [
            'group' => [['groupname' => '  binaries.b  '], $middle],
            'first post' => [['first_post_from' => '2026-10-12', 'first_post_to' => '2026-10-12'], $middle],
            'last post' => [['last_post_from' => '2026-10-12', 'last_post_to' => '2026-10-12'], $middle],
            'last updated' => [['last_updated_from' => '2026-10-12', 'last_updated_to' => '2026-10-12'], $middle],
            'status' => [['active' => '0'], ['alt.binaries.c']],
            'backfill' => [['backfill' => '0'], ['alt.binaries.c']],
            'releases' => [['releases_min' => '3', 'releases_max' => '8'], $middle],
            'min files' => [['min_files_min' => '3', 'min_files_max' => '8'], $middle],
            'min size in MB' => [['min_size_min' => '4.5', 'min_size_max' => '5.5'], $middle],
            'backfill days' => [['backfill_days_min' => '3', 'backfill_days_max' => '8'], $middle],
            'upper bound only' => [['releases_max' => '5'], ['alt.binaries.b', 'alt.binaries.c']],
            'combined filters' => [['active' => '1', 'backfill' => '1', 'releases_min' => '3', 'min_files_max' => '8'], $middle],
        ];
    }

    public function test_filters_and_sort_survive_pagination_and_header_links(): void
    {
        $this->seedListGroups();
        config(['nntmux.items_per_page' => 1]);
        $parameters = ['groupname' => 'binaries', 'active' => '1', 'backfill' => '1', 'releases_min' => '3', 'sort' => 'releases', 'direction' => 'desc'];
        $view = $this->groupListView($parameters + ['page' => 2]);
        $page = $view->getData()['grouplist'];
        parse_str(parse_url($page->url(1), PHP_URL_QUERY), $query);

        $this->assertEquals($parameters + ['page' => '1'], $query);
        $this->assertSame(['alt.binaries.b'], array_map(static fn (UsenetGroup $group): string => (string) $group->name, $page->items()));

        $html = $view->render();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $headers = $xpath->query('//th/a');
        $this->assertNotFalse($headers);
        $this->assertCount(10, $headers);
        foreach ($headers as $header) {
            $this->assertInstanceOf(\DOMElement::class, $header);
            parse_str((string) parse_url($header->getAttribute('href'), PHP_URL_QUERY), $headerQuery);
            $this->assertArrayNotHasKey('page', $headerQuery);
            $this->assertSame('3', $headerQuery['releases_min']);
            $this->assertSame('1', $headerQuery['active']);
            if ($headerQuery['sort'] === 'releases') {
                $this->assertSame('asc', $headerQuery['direction']);
                $this->assertInstanceOf(\DOMElement::class, $header->parentNode);
                $this->assertSame('descending', $header->parentNode->getAttribute('aria-sort'));
            }
        }
        $this->assertStringContainsString('name="sort" value="releases"', $html);
        $this->assertStringContainsString('name="direction" value="desc"', $html);
    }

    public function test_empty_filtered_list_still_renders_filter_controls(): void
    {
        $this->seedListGroups();

        $html = $this->groupListView(['releases_min' => '100'])->render();

        $this->assertStringContainsString('name="groupname"', $html);
        $this->assertStringContainsString('name="releases_min"', $html);
        $this->assertStringContainsString('Clear filters', $html);
        $this->assertStringContainsString('No groups match', $html);
    }

    public function test_invalid_range_redirect_preserves_the_submitted_filter_form(): void
    {
        $this->seedListGroups();
        config(['nntmux.items_per_page' => 1]);
        $this->withoutMiddleware([
            Authenticate::class,
            Google2FAMiddleware::class,
            RoleMiddleware::class,
        ]);
        $previousUrl = route('admin.group-list', ['sort' => 'releases', 'direction' => 'desc']);
        $parameters = [
            'groupname' => 'binaries.b', 'active' => '1', 'backfill' => '1',
            'first_post_from' => '2026-10-12', 'releases_min' => '10', 'releases_max' => '2',
            'min_size_min' => '4.5', 'sort' => 'releases', 'direction' => 'desc',
        ];

        $this->from($previousUrl)->get(route('admin.group-list', $parameters))
            ->assertRedirect($previousUrl)->assertSessionHasErrors('releases_max');
        $response = $this->get($previousUrl)->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML((string) $response->getContent());
        $xpath = new \DOMXPath($dom);

        foreach (['groupname', 'first_post_from', 'releases_min', 'releases_max', 'min_size_min', 'sort', 'direction'] as $field) {
            $nodes = $xpath->query('//input[@name="'.$field.'"]');
            $this->assertNotFalse($nodes);
            $node = $nodes->item(0);
            $this->assertInstanceOf(\DOMElement::class, $node);
            $this->assertSame($parameters[$field], $node->getAttribute('value'), $field);
        }
        foreach (['active', 'backfill'] as $field) {
            $nodes = $xpath->query('//select[@name="'.$field.'"]/option[@selected]');
            $this->assertNotFalse($nodes);
            $node = $nodes->item(0);
            $this->assertInstanceOf(\DOMElement::class, $node);
            $this->assertSame('1', $node->getAttribute('value'));
        }
        $openDetails = $xpath->query('//details[@open]');
        $this->assertNotFalse($openDetails);
        $this->assertCount(1, $openDetails);
        $headers = $xpath->query('//th/a');
        $this->assertNotFalse($headers);
        foreach ($headers as $header) {
            $this->assertInstanceOf(\DOMElement::class, $header);
            parse_str((string) parse_url($header->getAttribute('href'), PHP_URL_QUERY), $headerQuery);
            $this->assertArrayNotHasKey('releases_min', $headerQuery);
        }
    }

    public function test_zero_release_and_unconfigured_minimum_filters_include_empty_groups(): void
    {
        $this->seedListGroups();
        DB::table('usenet_groups')->insert(['name' => 'alt.binaries.empty']);

        $page = $this->groupListView(['releases_max' => '0', 'min_files_max' => '0', 'min_size_max' => '0'])->getData()['grouplist'];

        $this->assertSame(['alt.binaries.empty'], array_map(static fn (UsenetGroup $group): string => (string) $group->name, $page->items()));
        $this->assertSame(0, $page->items()[0]->num_releases);
    }

    public function test_active_and_inactive_routes_keep_their_status_scope(): void
    {
        $this->seedListGroups();

        $active = $this->groupListView(['active' => '0', 'sort' => 'releases', 'direction' => 'desc'], 'active')->getData()['grouplist'];
        $inactive = $this->groupListView(['active' => '1', 'backfill' => '0'], 'inactive')->getData()['grouplist'];

        $this->assertSame(['alt.binaries.a', 'alt.binaries.b'], array_map(static fn (UsenetGroup $group): string => (string) $group->name, $active->items()));
        $this->assertSame(['alt.binaries.c'], array_map(static fn (UsenetGroup $group): string => (string) $group->name, $inactive->items()));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[DataProvider('invalidListInputs')]
    public function test_group_list_rejects_invalid_sort_and_filters(array $parameters, string $field): void
    {
        $this->seedListGroups();

        try {
            $this->groupListView($parameters);
            $this->fail('Expected invalid list parameters to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidListInputs(): array
    {
        return [
            'sort injection' => [['sort' => 'name desc; DROP TABLE releases'], 'sort'],
            'direction injection' => [['direction' => 'desc; SELECT 1'], 'direction'],
            'array status' => [['active' => ['1']], 'active'],
            'invalid backfill' => [['backfill' => '2'], 'backfill'],
            'negative releases' => [['releases_min' => '-1'], 'releases_min'],
            'fractional files' => [['min_files_min' => '1.5'], 'min_files_min'],
            'inverted range' => [['releases_min' => '10', 'releases_max' => '2'], 'releases_max'],
            'invalid date' => [['first_post_from' => '2026-02-31'], 'first_post_from'],
            'inverted dates' => [['last_post_from' => '2026-10-15', 'last_post_to' => '2026-10-12'], 'last_post_to'],
        ];
    }

    private function seedListGroups(): void
    {
        $this->createUsenetGroupsTable();
        $this->createReleasesTable();
        foreach ([['c', 2, 0, '10'], ['a', 10, 1, '15'], ['b', 5, 1, '12']] as [$suffix, $number, $enabled, $day]) {
            $id = DB::table('usenet_groups')->insertGetId([
                'name' => 'alt.binaries.'.$suffix,
                'active' => $enabled,
                'backfill' => $enabled,
                'first_record_postdate' => '2026-10-'.$day.' 00:00:00',
                'last_record_postdate' => '2026-10-'.$day.' 23:59:59',
                'last_updated' => '2026-10-'.$day.' 12:00:00',
                'minfilestoformrelease' => $number,
                'minsizetoformrelease' => $number * 1048576,
                'backfill_target' => $number,
            ]);
            DB::table('releases')->insert(array_fill(0, $number, ['groups_id' => $id]));
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function groupListView(array $parameters, string $method = 'index'): View
    {
        $request = AdminGroupListRequest::create('/admin/group-list', 'GET', $parameters);
        $request->setContainer($this->app)->setRedirector(app('redirect'));
        $this->app->instance('request', $request);
        app('url')->setRequest($request);
        $request->validateResolved();

        return app(AdminGroupController::class)->{$method}($request);
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createSchema(): void
    {

        if (! Schema::hasTable('content')) {
            Schema::create('content', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title')->default('');
                $table->string('url', 2000)->nullable();
                $table->text('body')->nullable();
                $table->string('metadescription', 1000)->default('');
                $table->string('metakeywords', 1000)->default('');
                $table->integer('contenttype')->default(Content::TYPE_USEFUL);
                $table->integer('status')->default(Content::STATUS_ENABLED);
                $table->integer('ordinal')->nullable();
                $table->integer('role')->default(Content::ROLE_EVERYONE);
            });
        }
    }

    private function createUsenetGroupsTable(): void
    {
        if (Schema::hasTable('usenet_groups')) {
            return;
        }

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->default('');
            $table->string('description')->default('');
            $table->unsignedBigInteger('first_record')->default(0);
            $table->unsignedBigInteger('last_record')->default(0);
            $table->dateTime('first_record_postdate')->nullable();
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
            $table->boolean('active')->default(false);
            $table->boolean('backfill')->default(false);
            $table->unsignedBigInteger('minsizetoformrelease')->nullable();
            $table->unsignedBigInteger('minfilestoformrelease')->nullable();
            $table->integer('backfill_target')->default(1);
        });
    }

    private function createReleasesTable(): void
    {
        if (Schema::hasTable('releases')) {
            return;
        }

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid', 40)->default('');
            $table->unsignedInteger('groups_id')->index();
        });
    }

    private function createCbpmTables(): void
    {
        if (! Schema::hasTable('collections')) {
            Schema::create('collections', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('groups_id')->default(0);
                $table->string('subject')->default('');
            });
        }

        if (! Schema::hasTable('collection_groups')) {
            Schema::create('collection_groups', function (Blueprint $table): void {
                $table->unsignedInteger('collections_id');
                $table->string('group_name')->default('');
            });
        }

        if (! Schema::hasTable('binaries')) {
            Schema::create('binaries', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('collections_id')->default(0);
                $table->string('name')->default('');
            });
        }

        if (! Schema::hasTable('parts')) {
            Schema::create('parts', function (Blueprint $table): void {
                $table->unsignedBigInteger('binaries_id');
                $table->unsignedInteger('partnumber')->default(1);
                $table->string('messageid')->default('');
            });
        }

        if (! Schema::hasTable('missed_parts')) {
            Schema::create('missed_parts', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedBigInteger('numberid')->default(0);
                $table->unsignedInteger('groups_id')->default(0);
            });
        }
    }

    public function test_reset_group_deletes_group_collections_binaries_parts_and_missed_parts(): void
    {
        $this->createUsenetGroupsTable();
        $this->createCbpmTables();

        $targetGroupId = DB::table('usenet_groups')->insertGetId([
            'name' => 'alt.binaries.target',
            'active' => 1,
            'backfill' => 1,
            'first_record' => 100,
            'last_record' => 200,
            'first_record_postdate' => '2026-10-10 00:00:00',
            'last_record_postdate' => '2026-10-11 00:00:00',
            'last_updated' => '2026-10-12 00:00:00',
            'backfill_target' => 5,
        ]);
        $otherGroupId = DB::table('usenet_groups')->insertGetId([
            'name' => 'alt.binaries.other',
            'active' => 1,
            'backfill' => 1,
            'first_record' => 300,
            'last_record' => 400,
            'backfill_target' => 3,
        ]);

        $targetCollectionId = DB::table('collections')->insertGetId(['groups_id' => $targetGroupId, 'subject' => 'target']);
        $otherCollectionId = DB::table('collections')->insertGetId(['groups_id' => $otherGroupId, 'subject' => 'other']);

        DB::table('collection_groups')->insert([
            ['collections_id' => $targetCollectionId, 'group_name' => 'alt.binaries.target'],
            ['collections_id' => $otherCollectionId, 'group_name' => 'alt.binaries.other'],
        ]);

        $targetBinaryId = DB::table('binaries')->insertGetId(['collections_id' => $targetCollectionId, 'name' => 'target.rar']);
        $otherBinaryId = DB::table('binaries')->insertGetId(['collections_id' => $otherCollectionId, 'name' => 'other.rar']);

        DB::table('parts')->insert([
            ['binaries_id' => $targetBinaryId, 'partnumber' => 1, 'messageid' => '<target@news>'],
            ['binaries_id' => $otherBinaryId, 'partnumber' => 1, 'messageid' => '<other@news>'],
        ]);

        DB::table('missed_parts')->insert([
            ['numberid' => 101, 'groups_id' => $targetGroupId],
            ['numberid' => 301, 'groups_id' => $otherGroupId],
        ]);

        $updated = UsenetGroup::reset($targetGroupId);

        $this->assertSame(1, $updated);
        $this->assertSame([$otherCollectionId], Collection::query()->pluck('id')->all());
        $this->assertSame([$otherCollectionId], DB::table('collection_groups')->pluck('collections_id')->all());
        $this->assertSame([$otherBinaryId], DB::table('binaries')->pluck('id')->all());
        $this->assertSame([$otherBinaryId], DB::table('parts')->pluck('binaries_id')->all());
        $this->assertSame([$otherGroupId], MissedPart::query()->pluck('groups_id')->all());

        $resetGroup = DB::table('usenet_groups')->where('id', $targetGroupId)->first();
        $this->assertSame(0, (int) $resetGroup->active);
        $this->assertSame(0, (int) $resetGroup->first_record);
        $this->assertSame(0, (int) $resetGroup->last_record);
        $this->assertSame(1, (int) $resetGroup->backfill_target);
        $this->assertNull($resetGroup->first_record_postdate);
        $this->assertNull($resetGroup->last_record_postdate);
        $this->assertNull($resetGroup->last_updated);
    }

    public function test_delete_group_returns_bool_and_purges_group_artifacts_via_admin_ajax(): void
    {
        $this->createUsenetGroupsTable();
        $this->createReleasesTable();
        $this->createCbpmTables();

        $groupId = DB::table('usenet_groups')->insertGetId([
            'name' => 'alt.binaries.delete-me',
            'active' => 1,
            'backfill' => 1,
        ]);
        $collectionId = DB::table('collections')->insertGetId(['groups_id' => $groupId, 'subject' => 'delete-me']);
        $binaryId = DB::table('binaries')->insertGetId(['collections_id' => $collectionId, 'name' => 'delete.rar']);
        DB::table('parts')->insert(['binaries_id' => $binaryId, 'partnumber' => 1, 'messageid' => '<del@news>']);
        DB::table('collection_groups')->insert(['collections_id' => $collectionId, 'group_name' => 'alt.binaries.delete-me']);
        DB::table('missed_parts')->insert(['numberid' => 55, 'groups_id' => $groupId]);

        $releaseId = DB::table('releases')->insertGetId([
            'guid' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            'groups_id' => $groupId,
        ]);

        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        $releaseManagement->shouldReceive('deleteSingleWithService')
            ->once()
            ->withArgs(function (array $identifiers) use ($releaseId): bool {
                DB::table('releases')->where('id', $identifiers['i'])->delete();

                return $identifiers['g'] === 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
                    && (int) $identifiers['i'] === $releaseId;
            });
        $this->app->instance(ReleaseManagementService::class, $releaseManagement);

        $request = Request::create('/admin/ajax', 'POST', [
            'action' => 'delete_group',
            'group_id' => (string) $groupId,
        ]);

        $response = app(AdminAjaxController::class)->ajaxAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => "Group {$groupId} deleted successfully",
        ], $response->getData(true));

        $this->assertFalse(DB::table('usenet_groups')->where('id', $groupId)->exists());
        $this->assertSame(0, DB::table('collections')->where('groups_id', $groupId)->count());
        $this->assertSame(0, DB::table('binaries')->where('id', $binaryId)->count());
        $this->assertSame(0, DB::table('parts')->where('binaries_id', $binaryId)->count());
        $this->assertSame(0, DB::table('collection_groups')->where('collections_id', $collectionId)->count());
        $this->assertSame(0, DB::table('missed_parts')->where('groups_id', $groupId)->count());
        $this->assertSame(0, DB::table('releases')->where('groups_id', $groupId)->count());
        $this->assertFalse(UsenetGroup::deleteGroup($groupId));
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
