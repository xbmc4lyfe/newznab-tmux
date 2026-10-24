<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecaptchaService
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_captcha_widget_html(const char *parts, size_t parts_len, const size_t *lens, size_t count, uint32_t mode, char *out, size_t cap);
        int32_t nntmux_captcha_verification_outcome(uint32_t provider, uint32_t http_ok, uint32_t result_is_array, uint32_t success_true);
        C;

    /**
     * The Rust helpers stay opt-in: they did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustEmailDomainCaptchaTurnstileTrustedDeviceParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Verify a Google reCAPTCHA token.
     */
    public static function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = config('captcha.recaptcha.secret', config('captcha.secret'));

        if ($secret === null || $secret === '') {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp ?? request()->ip(),
            ]);

            $result = $response->json();

            return self::verificationSucceeded($result);
        } catch (Throwable $throwable) {
            Log::error('reCAPTCHA verification failed: '.$throwable->getMessage());

            return false;
        }
    }

    /**
     * Render the Google reCAPTCHA widget.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function display(array $attributes = []): string
    {
        $sitekey = config('captcha.recaptcha.sitekey', config('captcha.sitekey'));

        if ($sitekey === null || $sitekey === '') {
            return '';
        }

        $defaultAttributes = [
            'class' => 'g-recaptcha',
            'data-sitekey' => $sitekey,
        ];

        return self::renderWidget(array_merge($defaultAttributes, $attributes));
    }

    /**
     * Render the Google reCAPTCHA JavaScript include.
     */
    public static function renderJs(): string
    {
        return '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
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
     * Whether a decoded siteverify response reports success.
     */
    public static function verificationSucceeded(mixed $result): bool
    {
        return self::usesRustFfi()
            ? self::verificationSucceededNative($result)
            : self::verificationSucceededPhp($result);
    }

    public static function verificationSucceededPhp(mixed $result): bool
    {
        return isset($result['success']) && $result['success'] === true;
    }

    public static function verificationSucceededNative(mixed $result): bool
    {
        $ffi = self::ffi();
        if ($ffi === null || \is_object($result)) {
            return self::verificationSucceededPhp($result);
        }

        $isArray = \is_array($result);
        try {
            // @phpstan-ignore method.notFound
            $outcome = $ffi->nntmux_captcha_verification_outcome(0, 1, $isArray ? 1 : 0, $isArray && ($result['success'] ?? null) === true ? 1 : 0);
        } catch (Throwable) {
            return self::verificationSucceededPhp($result);
        }

        return \is_int($outcome) && $outcome >= 0 && $outcome <= 3
            ? $outcome === 0
            : self::verificationSucceededPhp($result);
    }

    /**
     * Render the widget `<div>` for already merged attributes.
     *
     * @param  array<array-key, mixed>  $attributes
     */
    public static function renderWidget(array $attributes): string
    {
        return self::usesRustFfi()
            ? self::renderWidgetNative($attributes)
            : self::renderWidgetPhp($attributes);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function renderWidgetPhp(array $attributes): string
    {
        $attributesString = '';

        foreach ($attributes as $key => $value) {
            $attributesString .= sprintf('%s="%s" ', $key, htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
        }

        return sprintf('<div %s></div>', trim($attributesString));
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function renderWidgetNative(array $attributes): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::renderWidgetPhp($attributes);
        }

        $packed = '';
        $parts = [];
        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;
            $packed .= $key.$value;
            $parts[] = \strlen($key);
            $parts[] = \strlen($value);
        }

        $count = \count($parts);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 16 + 6 * \strlen($packed) + 4 * $count, $cap);
        if ($scratch === null) {
            return self::renderWidgetPhp($attributes);
        }

        try {
            $lens = $ffi->new('size_t['.max(1, $count).']');
            foreach ($parts as $i => $len) {
                $lens[$i] = $len;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_captcha_widget_html($packed, \strlen($packed), $lens, $count, 0, $scratch, $cap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::renderWidgetPhp($attributes);
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::renderWidgetPhp($attributes);
        }
    }
}
