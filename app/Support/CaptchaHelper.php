<?php

declare(strict_types=1);

namespace App\Support;

use App\Rules\RecaptchaRule;
use App\Rules\TurnstileRule;
use App\Services\RecaptchaService;
use App\Services\TurnstileService;
use FFI;
use Illuminate\Support\Facades\Log;
use Throwable;

class CaptchaHelper
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_captcha_enabled(const char *provider, size_t provider_len, uint32_t flags);
        int32_t nntmux_captcha_response_field(const char *provider, size_t provider_len);
        C;

    /**
     * The Rust helpers stay opt-in: they did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustEmailDomainCaptchaTurnstileTrustedDeviceParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Check if captcha is enabled
     */
    public static function isEnabled(): bool
    {
        $provider = config('captcha.provider', 'recaptcha');

        // Enforce that both providers cannot be enabled at the same time
        $recaptchaEnabled = config('captcha.recaptcha.enabled') === true
            && ! empty(config('captcha.recaptcha.sitekey'))
            && ! empty(config('captcha.recaptcha.secret'));

        $turnstileEnabled = config('captcha.turnstile.enabled') === true
            && ! empty(config('captcha.turnstile.sitekey'))
            && ! empty(config('captcha.turnstile.secret'));

        // If both are configured as enabled, log a warning and use the configured provider
        if ($recaptchaEnabled && $turnstileEnabled) {
            Log::warning('Both reCAPTCHA and Turnstile are enabled. Only one can be active at a time. Using provider: '.$provider);
        }

        $legacyEnabled = config('captcha.enabled') === true
            && ! empty(config('captcha.sitekey'))
            && ! empty(config('captcha.secret'));

        return self::enabledFor(\is_string($provider) ? $provider : '', $recaptchaEnabled, $turnstileEnabled, $legacyEnabled);
    }

    /**
     * Get the current captcha provider
     */
    public static function getProvider(): string
    {
        return config('captcha.provider', 'recaptcha');
    }

    /**
     * Display the captcha widget
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function display(array $attributes = []): string
    {
        if (! self::isEnabled()) {
            return '';
        }

        $provider = self::getProvider();

        if ($provider === 'turnstile') {
            return TurnstileService::display($attributes);
        }

        // Default to reCAPTCHA
        return RecaptchaService::display($attributes);
    }

    /**
     * Render the captcha JavaScript
     */
    public static function renderJs(): string
    {
        if (! self::isEnabled()) {
            return '';
        }

        $provider = self::getProvider();

        if ($provider === 'turnstile') {
            return TurnstileService::renderJs();
        }

        // Default to reCAPTCHA
        return RecaptchaService::renderJs();
    }

    /**
     * Get the captcha response field name
     */
    public static function getResponseFieldName(): string
    {
        return self::responseFieldNameFor(self::getProvider());
    }

    /**
     * Get validation rules for captcha
     *
     * @return array<string, mixed>
     */
    public static function getValidationRules(): array
    {
        if (! self::isEnabled()) {
            return [];
        }

        $provider = self::getProvider();
        $fieldName = self::getResponseFieldName();

        if ($provider === 'turnstile') {
            return [
                $fieldName => [
                    'required',
                    new TurnstileRule,
                ],
            ];
        }

        // Default to reCAPTCHA
        return [
            $fieldName => [
                'required',
                new RecaptchaRule,
            ],
        ];
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Whether the configured provider is usable: Turnstile needs its own keys, while
     * reCAPTCHA (the default) accepts its own or the legacy top-level keys.
     */
    public static function enabledFor(string $provider, bool $recaptchaEnabled, bool $turnstileEnabled, bool $legacyEnabled): bool
    {
        return self::usesRustFfi()
            ? self::enabledForNative($provider, $recaptchaEnabled, $turnstileEnabled, $legacyEnabled)
            : self::enabledForPhp($provider, $recaptchaEnabled, $turnstileEnabled, $legacyEnabled);
    }

    public static function enabledForPhp(string $provider, bool $recaptchaEnabled, bool $turnstileEnabled, bool $legacyEnabled): bool
    {
        if ($provider === 'turnstile') {
            return $turnstileEnabled;
        }

        // Default to reCAPTCHA
        return $recaptchaEnabled || $legacyEnabled;
    }

    public static function enabledForNative(string $provider, bool $recaptchaEnabled, bool $turnstileEnabled, bool $legacyEnabled): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::enabledForPhp($provider, $recaptchaEnabled, $turnstileEnabled, $legacyEnabled);
        }

        $flags = ($recaptchaEnabled ? 1 : 0) | ($turnstileEnabled ? 2 : 0) | ($legacyEnabled ? 4 : 0);
        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_captcha_enabled($provider, \strlen($provider), $flags);
        } catch (Throwable) {
            return self::enabledForPhp($provider, $recaptchaEnabled, $turnstileEnabled, $legacyEnabled);
        }

        return $result === 0 || $result === 1
            ? $result === 1
            : self::enabledForPhp($provider, $recaptchaEnabled, $turnstileEnabled, $legacyEnabled);
    }

    /**
     * The captcha response field name submitted by the provider's widget.
     */
    public static function responseFieldNameFor(string $provider): string
    {
        return self::usesRustFfi()
            ? self::responseFieldNameForNative($provider)
            : self::responseFieldNameForPhp($provider);
    }

    public static function responseFieldNameForPhp(string $provider): string
    {
        if ($provider === 'turnstile') {
            return 'cf-turnstile-response';
        }

        return 'g-recaptcha-response';
    }

    public static function responseFieldNameForNative(string $provider): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::responseFieldNameForPhp($provider);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_captcha_response_field($provider, \strlen($provider));
        } catch (Throwable) {
            return self::responseFieldNameForPhp($provider);
        }

        return match ($result) {
            1 => 'cf-turnstile-response',
            0 => 'g-recaptcha-response',
            default => self::responseFieldNameForPhp($provider),
        };
    }
}
