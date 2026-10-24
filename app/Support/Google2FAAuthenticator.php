<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TrustedDevice;
use FFI;
use PragmaRX\Google2FALaravel\Exceptions\InvalidSecretKey;
use PragmaRX\Google2FALaravel\Support\Authenticator;
use Throwable;

class Google2FAAuthenticator extends Authenticator
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_2fa_secret_otp(const char *secret, size_t secret_len, const char *otp, size_t otp_len, char *out, size_t cap);
        C;

    /** `nntmux_2fa_secret_otp()` result for a PHP-`empty()` string secret. */
    private const int FFI_SECRET_EMPTY = -2147483647;

    /** Whitespace removed from one-time passwords: PHP `trim()`'s default characters. */
    private const array OTP_WHITESPACE = [' ', "\t", "\n", "\r", "\0", "\x0B"];

    /**
     * The Rust helper stays opt-in: it missed the 1.5x paired benchmark gate (common 0.29x mean /
     * 0.28x p99, uncommon 2.40x / 1.35x;
     * tests/Unit/RustSecurityForumSupportParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Check if the user is authenticated for 2FA
     */
    public function isAuthenticated()
    {
        // First check - directly check for the cookie before any other logic
        $cookie = request()->cookie('2fa_trusted_device');

        if ($cookie && $this->checkCookieValidity($cookie)) {
            // Force the session to be marked as 2FA authenticated
            $this->loginFromTrustedDevice();

            // Successful authentication with cookie
            return true;
        }

        if ($this->getRequest()->session()->pull('2fa:trusted_device', false)) {
            $this->logout();
        }

        return parent::isAuthenticated();
    }

    public function loginFromTrustedDevice(): void
    {
        $this->login();
        $this->getRequest()->session()->put('2fa:trusted_device', true);
    }

    /**
     * Directly validate the cookie without any output or logging
     */
    private function checkCookieValidity(mixed $cookie): bool
    {
        try {
            return $this->trustedDeviceCookieIsValid($cookie);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function canPassWithoutCheckingOTP(): bool
    {
        if (! $this->getUser()->passwordSecurity) {
            return true;
        }

        return
            ! $this->getUser()->passwordSecurity->google2fa_enable ||
            ! $this->isEnabled() ||
            $this->noUserIsAuthenticated() ||
            $this->twoFactorAuthStillValid() ||
            $this->isDeviceTrusted();
    }

    /**
     * Check if current device is trusted
     */
    protected function isDeviceTrusted(): bool
    {
        try {
            return $this->trustedDeviceCookieIsValid(request()->cookie('2fa_trusted_device'));
        } catch (\Exception $e) {
            // Silently handle any exceptions
            return false;
        }
    }

    /**
     * @return mixed
     *
     * @throws InvalidSecretKey
     */
    protected function getGoogle2FASecretKey()
    {
        $secret = $this->storedGoogle2FASecretKey();
        self::validateSecretAndNormalizeOtp($secret, '');

        return $secret;
    }

    /**
     * Verify the normalized one-time password against the validated secret and store its
     * timestamp.
     *
     * @param  mixed  $one_time_password
     * @return mixed
     *
     * @throws InvalidSecretKey
     */
    protected function verifyAndStoreOneTimePassword($one_time_password)
    {
        $secret = $this->storedGoogle2FASecretKey();
        $otp = self::validateSecretAndNormalizeOtp($secret, $one_time_password);

        return $this->storeOldTimestamp($this->verifyGoogle2FA($secret, $otp));
    }

    private function storedGoogle2FASecretKey(): mixed
    {
        return $this->getUser()->passwordSecurity->{$this->config('otp_secret_column')};
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
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
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
     * Reject a PHP-`empty()` secret and return the one-time password as a string with all
     * PHP `trim()` whitespace removed; a non-scalar password becomes `''`, which never verifies.
     *
     * @throws InvalidSecretKey
     */
    public static function validateSecretAndNormalizeOtp(mixed $secret, mixed $otp): string
    {
        return self::usesRustFfi()
            ? self::validateSecretAndNormalizeOtpNative($secret, $otp)
            : self::validateSecretAndNormalizeOtpPhp($secret, $otp);
    }

    /**
     * @throws InvalidSecretKey
     */
    public static function validateSecretAndNormalizeOtpPhp(mixed $secret, mixed $otp): string
    {
        if (empty($secret)) {
            throw new InvalidSecretKey('Secret key cannot be empty.');
        }

        if (! \is_scalar($otp)) {
            return '';
        }

        return str_replace(self::OTP_WHITESPACE, '', (string) $otp);
    }

    /**
     * @throws InvalidSecretKey
     */
    public static function validateSecretAndNormalizeOtpNative(mixed $secret, mixed $otp): string
    {
        if (\is_int($otp)) {
            $otp = (string) $otp;
        }
        if (! \is_string($secret) || ! \is_string($otp)) {
            return self::validateSecretAndNormalizeOtpPhp($secret, $otp);
        }

        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, \strlen($otp), $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::validateSecretAndNormalizeOtpPhp($secret, $otp);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_2fa_secret_otp($secret, \strlen($secret), $otp, \strlen($otp), $scratch, $cap);
        } catch (Throwable) {
            return self::validateSecretAndNormalizeOtpPhp($secret, $otp);
        }

        if ($written === self::FFI_SECRET_EMPTY) {
            throw new InvalidSecretKey('Secret key cannot be empty.');
        }
        if (! \is_int($written) || $written < 0 || $written > \strlen($otp)) {
            return self::validateSecretAndNormalizeOtpPhp($secret, $otp);
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Override the parent isEnabled method to force-disable 2FA when a trusted device is detected
     */
    public function isEnabled()
    {
        // Check for trusted device cookie
        $trustedCookie = request()->cookie('2fa_trusted_device');

        if ($trustedCookie && auth()->check()) {
            try {
                if ($this->trustedDeviceCookieIsValid($trustedCookie)) {
                    $this->loginFromTrustedDevice();

                    return false;
                }
            } catch (\Exception $e) {
                // Silently handle any exceptions
            }
        }

        // Otherwise, use the parent implementation
        return parent::isEnabled();
    }

    private function trustedDeviceCookieIsValid(mixed $cookie): bool
    {
        return TrustedDevice::cookieIsValidForUser($cookie, (int) $this->getUser()->id);
    }
}
