<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\AdminMonitoringController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminStatusController;
use App\Http\Controllers\BasePageController;
use App\Http\Controllers\BtcPaymentController;
use App\Http\Requests\Admin\StoreIncidentRequest;
use App\Http\Requests\Admin\UpdateIncidentRequest;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Services\AdminDashboardSnapshotService;
use App\Services\Monitoring\GrafanaEmbedService;
use App\Services\Monitoring\GrafanaJwtIssuer;
use App\Services\SiteStatusService;
use App\Support\Configuration\SettingsPageCatalog;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustAdminSettingsStatusMonitoringPaymentControllersParityTest extends TestCase
{
    private const array CLASSES = [AdminSettingsController::class, AdminStatusController::class, AdminMonitoringController::class, AdminPaymentController::class, BtcPaymentController::class];

    private const array SORT_COLUMNS = ['id', 'created_at', 'username', 'email', 'invoice_amount', 'payment_status', 'invoice_status', 'order_id'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        BasePageController::setUseRustFfi(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_defaults_nullable_toggles_and_parent_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi());
        }
        BasePageController::setUseRustFfi(true);
        foreach (self::CLASSES as $selected) {
            $selected::setUseRustFfi(true);
            foreach (self::CLASSES as $class) {
                $this->assertSame($selected === $class, $class::usesRustFfi());
            }
            $selected::setUseRustFfi(null);
            $this->assertFalse($selected::usesRustFfi());
        }
    }

    public function test_boundary_contracts_preserve_existing_php_behavior(): void
    {
        $this->assertSame(['post_non_mode' => 1, 'post_amazon_mode' => 0, 'keep' => null], AdminSettingsController::normalizeBinaryModesPhp(['post_non_mode' => '2', 'post_amazon_mode' => -2, 'keep' => null]));
        $this->assertSame(['secret' => null], AdminSettingsController::resolveSensitiveFieldPhp('secret', true, true, 'replacement'));
        $this->assertSame([], AdminSettingsController::resolveSensitiveFieldPhp('secret', false, false, 'ignored'));
        $this->assertSame(['secret' => '0'], AdminSettingsController::resolveSensitiveFieldPhp('secret', false, true, '0'));
        $this->assertSame('', AdminSettingsController::coerceNullableStringPhp('terms', null));
        $this->assertSame('', AdminSettingsController::coerceNullableStringPhp('dereferrer_link', null));
        $this->assertNull(AdminSettingsController::coerceNullableStringPhp('title', null));
        $this->assertSame(0, AdminSettingsController::coerceNullableStringPhp('terms', 0));
        $attributes = ['post_non_mode' => 0, 'keep' => null, 'movie_lookup' => 2, 'max_movies_processed' => 0];
        $this->assertSame(['attributes' => ['keep' => null], 'movie' => ['movie_lookup' => 2, 'max_movies_processed' => 0], 'tmux' => ['post_non_mode' => 0]], AdminSettingsController::partitionAttributesPhp($attributes, true));
        $this->assertSame(['attributes' => $attributes, 'movie' => [], 'tmux' => []], AdminSettingsController::partitionAttributesPhp($attributes, false));
        $now = '2026-10-24 12:34:56';
        foreach ([null, '', '0', false, 0] as $resolved) {
            $this->assertSame($resolved ?? $now, AdminStatusController::normalizeIncidentPhp(['status' => 'resolved', 'resolved_at' => $resolved], $now)['resolved_at']);
            $this->assertSame($now, AdminStatusController::normalizeIncidentPhp(['status' => 'resolved', 'resolved_at' => $resolved], $now, true)['resolved_at']);
        }
        $this->assertSame(['status' => 'investigating'], AdminStatusController::normalizeIncidentPhp(['status' => 'investigating'], $now));
        $this->assertSame($now, AdminStatusController::normalizeIncidentPhp(['status' => 'investigating', 'resolved_at' => $now], $now)['resolved_at']);
        $this->assertNull(AdminStatusController::normalizeIncidentPhp(['status' => 'investigating', 'resolved_at' => $now], $now, true)['resolved_at']);
        foreach ([null, 1, true, '01', '1 ', ['1']] as $untrusted) {
            $this->assertFalse(AdminMonitoringController::validAuthRequestPhp($untrusted, true));
        }
        $this->assertFalse(AdminMonitoringController::validAuthRequestPhp('1', false));
        $this->assertTrue(AdminMonitoringController::validAuthRequestPhp('1', true));
        $this->assertSame('created_at', AdminPaymentController::sortColumnPhp('username DESC; DROP TABLE payments'));
        $this->assertSame('desc', AdminPaymentController::sortDirectionPhp(' asc '));
        $this->assertSame('asc', AdminPaymentController::sortDirectionPhp('ASC'));
        $this->assertSame(['tx_count' => 0, 'invoice_total' => 0.0, 'value_total' => 0.0], AdminPaymentController::summaryTotalsPhp([]));
        $this->assertSame(['user@proton.me', 'user@protonmail.com'], BtcPaymentController::fallbackEmailsPhp('user@pm.me'));
        $this->assertSame([], BtcPaymentController::fallbackEmailsPhp('user@PM.ME'));
        $this->assertSame(['role' => 'Admin ++', 'addYears' => 2], BtcPaymentController::parseItemDescriptionPhp('prefix: Admin ++ 2 trailing'));
        $this->assertSame(['role' => 'No subscription', 'addYears' => null], BtcPaymentController::parseItemDescriptionPhp('No subscription'));
        $this->assertSame(['Pending', 'Processing', 'Settled', 'Invalid', 'Manual'], BtcPaymentController::paymentStatusesForAdminFilterPhp());
        $this->assertSame(['Pending', 'Settled'], BtcPaymentController::invoiceStatusesForAdminFilterPhp());
    }

    public function test_native_helpers_and_real_dispatch_have_exact_serialized_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = serialize($class::$php(...$args));
                // Check the raw bridge first: fallback must not conceal a failed native call.
                $this->assertSame($expected, serialize(AdminPaymentController::transformNative($op, self::rawArgs($op, $args))), $method.' raw native');
                $this->assertSame($expected, serialize($class::$native(...$args)), $method.' native');
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, serialize($class::$method(...$args)), $method.' public dispatch');
                }
            }
        }
    }

    public function test_native_byte_regex_matches_pcre_for_seeded_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(290214));
        $alphabet = "abcXYZ019_ ++\t\n\r\v\f:!\0\xff";
        for ($sample = 0; $sample < 2000; $sample++) {
            $description = '';
            for ($i = $random->getInt(0, 80); $i > 0; $i--) {
                $description .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }
            $this->assertSame(
                serialize(BtcPaymentController::parseItemDescriptionPhp($description)),
                serialize(AdminPaymentController::transformNative(14, [$description])),
                'PCRE byte-mode parity for '.bin2hex($description),
            );
        }
    }

    public function test_abi_capacity_malformed_inputs_and_overlap_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            foreach ([...$common, ...$uncommon] as $args) {
                $input = serialize(self::rawArgs($op, $args));
                $wire = serialize($class::$php(...$args));
                $buffer = $ffi->new('char['.(strlen($wire) + 2).']');
                foreach ([0, 1, strlen($wire) - 1, strlen($wire), strlen($wire) + 1] as $capacity) {
                    FFI::memset($buffer, 0x5A, strlen($wire) + 2);
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_w29_admin_settings_status_monitoring_payment($op, $input, strlen($input), $buffer, $capacity);
                    $this->assertSame($capacity < strlen($wire) ? -strlen($wire) : strlen($wire), $written, $method.' capacity');
                    $this->assertSame($capacity < strlen($wire) ? str_repeat('Z', strlen($wire) + 2) : $wire.'ZZ', FFI::string($buffer, strlen($wire) + 2));
                }
            }
        }
        $buffer = $ffi->new('char[1024]');
        foreach (['a:0:{}x', 'a:00:{}', 'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:1:{i:0;R:1;}', 'a:1:{i:0;s:100:"a";}', 'a:1:{s:1:"0";s:1:"a";}', 'a:1:{i:0;i:+1;}', 'a:2:{i:0;s:1:"a";i:0;s:1:"b";}'] as $bad) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w29_admin_settings_status_monitoring_payment(10, $bad, strlen($bad), $buffer, 1024));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
        $input = serialize(['ASC']);
        foreach ([[null, 0, $buffer, 1024], [null, 1, $buffer, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 1], [$input, 67108865, $buffer, 1024], [$input, strlen($input), $buffer, 67108865], [$buffer, 20, $buffer, 1024], [FFI::addr($buffer[1]), 20, $buffer, 1024], [$buffer, 20, FFI::addr($buffer[1]), 1023]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w29_admin_settings_status_monitoring_payment(10, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
    }

    public function test_transport_rejects_unsafe_values_and_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $object = new class
        {
            public function __serialize(): array
            {
                throw new RuntimeException('Do not serialize objects.');
            }
        };
        $this->assertNull(AdminPaymentController::transformNative(2, ['terms', $object]));
        $this->assertSame($object, AdminSettingsController::coerceNullableStringNative('terms', $object));
        $reference = 'ASC';
        $this->assertNull(AdminPaymentController::transformNative(10, [&$reference]));
        $cycle = [];
        $cycle[] = &$cycle;
        $this->assertNull(AdminPaymentController::transformNative(0, [$cycle]));
        foreach (['1e2', '0.5', '12tail', '999999999999999999999999999999'] as $numeric) {
            $attributes = ['post_non_mode' => $numeric];
            $this->assertNull(AdminPaymentController::transformNative(0, [$attributes]));
            $this->assertSame(AdminSettingsController::normalizeBinaryModesPhp($attributes), AdminSettingsController::normalizeBinaryModesNative($attributes));
        }
        foreach ([
            [['tx_count' => PHP_INT_MAX, 'invoice_total' => 0.0, 'value_total' => 0.0], ['tx_count' => 1, 'invoice_total' => 0.0, 'value_total' => 0.0]],
            [['tx_count' => 0, 'invoice_total' => INF, 'value_total' => NAN]],
        ] as $rows) {
            $this->assertNull(AdminPaymentController::transformNative(11, [$rows]));
            $outcomes = [];
            foreach (['summaryTotalsPhp', 'summaryTotalsNative'] as $method) {
                try {
                    $outcomes[] = ['result', serialize(AdminPaymentController::$method($rows))];
                } catch (Throwable $error) {
                    $outcomes[] = [$error::class, $error->getMessage()];
                }
            }
            $this->assertSame($outcomes[0], $outcomes[1]);
        }
        $this->assertNull(AdminPaymentController::transformNative(999, []));
        $this->assertNull(AdminPaymentController::transformNative(10, [123]));
        $this->assertNull(AdminPaymentController::transformNative(11, [[['tx_count' => 1]]]));
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Http\Controllers\Admin\AdminPaymentController::transformNative(10, ['ASC']) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($vectors as [$class, $method, $common, $uncommon]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $class::setUseRustFfi(true);
                foreach ([...$common, ...$uncommon] as $args) {
                    $expected = serialize($class::$php(...$args));
                    if ($expected !== serialize($class::$native(...$args)) || $expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $missing = '/tmp/w29-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_real_status_store_and_update_pass_normalized_data_to_service(): void
    {
        $this->nativeFfi();
        Carbon::setTestNow('2026-10-24 12:34:56');
        Auth::shouldReceive('id')->twice()->andReturn(7);
        foreach ([false, true] as $enabled) {
            AdminStatusController::setUseRustFfi($enabled);
            $incident = new ServiceIncident;
            $service = $this->createMock(SiteStatusService::class);
            $service->expects($this->once())->method('createIncident')->with(['status' => 'resolved', 'resolved_at' => '2026-10-24 12:34:56', 'created_by' => 7])->willReturn($incident);
            $service->expects($this->once())->method('updateIncident')->with($incident, ['status' => 'investigating', 'resolved_at' => null])->willReturn($incident);
            $controller = (new ReflectionClass(AdminStatusController::class))->newInstanceWithoutConstructor();
            (new ReflectionClass(AdminStatusController::class))->getProperty('siteStatusService')->setValue($controller, $service);
            $store = new class extends StoreIncidentRequest
            {
                /** @return array<string, mixed> */
                public function validated($key = null, $default = null): array
                {
                    return ['status' => 'resolved', 'resolved_at' => null];
                }
            };
            $update = new class extends UpdateIncidentRequest
            {
                /** @return array<string, mixed> */
                public function validated($key = null, $default = null): array
                {
                    return ['status' => 'investigating', 'resolved_at' => 'yesterday'];
                }
            };
            $this->assertSame(302, $controller->store($store)->getStatusCode());
            $this->assertSame(302, $controller->update($update, $incident)->getStatusCode());
        }
    }

    public function test_real_monitoring_rejects_header_spoofs_and_preserves_cookie_security(): void
    {
        $this->nativeFfi();
        foreach ([false, true] as $enabled) {
            AdminMonitoringController::setUseRustFfi($enabled);
            $controller = (new ReflectionClass(AdminMonitoringController::class))->newInstanceWithoutConstructor();
            $grafana = $this->createMock(GrafanaEmbedService::class);
            $grafana->method('isEnabled')->willReturn(true);
            $grafana->method('authMode')->willReturn(GrafanaEmbedService::AUTH_COOKIE);
            $grafana->method('cookiePath')->willReturn('/grafana/');
            $issuer = $this->createMock(GrafanaJwtIssuer::class);
            $issuer->expects($this->exactly(3))->method('issueFor')->willReturn(['token' => 'private-jwt', 'expires_at' => 1800000000, 'ttl' => 900]);
            foreach ([null, 1, true, '01'] as $marker) {
                $request = Request::create('/grafana-auth', 'GET', [], [], [], ['HTTP_NNTMUX_GRAFANA_AUTH_REQUEST' => '1', 'HTTP_X_NNTMUX_GRAFANA_JWT' => 'spoof', AdminMonitoringController::AUTH_REQUEST_PARAM => $marker]);
                try {
                    $controller->authorizeGrafana($request, $grafana, $issuer);
                    $this->fail('Client headers and non-string server markers must be rejected.');
                } catch (HttpException $exception) {
                    $this->assertSame(404, $exception->getStatusCode());
                }
            }
            $request = Request::create('/grafana-auth', 'GET', [], [], [], [AdminMonitoringController::AUTH_REQUEST_PARAM => '1']);
            $request->setUserResolver(static fn (): User => new User);
            $response = $controller->authorizeGrafana($request, $grafana, $issuer);
            $this->assertSame(204, $response->getStatusCode());
            $this->assertSame('private-jwt', $response->headers->get(AdminMonitoringController::AUTH_REQUEST_HEADER));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            foreach ([false, true] as $secure) {
                config(['session.secure' => $secure]);
                $response = $controller->token($request, $grafana, $issuer);
                $cookie = $response->headers->getCookies()[0];
                $this->assertSame($secure, $cookie->isSecure());
                $this->assertTrue($cookie->isHttpOnly());
                $this->assertSame('strict', $cookie->getSameSite());
                $this->assertSame('/grafana/', $cookie->getPath());
                $this->assertArrayNotHasKey('token', $response->getData(true));
            }
        }
    }

    public function test_real_settings_show_and_payment_query_use_helper_dispatch(): void
    {
        $this->nativeFfi();
        $this->useIsolatedDatabase();
        Schema::create('site_configurations', function (Blueprint $table): void {
            $table->id();
            $table->integer('post_non_mode');
            $table->integer('post_amazon_mode');
        });
        DB::table('site_configurations')->insert(['id' => 1, 'post_non_mode' => 2, 'post_amazon_mode' => -1]);
        $this->createPaymentsTable();
        DB::table('payments')->insert(['username' => 'Alice', 'email' => 'alice@example.test', 'payment_status' => 'Settled', 'invoice_status' => 'Pending', 'invoice_amount' => '1.25', 'payment_value' => '0.5', 'payment_method' => 'BTC', 'created_at' => '2026-10-24 10:00:00']);
        foreach ([false, true] as $enabled) {
            AdminSettingsController::setUseRustFfi($enabled);
            $reflection = new ReflectionClass(AdminSettingsController::class);
            $settings = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('catalog')->setValue($settings, new SettingsPageCatalog);
            $values = $settings->show('site')->getData()['configurationValues'];
            $this->assertSame(1, $values['post_non_mode']);
            $this->assertSame(0, $values['post_amazon_mode']);
            AdminPaymentController::setUseRustFfi($enabled);
            BtcPaymentController::setUseRustFfi($enabled);
            $controller = (new ReflectionClass(AdminPaymentController::class))->newInstanceWithoutConstructor();
            $request = Request::create('/admin/payments', 'GET', ['username' => ' Alice ', 'invoice_status' => ' Pending ', 'sort' => 'invalid; DROP TABLE payments', 'order' => 'ASC']);
            $this->app->instance('request', $request);
            config(['nntmux.items_per_page' => 10]);
            $data = $controller->index($request)->getData();
            $this->assertSame('Alice', $data['filters']['username']);
            $this->assertSame('Pending', $data['filters']['invoice_status']);
            $this->assertSame('', $data['filters']['email']);
            $this->assertSame('created_at', $data['sort']);
            $this->assertSame('asc', $data['order']);
            $this->assertSame(1, $data['payments']->total());
            $this->assertSame(['tx_count' => 1, 'invoice_total' => 1.25, 'value_total' => 0.5], $data['summaryTotals']);
        }
    }

    public function test_real_btc_callback_rejects_bad_signatures_without_effects_and_replays_once(): void
    {
        $this->nativeFfi();
        $this->useIsolatedDatabase();
        $this->createPaymentsTable();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('email');
            $table->softDeletes();
        });
        DB::table('users')->insert(['username' => 'Alice', 'email' => 'alice@example.test']);
        $logger = $this->createMock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('btc_payment')->andReturn($logger);
        config(['nntmux.btcpay_webhook_secret' => 'test-only-secret']);
        foreach ([false, true] as $enabled) {
            BtcPaymentController::setUseRustFfi($enabled);
            DB::table('payments')->delete();
            Cache::put(AdminDashboardSnapshotService::CACHE_KEY, 'unchanged');
            $body = json_encode(['type' => 'InvoicePaymentSettled', 'invoiceId' => 'invoice-1', 'webhookId' => 'webhook-1', 'metadata' => ['buyerEmail' => 'alice@example.test', 'itemDesc' => 'User 1', 'orderId' => 'order-1', 'invoice_amount' => '1.25'], 'payment' => ['id' => 'payment-1', 'status' => 'Settled', 'value' => '0.5'], 'paymentMethodId' => 'BTC'], JSON_THROW_ON_ERROR);
            $controller = (new ReflectionClass(BtcPaymentController::class))->newInstanceWithoutConstructor();
            $request = Request::create('/btcpay', 'POST', [], [], [], ['HTTP_BTCPAY_SIG' => 'sha256=wrong'], $body);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertSame(404, $controller->btcPayCallback($request)->getStatusCode());
            $this->assertSame([], DB::getQueryLog(), 'Invalid signatures must stop before database access.');
            DB::disableQueryLog();
            $this->assertSame('unchanged', Cache::get(AdminDashboardSnapshotService::CACHE_KEY));
            $this->assertSame(0, DB::table('payments')->count());
            $request->headers->set('btcpay-sig', 'sha256='.hash_hmac('sha256', $body, 'test-only-secret'));
            $this->assertSame(200, $controller->btcPayCallback($request)->getStatusCode());
            $this->assertNull(Cache::get(AdminDashboardSnapshotService::CACHE_KEY));
            $this->assertSame(1, DB::table('payments')->count());
            $stored = (array) DB::table('payments')->first();
            $this->assertSame(200, $controller->btcPayCallback($request)->getStatusCode());
            $this->assertSame(1, DB::table('payments')->count());
            $this->assertSame($stored, (array) DB::table('payments')->first());
        }
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w29-admin-settings-status-monitoring-payment-test', AdminPaymentController::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicitly built optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    private function useIsolatedDatabase(): void
    {
        config(['database.default' => 'wave29_parity', 'database.connections.wave29_parity' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('wave29_parity');
    }

    private function createPaymentsTable(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            foreach (['username', 'email', 'item_description', 'order_id', 'payment_id', 'payment_status', 'invoice_status', 'invoice_amount', 'payment_method', 'payment_value', 'webhook_id', 'invoice_id'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
        });
    }

    /** @param list<mixed> $args
     * @return list<mixed>
     */
    private static function rawArgs(int $op, array $args): array
    {
        return match ($op) {
            3 => [...$args, SettingsPageCatalog::MOVIE_PROCESSING_COLUMNS, SettingsPageCatalog::VIDEO_PANE_COLUMNS],
            9 => [...$args, self::SORT_COLUMNS],
            default => $args,
        };
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>, int}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 1024);
        $now = '2026-10-24 12:34:56';
        $body = '{"type":"InvoiceSettled"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'secret');
        $row = ['tx_count' => 2, 'invoice_total' => 1.25, 'value_total' => 0.00025];

        return [
            [AdminSettingsController::class, 'normalizeBinaryModes', [[['post_non_mode' => 2, 'post_amazon_mode' => '1']], [[]]], [[['post_non_mode' => '-1', 'post_amazon_mode' => 0.9, 'other' => $long]], [['post_non_mode' => null, 'post_amazon_mode' => false]], [['post_non_mode' => [], 'post_amazon_mode' => [1]]]], 0],
            [AdminSettingsController::class, 'resolveSensitiveField', [['secret', false, true, 'value'], ['secret', false, false, '']], [['secret', true, true, $long], ['0', false, true, '0'], ["key\0\xff", true, false, '']], 1],
            [AdminSettingsController::class, 'coerceNullableString', [['terms', null], ['title', 'Title']], [['dereferrer_link', null], ['title', null], ['terms', false], ['terms', ['nested' => $long]], ['title', -0.0]], 2],
            [AdminSettingsController::class, 'partitionAttributes', [[['movie_lookup' => 1, 'post_non_mode' => 2, 'post_threads' => 4], true], [[], false]], [[['max_movies_processed' => null, 'keep' => $long, 'post_non_mode' => '0', 'movie_lookup' => 0], true], [['movie_lookup' => 1, 'post_non_mode' => 2], false]], 3],
            [AdminStatusController::class, 'normalizeIncident', [[['status' => 'resolved'], $now, false], [['status' => 'investigating', 'resolved_at' => $now], $now, true]], [[['status' => 'resolved', 'resolved_at' => '0', 'title' => $long], $now, false], [['status' => 'resolved', 'resolved_at' => '0'], $now, true], [['status' => 'resolved', 'resolved_at' => false], $now, false], [['status' => 'resolved', 'resolved_at' => []], $now, true], [[], $now, false]], 4],
            [AdminMonitoringController::class, 'buildViewConfiguration', [[true, true, 'now-6h', '1m'], [false, false, 'now-6h', '1m']], [[false, true, $long, ''], [true, false, '0', $long]], 5],
            [AdminMonitoringController::class, 'cookieSecure', [[false, false], [true, false]], [[false, true], [true, true]], 6],
            [AdminMonitoringController::class, 'validAuthRequest', [['1', true], [null, false]], [[1, true], [true, true], ['1', false], ['01', true], [['1'], true], [$long, true]], 7],
            [AdminPaymentController::class, 'normalizeFilters', [[['username' => ' Alice ', 'email' => ' a@example.test ']], [[]]], [[['username' => "\0\t A\r\n", 'email' => $long, 'payment_status' => ' Settled ', 'invoice_status' => ' Pending ', 'start_date' => ' 2026-10-01 ', 'end_date' => ' 2026-10-24 ', 'ignored' => 'discard']], [['username' => "\xc2\xa0\xe2\x80\x8bAlice\x0c\xc2\xa0", 'email' => '0']]], 8],
            [AdminPaymentController::class, 'sortColumn', [['created_at'], ['username']], [['ID'], [''], ['username desc;--'], [$long], ['order_id']], 9],
            [AdminPaymentController::class, 'sortDirection', [['asc'], ['DESC']], [['ASC'], [' asc '], [''], [$long]], 10],
            [AdminPaymentController::class, 'summaryTotals', [[[$row]], [[]]], [[array_fill(0, 128, $row)], [[9 => ['tx_count' => 0, 'invoice_total' => -0.0, 'value_total' => -0.25], 'x' => ['tx_count' => 3, 'invoice_total' => 1.0E100, 'value_total' => 0.25]]]], 11],
            [BtcPaymentController::class, 'verifyWebhookSignature', [[$body, 'secret', $signature], [$body, 'secret', null]], [[$long, "secret\0\xff", 'sha256='.hash_hmac('sha256', $long, "secret\0\xff")], [$body, 'secret', strtoupper($signature)], [$body, 'secret', $signature.' '], ['', '', 'sha256='.hash_hmac('sha256', '', '')]], 12],
            [BtcPaymentController::class, 'fallbackEmails', [['alice@pm.me'], ['alice@example.test']], [['@pm.me'], ['alice@PM.ME'], [$long.'@pm.me'], ['not-an-email']], 13],
            [BtcPaymentController::class, 'parseItemDescription', [['User 1'], ['Admin ++ 2']], [['prefix: Friend 003 trailing'], ['User\t2'], ["User\t2"], ['No subscription'], ['User 999999999999999999999999999999'], [$long.'Role 4']], 14],
            [BtcPaymentController::class, 'paymentStatusesForAdminFilter', [[]], [[]], 15],
            [BtcPaymentController::class, 'invoiceStatusesForAdminFilter', [[]], [[]], 16],
        ];
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave29-2-admin-settings-status-monitoring-payment-controllers-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 29022026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'uncertainty' => 'Three-round min/max; descriptive, not a confidence interval. 8200 calls/side/round, 24600/cell.',
                'scope' => 'Public helper dispatch including scalar normalization, serialization, allocation, FFI and reconstruction. SQL stays unchanged; excludes app boot and database I/O. No production throughput claim.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w29-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAdminSettingsStatusMonitoringPaymentControllersParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'resource_limits' => self::command(['/bin/sh', '-c', 'ulimit -a']),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'cargo' => self::command(['cargo', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w29-2 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php',
            'app/Http/Controllers/Admin/AdminSettingsController.php', 'app/Http/Controllers/Admin/AdminStatusController.php', 'app/Http/Controllers/Admin/AdminMonitoringController.php', 'app/Http/Controllers/Admin/AdminPaymentController.php', 'app/Http/Controllers/BtcPaymentController.php', 'tests/Unit/RustAdminSettingsStatusMonitoringPaymentControllersParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937(29022026));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $this->assertSame(end($expected), serialize(AdminPaymentController::transformNative($op, self::rawArgs($op, $args))), $name.' raw native preflight');
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
            fwrite(STDERR, "\nWave 29.2 raw benchmark evidence: {$path}\n");
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
