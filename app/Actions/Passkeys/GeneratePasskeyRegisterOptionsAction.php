<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Support\Passkeys\RelyingPartyIdResolver;
use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction as BaseGeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Support\Config;
use Throwable;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;

final class GeneratePasskeyRegisterOptionsAction extends BaseGeneratePasskeyRegisterOptionsAction
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_passkey_augment_register_json(const char *input, size_t len, const char *hints, size_t hints_len, uint32_t cred_props, char *out, size_t cap);
        int32_t nntmux_passkey_selection(const char *attachment, size_t attachment_len, uint32_t attachment_null, const char *uv, size_t uv_len, const char *rk, size_t rk_len, uint32_t rk_null, char *out, size_t cap);
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
     * WebAuthn algorithms we tell the browser we accept. Order matters:
     * the authenticator will pick the first algorithm it supports.
     *  -7   = ES256 (used by most FIDO2 hardware keys, Apple, Android)
     *  -257 = RS256 (used by Windows Hello / TPM-backed platform authenticators)
     *  -8   = EdDSA (used by some modern security keys & password managers)
     */
    private const SUPPORTED_ALGORITHMS = [
        ['type' => 'public-key', 'alg' => -7],
        ['type' => 'public-key', 'alg' => -257],
        ['type' => 'public-key', 'alg' => -8],
    ];

    public function execute(
        HasPasskeys $authenticatable,
        bool $asJson = true,
    ): string|PublicKeyCredentialCreationOptions {
        $options = parent::execute($authenticatable, $asJson);

        if (! $asJson || ! is_string($options)) {
            return $options;
        }

        return self::augmentRegisterOptionsJson(
            $options,
            (array) config('passkeys.hints', []),
            (bool) config('passkeys.request_cred_props_extension', true),
        );
    }

    /** @param array<array-key, mixed> $hints */
    public static function augmentRegisterOptionsJson(string $optionsJson, array $hints, bool $requestCredProps): string
    {
        return self::$useRustFfi
            ? self::augmentRegisterOptionsJsonNative($optionsJson, $hints, $requestCredProps)
            : self::augmentRegisterOptionsJsonPhp($optionsJson, $hints, $requestCredProps);
    }

    /** @param array<array-key, mixed> $hints */
    public static function augmentRegisterOptionsJsonPhp(string $optionsJson, array $hints, bool $requestCredProps): string
    {
        $decoded = json_decode($optionsJson, true);
        if (! is_array($decoded)) {
            return $optionsJson;
        }

        // Different serializer/browser integrations can use either shape:
        // - options.pubKeyCredParams (WebAuthn JSON)
        // - options.publicKey.pubKeyCredParams (navigator.credentials.create payload)
        // Enforce valid algorithms (including RS256 for Windows Hello) for both
        // to prevent "alg undefined" errors and to allow Windows TPM-backed
        // platform authenticators to participate.
        $decoded['pubKeyCredParams'] = self::SUPPORTED_ALGORITHMS;

        // WebAuthn L3 hints help the browser show a richer chooser including
        // Windows Hello (client-device), phones (hybrid) and security keys.
        $hints = array_values(array_filter($hints));
        if ($hints !== []) {
            $decoded['hints'] = $hints;
        }

        // Request the credProps extension so we know whether a discoverable
        // (resident) key was actually created by the authenticator.
        if ($requestCredProps) {
            $decoded['extensions'] = array_merge(
                (array) ($decoded['extensions'] ?? []),
                ['credProps' => true],
            );
        }

        if (isset($decoded['publicKey']) && is_array($decoded['publicKey'])) {
            $decoded['publicKey']['pubKeyCredParams'] = self::SUPPORTED_ALGORITHMS;

            if ($hints !== []) {
                $decoded['publicKey']['hints'] = $hints;
            }

            if ($requestCredProps) {
                $decoded['publicKey']['extensions'] = array_merge(
                    (array) ($decoded['publicKey']['extensions'] ?? []),
                    ['credProps' => true],
                );
            }
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    /**
     * Unsupported PHP JSON shapes decline to the exact PHP implementation.
     *
     * @param  array<array-key, mixed>  $hints
     */
    public static function augmentRegisterOptionsJsonNative(string $optionsJson, array $hints, bool $requestCredProps): string
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                $hintsJson = json_encode(array_values(array_filter($hints)), JSON_THROW_ON_ERROR);
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($optionsJson) + strlen($hintsJson) * 2 + 512), $cap);
                if ($scratch !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_passkey_augment_register_json($optionsJson, strlen($optionsJson), $hintsJson, strlen($hintsJson), (int) $requestCredProps, $scratch, $cap);
                    if (is_int($written) && $written < 0 && $written !== -2147483648) {
                        $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                        if ($scratch !== null) {
                            // @phpstan-ignore method.notFound
                            $written = $ffi->nntmux_passkey_augment_register_json($optionsJson, strlen($optionsJson), $hintsJson, strlen($hintsJson), (int) $requestCredProps, $scratch, $cap);
                        }
                    }
                    if ($scratch !== null && is_int($written) && $written >= 0 && $written <= $cap) {
                        return $written === 0 ? '' : FFI::string($scratch, $written);
                    }
                }
            } catch (Throwable) {
                // Re-run PHP outside this catch so JSON_THROW_ON_ERROR is preserved.
            }
        }

        return self::augmentRegisterOptionsJsonPhp($optionsJson, $hints, $requestCredProps);
    }

    /**
     * Override the default selection criteria so that:
     *  - both platform authenticators (Windows Hello, Touch ID, password
     *    managers) and roaming/cross-platform FIDO2 security keys are offered;
     *  - a resident key is "preferred" rather than "required" — Windows
     *    domain-joined machines frequently refuse to expose Windows Hello when
     *    `required` is requested, which is why those users only saw the
     *    hardware-key dialog.
     */
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        $attachment = config('passkeys.authenticator_selection.authenticator_attachment');
        $userVerification = (string) config(
            'passkeys.authenticator_selection.user_verification',
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        );
        $residentKey = config(
            'passkeys.authenticator_selection.resident_key',
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        );

        [$attachment, $userVerification, $residentKey] = self::normalizeAuthenticatorSelection(
            is_string($attachment) ? $attachment : null,
            $userVerification,
            $residentKey === null || is_string($residentKey) ? $residentKey : '',
        );

        return new AuthenticatorSelectionCriteria(
            authenticatorAttachment: $attachment,
            userVerification: $userVerification,
            residentKey: $residentKey,
        );
    }

    /** @return array{?string, string, ?string} */
    public static function normalizeAuthenticatorSelection(?string $attachment, string $userVerification, ?string $residentKey): array
    {
        return self::$useRustFfi
            ? self::normalizeAuthenticatorSelectionNative($attachment, $userVerification, $residentKey)
            : self::normalizeAuthenticatorSelectionPhp($attachment, $userVerification, $residentKey);
    }

    /** @return array{?string, string, ?string} */
    public static function normalizeAuthenticatorSelectionPhp(?string $attachment, string $userVerification, ?string $residentKey): array
    {
        if (! in_array($attachment, AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENTS, true)) {
            $attachment = AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE;
        }

        if (! in_array($userVerification, AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENTS, true)) {
            $userVerification = AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED;
        }

        if (! in_array($residentKey, AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENTS, true)) {
            $residentKey = AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED;
        }

        return [$attachment, $userVerification, $residentKey];
    }

    /** @return array{?string, string, ?string} */
    public static function normalizeAuthenticatorSelectionNative(?string $attachment, string $userVerification, ?string $residentKey): array
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
                if ($scratch !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_passkey_selection($attachment ?? '', strlen($attachment ?? ''), (int) ($attachment === null), $userVerification, strlen($userVerification), $residentKey ?? '', strlen($residentKey ?? ''), (int) ($residentKey === null), $scratch, $cap);
                    if (is_int($written) && $written > 0 && $written <= $cap) {
                        $result = json_decode(FFI::string($scratch, $written), true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($result) && count($result) === 3 && in_array($result[0] ?? null, AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENTS, true) && in_array($result[1] ?? null, AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENTS, true) && in_array($result[2] ?? null, AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENTS, true)) {
                            return [$result[0], $result[1], $result[2]];
                        }
                    }
                }
            } catch (Throwable) {
                // Unavailable or incompatible libraries retain the PHP criteria.
            }
        }

        return self::normalizeAuthenticatorSelectionPhp($attachment, $userVerification, $residentKey);
    }

    protected function relatedPartyEntity(): PublicKeyCredentialRpEntity
    {
        $rpId = RelyingPartyIdResolver::resolve();

        return new PublicKeyCredentialRpEntity(
            name: (string) Config::getRelyingPartyName(),
            id: $rpId,
            icon: Config::getRelyingPartyIcon(),
        );
    }
}
