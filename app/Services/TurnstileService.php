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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W48 parity test). */
    public const bool W48_SITEVERIFY_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_turnstile_siteverify_batch(const char *tokens, size_t tokens_len, const char *bodies, size_t bodies_len, size_t count, const char *hostname, size_t hostname_len, const char *action, size_t action_len, char *out, size_t cap);
        C;

    /** Siteverify `error-codes` bit indexes; any other string sets bit 7. */
    public const array W48_ERROR_CODES = [
        'missing-input-secret' => 0,
        'invalid-input-secret' => 1,
        'missing-input-response' => 2,
        'invalid-input-response' => 3,
        'bad-request' => 4,
        'timeout-or-duplicate' => 5,
        'internal-error' => 6,
    ];

    private const string W48_TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

    private static ?bool $w48SiteverifyRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark default; enableRustFfi() is untouched. */
    public static function useW48SiteverifyRustFfi(?bool $enabled = true): void
    {
        self::$w48SiteverifyRustFfi = $enabled;
    }

    public static function usesW48SiteverifyRustFfi(): bool
    {
        return self::$w48SiteverifyRustFfi ?? self::W48_SITEVERIFY_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW48SiteverifyRustFfi(): void
    {
        self::$w48SiteverifyRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Batch token-format and siteverify-body evaluation. Two words per item: `outcome |
     * errorMask << 4 | hasHostname << 12 | hostnameMatch << 13 | hasAction << 14 |
     * actionMatch << 15 | tokenLength << 16 | errorCodes << 28 | decodedArray << 36 |
     * success << 37`, then crc32 of the lowercase hostname or -1. Outcomes: 0 accepted,
     * 1 malformed token (1..2048 bytes of `[A-Za-z0-9_.-]`), 2 body not a JSON array/object,
     * 3 `success !== true`, 4 hostname differs (case-insensitively) from a non-empty
     * expectation, 5 action differs from a non-empty expectation. A final word holds the
     * FNV-1a 64 digest of the item words.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $bodies  Raw siteverify response bodies, parallel to $tokens.
     * @return list<int>
     */
    public static function evaluateSiteverify(array $tokens, array $bodies, string $expectedHostname = '', string $expectedAction = ''): array
    {
        return self::usesW48SiteverifyRustFfi()
            ? self::evaluateSiteverifyNative($tokens, $bodies, $expectedHostname, $expectedAction)
            : self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function evaluateSiteverifyPhp(array $tokens, array $bodies, string $expectedHostname = '', string $expectedAction = ''): array
    {
        $host = strtolower($expectedHostname);
        $out = [];
        foreach ($tokens as $i => $token) {
            $result = json_decode($bodies[$i] ?? '', true);
            $isArray = \is_array($result);
            $success = $isArray && ($result['success'] ?? null) === true;
            $hostname = $isArray ? ($result['hostname'] ?? null) : null;
            $action = $isArray ? ($result['action'] ?? null) : null;
            $hasHost = \is_string($hostname);
            $hasAction = \is_string($action);
            $hostMatch = $hasHost && $host !== '' && strtolower($hostname) === $host;
            $actionMatch = $hasAction && $action === $expectedAction;
            $mask = $codes = 0;
            $errors = $isArray ? ($result['error-codes'] ?? null) : null;
            if (\is_array($errors)) {
                foreach ($errors as $code) {
                    if (\is_string($code)) {
                        $codes++;
                        $mask |= 1 << (self::W48_ERROR_CODES[$code] ?? 7);
                    }
                }
            }
            $length = \strlen($token);
            $outcome = match (true) {
                $length === 0 || $length > 2048 || strspn($token, self::W48_TOKEN_CHARS) !== $length => 1,
                ! $isArray => 2,
                ! $success => 3,
                $host !== '' && ! $hostMatch => 4,
                $expectedAction !== '' && ! $actionMatch => 5,
                default => 0,
            };
            $out[] = $outcome | $mask << 4 | (int) $hasHost << 12 | (int) $hostMatch << 13 | (int) $hasAction << 14
                | (int) $actionMatch << 15 | min(4095, $length) << 16 | min(255, $codes) << 28 | (int) $isArray << 36
                | (int) $success << 37;
            $out[] = $hasHost ? crc32(strtolower($hostname)) : -1;
        }
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a value holds a NUL byte.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function evaluateSiteverifyNative(array $tokens, array $bodies, string $expectedHostname = '', string $expectedAction = ''): array
    {
        $count = \count($tokens);
        $ffi = $count === 0 ? null : (self::$w48Ffi instanceof FFI ? self::$w48Ffi : self::w48Ffi());
        $packedTokens = implode("\0", $tokens);
        $packedBodies = implode("\0", $bodies);
        if ($ffi === null || \count($bodies) !== $count
            || substr_count($packedTokens, "\0") !== $count - 1 || substr_count($packedBodies, "\0") !== $count - 1) {
            return self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
        }
        $bytes = ($count * 2 + 1) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_turnstile_siteverify_batch($packedTokens, \strlen($packedTokens), $packedBodies, \strlen($packedBodies), $count, $expectedHostname, \strlen($expectedHostname), $expectedAction, \strlen($expectedAction), $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
    }

    private static function w48Ffi(): ?FFI
    {
        self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w48', self::W48_FFI_CDEF) ?? false;

        return self::$w48Ffi === false ? null : self::$w48Ffi;
    }
}
