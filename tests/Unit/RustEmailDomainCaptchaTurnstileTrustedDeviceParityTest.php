<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\TrustedDevice;
use App\Rules\ValidEmailDomain;
use App\Services\RecaptchaService;
use App\Services\TurnstileService;
use App\Support\CaptchaHelper;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;
use TypeError;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 22 Slice 22.4:
 * ValidEmailDomain, CaptchaHelper, RecaptchaService, TurnstileService, and TrustedDevice.
 *
 * Benchmarks run only with NNTMUX_EMAIL_CAPTCHA_TRUSTED_DEVICE_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_EMAIL_CAPTCHA_TRUSTED_DEVICE_BENCH=1 php artisan test --compact tests/Unit/RustEmailDomainCaptchaTurnstileTrustedDeviceParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * | Method                                  | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |-----------------------------------------|---------------------|-----------------------|---------|-------------|
 * | ValidEmailDomain::normalizedDomain      | 0.44x / 0.42x       | 0.41x / 0.42x         | No      | PHP (false) |
 * | ValidEmailDomain::domainBlockCode       | 1.77x / 1.42x       | 0.27x / 0.24x         | No      | PHP (false) |
 * | ValidEmailDomain::hasPlusAddressing     | 0.61x / 0.67x       | 0.46x / 0.43x         | No      | PHP (false) |
 * | CaptchaHelper::enabledFor               | 0.34x / 0.41x       | 0.33x / 0.29x         | No      | PHP (false) |
 * | CaptchaHelper::responseFieldNameFor     | 0.34x / 0.36x       | 0.47x / 0.43x         | No      | PHP (false) |
 * | RecaptchaService::renderWidget          | 0.59x / 0.52x       | 1.53x / 1.44x         | No      | PHP (false) |
 * | RecaptchaService::verificationSucceeded | 0.34x / 0.33x       | 0.28x / 0.20x         | No      | PHP (false) |
 * | TurnstileService::renderWidget          | 0.64x / 0.54x       | 1.22x / 1.25x         | No      | PHP (false) |
 * | TurnstileService::failureReason         | 0.39x / 0.36x       | 0.34x / 0.33x         | No      | PHP (false) |
 * | TrustedDevice::hmacTokenHash            | 2.44x / 2.11x       | 5.34x / 4.93x         | Yes     | PHP (false) |
 * | TrustedDevice::cookiePayloadToken       | 0.76x / 0.52x       | 2.03x / 1.64x         | No      | PHP (false) |
 * | TrustedDevice::limitUserAgent           | 0.54x / 0.57x       | 8.01x / 6.52x         | No      | PHP (false) |
 *
 * Measured on Apple M5 (arm64), PHP 8.5.12, release build of a712f93ab plus this change.
 * hmacTokenHash alone clears the gate, but TrustedDevice shares one toggle with
 * cookiePayloadToken and limitUserAgent, which miss it on common workloads, so every
 * class keeps PHP as its production default.
 */
class RustEmailDomainCaptchaTurnstileTrustedDeviceParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        ValidEmailDomain::class,
        CaptchaHelper::class,
        RecaptchaService::class,
        TurnstileService::class,
        TrustedDevice::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('search', TrustedDevice::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
    }

    /**
     * @param  class-string  $class
     */
    private static function toggle(string $class, ?bool $use): void
    {
        match ($use) {
            true => $class::enableRustFfi(),
            false => $class::disableRustFfi(),
            null => $class::resetRustFfi(),
        };
    }

    private static function randomBytes(int $seed, int $maxLen, string $alphabet = ''): string
    {
        mt_srand($seed);
        $len = mt_rand(0, $maxLen);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet === '' ? \chr(mt_rand(0, 255)) : $alphabet[mt_rand(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. ValidEmailDomain
    // ---------------------------------------------------------------------

    public function test_email_normalized_domain_parity(): void
    {
        $values = [
            'user@example.com', 'User@Example.COM', '  a @ Gmail.com ', "a@\tx.org\0", "a@\x0Cx", "a@\x0Bx\x0B",
            '', '0', 'plain', '@x.com', ' @x.com', '0@x.com', ' 0 @x.com', '00@x', 'a@', 'a@ ', 'a@0', 'a@ 0 ',
            'a@00', 'a@b@c', 'a@@b', '@', '@@', "\0@\0", 'a@É.COM', "a@\xC3\x89x", "a@\xff\xfe", 'A+tag@Mail.Example',
            str_repeat('l', 300).'@'.str_repeat('D', 400).'.com',
            null, false, true, 0, 1, 1.5, [], ['a@b.c'],
        ];
        for ($seed = 1; $seed <= 400; $seed++) {
            $values[] = self::randomBytes($seed, 24, "aZ0 @\t\0.+\x0B\x0C\xC3\x89");
        }

        foreach ($values as $value) {
            $expected = ValidEmailDomain::normalizedDomainPhp($value);
            $this->assertSame($expected, ValidEmailDomain::normalizedDomainNative($value), var_export($value, true));
            foreach ([true, false, null] as $use) {
                self::toggle(ValidEmailDomain::class, $use);
                $this->assertSame($expected, ValidEmailDomain::normalizedDomain($value));
            }
        }
    }

    public function test_email_domain_block_code_and_plus_addressing_parity(): void
    {
        $domains = ['gmail.com', 'example.org', '', 'MAILINATOR.COM', 'x.grr.la.io', 'mytempbox.net', 'mail.yopmail.fr'];
        foreach (ValidEmailDomain::BLOCKED_DOMAINS as $domain) {
            $domains[] = $domain;
            $domains[] = $domain.'.example';
            $domains[] = 'sub.'.$domain;
        }
        foreach (ValidEmailDomain::SUSPICIOUS_PATTERNS as $pattern) {
            $domains[] = 'x'.$pattern.'y.com';
        }
        for ($seed = 1; $seed <= 300; $seed++) {
            $domains[] = self::randomBytes($seed, 20, 'temspairlgun.0-1');
        }

        $seen = [];
        foreach ($domains as $domain) {
            $expected = ValidEmailDomain::domainBlockCodePhp($domain);
            $seen[$expected] = true;
            $this->assertSame($expected, ValidEmailDomain::domainBlockCodeNative($domain), $domain);
            foreach ([true, false, null] as $use) {
                self::toggle(ValidEmailDomain::class, $use);
                $this->assertSame($expected, ValidEmailDomain::domainBlockCode($domain));
            }
        }
        $this->assertArrayHasKey(ValidEmailDomain::BLOCK_NONE, $seen);
        $this->assertArrayHasKey(ValidEmailDomain::BLOCK_BLACKLIST, $seen);
        $this->assertArrayHasKey(ValidEmailDomain::BLOCK_PATTERN, $seen);

        $emails = ['a+b@x.com', 'ab@x+y.com', 'a+b', '', '+', '@+', "a\0+@x", 'plain'];
        for ($seed = 1; $seed <= 200; $seed++) {
            $emails[] = self::randomBytes($seed, 12, 'a+@');
        }
        foreach ($emails as $email) {
            $expected = ValidEmailDomain::hasPlusAddressingPhp($email);
            $this->assertSame($expected, ValidEmailDomain::hasPlusAddressingNative($email), $email);
            foreach ([true, false, null] as $use) {
                self::toggle(ValidEmailDomain::class, $use);
                $this->assertSame($expected, ValidEmailDomain::hasPlusAddressing($email));
            }
        }
    }

    public function test_email_validation_rule_matches_across_toggles(): void
    {
        foreach (['plain', 'a@b@c', ' @x.com', 'a@ 0 ', '0@x.com', 42] as $value) {
            foreach ([true, false] as $use) {
                self::toggle(ValidEmailDomain::class, $use);
                $messages = Validator::make(['email' => $value], ['email' => [new ValidEmailDomain]])->errors()->get('email');
                $this->assertSame(['The email must be a valid email address.'], $messages, var_export($value, true));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. CaptchaHelper
    // ---------------------------------------------------------------------

    public function test_captcha_enabled_and_field_name_parity(): void
    {
        foreach (['recaptcha', 'turnstile', 'Turnstile', '', 'other', "turnstile\0", 'turnstile '] as $provider) {
            foreach ([false, true] as $recaptcha) {
                foreach ([false, true] as $turnstile) {
                    foreach ([false, true] as $legacy) {
                        $expected = CaptchaHelper::enabledForPhp($provider, $recaptcha, $turnstile, $legacy);
                        $this->assertSame($expected, CaptchaHelper::enabledForNative($provider, $recaptcha, $turnstile, $legacy));
                        foreach ([true, false, null] as $use) {
                            self::toggle(CaptchaHelper::class, $use);
                            $this->assertSame($expected, CaptchaHelper::enabledFor($provider, $recaptcha, $turnstile, $legacy));
                        }
                    }
                }
            }

            $field = CaptchaHelper::responseFieldNameForPhp($provider);
            $this->assertSame($field, CaptchaHelper::responseFieldNameForNative($provider));
        }
    }

    public function test_captcha_helper_config_matches_across_toggles(): void
    {
        $configs = [
            ['provider' => 'turnstile', 'turnstile' => ['enabled' => true, 'sitekey' => 'ts', 'secret' => 'tsec']],
            ['provider' => 'turnstile', 'turnstile' => ['enabled' => true, 'sitekey' => '', 'secret' => 'tsec']],
            ['provider' => 'recaptcha', 'recaptcha' => ['enabled' => true, 'sitekey' => 'rs', 'secret' => 'rsec']],
            ['provider' => 'recaptcha', 'recaptcha' => ['enabled' => false], 'enabled' => true, 'sitekey' => 'ls', 'secret' => 'lsec'],
            ['provider' => 'recaptcha', 'recaptcha' => ['enabled' => 'yes', 'sitekey' => 'rs', 'secret' => 'rsec']],
            ['provider' => 'none'],
        ];
        foreach ($configs as $config) {
            $results = [];
            foreach ([true, false] as $use) {
                config(['captcha' => $config]);
                self::toggle(CaptchaHelper::class, $use);
                self::toggle(RecaptchaService::class, $use);
                self::toggle(TurnstileService::class, $use);
                $results[] = [
                    CaptchaHelper::isEnabled(),
                    CaptchaHelper::getResponseFieldName(),
                    CaptchaHelper::display(['data-callback' => 'onCaptcha']),
                    array_keys(CaptchaHelper::getValidationRules()),
                ];
            }
            $this->assertSame($results[0], $results[1], json_encode($config) ?: '');
        }
    }

    // ---------------------------------------------------------------------
    // 3. RecaptchaService / TurnstileService
    // ---------------------------------------------------------------------

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function widgetAttributeSets(): array
    {
        $sets = [
            ['class' => 'g-recaptcha', 'data-sitekey' => 'site-key'],
            ['class' => 'cf-turnstile', 'data-sitekey' => "k<'&\">", 'data-theme' => 'auto', 'data-size' => 'normal'],
            ['class' => 'x', 'data-callback' => 'onSubmit', 'data-action' => 'login'],
            ['class' => 'x', 'data-a' => "\xC3", 'data-b' => "\xE0\x80\x80!", 'data-c' => "\xF0\x9F\x98\x80", 'data-d' => "\xED\xA0\x80"],
            ['class' => 'x', 'data-e' => "\xF4\x90\x80\x80", 'data-f' => "\xC2\x41", 'data-g' => "\xE1\x80", 'data-h' => "\xF0\x80\x80\x80\xff"],
            [' class' => 'x', "data\0" => "\0"],
            ['class' => 'é', 'tail ' => ''],
            [],
            ['class' => 'x', 0 => 'zero', 5 => 'five'],
        ];
        for ($seed = 1; $seed <= 200; $seed++) {
            $sets[] = ['class' => 'x', 'data-r' => self::randomBytes($seed, 16, "a&<>\"'\x80\xBF\xC2\xC3\xE0\xED\xEF\xF0\xF4\xF5\xff"), 'k'.$seed => self::randomBytes($seed + 7000, 8)];
        }

        return $sets;
    }

    public function test_widget_html_parity(): void
    {
        foreach (self::widgetAttributeSets() as $attributes) {
            $label = bin2hex(serialize($attributes));
            $recaptcha = RecaptchaService::renderWidgetPhp($attributes);
            $this->assertSame($recaptcha, RecaptchaService::renderWidgetNative($attributes), $label);
            $turnstile = TurnstileService::renderWidgetPhp($attributes);
            $this->assertSame($turnstile, TurnstileService::renderWidgetNative($attributes), $label);
            foreach ([true, false, null] as $use) {
                self::toggle(RecaptchaService::class, $use);
                self::toggle(TurnstileService::class, $use);
                $this->assertSame($recaptcha, RecaptchaService::renderWidget($attributes));
                $this->assertSame($turnstile, TurnstileService::renderWidget($attributes));
            }
        }

        $scalars = ['class' => 'g', 'data-int' => 42, 'data-float' => 1.5, 'data-true' => true, 'data-false' => false, 'data-null' => null];
        $this->assertSame(RecaptchaService::renderWidgetPhp($scalars), RecaptchaService::renderWidgetNative($scalars));

        foreach ([true, false] as $use) {
            self::toggle(TurnstileService::class, $use);
            try {
                TurnstileService::renderWidget(['class' => 'x', 'data-int' => 42]);
                $this->fail('Turnstile must reject non-string attribute values');
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_widget_display_matches_across_toggles(): void
    {
        config([
            'captcha.recaptcha.sitekey' => 'r<site>',
            'captcha.turnstile.sitekey' => "t'site",
        ]);
        foreach ([[], ['data-theme' => 'dark', 'class' => 'custom'], ['data-x' => "\xff"]] as $attributes) {
            $results = [];
            foreach ([true, false] as $use) {
                self::toggle(RecaptchaService::class, $use);
                self::toggle(TurnstileService::class, $use);
                $results[] = [RecaptchaService::display($attributes), TurnstileService::display($attributes)];
            }
            $this->assertSame($results[0], $results[1]);
        }
    }

    public function test_verification_outcome_parity(): void
    {
        $results = [
            ['success' => true], ['success' => false], ['success' => 1], ['success' => 'true'], ['success' => null],
            ['error-codes' => ['invalid-input-response']], [], null, 'success', '', 0, 1, true, false, 1.5, [true],
        ];
        foreach ($results as $result) {
            $label = var_export($result, true);
            $expected = RecaptchaService::verificationSucceededPhp($result);
            $this->assertSame($expected, RecaptchaService::verificationSucceededNative($result), $label);
            foreach ([true, false] as $http) {
                $reason = TurnstileService::failureReasonPhp($http, $result);
                $this->assertSame($reason, TurnstileService::failureReasonNative($http, $result), $label);
                foreach ([true, false, null] as $use) {
                    self::toggle(TurnstileService::class, $use);
                    $this->assertSame($reason, TurnstileService::failureReason($http, $result));
                }
            }
        }
        $this->assertTrue(RecaptchaService::verificationSucceededNative(['success' => true]));
        $this->assertNull(TurnstileService::failureReasonNative(true, ['success' => true]));
        $this->assertSame('http-error', TurnstileService::failureReasonNative(false, ['success' => true]));
        $this->assertSame('rejected', TurnstileService::failureReasonNative(true, ['success' => false]));
        $this->assertSame('invalid-response', TurnstileService::failureReasonNative(true, 'oops'));
    }

    // ---------------------------------------------------------------------
    // 4. TrustedDevice
    // ---------------------------------------------------------------------

    public function test_trusted_device_hmac_parity(): void
    {
        $keys = ['', 'k', 'base64:'.base64_encode(str_repeat("\x11", 32)), str_repeat('K', 64), str_repeat('K', 65), str_repeat("\xAA", 131)];
        $tokens = ['', 'a', Str::random(64), str_repeat('x', 55), str_repeat('x', 56), str_repeat('x', 64), str_repeat("\0\xff", 300)];
        for ($seed = 1; $seed <= 60; $seed++) {
            $keys[] = self::randomBytes($seed, 150);
            $tokens[] = self::randomBytes($seed + 500, 200);
        }
        foreach ($keys as $key) {
            foreach ($tokens as $token) {
                $expected = TrustedDevice::hmacTokenHashPhp($token, $key);
                $this->assertSame(hash_hmac('sha256', $token, $key), $expected);
                $this->assertSame($expected, TrustedDevice::hmacTokenHashNative($token, $key));
            }
        }

        config(['app.key' => 'base64:'.base64_encode(str_repeat("\x22", 32))]);
        foreach ([true, false, null] as $use) {
            self::toggle(TrustedDevice::class, $use);
            $this->assertSame(hash_hmac('sha256', 'plain-token', (string) config('app.key')), TrustedDevice::hashToken('plain-token'));
        }
    }

    /**
     * @return list<mixed>
     */
    private static function cookieCorpus(): array
    {
        $valid = json_encode(['user_id' => 7, 'token' => 'tok', 'expires_at' => 2000]) ?: '';
        $corpus = [
            $valid,
            '{"user_id":7,"token":"","expires_at":2000}',
            ' {"token":"t","user_id":7,"expires_at":2000,"x":[1,{"a":1.5e3,"b":[true,null,"s"]}]} ',
            '{"user_id":7,"token":"t","expires_at":2000,"user_id":8}',
            '{"user\u005fid":7,"token":"\u00e9\ud83d\ude00\/\\\\\"\n","expires_at":2000}',
            '{"user_id":"7","token":"t","expires_at":2000}',
            '{"user_id":" 7","token":"t","expires_at":"2000 "}',
            '{"user_id":"7abc","token":"t","expires_at":2000}',
            '{"user_id":7.0,"token":"t","expires_at":2000}',
            '{"user_id":7.9,"token":"t","expires_at":2e3}',
            '{"user_id":"7","token":1,"expires_at":2000}',
            '{"user_id":true,"token":"t","expires_at":2000}',
            '{"user_id":null,"token":"t","expires_at":2000}',
            '{"user_id":[7],"token":"t","expires_at":2000}',
            '{"user_id":7,"token":"t"}',
            '{"user_id":7,"token":null,"expires_at":2000}',
            '{"user_id":7,"token":"t","expires_at":2000,}',
            '{"user_id":7,"token":"t","expires_at":2000} x',
            '{"user_id":07,"token":"t","expires_at":2000}',
            '{"user_id":-0,"token":"t","expires_at":2000}',
            '{"user_id":7,"token":"\ud800","expires_at":2000}',
            '{"user_id":7,"token":"\udc00","expires_at":2000}',
            '{"user_id":7,"token":"\ud800\u0041","expires_at":2000}',
            "{\"user_id\":7,\"token\":\"a\x01\",\"expires_at\":2000}",
            "{\"user_id\":7,\"token\":\"a\x7f\",\"expires_at\":2000}",
            "{\"user_id\":7,\"token\":\"\xff\",\"expires_at\":2000}",
            "\xEF\xBB\xBF".$valid,
            '{"user_id":99999999999999999999,"token":"t","expires_at":2000}',
            '{"user_id":9223372036854775807,"token":"t","expires_at":9223372036854775808}',
            '{"user_id":-9223372036854775808,"token":"t","expires_at":9223372036854775807}',
            '[7,"t",2000]',
            '"{}"',
            '7',
            '{}',
            '{"user_id":7,"token":"t","expires_at":1999}',
            '{"user_id":7,"token":"t","expires_at":2000,"d":'.str_repeat('[', 200).str_repeat(']', 200).'}',
            '{"user_id":7,"token":"t","expires_at":2000,"d":'.str_repeat('[', 600).str_repeat(']', 600).'}',
            '{"user_id":7,"token":"t","expires_at":2000,"bad":tru}',
            '{"user_id":7,"token":"t","expires_at":2000,"bad":1.}',
            '{"user_id":7,"token":"t","expires_at":2000,"bad":"\x"}',
            "{\"user_id\":7,\"token\":\"t\",\"expires_at\":2000,\"ws\":\f1}",
            '',
            null,
            7,
            ['user_id' => 7],
        ];
        $punctuation = '"{}[],:\\ 0-.eEu';
        mt_srand(2204);
        for ($i = 0; $i < 600; $i++) {
            $base = $corpus[mt_rand(0, 4)];
            $pos = mt_rand(0, \strlen($base));
            $mutated = match (mt_rand(0, 3)) {
                0 => substr($base, 0, $pos),
                1 => substr($base, 0, $pos).\chr(mt_rand(0, 255)).substr($base, $pos + 1),
                2 => substr($base, 0, $pos).$punctuation[mt_rand(0, \strlen($punctuation) - 1)].substr($base, $pos),
                default => substr($base, 0, $pos).substr($base, $pos + 1),
            };
            $corpus[] = $mutated;
        }

        return $corpus;
    }

    public function test_trusted_device_cookie_payload_parity(): void
    {
        $answered = 0;
        $ffi = RustPipelineLibrary::resolveDomain('search', TrustedDevice::FFI_CDEF);
        $this->assertNotNull($ffi);
        $buf = $ffi->new('char[8192]');
        foreach (self::cookieCorpus() as $cookie) {
            foreach ([[7, 1000], [7, 2000], [7, 2001], [8, 1000], [PHP_INT_MIN, 0], [PHP_INT_MAX, PHP_INT_MAX]] as [$userId, $now]) {
                $label = var_export($cookie, true)." user={$userId} now={$now}";
                $expected = self::outcome(static fn () => TrustedDevice::cookiePayloadTokenPhp($cookie, $userId, $now));
                $this->assertSame($expected, self::outcome(static fn () => TrustedDevice::cookiePayloadTokenNative($cookie, $userId, $now)), $label);
                if (\is_string($cookie)) {
                    $raw = self::ffiCall($ffi, 'nntmux_trusted_device_cookie_token', $cookie, \strlen($cookie), $userId, $now, $buf, 8192);
                    $this->assertIsInt($raw);
                    if ($raw !== -1) {
                        $answered++;
                        $this->assertSame($expected, ['value', $raw === 0 ? null : FFI::string($buf, $raw - 1)], "raw {$label}");
                    }
                }
            }
        }
        $this->assertGreaterThan(3000, $answered);

        foreach ([true, false, null] as $use) {
            self::toggle(TrustedDevice::class, $use);
            $this->assertSame('tok', TrustedDevice::cookiePayloadToken('{"user_id":7,"token":"tok","expires_at":2000}', 7, 1000));
            $this->assertFalse(TrustedDevice::cookieIsValidForUser('{"user_id":7,"token":"tok","expires_at":1}', 7));
            $this->assertFalse(TrustedDevice::cookieIsValidForUser(null, 7));
        }
    }

    /**
     * The returned value, or the class and message of the thrown exception (PHP 8.5 warns
     * on float-to-int casts that are not representable, which Laravel converts to errors).
     *
     * @return array{0: string, 1: mixed}
     */
    private static function outcome(Closure $fn): array
    {
        try {
            return ['value', $fn()];
        } catch (\Throwable $e) {
            return ['throw', $e::class.': '.$e->getMessage()];
        }
    }

    public function test_trusted_device_user_agent_limit_parity(): void
    {
        $agents = [
            null, '', 'Mozilla/5.0', str_repeat('a', 500), str_repeat('a', 501), str_repeat('a', 499).'  b',
            str_repeat('a', 498)."\t\0\x0B\n\r b", str_repeat(' ', 600), str_repeat('é', 300), str_repeat('中', 300),
            str_repeat('a', 499).'é', str_repeat("\xff", 600), str_repeat('a', 2000)."\x0C",
        ];
        for ($seed = 1; $seed <= 100; $seed++) {
            $agents[] = str_repeat('x', 490).self::randomBytes($seed, 20, "ab \t\0\x0B\x0C\r\n");
        }
        foreach ($agents as $agent) {
            $expected = TrustedDevice::limitUserAgentPhp($agent);
            $this->assertSame($agent !== null ? Str::limit($agent, 500, '') : null, $expected);
            $this->assertSame($expected, TrustedDevice::limitUserAgentNative($agent), var_export($agent, true));
            foreach ([true, false, null] as $use) {
                self::toggle(TrustedDevice::class, $use);
                $this->assertSame($expected, TrustedDevice::limitUserAgent($agent));
            }
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI contracts and fallbacks
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_unaligned_overflow_and_buffer_contracts(): void
    {
        $email = RustPipelineLibrary::resolveDomain('search', ValidEmailDomain::FFI_CDEF);
        $this->assertNotNull($email);
        $small = $email->new('char[2]');
        $small[0] = 'Z';
        $this->assertSame(-8, self::ffiCall($email, 'nntmux_email_normalized_domain', 'a@example', 9, $small, 2));
        $this->assertSame('Z', $small[0]);
        $this->assertSame(0, self::ffiCall($email, 'nntmux_email_normalized_domain', 'plain', 5, $small, 2));
        $this->assertSame(-1, self::ffiCall($email, 'nntmux_email_normalized_domain', null, 5, $small, 2));
        $this->assertSame(-1, self::ffiCall($email, 'nntmux_email_domain_block_code', null, 3));
        $this->assertSame(-1, self::ffiCall($email, 'nntmux_email_has_plus_addressing', null, 3));
        $this->assertSame(-1, self::ffiCall($email, 'nntmux_email_has_plus_addressing', 'a+b', PHP_INT_MAX));

        $captcha = RustPipelineLibrary::resolveDomain('search', CaptchaHelper::FFI_CDEF);
        $this->assertNotNull($captcha);
        $this->assertSame(-1, self::ffiCall($captcha, 'nntmux_captcha_enabled', 'turnstile', 9, 8));
        $this->assertSame(-1, self::ffiCall($captcha, 'nntmux_captcha_enabled', null, 9, 2));
        $this->assertSame(-1, self::ffiCall($captcha, 'nntmux_captcha_response_field', null, 9));

        $widget = RustPipelineLibrary::resolveDomain('search', RecaptchaService::FFI_CDEF);
        $this->assertNotNull($widget);
        $lens = $widget->new('size_t[2]');
        $lens[0] = 1;
        $lens[1] = 1;
        $out = $widget->new('char[4]');
        $out[0] = 'Q';
        $this->assertSame(-18, self::ffiCall($widget, 'nntmux_captcha_widget_html', 'ab', 2, $lens, 2, 0, $out, 4));
        $this->assertSame('Q', $out[0]);
        $this->assertSame(-1, self::ffiCall($widget, 'nntmux_captcha_widget_html', 'ab', 2, $lens, 1, 0, $out, 4));
        $this->assertSame(-1, self::ffiCall($widget, 'nntmux_captcha_widget_html', 'ab', 2, $lens, 2, 2, $out, 4));
        $this->assertSame(-1, self::ffiCall($widget, 'nntmux_captcha_widget_html', 'abc', 3, $lens, 2, 0, $out, 4));
        $this->assertSame(-1, self::ffiCall($widget, 'nntmux_captcha_widget_html', 'ab', 2, null, 2, 0, $out, 4));
        $raw = $widget->new('uint8_t[32]');
        $unaligned = $widget->cast('size_t*', FFI::addr($raw[1]));
        $this->assertSame(-1, self::ffiCall($widget, 'nntmux_captcha_widget_html', 'ab', 2, $unaligned, 2, 0, $out, 4));
        $this->assertSame(-1, self::ffiCall($widget, 'nntmux_captcha_verification_outcome', 2, 1, 1, 1));
        $this->assertSame(-1, self::ffiCall($widget, 'nntmux_captcha_verification_outcome', 1, 2, 1, 1));

        $device = RustPipelineLibrary::resolveDomain('search', TrustedDevice::FFI_CDEF);
        $this->assertNotNull($device);
        $hash = $device->new('char[63]');
        $hash[0] = 'H';
        $this->assertSame(-65, self::ffiCall($device, 'nntmux_trusted_device_token_hash', 'a', 1, 'k', 1, $hash, 63));
        $this->assertSame('H', $hash[0]);
        $this->assertSame(-1, self::ffiCall($device, 'nntmux_trusted_device_token_hash', null, 1, 'k', 1, $hash, 63));
        $this->assertSame(-1, self::ffiCall($device, 'nntmux_trusted_device_token_hash', 'a', 1, 'k', 1, null, 64));
        $tok = $device->new('char[2]');
        $tok[0] = 'T';
        $cookie = '{"user_id":7,"token":"tok","expires_at":2000}';
        $this->assertSame(-4, self::ffiCall($device, 'nntmux_trusted_device_cookie_token', $cookie, \strlen($cookie), 7, 1, $tok, 2));
        $this->assertSame('T', $tok[0]);
        $this->assertSame(-1, self::ffiCall($device, 'nntmux_trusted_device_cookie_token', null, 4, 7, 1, $tok, 2));
        $this->assertSame(-1, self::ffiCall($device, 'nntmux_trusted_device_user_agent_limit', 'é', 2, 500, $tok, 2));
        $this->assertSame(-4, self::ffiCall($device, 'nntmux_trusted_device_user_agent_limit', 'abc', 3, 500, $tok, 2));
        $this->assertSame(-1, self::ffiCall($device, 'nntmux_trusted_device_user_agent_limit', null, 3, 500, $tok, 2));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame('example.com', ValidEmailDomain::normalizedDomainNative('a@Example.com'));
        $this->assertSame(ValidEmailDomain::BLOCK_BLACKLIST, ValidEmailDomain::domainBlockCodeNative('yopmail.com'));
        $this->assertTrue(ValidEmailDomain::hasPlusAddressingNative('a+b@x.com'));
        $this->assertTrue(CaptchaHelper::enabledForNative('turnstile', false, true, false));
        $this->assertSame('cf-turnstile-response', CaptchaHelper::responseFieldNameForNative('turnstile'));
        $this->assertSame('<div class="g"></div>', RecaptchaService::renderWidgetNative(['class' => 'g']));
        $this->assertSame('<div class="t"></div>', TurnstileService::renderWidgetNative(['class' => 't']));
        $this->assertTrue(RecaptchaService::verificationSucceededNative(['success' => true]));
        $this->assertSame('rejected', TurnstileService::failureReasonNative(true, []));
        $this->assertSame(hash_hmac('sha256', 't', 'k'), TrustedDevice::hmacTokenHashNative('t', 'k'));
        $this->assertSame('t', TrustedDevice::cookiePayloadTokenNative('{"user_id":1,"token":"t","expires_at":5}', 1, 5));
        $this->assertSame('ua', TrustedDevice::limitUserAgentNative('ua'));
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_EMAIL_CAPTCHA_TRUSTED_DEVICE_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_EMAIL_CAPTCHA_TRUSTED_DEVICE_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longEmail = str_repeat('local.part', 20).'@'.str_repeat('Sub-Domain.', 30).'Example.COM';
        $longDomain = str_repeat('legit-subdomain.', 30).'example.com';
        $recaptchaCommon = ['class' => 'g-recaptcha', 'data-sitekey' => '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'];
        $recaptchaUncommon = $recaptchaCommon + ['data-callback' => 'onSubmit', 'data-expired-callback' => 'onExpired', 'data-x' => str_repeat("<a href='x'>&amp;</a>", 20)];
        $turnstileCommon = ['class' => 'cf-turnstile', 'data-sitekey' => '1x00000000000000000000AA', 'data-theme' => 'auto', 'data-size' => 'normal'];
        $turnstileUncommon = $turnstileCommon + ['data-action' => 'register', 'data-x' => str_repeat("\"é\xff<>", 40)];
        $appKey = 'base64:'.base64_encode(str_repeat("\x33", 32));
        $cookieCommon = json_encode(['user_id' => 42, 'token' => Str::random(64), 'expires_at' => 1893456000]) ?: '';
        $cookieUncommon = json_encode(['token' => Str::random(64), 'meta' => array_fill(0, 30, ['k' => 'v', 'n' => 1.5]), 'user_id' => 42, 'expires_at' => 1893456000]) ?: '';
        $uaCommon = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        $uaUncommon = str_repeat('Mozilla/5.0 (X11; Linux x86_64) ', 40);

        $workloads = [
            'ValidEmailDomain::normalizedDomain' => [
                ValidEmailDomain::class,
                static fn () => ValidEmailDomain::normalizedDomain('someone@Example.com'),
                static fn () => ValidEmailDomain::normalizedDomain($longEmail),
            ],
            'ValidEmailDomain::domainBlockCode' => [
                ValidEmailDomain::class,
                static fn () => ValidEmailDomain::domainBlockCode('gmail.com'),
                static fn () => ValidEmailDomain::domainBlockCode($longDomain),
            ],
            'ValidEmailDomain::hasPlusAddressing' => [
                ValidEmailDomain::class,
                static fn () => ValidEmailDomain::hasPlusAddressing('someone@example.com'),
                static fn () => ValidEmailDomain::hasPlusAddressing($longEmail),
            ],
            'CaptchaHelper::enabledFor' => [
                CaptchaHelper::class,
                static fn () => CaptchaHelper::enabledFor('turnstile', false, true, false),
                static fn () => CaptchaHelper::enabledFor('recaptcha', false, true, true),
            ],
            'CaptchaHelper::responseFieldNameFor' => [
                CaptchaHelper::class,
                static fn () => CaptchaHelper::responseFieldNameFor('turnstile'),
                static fn () => CaptchaHelper::responseFieldNameFor(str_repeat('provider', 20)),
            ],
            'RecaptchaService::renderWidget' => [
                RecaptchaService::class,
                static fn () => RecaptchaService::renderWidget($recaptchaCommon),
                static fn () => RecaptchaService::renderWidget($recaptchaUncommon),
            ],
            'RecaptchaService::verificationSucceeded' => [
                RecaptchaService::class,
                static fn () => RecaptchaService::verificationSucceeded(['success' => true, 'hostname' => 'example.com']),
                static fn () => RecaptchaService::verificationSucceeded('not-json'),
            ],
            'TurnstileService::renderWidget' => [
                TurnstileService::class,
                static fn () => TurnstileService::renderWidget($turnstileCommon),
                static fn () => TurnstileService::renderWidget($turnstileUncommon),
            ],
            'TurnstileService::failureReason' => [
                TurnstileService::class,
                static fn () => TurnstileService::failureReason(true, ['success' => true]),
                static fn () => TurnstileService::failureReason(false, null),
            ],
            'TrustedDevice::hmacTokenHash' => [
                TrustedDevice::class,
                static fn () => TrustedDevice::hmacTokenHash('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', $appKey),
                static fn () => TrustedDevice::hmacTokenHash(str_repeat('t', 2048), str_repeat('k', 200)),
            ],
            'TrustedDevice::cookiePayloadToken' => [
                TrustedDevice::class,
                static fn () => TrustedDevice::cookiePayloadToken($cookieCommon, 42, 1_800_000_000),
                static fn () => TrustedDevice::cookiePayloadToken($cookieUncommon, 42, 1_800_000_000),
            ],
            'TrustedDevice::limitUserAgent' => [
                TrustedDevice::class,
                static fn () => TrustedDevice::limitUserAgent($uaCommon),
                static fn () => TrustedDevice::limitUserAgent($uaUncommon),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::enableRustFfi();
                    $rustOut = self::invoke($fn);
                    $class::disableRustFfi();
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2204 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-39s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-11s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $use) {
            self::toggle($class, $use);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $use) {
                self::toggle($class, $use);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $use][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
