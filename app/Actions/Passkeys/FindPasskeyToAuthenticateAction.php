<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Support\Passkeys\RelyingPartyIdResolver;
use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction as BaseFindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\CredentialRecordConverter;
use Throwable;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

final class FindPasskeyToAuthenticateAction extends BaseFindPasskeyToAuthenticateAction
{
    private const string FFI_CDEF = 'ptrdiff_t nntmux_w33_passkey_auth_admin_inform_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyAuthAdminInformMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected function determinePublicKeyCredentialSource(
        PublicKeyCredential $publicKeyCredential,
        PublicKeyCredentialRequestOptions $passkeyOptions,
        Passkey $passkey,
    ): ?PublicKeyCredentialSource {
        $configureCeremonyStepManagerFactoryAction = Config::getAction(
            'configure_ceremony_step_manager_factory',
            ConfigureCeremonyStepManagerFactoryAction::class
        );
        $csmFactory = $configureCeremonyStepManagerFactoryAction->execute();
        $requestCsm = $csmFactory->requestCeremony();

        $host = RelyingPartyIdResolver::resolve();

        try {
            $validator = AuthenticatorAssertionResponseValidator::create($requestCsm);

            $publicKeyCredentialSource = $validator->check(
                $passkey->data,
                $publicKeyCredential->response,
                $passkeyOptions,
                $host,
                null,
            );
        } catch (Throwable) {
            return null;
        }

        return CredentialRecordConverter::toPublicKeyCredentialSource($publicKeyCredentialSource);
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Unpadded base64url form of a raw WebAuthn credential id plus its byte length. Ids are
     * valid between 16 (minimum authenticator entropy) and 1023 bytes (WebAuthn maximum).
     * The stored lookup encoding is unchanged; this is a diagnostic/log-safe normalization.
     *
     * @return array{credential_id: string, byte_length: int, valid: bool}
     */
    public static function normalizeCredentialLookupId(string $rawCredentialId): array
    {
        return self::usesRustFfi()
            ? self::normalizeCredentialLookupIdNative($rawCredentialId)
            : self::normalizeCredentialLookupIdPhp($rawCredentialId);
    }

    /**
     * @return array{credential_id: string, byte_length: int, valid: bool}
     */
    public static function normalizeCredentialLookupIdPhp(string $rawCredentialId): array
    {
        $length = strlen($rawCredentialId);

        return [
            'credential_id' => rtrim(strtr(base64_encode($rawCredentialId), '+/', '-_'), '='),
            'byte_length' => $length,
            'valid' => $length >= 16 && $length <= 1023,
        ];
    }

    /**
     * @return array{credential_id: string, byte_length: int, valid: bool}
     */
    public static function normalizeCredentialLookupIdNative(string $rawCredentialId): array
    {
        $result = self::nativePacket(1, [$rawCredentialId]);
        if ($result === null || count($result) !== 3) {
            return self::normalizeCredentialLookupIdPhp($rawCredentialId);
        }

        return [
            'credential_id' => $result[0],
            'byte_length' => (int) $result[1],
            'valid' => $result[2] === '1',
        ];
    }

    /**
     * Count- and length-prefixed (little-endian u32) packets preserve PHP byte strings.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                $input .= pack('V', strlen($item)).$item;
            }
            if (strlen($input) > 16777216) {
                return null;
            }

            $cap = strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $out = $ffi->new("char[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = (int) $ffi->nntmux_w33_passkey_auth_admin_inform_mcp($mode, $input, strlen($input), $out, $cap);
                if ($written >= 4 && $written <= $cap) {
                    return self::unpackNativePacket(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $cap) {
                    return null;
                }
                $cap = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function unpackNativePacket(string $packet): ?array
    {
        $length = strlen($packet);
        $header = unpack('V', $packet);
        $count = is_array($header) ? $header[1] : null;
        if (! is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $prefix = unpack('V', $packet, $offset);
            $size = is_array($prefix) ? $prefix[1] : null;
            $offset += 4;
            if (! is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search',
                self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
