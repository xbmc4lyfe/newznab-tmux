<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facades\Search;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AdminStatusController;
use App\Http\Controllers\Controller;
use App\Support\RustPipelineLibrary;
use App\View\Components\GuestLayout;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib, PHP 8.5 on Apple Silicon Darwin. See the commit message for the measured run; each run prints
 * its ratios to STDERR.
 *
 * Packet marshaling, FFI scratch access and result decoding dominate these small planners, so Rust
 * misses the 1.5x gate and every helper keeps PHP as the default.
 */
class RustSearchFacadeControllerGuestLayoutParityTest extends TestCase
{
    /**
     * Toggle names per class: [default constant, use, uses, reset]. `Controller` and the admin
     * controllers use prefixed names so subclasses never collide with the base toggles.
     */
    private const TOGGLES = [
        Search::class => ['FACADE_RUST_FFI_DEFAULT', 'useFacadeRustFfi', 'usesFacadeRustFfi', 'resetFacadeRustFfi'],
        Controller::class => ['CONTROLLER_RUST_FFI_DEFAULT', 'useControllerRustFfi', 'usesControllerRustFfi', 'resetControllerRustFfi'],
        GuestLayout::class => ['RUST_FFI_DEFAULT', 'useRustFfi', 'usesRustFfi', 'resetRustFfi'],
        AdminPageController::class => ['ADMIN_PAGE_RUST_FFI_DEFAULT', 'useAdminPageRustFfi', 'usesAdminPageRustFfi', 'resetAdminPageRustFfi'],
        AdminStatusController::class => ['ADMIN_STATUS_RUST_FFI_DEFAULT', 'useAdminStatusRustFfi', 'usesAdminStatusRustFfi', 'resetAdminStatusRustFfi'],
    ];

    private const RANDOM_ROUNDS = 1200;

    /** Bytes that exercise trimming, whitespace collapse, case folding, digits and invalid UTF-8. */
    private const ALPHABET = ['a', 'B', 'e', 'Z', '0', '1', '7', '9', '-', '+', '.', ' ', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\x01", "\x7F", "'", "\xc3", "\xa9", "\xe2", "\x82", "\xac", "\xff", 'K', '_'];

    private const SERVICE_VALUES = ['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance', ' Degraded ', "MAJOR_OUTAGE\n", 'bogus', ''];

    private const INCIDENT_STATUSES = ['investigating', 'identified', 'monitoring', 'resolved', ' Resolved ', "MONITORING\t", 'open', ''];

    private const INCIDENT_IMPACTS = ['none', 'minor', 'major', 'critical', ' Critical', "MINOR\0", 'severe', ''];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TOGGLES) as $class) {
            self::reset($class);
        }
        (new ReflectionClass(Search::class))->setStaticPropertyValue('helperFfi', null);

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::TOGGLES as $class => [$constant, $use, $uses, $reset]) {
            $this->assertFalse((new ReflectionClassConstant($class, $constant))->getValue());
            $this->assertFalse($class::$uses(), "{$class} must default to PHP");
            $class::$use(true);
            $this->assertTrue($class::$uses());
            $class::$use(false);
            $this->assertFalse($class::$uses());
            $class::$use(true);
            $class::$reset();
            $this->assertFalse($class::$uses());
            $class::$use(null);
            $this->assertFalse($class::$uses());
        }

        // Pre-existing admin toggles are independent of the Wave 38.5 ones.
        $this->assertFalse(AdminPageController::usesRustFfi());
        $this->assertFalse(AdminStatusController::usesRustFfi());
        AdminPageController::useAdminPageRustFfi(true);
        AdminStatusController::useAdminStatusRustFfi(true);
        Controller::useControllerRustFfi(true);
        $this->assertFalse(AdminPageController::usesRustFfi());
        $this->assertFalse(AdminStatusController::usesRustFfi());
        $this->assertSame(['daily' => true, 'count' => 30], AdminPageController::metricWindow('30d'));
    }

    public function test_toggle_names_do_not_collide_across_the_hierarchy(): void
    {
        foreach ([AdminPageController::class, AdminStatusController::class] as $class) {
            foreach (['useControllerRustFfi', 'usesControllerRustFfi', 'resetControllerRustFfi', 'planControllerPagination'] as $method) {
                $this->assertSame(Controller::class, (new ReflectionMethod($class, $method))->getDeclaringClass()->getName(), "{$class}::{$method}");
            }
            $this->assertSame($class, (new ReflectionMethod($class, 'usesRustFfi'))->getDeclaringClass()->getName());
        }
        $this->assertSame(Controller::class, (new ReflectionClassConstant(AdminPageController::class, 'CONTROLLER_RUST_FFI_DEFAULT'))->getDeclaringClass()->getName());
    }

    public function test_guest_layout_view_data_hides_the_static_helpers(): void
    {
        $data = (new GuestLayout)->data();
        foreach (['useRustFfi', 'usesRustFfi', 'resetRustFfi', 'planGuestLayoutMeta'] as $method) {
            $this->assertArrayNotHasKey($method, $data);
        }
    }

    /**
     * @return array<string, array{0: array<array-key, string>|string, 1: int, 2: array{phrases: list<string>, query: string, limit: int, empty: int, duplicates: int}}>
     */
    public static function searchPhrasesProvider(): array
    {
        return [
            'string collapses' => ["  Foo\t Bar  ", 100, ['phrases' => ['Foo Bar'], 'query' => 'Foo Bar', 'limit' => 100, 'empty' => 0, 'duplicates' => 0]],
            'blank string' => [" \0\x0C ", 0, ['phrases' => [], 'query' => '', 'limit' => 1000, 'empty' => 1, 'duplicates' => 0]],
            'array dedupes case-insensitively' => [['a' => 'Foo', 'b' => 'foo', 'c' => '', 'd' => " FOO\n", 'e' => 'bar'], 5, ['phrases' => ['Foo', 'bar'], 'query' => 'Foo bar', 'limit' => 5, 'empty' => 1, 'duplicates' => 2]],
            'empty array' => [[], -3, ['phrases' => [], 'query' => '', 'limit' => 1000, 'empty' => 0, 'duplicates' => 0]],
            'limit capped' => [['x'], PHP_INT_MAX, ['phrases' => ['x'], 'query' => 'x', 'limit' => 10000, 'empty' => 0, 'duplicates' => 0]],
            'utf8 is not folded' => [["Caf\xc3\xa9", "CAF\xc3\x89", "caf\xc3\xa9"], 1, ['phrases' => ["Caf\xc3\xa9", "CAF\xc3\x89"], 'query' => "Caf\xc3\xa9 CAF\xc3\x89", 'limit' => 1, 'empty' => 0, 'duplicates' => 1]],
        ];
    }

    /**
     * @param  array<array-key, string>|string  $phrases
     * @param  array{phrases: list<string>, query: string, limit: int, empty: int, duplicates: int}  $expected
     */
    #[DataProvider('searchPhrasesProvider')]
    public function test_search_phrases_parity(array|string $phrases, int $limit, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, $this->both(Search::class, static fn () => Search::planSearchPhrases($phrases, $limit)));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: int, 5: array{page: int, per_page: int, offset: int, last_page: int, clamped: bool}}>
     */
    public static function paginationProvider(): array
    {
        return [
            'plain' => [' 3 ', '25', 101, 50, 100, ['page' => 3, 'per_page' => 25, 'offset' => 50, 'last_page' => 5, 'clamped' => false]],
            'defaults' => ['', '', 0, 50, 100, ['page' => 1, 'per_page' => 50, 'offset' => 0, 'last_page' => 1, 'clamped' => false]],
            'page past last' => ['9', '10', 30, 50, 100, ['page' => 3, 'per_page' => 10, 'offset' => 20, 'last_page' => 3, 'clamped' => true]],
            'per page capped' => ['1', '500', 1000, 50, 100, ['page' => 1, 'per_page' => 100, 'offset' => 0, 'last_page' => 10, 'clamped' => true]],
            'invalid input' => ['-2', '+5', -7, 500, 0, ['page' => 1, 'per_page' => 1, 'offset' => 0, 'last_page' => 1, 'clamped' => false]],
            'zero page' => ['0', '0', 10, 3, 100, ['page' => 1, 'per_page' => 3, 'offset' => 0, 'last_page' => 4, 'clamped' => false]],
            'nineteen digits rejected' => ['1000000000000000000', '7', 70, 50, 100, ['page' => 1, 'per_page' => 7, 'offset' => 0, 'last_page' => 10, 'clamped' => false]],
            'huge total' => ['999999999999999999', '0', PHP_INT_MAX, 7, PHP_INT_MAX, ['page' => 999999999999999999, 'per_page' => 7, 'offset' => 6999999999999999986, 'last_page' => 1317624576693539401, 'clamped' => false]],
        ];
    }

    /**
     * @param  array{page: int, per_page: int, offset: int, last_page: int, clamped: bool}  $expected
     */
    #[DataProvider('paginationProvider')]
    public function test_controller_pagination_parity(string $page, string $perPage, int $total, int $default, int $max, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, $this->both(Controller::class, static fn () => Controller::planControllerPagination($page, $perPage, $total, $default, $max)));
        $this->assertSame($expected, AdminPageController::planControllerPagination($page, $perPage, $total, $default, $max));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: array{title: string, meta_title: string, description: string, truncated: bool}}>
     */
    public static function guestMetaProvider(): array
    {
        return [
            'title and default app' => [" Log\tin ", 'Welcome  back', '', 0, ['title' => 'Log in', 'meta_title' => 'Log in - NNTmux', 'description' => 'Welcome back', 'truncated' => false]],
            'title equals app' => ['nntmux', '', ' NNTmux ', 160, ['title' => 'nntmux', 'meta_title' => 'NNTmux', 'description' => '', 'truncated' => false]],
            'truncated on space' => ['', 'abc def ghi', 'Site', 4, ['title' => '', 'meta_title' => 'Site', 'description' => 'abc...', 'truncated' => true]],
            'truncated on utf8 boundary' => ['Reset', "a\xc3\xa9b", 'S', 2, ['title' => 'Reset', 'meta_title' => 'Reset - S', 'description' => 'a...', 'truncated' => true]],
            'exact budget' => ['', 'abcd', 'S', 4, ['title' => '', 'meta_title' => 'S', 'description' => 'abcd', 'truncated' => false]],
            'budget capped' => ['', str_repeat('x', 1001), 'S', PHP_INT_MAX, ['title' => '', 'meta_title' => 'S', 'description' => str_repeat('x', 1000).'...', 'truncated' => true]],
            'continuation bytes only' => ['', "\x80\x80\x80", 'S', 1, ['title' => '', 'meta_title' => 'S', 'description' => '...', 'truncated' => true]],
        ];
    }

    /**
     * @param  array{title: string, meta_title: string, description: string, truncated: bool}  $expected
     */
    #[DataProvider('guestMetaProvider')]
    public function test_guest_layout_meta_parity(string $title, string $description, string $appName, int $max, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, $this->both(GuestLayout::class, static fn () => GuestLayout::planGuestLayoutMeta($title, $description, $appName, $max)));
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: int, 3: int, 4: bool}>
     */
    public static function serviceStatusProvider(): array
    {
        return [
            'mixed' => [['operational', ' Degraded ', 'maintenance', 'bogus'], 'maintenance', 2, 1, false],
            'worst wins' => [['maintenance', 'PARTIAL_OUTAGE', 'major_outage'], 'major_outage', 4, 0, false],
            'none' => [[], 'operational', 0, 0, true],
            'all operational' => [['operational', "operational\n"], 'operational', 0, 0, true],
            'unknown only' => [['', 'up'], 'operational', 0, 2, false],
        ];
    }

    /**
     * @param  list<string>  $statuses
     */
    #[DataProvider('serviceStatusProvider')]
    public function test_service_status_summary_parity(array $statuses, string $overall, int $severity, int $unknown, bool $allOperational): void
    {
        $this->requireFfi();

        $php = $this->both(AdminPageController::class, static fn () => AdminPageController::summarizeServiceStatuses($statuses));
        $this->assertSame(['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance'], array_keys($php['counts']));
        $this->assertSame(\count($statuses), $php['total']);
        $this->assertSame($overall, $php['overall']);
        $this->assertSame($severity, $php['severity']);
        $this->assertSame($unknown, $php['unknown']);
        $this->assertSame($allOperational, $php['all_operational']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: array{valid: bool, status: ?string, impact: ?string, resolved: bool, rejected: int, service_ids: list<int>}}>
     */
    public static function incidentProvider(): array
    {
        return [
            'resolved with ids' => [' Resolved', 'MAJOR', ['3', ' 1 ', '3', '0', 'x', '-2', '007'], ['valid' => true, 'status' => 'resolved', 'impact' => 'major', 'resolved' => true, 'rejected' => 3, 'service_ids' => [1, 3, 7]]],
            'unknown status' => ['open', 'none', [], ['valid' => false, 'status' => null, 'impact' => 'none', 'resolved' => false, 'rejected' => 0, 'service_ids' => []]],
            'unknown impact and long id' => ['monitoring', 'severe', ['1234567890123456789'], ['valid' => false, 'status' => 'monitoring', 'impact' => null, 'resolved' => false, 'rejected' => 1, 'service_ids' => []]],
            'no services' => ['investigating', 'minor', ['', '+1', '1.0'], ['valid' => false, 'status' => 'investigating', 'impact' => 'minor', 'resolved' => false, 'rejected' => 3, 'service_ids' => []]],
            'max digits' => ['identified', 'critical', ['999999999999999999'], ['valid' => true, 'status' => 'identified', 'impact' => 'critical', 'resolved' => false, 'rejected' => 0, 'service_ids' => [999999999999999999]]],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @param  array{valid: bool, status: ?string, impact: ?string, resolved: bool, rejected: int, service_ids: list<int>}  $expected
     */
    #[DataProvider('incidentProvider')]
    public function test_incident_payload_parity(string $status, string $impact, array $ids, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, $this->both(AdminStatusController::class, static fn () => AdminStatusController::validateIncidentPayload($status, $impact, $ids)));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();

        mt_srand(3805);
        $pick = static fn (array $values): mixed => $values[mt_rand(0, \count($values) - 1)];
        $mixed = static fn (array $values, int $max): string => mt_rand(0, 2) === 0 ? self::randomBytes($max) : $pick($values);
        $numbers = ['0', '1', '7', ' 12 ', '0042', '999999999999999999', '1000000000000000000', '-1', '+3', '1.5', '', "5\n"];

        for ($i = 0; $i < self::RANDOM_ROUNDS; $i++) {
            $phrases = [];
            for ($p = mt_rand(0, 8); $p > 0; $p--) {
                $phrases[] = $mixed(['Foo', 'foo', ' FOO ', "bar\tbaz", '', ' ', "Caf\xc3\xa9"], 14);
            }
            $search = [mt_rand(0, 3) === 0 ? self::randomBytes(20) : $phrases, self::randomInt()];
            $this->assertNotNull(Search::evaluateNative(1, [\is_string($search[0]) ? '0' : '1', (string) $search[1], ...(\is_string($search[0]) ? [$search[0]] : $search[0])]));
            $this->assertParity(Search::class, static fn () => Search::planSearchPhrases(...$search), $search);

            $pagination = [$mixed($numbers, 6), $mixed($numbers, 6), self::randomInt(), self::randomInt(), self::randomInt()];
            $this->assertNotNull(Search::evaluateNative(2, [$pagination[0], $pagination[1], (string) $pagination[2], (string) $pagination[3], (string) $pagination[4]]));
            $this->assertParity(Controller::class, static fn () => Controller::planControllerPagination(...$pagination), $pagination);

            $meta = [$mixed(['Login', 'NNTmux', ' nntmux ', "Reset\npassword"], 16), self::randomBytes(mt_rand(0, 1) === 0 ? 24 : 200), $mixed(['', 'NNTmux', 'My  Site'], 10), mt_rand(0, 2) === 0 ? self::randomInt() : mt_rand(-2, 40)];
            $this->assertNotNull(Search::evaluateNative(3, [$meta[0], $meta[1], $meta[2], (string) $meta[3]]));
            $this->assertParity(GuestLayout::class, static fn () => GuestLayout::planGuestLayoutMeta(...$meta), $meta);

            $statuses = [];
            for ($s = mt_rand(0, 12); $s > 0; $s--) {
                $statuses[] = $mixed(self::SERVICE_VALUES, 12);
            }
            $this->assertNotNull(Search::evaluateNative(4, $statuses));
            $this->assertParity(AdminPageController::class, static fn () => AdminPageController::summarizeServiceStatuses($statuses), $statuses);

            $ids = [];
            for ($s = mt_rand(0, 10); $s > 0; $s--) {
                $ids[] = mt_rand(0, 3) === 0 ? self::randomBytes(6) : $pick([...$numbers, (string) mt_rand(1, 50)]);
            }
            $incident = [$mixed(self::INCIDENT_STATUSES, 10), $mixed(self::INCIDENT_IMPACTS, 10), $ids];
            $this->assertNotNull(Search::evaluateNative(5, [$incident[0], $incident[1], ...$ids]));
            $this->assertParity(AdminStatusController::class, static fn () => AdminStatusController::validateIncidentPayload(...$incident), $incident);
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['1000', '1', '1', 'a b', 'a', 'b'], Search::evaluateNative(1, ['1', '0', 'a', 'A', '', 'b']));
        $this->assertSame(['2', '10', '10', '3', '0'], Search::evaluateNative(2, ['2', '10', '25', '50', '100']));
        $this->assertSame(['T', 'T - NNTmux', 'd', '0'], Search::evaluateNative(3, ['T', 'd', '', '160']));
        $this->assertSame(['1', '0', '1', '0', '0', '0', '0', 'degraded', '1', '0'], Search::evaluateNative(4, ['degraded']));
        $this->assertSame(['1', '1', 'resolved', '1', 'none', '1', '0', '2'], Search::evaluateNative(5, ['resolved', 'none', '2']));
        $this->assertNull(Search::evaluateNative(-1, ['x']));
        $this->assertNull(Search::evaluateNative(0, ['x']));
        $this->assertNull(Search::evaluateNative(6, ['x']));
        $this->assertNull(Search::evaluateNative(4, array_fill(0, 4097, 'operational')));
    }

    public function test_native_helpers_fall_back_to_php(): void
    {
        foreach (array_keys(self::TOGGLES) as $class) {
            self::toggle($class, true);
        }
        (new ReflectionClass(Search::class))->setStaticPropertyValue('helperFfi', false);

        $this->assertNull(Search::evaluateNative(4, ['operational']));
        $this->assertSame('a b', Search::planSearchPhrases(['a', 'b'])['query']);
        $this->assertSame(2, Controller::planControllerPagination('2', '10', 25)['page']);
        $this->assertSame('T - NNTmux', GuestLayout::planGuestLayoutMeta('T')['meta_title']);
        $this->assertSame('degraded', AdminPageController::summarizeServiceStatuses(['degraded'])['overall']);
        $this->assertSame([2], AdminStatusController::validateIncidentPayload('resolved', 'none', ['2'])['service_ids']);

        // Over the 4096-part bridge limit Rust is never called and PHP answers.
        (new ReflectionClass(Search::class))->setStaticPropertyValue('helperFfi', null);
        $many = array_fill(0, 5000, 'degraded');
        $this->assertSame(5000, AdminPageController::summarizeServiceStatuses($many)['counts']['degraded']);
        $this->assertSame(['x'], Search::planSearchPhrases(array_fill(0, 5000, 'x'))['phrases']);

        $missing = RustPipelineLibrary::resolveDomain('search_w385', Search::FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['1', '1000', 'alpha', 'beta']);
        $buf = $ffi->new('char[1024]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w38_search_facade_controller_guest_layout($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(1, $valid, strlen($valid), $buf, 1024);
        $this->assertGreaterThan(8, $written);
        $expected = Search::evaluateNative(1, ['1', '1000', 'alpha', 'beta']);
        $this->assertNotNull($expected);
        $this->assertSame(self::packet($expected), FFI::string($buf, $written));

        // Tiny capacity: -required without touching the buffer, then a retry with that capacity succeeds.
        $this->assertSame(-$written, $call(1, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(1, $valid, strlen($valid), null, 0));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $exact = $ffi->new("char[{$written}]");
        $this->assertSame($written, $call(1, $valid, strlen($valid), $exact, $written));
        $this->assertSame(self::packet($expected), FFI::string($exact, $written));

        // Null pointers with non-zero lengths, malformed packets and unknown modes.
        $this->assertSame(-1, $call(1, null, 4, $buf, 1024));
        $this->assertSame(-1, $call(1, $valid, strlen($valid), null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $valid, strlen($valid), $small, 8));
        }
        $this->assertSame(-1, $call(4, '', 0, $small, 8));
        $this->assertSame(-1, $call(4, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(4, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(1, $valid."\0", strlen($valid) + 1, $small, 8));

        // Invalid flags, non-canonical integers and wrong field counts.
        $this->assertSame(-1, $callParts(1, ['2', '5', 'a']));
        $this->assertSame(-1, $callParts(1, ['0', '5']));
        $this->assertSame(-1, $callParts(1, ['0', '5', 'a', 'b']));
        $this->assertSame(-1, $callParts(1, ['1', '05', 'a']));
        $this->assertSame(-1, $callParts(1, ['1']));
        $this->assertSame(-1, $callParts(2, ['1', '1', 'x', '1', '1']));
        $this->assertSame(-1, $callParts(2, ['1', '1', '1', '1']));
        $this->assertSame(-1, $callParts(2, ['1', '1', '1', '1', '99999999999999999999']));
        $this->assertSame(-1, $callParts(3, ['', '', '', '+1']));
        $this->assertSame(-1, $callParts(3, ['', '', '']));
        $this->assertSame(-1, $callParts(5, ['resolved']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $common = static function (): void {
            Search::planSearchPhrases('the matrix 1999', 100);
            Controller::planControllerPagination('2', '50', 1234);
            GuestLayout::planGuestLayoutMeta('Login', 'Sign in to your account', 'NNTmux');
            AdminPageController::summarizeServiceStatuses(['operational', 'operational', 'degraded', 'operational']);
            AdminStatusController::validateIncidentPayload('investigating', 'minor', ['1', '2']);
        };
        $phrases = [];
        $statuses = [];
        $ids = [];
        for ($i = 0; $i < 300; $i++) {
            $phrases[] = $i % 4 === 0 ? " Phrase\t{$i} " : 'phrase '.($i % 50);
            $statuses[] = self::SERVICE_VALUES[$i % \count(self::SERVICE_VALUES)];
            $ids[] = $i % 5 === 0 ? 'bad' : (string) (300 - $i);
        }
        $description = str_repeat("Caf\xc3\xa9  society\t", 40);
        $uncommon = static function () use ($phrases, $statuses, $ids, $description): void {
            Search::planSearchPhrases($phrases, PHP_INT_MAX);
            Controller::planControllerPagination(' 999999999999999999 ', '1000000', PHP_INT_MAX, PHP_INT_MIN, 250);
            GuestLayout::planGuestLayoutMeta(str_repeat('Title ', 30), $description, " My\tSite ", 155);
            AdminPageController::summarizeServiceStatuses($statuses);
            AdminStatusController::validateIncidentPayload(' Resolved ', 'CRITICAL', $ids);
        };

        $setAll = static function (bool $rust): void {
            foreach (array_keys(self::TOGGLES) as $class) {
                self::toggle($class, $rust);
            }
        };

        $results = [];
        foreach (['common' => $common, 'uncommon' => $uncommon] as $name => $workload) {
            for ($w = 0; $w < 20; $w++) {
                foreach ([false, true] as $rust) {
                    $setAll($rust);
                    $workload();
                }
            }

            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < 300; $s++) {
                $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAll($rust);
                    $t0 = hrtime(true);
                    for ($i = 0; $i < 20; $i++) {
                        $workload();
                    }
                    $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / 20_000;
                }
            }

            $results[$name] = [
                'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                'php_mean_us' => self::mean($times['php']),
                'rust_mean_us' => self::mean($times['rust']),
                'php_p99_us' => self::p99($times['php']),
                'rust_p99_us' => self::p99($times['rust']),
            ];
        }

        foreach (array_keys(self::TOGGLES) as $class) {
            self::reset($class);
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 38.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus) | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
            $results['common']['mean'],
            $results['common']['php_mean_us'],
            $results['common']['rust_mean_us'],
            $results['common']['p99'],
            $results['common']['php_p99_us'],
            $results['common']['rust_p99_us'],
            $results['uncommon']['mean'],
            $results['uncommon']['php_mean_us'],
            $results['uncommon']['rust_mean_us'],
            $results['uncommon']['p99'],
            $results['uncommon']['php_p99_us'],
            $results['uncommon']['rust_p99_us'],
        ));

        $qualifies = $results['common']['mean'] >= 1.5 && $results['common']['p99'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach (self::TOGGLES as $class => [, , $uses]) {
            if (! $qualifies) {
                $this->assertFalse($class::$uses(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::$uses());
            }
        }
    }

    /** @param class-string $class */
    private static function toggle(string $class, bool $rust): void
    {
        $use = self::TOGGLES[$class][1];
        $class::$use($rust);
    }

    /** @param class-string $class */
    private static function reset(string $class): void
    {
        $reset = self::TOGGLES[$class][3];
        $class::$reset();
    }

    /**
     * Runs $plan with PHP then Rust, asserts identical results and returns the PHP result.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): T  $plan
     * @return T
     */
    private function both(string $class, callable $plan): mixed
    {
        self::toggle($class, false);
        $php = $plan();
        self::toggle($class, true);
        $rust = $plan();
        self::reset($class);
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        self::toggle($class, false);
        $php = $plan();
        self::toggle($class, true);
        $rust = $plan();
        self::reset($class);
        $this->assertSame($php, $rust, bin2hex(serialize($args)));
    }

    private static function randomBytes(int $maxLength): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $out .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
        }

        return $out;
    }

    private static function randomInt(): int
    {
        return match (mt_rand(0, 3)) {
            0 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            1 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 1, PHP_INT_MAX - 1][mt_rand(0, 5)],
            default => mt_rand(-2, 50_000),
        };
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        $packet = pack('V', count($parts));
        foreach ($parts as $part) {
            $packet .= pack('V', strlen($part)).$part;
        }

        return $packet;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(0.99 * count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w385', Search::FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
