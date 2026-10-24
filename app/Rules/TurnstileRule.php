<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\TurnstileService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

class TurnstileRule implements ValidationRule
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
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {

        $error = self::evaluateTurnstileInput($value, null);
        if ($error !== null) {
            $fail($error);

            return;
        }

        // A successful presence check guarantees a string without coercing tokens.
        if (is_string($value)) {
            $error = self::evaluateTurnstileInput($value, TurnstileService::verify($value));
            if ($error !== null) {
                $fail($error);
            }
        }
    }

    public static function evaluateTurnstileInput(mixed $value, ?bool $verified): ?string
    {
        return self::$useRustFfi ? self::evaluateTurnstileInputNative($value, $verified) : self::evaluateTurnstileInputPhp($value, $verified);
    }

    public static function evaluateTurnstileInputPhp(mixed $value, ?bool $verified): ?string
    {
        if (! is_string($value) || $value === '') {
            return 'Please complete the Cloudflare human verification before signing in.';
        }

        return $verified === false ? 'Cloudflare human verification could not be confirmed. Please reload the page, complete the verification, and try again.' : null;
    }

    public static function evaluateTurnstileInputNative(mixed $value, ?bool $verified): ?string
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            $isString = is_string($value);
            $token = $isString ? $value : '';
            try {
                // @phpstan-ignore method.notFound
                $status = $ffi->nntmux_captcha_rule_input(1, (int) $isString, $token, strlen($token), $verified === null ? -1 : (int) $verified);

                return match ($status) {
                    0 => null,
                    1 => 'Please complete the Cloudflare human verification before signing in.',
                    2 => 'Cloudflare human verification could not be confirmed. Please reload the page, complete the verification, and try again.',
                    default => self::evaluateTurnstileInputPhp($value, $verified),
                };
            } catch (Throwable) {
                // Provider verification remains in PHP; only pure evaluation is native.
            }
        }

        return self::evaluateTurnstileInputPhp($value, $verified);
    }
}
