<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Requests\Auth\LoginLoginRequest;
use App\Http\Requests\Auth\RegisterRegisterRequest;
use App\Http\Requests\Auth\ShowLinkRequestFormForgotPasswordRequest;
use App\Http\Requests\Disable2faPasswordSecurityRequest;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib, PHP 8.5 on Apple Silicon Darwin. See the commit message for the measured run; each run prints
 * its ratios to STDERR.
 *
 * Packet marshaling, FFI scratch access and decoding dominate these sub-microsecond helpers, so Rust
 * misses the 1.5x gate and every helper keeps PHP as the default (`usesRustFfi() === false`).
 */
class RustAuthRequestsConfirmPasswordParityTest extends TestCase
{
    private const CLASSES = [
        LoginLoginRequest::class,
        RegisterRegisterRequest::class,
        ShowLinkRequestFormForgotPasswordRequest::class,
        Disable2faPasswordSecurityRequest::class,
        ConfirmPasswordController::class,
    ];

    /** Bytes that exercise trimming, case folding, separators, control bytes and invalid UTF-8. */
    private const ALPHABET = ['a', 'Z', '0', '9', '_', '-', '.', '@', '/', '\\', '#', '?', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x01", "\x7F", ':', "\xc3", "\x89", "\xff", 'E', 'h'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        (new ReflectionClass(LoginLoginRequest::class))->setStaticPropertyValue('ffi', null);

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: bool, 2: ?string, 3: bool, 4: array{login: string, remember: bool, captcha: ?string, captcha_present: bool, valid: bool}}>
     */
    public static function loginProvider(): array
    {
        return [
            'email with captcha' => [" alice@example.test\n", true, ' tok ', true, ['login' => 'alice@example.test', 'remember' => true, 'captcha' => 'tok', 'captcha_present' => true, 'valid' => true]],
            'username without captcha' => ['bob', false, null, false, ['login' => 'bob', 'remember' => false, 'captcha' => null, 'captcha_present' => false, 'valid' => true]],
            'captcha required but blank' => ['bob', false, " \t", true, ['login' => 'bob', 'remember' => false, 'captcha' => null, 'captcha_present' => false, 'valid' => false]],
            'all null' => [null, false, null, false, ['login' => '', 'remember' => false, 'captcha' => null, 'captcha_present' => false, 'valid' => false]],
            'blank login' => ["\0 \x0B", true, 'x', false, ['login' => '', 'remember' => true, 'captcha' => 'x', 'captcha_present' => true, 'valid' => false]],
            'inner whitespace kept' => [" a b\x01 ", false, "c\nd", true, ['login' => "a b\x01", 'remember' => false, 'captcha' => "c\nd", 'captcha_present' => true, 'valid' => true]],
            'multibyte kept' => ["\xc3\x89lise", true, '', false, ['login' => "\xc3\x89lise", 'remember' => true, 'captcha' => null, 'captcha_present' => false, 'valid' => true]],
        ];
    }

    /**
     * @param  array{login: string, remember: bool, captcha: ?string, captcha_present: bool, valid: bool}  $expected
     */
    #[DataProvider('loginProvider')]
    public function test_login_attempt_input_parity(?string $login, bool $remember, ?string $captcha, bool $require, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, LoginLoginRequest::normalizeLoginAttemptInputPhp($login, $remember, $captcha, $require));
        $this->assertSame($expected, LoginLoginRequest::normalizeLoginAttemptInputNative($login, $remember, $captcha, $require));
        LoginLoginRequest::setUseRustFfi(true);
        $this->assertSame($expected, LoginLoginRequest::normalizeLoginAttemptInput($login, $remember, $captcha, $require));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: int, 4: array{username: string, email: string, invite_token: ?string, status: int, invite_required: bool, closed: bool, allowed: bool}}>
     */
    public static function registrationProvider(): array
    {
        return [
            'open' => [' Alice ', ' Alice@Example.TEST ', null, 0, ['username' => 'Alice', 'email' => 'alice@example.test', 'invite_token' => null, 'status' => 0, 'invite_required' => false, 'closed' => false, 'allowed' => true]],
            'invite with token' => [null, null, ' abc ', 1, ['username' => '', 'email' => '', 'invite_token' => 'abc', 'status' => 1, 'invite_required' => true, 'closed' => false, 'allowed' => true]],
            'invite blank token' => ['u', 'e@x', "\t", 1, ['username' => 'u', 'email' => 'e@x', 'invite_token' => null, 'status' => 1, 'invite_required' => true, 'closed' => false, 'allowed' => false]],
            'closed' => ['u', 'e@x', 'tok', 2, ['username' => 'u', 'email' => 'e@x', 'invite_token' => 'tok', 'status' => 2, 'invite_required' => false, 'closed' => true, 'allowed' => false]],
            'unknown status fails closed' => ['u', 'e', null, 7, ['username' => 'u', 'email' => 'e', 'invite_token' => null, 'status' => 2, 'invite_required' => false, 'closed' => true, 'allowed' => false]],
            'negative status' => ['u', 'e', null, -1, ['username' => 'u', 'email' => 'e', 'invite_token' => null, 'status' => 2, 'invite_required' => false, 'closed' => true, 'allowed' => false]],
            'min int status' => ['u', 'e', null, PHP_INT_MIN, ['username' => 'u', 'email' => 'e', 'invite_token' => null, 'status' => 2, 'invite_required' => false, 'closed' => true, 'allowed' => false]],
            'multibyte email only ascii folded' => ['u', "\xc3\x89A@B", null, 0, ['username' => 'u', 'email' => "\xc3\x89a@b", 'invite_token' => null, 'status' => 0, 'invite_required' => false, 'closed' => false, 'allowed' => true]],
        ];
    }

    /**
     * @param  array{username: string, email: string, invite_token: ?string, status: int, invite_required: bool, closed: bool, allowed: bool}  $expected
     */
    #[DataProvider('registrationProvider')]
    public function test_registration_input_parity(?string $username, ?string $email, ?string $token, int $status, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, RegisterRegisterRequest::normalizeRegistrationInputPhp($username, $email, $token, $status));
        $this->assertSame($expected, RegisterRegisterRequest::normalizeRegistrationInputNative($username, $email, $token, $status));
        RegisterRegisterRequest::setUseRustFfi(true);
        $this->assertSame($expected, RegisterRegisterRequest::normalizeRegistrationInput($username, $email, $token, $status));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: array{email: ?string, apikey: ?string, lookup: string, captcha: ?string}}>
     */
    public static function resetProvider(): array
    {
        return [
            'email wins' => [' A@B.Test ', 'ABC123', 'c', ['email' => 'a@b.test', 'apikey' => 'abc123', 'lookup' => 'email', 'captcha' => 'c']],
            'apikey only' => [' ', ' ABC ', null, ['email' => null, 'apikey' => 'abc', 'lookup' => 'apikey', 'captcha' => null]],
            'invalid apikey' => [null, 'ab-c', ' ', ['email' => null, 'apikey' => null, 'lookup' => 'none', 'captcha' => null]],
            'multibyte apikey' => [null, "\xc3\x89", null, ['email' => null, 'apikey' => null, 'lookup' => 'none', 'captcha' => null]],
            'all null' => [null, null, null, ['email' => null, 'apikey' => null, 'lookup' => 'none', 'captcha' => null]],
            'inner newline apikey' => [null, "ab\ncd", 'x', ['email' => null, 'apikey' => null, 'lookup' => 'none', 'captcha' => 'x']],
        ];
    }

    /**
     * @param  array{email: ?string, apikey: ?string, lookup: string, captcha: ?string}  $expected
     */
    #[DataProvider('resetProvider')]
    public function test_reset_lookup_input_parity(?string $email, ?string $apikey, ?string $captcha, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInputPhp($email, $apikey, $captcha));
        $this->assertSame($expected, ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInputNative($email, $apikey, $captcha));
        ShowLinkRequestFormForgotPasswordRequest::setUseRustFfi(true);
        $this->assertSame($expected, ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInput($email, $apikey, $captcha));
    }

    /**
     * @return array<string, array{0: ?string, 1: int, 2: array{present: bool, bytes: int, min_length: int, meets_min_length: bool, valid: bool}}>
     */
    public static function disable2faProvider(): array
    {
        return [
            'not trimmed' => [' secret1 ', 8, ['present' => true, 'bytes' => 9, 'min_length' => 8, 'meets_min_length' => true, 'valid' => true]],
            'short' => ['short', 8, ['present' => true, 'bytes' => 5, 'min_length' => 8, 'meets_min_length' => false, 'valid' => false]],
            'null' => [null, 8, ['present' => false, 'bytes' => 0, 'min_length' => 8, 'meets_min_length' => false, 'valid' => false]],
            'negative minimum' => [null, -5, ['present' => false, 'bytes' => 0, 'min_length' => 0, 'meets_min_length' => true, 'valid' => false]],
            'min int minimum' => ['x', PHP_INT_MIN, ['present' => true, 'bytes' => 1, 'min_length' => 0, 'meets_min_length' => true, 'valid' => true]],
            'max int minimum' => ['x', PHP_INT_MAX, ['present' => true, 'bytes' => 1, 'min_length' => PHP_INT_MAX, 'meets_min_length' => false, 'valid' => false]],
            'nul byte' => ["abcdefgh\0", 8, ['present' => true, 'bytes' => 9, 'min_length' => 8, 'meets_min_length' => true, 'valid' => false]],
            'multibyte counted in bytes' => ["\xc3\x89\xc3\x89\xc3\x89\xc3\x89", 8, ['present' => true, 'bytes' => 8, 'min_length' => 8, 'meets_min_length' => true, 'valid' => true]],
        ];
    }

    /**
     * @param  array{present: bool, bytes: int, min_length: int, meets_min_length: bool, valid: bool}  $expected
     */
    #[DataProvider('disable2faProvider')]
    public function test_disable_2fa_payload_parity(?string $password, int $minLength, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, Disable2faPasswordSecurityRequest::normalizeDisable2faPayloadPhp($password, $minLength));
        $this->assertSame($expected, Disable2faPasswordSecurityRequest::normalizeDisable2faPayloadNative($password, $minLength));
        Disable2faPasswordSecurityRequest::setUseRustFfi(true);
        $this->assertSame($expected, Disable2faPasswordSecurityRequest::normalizeDisable2faPayload($password, $minLength));
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: array{path: string, used_default: bool, has_fragment: bool}}>
     */
    public static function redirectProvider(): array
    {
        return [
            'local with fragment' => [' /profile#security ', '/', ['path' => '/profile#security', 'used_default' => false, 'has_fragment' => true]],
            'local with query' => ['/a?b=c', '/', ['path' => '/a?b=c', 'used_default' => false, 'has_fragment' => false]],
            'protocol relative' => ['//evil.test', '/home', ['path' => '/home', 'used_default' => true, 'has_fragment' => false]],
            'backslash bypass' => ['/\\evil.test', 'https://x', ['path' => '/', 'used_default' => true, 'has_fragment' => false]],
            'absolute url' => ['https://evil.test/', '/', ['path' => '/', 'used_default' => true, 'has_fragment' => false]],
            'header injection' => ["/a\r\nSet-Cookie: x", '/', ['path' => '/', 'used_default' => true, 'has_fragment' => false]],
            'delete byte' => ["/a\x7F", '/d#x', ['path' => '/d#x', 'used_default' => true, 'has_fragment' => true]],
            'null intended' => [null, ' /x ', ['path' => '/x', 'used_default' => true, 'has_fragment' => false]],
            'blank intended' => [" \t", '', ['path' => '/', 'used_default' => true, 'has_fragment' => false]],
            'relative path' => ['profile', '//x', ['path' => '/', 'used_default' => true, 'has_fragment' => false]],
            'root' => ['/', '/home', ['path' => '/', 'used_default' => false, 'has_fragment' => false]],
            '2048 bytes' => ['/'.str_repeat('a', 2047), '/', ['path' => '/'.str_repeat('a', 2047), 'used_default' => false, 'has_fragment' => false]],
            '2049 bytes' => ['/'.str_repeat('a', 2048), '/', ['path' => '/', 'used_default' => true, 'has_fragment' => false]],
            'multibyte kept' => ["/caf\xc3\xa9", '/', ['path' => "/caf\xc3\xa9", 'used_default' => false, 'has_fragment' => false]],
        ];
    }

    /**
     * @param  array{path: string, used_default: bool, has_fragment: bool}  $expected
     */
    #[DataProvider('redirectProvider')]
    public function test_redirect_destination_parity(?string $intended, string $default, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, ConfirmPasswordController::normalizeRedirectDestinationPhp($intended, $default));
        $this->assertSame($expected, ConfirmPasswordController::normalizeRedirectDestinationNative($intended, $default));
        ConfirmPasswordController::setUseRustFfi(true);
        $this->assertSame($expected, ConfirmPasswordController::normalizeRedirectDestination($intended, $default));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();

        mt_srand(3605);
        $nullable = static fn (string $value): ?string => mt_rand(0, 4) === 0 ? null : $value;
        for ($i = 0; $i < 400; $i++) {
            $login = [$nullable(self::randomBytes(24)), mt_rand(0, 1) === 1, $nullable(self::randomBytes(12)), mt_rand(0, 1) === 1];
            $this->assertNotNull(LoginLoginRequest::evaluateNative(1, [
                ...LoginLoginRequest::nullableParts($login[0]), $login[1] ? '1' : '0', ...LoginLoginRequest::nullableParts($login[2]), $login[3] ? '1' : '0',
            ]));
            $this->assertSame(LoginLoginRequest::normalizeLoginAttemptInputPhp(...$login), LoginLoginRequest::normalizeLoginAttemptInputNative(...$login), bin2hex(serialize($login)));

            $register = [$nullable(self::randomBytes(16)), $nullable(self::randomBytes(24)), $nullable(self::randomBytes(12)), self::randomInt()];
            $this->assertSame(RegisterRegisterRequest::normalizeRegistrationInputPhp(...$register), RegisterRegisterRequest::normalizeRegistrationInputNative(...$register), bin2hex(serialize($register)));

            $key = mt_rand(0, 2) === 0 ? ' '.bin2hex(random_bytes(16))."\n" : self::randomBytes(20);
            $reset = [$nullable(self::randomBytes(24)), $nullable($key), $nullable(self::randomBytes(8))];
            $this->assertSame(ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInputPhp(...$reset), ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInputNative(...$reset), bin2hex(serialize($reset)));

            $disable = [$nullable(self::randomBytes(20)), mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(-2, 16)];
            $this->assertSame(Disable2faPasswordSecurityRequest::normalizeDisable2faPayloadPhp(...$disable), Disable2faPasswordSecurityRequest::normalizeDisable2faPayloadNative(...$disable), bin2hex(serialize($disable)));

            $path = mt_rand(0, 1) === 1 ? '/'.self::randomBytes(20) : self::randomBytes(20);
            $redirect = [$nullable($path), mt_rand(0, 1) === 1 ? '/'.self::randomBytes(6) : self::randomBytes(6)];
            $this->assertNotNull(LoginLoginRequest::evaluateNative(5, [...LoginLoginRequest::nullableParts($redirect[0]), $redirect[1]]));
            $this->assertSame(ConfirmPasswordController::normalizeRedirectDestinationPhp(...$redirect), ConfirmPasswordController::normalizeRedirectDestinationNative(...$redirect), bin2hex(serialize($redirect)));
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['bob', '1', '1', 'tok', '1', '1'], LoginLoginRequest::evaluateNative(1, ['1', ' bob ', '1', '1', ' tok ', '1']));
        $this->assertSame(['u', 'e@x', '1', 't', '1', '1', '0', '1'], LoginLoginRequest::evaluateNative(2, ['1', 'u', '1', 'E@X', '1', 't', '1']));
        $this->assertSame(['0', '', '1', 'abc', 'apikey', '0', ''], LoginLoginRequest::evaluateNative(3, ['0', '', '1', 'ABC', '0', '']));
        $this->assertSame(['1', '3', '8', '0', '0'], LoginLoginRequest::evaluateNative(4, ['1', 'abc', '8']));
        $this->assertSame(['/home', '1', '0'], LoginLoginRequest::evaluateNative(5, ['1', '//evil', '/home']));
        $this->assertNull(LoginLoginRequest::evaluateNative(-1, ['x']));
        $this->assertNull(LoginLoginRequest::evaluateNative(6, ['x']));
        $this->assertNull(LoginLoginRequest::evaluateNative(1, array_fill(0, 65, 'x')));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(LoginLoginRequest::class))->setStaticPropertyValue('ffi', false);

        $this->assertNull(LoginLoginRequest::evaluateNative(1, ['0', '', '0', '0', '', '0']));
        $this->assertSame(LoginLoginRequest::normalizeLoginAttemptInputPhp(' a ', true, null, true), LoginLoginRequest::normalizeLoginAttemptInputNative(' a ', true, null, true));
        $this->assertSame(RegisterRegisterRequest::normalizeRegistrationInputPhp('u', 'E', 't', 1), RegisterRegisterRequest::normalizeRegistrationInputNative('u', 'E', 't', 1));
        $this->assertSame(ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInputPhp(null, 'K1', 'c'), ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInputNative(null, 'K1', 'c'));
        $this->assertSame(Disable2faPasswordSecurityRequest::normalizeDisable2faPayloadPhp('pw', 8), Disable2faPasswordSecurityRequest::normalizeDisable2faPayloadNative('pw', 8));
        $this->assertSame(['path' => '/', 'used_default' => true, 'has_fragment' => false], ConfirmPasswordController::normalizeRedirectDestinationNative('//evil', 'https://x'));
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        $this->assertTrue(LoginLoginRequest::normalizeLoginAttemptInput('bob', false, null)['valid']);
        $this->assertSame('/x', ConfirmPasswordController::normalizeRedirectDestination('/x')['path']);

        $missing = RustPipelineLibrary::resolveDomain('search', LoginLoginRequest::FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_direct_c_abi_declines_malformed_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['0', '', '/']);
        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w36_auth_requests_confirm_password($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(5, $valid, strlen($valid), $buf, 256);
        $this->assertGreaterThan(8, $written);
        $this->assertSame(self::packet(['/', '1', '0']), FFI::string($buf, $written));
        $this->assertSame(-$written, $call(5, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(5, $valid, strlen($valid), null, 0));
        $this->assertSame(-1, $call(5, null, 4, $buf, 256));
        $this->assertSame(-1, $call(5, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(0, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(6, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(5, '', 0, $small, 8));
        $this->assertSame(-1, $call(5, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(5, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(5, $valid."\0", strlen($valid) + 1, $small, 8));
        $this->assertSame(-1, $call(5, "\x41\0\0\0".str_repeat("\0", 260), 264, $small, 8));
        $this->assertSame(-1, $callParts(1, ['0', 'x', '0', '0', '', '0']));
        $this->assertSame(-1, $callParts(1, ['1', 'x', '2', '0', '', '0']));
        $this->assertSame(-1, $callParts(1, ['1', 'x', '0', '0', '']));
        $this->assertSame(-1, $callParts(2, ['0', '', '0', '', '0', '', '01']));
        $this->assertSame(-1, $callParts(2, ['0', '', '0', '', '0', '', '+1']));
        $this->assertSame(-1, $callParts(2, ['0', '', '0', '', '0', '']));
        $this->assertSame(-1, $callParts(3, ['0', '', '0', '', '0', 'x']));
        $this->assertSame(-1, $callParts(4, ['1', 'x', '8.0']));
        $this->assertSame(-1, $callParts(4, ['1', 'x']));
        $this->assertSame(-1, $callParts(5, ['0', 'x', '/']));
        $this->assertSame(-1, $callParts(5, ['1', '/']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $common = static function (): void {
            LoginLoginRequest::normalizeLoginAttemptInput('alice@example.test', true, 'turnstile-token-abc', true);
            RegisterRegisterRequest::normalizeRegistrationInput('alice', 'Alice@Example.test', null, 0);
            ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInput('alice@example.test', null, 'turnstile-token-abc');
            Disable2faPasswordSecurityRequest::normalizeDisable2faPayload('correct horse battery staple');
            ConfirmPasswordController::normalizeRedirectDestination('/profileedit#security');
        };
        $longPath = '/'.str_repeat('segment/', 200);
        $uncommon = static function () use ($longPath): void {
            LoginLoginRequest::normalizeLoginAttemptInput(" \t".str_repeat('user', 60)."\n", false, " \t", true);
            RegisterRegisterRequest::normalizeRegistrationInput(" \0Ünïcode ", ' '.str_repeat('MiXeD', 50).'@Example.TEST ', " \t", PHP_INT_MIN);
            ShowLinkRequestFormForgotPasswordRequest::normalizeResetLookupInput(" \t", ' '.str_repeat('ABCDEF0123', 20).' ', null);
            Disable2faPasswordSecurityRequest::normalizeDisable2faPayload(str_repeat("p\0", 200), -3);
            ConfirmPasswordController::normalizeRedirectDestination(" {$longPath}\\evil ", " //evil.test\n");
        };

        $setAll = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
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

        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 36.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus) | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
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
        foreach (self::CLASSES as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesRustFfi());
            }
        }
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
            1 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 1][mt_rand(0, 4)],
            default => mt_rand(-2, 4),
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
        $ffi = RustPipelineLibrary::resolveDomain('search', LoginLoginRequest::FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
