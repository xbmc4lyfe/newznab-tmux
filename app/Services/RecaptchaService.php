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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W48 parity test). */
    public const bool W48_ASSESSMENT_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_recaptcha_assess_batch(const char *bodies, size_t bodies_len, size_t count, double threshold, const char *action, size_t action_len, const char *hostname, size_t hostname_len, char *out, size_t cap);
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

    private static ?bool $w48AssessmentRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark default; enableRustFfi() is untouched. */
    public static function useW48AssessmentRustFfi(?bool $enabled = true): void
    {
        self::$w48AssessmentRustFfi = $enabled;
    }

    public static function usesW48AssessmentRustFfi(): bool
    {
        return self::$w48AssessmentRustFfi ?? self::W48_ASSESSMENT_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW48AssessmentRustFfi(): void
    {
        self::$w48AssessmentRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Batch v2/v3 siteverify assessment. A body with a top-level `score` key is v3. Two words per
     * item: `outcome | errorMask << 4 | version << 12 | hostnameMatch << 14 | actionMatch << 15 |
     * errorCodes << 16 | decodedArray << 24 | success << 25 | hasHostname << 26 | hasAction << 27`,
     * then `floor(score * 1e6)` for a numeric 0..1 score, -1 without a score or -2 for any
     * other score. Outcomes: 0 accepted, 1 body not a JSON array/object, 2 `success !== true`,
     * 6 invalid v3 score, 3 v3 score below $threshold, 4 v3 action differs from a non-empty
     * expectation, 5 hostname differs (case-insensitively) from a non-empty expectation. A
     * final word holds the FNV-1a 64 digest of the item words.
     *
     * @param  list<string>  $bodies  Raw siteverify response bodies.
     * @return list<int>
     */
    public static function assessResponses(array $bodies, float $threshold, string $expectedAction = '', string $expectedHostname = ''): array
    {
        return self::usesW48AssessmentRustFfi()
            ? self::assessResponsesNative($bodies, $threshold, $expectedAction, $expectedHostname)
            : self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
    }

    /**
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function assessResponsesPhp(array $bodies, float $threshold, string $expectedAction = '', string $expectedHostname = ''): array
    {
        $host = strtolower($expectedHostname);
        $out = [];
        foreach ($bodies as $body) {
            $result = json_decode($body, true);
            $isArray = \is_array($result);
            $success = $isArray && ($result['success'] ?? null) === true;
            $v3 = $isArray && \array_key_exists('score', $result);
            $score = $v3 ? $result['score'] : null;
            $micros = ! $v3 ? -1 : ((\is_int($score) || \is_float($score)) && $score >= 0 && $score <= 1 ? (int) floor($score * 1e6) : -2);
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
            $outcome = match (true) {
                ! $isArray => 1,
                ! $success => 2,
                $v3 && $micros < 0 => 6,
                $v3 && $score < $threshold => 3,
                $v3 && $expectedAction !== '' && ! $actionMatch => 4,
                $host !== '' && ! $hostMatch => 5,
                default => 0,
            };
            $out[] = $outcome | $mask << 4 | ($v3 ? 2 : 1) << 12 | (int) $hostMatch << 14 | (int) $actionMatch << 15
                | min(255, $codes) << 16 | (int) $isArray << 24 | (int) $success << 25 | (int) $hasHost << 26
                | (int) $hasAction << 27;
            $out[] = $micros;
        }
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a body holds a NUL byte.
     *
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function assessResponsesNative(array $bodies, float $threshold, string $expectedAction = '', string $expectedHostname = ''): array
    {
        $count = \count($bodies);
        $ffi = $count === 0 ? null : (self::$w48Ffi instanceof FFI ? self::$w48Ffi : self::w48Ffi());
        $packed = implode("\0", $bodies);
        if ($ffi === null || substr_count($packed, "\0") !== $count - 1) {
            return self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
        }
        $bytes = ($count * 2 + 1) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_recaptcha_assess_batch($packed, \strlen($packed), $count, $threshold, $expectedAction, \strlen($expectedAction), $expectedHostname, \strlen($expectedHostname), $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
    }

    private static function w48Ffi(): ?FFI
    {
        self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w48', self::W48_FFI_CDEF) ?? false;

        return self::$w48Ffi === false ? null : self::$w48Ffi;
    }
}
