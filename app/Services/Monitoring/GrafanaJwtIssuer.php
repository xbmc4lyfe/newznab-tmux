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

    /**
     * Enabled: the paired benchmark in the W60 parity test measures 5.76x common mean, 4.829x
     * common p99, 4.484x uncommon mean and 2.386x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_GRAFANA_JWT_FFI_CDEF = 'ssize_t nntmux_w601_grafana_jwt_claim_plan_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W60_CLAIM_WORDS = 7;

    public const int W60_ROLE_EXACT = 8;

    public const int W60_SUB_VALID = 16;

    public const int W60_EMAIL_EMPTY = 32;

    public const int W60_EMAIL_VALID = 64;

    public const int W60_ORG_DEFAULTED = 128;

    public const int W60_ORG_INVALID = 256;

    public const int W60_TTL_DEFAULTED = 512;

    public const int W60_TTL_CLAMPED = 1024;

    public const int W60_SKEW_CLAMPED = 2048;

    public const int W60_PRIOR_NONE = 4096;

    public const int W60_PRIOR_ACTIVE = 8192;

    public const int W60_PRIOR_EXPIRED = 16384;

    public const int W60_PRIOR_NOT_YET = 32768;

    public const int W60_REFRESH = 65536;

    public const int W60_CLAIM_SAFE = 131072;

    public const int W60_ISSUABLE = 262144;

    public const int W60_MALFORMED = 524288;

    private const array W60_ROLES = ['none' => 1, 'viewer' => 2, 'editor' => 3, 'admin' => 4];

    private const array W60_ROLE_NAMES = ['None', 'Viewer', 'Editor', 'Admin'];

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 60 batch routed to Rust or PHP, then restores the routing. */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function jwtClaimPlanBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::jwtClaimPlanBatchNative($rows) : self::jwtClaimPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function jwtClaimPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W60_CLAIM_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($buffer === null) {
            return self::jwtClaimPlanBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound (Declared by W60_GRAFANA_JWT_FFI_CDEF.)
            $written = $ffi->nntmux_w601_grafana_jwt_claim_plan_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::jwtClaimPlanBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($result);
    }

    /**
     * Seven words per `sub\temail\trole\torg\tttl\tnow\tleeway\tprior_iat` row: the role code
     * (0 unknown, 1 None, 2 Viewer, 3 Editor, 4 Admin; trimmed, case-insensitive) with the
     * exact-role, subject (`[A-Za-z0-9._@+-]{1,255}`), empty/valid email, org defaulted (empty
     * is 1) or invalid (not a positive strict integer, 0), ttl defaulted (900) or clamped
     * (60-86400), skew clamped (0-300), prior token none (iat 0), active, expired or not yet
     * valid, refresh (no active prior token or less than a fifth of the ttl left), claim-safe
     * (sub, email and role are printable ASCII without `"` or `\`) and issuable flags; then the
     * org, ttl, nbf (now - skew), exp (now + ttl), prior seconds left and the claims JSON length
     * (-1 unless claim-safe). A malformed row yields `[W60_MALFORMED, 0, 0, 0, 0, 0, 0]`.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function jwtClaimPlanBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $f = explode("\t", $row);
            $ints = [];
            if (\count($f) === 8) {
                for ($i = 4; $i <= 7 && preg_match('/\A-?[0-9]{1,18}\z/', $f[$i]) === 1; $i++) {
                    $ints[] = (int) $f[$i];
                }
            }
            if (\count($ints) !== 4) {
                array_push($words, self::W60_MALFORMED, 0, 0, 0, 0, 0, 0);

                continue;
            }
            [$sub, $email, $role, $org] = $f;
            [$ttl, $now, $leeway, $iat] = $ints;
            $code = self::W60_ROLES[strtolower(trim($role))] ?? 0;
            $subValid = preg_match('/\A[A-Za-z0-9._@+\-]{1,255}\z/', $sub) === 1;
            $emailValid = $email !== '' && preg_match('/\A[^@ \t\n\r\x0b\x0c]+@[^@ \t\n\r\x0b\x0c]+\.[^@ \t\n\r\x0b\x0c]+\z/', $email) === 1;
            $flags = $code
                | (\in_array($role, self::W60_ROLE_NAMES, true) ? self::W60_ROLE_EXACT : 0)
                | ($subValid ? self::W60_SUB_VALID : 0)
                | ($email === '' ? self::W60_EMAIL_EMPTY : 0)
                | ($emailValid ? self::W60_EMAIL_VALID : 0);
            if ($org === '') {
                $org = 1;
                $flags |= self::W60_ORG_DEFAULTED;
            } elseif (preg_match('/\A-?[0-9]{1,18}\z/', $org) === 1 && (int) $org >= 1) {
                $org = (int) $org;
            } else {
                $org = 0;
                $flags |= self::W60_ORG_INVALID;
            }
            if ($ttl <= 0) {
                $ttl = 900;
                $flags |= self::W60_TTL_DEFAULTED;
            } elseif ($ttl < 60 || $ttl > 86400) {
                $ttl = max(60, min(86400, $ttl));
                $flags |= self::W60_TTL_CLAMPED;
            }
            $skew = max(0, min(300, $leeway));
            $flags |= $skew !== $leeway ? self::W60_SKEW_CLAMPED : 0;
            $nbf = $now - $skew;
            $exp = $now + $ttl;
            $remaining = 0;
            if ($iat === 0) {
                $flags |= self::W60_PRIOR_NONE;
            } elseif ($now < $iat - $skew) {
                $flags |= self::W60_PRIOR_NOT_YET;
            } elseif ($now >= $iat + $ttl + $skew) {
                $flags |= self::W60_PRIOR_EXPIRED;
            } else {
                $flags |= self::W60_PRIOR_ACTIVE;
                $remaining = $iat + $ttl + $skew - $now;
            }
            $flags |= ($flags & self::W60_PRIOR_ACTIVE) === 0 || $remaining < intdiv($ttl, 5) ? self::W60_REFRESH : 0;
            $safe = preg_match('/\A[\x20\x21\x23-\x5b\x5d-\x7e]*\z/', $sub.$email.$role) === 1;
            if ($safe) {
                $flags |= self::W60_CLAIM_SAFE;
                if ($subValid && $code !== 0 && $org !== 0 && ($email === '' || $emailValid)) {
                    $flags |= self::W60_ISSUABLE;
                }
            }
            $claimsLen = $safe
                ? \strlen((string) json_encode(['sub' => $sub, 'email' => $email, 'name' => $sub, 'role' => $role, 'org' => $org, 'iat' => $now, 'nbf' => $nbf, 'exp' => $exp], JSON_UNESCAPED_SLASHES))
                : -1;
            array_push($words, $flags, $org, $ttl, $nbf, $exp, $remaining, $claimsLen);
        }

        return $words;
    }

    /**
     * Wave 60 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics so the
     * class gains no Wave 60 static properties.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('grafana_jwt_issuer_w60', self::W60_GRAFANA_JWT_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
