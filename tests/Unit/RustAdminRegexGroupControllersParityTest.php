<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\AdminBlacklistController as Blacklist;
use App\Http\Controllers\Admin\AdminCategoryRegexesController as CategoryRegex;
use App\Http\Controllers\Admin\AdminCollectionRegexesController as CollectionRegex;
use App\Http\Controllers\Admin\AdminGroupController as Group;
use App\Http\Controllers\Admin\AdminReleaseNamingRegexesController as NamingRegex;
use App\Http\Controllers\BasePageController;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Release build and invocation are pinned by the slice request to /tmp/cargo-w28-1.
 * Paired screen: three rounds, 1001 individual-call pairs/path, 51 warmups,
 * seeded randomized execution order, identical common/uncommon fixtures.
 * Raw samples, identities, failures and reproduction commands go to system temp.
 * Includes PHP dispatch, framing, FFI, allocation and decoding; caller screens
 * include Request extraction, View creation and isolated SQLite category reads;
 * rendering, production services and network are excluded.
 * Each path must clear common mean AND uncommon p99 >=1.5x for adoption.
 * Initial release screen (2026-10-24, Apple M5, PHP 8.5.12, rustc 1.99.1):
 * helper common mean speedups 0.019-0.051x; uncommon p99 0.014-0.075x.
 * Isolated edit callers: common mean 0.301-1.031x, uncommon p99 0.297-0.848x.
 * Every helper/caller fails the gate; all five production defaults stay PHP.
 * This CPU/SQLite screen does not establish production request throughput.
 */
class RustAdminRegexGroupControllersParityTest extends TestCase
{
    private const CLASSES = [Blacklist::class, CategoryRegex::class, CollectionRegex::class, NamingRegex::class, Group::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        BasePageController::setUseRustFfi(null);
        (new ReflectionProperty(Blacklist::class, 'wave28Ffi'))->setValue(null, null);
        parent::tearDown();
    }

    /** @return list<array{class-string, string, int, array<int, mixed>, array<string, mixed>, mixed}> */
    private static function cases(): array
    {
        return [
            [Blacklist::class, 'resolveEditState', 0, ['submit', ['groupname' => '', 'regex' => '']], ['action' => 'submit', 'input' => ['groupname' => '', 'regex' => '']], ['error' => 'Group must be a valid usenet group', 'regex' => ['id' => '', 'groupname' => '', 'regex' => '', 'description' => '', 'msgcol' => 1, 'status' => 1, 'optype' => 1], 'title' => 'Binary Black/White list', 'meta_title' => 'Binary Black/White list', 'save' => null, 'load' => false]],
            [Blacklist::class, 'optionMetadata', 1, [], [], ['status_ids' => [1, 0], 'status_names' => ['Yes', 'No'], 'optype_ids' => [1, 2], 'optype_names' => ['Black', 'White'], 'msgcol_ids' => [1, 2, 3], 'msgcol_names' => ['Subject', 'Poster', 'MessageId']]],
            [CategoryRegex::class, 'validateForm', 2, ['submit', ['group_regex' => '0']], ['action' => 'submit', 'input' => ['group_regex' => '0']], ['error' => 'Group regex must not be empty!', 'input' => ['group_regex' => '0'], 'id' => null]],
            [CategoryRegex::class, 'categoryLabels', 3, [[['id' => 2040, 'parent_title' => 'Movies', 'title' => 'HD'], ['id' => '001', 'parent_title' => '', 'title' => "A\0\xff"]]], ['categories' => [['id' => 2040, 'parent_title' => 'Movies', 'title' => 'HD'], ['id' => '001', 'parent_title' => '', 'title' => "A\0\xff"]]], ['category_ids' => [2040, '001'], 'category_names' => ['Movies HD: 2040', " A\0\xff: 001"]]],
            [CollectionRegex::class, 'validateForm', 4, ['submit', ['group_regex' => 'g', 'regex' => 'r', 'description' => '0', 'ordinal' => -1]], ['action' => 'submit', 'input' => ['group_regex' => 'g', 'regex' => 'r', 'description' => '0', 'ordinal' => -1]], ['error' => 'Ordinal must be a number, 0 or higher.', 'input' => ['group_regex' => 'g', 'regex' => 'r', 'description' => '', 'ordinal' => -1], 'id' => null]],
            [CollectionRegex::class, 'defaultRegex', 5, [], [], ['id' => '', 'regex' => '', 'description' => '', 'group_regex' => '', 'ordinal' => '', 'status' => 1]],
            [CollectionRegex::class, 'normalizeTestParameters', 6, [['group' => "\0 g \t", 'regex' => '0', 'limit' => ' 1e2 ']], ['input' => ['group' => "\0 g \t", 'regex' => '0', 'limit' => ' 1e2 ']], ['group' => 'g', 'regex' => '', 'limit' => ' 1e2 ']],
            [NamingRegex::class, 'validateForm', 7, ['view', ['id' => '+12']], ['action' => 'view', 'input' => ['id' => '+12']], ['error' => '', 'input' => ['id' => '+12'], 'id' => 12]],
            [NamingRegex::class, 'normalizeTestParameters', 8, [['group' => ' g ', 'regex' => ' r ', 'showlimit' => '0', 'querylimit' => 'invalid']], ['input' => ['group' => ' g ', 'regex' => ' r ', 'showlimit' => '0', 'querylimit' => 'invalid']], ['group' => 'g', 'regex' => 'r', 'showlimit' => '0', 'querylimit' => 100000]],
            [Group::class, 'defaultGroup', 9, [], [], ['id' => '', 'name' => '', 'description' => '', 'minfilestoformrelease' => 0, 'active' => 0, 'backfill' => 0, 'minsizetoformrelease' => 0, 'first_record' => 0, 'last_record' => 0, 'backfill_target' => 0]],
            [Group::class, 'filterQuery', 10, ['alt.binaries', ['groupname' => 'shadow', 'sort' => 'name', 'active' => '0'], true], ['groupname' => 'alt.binaries', 'filters' => ['groupname' => 'shadow', 'sort' => 'name', 'active' => '0'], 'active' => true], ['filters' => ['groupname' => 'shadow', 'sort' => 'name', 'active' => '1'], 'listQuery' => ['groupname' => 'alt.binaries', 'sort' => 'name', 'active' => '1']]],
            [Group::class, 'editTitle', 11, ['view', false], ['action' => 'view', 'has_id' => false], 'Newsgroup Add'],
        ];
    }

    public function test_literal_contracts_execute_rust_and_preserve_dispatch_results(): void
    {
        $this->native();
        foreach (self::cases() as [$class, $method, $operation, $args, $wire, $expected]) {
            self::assertSame($expected, $class::{$method.'Php'}(...$args));
            self::assertSame($operation === 11 ? ['title' => $expected] : $expected, Blacklist::wave28Native($operation, $wire), "Actual Rust operation {$operation} must succeed, rather than silently fall back.");
            self::assertSame($expected, $class::{$method.'Native'}(...$args));
            foreach ([false, true, null] as $enabled) {
                $class::setUseRustFfi($enabled);
                self::assertSame($expected, $class::$method(...$args));
            }
        }
    }

    public function test_nullable_switches_are_independent_from_siblings_and_parent(): void
    {
        BasePageController::setUseRustFfi(true);
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
            foreach (self::CLASSES as $other) {
                self::assertSame($class === $other, $other::usesRustFfi());
            }
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(null);
        }
        self::assertTrue(BasePageController::usesRustFfi());
    }

    public function test_form_edge_cases_match_php_without_losing_types_or_normalization_order(): void
    {
        $this->native();
        $values = [null, '', '0', 0, false, true, [], ['x'], ' ', 'r', "\0\xff"];
        $ordinals = [null, '', false, true, [], -1, 0, 1, 1.5, '0', '01', '+2', ' 1e3 ', '-0', '-1e-999', '-1e999', '1e999', '1x', "1\0", "\f1\f"];
        foreach ([CategoryRegex::class => 2, CollectionRegex::class => 4, NamingRegex::class => 7] as $class => $op) {
            foreach ($values as $value) {
                foreach (['group_regex', 'regex', 'description'] as $field) {
                    $input = array_replace(['group_regex' => 'g', 'regex' => 'r', 'ordinal' => 0], [$field => $value]);
                    $expected = $class::validateFormPhp('submit', $input);
                    self::assertSame($expected, Blacklist::wave28Native($op, ['action' => 'submit', 'input' => $input]));
                }
            }
            foreach ($ordinals as $ordinal) {
                $input = ['group_regex' => 'g', 'regex' => 'r', 'ordinal' => $ordinal, 'extra' => [1, null, false]];
                self::assertSame($class::validateFormPhp('submit', $input), Blacklist::wave28Native($op, ['action' => 'submit', 'input' => $input]));
            }
            foreach ([null, '', '0', 0, '01', '1', '+1', ' 1 ', '1.0', true, false, [], "\f1\f", "\v1\v", PHP_INT_MAX, (string) PHP_INT_MAX, '9223372036854775808', '-1'] as $id) {
                $input = ['id' => $id];
                $expected = $class::validateFormPhp('view', $input);
                self::assertSame($expected, Blacklist::wave28Native($op, ['action' => 'view', 'input' => $input]));
                self::assertSame($expected, $class::validateFormNative('view', $input));
            }
        }
        foreach ($values as $groupname) {
            foreach ($values as $regex) {
                foreach (['submit', 'addtest', 'view', 'other'] as $action) {
                    $input = ['groupname' => $groupname, 'regex' => $regex, 'id' => '0'];
                    self::assertSame(Blacklist::resolveEditStatePhp($action, $input), Blacklist::wave28Native(0, ['action' => $action, 'input' => $input]));
                }
            }
        }
        foreach (['', '0', " \0\t\r\n", "\f g \f", "\xff\0", str_repeat('r', 300000)] as $value) {
            $input = ['group' => $value, 'regex' => $value, 'limit' => ' 2.5 ', 'showlimit' => 0, 'querylimit' => '1e999'];
            foreach ([CollectionRegex::class => 6, NamingRegex::class => 8] as $class => $op) {
                self::assertSame($class::normalizeTestParametersPhp($input), Blacklist::wave28Native($op, ['input' => $input]));
            }
        }
        foreach ([null, false, true] as $active) {
            $filters = [4 => 'four', 'groupname' => 'ignored', 2 => 'two', 'active' => 'x'];
            self::assertSame(Group::filterQueryPhp("\0\xff", $filters, $active), Group::filterQueryNative("\0\xff", $filters, $active));
        }
    }

    public function test_non_string_trim_arguments_keep_strict_php_type_errors(): void
    {
        $this->native();
        foreach ([true, 1, 1.5, ['g']] as $value) {
            foreach ([CollectionRegex::class => 6, NamingRegex::class => 8] as $class => $operation) {
                $input = ['group' => $value, 'regex' => 'r'];
                self::assertNull(Blacklist::wave28Native($operation, ['input' => $input]));
                try {
                    $class::normalizeTestParametersNative($input);
                    self::fail('The native path must preserve strict PHP trim type errors.');
                } catch (\TypeError $error) {
                    self::assertStringContainsString('trim()', $error->getMessage());
                }
            }
        }
    }

    public function test_ffi_unavailable_missing_symbol_and_unsupported_values_fall_back(): void
    {
        foreach ([false, FFI::cdef('int abs(int value);')] as $unavailable) {
            (new ReflectionProperty(Blacklist::class, 'wave28Ffi'))->setValue(null, $unavailable);
            foreach (self::cases() as [$class, $method, , $args, , $expected]) {
                $class::setUseRustFfi(true);
                self::assertSame($expected, $class::{$method.'Native'}(...$args));
                self::assertSame($expected, $class::$method(...$args));
            }
        }
        (new ReflectionProperty(Blacklist::class, 'wave28Ffi'))->setValue(null, null);
        $input = ['group_regex' => 'g', 'regex' => 'r', 'ordinal' => 0, 'extra' => new \stdClass];
        self::assertNull(Blacklist::wave28Native(2, ['action' => 'submit', 'input' => $input]));
        self::assertSame(CategoryRegex::validateFormPhp('submit', $input), CategoryRegex::validateFormNative('submit', $input));
        self::assertNull(Blacklist::wave28Native(999, []));
    }

    private function native(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        self::assertIsString($path, 'Provide the requested optimized release library.');
        $ffi = RustPipelineLibrary::resolveDomain('wave28-admin-regex-group-controllers-test', Blacklist::FFI_CDEF, $path);
        self::assertNotNull($ffi, 'Native tests must not pass through PHP fallback.');

        return $ffi;
    }

    /** @return array{blacklist: Blacklist, category: CategoryRegex, collection: CollectionRegex, naming: NamingRegex, group: Group} */
    private static function isolatedControllers(): array
    {
        return [
            'blacklist' => new class extends Blacklist
            {
                public function __construct() {}

                public function setAdminPrefs(): void {}
            },
            'category' => new class extends CategoryRegex
            {
                public function __construct() {}

                public function setAdminPrefs(): void {}
            },
            'collection' => new class extends CollectionRegex
            {
                public function __construct() {}

                public function setAdminPrefs(): void {}
            },
            'naming' => new class extends NamingRegex
            {
                public function __construct() {}

                public function setAdminPrefs(): void {}
            },
            'group' => (new ReflectionClass(Group::class))->newInstanceWithoutConstructor(),
        ];
    }

    private function categoryFixture(): void
    {
        config(['database.default' => 'wave28_parity', 'database.connections.wave28_parity' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('wave28_parity');
        DB::statement('CREATE TABLE root_categories (id INTEGER PRIMARY KEY, title TEXT)');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title TEXT, root_categories_id INTEGER)');
        DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies']);
        DB::table('categories')->insert([['id' => 2040, 'title' => 'HD', 'root_categories_id' => 2000], ['id' => 2030, 'title' => 'SD', 'root_categories_id' => 2000]]);
    }

    public function test_real_handlers_keep_defaults_errors_id_rejection_and_request_normalization(): void
    {
        $this->native();
        $this->categoryFixture();
        $controllers = self::isolatedControllers();
        foreach ([false, true] as $native) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($native);
            }
            $blacklist = $controllers['blacklist'];
            $view = $blacklist->edit(Request::create('/', 'GET', ['action' => 'addtest', 'groupname' => 'alt.test', 'regex' => '/r/']));
            self::assertSame('', $view->getData()['regex']->groupname);
            self::assertSame(1, $view->getData()['regex']->ordinal);
            $view = $blacklist->edit(Request::create('/', 'POST', ['action' => 'submit', 'groupname' => '', 'regex' => 'r']));
            self::assertSame('Group must be a valid usenet group', $view->getData()['error']);

            $collection = $controllers['collection'];
            $naming = $controllers['naming'];
            foreach ([$collection, $naming] as $controller) {
                $request = Request::create('/', 'POST', ['action' => 'submit', 'group_regex' => 'g', 'regex' => 'r', 'ordinal' => -1, 'description' => '0']);
                $view = $controller->edit($request);
                self::assertSame('Ordinal must be a number, 0 or higher.', $view->getData()['error']);
                self::assertSame('', $request->input('description'));
                $view = $controller->testRegex(Request::create('/', 'GET', ['group' => ' ', 'regex' => ' r ', 'limit' => 'bad']));
                self::assertSame('', $view->getData()['group']);
                self::assertSame('r', $view->getData()['regex']);
                self::assertNull($view->getData()['data']);
                self::assertSame($controller === $collection ? 50 : 250, $view->getData()[$controller === $collection ? 'limit' : 'showlimit']);
            }
            $group = (new ReflectionClass(Group::class))->newInstanceWithoutConstructor();
            $view = $group->edit(Request::create('/', 'GET'));
            self::assertSame('Newsgroup Add', $view->getData()['title']);
            self::assertSame(0, $view->getData()['group']['backfill_target']);

            $category = $controllers['category'];
            $view = $category->edit(Request::create('/', 'GET'));
            self::assertSame([2030, 2040], $view->getData()['category_ids']);
            self::assertSame(['Movies SD: 2030', 'Movies HD: 2040'], $view->getData()['category_names']);
            foreach ([$collection, $naming] as $controller) {
                $view = $controller->edit(Request::create('/', 'GET'));
                self::assertSame(1, $view->getData()['regex']->status);
                self::assertSame($controller === $collection ? 'Collections Regex Add' : 'Release Naming Regex Add', $view->getData()['title']);
            }
            foreach ([$category, $collection, $naming] as $controller) {
                try {
                    $controller->edit(Request::create('/', 'GET', ['id' => '01']));
                    self::fail('Invalid IDs must abort before querying the database.');
                } catch (NotFoundHttpException $error) {
                    self::assertSame(404, $error->getStatusCode());
                }
            }
        }
    }

    public function test_paired_randomized_release_benchmarks_record_each_path_and_uncommon_p99(): void
    {
        $this->native();
        $this->categoryFixture();
        $controllers = self::isolatedControllers();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-wave28-1-bench-');
        self::assertIsString($artifact);
        $evidence = ['protocol' => ['rounds' => 3, 'pairs' => 1001, 'warmups' => 51, 'seed' => 281, 'unit' => 'nanoseconds per individual call', 'criteria' => 'each common mean and uncommon p99 >= 1.5x', 'commands' => ['CARGO_TARGET_DIR=/tmp/cargo-w28-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release', 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w28-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAdminRegexGroupControllersParityTest.php']], 'identity' => ['php' => PHP_VERSION, 'os' => php_uname(), 'ffi.enable' => ini_get('ffi.enable'), 'opcache.enable_cli' => ini_get('opcache.enable_cli'), 'memory_limit' => ini_get('memory_limit'), 'load_average' => sys_getloadavg(), 'library_sha256' => hash_file('sha256', (string) getenv('NNTMUX_PIPELINE_FFI_LIB')), 'composer_lock_sha256' => hash_file('sha256', base_path('composer.lock'))], 'sources' => [], 'samples' => [], 'failures' => []];
        foreach (array_merge(array_map(static fn ($class) => (new ReflectionClass($class))->getFileName(), self::CLASSES), [__FILE__, base_path('rust/pipeline-ffi/src/cleaning.rs')]) as $file) {
            $evidence['sources'][$file] = hash_file('sha256', $file);
        }
        foreach (['git' => ['git', 'rev-parse', 'HEAD'], 'rust' => ['rustc', '--version'], 'cargo' => ['cargo', '--version'], 'hardware' => ['sysctl', '-n', 'machdep.cpu.brand_string']] as $name => $command) {
            $process = new Process($command);
            $process->run();
            $evidence['identity'][$name] = trim($process->getOutput().$process->getErrorOutput());
        }
        $random = new Randomizer(new Mt19937(281));
        try {
            foreach (self::cases() as [$class, $method, $operation, $args]) {
                foreach (['common', 'uncommon'] as $kind) {
                    $fixture = $args;
                    if (in_array($method, ['validateForm', 'resolveEditState'], true)) {
                        $fixture = ['submit', ['groupname' => 'alt.binaries.test', 'group_regex' => 'alt.*', 'regex' => '/sample/', 'ordinal' => 1, 'description' => 'sample rule']];
                    } elseif ($method === 'normalizeTestParameters') {
                        $fixture = [['group' => ' alt.test ', 'regex' => ' /sample/ ', 'limit' => '50', 'showlimit' => '250', 'querylimit' => '100000']];
                    }
                    if ($kind === 'uncommon') {
                        if ($method === 'categoryLabels') {
                            $fixture = [array_fill(0, 128, ['id' => 2040, 'parent_title' => str_repeat('P', 64), 'title' => "HD\0\xff"])];
                        } elseif ($method === 'normalizeTestParameters') {
                            $fixture = [['group' => ' g ', 'regex' => str_repeat('r', 16384), 'limit' => '1e3', 'showlimit' => '1e3', 'querylimit' => '1e6']];
                        } elseif (in_array($method, ['validateForm', 'resolveEditState'], true)) {
                            $fixture = ['submit', ['groupname' => 'alt.test', 'group_regex' => 'g', 'regex' => str_repeat('r', 16384), 'ordinal' => '-1e-999']];
                        } elseif ($method === 'filterQuery') {
                            $fixture = [str_repeat('alt.test', 128), ['sort' => 'name', 'direction' => 'asc', 'active' => '0', 'backfill' => '1'], false];
                        } elseif ($method === 'editTitle') {
                            $fixture = ['view', true];
                        }
                    }
                    $wire = match ($method) {
                        'resolveEditState', 'validateForm' => ['action' => $fixture[0], 'input' => $fixture[1]],
                        'categoryLabels' => ['categories' => $fixture[0]],
                        'normalizeTestParameters' => ['input' => $fixture[0]],
                        'filterQuery' => ['groupname' => $fixture[0], 'filters' => $fixture[1], 'active' => $fixture[2]],
                        'editTitle' => ['action' => $fixture[0], 'has_id' => $fixture[1]],
                        default => [],
                    };
                    self::assertNotNull(Blacklist::wave28Native($operation, $wire), 'Benchmarks require actual Rust, without PHP fallback.');
                    $name = class_basename($class).'::'.$method.'/'.$kind;
                    foreach (range(1, 3) as $round) {
                        for ($warmup = 0; $warmup < 51; $warmup++) {
                            $class::setUseRustFfi(false);
                            $expected = $class::$method(...$fixture);
                            $class::setUseRustFfi(true);
                            self::assertSame($expected, $class::$method(...$fixture));
                        }
                        $raw = [];
                        for ($sample = 0; $sample < 1001; $sample++) {
                            $pair = ['order' => $random->getInt(0, 1) === 0 ? [false, true] : [true, false]];
                            foreach ($pair['order'] as $native) {
                                $class::setUseRustFfi($native);
                                $start = hrtime(true);
                                $actual = $class::$method(...$fixture);
                                $pair[$native ? 'rust' : 'php'] = hrtime(true) - $start;
                                self::assertSame($expected, $actual);
                            }
                            $raw[] = $pair;
                        }
                        $php = array_column($raw, 'php');
                        $rust = array_column($raw, 'rust');
                        sort($php);
                        sort($rust);
                        $evidence['samples'][$name][$round] = ['pairs' => $raw, 'mean_speedup' => array_sum($php) / array_sum($rust), 'p99_speedup' => $php[990] / $rust[990]];
                    }
                }
            }
            foreach (self::CLASSES as $class) {
                $controller = $controllers[match ($class) {
                    Blacklist::class => 'blacklist',
                    CategoryRegex::class => 'category',
                    CollectionRegex::class => 'collection',
                    NamingRegex::class => 'naming',
                    Group::class => 'group',
                }];
                foreach (['common', 'uncommon'] as $kind) {
                    $parameters = $kind === 'common' ? [] : ['action' => 'submit', 'group_regex' => 'g', 'regex' => str_repeat('r', 16384), 'ordinal' => -1, 'description' => '0'];
                    // Group/blacklist uncommon requests remain read-only and avoid storage writes.
                    if ($kind === 'uncommon' && $class === Group::class) {
                        $parameters = ['name' => str_repeat('alt.test', 2048)];
                    } elseif ($kind === 'uncommon' && $class === Blacklist::class) {
                        $parameters = ['action' => 'addtest', 'groupname' => 'alt.test', 'regex' => str_repeat('r', 16384)];
                    }
                    $name = class_basename($class).'::edit-caller/'.$kind;
                    for ($round = 1; $round <= 3; $round++) {
                        for ($warmup = 0; $warmup < 51; $warmup++) {
                            $class::setUseRustFfi(false);
                            $expected = $controller->edit(Request::create('/', 'POST', $parameters))->getData();
                            $class::setUseRustFfi(true);
                            self::assertEquals($expected, $controller->edit(Request::create('/', 'POST', $parameters))->getData());
                        }
                        $raw = [];
                        for ($sample = 0; $sample < 1001; $sample++) {
                            $pair = ['order' => $random->getInt(0, 1) === 0 ? [false, true] : [true, false]];
                            foreach ($pair['order'] as $native) {
                                $class::setUseRustFfi($native);
                                $start = hrtime(true);
                                $actual = $controller->edit(Request::create('/', 'POST', $parameters))->getData();
                                $pair[$native ? 'rust' : 'php'] = hrtime(true) - $start;
                                self::assertEquals($expected, $actual);
                            }
                            $raw[] = $pair;
                        }
                        $php = array_column($raw, 'php');
                        $rust = array_column($raw, 'rust');
                        sort($php);
                        sort($rust);
                        $evidence['samples'][$name][$round] = ['pairs' => $raw, 'mean_speedup' => array_sum($php) / array_sum($rust), 'p99_speedup' => $php[990] / $rust[990]];
                    }
                }
            }
        } catch (\Throwable $error) {
            $evidence['failures'][] = (string) $error;
            throw $error;
        } finally {
            file_put_contents($artifact, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            fwrite(STDERR, "\nWave 28.1 paired benchmark evidence: {$artifact}\n");
        }
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
    }
}
