<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Signs short-lived RS256 tokens that Grafana's [auth.jwt] provider accepts.
 *
 * Grafana keeps no session for JWT logins, so the token has to arrive as the
 * X-JWT-Assertion header on every request. The web server adds it: from the
 * nginx auth_request response in proxy mode, or from the COOKIE in cookie mode.
 */
class GrafanaJwtIssuer
{
    /**
     * Cookie-mode cookie; the installer's Apache config reads it by this name.
     */
    public const string COOKIE = 'nntmux_grafana_jwt';

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_grafana_jwt_base64url_encode(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_grafana_jwt_signing_input(const char *username, size_t username_len, const char *email, size_t email_len, const char *role, size_t role_len, const char *issuer, size_t issuer_len, const char *audience, size_t audience_len, int64_t now_ts, int64_t expires_at, const char *jti, size_t jti_len, uint32_t raw_claims_only, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @return array{token: string, expires_at: int, ttl: int}
     */
    public function issueFor(User $user): array
    {
        $ttl = (int) config('monitoring.grafana.jwt.ttl', 900);
        $now = time();
        $expiresAt = $now + $ttl;

        $signingInput = $this->buildSigningInput(
            (string) $user->username,
            (string) $user->email,
            (string) config('monitoring.grafana.jwt.role', 'Viewer'),
            (string) config('monitoring.grafana.jwt.issuer'),
            (string) config('monitoring.grafana.jwt.audience'),
            $now,
            $expiresAt,
            (string) Str::uuid(),
        );

        if (! openssl_sign($signingInput, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the Grafana token: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return [
            'token' => $signingInput.'.'.$this->base64Url($signature),
            'expires_at' => $expiresAt,
            'ttl' => $ttl,
        ];
    }

    public function hasUsableKey(): bool
    {
        $path = (string) config('monitoring.grafana.jwt.private_key_path');

        return $path !== '' && is_file($path) && is_readable($path);
    }

    public function formatClaimsJson(
        string $username,
        string $email,
        string $role,
        string $issuer,
        string $audience,
        int $now,
        int $expiresAt,
        string $jti,
    ): string {
        return self::$useRustFfi
            ? $this->formatClaimsJsonNative($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti)
            : $this->formatClaimsJsonPhp($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti);
    }

    public function formatClaimsJsonPhp(
        string $username,
        string $email,
        string $role,
        string $issuer,
        string $audience,
        int $now,
        int $expiresAt,
        string $jti,
    ): string {
        $claims = [
            'sub' => $username,
            'email' => $email,
            'name' => $username,
            'role' => $role,
            'iss' => $issuer,
            'aud' => $audience,
            'iat' => $now,
            // Small allowance for clock drift between PHP and Grafana.
            'nbf' => $now - 30,
            'exp' => $expiresAt,
            'jti' => $jti,
        ];

        return json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function formatClaimsJsonNative(
        string $username,
        string $email,
        string $role,
        string $issuer,
        string $audience,
        int $now,
        int $expiresAt,
        string $jti,
    ): string {
        $formatted = $this->callSigningInputFfi($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti, true);

        return $formatted ?? $this->formatClaimsJsonPhp($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti);
    }

    public function buildSigningInput(
        string $username,
        string $email,
        string $role,
        string $issuer,
        string $audience,
        int $now,
        int $expiresAt,
        string $jti,
    ): string {
        return self::$useRustFfi
            ? $this->buildSigningInputNative($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti)
            : $this->buildSigningInputPhp($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti);
    }

    public function buildSigningInputPhp(
        string $username,
        string $email,
        string $role,
        string $issuer,
        string $audience,
        int $now,
        int $expiresAt,
        string $jti,
    ): string {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claimsJson = $this->formatClaimsJsonPhp($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti);

        return $this->encodePhp($header).'.'.$this->base64UrlPhp($claimsJson);
    }

    public function buildSigningInputNative(
        string $username,
        string $email,
        string $role,
        string $issuer,
        string $audience,
        int $now,
        int $expiresAt,
        string $jti,
    ): string {
        $signingInput = $this->callSigningInputFfi($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti, false);

        return $signingInput ?? $this->buildSigningInputPhp($username, $email, $role, $issuer, $audience, $now, $expiresAt, $jti);
    }

    public function base64Url(string $value): string
    {
        return self::$useRustFfi
            ? $this->base64UrlNative($value)
            : $this->base64UrlPhp($value);
    }

    public function base64UrlPhp(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function base64UrlNative(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->base64UrlPhp($value);
        }

        $len = \strlen($value);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, intdiv($len * 4, 3) + 16), $cap);
        if ($scratch === null) {
            return $this->base64UrlPhp($value);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_grafana_jwt_base64url_encode($value, $len, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return $this->base64UrlPhp($value);
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->base64UrlPhp($value);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_grafana_jwt_base64url_encode($value, $len, $scratch, $cap);
            if (! \is_int($written) || $written < 0) {
                return $this->base64UrlPhp($value);
            }
        }

        return $written <= 0 ? '' : FFI::string($scratch, $written);
    }

    private function callSigningInputFfi(
        string $username,
        string $email,
        string $role,
        string $issuer,
        string $audience,
        int $now,
        int $expiresAt,
        string $jti,
        bool $rawClaimsOnly,
    ): ?string {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return null;
        }

        $uLen = \strlen($username);
        $eLen = \strlen($email);
        $rLen = \strlen($role);
        $iLen = \strlen($issuer);
        $aLen = \strlen($audience);
        $jLen = \strlen($jti);
        $needed = ($uLen * 2 + $eLen + $rLen + $iLen + $aLen + $jLen) * 2 + 256;

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(512, $needed), $cap);
        if ($scratch === null) {
            return null;
        }

        $rawFlag = $rawClaimsOnly ? 1 : 0;
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_grafana_jwt_signing_input(
            $username,
            $uLen,
            $email,
            $eLen,
            $role,
            $rLen,
            $issuer,
            $iLen,
            $audience,
            $aLen,
            $now,
            $expiresAt,
            $jti,
            $jLen,
            $rawFlag,
            $scratch,
            $cap,
        );
        if (! \is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_grafana_jwt_signing_input(
                $username,
                $uLen,
                $email,
                $eLen,
                $role,
                $rLen,
                $issuer,
                $iLen,
                $audience,
                $aLen,
                $now,
                $expiresAt,
                $jti,
                $jLen,
                $rawFlag,
                $scratch,
                $cap,
            );
            if (! \is_int($written) || $written <= 0) {
                return null;
            }
        }

        return $written <= 0 ? null : FFI::string($scratch, $written);
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $path = (string) config('monitoring.grafana.jwt.private_key_path');

        if (! $this->hasUsableKey()) {
            throw new RuntimeException("Grafana JWT private key is not readable at [{$path}]. Run `php artisan monitoring:install`.");
        }

        $key = openssl_pkey_get_private((string) file_get_contents($path));

        if ($key === false) {
            throw new RuntimeException("Grafana JWT private key at [{$path}] is not a valid PEM private key.");
        }

        return $key;
    }

    /**
     * @param  array<string, int|string>  $segment
     */
    private function encodePhp(array $segment): string
    {
        return $this->base64UrlPhp(json_encode($segment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
