<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TurnstileService
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_captcha_widget_html(const char *parts, size_t parts_len, const size_t *lens, size_t count, uint32_t mode, char *out, size_t cap);
        int32_t nntmux_captcha_verification_outcome(uint32_t provider, uint32_t http_ok, uint32_t result_is_array, uint32_t success_true);
        C;

    /**
     * The Rust helpers stay opt-in: they did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustEmailDomainCaptchaTurnstileTrustedDeviceParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Verify Cloudflare Turnstile token
     */
    public static function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = config('captcha.turnstile.secret');

        if (empty($secret)) {
            Log::error('Turnstile verification failed: missing secret.', self::logContext());

            return false;
        }

        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp ?? request()->ip(),
            ]);

            $result = $response->json();
            $reason = self::failureReason($response->successful(), $result);

            if ($reason === null) {
                return true;
            }

            $errorCodes = is_array($result) ? ($result['error-codes'] ?? []) : [];

            Log::warning('Turnstile verification failed.', self::logContext() + [
                'http_status' => $response->status(),
                'error_codes' => is_array($errorCodes) ? array_values(array_filter($errorCodes, 'is_string')) : [],
                'reason' => $reason,
                'siteverify_ray' => $response->header('cf-ray'),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Turnstile verification request failed.', self::logContext() + [
                'exception_class' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Keep credentials, CAPTCHA tokens, and request bodies out of diagnostics.
     *
     * @return array<string, mixed>
     */
    private static function logContext(): array
    {
        return [
            'route' => request()->route()?->getName(),
            'cf_ray' => request()->header('CF-Ray'),
            'ip' => config('nntmux_settings.store_user_ip') ? request()->ip() : null,
        ];
    }

    /**
     * Get the Turnstile HTML widget
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function display(array $attributes = []): string
    {
        $sitekey = config('captcha.turnstile.sitekey');

        if (empty($sitekey)) {
            return '';
        }

        $defaultAttributes = [
            'class' => 'cf-turnstile',
            'data-sitekey' => $sitekey,
            'data-theme' => 'auto',
            'data-size' => 'normal',
        ];

        return self::renderWidget(array_merge($defaultAttributes, $attributes));
    }

    /**
     * Get the Turnstile JavaScript
     */
    public static function renderJs(): string
    {
        return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
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
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
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
     * Null when siteverify accepted the token, otherwise the logged failure reason
     * (`http-error`, `rejected`, or `invalid-response`).
     */
    public static function failureReason(bool $httpSuccessful, mixed $result): ?string
    {
        return self::usesRustFfi()
            ? self::failureReasonNative($httpSuccessful, $result)
            : self::failureReasonPhp($httpSuccessful, $result);
    }

    public static function failureReasonPhp(bool $httpSuccessful, mixed $result): ?string
    {
        if ($httpSuccessful && is_array($result) && ($result['success'] ?? null) === true) {
            return null;
        }

        return ! $httpSuccessful ? 'http-error' : (is_array($result) ? 'rejected' : 'invalid-response');
    }

    public static function failureReasonNative(bool $httpSuccessful, mixed $result): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::failureReasonPhp($httpSuccessful, $result);
        }

        $isArray = is_array($result);
        try {
            // @phpstan-ignore method.notFound
            $outcome = $ffi->nntmux_captcha_verification_outcome(1, $httpSuccessful ? 1 : 0, $isArray ? 1 : 0, $isArray && ($result['success'] ?? null) === true ? 1 : 0);
        } catch (Throwable) {
            return self::failureReasonPhp($httpSuccessful, $result);
        }

        return match ($outcome) {
            0 => null,
            1 => 'http-error',
            2 => 'rejected',
            3 => 'invalid-response',
            default => self::failureReasonPhp($httpSuccessful, $result),
        };
    }

    /**
     * Render the widget `<div>` for already merged attributes.
     *
     * @param  array<array-key, mixed>  $attributes
     */
    public static function renderWidget(array $attributes): string
    {
        return self::usesRustFfi()
            ? self::renderWidgetNative($attributes)
            : self::renderWidgetPhp($attributes);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function renderWidgetPhp(array $attributes): string
    {
        $attributesString = '';
        foreach ($attributes as $key => $value) {
            $attributesString .= sprintf('%s="%s" ', $key, htmlspecialchars($value));
        }

        return sprintf('<div %s></div>', trim($attributesString));
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function renderWidgetNative(array $attributes): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::renderWidgetPhp($attributes);
        }

        $packed = '';
        $parts = [];
        foreach ($attributes as $key => $value) {
            if (! \is_string($value)) {
                // htmlspecialchars() rejects non-string values under strict types.
                return self::renderWidgetPhp($attributes);
            }
            $key = (string) $key;
            $value = $value;
            $packed .= $key.$value;
            $parts[] = \strlen($key);
            $parts[] = \strlen($value);
        }

        $count = \count($parts);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 16 + 6 * \strlen($packed) + 4 * $count, $cap);
        if ($scratch === null) {
            return self::renderWidgetPhp($attributes);
        }

        try {
            $lens = $ffi->new('size_t['.max(1, $count).']');
            foreach ($parts as $i => $len) {
                $lens[$i] = $len;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_captcha_widget_html($packed, \strlen($packed), $lens, $count, 1, $scratch, $cap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::renderWidgetPhp($attributes);
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::renderWidgetPhp($attributes);
        }
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W48 parity test). */
    public const bool W48_SITEVERIFY_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_turnstile_siteverify_batch(const char *tokens, size_t tokens_len, const char *bodies, size_t bodies_len, size_t count, const char *hostname, size_t hostname_len, const char *action, size_t action_len, char *out, size_t cap);
        C;

    /** Siteverify `error-codes` bit indexes; any other string sets bit 7. */
    public const array W48_ERROR_CODES = [
        'missing-input-secret' => 0,
        'invalid-input-secret' => 1,
        'missing-input-response' => 2,
        'invalid-input-response' => 3,
        'bad-request' => 4,
        'timeout-or-duplicate' => 5,
        'internal-error' => 6,
    ];

    private const string W48_TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

    private static ?bool $w48SiteverifyRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark default; enableRustFfi() is untouched. */
    public static function useW48SiteverifyRustFfi(?bool $enabled = true): void
    {
        self::$w48SiteverifyRustFfi = $enabled;
    }

    public static function usesW48SiteverifyRustFfi(): bool
    {
        return self::$w48SiteverifyRustFfi ?? self::W48_SITEVERIFY_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW48SiteverifyRustFfi(): void
    {
        self::$w48SiteverifyRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Batch token-format and siteverify-body evaluation. Two words per item: `outcome |
     * errorMask << 4 | hasHostname << 12 | hostnameMatch << 13 | hasAction << 14 |
     * actionMatch << 15 | tokenLength << 16 | errorCodes << 28 | decodedArray << 36 |
     * success << 37`, then crc32 of the lowercase hostname or -1. Outcomes: 0 accepted,
     * 1 malformed token (1..2048 bytes of `[A-Za-z0-9_.-]`), 2 body not a JSON array/object,
     * 3 `success !== true`, 4 hostname differs (case-insensitively) from a non-empty
     * expectation, 5 action differs from a non-empty expectation. A final word holds the
     * FNV-1a 64 digest of the item words.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $bodies  Raw siteverify response bodies, parallel to $tokens.
     * @return list<int>
     */
    public static function evaluateSiteverify(array $tokens, array $bodies, string $expectedHostname = '', string $expectedAction = ''): array
    {
        return self::usesW48SiteverifyRustFfi()
            ? self::evaluateSiteverifyNative($tokens, $bodies, $expectedHostname, $expectedAction)
            : self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function evaluateSiteverifyPhp(array $tokens, array $bodies, string $expectedHostname = '', string $expectedAction = ''): array
    {
        $host = strtolower($expectedHostname);
        $out = [];
        foreach ($tokens as $i => $token) {
            $result = json_decode($bodies[$i] ?? '', true);
            $isArray = \is_array($result);
            $success = $isArray && ($result['success'] ?? null) === true;
            $hostname = $isArray ? ($result['hostname'] ?? null) : null;
            $action = $isArray ? ($result['action'] ?? null) : null;
            $hasHost = \is_string($hostname);
            $hasAction = \is_string($action);
            $hostMatch = $hasHost && $host !== '' && strtolower($hostname) === $host;
            $actionMatch = $hasAction && $action === $expectedAction;
            $mask = $codes = 0;
            $errors = $isArray ? ($result['error-codes'] ?? null) : null;
            if (\is_array($errors)) {
                foreach ($errors as $code) {
                    if (\is_string($code)) {
                        $codes++;
                        $mask |= 1 << (self::W48_ERROR_CODES[$code] ?? 7);
                    }
                }
            }
            $length = \strlen($token);
            $outcome = match (true) {
                $length === 0 || $length > 2048 || strspn($token, self::W48_TOKEN_CHARS) !== $length => 1,
                ! $isArray => 2,
                ! $success => 3,
                $host !== '' && ! $hostMatch => 4,
                $expectedAction !== '' && ! $actionMatch => 5,
                default => 0,
            };
            $out[] = $outcome | $mask << 4 | (int) $hasHost << 12 | (int) $hostMatch << 13 | (int) $hasAction << 14
                | (int) $actionMatch << 15 | min(4095, $length) << 16 | min(255, $codes) << 28 | (int) $isArray << 36
                | (int) $success << 37;
            $out[] = $hasHost ? crc32(strtolower($hostname)) : -1;
        }
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a value holds a NUL byte.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function evaluateSiteverifyNative(array $tokens, array $bodies, string $expectedHostname = '', string $expectedAction = ''): array
    {
        $count = \count($tokens);
        $ffi = $count === 0 ? null : (self::$w48Ffi instanceof FFI ? self::$w48Ffi : self::w48Ffi());
        $packedTokens = implode("\0", $tokens);
        $packedBodies = implode("\0", $bodies);
        if ($ffi === null || \count($bodies) !== $count
            || substr_count($packedTokens, "\0") !== $count - 1 || substr_count($packedBodies, "\0") !== $count - 1) {
            return self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
        }
        $bytes = ($count * 2 + 1) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_turnstile_siteverify_batch($packedTokens, \strlen($packedTokens), $packedBodies, \strlen($packedBodies), $count, $expectedHostname, \strlen($expectedHostname), $expectedAction, \strlen($expectedAction), $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::evaluateSiteverifyPhp($tokens, $bodies, $expectedHostname, $expectedAction);
    }

    private static function w48Ffi(): ?FFI
    {
        self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w48', self::W48_FFI_CDEF) ?? false;

        return self::$w48Ffi === false ? null : self::$w48Ffi;
    }

    /** C signature of the Wave 62.5 {@see self::verificationPlanBatch()} helper. */
    private const string W62_TURNSTILE_PLAN_FFI_CDEF = 'int64_t nntmux_w625_turnstile_verification_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 62.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 1.92/1.85/3.05/2.46x); see tests/Unit/RustSteamTmdbClientTraktTurnstileReleaseSearchW62ParityTest.php.
     */
    public const bool W62_RUST_FFI_DEFAULT = true;

    /**
     * Wave 62.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w62_5', self::W62_TURNSTILE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W62_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW62RustFfi(): bool
    {
        return self::w62RustFfiState() === true;
    }

    /**
     * Runs $callback with verificationPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 62.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w62Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w62RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w625_turnstile_verification_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch Turnstile verification plan over
     * `token\tsecret\tremote_ip\tidempotency_key\terror_codes\tsuccess\thostname\texpected_hostname`. Nine words
     * per record: `[token_len, token_state, secret_state, ip_kind, idempotency_state, error_mask, outcome,
     * retryable, flags]`. Fields are trimmed. The token is 0 empty, 1 valid (W48_TOKEN_CHARS), 2 over 2048 bytes,
     * 3 invalid bytes. The secret is 0 empty, 1 `0x` plus 1-64 `[A-Za-z0-9_-]`, 2-4 Cloudflare's always-pass,
     * always-fail and token-spent test secrets, 5 invalid. The remote IP is 0 empty, 1 IPv4 (dotted quad without
     * leading zeros), 2 IPv6 (2-45 hex digit, colon and dot bytes with at least two colons, no `:::`, at most one
     * `::` and eight groups of up to four hex digits; only the last group may be a dotted quad), 3 invalid;
     * private means IPv4 10/8, 127/8, 169.254/16, 172.16/12 or 192.168/16, or IPv6 `::1` and `fc`/`fd` prefixes
     * (ASCII case-insensitive). The idempotency key is 0 empty, 1 UUID, 2 invalid. Error codes are trimmed,
     * non-empty comma-separated items mapped to W48_ERROR_CODES bits; others set bit 7. Success is `1`/`true` or
     * empty/`0`/`false` (ASCII case-insensitive). Outcome: 2 skipped for an empty or invalid secret, 3 rejected
     * locally for a token that is not valid, 1 passed on success without error codes or hostname mismatch, else
     * 0. Failures with `internal-error` are retryable. Flags: 1 invalid token, 2 invalid secret, 4 invalid IP, 8
     * private IP, 16 invalid idempotency key, 32 unknown error code, 64 invalid success, 128 hostname mismatch
     * against a non-empty expected hostname (ASCII case-insensitive), 256 success with error codes, 512 not
     * exactly eight fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function verificationPlanBatch(array $records): array
    {
        return self::usesW62RustFfi() ? self::verificationPlanBatchNative($records) : self::verificationPlanBatchPhp($records);
    }

    /** Cloudflare's always-pass, always-fail and token-spent test secrets (secret states 2-4). */
    private const array W62_TEST_SECRETS = ['1x0000000000000000000000000000000AA', '2x0000000000000000000000000000000AA', '3x0000000000000000000000000000000AA'];

    private const string W62_HEX = '0123456789abcdefABCDEF';

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function verificationPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 8 ? 0 : 512;
            $token = trim($fields[0]);
            $tokenLength = \strlen($token);
            if ($token === '') {
                $tokenState = 0;
            } elseif ($tokenLength > 2048) {
                [$tokenState, $flags] = [2, $flags | 1];
            } elseif (strspn($token, self::W48_TOKEN_CHARS) !== $tokenLength) {
                [$tokenState, $flags] = [3, $flags | 1];
            } else {
                $tokenState = 1;
            }
            $secret = trim($fields[1] ?? '');
            $test = array_search($secret, self::W62_TEST_SECRETS, true);
            if ($secret === '') {
                $secretState = 0;
            } elseif ($test !== false) {
                $secretState = $test + 2;
            } elseif (\strlen($secret) >= 3 && \strlen($secret) <= 66 && str_starts_with($secret, '0x') && strspn($secret, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', 2) === \strlen($secret) - 2) {
                $secretState = 1;
            } else {
                [$secretState, $flags] = [5, $flags | 2];
            }
            $ip = trim($fields[2] ?? '');
            if ($ip === '') {
                $ipKind = 0;
            } elseif (($octets = self::w62Ipv4($ip)) !== null) {
                [$a, $b] = $octets;
                if ($a === 10 || $a === 127 || ($a === 169 && $b === 254) || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168)) {
                    $flags |= 8;
                }
                $ipKind = 1;
            } elseif (self::w62Ipv6($ip)) {
                if ($ip === '::1' || strncasecmp($ip, 'fc', 2) === 0 || strncasecmp($ip, 'fd', 2) === 0) {
                    $flags |= 8;
                }
                $ipKind = 2;
            } else {
                [$ipKind, $flags] = [3, $flags | 4];
            }
            $key = trim($fields[3] ?? '');
            if ($key === '') {
                $keyState = 0;
            } elseif (preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D', $key) === 1) {
                $keyState = 1;
            } else {
                [$keyState, $flags] = [2, $flags | 16];
            }
            $mask = 0;
            foreach (explode(',', $fields[4] ?? '') as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                if (isset(self::W48_ERROR_CODES[$item])) {
                    $mask |= 1 << self::W48_ERROR_CODES[$item];
                } else {
                    $mask |= 128;
                    $flags |= 32;
                }
            }
            $success = match (strtolower(trim($fields[5] ?? ''))) {
                '1', 'true' => 1,
                '', '0', 'false' => 0,
                default => -1,
            };
            if ($success < 0) {
                [$success, $flags] = [0, $flags | 64];
            }
            $expected = trim($fields[7] ?? '');
            if ($expected !== '' && strtolower(trim($fields[6] ?? '')) !== strtolower($expected)) {
                $flags |= 128;
            }
            if ($success === 1 && $mask !== 0) {
                $flags |= 256;
            }
            if ($secretState === 0 || $secretState === 5) {
                $outcome = 2;
            } elseif ($tokenState !== 1) {
                $outcome = 3;
            } else {
                $outcome = $success === 1 && $mask === 0 && ($flags & 128) === 0 ? 1 : 0;
            }
            array_push($out, $tokenLength, $tokenState, $secretState, $ipKind, $keyState, $mask, $outcome, $outcome === 0 && ($mask & 64) !== 0 ? 1 : 0, $flags);
        }

        return $out;
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w62Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Dotted quad with 1-3 digit octets up to 255 (no leading zeros) as its octets, else null.
     *
     * @return list<int>|null
     */
    private static function w62Ipv4(string $text): ?array
    {
        $parts = explode('.', $text);
        if (\count($parts) !== 4) {
            return null;
        }
        $octets = [];
        foreach ($parts as $part) {
            if (\strlen($part) > 1 && $part[0] === '0') {
                return null;
            }
            $value = self::w62Digits($part, 3);
            if ($value === null || $value > 255) {
                return null;
            }
            $octets[] = $value;
        }

        return $octets;
    }

    /** The Wave 62.5 IPv6 shape (see verificationPlanBatch()). */
    private static function w62Ipv6(string $text): bool
    {
        $length = \strlen($text);
        if ($length < 2 || $length > 45 || strspn($text, self::W62_HEX.':.') !== $length || substr_count($text, ':') < 2 || str_contains($text, ':::') || substr_count($text, '::') > 1) {
            return false;
        }
        $groups = explode(':', $text);
        if (\count($groups) > 8) {
            return false;
        }
        $last = \count($groups) - 1;
        foreach ($groups as $index => $group) {
            if ($index === $last && str_contains($group, '.')) {
                if (self::w62Ipv4($group) === null) {
                    return false;
                }
            } elseif (\strlen($group) > 4 || strspn($group, self::W62_HEX) !== \strlen($group)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function verificationPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w62Words($count, implode("\0", $records), 9 * $count) ?? self::verificationPlanBatchPhp($records);
    }
}
