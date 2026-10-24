<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\GdprRequestStatusMail;
use App\Models\GdprRequest;
use App\Services\Gdpr\GdprDataInventory;
use App\Services\Gdpr\GdprExportService;
use App\Services\Gdpr\GdprNotificationService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Wave 23.1: independent fixtures, caller parity, direct C ABI safety, and paired benchmarks.
 *
 * NNTMUX_GDPR_EXPORT_MAIL_BENCH=1 php vendor/bin/phpunit tests/Unit/RustGdprExportMailParityTest.php
 * Build first: cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *
 * The benchmark preserves 3 rounds x 41 paired samples x 200 calls, with 5 warmup batches
 * per implementation and seeded randomized pair order. Times include PHP dispatch, FFI,
 * framing, allocation, decoding, and result construction. Its p99 is the nearest-rank p99
 * of 41 batch means (200 calls each), NOT individual-call or production tail latency.
 * This screen cannot establish a production p99 improvement; defaults remain PHP.
 * Raw samples, order, fixture hashes, failures, source/build/host/tool identities are
 * retained in a unique system-temp JSON artifact, printed even if measurement fails.
 *
 * Apple M5 / PHP 8.5.12 / rustc 1.99.1 release screen (2026-10-24).
 * Worst-round PHP/Rust latency speedups: common mean/p99; uncommon mean/p99.
 * GdprDataInventory::retainedRecords: 0.0269/0.0403; 0.2272/0.1958
 * GdprDataInventory::erasableTables: 0.0169/0.0166; 0.1814/0.1497
 * GdprDataInventory::anonymizedTables: 0.0467/0.0313; 0.1814/0.2162
 * GdprDataInventory::exportTables: 0.0110/0.0109; 0.1939/0.1831
 * GdprDataInventory::essentialCookies: 0.0298/0.0377; 0.1892/0.2034
 * GdprExportService::formatExportPath: 0.0788/0.0458; 0.1013/0.0367
 * GdprExportService::redactAccountAttributes: 0.1351/0.0981; 0.0479/0.0501
 * GdprExportService::redactRowData: 0.1065/0.1096; 0.0878/0.1131
 * GdprNotificationService::normalizeRecipientEmail: 1.1572/0.7175; 3.0084/2.4520
 * GdprNotificationService::shouldSendAdminCopy: 0.1252/0.1167; 0.1232/0.1247
 * GdprRequest::evaluateDownloadableExport: 0.1094/0.0878; 0.1011/0.1105
 * GdprRequestStatusMail::formatLabel: 0.2154/0.2499; 0.2796/0.2646
 * GdprRequestStatusMail::formatRequester: 0.1936/0.1740; 0.2109/0.2066
 * GdprRequestStatusMail::resolveMailContent: 0.1137/0.1166; 0.0658/0.0643
 * Every helper fails at least one 1.5x gate; all five class defaults stay false.
 * Raw artifact: nntmux-w23-gdpr-l0op41p0tdrud52kUxo (system temp).
 * This docblock records the screen after execution; exact pre-annotation source
 * hashes, library identity, protocol, raw samples and failures are in the artifact.
 */
class RustGdprExportMailParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [GdprDataInventory::class, GdprExportService::class, GdprNotificationService::class, GdprRequest::class, GdprRequestStatusMail::class];

    private const string CDEF = 'int32_t nntmux_w23_gdpr_export_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    /** @var list<string> */
    private const array SECRETS = ['password', 'remember_token', 'session_token', 'api_token', 'resetguid', 'verification_token', 'sabapikey', 'nzbgetpassword', 'nzbvortex_api_key', 'cp_api'];

    /** @var array<string, FFI|false>|null */
    private ?array $savedDomainLibraries = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
        if ($this->savedDomainLibraries !== null) {
            (new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries'))->setValue(null, $this->savedDomainLibraries);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_class_defaults_and_independent_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::usesRustFfi(), $class);
            $class::enableRustFfi();
            self::assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            self::assertFalse($class::usesRustFfi());
            $class::enableRustFfi();
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi());
            self::assertSame(self::CDEF, $class::FFI_CDEF);
        }
    }

    private function native(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave23-gdpr-export-mail-test', self::CDEF);
        self::assertNotNull($ffi, 'Required native parity: build the release Rust pipeline library before running this harness.');

        return $ffi;
    }

    /** @param list<?string> $parts */
    private static function frames(array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= $part === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($part)).$part;
        }

        return $out;
    }

    private static function abi(FFI $ffi, mixed ...$args): int
    {
        // Dynamic symbol invocation is intentionally direct: PHP fallback cannot mask failure.
        $symbol = 'nntmux_w23_gdpr_export_mail';

        // @phpstan-ignore method.notFound (The symbol is defined by the CDEF supplied to FFI::cdef.)
        return $ffi->$symbol(...$args);
    }

    private function raw(int $operation, string $input, string $expected): void
    {
        $ffi = $this->native();
        $cap = max(1, strlen($expected));
        $out = $ffi->new("char[{$cap}]");
        self::assertSame(strlen($expected), self::abi($ffi, $operation, $input, strlen($input), $out, $cap), "operation {$operation}");
        self::assertSame($expected, $expected === '' ? '' : FFI::string($out, strlen($expected)), "operation {$operation}");
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function parity(string $class, string $method, array $args, mixed $expected): void
    {
        self::assertSame($expected, $class::{$method.'Php'}(...$args), $class.'::'.$method.' PHP fixture');
        self::assertSame($expected, $class::{$method.'Native'}(...$args), $class.'::'.$method.' native fixture');
        foreach ([false, true] as $enabled) {
            $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
            self::assertSame($expected, $class::$method(...$args), $class.'::'.$method.' dispatch');
        }
    }

    public function test_inventory_exact_independent_fixtures_and_public_callers(): void
    {
        $ffi = $this->native();
        $inventory = new GdprDataInventory;
        foreach (['retainedRecords', 'erasableTables', 'anonymizedTables', 'exportTables', 'essentialCookies'] as $op => $method) {
            $expected = self::inventoryFixture($method);
            self::assertSame($expected, GdprDataInventory::{$method.'Php'}());
            self::assertSame($expected, GdprDataInventory::{$method.'Native'}());
            foreach ([false, true] as $use) {
                $use ? GdprDataInventory::enableRustFfi() : GdprDataInventory::disableRustFfi();
                self::assertSame($expected, $inventory->$method());
            }
            $out = $ffi->new('char[32768]');
            $length = self::abi($ffi, $op, '', 0, $out, 32768);
            self::assertGreaterThan(0, $length);
            self::assertSame($expected, json_decode(FFI::string($out, $length), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function test_path_bytes_integer_boundaries_and_account_redaction(): void
    {
        $this->native();
        foreach ([[42, '20261024090000', 'abc123'], [0, '', ''], [-7, "a\0b", "é\xff"], [PHP_INT_MIN, '../x', '/random'], [PHP_INT_MAX, str_repeat('t', 5000), str_repeat('r', 5000)]] as [$id, $timestamp, $random]) {
            $expected = 'gdpr/exports/user-'.$id.'-'.$timestamp.'-'.$random.'.json';
            $this->parity(GdprExportService::class, 'formatExportPath', [$id, $timestamp, $random], $expected);
            $this->raw(5, self::frames([(string) $id, $timestamp, $random]), $expected);
        }
        $object = (object) ['opaque' => true];
        $attributes = ['username' => 'alice', 'password' => 'secret', 'Password' => 'keep', 'nested' => ['password' => 'nested value'], 7 => $object, 'redacted_columns' => ['old'], 'nullable' => null];
        foreach (self::SECRETS as $column) {
            $attributes[$column] = 'secret-'.$column;
        }
        $expected = ['username' => 'alice', 'Password' => 'keep', 'nested' => ['password' => 'nested value'], 7 => $object, 'redacted_columns' => self::SECRETS, 'nullable' => null];
        $this->parity(GdprExportService::class, 'redactAccountAttributes', [$attributes], $expected);
        self::assertSame('secret-password', $attributes['password']);
        $this->parity(GdprExportService::class, 'redactAccountAttributes', [[]], ['redacted_columns' => self::SECRETS]);
        $this->raw(6, self::frames(['password', 'Password', 'api_token', 'username', '', "password\0", '0']), '1010000');
    }

    public function test_row_redaction_preserves_mixed_values_order_keys_and_references(): void
    {
        $this->native();
        $object = (object) ['id' => 3];
        $data = ['id' => 9, 'secret' => null, 'object' => $object, 'nested' => ['secret' => 'keep'], 3 => false, '' => 'empty', '03' => 3.5];
        $expected = ['id' => 9, 'secret' => '[redacted]', 'object' => $object, 'nested' => ['secret' => 'keep'], 3 => '[redacted]', '' => '[redacted]', '03' => 3.5];
        $this->parity(GdprExportService::class, 'redactRowData', [$data, ['secret', 'secret', 'missing', '3', '']], $expected);
        $this->parity(GdprExportService::class, 'redactRowData', [[], ['missing']], []);
        foreach (['Php', 'Native', ''] as $suffix) {
            foreach ([false, true] as $enabled) {
                $enabled ? GdprExportService::enableRustFfi() : GdprExportService::disableRustFfi();
                $value = 'old';
                $data = ['first' => &$value, 'last' => 3];
                $result = GdprExportService::{'redactRowData'.$suffix}($data, ['first']);
                self::assertSame('[redacted]', $value, 'A native PHP array copy preserves reference identity');
                $result['first'] = 'changed';
                self::assertSame('changed', $value);
                self::assertSame(['first', 'last'], array_keys($result));
            }
        }
        $this->raw(7, self::frames(['4', 'secret', 'other', '0', '', 'secret', '0', 'missing']), '1010');
        $rowMethod = new ReflectionMethod(GdprExportService::class, 'rowToArray');
        foreach ([false, true] as $enabled) {
            $enabled ? GdprExportService::enableRustFfi() : GdprExportService::disableRustFfi();
            self::assertSame(['id' => 7, 'secret' => '[redacted]', 'nested' => ['a' => null]], $rowMethod->invoke(new GdprExportService, (object) ['id' => 7, 'secret' => null, 'nested' => (object) ['a' => null]], ['secret', 'missing']));
        }
    }

    public function test_recipient_validation_and_admin_routing_parity(): void
    {
        $this->native();
        $emails = ['', '0', 'plain', 'User@example.com', " \tUser+tag@EXAMPLE.com\r\n", 'a..b@example.com', '.a@example.com', 'a.@example.com', 'a@localhost', 'a@-bad.example', 'a@bad-.example', 'a@exam_ple.com', 'a@127.0.0.1', 'a@[127.0.0.1]', '"quoted local"@example.com', '"a@b"@example.com', 'é@example.com', 'a@é.example', "a@example.com\0", "\0a@example.com\0", "\x0ba@example.com\x0b", "\x0ca@example.com\x0c", "a\nb@example.com", str_repeat('a', 64).'@example.com', str_repeat('a', 65).'@example.com', 'a@'.str_repeat('b', 64).'.com'];
        foreach ($emails as $email) {
            $trimmed = trim($email);
            $expected = filter_var($trimmed, FILTER_VALIDATE_EMAIL) ? $trimmed : null;
            $this->parity(GdprNotificationService::class, 'normalizeRecipientEmail', [$email], $expected);
        }
        $this->raw(8, self::frames(['  User+tag@Example.com  ']), 'User+tag@Example.com');
        $this->raw(8, self::frames(['invalid']), '');
        foreach (['erasure', 'export', 'rectification', 'restriction', '', 'Erasure', "erasure\0"] as $type) {
            foreach (['submitted', 'export_ready', 'rejected', 'erasure_completed', '', 'Submitted'] as $event) {
                $expected = $type === 'erasure' && $event === 'submitted';
                $this->parity(GdprNotificationService::class, 'shouldSendAdminCopy', [$type, $event], $expected);
                $this->raw(9, self::frames([$type, $event]), $expected ? '1' : '0');
            }
        }
    }

    public function test_download_eligibility_exact_boundaries_and_real_model(): void
    {
        $this->native();
        $now = 1_792_830_600;
        foreach (['export', 'erasure', '', 'Export'] as $type) {
            foreach (['completed', 'pending', '', 'Completed'] as $status) {
                foreach ([null, '', '0', 'gdpr/export.json'] as $path) {
                    foreach ([null, $now - 1, $now, $now + 1, PHP_INT_MIN, PHP_INT_MAX] as $expiry) {
                        $expected = $type === 'export' && $status === 'completed' && $path !== null && ($expiry === null || $expiry > $now);
                        $args = [$type, $status, $path, $expiry, $now];
                        $this->parity(GdprRequest::class, 'evaluateDownloadableExport', $args, $expected);
                        $this->raw(10, self::frames([$type, $status, $path, $expiry === null ? null : (string) $expiry, (string) $now]), $expected ? '1' : '0');
                    }
                }
            }
        }
        Carbon::setTestNow(Carbon::createFromTimestampUTC($now)->addMicroseconds(500_000));
        foreach ([null, $now - 1, $now, $now + 1] as $expiry) {
            foreach ([false, true] as $enabled) {
                $enabled ? GdprRequest::enableRustFfi() : GdprRequest::disableRustFfi();
                $request = new GdprRequest;
                $request->setDateFormat('Y-m-d H:i:s.u');
                $request->fill(['type' => 'export', 'status' => 'completed', 'export_path' => '', 'export_expires_at' => $expiry === null ? null : Carbon::createFromTimestampUTC($expiry)]);
                self::assertSame($expiry === null || $expiry > $now, $request->isDownloadableExport());
            }
        }
        foreach ([false, true] as $enabled) {
            $enabled ? GdprRequest::enableRustFfi() : GdprRequest::disableRustFfi();
            $request = new GdprRequest;
            $request->setDateFormat('Y-m-d H:i:s.u');
            $request->setRawAttributes(['type' => 'export', 'status' => 'completed', 'export_path' => 'x', 'export_expires_at' => Carbon::createFromTimestampUTC($now)->addMicroseconds(750_000)->format('Y-m-d H:i:s.u')]);
            self::assertTrue($request->isDownloadableExport(), 'A future expiry in the same second remains downloadable');
        }
    }

    public function test_labels_requesters_and_mail_content_independent_fixtures(): void
    {
        $this->native();
        foreach (['export', 'some_request_type', '_', '', '0', 'é_request', "\xff_x", "abc\0_def", str_repeat('long_', 1500)] as $value) {
            $expected = ucfirst(str_replace('_', ' ', $value));
            $this->parity(GdprRequestStatusMail::class, 'formatLabel', [$value], $expected);
            $this->raw(11, self::frames([$value]), $expected);
        }
        foreach ([null, '', '0', 'alice', ' alice ', "\0x\0", 'é'] as $username) {
            foreach ([null, '', '0', 'a@example.com', ' spaced '] as $email) {
                $expected = trim(($username ?: 'Unknown user').' <'.($email ?: 'unknown email').'>');
                $this->parity(GdprRequestStatusMail::class, 'formatRequester', [$username, $email], $expected);
                $this->raw(12, self::frames([$username, $email]), $expected);
            }
        }
        foreach (['export', 'erasure', 'some_request', '', "é\0_x"] as $type) {
            foreach (['submitted', 'export_ready', 'erasure_completed', 'rejected', 'unknown'] as $event) {
                foreach ([false, true] as $admin) {
                    foreach ([null, '', '/privacy?x=1&b=2', "x\0\xff"] as $url) {
                        $expected = self::mailFixture($type, $event, $admin, $url);
                        $this->parity(GdprRequestStatusMail::class, 'resolveMailContent', [$type, $event, $admin, $url], $expected);
                        $this->raw(13, self::frames([$type, $event, $admin ? '1' : '0', $url]), self::frames($expected));
                    }
                }
            }
        }
    }

    public function test_real_notification_and_mail_construction_without_database_or_network(): void
    {
        $previous = Container::getInstance();
        $facadeApp = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 2));
        $container->instance('config', new Repository(['mail' => ['default' => 'array', 'from' => ['address' => ' admin@example.com ']], 'app' => ['name' => 'Parity Site', 'timezone' => 'UTC']]));
        $container->instance('mail.manager', new MailManager($container));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('mail.manager');
        try {
            foreach ([false, true] as $enabled) {
                foreach (self::CLASSES as $class) {
                    $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
                }
                Mail::fake();
                $request = new GdprRequest(['requester_username' => 'alice', 'requester_email' => ' alice@example.com ', 'type' => 'erasure', 'status' => 'pending']);
                (new GdprNotificationService)->requestSubmitted($request);
                Mail::assertSentCount(2);
                Mail::assertSent(GdprRequestStatusMail::class, static fn (GdprRequestStatusMail $mail): bool => $mail->hasTo('alice@example.com') && $mail->heading === 'We received your GDPR request' && $mail->requester === 'alice < alice@example.com >');
                Mail::assertSent(GdprRequestStatusMail::class, static fn (GdprRequestStatusMail $mail): bool => $mail->hasTo('admin@example.com') && $mail->heading === 'New GDPR request submitted');
                Mail::fake();
                $request->type = 'export';
                (new GdprNotificationService)->requestSubmitted($request);
                Mail::assertSentCount(1);
                Mail::fake();
                $request->requester_email = 'invalid';
                (new GdprNotificationService)->exportReady($request);
                Mail::assertNothingSent();
                $request->requester_email = null;
                $request->requester_username = '0';
                $mail = new GdprRequestStatusMail($request, 'erasure_completed');
                self::assertSame('Unknown user <unknown email>', $mail->requester);
                self::assertSame('N/A', $mail->requestedAt);
                self::assertSame(self::mailFixture('export', 'erasure_completed', false, null)[2], $mail->body);
                self::assertSame($mail->body, $mail->preheader);
                self::assertNull($mail->actionUrl);
                self::assertNull($mail->actionText);
            }
        } finally {
            Facade::clearResolvedInstance('mail.manager');
            Facade::setFacadeApplication($facadeApp);
            Container::setInstance($previous);
        }
    }

    public function test_unavailable_library_fallback_for_every_helper(): void
    {
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $this->savedDomainLibraries = $cache->getValue();
        $blocked = $this->savedDomainLibraries;
        $blocked['wave23-gdpr-export-mail|default|'.hash('xxh3', self::CDEF)] = false;
        $cache->setValue(null, $blocked);
        foreach (self::CLASSES as $class) {
            $class::enableRustFfi();
            if (property_exists($class, 'ffi')) {
                (new ReflectionProperty($class, 'ffi'))->setValue(null, false);
            }
        }
        foreach (['retainedRecords', 'erasableTables', 'anonymizedTables', 'exportTables', 'essentialCookies'] as $method) {
            self::assertSame(self::inventoryFixture($method), (new GdprDataInventory)->$method());
        }
        $this->parity(GdprExportService::class, 'formatExportPath', [7, 't', 'r'], 'gdpr/exports/user-7-t-r.json');
        $this->parity(GdprExportService::class, 'redactAccountAttributes', [['password' => 's', 'id' => 7]], ['id' => 7, 'redacted_columns' => self::SECRETS]);
        $this->parity(GdprExportService::class, 'redactRowData', [['secret' => null], ['secret']], ['secret' => '[redacted]']);
        $this->parity(GdprNotificationService::class, 'normalizeRecipientEmail', [' a@example.com '], 'a@example.com');
        $this->parity(GdprNotificationService::class, 'shouldSendAdminCopy', ['erasure', 'submitted'], true);
        $this->parity(GdprRequest::class, 'evaluateDownloadableExport', ['export', 'completed', '', null, 7], true);
        $this->parity(GdprRequestStatusMail::class, 'formatLabel', ['data_export'], 'Data export');
        $this->parity(GdprRequestStatusMail::class, 'formatRequester', [null, null], 'Unknown user <unknown email>');
        $this->parity(GdprRequestStatusMail::class, 'resolveMailContent', ['export', 'submitted', false, '/privacy'], self::mailFixture('export', 'submitted', false, '/privacy'));
    }

    public function test_direct_abi_all_operations_buffer_retry_no_partial_and_byte_alignment(): void
    {
        $ffi = $this->native();
        $cases = [
            5 => [self::frames(['42', '20261024090000', 'random']), 'gdpr/exports/user-42-20261024090000-random.json'],
            6 => [self::frames(['password', 'username', 'api_token']), '101'],
            7 => [self::frames(['2', 'secret', 'name', 'secret']), '10'],
            8 => [self::frames([' a@example.com ']), 'a@example.com'],
            9 => [self::frames(['erasure', 'submitted']), '1'],
            10 => [self::frames(['export', 'completed', '', null, '100']), '1'],
            11 => [self::frames(['pending_request']), 'Pending request'],
            12 => [self::frames([null, 'a@example.com']), 'Unknown user <a@example.com>'],
            13 => [self::frames(['Data export', 'submitted', '0', '/privacy']), self::frames(self::mailFixture('Data export', 'submitted', false, '/privacy'))],
        ];
        foreach (['retainedRecords', 'erasableTables', 'anonymizedTables', 'exportTables', 'essentialCookies'] as $operation => $method) {
            // Compare decoded inventories elsewhere; use the exact successful bytes for capacity checks.
            $out = $ffi->new('char[32768]');
            $written = self::abi($ffi, $operation, '', 0, $out, 32768);
            self::assertGreaterThan(1, $written);
            $cases[$operation] = ['', FFI::string($out, $written)];
        }
        foreach ($cases as $operation => [$input, $expected]) {
            $required = strlen($expected);
            $out = $ffi->new('char[32768]');
            FFI::memset($out, 0x5A, 32768);
            self::assertSame(-$required, self::abi($ffi, $operation, $input, strlen($input), $out, max(0, $required - 1)), "small output {$operation}");
            self::assertSame(str_repeat('Z', 32768), FFI::string($out, 32768), 'No partial write or terminator on insufficient capacity');
            self::assertSame($required, self::abi($ffi, $operation, $input, strlen($input), $out, $required));
            self::assertSame($expected, FFI::string($out, $required));
            self::assertSame('Z', $out[$required], 'Exact byte capacity needs no trailing NUL');

            // Input and output are byte pointers: odd addresses are valid, not a typed-alignment error.
            $inSize = strlen($input) + 2;
            $inStorage = $ffi->new("char[{$inSize}]");
            if ($input !== '') {
                FFI::memcpy(FFI::addr($inStorage[1]), $input, strlen($input));
            }
            $outSize = $required + 2;
            $outStorage = $ffi->new("char[{$outSize}]");
            FFI::memset($outStorage, 0x51, $outSize);
            self::assertSame($required, self::abi($ffi, $operation, FFI::addr($inStorage[1]), strlen($input), FFI::addr($outStorage[1]), $required));
            self::assertSame($expected, FFI::string(FFI::addr($outStorage[1]), $required));
            self::assertSame('Q', $outStorage[0]);
            self::assertSame('Q', $outStorage[$required + 1]);
        }
        foreach ([[6, ''], [7, self::frames(['0'])], [8, self::frames(['invalid'])], [11, self::frames([''])]] as [$operation, $input]) {
            $out = $ffi->new('char[1]');
            $out[0] = 'Z';
            self::assertSame(0, self::abi($ffi, $operation, $input, strlen($input), $out, 0));
            self::assertSame('Z', $out[0]);
        }
    }

    public function test_direct_abi_rejects_null_overflow_wraparound_alias_and_malformed_frames(): void
    {
        $ffi = $this->native();
        $out = $ffi->new('char[256]');
        FFI::memset($out, 0x5A, 256);
        foreach (range(0, 13) as $operation) {
            self::assertSame(-1, self::abi($ffi, $operation, null, 0, $out, 256));
            self::assertSame(-1, self::abi($ffi, $operation, '', 0, null, 0));
            self::assertSame(-1, self::abi($ffi, $operation, '', 0, null, 256));
            self::assertSame(-1, self::abi($ffi, $operation, '', -1, $out, 256), 'size_t above isize::MAX');
            self::assertSame(-1, self::abi($ffi, $operation, '', 0, $out, -1), 'capacity above isize::MAX');
        }
        $wrap = $ffi->cast('char *', -8);
        self::assertSame(-1, self::abi($ffi, 11, $wrap, 16, $out, 256));
        self::assertSame(-1, self::abi($ffi, 11, self::frames(['abc']), 7, $wrap, 16));
        $input = self::frames(['pending_request']);
        $alias = $ffi->new('char[256]');
        foreach ([0, 1, 4] as $offset) {
            FFI::memset($alias, 0x51, 256);
            FFI::memcpy($alias, $input, strlen($input));
            $before = FFI::string($alias, 256);
            $expected = 'Pending request';
            self::assertSame(strlen($expected), self::abi($ffi, 11, $alias, strlen($input), FFI::addr($alias[$offset]), 128));
            self::assertSame(substr($before, 0, $offset).$expected.substr($before, $offset + strlen($expected)), FFI::string($alias, 256), 'Overlap must finish reading before writing and preserve bytes outside output');
        }
        $bad = [
            [99, ''], [0, self::frames(['trailing'])], [1, 'x'], [2, 'x'], [3, 'x'], [4, 'x'],
            [5, ''], [5, self::frames(['x', 't', 'r'])], [5, self::frames(['9223372036854775808', 't', 'r'])], [5, self::frames([null, 't', 'r'])],
            [5, self::frames(['7', 't', 'r', 'extra'])], [6, "\x01"], [6, self::frames([null])], [6, pack('V', 200).'short'],
            [7, self::frames(['x'])], [7, self::frames(['2', 'only-one'])], [7, self::frames(['-1'])], [7, self::frames(['18446744073709551616'])],
            [8, ''], [8, self::frames([null])], [8, self::frames(['a@example.com', 'extra'])],
            [9, self::frames(['erasure'])], [9, self::frames(['erasure', null])],
            [10, self::frames(['export', 'completed', '', 'no', '100'])], [10, self::frames(['export', 'completed', '', null, null])],
            [11, self::frames([null])], [11, self::frames(['a', 'extra'])], [12, ''], [12, self::frames(['a'])],
            [13, self::frames(['export', 'submitted', '2', '/'])], [13, self::frames(['export', 'submitted', null, '/'])], [13, self::frames([null, 'submitted', '0', '/'])],
        ];
        foreach ($bad as [$operation, $bytes]) {
            self::assertSame(-1, self::abi($ffi, $operation, $bytes, strlen($bytes), $out, 256), "malformed {$operation}: ".bin2hex($bytes));
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        }
    }

    /** @return array{string, string, string, ?string, ?string} */
    private static function mailFixture(string $requestType, string $event, bool $admin, ?string $url): array
    {
        if ($admin) {
            return ['New GDPR '.$requestType.' request', 'New GDPR request submitted', 'A GDPR '.$requestType.' request was submitted and may require administrator review.', 'Review request', $url];
        }

        return match ($event) {
            'submitted' => ['GDPR '.$requestType.' request received', 'We received your GDPR request', 'Your GDPR '.$requestType.' request has been received and recorded.', 'Open Privacy Center', $url],
            'export_ready' => ['Your GDPR data export is ready', 'Your data export is ready', 'Your GDPR data export has been generated and is available for download until its listed expiry time.', 'Download export', $url],
            'erasure_completed' => ['Your GDPR erasure request is complete', 'Your account erasure is complete', 'Your GDPR erasure request has been completed. Account data was removed or anonymized, and retained payment/audit records were anonymized or minimized where practical.', null, null],
            'rejected' => ['Your GDPR request was rejected', 'Your GDPR request was rejected', 'Your GDPR '.$requestType.' request was reviewed and rejected. See the notes below for the reason provided by the administrator.', 'Open Privacy Center', $url],
            default => ['GDPR request update', 'GDPR request update', 'There has been an update to your GDPR '.$requestType.' request.', 'Open Privacy Center', $url],
        };
    }

    /** @return array<array-key, mixed> */
    private static function inventoryFixture(string $method): array
    {
        // Frozen literal fixtures from the pre-change inventory; never derived from candidate helpers.
        return match ($method) {
            'retainedRecords' => [
                [
                    'table' => 'payments',
                    'reason' => 'Accounting, fraud prevention, dispute handling, and legal/tax obligations; direct account identifiers are anonymized while transaction details are retained.',
                    'erasure_action' => 'retained_anonymized',
                ],
                [
                    'table' => 'paypal_payments',
                    'reason' => 'Legacy payment audit data retained for accounting and dispute handling; linked account identity resolves only to the anonymized/deleted account record.',
                    'erasure_action' => 'retained_minimized',
                ],
                [
                    'table' => 'user_activities',
                    'reason' => 'Security and administrative audit trail.',
                    'erasure_action' => 'retained_minimized',
                ],
                [
                    'table' => 'gdpr_audit_logs',
                    'reason' => 'GDPR accountability record for data subject requests.',
                    'erasure_action' => 'retained_minimized',
                ],
                [
                    'table' => 'user_role_history',
                    'reason' => 'Administrative audit trail for role changes, upgrades, expirations, and account security reviews.',
                    'erasure_action' => 'retained_minimized',
                ],
            ],
            'erasableTables' => [
                'user_requests' => ['user_column' => 'users_id'],
                'user_downloads' => ['user_column' => 'users_id'],
                'dnzb_failures' => ['user_column' => 'users_id'],
                'users_releases' => ['user_column' => 'users_id'],
                'user_series' => ['user_column' => 'users_id'],
                'user_movies' => ['user_column' => 'users_id'],
                'user_excluded_categories' => ['user_column' => 'users_id'],
                'user_invitations' => ['user_column' => 'user_id'],
                'trusted_devices' => ['user_column' => 'user_id'],
                'password_securities' => ['user_column' => 'user_id'],
                'passkeys' => ['user_column' => 'authenticatable_id', 'extra' => ['authenticatable_type' => 'App\\Models\\User']],
                'model_has_permissions' => ['user_column' => 'model_id', 'extra' => ['model_type' => 'App\\Models\\User']],
                'model_has_roles' => ['user_column' => 'model_id', 'extra' => ['model_type' => 'App\\Models\\User']],
                'role_promotion_stats' => ['user_column' => 'user_id'],
            ],
            'anonymizedTables' => [
                'release_comments' => [
                    'user_column' => 'users_id',
                    'updates' => [
                        'username' => 'Deleted user',
                        'host' => null,
                    ],
                ],
                'release_reports' => [
                    'user_column' => 'users_id',
                    'updates' => [
                        'description' => '[Removed during account erasure]',
                    ],
                ],
            ],
            'exportTables' => [
                'user_requests' => ['user_column' => 'users_id', 'order_by' => 'timestamp'],
                'user_downloads' => ['user_column' => 'users_id', 'order_by' => 'timestamp'],
                'dnzb_failures' => ['user_column' => 'users_id'],
                'users_releases' => ['user_column' => 'users_id', 'order_by' => 'created_at'],
                'user_series' => ['user_column' => 'users_id'],
                'user_movies' => ['user_column' => 'users_id'],
                'user_excluded_categories' => ['user_column' => 'users_id'],
                'user_invitations' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
                'release_comments' => ['user_column' => 'users_id', 'order_by' => 'created_at'],
                'release_reports' => ['user_column' => 'users_id', 'order_by' => 'created_at'],
                'invitations_sent' => ['table' => 'invitations', 'user_column' => 'invited_by', 'order_by' => 'created_at'],
                'invitations_used' => ['table' => 'invitations', 'user_column' => 'used_by', 'order_by' => 'used_at'],
                'trusted_devices' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
                'role_history' => ['table' => 'user_role_history', 'user_column' => 'user_id', 'order_by' => 'created_at'],
                'promotion_stats' => ['table' => 'role_promotion_stats', 'user_column' => 'user_id', 'order_by' => 'created_at'],
                'gdpr_requests' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
                'gdpr_consents' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
                'gdpr_audit_logs' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
            ],
            'essentialCookies' => [
                ['name' => 'session', 'purpose' => 'Keeps you signed in and stores security/session state.', 'type' => 'essential'],
                ['name' => 'XSRF-TOKEN', 'purpose' => 'Protects forms and authenticated requests from cross-site request forgery.', 'type' => 'essential'],
                ['name' => '2fa_trusted_device', 'purpose' => 'Optional security cookie used only when a user chooses to trust a device for two-factor authentication.', 'type' => 'essential_security'],
                ['name' => 'theme/color local storage', 'purpose' => 'Stores display preferences for guests; authenticated preferences are stored on the account.', 'type' => 'essential_preference'],
            ],
            default => throw new \InvalidArgumentException('Unknown inventory helper: '.$method),
        };
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_GDPR_EXPORT_MAIL_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_GDPR_EXPORT_MAIL_BENCH=1 to preserve paired benchmark evidence.');
        }
        $this->native();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w23-gdpr-');
        self::assertNotFalse($artifact);
        $evidence = [
            'protocol' => [
                'rounds' => 3, 'samples_per_round' => 41, 'calls_per_sample' => 200, 'warmup_batches_per_implementation' => 5,
                'order' => 'Seeded local Randomizer Mt19937 per method/workload/round, PHP and Rust paired on identical inputs',
                'p99_definition' => 'nearest rank ceil(0.99 * 41), of batch means of 200 calls; not individual-call or production p99; coarse screening evidence only',
                'gate' => 'Each common/uncommon method must clear 1.5x mean and p99 in every round; all methods sharing a class toggle must qualify; defaults stay PHP pending individual-call p99 evidence',
                'scope' => 'Host CPU helpers only; real unsuffixed callers include dispatch, FFI, framing, decoding and allocation. No DB/network or production throughput claim.',
                'inventory_uncommon' => 'Fixed no-argument inventory has no alternate input; uncommon workload includes JSON serialization used by exported payload consumers.',
                'reproduce' => 'cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release && NNTMUX_GDPR_EXPORT_MAIL_BENCH=1 php vendor/bin/phpunit tests/Unit/RustGdprExportMailParityTest.php',
            ],
            'metadata' => self::benchmarkMetadata(), 'measurements' => [], 'summary' => [], 'failures' => [],
        ];
        fwrite(STDERR, 'Wave23.1 raw benchmark artifact: '.$artifact.PHP_EOL);
        try {
            foreach (self::benchmarkWorkloads() as $name => [$class, $common, $uncommon, $fixtureHash]) {
                $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $call) {
                    $class::disableRustFfi();
                    $expected = $call();
                    $class::enableRustFfi();
                    self::assertSame($expected, $call(), $name.' '.$kind.' correctness before timing');
                    for ($round = 0; $round < 3; $round++) {
                        $seed = 230100 + $round + (int) (crc32($name.$kind) & 0xFFFF);
                        $index = count($evidence['measurements']);
                        $evidence['measurements'][$index] = ['method' => $name, 'workload' => $kind, 'round' => $round, 'seed' => $seed, 'fixture_sha256' => $fixtureHash, 'pairs' => [], 'warmup' => []];
                        $record = &$evidence['measurements'][$index];
                        $stats = self::measurePair($class, $call, $seed, $record);
                        $record['stats'] = $stats;
                        unset($record);
                        $worst[$kind.'_mean'] = min($worst[$kind.'_mean'], $stats['mean_speedup']);
                        $worst[$kind.'_p99'] = min($worst[$kind.'_p99'], $stats['p99_speedup']);
                    }
                }
                $class::resetRustFfi();
                $qualified = min($worst) >= 1.5;
                $evidence['summary'][$name] = $worst + ['paired_screen_qualified' => $qualified, 'production_default' => $class::usesRustFfi() ? 'Rust' : 'PHP', 'production_p99_qualified' => false];
                fwrite(STDERR, $name.' '.json_encode($evidence['summary'][$name], JSON_THROW_ON_ERROR).PHP_EOL);
                self::assertFalse($class::usesRustFfi(), 'Batch means do not establish individual-call p99 qualification');
            }
        } catch (Throwable $failure) {
            $evidence['failures'][] = ['class' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            $evidence['finished_at_utc'] = gmdate('c');
            self::assertNotFalse(file_put_contents($artifact, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)));
            fwrite(STDERR, 'Wave23.1 evidence preserved: '.$artifact.PHP_EOL);
        }
    }

    /** @return array<string, array{class-string, Closure, Closure, string}> */
    private static function benchmarkWorkloads(): array
    {
        $workloads = [];
        $inventory = new GdprDataInventory;
        foreach (['retainedRecords', 'erasableTables', 'anonymizedTables', 'exportTables', 'essentialCookies'] as $method) {
            $workloads['GdprDataInventory::'.$method] = [GdprDataInventory::class, static fn (): array => $inventory->$method(), static fn (): string => json_encode($inventory->$method(), JSON_THROW_ON_ERROR), hash('sha256', serialize(self::inventoryFixture($method)))];
        }
        $commonAccount = ['id' => 42, 'username' => 'alice', 'email' => 'alice@example.com', 'password' => 'secret', 'api_token' => 'secret', 'created_at' => '2026-10-24 10:00:00', 'theme' => 'dark'];
        $uncommonAccount = $commonAccount;
        $uncommonRow = [];
        for ($i = 0; $i < 200; $i++) {
            $uncommonAccount['preference_'.$i] = ['enabled' => true, 'value' => str_repeat('x', 20)];
            $uncommonRow['field_'.$i] = $i % 5 === 0 ? null : str_repeat('value', 20);
        }
        $definitions = [
            ['GdprExportService::formatExportPath', GdprExportService::class, 'formatExportPath', [42, '20261024090000', 'Ab12Cd34Ef56'], [PHP_INT_MAX, '20380119031407', 'Zz09Yy08Xx07']],
            ['GdprExportService::redactAccountAttributes', GdprExportService::class, 'redactAccountAttributes', [$commonAccount], [$uncommonAccount]],
            ['GdprExportService::redactRowData', GdprExportService::class, 'redactRowData', [['id' => 7, 'credential_id' => 'opaque', 'data' => null, 'created_at' => '2026-10-24'], ['credential_id', 'data']], [$uncommonRow, array_merge(array_keys($uncommonRow), ['missing'])]],
            ['GdprNotificationService::normalizeRecipientEmail', GdprNotificationService::class, 'normalizeRecipientEmail', [' alice@example.com '], [str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.example.com']],
            ['GdprNotificationService::shouldSendAdminCopy', GdprNotificationService::class, 'shouldSendAdminCopy', ['erasure', 'submitted'], ['rectification', 'rejected']],
            ['GdprRequest::evaluateDownloadableExport', GdprRequest::class, 'evaluateDownloadableExport', ['export', 'completed', 'gdpr/exports/user-42-export.json', 1_800_000_000, 1_790_000_000], ['export', 'completed', '', 1_790_000_000, 1_790_000_000]],
            ['GdprRequestStatusMail::formatLabel', GdprRequestStatusMail::class, 'formatLabel', ['export'], ['data_portability_and_rectification_request']],
            ['GdprRequestStatusMail::formatRequester', GdprRequestStatusMail::class, 'formatRequester', ['alice', 'alice@example.com'], [null, null]],
            ['GdprRequestStatusMail::resolveMailContent', GdprRequestStatusMail::class, 'resolveMailContent', ['Export', 'submitted', false, '/privacy-center'], ['Erasure', 'erasure_completed', false, null]],
        ];
        foreach ($definitions as [$name, $class, $method, $commonArgs, $uncommonArgs]) {
            $workloads[$name] = [$class, static fn (): mixed => $class::$method(...$commonArgs), static fn (): mixed => $class::$method(...$uncommonArgs), hash('sha256', serialize([$commonArgs, $uncommonArgs]))];
        }

        return $workloads;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $record
     * @return array<string, float>
     */
    private static function measurePair(string $class, Closure $call, int $seed, array &$record): array
    {
        $times = ['php' => [], 'rust' => []];
        foreach (['php', 'rust'] as $implementation) {
            $implementation === 'rust' ? $class::enableRustFfi() : $class::disableRustFfi();
            for ($batch = 0; $batch < 5; $batch++) {
                $start = hrtime(true);
                for ($i = 0; $i < 200; $i++) {
                    $call();
                }
                $record['warmup'][] = ['implementation' => $implementation, 'batch' => $batch, 'elapsed_ns' => hrtime(true) - $start];
            }
        }
        $randomizer = new Randomizer(new Mt19937($seed));
        for ($sample = 0; $sample < 41; $sample++) {
            $order = $randomizer->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
            $record['pairs'][$sample] = ['sample' => $sample, 'order' => $order, 'elapsed_ns' => []];
            foreach ($order as $implementation) {
                $implementation === 'rust' ? $class::enableRustFfi() : $class::disableRustFfi();
                $start = hrtime(true);
                for ($i = 0; $i < 200; $i++) {
                    $call();
                }
                $elapsed = hrtime(true) - $start;
                $record['pairs'][$sample]['elapsed_ns'][$implementation] = $elapsed;
                $times[$implementation][] = $elapsed / 200;
            }
        }
        $stats = [];
        foreach ($times as $implementation => $values) {
            sort($values);
            $stats[$implementation.'_mean_ns'] = array_sum($values) / count($values);
            $stats[$implementation.'_p99_batch_mean_ns'] = $values[(int) ceil(0.99 * count($values)) - 1];
        }
        $stats['mean_speedup'] = $stats['php_mean_ns'] / $stats['rust_mean_ns'];
        $stats['p99_speedup'] = $stats['php_p99_batch_mean_ns'] / $stats['rust_p99_batch_mean_ns'];

        return $stats;
    }

    /** @return array<string, mixed> */
    private static function benchmarkMetadata(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [__FILE__, $root.'/composer.lock', $root.'/vendor/composer/installed.php', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/rust/pipeline-ffi/Cargo.toml', $root.'/app/Support/RustPipelineLibrary.php'];
        foreach (self::CLASSES as $class) {
            $source = (new \ReflectionClass($class))->getFileName();
            if ($source !== false) {
                $files[] = $source;
            }
        }
        foreach (glob($root.'/rust/pipeline-ffi/src/*.rs') ?: [] as $file) {
            $files[] = $file;
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix] as $library) {
            if (is_file($library)) {
                $files[] = $library;
            }
        }
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file] = is_file($file) ? hash_file('sha256', $file) : 'absent';
        }
        $caps = [];
        foreach (['/sys/fs/cgroup/cpu.max', '/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/cpu.stat'] as $file) {
            $caps[$file] = is_readable($file) ? file_get_contents($file) : 'unavailable';
        }

        return [
            'started_at_utc' => gmdate('c'), 'cwd' => getcwd(), 'os' => php_uname(), 'architecture' => php_uname('m'),
            'cpu' => PHP_OS_FAMILY === 'Darwin' ? trim((string) shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null')) : (is_readable('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : 'unavailable'),
            'php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'phpunit' => Version::id(),
            'ffi_extension' => phpversion('FFI'), 'ffi_enable' => ini_get('ffi.enable'), 'memory_limit' => ini_get('memory_limit'),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'load_average' => sys_getloadavg(),
            'rustc' => trim((string) shell_exec('rustc --version 2>&1')), 'cargo' => trim((string) shell_exec('cargo --version 2>&1')),
            'build_profile' => 'release; verify preserved dylib/so SHA256 and Cargo manifest profile settings',
            'git_head' => trim((string) shell_exec('git rev-parse HEAD 2>&1')), 'git_status' => (string) shell_exec('git status --short 2>&1'),
            'source_sha256' => $hashes, 'limits' => $caps, 'host_ulimits' => (string) shell_exec('ulimit -a 2>&1'),
        ];
    }
}
