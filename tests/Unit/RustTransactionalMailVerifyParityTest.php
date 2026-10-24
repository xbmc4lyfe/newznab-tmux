<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Mail\AccountChange;
use App\Mail\AccountWillExpire;
use App\Mail\IncidentDetected;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Notifications\VerifyEmailBranded;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use PHPUnit\Runner\Version;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use stdClass;
use Tests\TestCase;
use Throwable;

/**
 * Wave 24.4: independent fixtures, caller parity, direct C ABI safety, and paired benchmarks.
 *
 * NNTMUX_TRANSACTIONAL_MAIL_VERIFY_BENCH=1 php artisan test --compact tests/Unit/RustTransactionalMailVerifyParityTest.php
 * Build first: cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *
 * The benchmark preserves 3 rounds x 41 paired samples x 200 calls, with 5 warmup batches
 * per implementation and seeded randomized pair order. Times include PHP dispatch, FFI,
 * framing, allocation, decoding, and result construction. Its p99 is the nearest-rank p99
 * of 41 batch means (200 calls each), NOT individual-call or production tail latency.
 * Raw samples, order, fixture hashes, failures, source/build/host/tool identities are
 * retained in a unique system-temp JSON artifact, printed even if measurement fails.
 *
 * Apple M5 / PHP 8.5.12 / rustc 1.99.1 release screen (2026-10-24, load average ~3.4).
 * Worst-round PHP/Rust latency speedups: common mean/p99; uncommon mean/p99.
 * AccountWillExpire::resolveRoleExpiryMailState: 0.1294/0.1332; 0.1217/0.1239
 * InvitationMail::formatInvitationSubject: 0.1916/0.1160; 0.1757/0.1647
 * InvitationMail::resolveInvitationMailContext: 0.1329/0.1154; 0.1372/0.1104
 * IncidentDetected::formatIncidentMailCopy: 0.1400/0.1384; 0.1322/0.1225
 * AccountChange::formatAccountChangePreheader: 0.1497/0.1620; 0.1442/0.1030
 * VerifyEmailBranded::normalizeAppUrl: 0.1829/0.1701; 0.2111/0.2095
 * VerifyEmailBranded::appendVerificationSignature: 1.6944/1.3320; 3.9443/2.9560
 * Every class has at least one helper below a 1.5x gate (the signature helper misses
 * common p99 and shares its toggle with normalizeAppUrl); all five defaults stay false.
 * Raw artifact: nntmux-w24-mail-bbsotvps499ff72UPNR (system temp).
 */
class RustTransactionalMailVerifyParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [AccountWillExpire::class, InvitationMail::class, IncidentDetected::class, AccountChange::class, VerifyEmailBranded::class];

    private const string CDEF = 'int32_t nntmux_w24_transactional_mail_verify(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    private const string SYMBOL = 'nntmux_w24_transactional_mail_verify';

    /** @var array<string, FFI|false>|null */
    private ?array $savedDomainLibraries = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
        config([
            'app.name' => 'NNTmux',
            'app.url' => 'https://example.test',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'mail.from.address' => 'noreply@example.test',
            'mail.brand.subject_prefix' => '[NNTmux] ',
        ]);
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
            foreach (self::CLASSES as $other) {
                if ($other !== $class) {
                    self::assertFalse($other::usesRustFfi(), $other.' toggles independently of '.$class);
                }
            }
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
        $ffi = RustPipelineLibrary::resolveDomain('wave24-transactional-mail-verify-test', self::CDEF);
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
        $symbol = self::SYMBOL;

        // @phpstan-ignore method.notFound (The symbol is defined by the CDEF supplied to FFI::cdef.)
        return $ffi->$symbol(...$args);
    }

    private function raw(int $operation, string $input, string $expected): void
    {
        $ffi = $this->native();
        $cap = max(1, strlen($expected));
        $out = $ffi->new("char[{$cap}]");
        self::assertSame(strlen($expected), self::abi($ffi, $operation, $input, strlen($input), $out, $cap), "operation {$operation}");
        self::assertSame($expected, FFI::string($out, strlen($expected)), "operation {$operation}");
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
        $class::disableRustFfi();
    }

    public function test_role_expiry_state_independent_fixtures_and_grid(): void
    {
        $this->native();
        $this->parity(AccountWillExpire::class, 'resolveRoleExpiryMailState', ['alice', 'Silver', 5, true, 'Gold', 'Jun 4, 2026'], [
            'username' => 'alice', 'account' => 'Silver', 'days' => 5, 'hasPendingRole' => true, 'pendingRoleName' => 'Gold',
            'pendingRoleStartDate' => 'Jun 4, 2026', 'preheader' => 'Your Silver role expires in 5 day(s), then Gold is scheduled.',
        ]);
        $this->parity(AccountWillExpire::class, 'resolveRoleExpiryMailState', ['bob', 'User', -1, true, 'Gold', null], [
            'username' => 'bob', 'account' => 'User', 'days' => -1, 'hasPendingRole' => false, 'pendingRoleName' => null,
            'pendingRoleStartDate' => null, 'preheader' => 'Your User role expires in -1 day(s).',
        ]);
        $this->parity(AccountWillExpire::class, 'resolveRoleExpiryMailState', ['', '', PHP_INT_MIN, false, 'Gold', 'Jun 4, 2026'], [
            'username' => '', 'account' => '', 'days' => PHP_INT_MIN, 'hasPendingRole' => false, 'pendingRoleName' => null,
            'pendingRoleStartDate' => null, 'preheader' => 'Your  role expires in -9223372036854775808 day(s).',
        ]);
        $this->parity(AccountWillExpire::class, 'resolveRoleExpiryMailState', ["\0\xff", 'é', PHP_INT_MAX, true, '', ''], [
            'username' => "\0\xff", 'account' => 'é', 'days' => PHP_INT_MAX, 'hasPendingRole' => true, 'pendingRoleName' => '',
            'pendingRoleStartDate' => '', 'preheader' => 'Your é role expires in 9223372036854775807 day(s), then  is scheduled.',
        ]);
        foreach (['Silver', '', "a\0b", str_repeat('Role ', 2000)] as $account) {
            foreach ([0, 1, 30, -7] as $days) {
                foreach ([false, true] as $flag) {
                    foreach ([null, 'Gold', ''] as $name) {
                        foreach ([null, 'Jan 1, 2027'] as $start) {
                            $pending = $flag && $name !== null && $start !== null;
                            $expected = [
                                'username' => 'u', 'account' => $account, 'days' => $days, 'hasPendingRole' => $pending,
                                'pendingRoleName' => $pending ? $name : null, 'pendingRoleStartDate' => $pending ? $start : null,
                                'preheader' => 'Your '.$account.' role expires in '.$days.' day(s)'.($pending ? ', then '.$name.' is scheduled' : '').'.',
                            ];
                            $this->parity(AccountWillExpire::class, 'resolveRoleExpiryMailState', ['u', $account, $days, $flag, $name, $start], $expected);
                            $this->raw(0, self::frames([$account, (string) $days, $flag ? '1' : '0', $name, $start]), self::frames([
                                $pending ? '1' : '0', $expected['pendingRoleName'], $expected['pendingRoleStartDate'], $expected['preheader'],
                            ]));
                        }
                    }
                }
            }
        }
    }

    public function test_invitation_subject_and_context_independent_fixtures(): void
    {
        $this->native();
        $this->parity(InvitationMail::class, 'formatInvitationSubject', ['[NNTmux] ', 'NNTmux'], "[NNTmux] You're invited to join NNTmux");
        $this->parity(InvitationMail::class, 'formatInvitationSubject', ['', ''], "You're invited to join ");
        $this->parity(InvitationMail::class, 'resolveInvitationMailContext', [null, 'NNTmux'], ['invitedByName' => 'A member', 'preheader' => 'A member has invited you to join NNTmux.']);
        $this->parity(InvitationMail::class, 'resolveInvitationMailContext', ['', 'NNTmux'], ['invitedByName' => '', 'preheader' => ' has invited you to join NNTmux.']);
        $this->parity(InvitationMail::class, 'resolveInvitationMailContext', ['0', ''], ['invitedByName' => '0', 'preheader' => '0 has invited you to join .']);
        foreach (['', '[S] ', "\0", 'é ', str_repeat('p', 5000)] as $prefix) {
            foreach (['', 'NNTmux', "\xff\0", str_repeat('s', 5000)] as $site) {
                $this->parity(InvitationMail::class, 'formatInvitationSubject', [$prefix, $site], $prefix."You're invited to join ".$site);
                $this->raw(1, self::frames([$prefix, $site]), $prefix."You're invited to join ".$site);
                foreach ([null, '', 'mod_alice', "x\0\xff", str_repeat('u', 3000)] as $inviter) {
                    $name = $inviter ?? 'A member';
                    $this->parity(InvitationMail::class, 'resolveInvitationMailContext', [$inviter, $site], ['invitedByName' => $name, 'preheader' => $name.' has invited you to join '.$site.'.']);
                    $this->raw(2, self::frames([$inviter, $site]), self::frames([$name, $name.' has invited you to join '.$site.'.']));
                }
            }
        }
    }

    public function test_incident_and_account_change_independent_fixtures(): void
    {
        $this->native();
        $this->parity(IncidentDetected::class, 'formatIncidentMailCopy', [false, 'critical', 'NZB Search, API'], [
            'status' => 'Detected', 'impact' => 'Critical', 'subject' => 'Service incident Detected: NZB Search, API',
            'preheader' => 'Critical impact incident detected on NZB Search, API.',
        ]);
        $this->parity(IncidentDetected::class, 'formatIncidentMailCopy', [true, 'minor', 'Web'], [
            'status' => 'Resolved', 'impact' => 'Minor', 'subject' => 'Service incident Resolved: Web', 'preheader' => 'Minor incident on Web has been resolved.',
        ]);
        $impacts = array_merge(array_map(static fn (IncidentImpactEnum $case): string => $case->value, IncidentImpactEnum::cases()), ['', 'éclat', "\xe9x", 'Already', '1st', "\0x", 'zZ', str_repeat('m', 4000)]);
        foreach ([false, true] as $resolved) {
            foreach ($impacts as $impact) {
                foreach (['', 'API', 'NZB Search, API', "\0\xff", str_repeat('Svc, ', 1000)] as $services) {
                    // PHP 8.2+ ucfirst() is locale-insensitive ASCII; expected values are built independently.
                    $label = $impact === '' ? '' : ($impact[0] >= 'a' && $impact[0] <= 'z' ? chr(ord($impact[0]) - 32) : $impact[0]).substr($impact, 1);
                    $status = $resolved ? 'Resolved' : 'Detected';
                    $expected = [
                        'status' => $status, 'impact' => $label, 'subject' => 'Service incident '.$status.': '.$services,
                        'preheader' => $resolved ? $label.' incident on '.$services.' has been resolved.' : $label.' impact incident detected on '.$services.'.',
                    ];
                    $this->parity(IncidentDetected::class, 'formatIncidentMailCopy', [$resolved, $impact, $services], $expected);
                    $this->raw(3, self::frames([$resolved ? '1' : '0', $impact, $services]), self::frames(array_values($expected)));
                }
            }
        }
        $this->parity(AccountChange::class, 'formatAccountChangePreheader', ['NNTmux', 'Silver'], ['account' => 'Silver', 'preheader' => 'Your NNTmux account level was updated to Silver.']);
        foreach (['', 'NNTmux', "\0\xff", str_repeat('s', 4000)] as $site) {
            foreach ([null, '', '0', 'Silver', 'é', str_repeat('r', 4000)] as $role) {
                $account = $role ?? 'User';
                $expected = ['account' => $account, 'preheader' => 'Your '.$site.' account level was updated to '.$account.'.'];
                $this->parity(AccountChange::class, 'formatAccountChangePreheader', [$site, $role], $expected);
                $this->raw(4, self::frames([$site, $role]), self::frames(array_values($expected)));
            }
        }
    }

    public function test_verification_origin_and_signature_independent_fixtures(): void
    {
        $this->native();
        $origins = [
            '' => null, '   ' => null, " \t\n\r\0\x0B" => null, 'https://example.test' => 'https://example.test',
            ' https://example.test/ ' => 'https://example.test', 'HTTP://Example.Test///' => 'HTTP://Example.Test', 'HtTpS://x' => 'HtTpS://x',
            'example.test' => 'https://example.test', 'example.test/' => 'https://example.test', '/' => 'https:', '///' => 'https:',
            'http:/x' => 'https://http:/x', 'ftp://x/' => 'https://ftp://x', 'https:/' => 'https://https:', 'https://' => 'https:',
            'https://x/path/' => 'https://x/path', "\0https://x" => 'https://x', "x\0y" => "https://x\0y", 'é.test/' => 'https://é.test',
            " \x0Bhttp://a.b:8080/sub/ \n" => 'http://a.b:8080/sub', "\xffhost" => "https://\xffhost", 'httpss://x' => 'https://httpss://x',
            str_repeat('a', 5000).'/' => 'https://'.str_repeat('a', 5000),
        ];
        foreach ($origins as $raw => $expected) {
            $raw = (string) $raw;
            $this->parity(VerifyEmailBranded::class, 'normalizeAppUrl', [$raw], $expected);
            $this->raw(5, self::frames([$raw]), self::frames([$expected]));
        }
        // RFC 4231 test case 2 through the URL assembly path.
        $this->parity(VerifyEmailBranded::class, 'appendVerificationSignature', ['what do ya want for nothing?', 'Jefe'], 'what do ya want for nothing?&signature=5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843');
        $urls = ['', 'https://example.test/email/verify/7/abc?expires=1', 'https://example.test/email/verify/7/abc', 'https://x/?', "https://x/\0\xff", str_repeat('u', 9000)];
        $keys = ['', 'k', 'base64:'.base64_encode(str_repeat('k', 32)), str_repeat('K', 64), str_repeat('K', 65), str_repeat("\xaa", 131), "\0\xff\0"];
        foreach ($urls as $url) {
            foreach ($keys as $key) {
                $expected = $url.(str_contains($url, '?') ? '&' : '?').'signature='.hash_hmac('sha256', $url, $key);
                $this->parity(VerifyEmailBranded::class, 'appendVerificationSignature', [$url, $key], $expected);
                $this->raw(6, self::frames([$url, $key]), $expected);
            }
        }
    }

    public function test_real_mail_and_notification_callers_match_with_either_backend(): void
    {
        $this->native();
        Carbon::setTestNow(Carbon::create(2026, 10, 24, 12));
        $results = [];
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
            }
            $result = [];

            $will = new AccountWillExpire($this->userWithPendingRole(), 5);
            $result['will'] = [$will->username, $will->account, $will->days, $will->hasPendingRole, $will->pendingRoleName, $will->pendingRoleStartDate, $will->preheader];
            $plain = new User;
            $plain->username = 'plain';
            $willPlain = new AccountWillExpire($plain, 2);
            $result['will_plain'] = [$willPlain->account, $willPlain->hasPendingRole, $willPlain->pendingRoleName, $willPlain->preheader];

            $change = new AccountChange($this->userWithPendingRole());
            $changePlain = new AccountChange($plain);
            $result['change'] = [$change->account, $change->preheader, $changePlain->account, $changePlain->preheader];

            $invitation = $this->invitation('mod_alice');
            /** @var Envelope $envelope */
            $envelope = (new InvitationMail($invitation))->envelope();
            /** @var Content $content */
            $content = (new InvitationMail($invitation))->content();
            /** @var Content $anonymous */
            $anonymous = (new InvitationMail($this->invitation(null)))->content();
            $result['invitation'] = [$envelope->subject, $content->with['invitedByName'], $content->with['preheader'], $anonymous->with['invitedByName'], $anonymous->with['preheader']];

            $detected = (new IncidentDetected($this->incident(), false))->build();
            $resolved = (new IncidentDetected($this->incident(), true))->build();
            $result['incident'] = [$detected->subject, $detected->viewData['preheader'], $resolved->subject, $resolved->viewData['preheader']];

            foreach (['https://example.test/', 'example.test', '  '] as $appUrl) {
                config(['app.url' => $appUrl]);
                /** @var MailMessage $message */
                $message = (new VerifyEmailBranded)->toMail($this->notifiable());
                $url = (string) $message->viewData['url'];
                if (trim($appUrl) !== '') {
                    self::assertStringStartsWith('https://example.test/email/verify/7/', $url);
                    self::assertTrue(URL::hasValidSignature(Request::create($url)), 'signature validates with backend '.($enabled ? 'Rust' : 'PHP'));
                }
                $result['verify'][$appUrl] = $url;
            }
            $results[] = $result;
        }
        self::assertSame($results[0], $results[1]);
        self::assertSame(['tester', 'Silver', 5, true, 'Gold', 'Jun 4, 2026', 'Your Silver role expires in 5 day(s), then Gold is scheduled.'], $results[0]['will']);
        self::assertSame(['User', false, null, 'Your User role expires in 2 day(s).'], $results[0]['will_plain']);
        self::assertSame(['Silver', 'Your NNTmux account level was updated to Silver.', 'User', 'Your NNTmux account level was updated to User.'], $results[0]['change']);
        self::assertSame(["[NNTmux] You're invited to join NNTmux", 'mod_alice', 'mod_alice has invited you to join NNTmux.', 'A member', 'A member has invited you to join NNTmux.'], $results[0]['invitation']);
        self::assertSame(['[NNTmux] Service incident Detected: NZB Search, API', 'Critical impact incident detected on NZB Search, API.', '[NNTmux] Service incident Resolved: NZB Search, API', 'Critical incident on NZB Search, API has been resolved.'], $results[0]['incident']);
    }

    public function test_unavailable_library_fallback_for_every_helper(): void
    {
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $this->savedDomainLibraries = $cache->getValue();
        $blocked = $this->savedDomainLibraries;
        $blocked['wave24-transactional-mail-verify|default|'.hash('xxh3', self::CDEF)] = false;
        $cache->setValue(null, $blocked);
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        $this->parity(AccountWillExpire::class, 'resolveRoleExpiryMailState', ['u', 'A', 1, true, 'B', 'C'], [
            'username' => 'u', 'account' => 'A', 'days' => 1, 'hasPendingRole' => true, 'pendingRoleName' => 'B', 'pendingRoleStartDate' => 'C',
            'preheader' => 'Your A role expires in 1 day(s), then B is scheduled.',
        ]);
        $this->parity(InvitationMail::class, 'formatInvitationSubject', ['P ', 'S'], "P You're invited to join S");
        $this->parity(InvitationMail::class, 'resolveInvitationMailContext', [null, 'S'], ['invitedByName' => 'A member', 'preheader' => 'A member has invited you to join S.']);
        $this->parity(IncidentDetected::class, 'formatIncidentMailCopy', [true, 'major', 'X'], ['status' => 'Resolved', 'impact' => 'Major', 'subject' => 'Service incident Resolved: X', 'preheader' => 'Major incident on X has been resolved.']);
        $this->parity(AccountChange::class, 'formatAccountChangePreheader', ['S', null], ['account' => 'User', 'preheader' => 'Your S account level was updated to User.']);
        $this->parity(VerifyEmailBranded::class, 'normalizeAppUrl', ['x/'], 'https://x');
        $this->parity(VerifyEmailBranded::class, 'appendVerificationSignature', ['u', 'k'], 'u?signature='.hash_hmac('sha256', 'u', 'k'));
        foreach (self::CLASSES as $class) {
            self::assertFalse((new ReflectionProperty($class, 'ffi'))->getValue(), $class.' caches the unavailable library');
        }
    }

    /** @return array<int, array{string, string}> */
    private static function abiCases(): array
    {
        return [
            0 => [self::frames(['Silver', '5', '1', 'Gold', 'Jun 4, 2026']), self::frames(['1', 'Gold', 'Jun 4, 2026', 'Your Silver role expires in 5 day(s), then Gold is scheduled.'])],
            1 => [self::frames(['[S] ', 'Site']), "[S] You're invited to join Site"],
            2 => [self::frames([null, 'Site']), self::frames(['A member', 'A member has invited you to join Site.'])],
            3 => [self::frames(['0', 'major', 'API']), self::frames(['Detected', 'Major', 'Service incident Detected: API', 'Major impact incident detected on API.'])],
            4 => [self::frames(['Site', null]), self::frames(['User', 'Your Site account level was updated to User.'])],
            5 => [self::frames([' example.test/ ']), self::frames(['https://example.test'])],
            6 => [self::frames(['https://x/v?a=1', 'k']), 'https://x/v?a=1&signature='.hash_hmac('sha256', 'https://x/v?a=1', 'k')],
        ];
    }

    public function test_direct_abi_all_operations_buffer_retry_no_partial_and_byte_alignment(): void
    {
        $ffi = $this->native();
        foreach (self::abiCases() as $operation => [$input, $expected]) {
            $required = strlen($expected);
            $out = $ffi->new('char[1024]');
            FFI::memset($out, 0x5A, 1024);
            foreach ([0, 1, $required - 1] as $short) {
                self::assertSame(-$required, self::abi($ffi, $operation, $input, strlen($input), $out, $short), "small output {$operation}/{$short}");
                self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024), 'No partial write or terminator on insufficient capacity');
            }
            self::assertSame($required, self::abi($ffi, $operation, $input, strlen($input), $out, $required));
            self::assertSame($expected, FFI::string($out, $required));
            self::assertSame('Z', $out[$required], 'Exact byte capacity needs no trailing NUL');
            self::assertSame($required, self::abi($ffi, $operation, $input, strlen($input), $out, 1024), 'larger capacity reports exact length');

            // Input and output are byte pointers: odd addresses are valid, not a typed-alignment error.
            $inSize = strlen($input) + 2;
            $inStorage = $ffi->new("char[{$inSize}]");
            FFI::memcpy(FFI::addr($inStorage[1]), $input, strlen($input));
            $outSize = $required + 2;
            $outStorage = $ffi->new("char[{$outSize}]");
            FFI::memset($outStorage, 0x51, $outSize);
            self::assertSame($required, self::abi($ffi, $operation, FFI::addr($inStorage[1]), strlen($input), FFI::addr($outStorage[1]), $required));
            self::assertSame($expected, FFI::string(FFI::addr($outStorage[1]), $required));
            self::assertSame('Q', $outStorage[0]);
            self::assertSame('Q', $outStorage[$required + 1]);
        }
        // The PHP wrapper retries once with the negated required length beyond its 4096-byte scratch.
        $long = str_repeat('x', 20_000);
        foreach (self::CLASSES as $class) {
            $class::enableRustFfi();
        }
        self::assertSame("You're invited to join ".$long, InvitationMail::formatInvitationSubject('', $long));
        self::assertSame('https://'.$long, VerifyEmailBranded::normalizeAppUrl($long.'//'));
        self::assertSame(['account' => $long, 'preheader' => 'Your S account level was updated to '.$long.'.'], AccountChange::formatAccountChangePreheader('S', $long));
    }

    public function test_direct_abi_rejects_null_overflow_wraparound_alias_and_malformed_frames(): void
    {
        $ffi = $this->native();
        $out = $ffi->new('char[256]');
        FFI::memset($out, 0x5A, 256);
        foreach (range(0, 7) as $operation) {
            $input = self::abiCases()[$operation][0] ?? self::frames(['x']);
            self::assertSame(-1, self::abi($ffi, $operation, null, 0, $out, 256));
            self::assertSame(-1, self::abi($ffi, $operation, null, strlen($input), $out, 256));
            self::assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), null, 0));
            self::assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), null, 256));
            self::assertSame(-1, self::abi($ffi, $operation, $input, -1, $out, 256), 'size_t above isize::MAX');
            self::assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), $out, -1), 'capacity above isize::MAX');
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        }
        $wrap = $ffi->cast('char *', -8);
        self::assertSame(-1, self::abi($ffi, 1, $wrap, 16, $out, 256), 'input address wraparound');
        $input = self::frames(['P', 'S']);
        self::assertSame(-1, self::abi($ffi, 1, $input, strlen($input), $wrap, 16), 'output address wraparound');
        $expected = "PYou're invited to join S";
        $alias = $ffi->new('char[256]');
        foreach ([0, 1, 4, 9] as $offset) {
            FFI::memset($alias, 0x51, 256);
            FFI::memcpy($alias, $input, strlen($input));
            $before = FFI::string($alias, 256);
            self::assertSame(strlen($expected), self::abi($ffi, 1, $alias, strlen($input), FFI::addr($alias[$offset]), 128));
            self::assertSame(substr($before, 0, $offset).$expected.substr($before, $offset + strlen($expected)), FFI::string($alias, 256), 'Overlap must finish reading before writing and preserve bytes outside output');
        }
        $bad = [
            [7, self::frames(['x'])], [99, ''], [0xFFFFFFFF, ''],
            [0, ''], [0, self::frames(['a', '5', '0', null])], [0, self::frames(['a', '5', '0', null, null, 'extra'])],
            [0, self::frames([null, '5', '0', null, null])], [0, self::frames(['a', null, '0', null, null])], [0, self::frames(['a', '05', '0', null, null])],
            [0, self::frames(['a', '+5', '0', null, null])], [0, self::frames(['a', '9223372036854775808', '0', null, null])], [0, self::frames(['a', '5', 'true', null, null])],
            [0, self::frames(['a', '5', null, null, null])], [0, self::frames(['a', '-0', '0', null, null])],
            [1, self::frames(['p'])], [1, self::frames([null, 's'])], [1, self::frames(['p', null])], [1, "\x01\x00\x00"], [1, pack('V', 200).'short'],
            [2, self::frames(['a', null])], [2, self::frames([null])], [2, self::frames(['a', 's', 'x'])],
            [3, self::frames(['2', 'minor', 's'])], [3, self::frames([null, 'minor', 's'])], [3, self::frames(['1', null, 's'])], [3, self::frames(['1', 'minor', null])], [3, self::frames(['1', 'minor'])],
            [4, self::frames([null, 'r'])], [4, self::frames(['s'])],
            [5, ''], [5, self::frames([null])], [5, self::frames(['a', 'b'])],
            [6, self::frames(['u'])], [6, self::frames([null, 'k'])], [6, self::frames(['u', null])], [6, self::frames(['u', 'k']).'x'],
        ];
        foreach ($bad as [$operation, $bytes]) {
            self::assertSame(-1, self::abi($ffi, $operation, $bytes, strlen($bytes), $out, 256), "malformed {$operation}: ".bin2hex($bytes));
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        }
    }

    private function userWithPendingRole(): User
    {
        $currentRole = new Role;
        $currentRole->name = 'Silver';
        $pendingRole = new Role;
        $pendingRole->name = 'Gold';
        $user = new User;
        $user->username = 'tester';
        $user->email = 'tester@example.test';
        $user->pending_roles_id = 2;
        $user->pending_role_start_date = Carbon::create(2026, 6, 4, 12);
        $user->setRelation('role', $currentRole);
        $user->setRelation('pendingRole', $pendingRole);

        return $user;
    }

    private function invitation(?string $inviterName): Invitation
    {
        $invitation = new Invitation;
        $invitation->token = 'fake-token-abc123';
        $invitation->email = 'newuser@example.test';
        $invitation->expires_at = Carbon::now()->addDays(7);
        $inviter = null;
        if ($inviterName !== null) {
            $inviter = new User;
            $inviter->username = $inviterName;
        }
        $invitation->setRelation('invitedBy', $inviter);

        return $invitation;
    }

    private function incident(): ServiceIncident
    {
        $incident = new ServiceIncident;
        $incident->title = 'API latency spike';
        $incident->status = IncidentStatusEnum::Identified;
        $incident->impact = IncidentImpactEnum::Critical;
        $incident->started_at = Carbon::now()->subMinutes(15);
        $incident->setRelation('services', collect([(object) ['name' => 'NZB Search'], (object) ['name' => 'API']]));

        return $incident;
    }

    private function notifiable(): object
    {
        return new class extends stdClass
        {
            public int $id = 7;

            public string $username = 'tester';

            public string $email = 'tester@example.test';

            public function getKey(): int
            {
                return $this->id;
            }

            public function getEmailForVerification(): string
            {
                return $this->email;
            }
        };
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_TRANSACTIONAL_MAIL_VERIFY_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_TRANSACTIONAL_MAIL_VERIFY_BENCH=1 to preserve paired benchmark evidence.');
        }
        $this->native();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w24-mail-');
        self::assertNotFalse($artifact);
        $evidence = [
            'protocol' => [
                'rounds' => 3, 'samples_per_round' => 41, 'calls_per_sample' => 200, 'warmup_batches_per_implementation' => 5,
                'order' => 'Seeded local Randomizer Mt19937 per method/workload/round, PHP and Rust paired on identical inputs',
                'p99_definition' => 'nearest rank ceil(0.99 * 41), of batch means of 200 calls; not individual-call or production p99; coarse screening evidence only',
                'gate' => 'Each common/uncommon method must clear 1.5x mean and p99 in every round; all methods sharing a class toggle must qualify',
                'scope' => 'Host CPU helpers only; real unsuffixed callers include dispatch, FFI, framing, decoding and allocation. No DB/network or mail transport.',
                'reproduce' => 'cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release && cp rust/pipeline-ffi/target/release/libnntmux_pipeline.dylib storage/app/nntmux-native/ && NNTMUX_TRANSACTIONAL_MAIL_VERIFY_BENCH=1 php artisan test --compact tests/Unit/RustTransactionalMailVerifyParityTest.php',
            ],
            'metadata' => self::benchmarkMetadata(), 'measurements' => [], 'summary' => [], 'failures' => [],
        ];
        fwrite(STDERR, 'Wave24.4 raw benchmark artifact: '.$artifact.PHP_EOL);
        try {
            $classQualified = [];
            foreach (self::benchmarkWorkloads() as $name => [$class, $common, $uncommon, $fixtureHash]) {
                $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $call) {
                    $class::disableRustFfi();
                    $expected = $call();
                    $class::enableRustFfi();
                    self::assertSame($expected, $call(), $name.' '.$kind.' correctness before timing');
                    for ($round = 0; $round < 3; $round++) {
                        $seed = 240400 + $round + (int) (crc32($name.$kind) & 0xFFFF);
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
                $classQualified[$class] = ($classQualified[$class] ?? true) && $qualified;
                $evidence['summary'][$name] = $worst + ['paired_screen_qualified' => $qualified, 'production_default' => $class::usesRustFfi() ? 'Rust' : 'PHP'];
                fwrite(STDERR, $name.' '.json_encode($evidence['summary'][$name], JSON_THROW_ON_ERROR).PHP_EOL);
            }
            foreach ($classQualified as $class => $qualified) {
                // Defaults change only by source edit after every helper behind a toggle qualifies.
                $evidence['class_defaults'][$class] = ['qualified' => $qualified, 'source_default' => (new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue()];
                if (! $qualified) {
                    self::assertFalse((new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue(), $class.' must default to PHP when any helper misses a 1.5x gate');
                }
            }
        } catch (Throwable $failure) {
            $evidence['failures'][] = ['class' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            $evidence['finished_at_utc'] = gmdate('c');
            self::assertNotFalse(file_put_contents($artifact, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)));
            fwrite(STDERR, 'Wave24.4 evidence preserved: '.$artifact.PHP_EOL);
        }
    }

    /** @return array<string, array{class-string, Closure, Closure, string}> */
    private static function benchmarkWorkloads(): array
    {
        $longUrl = 'https://example.test/email/verify/123456789/'.sha1('long@example.test').'?expires=1793000000&'.str_repeat('utm_x=1&', 120).'z=1';
        $key = 'base64:'.base64_encode(str_repeat('k', 32));
        $definitions = [
            ['AccountWillExpire::resolveRoleExpiryMailState', AccountWillExpire::class, 'resolveRoleExpiryMailState', ['alice', 'Silver', 5, false, null, null], ['alice', str_repeat('Premium Lifetime ', 20), 365, true, str_repeat('Gold Plus ', 20), 'Jun 4, 2026']],
            ['InvitationMail::formatInvitationSubject', InvitationMail::class, 'formatInvitationSubject', ['[NNTmux] ', 'NNTmux'], ['', str_repeat('Indexer Name ', 40)]],
            ['InvitationMail::resolveInvitationMailContext', InvitationMail::class, 'resolveInvitationMailContext', ['mod_alice', 'NNTmux'], [null, str_repeat('Indexer Name ', 40)]],
            ['IncidentDetected::formatIncidentMailCopy', IncidentDetected::class, 'formatIncidentMailCopy', [false, 'major', 'NZB Search, API'], [true, 'critical', implode(', ', array_map(static fn (int $i): string => 'Service '.$i, range(1, 60)))]],
            ['AccountChange::formatAccountChangePreheader', AccountChange::class, 'formatAccountChangePreheader', ['NNTmux', 'Silver'], [str_repeat('Indexer Name ', 40), null]],
            ['VerifyEmailBranded::normalizeAppUrl', VerifyEmailBranded::class, 'normalizeAppUrl', ['https://example.test'], ['  '.str_repeat('sub.', 60).'example.test'.str_repeat('/', 10).' ']],
            ['VerifyEmailBranded::appendVerificationSignature', VerifyEmailBranded::class, 'appendVerificationSignature', ['https://example.test/email/verify/7/'.sha1('a@example.test').'?expires=1793000000', $key], [$longUrl, str_repeat("\xaa", 131)]],
        ];
        $workloads = [];
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
        $class::disableRustFfi();
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
        $files = [__FILE__, $root.'/composer.lock', $root.'/vendor/composer/installed.php', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/rust/pipeline-ffi/Cargo.toml', $root.'/app/Support/RustPipelineLibrary.php', $root.'/rust/pipeline-ffi/src/cleaning.rs'];
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
