<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Support\Passkeys\RelyingPartyIdResolver;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction as BaseGeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Serializer;
use Throwable;
use Webauthn\PublicKeyCredentialRequestOptions;

final class GeneratePasskeyAuthenticationOptionsAction extends BaseGeneratePasskeyAuthenticationOptionsAction
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_passkey_auth_rp(const char *rp, size_t len, uint32_t rp_null, char *out, size_t cap);
        int32_t nntmux_passkey_auth_session_key(char *out, size_t cap);
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

    public function execute(): string
    {
        $rpId = self::normalizeAuthenticationRpId(RelyingPartyIdResolver::resolve());

        $options = new PublicKeyCredentialRequestOptions(
            challenge: Str::random(),
            rpId: $rpId,
            allowCredentials: [],
        );

        $options = Serializer::make()->toJson($options);

        Session::put(self::authenticationOptionsSessionKey(), $options);

        return $options;
    }

    public static function normalizeAuthenticationRpId(?string $rpId): string
    {
        return self::$useRustFfi ? self::normalizeAuthenticationRpIdNative($rpId) : self::normalizeAuthenticationRpIdPhp($rpId);
    }

    public static function normalizeAuthenticationRpIdPhp(?string $rpId): string
    {
        return $rpId === null || $rpId === '' ? 'localhost' : $rpId;
    }

    public static function normalizeAuthenticationRpIdNative(?string $rpId): string
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($rpId ?? '') + 16), $cap);
                if ($scratch !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_passkey_auth_rp($rpId ?? '', strlen($rpId ?? ''), (int) ($rpId === null), $scratch, $cap);
                    if (is_int($written) && $written > 0 && $written <= $cap) {
                        return FFI::string($scratch, $written);
                    }
                }
            } catch (Throwable) {
                // Keep normalization available when FFI is unavailable.
            }
        }

        return self::normalizeAuthenticationRpIdPhp($rpId);
    }

    public static function authenticationOptionsSessionKey(): string
    {
        return self::$useRustFfi ? self::authenticationOptionsSessionKeyNative() : self::authenticationOptionsSessionKeyPhp();
    }

    public static function authenticationOptionsSessionKeyPhp(): string
    {
        return 'passkey-authentication-options';
    }

    public static function authenticationOptionsSessionKeyNative(): string
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
                if ($scratch !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_passkey_auth_session_key($scratch, $cap);
                    if (is_int($written) && $written > 0 && $written <= $cap) {
                        return FFI::string($scratch, $written);
                    }
                }
            } catch (Throwable) {
                // Keep the package's session contract if the library is unavailable.
            }
        }

        return self::authenticationOptionsSessionKeyPhp();
    }
}
