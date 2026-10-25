<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

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

    public const string W49_FFI_CDEF = 'int64_t nntmux_w49_grafana_jwt_signing_digest(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Rust: ~1.9-2.2x mean, p99 >=1.5x in 8 of 10 strict runs; see tests/Unit/RustMonitoringBoundedMetricFamilyExporterSnapshotJwtW49ParityTest.php. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    private static ?bool $w49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

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

    public static function usesW49RustFfi(): bool
    {
        return self::$w49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49.5 batch helper routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w49RustFfi;
        self::$w49RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w49RustFfi = $previous;
        }
    }

    /**
     * Digests many tokens' signing inputs. Per `[username, email, role, issuer, audience, now, expires_at,
     * jti]` token (the {@see buildSigningInputPhp()} arguments): `[status, claims_len, signing_input_len,
     * signing_input_xxh64]`; status -1 (other fields 0) when the claims cannot be JSON-encoded.
     *
     * @param  list<array{string, string, string, string, string, int, int, string}>  $tokens
     * @return list<list<int>>
     */
    public static function signingDigestBatch(array $tokens): array
    {
        return self::usesW49RustFfi() ? self::signingDigestBatchNative($tokens) : self::signingDigestBatchPhp($tokens);
    }

    /**
     * @param  list<array{string, string, string, string, string, int, int, string}>  $tokens
     * @return list<list<int>>
     */
    public static function signingDigestBatchPhp(array $tokens): array
    {
        $issuer = new self;
        $header = $issuer->encodePhp(['alg' => 'RS256', 'typ' => 'JWT']).'.';
        $rows = [];

        foreach ($tokens as [$username, $email, $role, $iss, $audience, $now, $expiresAt, $jti]) {
            try {
                $claims = $issuer->formatClaimsJsonPhp($username, $email, $role, $iss, $audience, $now, $expiresAt, $jti);
            } catch (JsonException) {
                $rows[] = [-1, 0, 0, 0];

                continue;
            }
            $signingInput = $header.$issuer->base64UrlPhp($claims);
            /** @var array{1: int} $hash */
            $hash = unpack('J', hash('xxh64', $signingInput, true));
            $rows[] = [0, \strlen($claims), \strlen($signingInput), $hash[1]];
        }

        return $rows;
    }

    /**
     * @param  list<array{string, string, string, string, string, int, int, string}>  $tokens
     * @return list<list<int>>
     */
    public static function signingDigestBatchNative(array $tokens): array
    {
        $count = \count($tokens);
        if ($count === 0) {
            return [];
        }

        // NUL-delimited columns (usernames, emails, roles, issuers, audiences, jtis); Rust declines a value
        // holding NUL (the field count no longer matches) or an `nbf` overflow.
        $data = implode("\0", array_merge(
            array_column($tokens, 0),
            array_column($tokens, 1),
            array_column($tokens, 2),
            array_column($tokens, 3),
            array_column($tokens, 4),
            array_column($tokens, 7),
        ));
        $times = pack('P*', ...array_column($tokens, 5), ...array_column($tokens, 6));
        $words = self::w49Words($count, $data, $times, 4 * $count);

        return $words === null ? self::signingDigestBatchPhp($tokens) : array_chunk($words, 4);
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

    /**
     * Exactly $words output words from the Wave 49.5 Rust helper, or null to fall back to PHP.
     *
     * @return array<int, int>|null
     */
    private static function w49Words(int $count, string $data, string $ints, int $words): ?array
    {
        if (self::$w49Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w49Ffi = RustPipelineLibrary::resolveDomain('search_w49', self::W49_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w49Out = null;
            self::$w49OutCap = 0;
        }
        $ffi = self::$w49Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w49Out === null || $words > self::$w49OutCap) {
                $cap = max(1024, $words);
                self::$w49Out = $ffi->new('int64_t['.$cap.']');
                self::$w49OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_grafana_jwt_signing_digest($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w49Out, self::$w49OutCap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w49Out, $words * 8));

            return \is_array($values) ? $values : null;
        } catch (Throwable) {
            return null;
        }
    }
}
