<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TurnstileService
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
     * Verify Cloudflare Turnstile token
     */
    public static function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = config('captcha.turnstile.secret');

        if (empty($secret)) {
            Log::error('Turnstile verification failed: missing secret.', self::logContext());

            return false;
        }

        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp ?? request()->ip(),
            ]);

            $result = $response->json();
            $reason = self::failureReason($response->successful(), $result);

            if ($reason === null) {
                return true;
            }

            $errorCodes = is_array($result) ? ($result['error-codes'] ?? []) : [];

            Log::warning('Turnstile verification failed.', self::logContext() + [
                'http_status' => $response->status(),
                'error_codes' => is_array($errorCodes) ? array_values(array_filter($errorCodes, 'is_string')) : [],
                'reason' => $reason,
                'siteverify_ray' => $response->header('cf-ray'),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Turnstile verification request failed.', self::logContext() + [
                'exception_class' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Keep credentials, CAPTCHA tokens, and request bodies out of diagnostics.
     *
     * @return array<string, mixed>
     */
    private static function logContext(): array
    {
        return [
            'route' => request()->route()?->getName(),
            'cf_ray' => request()->header('CF-Ray'),
            'ip' => config('nntmux_settings.store_user_ip') ? request()->ip() : null,
        ];
    }

    /**
     * Get the Turnstile HTML widget
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function display(array $attributes = []): string
    {
        $sitekey = config('captcha.turnstile.sitekey');

        if (empty($sitekey)) {
            return '';
        }

        $defaultAttributes = [
            'class' => 'cf-turnstile',
            'data-sitekey' => $sitekey,
            'data-theme' => 'auto',
            'data-size' => 'normal',
        ];

        return self::renderWidget(array_merge($defaultAttributes, $attributes));
    }

    /**
     * Get the Turnstile JavaScript
     */
    public static function renderJs(): string
    {
        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
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
     * Null when siteverify accepted the token, otherwise the logged failure reason
     * (`http-error`, `rejected`, or `invalid-response`).
     */
    public static function failureReason(bool $httpSuccessful, mixed $result): ?string
    {
        return self::usesRustFfi()
            ? self::failureReasonNative($httpSuccessful, $result)
            : self::failureReasonPhp($httpSuccessful, $result);
    }

    public static function failureReasonPhp(bool $httpSuccessful, mixed $result): ?string
    {
        if ($httpSuccessful && is_array($result) && ($result['success'] ?? null) === true) {
            return null;
        }

        return ! $httpSuccessful ? 'http-error' : (is_array($result) ? 'rejected' : 'invalid-response');
    }

    public static function failureReasonNative(bool $httpSuccessful, mixed $result): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::failureReasonPhp($httpSuccessful, $result);
        }

        $isArray = is_array($result);
        try {
            // @phpstan-ignore method.notFound
            $outcome = $ffi->nntmux_captcha_verification_outcome(1, $httpSuccessful ? 1 : 0, $isArray ? 1 : 0, $isArray && ($result['success'] ?? null) === true ? 1 : 0);
        } catch (Throwable) {
            return self::failureReasonPhp($httpSuccessful, $result);
        }

        return match ($outcome) {
            0 => null,
            1 => 'http-error',
            2 => 'rejected',
            3 => 'invalid-response',
            default => self::failureReasonPhp($httpSuccessful, $result),
        };
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
            $attributesString .= sprintf('%s="%s" ', $key, htmlspecialchars($value));
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
            if (! \is_string($value)) {
                // htmlspecialchars() rejects non-string values under strict types.
                return self::renderWidgetPhp($attributes);
            }
            $key = (string) $key;
            $value = $value;
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
            $written = $ffi->nntmux_captcha_widget_html($packed, \strlen($packed), $lens, $count, 1, $scratch, $cap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::renderWidgetPhp($attributes);
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::renderWidgetPhp($attributes);
        }
    }
}
