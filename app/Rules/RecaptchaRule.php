<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\RecaptchaService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class RecaptchaRule implements ValidationRule
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_captcha_rule_input(uint32_t provider, uint32_t is_string, const char *token, size_t len, int32_t verified);
        C;

    /** Opt-in only; see the paired measurements in RustPasskeyCaptchaRuleParityTest. */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Validate the reCAPTCHA response token.
     *
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {

        $error = self::evaluateRecaptchaInput($value, null);
        if ($error !== null) {
            $fail($error);

            return;
        }

        // A successful presence check guarantees a string without coercing tokens.
        if (is_string($value)) {
            $error = self::evaluateRecaptchaInput($value, RecaptchaService::verify($value));
            if ($error !== null) {
                $fail($error);
            }
        }
    }

    public static function evaluateRecaptchaInput(mixed $value, ?bool $verified): ?string
    {
        return self::$useRustFfi ? self::evaluateRecaptchaInputNative($value, $verified) : self::evaluateRecaptchaInputPhp($value, $verified);
    }

    public static function evaluateRecaptchaInputPhp(mixed $value, ?bool $verified): ?string
    {
        if (! is_string($value) || $value === '') {
            return 'Please complete the captcha verification.';
        }

        return $verified === false ? 'The captcha verification failed. Please try again.' : null;
    }

    public static function evaluateRecaptchaInputNative(mixed $value, ?bool $verified): ?string
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            $isString = is_string($value);
            $token = $isString ? $value : '';
            try {
                // @phpstan-ignore method.notFound
                $status = $ffi->nntmux_captcha_rule_input(0, (int) $isString, $token, strlen($token), $verified === null ? -1 : (int) $verified);

                return match ($status) {
                    0 => null,
                    1 => 'Please complete the captcha verification.',
                    2 => 'The captcha verification failed. Please try again.',
                    default => self::evaluateRecaptchaInputPhp($value, $verified),
                };
            } catch (Throwable) {
                // Provider verification remains in PHP; only pure evaluation is native.
            }
        }

        return self::evaluateRecaptchaInputPhp($value, $verified);
    }
}
