<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class PasswordBreachService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_password_breach_threshold(int64_t threshold, int64_t *out);
        int32_t nntmux_password_breach_should_check(const char *password, size_t len);
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
     * Minimum number of times a password must appear in breaches to be considered compromised.
     */
    protected int $threshold;

    public function __construct(int $threshold = 1)
    {
        $this->threshold = self::normalizeThreshold($threshold);
    }

    /**
     * Check if a password has been found in data breaches.
     *
     * This method uses Laravel's Password validation rule with uncompromised() which queries
     * the Have I Been Pwned API using k-Anonymity (only first 5 chars of SHA-1 hash are sent).
     *
     * @param  string  $password  The password to check
     * @return bool True if the password has been found in breaches, false otherwise
     */
    public function isPasswordBreached(string $password): bool
    {
        if (! self::shouldCheckPassword($password)) {
            return false;
        }

        try {
            $validator = Validator::make(
                ['password' => $password],
                ['password' => Password::min(1)->uncompromised($this->threshold)]
            );

            // If validation fails, the password is compromised
            return $validator->fails();
        } catch (\Exception $e) {
            // Log the error but don't block login (fail open)
            Log::error('Password breach check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Negative counts are normalized to Laravel's zero-tolerance threshold. */
    public static function normalizeThreshold(int $threshold): int
    {
        return self::$useRustFfi ? self::normalizeThresholdNative($threshold) : self::normalizeThresholdPhp($threshold);
    }

    public static function normalizeThresholdPhp(int $threshold): int
    {
        return max(0, $threshold);
    }

    public static function normalizeThresholdNative(int $threshold): int
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                $out = $ffi->new('int64_t[1]');
                // @phpstan-ignore method.notFound
                $status = $ffi->nntmux_password_breach_threshold($threshold, $out);
                if ($status === 0) {
                    return (int) $out[0];
                }
            } catch (Throwable) {
                // Preserve breach-check availability without FFI.
            }
        }

        return self::normalizeThresholdPhp($threshold);
    }

    public static function shouldCheckPassword(string $password): bool
    {
        return self::$useRustFfi ? self::shouldCheckPasswordNative($password) : self::shouldCheckPasswordPhp($password);
    }

    public static function shouldCheckPasswordPhp(string $password): bool
    {
        return $password !== '';
    }

    public static function shouldCheckPasswordNative(string $password): bool
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                // @phpstan-ignore method.notFound
                $status = $ffi->nntmux_password_breach_should_check($password, strlen($password));
                if ($status === 0 || $status === 1) {
                    return $status === 1;
                }
            } catch (Throwable) {
                // Preserve byte-string semantics without FFI.
            }
        }

        return self::shouldCheckPasswordPhp($password);
    }
}
