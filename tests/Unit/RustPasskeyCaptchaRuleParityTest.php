<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Passkeys\GeneratePasskeyAuthenticationOptionsAction as Authentication;
use App\Actions\Passkeys\GeneratePasskeyRegisterOptionsAction as Registration;
use App\Models\User;
use App\Rules\RecaptchaRule;
use App\Rules\TurnstileRule;
use App\Services\PasswordBreachService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use JsonException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Wave 24.2 parity, caller wiring, C ABI safety and paired performance gate.
 * Run the benchmark with NNTMUX_PASSKEY_CAPTCHA_RULE_BENCH=1 and the release library.
 * Protocol: 3 rounds x 41 randomized pairs x 200 iterations, 50 warmups per side.
 * Samples are batch per-call means; p99 uses nearest rank (max of 41), not a
 * production request-tail estimate. Conservative opt-in requires every gate >=1.5x.
 *
 * Recorded on Apple M5 arm64, PHP 8.5.12 (CLI opcache/JIT disabled), rustc/cargo
 * 1.99.1, release opt-level=3/lto/codegen-units=1, host-default resource limits,
 * base dddbb7a7e944247901f3acbc5488a9cc5240274d plus this slice, 2026-10-24.
 * Worst-round PHP / Native mean and p99 speedups (all classes default to PHP):
 *
 * | Helper                              | Common mean / p99 | Uncommon mean / p99 |
 * |-------------------------------------|-------------------|---------------------|
 * | augmentRegisterOptionsJson          | 0.47x / 0.55x     | 0.96x / 0.88x       |
 * | normalizeAuthenticatorSelection     | 0.16x / 0.17x     | 0.17x / 0.16x       |
 * | normalizeAuthenticationRpId         | 0.20x / 0.19x     | 0.14x / 0.14x       |
 * | authenticationOptionsSessionKey     | 0.22x / 0.20x     | 0.22x / 0.23x       |
 * | evaluateRecaptchaInput              | 0.37x / 0.35x     | 0.37x / 0.34x       |
 * | evaluateTurnstileInput              | 0.37x / 0.36x     | 0.37x / 0.40x       |
 * | normalizeThreshold                  | 0.25x / 0.25x     | 0.25x / 0.20x       |
 * | shouldCheckPassword                 | 0.35x / 0.34x     | 0.35x / 0.32x       |
 *
 * Each Native helper includes FFI lookup/call, marshalling, allocation and result
 * conversion. The timed functions perform no network I/O. Session-key workloads
 * are identical because that operation is input-independent. These are local
 * helper measurements, not application/production throughput claims.
 * Raw 1,968 pairs, order, per-round latencies, source/vendor/library hashes and
 * environment metadata are retained in the benchmark's printed JSONL artifact:
 * /private/var/folders/gx/2s9j3tj918q0m2j3ns56pz7h0000gn/T/wave24-2-bench-ferfa3p8rv4g94K8SN7
 *
 * Reproduce from this worktree:
 * cargo test --manifest-path rust/pipeline-ffi/Cargo.toml
 * cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
 * cp rust/pipeline-ffi/target/release/libnntmux_pipeline.dylib storage/app/nntmux-native/libnntmux_pipeline.dylib
 * NNTMUX_PASSKEY_CAPTCHA_RULE_BENCH=1 php artisan test --compact tests/Unit/RustPasskeyCaptchaRuleParityTest.php
 */
class RustPasskeyCaptchaRuleParityTest extends TestCase
{
    private const array CLASSES = [Registration::class, Authentication::class, RecaptchaRule::class, TurnstileRule::class, PasswordBreachService::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'resetRustFfi')) {
                $class::resetRustFfi();
            }
        }
        parent::tearDown();
    }

    public function test_php_contracts_and_default_dispatch(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertTrue(method_exists($class, 'usesRustFfi'), $class.' has no candidate dispatch');
            self::assertFalse($class::usesRustFfi());
        }
        self::assertSame(0, PasswordBreachService::normalizeThreshold(-1));
        self::assertSame(PHP_INT_MAX, PasswordBreachService::normalizeThreshold(PHP_INT_MAX));
        self::assertFalse(PasswordBreachService::shouldCheckPassword(''));
        self::assertTrue(PasswordBreachService::shouldCheckPassword('0'));
        self::assertSame('localhost', Authentication::normalizeAuthenticationRpId(null));
        self::assertSame('localhost', Authentication::normalizeAuthenticationRpId(''));
        self::assertSame(' example.com ', Authentication::normalizeAuthenticationRpId(' example.com '));
        self::assertSame('passkey-authentication-options', Authentication::authenticationOptionsSessionKey());
        self::assertSame([null, 'preferred', 'preferred'], Registration::normalizeAuthenticatorSelection('invalid', 'invalid', 'invalid'));
        self::assertSame(['platform', 'required', null], Registration::normalizeAuthenticatorSelection('platform', 'required', null));
    }

    public function test_register_augmentation_parity_and_explicit_shape_contract(): void
    {
        $algorithms = [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257], ['type' => 'public-key', 'alg' => -8]];
        $input = '{"challenge":"a/b","hints":["old"],"extensions":{"other":2,"credProps":false},"publicKey":{"pubKeyCredParams":[],"extensions":{"nested":true}}}';
        $expected = ['challenge' => 'a/b', 'hints' => ['hybrid', 'client-device'], 'extensions' => ['other' => 2, 'credProps' => true], 'publicKey' => ['pubKeyCredParams' => $algorithms, 'extensions' => ['nested' => true, 'credProps' => true], 'hints' => ['hybrid', 'client-device']], 'pubKeyCredParams' => $algorithms];
        foreach (['Php', 'Native', ''] as $side) {
            $method = 'augmentRegisterOptionsJson'.$side;
            self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), Registration::$method($input, ['hybrid', '', 'client-device', '0'], true));
        }
        $inputs = ['', 'bad-json', 'null', 'true', '17', '"hello"', '[]', '{}', '[1,"a",null]', '{"publicKey":[]}', '{"publicKey":null}', '{"publicKey":"keep"}', '{"extensions":[1,2],"publicKey":{"extensions":5}}', '{"extensions":false}', '{"0":1,"2":2}', '{"hints":["keep"],"extensions":{"credProps":false}}', '{"a":1,"a":2}', '{"float":1.0,"big":9223372036854775809}', '{"text":"é😀/\u2028\u2029\\\""}', "{\"bad\":\"\xff\"}", str_repeat('[', 513).str_repeat(']', 513)];
        foreach ($inputs as $json) {
            foreach ([[], ['hybrid'], [null, false, 0, '0', '', 'client-device'], ['a' => 'security-key', 'b' => ['nested']], ["\xff"]] as $hints) {
                foreach ([false, true] as $credProps) {
                    self::assertSame(self::outcome(fn () => Registration::augmentRegisterOptionsJsonPhp($json, $hints, $credProps)), self::outcome(fn () => Registration::augmentRegisterOptionsJsonNative($json, $hints, $credProps)), 'registration parity '.bin2hex($json));
                }
            }
        }
        self::assertSame('invalid', Registration::augmentRegisterOptionsJsonNative('invalid', ["\xff"], true));
    }

    public function test_selection_rp_password_and_captcha_matrix(): void
    {
        $strings = [null, '', '0', 'platform', 'cross-platform', 'required', 'preferred', 'discouraged', 'invalid', "\0", "\xff", 'é', str_repeat('x', 4096)];
        foreach ($strings as $attachment) {
            foreach ($strings as $uv) {
                foreach ($strings as $rk) {
                    self::assertSame(Registration::normalizeAuthenticatorSelectionPhp($attachment, $uv ?? '', $rk), Registration::normalizeAuthenticatorSelectionNative($attachment, $uv ?? '', $rk));
                }
            }
            self::assertSame(Authentication::normalizeAuthenticationRpIdPhp($attachment), Authentication::normalizeAuthenticationRpIdNative($attachment));
            if ($attachment !== null) {
                self::assertSame(PasswordBreachService::shouldCheckPasswordPhp($attachment), PasswordBreachService::shouldCheckPasswordNative($attachment));
            }
        }
        foreach ([PHP_INT_MIN, -10, -1, 0, 1, 2, 999, PHP_INT_MAX] as $threshold) {
            self::assertSame(PasswordBreachService::normalizeThresholdPhp($threshold), PasswordBreachService::normalizeThresholdNative($threshold));
        }
        foreach ([null, false, true, 0, 1, 0.0, [], ['token'], new \stdClass, '', '0', ' ', "\0", "\xff", str_repeat('a', 8192)] as $value) {
            foreach ([null, false, true] as $verified) {
                foreach (['evaluateRecaptchaInput' => RecaptchaRule::class, 'evaluateTurnstileInput' => TurnstileRule::class] as $method => $class) {
                    $php = $method.'Php';
                    $native = $method.'Native';
                    self::assertSame($class::$php($value, $verified), $class::$native($value, $verified));
                }
            }
        }
        self::assertSame('Please complete the captcha verification.', RecaptchaRule::evaluateRecaptchaInputNative(0, true));
        self::assertSame('The captcha verification failed. Please try again.', RecaptchaRule::evaluateRecaptchaInputNative('0', false));
        self::assertNull(RecaptchaRule::evaluateRecaptchaInputNative('0', null));
        self::assertNull(TurnstileRule::evaluateTurnstileInputNative(' ', true));
    }

    public function test_real_callers_preserve_session_options_and_selection(): void
    {
        config([
            'passkeys.relying_party.id' => 'login.example.test',
            'passkeys.relying_party.name' => 'NNTmux',
            'passkeys.hints' => ['hybrid', '', 'client-device'],
            'passkeys.request_cred_props_extension' => true,
            'passkeys.authenticator_selection.authenticator_attachment' => 'unsupported',
            'passkeys.authenticator_selection.user_verification' => 'unsupported',
            'passkeys.authenticator_selection.resident_key' => 'required',
        ]);
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('c', $length));
        $user = new User;
        $user->forceFill(['id' => 7, 'username' => 'passkey-user', 'email' => 'user@example.test']);
        $results = [];
        try {
            foreach ([false, true] as $native) {
                foreach ([Registration::class, Authentication::class] as $class) {
                    $native ? $class::enableRustFfi() : $class::disableRustFfi();
                }
                $registration = new Registration;
                $object = $registration->execute($user, false);
                self::assertInstanceOf(PublicKeyCredentialCreationOptions::class, $object);
                $json = $registration->execute($user);
                self::assertIsString($json);
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame([-7, -257, -8], array_column($decoded['pubKeyCredParams'], 'alg'));
                self::assertSame(['hybrid', 'client-device'], $decoded['hints']);
                self::assertTrue($decoded['extensions']['credProps']);
                $criteria = $registration->authenticatorSelection();
                self::assertNull($criteria->authenticatorAttachment);
                self::assertSame('preferred', $criteria->userVerification);
                self::assertSame('required', $criteria->residentKey);
                self::assertTrue($criteria->requireResidentKey);
                $authentication = (new Authentication)->execute();
                self::assertSame($authentication, Session::get('passkey-authentication-options'));
                self::assertSame('login.example.test', json_decode($authentication, true, 512, JSON_THROW_ON_ERROR)['rpId']);
                $results[] = [$json, $authentication];
            }
            self::assertSame($results[0], $results[1]);
            config(['passkeys.authenticator_selection.resident_key' => null, 'passkeys.request_cred_props_extension' => false, 'passkeys.hints' => []]);
            self::assertNull((new Registration)->authenticatorSelection()->residentKey);
            $withoutExtensions = json_decode((new Registration)->execute($user), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('credProps', $withoutExtensions['extensions'] ?? []);
            config(['passkeys.authenticator_selection.resident_key' => 17]);
            self::assertSame('preferred', (new Registration)->authenticatorSelection()->residentKey);
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_captcha_callers_short_circuit_and_keep_provider_errors(): void
    {
        config(['captcha.recaptcha.secret' => 'fixture-secret', 'captcha.turnstile.secret' => 'fixture-secret']);
        foreach ([false, true] as $native) {
            foreach ([RecaptchaRule::class, TurnstileRule::class] as $class) {
                $native ? $class::enableRustFfi() : $class::disableRustFfi();
                Http::swap(new Factory);
                Http::fake();
                $errors = [];
                (new $class)->validate('captcha', [], static function (string $message) use (&$errors): void {
                    $errors[] = $message;
                });
                self::assertCount(1, $errors);
                Http::assertNothingSent();
                foreach ([false, true] as $verified) {
                    Http::swap(new Factory);
                    Http::fake(['*' => Http::response(['success' => $verified])]);
                    $errors = [];
                    (new $class)->validate('captcha', '0', static function (string $message) use (&$errors): void {
                        $errors[] = $message;
                    });
                    self::assertCount($verified ? 0 : 1, $errors);
                    if (! $verified) {
                        self::assertSame($class === RecaptchaRule::class
                            ? 'The captcha verification failed. Please try again.'
                            : 'Cloudflare human verification could not be confirmed. Please reload the page, complete the verification, and try again.', $errors[0]);
                    }
                    Http::assertSentCount(1);
                    Http::assertSent(static fn (Request $request): bool => $request['response'] === '0');
                }
            }
        }
    }

    public function test_breach_caller_normalizes_threshold_and_skips_empty_password(): void
    {
        foreach ([false, true] as $native) {
            $native ? PasswordBreachService::enableRustFfi() : PasswordBreachService::disableRustFfi();
            Http::swap(new Factory);
            $this->app->forgetInstance(UncompromisedVerifier::class);
            Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('1E4C9B93F3F0682250B6CF8331B7EE68FD8:5')]);
            $service = new PasswordBreachService(-1);
            self::assertFalse($service->isPasswordBreached(''));
            Http::assertNothingSent();
            self::assertTrue($service->isPasswordBreached('password'));
            self::assertFalse((new PasswordBreachService(5))->isPasswordBreached('password'));
            Http::swap(new Factory);
            $this->app->forgetInstance(UncompromisedVerifier::class);
            Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('Unavailable', 503)]);
            self::assertFalse($service->isPasswordBreached('password'));
        }
    }

    public function test_unavailable_and_incompatible_ffi_fall_back_without_swallowing_json_errors(): void
    {
        foreach ([false, FFI::cdef('int abs(int);')] as $override) {
            foreach (self::CLASSES as $class) {
                $class::enableRustFfi();
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $override);
            }
            self::assertSame([null, 'preferred', null], Registration::normalizeAuthenticatorSelection('bad', 'bad', null));
            self::assertSame(Registration::augmentRegisterOptionsJsonPhp('{}', ['hybrid'], true), Registration::augmentRegisterOptionsJson('{}', ['hybrid'], true));
            self::assertSame('localhost', Authentication::normalizeAuthenticationRpId(null));
            self::assertSame('passkey-authentication-options', Authentication::authenticationOptionsSessionKey());
            self::assertSame(RecaptchaRule::evaluateRecaptchaInputPhp('', null), RecaptchaRule::evaluateRecaptchaInput('', null));
            self::assertSame(TurnstileRule::evaluateTurnstileInputPhp('token', false), TurnstileRule::evaluateTurnstileInput('token', false));
            self::assertSame(0, PasswordBreachService::normalizeThreshold(-1));
            self::assertTrue(PasswordBreachService::shouldCheckPassword('0'));
            $outcome = self::outcome(fn () => Registration::augmentRegisterOptionsJson('{}', ["\xff"], true));
            self::assertSame(JsonException::class, $outcome['error'] ?? null);
        }
    }

    public function test_randomized_binary_and_json_parity(): void
    {
        $random = new Randomizer(new Mt19937(2402));
        for ($i = 0; $i < 300; $i++) {
            $value = '';
            for ($j = 0, $len = $random->getInt(0, 128); $j < $len; $j++) {
                $value .= chr($random->getInt(0, 255));
            }
            self::assertSame(Authentication::normalizeAuthenticationRpIdPhp($value), Authentication::normalizeAuthenticationRpIdNative($value));
            self::assertSame(PasswordBreachService::shouldCheckPasswordPhp($value), PasswordBreachService::shouldCheckPasswordNative($value));
            $threshold = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
            self::assertSame(PasswordBreachService::normalizeThresholdPhp($threshold), PasswordBreachService::normalizeThresholdNative($threshold));
            $json = json_encode(['publicKey' => ['extensions' => ['original' => $i]], 'text' => base64_encode($value), 'empty' => (object) [], 'items' => [null, true, $threshold]], JSON_THROW_ON_ERROR);
            self::assertSame(Registration::augmentRegisterOptionsJsonPhp($json, ['hybrid', false, '0'], (bool) ($i % 2)), Registration::augmentRegisterOptionsJsonNative($json, ['hybrid', false, '0'], (bool) ($i % 2)));
        }
    }

    private function library(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', implode("\n", [Registration::FFI_CDEF, Authentication::FFI_CDEF, RecaptchaRule::FFI_CDEF, PasswordBreachService::FFI_CDEF]));
        self::assertInstanceOf(FFI::class, $ffi, 'Build and copy the worktree release library; parity must exercise actual Rust exports.');

        return $ffi;
    }

    public function test_c_abi_buffers_flags_nulls_overflow_alignment_and_real_native_output(): void
    {
        $ffi = $this->library();
        $decline = -2147483648;
        $buffer = $ffi->new('char[4096]');
        // @phpstan-ignore method.notFound
        $needed = $ffi->nntmux_passkey_augment_register_json('{}', 2, '["hybrid"]', 10, 1, null, 0);
        self::assertLessThan(0, $needed);
        self::assertNotSame($decline, $needed);
        $needed = -$needed;
        FFI::memset($buffer, 0x5A, 4096);
        // @phpstan-ignore method.notFound
        self::assertSame(-$needed, $ffi->nntmux_passkey_augment_register_json('{}', 2, '["hybrid"]', 10, 1, $buffer, $needed - 1));
        self::assertSame(str_repeat('Z', 4096), FFI::string($buffer, 4096));
        // @phpstan-ignore method.notFound
        self::assertSame($needed, $ffi->nntmux_passkey_augment_register_json('{}', 2, '["hybrid"]', 10, 1, $buffer, $needed));
        self::assertSame(Registration::augmentRegisterOptionsJsonPhp('{}', ['hybrid'], true), FFI::string($buffer, $needed));
        self::assertSame('Z', $buffer[$needed]);
        $overflow = $ffi->cast('char *', -8);
        // Invalid pointer ranges must be refused before constructing slices.
        foreach ([[null, 1], [$overflow, 32], ['x', -1]] as [$input, $length]) {
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_augment_register_json($input, $length, '[]', 2, 0, $buffer, 4096));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_augment_register_json('{}', 2, $input, $length, 0, $buffer, 4096));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_auth_rp($input, $length, 0, $buffer, 4096));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_password_breach_should_check($input, $length));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_captcha_rule_input(0, 1, $input, $length, -1));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_selection($input, $length, 0, 'preferred', 9, '', 0, 1, $buffer, 4096));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_selection('', 0, 1, $input, $length, '', 0, 1, $buffer, 4096));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_selection('', 0, 1, 'preferred', 9, $input, $length, 0, $buffer, 4096));
        }
        foreach ([[null, 16], [$overflow, 32], [$buffer, -1]] as [$out, $cap]) {
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_augment_register_json('{}', 2, '[]', 2, 0, $out, $cap));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_auth_rp('rp', 2, 0, $out, $cap));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_auth_session_key($out, $cap));
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_passkey_selection('', 0, 1, '', 0, '', 0, 1, $out, $cap));
        }
        // The other buffer exports use the same atomic sizing contract.
        $calls = [
            // @phpstan-ignore method.notFound
            static fn ($out, $cap) => $ffi->nntmux_passkey_auth_rp('', 0, 1, $out, $cap),
            // @phpstan-ignore method.notFound
            static fn ($out, $cap) => $ffi->nntmux_passkey_auth_session_key($out, $cap),
            // @phpstan-ignore method.notFound
            static fn ($out, $cap) => $ffi->nntmux_passkey_selection('bad', 3, 0, '', 0, '', 0, 1, $out, $cap),
        ];
        foreach ($calls as $call) {
            $length = -$call(null, 0);
            self::assertGreaterThan(0, $length);
            self::assertLessThan(256, $length);
            FFI::memset($buffer, 0x5A, 4096);
            self::assertSame(-$length, $call($buffer, $length - 1));
            self::assertSame(str_repeat('Z', 4096), FFI::string($buffer, 4096));
            self::assertSame($length, $call($buffer, $length));
            self::assertSame('Z', $buffer[$length]);
        }
        $words = $ffi->new('int64_t[4]');
        FFI::memset($words, 0x5A, 32);
        $bytes = $ffi->cast('char *', FFI::addr($words[0]));
        $unaligned = $ffi->cast('int64_t *', FFI::addr($bytes[1]));
        foreach ([null, $unaligned, $ffi->cast('int64_t *', -8)] as $out) {
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_password_breach_threshold(1, $out));
        }
        self::assertSame(str_repeat('Z', 32), FFI::string($bytes, 32));
        foreach ([PHP_INT_MIN => 0, -1 => 0, 0 => 0, 1 => 1, PHP_INT_MAX => PHP_INT_MAX] as $input => $expected) {
            // @phpstan-ignore method.notFound
            self::assertSame(0, $ffi->nntmux_password_breach_threshold($input, $words));
            self::assertSame($expected, $words[0]);
        }
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_password_breach_should_check(null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_password_breach_should_check("\0", 1));
        foreach ([0, 1] as $provider) {
            foreach ([-1, 0, 1] as $verified) {
                // @phpstan-ignore method.notFound
                self::assertSame(1, $ffi->nntmux_captcha_rule_input($provider, 0, null, 0, $verified));
                // @phpstan-ignore method.notFound
                self::assertSame($verified === 0 ? 2 : 0, $ffi->nntmux_captcha_rule_input($provider, 1, '0', 1, $verified));
            }
        }
        foreach ([[2, 1, -1], [0, 2, -1], [0, 1, 2], [1, 1, -2]] as [$provider, $isString, $verified]) {
            // @phpstan-ignore method.notFound
            self::assertSame($decline, $ffi->nntmux_captcha_rule_input($provider, $isString, 't', 1, $verified));
        }
        // @phpstan-ignore method.notFound
        self::assertSame($decline, $ffi->nntmux_passkey_auth_rp('t', 1, 2, $buffer, 4096));
        // @phpstan-ignore method.notFound
        self::assertSame($decline, $ffi->nntmux_passkey_augment_register_json('{}', 2, '[]', 2, 2, $buffer, 4096));
        // @phpstan-ignore method.notFound
        self::assertSame($decline, $ffi->nntmux_passkey_selection('', 0, 2, '', 0, '', 0, 1, $buffer, 4096));
        // @phpstan-ignore method.notFound
        self::assertSame($decline, $ffi->nntmux_passkey_selection('', 0, 1, '', 0, '', 0, 2, $buffer, 4096));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_PASSKEY_CAPTCHA_RULE_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_PASSKEY_CAPTCHA_RULE_BENCH=1 for the release-library benchmark.');
        }
        $this->library();
        $common = '{"rp":{"id":"example.test","name":"NNTmux"},"challenge":"a/b","user":{"id":"7","name":"user"},"pubKeyCredParams":[]}';
        $uncommon = json_encode(['challenge' => str_repeat('é😀/\\', 256), 'extensions' => ['original' => true, 'credProps' => false], 'publicKey' => ['challenge' => 'nested', 'extensions' => ['original' => 'yes']], 'items' => range(0, 128)], JSON_THROW_ON_ERROR);
        $workloads = [
            'augmentRegisterOptionsJson' => [Registration::class, [$common, ['hybrid', 'client-device'], true], [$uncommon, ['security-key', 'hybrid', 'client-device'], true]],
            'normalizeAuthenticatorSelection' => [Registration::class, [null, 'preferred', 'preferred'], [str_repeat('x', 4096), 'INVALID', null]],
            'normalizeAuthenticationRpId' => [Authentication::class, ['example.test'], [str_repeat('sub.', 1024).'example.test']],
            'authenticationOptionsSessionKey' => [Authentication::class, [], []],
            'evaluateRecaptchaInput' => [RecaptchaRule::class, ['token', true], [str_repeat('t', 8192), false]],
            'evaluateTurnstileInput' => [TurnstileRule::class, ['token', true], [str_repeat('t', 8192), false]],
            'normalizeThreshold' => [PasswordBreachService::class, [1], [PHP_INT_MIN]],
            'shouldCheckPassword' => [PasswordBreachService::class, ['password'], [str_repeat("\0\xff", 4096)]],
        ];
        $sources = [];
        foreach (['rust/pipeline-ffi/src/search.rs', 'app/Actions/Passkeys/GeneratePasskeyRegisterOptionsAction.php', 'app/Actions/Passkeys/GeneratePasskeyAuthenticationOptionsAction.php', 'app/Rules/RecaptchaRule.php', 'app/Rules/TurnstileRule.php', 'app/Services/PasswordBreachService.php', 'tests/Unit/RustPasskeyCaptchaRuleParityTest.php', 'composer.lock', 'vendor/composer/installed.json', 'storage/app/nntmux-native/libnntmux_pipeline.dylib'] as $file) {
            $sources[$file] = hash_file('sha256', base_path($file));
        }
        $metadata = ['time' => gmdate('c'), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'php' => PHP_VERSION, 'platform' => php_uname(), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'rustc' => trim((string) shell_exec('rustc -V')), 'cargo' => trim((string) shell_exec('cargo -V')), 'profile' => 'release opt-level=3 lto=true codegen-units=1', 'resource_limits' => 'host defaults, one process, no runtime/network I/O in timed helpers', 'php_jit' => ini_get('opcache.jit'), 'php_opcache_cli' => ini_get('opcache.enable_cli'), 'ffi_enable' => ini_get('ffi.enable'), 'sources' => $sources, 'protocol' => ['seed' => 2402, 'rounds' => 3, 'samples' => 41, 'iterations' => 200, 'warmup' => 50, 'p99' => 'nearest rank; batch per-call means', 'speedup' => 'PHP latency / Native latency'], 'uncertainty' => '41 batch samples per round cannot establish production p99; report each round, conservative worst round gate; no production throughput claim', 'failures' => []];
        $artifact = tempnam(sys_get_temp_dir(), 'wave24-2-bench-');
        self::assertNotFalse($artifact);
        self::assertNotFalse(file_put_contents($artifact, json_encode(['metadata' => $metadata], JSON_THROW_ON_ERROR).PHP_EOL));
        $random = new Randomizer(new Mt19937(2402));
        $rows = [];
        foreach ($workloads as $method => [$class, $commonArgs, $uncommonArgs]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            foreach (range(1, 3) as $round) {
                foreach (['common' => $commonArgs, 'uncommon' => $uncommonArgs] as $kind => $arguments) {
                    $native = $method.'Native';
                    $php = $method.'Php';
                    $calls = ['native' => static fn () => $class::$native(...$arguments), 'php' => static fn () => $class::$php(...$arguments)];
                    // Require output parity before eligibility; timing includes all FFI,
                    // allocation, JSON marshalling and result conversion in each public helper.
                    self::assertSame($calls['php'](), $calls['native'](), $method.' benchmark parity');
                    for ($warmup = 0; $warmup < 50; $warmup++) {
                        $calls['native']();
                        $calls['php']();
                    }
                    $times = ['native' => [], 'php' => []];
                    $orders = [];
                    for ($sample = 0; $sample < 41; $sample++) {
                        $order = $random->getInt(0, 1) === 0 ? ['native', 'php'] : ['php', 'native'];
                        $orders[] = $order;
                        foreach ($order as $side) {
                            $call = $calls[$side];
                            $start = hrtime(true);
                            for ($iteration = 0; $iteration < 200; $iteration++) {
                                $call();
                            }
                            $times[$side][] = (hrtime(true) - $start) / 200;
                        }
                    }
                    $nativeTimes = $times['native'];
                    $phpTimes = $times['php'];
                    sort($nativeTimes);
                    sort($phpTimes);
                    $mean = (array_sum($phpTimes) / 41) / (array_sum($nativeTimes) / 41);
                    $p99 = $phpTimes[40] / $nativeTimes[40];
                    $worst[$kind.'_mean'] = min($worst[$kind.'_mean'], $mean);
                    $worst[$kind.'_p99'] = min($worst[$kind.'_p99'], $p99);
                    $result = ['method' => $method, 'workload' => $kind, 'round' => $round, 'native_mean_ns' => array_sum($nativeTimes) / 41, 'php_mean_ns' => array_sum($phpTimes) / 41, 'native_p99_ns' => $nativeTimes[40], 'php_p99_ns' => $phpTimes[40], 'mean_speedup' => $mean, 'p99_speedup' => $p99, 'raw_paired_ns' => $times, 'orders' => $orders];
                    self::assertNotFalse(file_put_contents($artifact, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND));
                }
            }
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(' * %-38s common %.2fx / %.2fx; uncommon %.2fx / %.2fx; %s', $method, $worst['common_mean'], $worst['common_p99'], $worst['uncommon_mean'], $worst['uncommon_p99'], $qualified ? 'qualifies' : 'PHP default');
            if ($class::usesRustFfi()) {
                self::assertTrue($qualified, $method.' cannot default to Rust below its performance gate');
            }
        }
        self::assertNotFalse(file_put_contents($artifact, json_encode(['summary' => $rows], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND));
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $rows).PHP_EOL.'Raw benchmark evidence: '.$artifact.PHP_EOL);
    }

    /** @return array{value?: mixed, error?: class-string, message?: string} */
    private static function outcome(Closure $fn): array
    {
        try {
            return ['value' => $fn()];
        } catch (Throwable $e) {
            return ['error' => $e::class, 'message' => $e->getMessage()];
        }
    }
}
