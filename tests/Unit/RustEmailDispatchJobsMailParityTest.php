<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SendAccountChangedEmail;
use App\Jobs\SendAccountWillExpireEmail;
use App\Jobs\SendContactUsEmail;
use App\Mail\AccountChange;
use App\Mail\AccountDeleted;
use App\Mail\AccountExpired;
use App\Mail\AccountWillExpire;
use App\Mail\ContactUs;
use App\Mail\ForgottenPassword;
use App\Mail\GdprRequestStatusMail;
use App\Mail\IncidentDetected;
use App\Mail\InvitationMail;
use App\Mail\NewAccountCreatedEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Runner\Version;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Wave 26.1: literal/byte parity, real callers, ABI safety and paired CPU measurements.
 * Build: CARGO_TARGET_DIR=/tmp/cargo-w26-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 * Run: NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w26-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustEmailDispatchJobsMailParityTest.php
 * Raw randomized samples, failures and source/build identities are retained in system temp.
 * Apple M5 / PHP 8.5.12 / rustc 1.99.1 release screen (2026-10-24):
 * worst-round helper common mean / uncommon individual-call p99 speedups:
 * branding 0.13x/0.08x; ContactUs 0.11x/0.03x; account changed job 0.15x/0.23x;
 * expiry job 0.10x/0.05x; contact job 0.09x/0.01x. All toggles remain false.
 * These local CPU results do not establish queue or transport performance.
 * Job caller measurements use MailFake at the transport boundary and explicitly build
 * the captured mailable; they exclude queue persistence, Blade rendering and delivery.
 */
class RustEmailDispatchJobsMailParityTest extends TestCase
{
    private const string CDEF = 'int32_t nntmux_w26_email_dispatch_jobs_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    /** @var list<class-string> */
    private const array CLASSES = [ContactUs::class, SendAccountChangedEmail::class, SendAccountWillExpireEmail::class, SendContactUsEmail::class];

    /** @var list<class-string<Mailable>> */
    private const array BRANDED_CLASSES = [ContactUs::class, AccountDeleted::class, AccountExpired::class, AccountChange::class, AccountWillExpire::class, ForgottenPassword::class, NewAccountCreatedEmail::class, WelcomeEmail::class, GdprRequestStatusMail::class, IncidentDetected::class, InvitationMail::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetNativeState();
        config(['app.name' => 'NNTmux', 'mail.from.address' => 'noreply@example.test', 'mail.brand.subject_prefix' => '[NNTmux] ']);
    }

    protected function tearDown(): void
    {
        $this->resetNativeState();
        parent::tearDown();
    }

    private function resetNativeState(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        foreach (self::BRANDED_CLASSES as $class) {
            $class::setUseBrandedSubjectRustFfi(false);
            (new ReflectionProperty($class, 'brandedSubjectFfi'))->setValue(null, null);
        }
    }

    private function native(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('wave26-email-dispatch-jobs-mail-test', self::CDEF, $path === false || $path === '' ? null : $path);
        self::assertNotNull($ffi, 'Native parity requires the release library and NNTMUX_PIPELINE_FFI_LIB.');

        return $ffi;
    }

    /** @param list<?string> $parts */
    private static function frames(array $parts): string
    {
        $result = '';
        foreach ($parts as $part) {
            $result .= $part === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($part)).$part;
        }

        return $result;
    }

    private static function abi(FFI $ffi, mixed ...$args): int
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w26_email_dispatch_jobs_mail(...$args);
    }

    /** @return list<array{int, list<?string>, list<?string>}> */
    private static function wireFixtures(): array
    {
        return [
            [0, ['alice', '<Body>', 'Site'], ['Contact form submitted', 'New contact form submission from alice.', '<Body>', 'alice', 'Site']],
            [1, ['Subject', '[Site] ', '1'], ['[Site] Subject']],
            [1, ['Subject', '[Site] ', '0'], ['Subject']],
            [2, ['alice@example.test'], ['alice@example.test']],
            [2, [null], [null]],
            [3, [null, '-9223372036854775808'], [null, '-9223372036854775808']],
            [3, ['alice@example.test', '9223372036854775807'], ['alice@example.test', '9223372036854775807']],
            [4, ['to@example.test', 'from@example.test', "body\0\xff"], ['to@example.test', 'from@example.test', "body\0\xff"]],
        ];
    }

    public function test_php_helpers_preserve_literal_copy_and_dispatch_values(): void
    {
        self::assertSame([
            'subject' => 'Contact form submitted',
            'preheader' => 'New contact form submission from alice@example.test.',
            'viewData' => ['mailBody' => "Hello\n<&>", 'mailFrom' => 'alice@example.test', 'site' => 'NNTmux', 'preheader' => 'New contact form submission from alice@example.test.'],
        ], ContactUs::formatMailDataPhp('alice@example.test', "Hello\n<&>", 'NNTmux'));
        self::assertSame('[Site] Hello', ContactUs::formatBrandedSubjectPhp('Hello', '[Site] '));
        self::assertSame('Hello', ContactUs::formatBrandedSubjectPhp('Hello', '[Site] ', false));
        self::assertSame(['recipient' => null], SendAccountChangedEmail::normalizeDispatchPhp(null));
        self::assertSame(['recipient' => '0', 'days' => PHP_INT_MIN], SendAccountWillExpireEmail::normalizeDispatchPhp('0', PHP_INT_MIN));
        self::assertSame(['recipient' => 'to@example.test', 'mailFrom' => 'from@example.test', 'mailBody' => 'Body'], SendContactUsEmail::normalizeDispatchPhp('to@example.test', 'from@example.test', 'Body'));
    }

    public function test_trait_toggle_is_independent_of_existing_mailable_toggle(): void
    {
        self::assertFalse(AccountDeleted::usesRustFfi());
        self::assertFalse(AccountDeleted::usesBrandedSubjectRustFfi());
        try {
            AccountDeleted::setUseBrandedSubjectRustFfi(true);
            self::assertTrue(AccountDeleted::usesBrandedSubjectRustFfi());
            self::assertFalse(AccountDeleted::usesRustFfi());
            self::assertFalse(ContactUs::usesBrandedSubjectRustFfi());
        } finally {
            AccountDeleted::setUseBrandedSubjectRustFfi(false);
        }
    }

    public function test_native_fixtures_bytes_and_all_toggle_dispatches(): void
    {
        $ffi = $this->native();
        foreach (self::wireFixtures() as [$operation, $args, $fields]) {
            $input = self::frames($args);
            $expected = self::frames($fields);
            $out = $ffi->new('char[1024]');
            self::assertSame(strlen($expected), self::abi($ffi, $operation, $input, strlen($input), $out, 1024));
            self::assertSame($expected, FFI::string($out, strlen($expected)));
        }
        // Includes outputs larger than the shared initial scratch allocation.
        foreach (['', '0', "é漢字<&>\"'", "a\0b\xff\n", str_repeat('long', 80_000)] as $value) {
            foreach ([false, true] as $enabled) {
                ContactUs::setUseRustFfi($enabled);
                ContactUs::setUseBrandedSubjectRustFfi($enabled);
                $expected = ContactUs::formatMailDataPhp($value, $value, $value);
                self::assertSame($expected, ContactUs::formatMailDataNative($value, $value, $value));
                self::assertSame($expected, ContactUs::formatMailData($value, $value, $value));
                foreach ([false, true] as $prefix) {
                    self::assertSame(($prefix ? $value : '').$value, ContactUs::formatBrandedSubjectNative($value, $value, $prefix));
                    self::assertSame(($prefix ? $value : '').$value, ContactUs::formatBrandedSubject($value, $value, $prefix));
                }
                foreach ([null, $value] as $recipient) {
                    SendAccountChangedEmail::setUseRustFfi($enabled);
                    self::assertSame(['recipient' => $recipient], SendAccountChangedEmail::normalizeDispatchNative($recipient));
                    self::assertSame(['recipient' => $recipient], SendAccountChangedEmail::normalizeDispatch($recipient));
                    foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $days) {
                        SendAccountWillExpireEmail::setUseRustFfi($enabled);
                        self::assertSame(['recipient' => $recipient, 'days' => $days], SendAccountWillExpireEmail::normalizeDispatchNative($recipient, $days));
                        self::assertSame(['recipient' => $recipient, 'days' => $days], SendAccountWillExpireEmail::normalizeDispatch($recipient, $days));
                    }
                }
                SendContactUsEmail::setUseRustFfi($enabled);
                self::assertSame(['recipient' => $value, 'mailFrom' => $value, 'mailBody' => $value], SendContactUsEmail::normalizeDispatchNative($value, $value, $value));
                self::assertSame(['recipient' => $value, 'mailFrom' => $value, 'mailBody' => $value], SendContactUsEmail::normalizeDispatch($value, $value, $value));
            }
        }
        foreach (self::CLASSES as $class) {
            self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue());
        }
        self::assertInstanceOf(FFI::class, (new ReflectionProperty(ContactUs::class, 'brandedSubjectFfi'))->getValue());
    }

    public function test_contact_build_preserves_addresses_context_and_public_overrides(): void
    {
        $this->native();
        foreach ([false, true] as $enabled) {
            ContactUs::setUseRustFfi($enabled);
            foreach ([false, true] as $branded) {
                ContactUs::setUseBrandedSubjectRustFfi($branded);
                $mail = new ContactUs('admin@example.test', 'alice@example.test', '<Hello>');
                self::assertSame('New contact form submission from alice@example.test.', $mail->preheader);
                $mail->build();
                self::assertSame('[NNTmux] Contact form submitted', $mail->subject);
                self::assertSame('admin@example.test', $mail->from[0]['address']);
                self::assertSame('alice@example.test', $mail->replyTo[0]['address']);
                self::assertSame('emails.markdown.contactUs', $mail->markdown);
                self::assertSame(['mailBody' => '<Hello>', 'mailFrom' => 'alice@example.test', 'site' => 'NNTmux', 'preheader' => 'New contact form submission from alice@example.test.'], $mail->viewData);
                $mail->mailBody = 'Updated body';
                $mail->mailFrom = 'other@example.test';
                $mail->mailTo = 'updated@example.test';
                $mail->site = 'Other';
                $mail->preheader = null;
                config(['mail.brand.subject_prefix' => '']);
                $mail->build();
                self::assertSame('Contact form submitted', $mail->subject);
                self::assertSame(['admin@example.test', 'updated@example.test'], array_column($mail->from, 'address'));
                self::assertSame(['alice@example.test', 'other@example.test'], array_column($mail->replyTo, 'address'));
                self::assertSame(['mailBody' => 'Updated body', 'mailFrom' => 'other@example.test', 'site' => 'Other', 'preheader' => null], $mail->viewData);
                config(['mail.brand.subject_prefix' => '[NNTmux] ']);
            }
            $mail = new ContactUs(123, false, new class
            {
                public function __toString(): string
                {
                    return 'stringable';
                }
            });
            self::assertSame('123', $mail->mailTo);
            self::assertSame('', $mail->mailFrom);
            self::assertSame('stringable', $mail->mailBody);
        }
    }

    private static function user(): User
    {
        $user = new User;
        $user->username = 'alice';
        $user->email = 'alice@example.test';
        $role = new Role;
        $role->name = 'Gold';
        $user->setRelation('role', $role);

        return $user;
    }

    public function test_real_jobs_send_once_to_current_recipient_with_original_mail_arguments(): void
    {
        $this->native();
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            $user = self::user();
            $changed = new SendAccountChangedEmail($user);
            $expiring = new SendAccountWillExpireEmail($user, -2);
            // The recipient is read by handle, not frozen at job construction.
            $user->email = 'updated@example.test';
            Mail::fake();
            $changed->handle();
            $expiring->handle();
            (new SendContactUsEmail('visitor@example.test', 'admin@example.test', "Hello\n<&>"))->handle();
            Mail::assertSentCount(3);
            Mail::assertNothingQueued();
            Mail::assertSent(AccountChange::class, static function (AccountChange $mail): bool {
                $mail->build();

                return $mail->hasTo('updated@example.test') && $mail->username === 'alice' && $mail->account === 'Gold'
                    && $mail->subject === '[NNTmux] Account level changed';
            });
            Mail::assertSent(AccountWillExpire::class, static function (AccountWillExpire $mail): bool {
                $mail->build();

                return $mail->hasTo('updated@example.test') && $mail->username === 'alice' && $mail->days === -2 && ! $mail->hasPendingRole
                    && $mail->preheader === 'Your Gold role expires in -2 day(s).';
            });
            Mail::assertSent(ContactUs::class, static function (ContactUs $mail): bool {
                $mail->build();

                return $mail->hasTo('admin@example.test') && $mail->from[0]['address'] === 'admin@example.test'
                    && $mail->replyTo[0]['address'] === 'visitor@example.test' && $mail->viewData['mailBody'] === "Hello\n<&>";
            });
        }
    }

    public function test_trait_names_load_safely_in_all_existing_mailables_on_php_85(): void
    {
        $this->native();
        foreach (self::BRANDED_CLASSES as $class) {
            self::assertFalse($class::usesBrandedSubjectRustFfi());
            $class::setUseBrandedSubjectRustFfi(true);
            self::assertSame('[Brand] Subject', $class::formatBrandedSubject('Subject', '[Brand] '));
            self::assertSame('Subject', $class::formatBrandedSubject('Subject', '[Brand] ', false));
            self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'brandedSubjectFfi'))->getValue());
            if (method_exists($class, 'usesRustFfi')) {
                self::assertFalse($class::usesRustFfi());
            }
        }
    }

    public function test_helpers_fall_back_when_ffi_is_absent_or_symbol_throws(): void
    {
        foreach ([false, FFI::cdef('')] as $handle) {
            foreach (self::CLASSES as $class) {
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $handle);
                $class::setUseRustFfi(true);
            }
            (new ReflectionProperty(ContactUs::class, 'brandedSubjectFfi'))->setValue(null, $handle);
            ContactUs::setUseBrandedSubjectRustFfi(true);
            $this->assertFallbackResults();
        }
    }

    private function assertFallbackResults(): void
    {
        self::assertSame(ContactUs::formatMailDataPhp('u', 'body', 'site'), ContactUs::formatMailData('u', 'body', 'site'));
        self::assertSame(ContactUs::formatMailDataPhp('u', 'body', 'site'), ContactUs::formatMailDataNative('u', 'body', 'site'));
        self::assertSame('[Site] Subject', ContactUs::formatBrandedSubject('Subject', '[Site] '));
        self::assertSame('[Site] Subject', ContactUs::formatBrandedSubjectNative('Subject', '[Site] '));
        self::assertSame(['recipient' => null], SendAccountChangedEmail::normalizeDispatch(null));
        self::assertSame(['recipient' => null], SendAccountChangedEmail::normalizeDispatchNative(null));
        self::assertSame(['recipient' => 'u', 'days' => PHP_INT_MAX], SendAccountWillExpireEmail::normalizeDispatch('u', PHP_INT_MAX));
        self::assertSame(['recipient' => 'u', 'days' => PHP_INT_MAX], SendAccountWillExpireEmail::normalizeDispatchNative('u', PHP_INT_MAX));
        self::assertSame(['recipient' => 'to', 'mailFrom' => 'from', 'mailBody' => 'body'], SendContactUsEmail::normalizeDispatch('to', 'from', 'body'));
        self::assertSame(['recipient' => 'to', 'mailFrom' => 'from', 'mailBody' => 'body'], SendContactUsEmail::normalizeDispatchNative('to', 'from', 'body'));
    }

    public function test_missing_library_uses_php_fallback(): void
    {
        $original = getenv('NNTMUX_PIPELINE_FFI_LIB');
        try {
            putenv('NNTMUX_PIPELINE_FFI_LIB=/nonexistent/wave26-1/libnntmux_pipeline.dylib');
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
            }
            ContactUs::setUseBrandedSubjectRustFfi(true);
            $this->assertFallbackResults();
            foreach (self::CLASSES as $class) {
                self::assertFalse((new ReflectionProperty($class, 'ffi'))->getValue());
            }
            self::assertFalse((new ReflectionProperty(ContactUs::class, 'brandedSubjectFfi'))->getValue());
        } finally {
            $original === false ? putenv('NNTMUX_PIPELINE_FFI_LIB') : putenv('NNTMUX_PIPELINE_FFI_LIB='.$original);
        }
    }

    public function test_ffi_disabled_in_a_fresh_php_process_falls_back_cleanly(): void
    {
        $script = <<<'PHP'
require getcwd().'/tests/bootstrap.php';
use App\Mail\ContactUs;
use App\Jobs\SendAccountChangedEmail;
use App\Jobs\SendAccountWillExpireEmail;
use App\Jobs\SendContactUsEmail;
ContactUs::setUseRustFfi(true);
ContactUs::setUseBrandedSubjectRustFfi(true);
SendAccountChangedEmail::setUseRustFfi(true);
SendAccountWillExpireEmail::setUseRustFfi(true);
SendContactUsEmail::setUseRustFfi(true);
$actual = [
    ContactUs::formatMailData('u', 'body', 'site'),
    ContactUs::formatBrandedSubject('Subject', '[Site] '),
    SendAccountChangedEmail::normalizeDispatch(null),
    SendAccountWillExpireEmail::normalizeDispatch('u', PHP_INT_MIN),
    SendContactUsEmail::normalizeDispatch('to', 'from', 'body'),
];
$expected = [
    ['subject' => 'Contact form submitted', 'preheader' => 'New contact form submission from u.', 'viewData' => ['mailBody' => 'body', 'mailFrom' => 'u', 'site' => 'site', 'preheader' => 'New contact form submission from u.']],
    '[Site] Subject', ['recipient' => null], ['recipient' => 'u', 'days' => PHP_INT_MIN],
    ['recipient' => 'to', 'mailFrom' => 'from', 'mailBody' => 'body'],
];
exit($actual === $expected ? 0 : 1);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=false', '-r', $script], dirname(__DIR__, 2));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_direct_abi_errors_capacity_canaries_and_aliasing(): void
    {
        $ffi = $this->native();
        $out = $ffi->new('char[1024]');
        foreach (self::wireFixtures() as [$operation, $args, $fields]) {
            $input = self::frames($args);
            $expected = self::frames($fields);
            $needed = strlen($expected);
            FFI::memset($out, 90, 1024);
            foreach ([0, 1, $needed - 1] as $capacity) {
                self::assertSame(-$needed, self::abi($ffi, $operation, $input, strlen($input), $out, $capacity));
                self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
            }
            foreach ([[null, 0, $out, 1024], [null, 8, $out, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 10], [$input, -1, $out, 1024], [$input, strlen($input), $out, -1], [$ffi->cast('char *', -8), 16, $out, 1024], [$input, strlen($input), $ffi->cast('char *', -8), 16]] as $regions) {
                self::assertSame(-1, self::abi($ffi, $operation, ...$regions));
                self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
            }
            foreach (['', substr($input, 0, -1), $input.'x', self::frames([...$args, 'extra']), pack('V', 100).'short', "\x01\x00\x00"] as $bad) {
                self::assertSame(-1, self::abi($ffi, $operation, $bad, strlen($bad), $out, 1024));
                self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
            }
            self::assertSame($needed, self::abi($ffi, $operation, $input, strlen($input), FFI::addr($out[1]), $needed));
            self::assertSame($expected, FFI::string(FFI::addr($out[1]), $needed));
            self::assertSame('Z', $out[0]);
            self::assertSame('Z', $out[$needed + 1]);
            foreach ([[0, 0], [0, 1], [1, 0], [7, 3]] as [$sourceOffset, $targetOffset]) {
                FFI::memset($out, 81, 1024);
                FFI::memcpy(FFI::addr($out[$sourceOffset]), $input, strlen($input));
                $before = FFI::string($out, 1024);
                self::assertSame($needed, self::abi($ffi, $operation, FFI::addr($out[$sourceOffset]), strlen($input), FFI::addr($out[$targetOffset]), $needed));
                self::assertSame(substr($before, 0, $targetOffset).$expected.substr($before, $targetOffset + $needed), FFI::string($out, 1024));
            }
        }
        foreach ([[99, []], [0, [null, '', '']], [1, ['s', 'p', 'true']], [3, [null, null]], [3, [null, '+1']], [3, [null, '9223372036854775808']], [4, ['', null, '']]] as [$operation, $args]) {
            $input = self::frames($args);
            FFI::memset($out, 90, 1024);
            self::assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), $out, 1024));
            self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
        }
    }

    private static function setMode(string $class, bool $enabled): void
    {
        if ($class === 'branding') {
            ContactUs::setUseBrandedSubjectRustFfi($enabled);
        } else {
            $class::setUseRustFfi($enabled);
        }
    }

    /** @return array{mixed, mixed, array<string, mixed>, mixed} */
    private static function builtMail(ContactUs|AccountChange|AccountWillExpire $mail): array
    {
        $mail->build();

        return [$mail->subject, $mail->markdown, $mail->viewData, $mail->to];
    }

    public function test_paired_randomized_benchmarks(): void
    {
        $ffi = $this->native();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w26-1-mail-');
        self::assertNotFalse($artifact);
        $evidence = [
            'protocol' => [
                'rounds' => 3, 'paired_samples_per_round' => 1001, 'calls_per_sample' => 1, 'warmup_calls_per_implementation' => 101,
                'order' => 'seeded Mt19937 randomized PHP/Rust pairs; identical fixture per pair',
                'p99' => 'nearest-rank individual-call p99 per round (1001 samples), including callback/timer overhead',
                'uncertainty' => 'retain all three rounds and ratio ranges; local CPU screen, no production p99 or confidence interval claim',
                'gate' => 'each helper/caller common mean and uncommon p99 must be >=1.5x in every round; otherwise source default must be false',
                'scope' => 'PHP dispatch, framing, FFI, allocation/decoding and result assembly; caller adds construction/handle/build; job transport is MailFake; no queue/Blade/network',
                'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w26-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w26-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustEmailDispatchJobsMailParityTest.php',
            ],
            'metadata' => self::benchmarkMetadata(), 'measurements' => [], 'summary' => [], 'failures' => [],
        ];
        fwrite(STDERR, 'Wave26.1 raw benchmark artifact: '.$artifact.PHP_EOL);
        try {
            foreach (['branding', ...self::CLASSES] as $class) {
                $qualified = true;
                foreach (['common', 'uncommon'] as $kind) {
                    $value = $kind === 'common' ? 'alice@example.test' : str_repeat("é漢字\0\xff", 500);
                    $site = $kind === 'common' ? 'NNTmux' : str_repeat('Indexer é ', 200);
                    $body = $kind === 'common' ? 'Contact message' : str_repeat("<body>\0\xff", 8192);
                    $days = $kind === 'common' ? 7 : PHP_INT_MIN;
                    $user = self::user();
                    // Valid addresses for real callers; helper fixtures also exercise arbitrary bytes.
                    $user->username = $value;
                    config(['app.name' => $site, 'mail.brand.subject_prefix' => '['.$site.'] ']);
                    $operation = match ($class) {
                        ContactUs::class => 0, 'branding' => 1, SendAccountChangedEmail::class => 2,
                        SendAccountWillExpireEmail::class => 3, SendContactUsEmail::class => 4,
                    };
                    $args = match ($operation) {
                        0 => [$value, $body, $site], 1 => [$value, '['.$site.'] ', '1'],
                        2 => [$value], 3 => [$value, (string) $days], 4 => [$value, $value, $body],
                    };
                    $helper = match ($operation) {
                        0 => static fn (): array => ContactUs::formatMailData($value, $body, $site),
                        1 => static fn (): string => ContactUs::formatBrandedSubject($value, '['.$site.'] '),
                        2 => static fn (): array => SendAccountChangedEmail::normalizeDispatch($value),
                        3 => static fn (): array => SendAccountWillExpireEmail::normalizeDispatch($value, $days),
                        4 => static fn (): array => SendContactUsEmail::normalizeDispatch($value, $value, $body),
                    };
                    $caller = static function () use ($operation, $user, $days, $body): array {
                        if ($operation <= 1) {
                            return self::builtMail(new ContactUs('admin@example.test', 'visitor@example.test', $body));
                        }
                        $fake = Mail::fake();
                        $job = match ($operation) {
                            2 => new SendAccountChangedEmail($user),
                            3 => new SendAccountWillExpireEmail($user, $days),
                            4 => new SendContactUsEmail('visitor@example.test', 'admin@example.test', $body),
                        };
                        $job->handle();
                        $mailClass = match ($operation) {
                            2 => AccountChange::class, 3 => AccountWillExpire::class, 4 => ContactUs::class
                        };
                        $mail = $fake->sent($mailClass)->sole();

                        return self::builtMail($mail);
                    };
                    foreach (['helper' => $helper, 'caller' => $caller] as $method => $call) {
                        $name = $class.'::'.$method.'/'.$kind;
                        self::setMode($class, false);
                        $expected = $call();
                        self::setMode($class, true);
                        self::assertSame($expected, $call(), $name.' parity before timing');
                        $owner = $class === 'branding' ? ContactUs::class : $class;
                        $property = $class === 'branding' ? 'brandedSubjectFfi' : 'ffi';
                        self::assertInstanceOf(FFI::class, (new ReflectionProperty($owner, $property))->getValue());
                        // Prove native success for this workload rather than timing an unavailable library.
                        $input = self::frames($args);
                        $capacity = strlen($input) * 3 + 1024;
                        $out = $ffi->new('char['.$capacity.']');
                        self::assertGreaterThan(0, self::abi($ffi, $operation, $input, strlen($input), $out, $capacity));
                        $ratios = [];
                        for ($round = 0; $round < 3; $round++) {
                            $seed = 260100 + $round + (int) (crc32($name) & 0xFFFF);
                            $index = count($evidence['measurements']);
                            $evidence['measurements'][$index] = ['name' => $name, 'round' => $round, 'seed' => $seed, 'fixture_sha256' => hash('sha256', serialize($method === 'helper' ? $args : [$operation, $user->getAttributes(), 'Gold', $days, $body, $site, 'visitor@example.test', 'admin@example.test'])), 'warmup' => [], 'pairs' => []];
                            $record = &$evidence['measurements'][$index];
                            $stats = self::measurePair($class, $call, $seed, $record);
                            $record['stats'] = $stats;
                            unset($record);
                            $ratios[] = $stats;
                        }
                        $means = array_column($ratios, 'mean_speedup');
                        $tails = array_column($ratios, 'p99_speedup');
                        $passed = min($means) >= 1.5 && min($tails) >= 1.5;
                        $qualified = $qualified && $passed;
                        $evidence['summary'][$name] = ['mean_speedup_range' => [min($means), max($means)], 'p99_speedup_range' => [min($tails), max($tails)], 'qualified' => $passed];
                        fwrite(STDERR, $name.' '.json_encode($evidence['summary'][$name], JSON_THROW_ON_ERROR).PHP_EOL);
                    }
                }
                self::setMode($class, false);
                $owner = $class === 'branding' ? ContactUs::class : $class;
                $property = $class === 'branding' ? 'useBrandedSubjectRustFfi' : 'useRustFfi';
                $default = (new ReflectionProperty($owner, $property))->getDefaultValue();
                $evidence['class_defaults'][$class] = ['qualified' => $qualified, 'source_default' => $default];
                if (! $qualified) {
                    self::assertFalse($default, $class.' cannot default to Rust without qualifying evidence');
                }
            }
        } catch (Throwable $failure) {
            $evidence['failures'][] = ['class' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            foreach (['branding', ...self::CLASSES] as $class) {
                self::setMode($class, false);
            }
            $evidence['finished_at_utc'] = gmdate('c');
            self::assertNotFalse(file_put_contents($artifact, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)));
            fwrite(STDERR, 'Wave26.1 evidence preserved: '.$artifact.PHP_EOL);
        }
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
            self::setMode($class, $implementation === 'rust');
            $start = hrtime(true);
            for ($i = 0; $i < 101; $i++) {
                $call();
            }
            $record['warmup'][] = ['implementation' => $implementation, 'calls' => 101, 'elapsed_ns' => hrtime(true) - $start];
        }
        $randomizer = new Randomizer(new Mt19937($seed));
        for ($sample = 0; $sample < 1001; $sample++) {
            $order = $randomizer->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
            $record['pairs'][$sample] = ['order' => $order, 'elapsed_ns' => []];
            foreach ($order as $implementation) {
                self::setMode($class, $implementation === 'rust');
                $start = hrtime(true);
                $call();
                $elapsed = hrtime(true) - $start;
                $record['pairs'][$sample]['elapsed_ns'][$implementation] = $elapsed;
                $times[$implementation][] = $elapsed;
            }
        }
        self::setMode($class, false);
        $stats = [];
        foreach ($times as $implementation => $values) {
            sort($values);
            $stats[$implementation.'_mean_ns'] = array_sum($values) / count($values);
            $stats[$implementation.'_p99_ns'] = (float) $values[(int) ceil(0.99 * count($values)) - 1];
        }
        $stats['mean_speedup'] = $stats['php_mean_ns'] / $stats['rust_mean_ns'];
        $stats['p99_speedup'] = $stats['php_p99_ns'] / $stats['rust_p99_ns'];

        return $stats;
    }

    /** @return array<string, mixed> */
    private static function benchmarkMetadata(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [__FILE__, (string) getenv('NNTMUX_PIPELINE_FFI_LIB'), $root.'/composer.lock', $root.'/vendor/composer/installed.php', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/rust/pipeline-ffi/Cargo.toml', $root.'/app/Support/RustPipelineLibrary.php', $root.'/rust/pipeline-ffi/src/cleaning.rs', $root.'/app/Mail/Concerns/HasBrandedSubject.php'];
        foreach (self::CLASSES as $class) {
            $source = (new \ReflectionClass($class))->getFileName();
            if ($source !== false) {
                $files[] = $source;
            }
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix] as $library) {
            $files[] = $library;
        }
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file] = is_file($file) ? hash_file('sha256', $file) : 'absent';
        }

        return [
            'started_at_utc' => gmdate('c'), 'os' => php_uname(), 'architecture' => php_uname('m'),
            'cpu' => PHP_OS_FAMILY === 'Darwin' ? trim((string) shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null')) : (is_readable('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : 'unavailable'),
            'php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'phpunit' => Version::id(),
            'ffi_extension' => phpversion('FFI'), 'ffi_enable' => ini_get('ffi.enable'), 'memory_limit' => ini_get('memory_limit'),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'load_average' => sys_getloadavg(),
            'rustc' => trim((string) shell_exec('rustc --version 2>&1')), 'cargo' => trim((string) shell_exec('cargo --version 2>&1')),
            'build_profile' => 'release (opt-level 3, lto, codegen-units 1)',
            'git_head' => trim((string) shell_exec('git rev-parse HEAD 2>&1')), 'git_status' => (string) shell_exec('git status --short 2>&1'),
            'source_sha256' => $hashes, 'host_ulimits' => (string) shell_exec('ulimit -a 2>&1'),
        ];
    }
}
