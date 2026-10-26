<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RecaptchaService
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
     * Verify a Google reCAPTCHA token.
     */
    public static function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = config('captcha.recaptcha.secret', config('captcha.secret'));

        if ($secret === null || $secret === '') {
            return false;
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp ?? request()->ip(),
            ]);

            $result = $response->json();

            return self::verificationSucceeded($result);
        } catch (Throwable $throwable) {
            Log::error('reCAPTCHA verification failed: '.$throwable->getMessage());

            return false;
        }
    }

    /**
     * Render the Google reCAPTCHA widget.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function display(array $attributes = []): string
    {
        $sitekey = config('captcha.recaptcha.sitekey', config('captcha.sitekey'));

        if ($sitekey === null || $sitekey === '') {
            return '';
        }

        $defaultAttributes = [
            'class' => 'g-recaptcha',
            'data-sitekey' => $sitekey,
        ];

        return self::renderWidget(array_merge($defaultAttributes, $attributes));
    }

    /**
     * Render the Google reCAPTCHA JavaScript include.
     */
    public static function renderJs(): string
    {
        return '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
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
     * Whether a decoded siteverify response reports success.
     */
    public static function verificationSucceeded(mixed $result): bool
    {
        return self::usesRustFfi()
            ? self::verificationSucceededNative($result)
            : self::verificationSucceededPhp($result);
    }

    public static function verificationSucceededPhp(mixed $result): bool
    {
        return isset($result['success']) && $result['success'] === true;
    }

    public static function verificationSucceededNative(mixed $result): bool
    {
        $ffi = self::ffi();
        if ($ffi === null || \is_object($result)) {
            return self::verificationSucceededPhp($result);
        }

        $isArray = \is_array($result);
        try {
            // @phpstan-ignore method.notFound
            $outcome = $ffi->nntmux_captcha_verification_outcome(0, 1, $isArray ? 1 : 0, $isArray && ($result['success'] ?? null) === true ? 1 : 0);
        } catch (Throwable) {
            return self::verificationSucceededPhp($result);
        }

        return \is_int($outcome) && $outcome >= 0 && $outcome <= 3
            ? $outcome === 0
            : self::verificationSucceededPhp($result);
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
            $attributesString .= sprintf('%s="%s" ', $key, htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
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
            $key = (string) $key;
            $value = (string) $value;
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
            $written = $ffi->nntmux_captcha_widget_html($packed, \strlen($packed), $lens, $count, 0, $scratch, $cap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::renderWidgetPhp($attributes);
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::renderWidgetPhp($attributes);
        }
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W48 parity test). */
    public const bool W48_ASSESSMENT_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_recaptcha_assess_batch(const char *bodies, size_t bodies_len, size_t count, double threshold, const char *action, size_t action_len, const char *hostname, size_t hostname_len, char *out, size_t cap);
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

    private static ?bool $w48AssessmentRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark default; enableRustFfi() is untouched. */
    public static function useW48AssessmentRustFfi(?bool $enabled = true): void
    {
        self::$w48AssessmentRustFfi = $enabled;
    }

    public static function usesW48AssessmentRustFfi(): bool
    {
        return self::$w48AssessmentRustFfi ?? self::W48_ASSESSMENT_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW48AssessmentRustFfi(): void
    {
        self::$w48AssessmentRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Batch v2/v3 siteverify assessment. A body with a top-level `score` key is v3. Two words per
     * item: `outcome | errorMask << 4 | version << 12 | hostnameMatch << 14 | actionMatch << 15 |
     * errorCodes << 16 | decodedArray << 24 | success << 25 | hasHostname << 26 | hasAction << 27`,
     * then `floor(score * 1e6)` for a numeric 0..1 score, -1 without a score or -2 for any
     * other score. Outcomes: 0 accepted, 1 body not a JSON array/object, 2 `success !== true`,
     * 6 invalid v3 score, 3 v3 score below $threshold, 4 v3 action differs from a non-empty
     * expectation, 5 hostname differs (case-insensitively) from a non-empty expectation. A
     * final word holds the FNV-1a 64 digest of the item words.
     *
     * @param  list<string>  $bodies  Raw siteverify response bodies.
     * @return list<int>
     */
    public static function assessResponses(array $bodies, float $threshold, string $expectedAction = '', string $expectedHostname = ''): array
    {
        return self::usesW48AssessmentRustFfi()
            ? self::assessResponsesNative($bodies, $threshold, $expectedAction, $expectedHostname)
            : self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
    }

    /**
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function assessResponsesPhp(array $bodies, float $threshold, string $expectedAction = '', string $expectedHostname = ''): array
    {
        $host = strtolower($expectedHostname);
        $out = [];
        foreach ($bodies as $body) {
            $result = json_decode($body, true);
            $isArray = \is_array($result);
            $success = $isArray && ($result['success'] ?? null) === true;
            $v3 = $isArray && \array_key_exists('score', $result);
            $score = $v3 ? $result['score'] : null;
            $micros = ! $v3 ? -1 : ((\is_int($score) || \is_float($score)) && $score >= 0 && $score <= 1 ? (int) floor($score * 1e6) : -2);
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
            $outcome = match (true) {
                ! $isArray => 1,
                ! $success => 2,
                $v3 && $micros < 0 => 6,
                $v3 && $score < $threshold => 3,
                $v3 && $expectedAction !== '' && ! $actionMatch => 4,
                $host !== '' && ! $hostMatch => 5,
                default => 0,
            };
            $out[] = $outcome | $mask << 4 | ($v3 ? 2 : 1) << 12 | (int) $hostMatch << 14 | (int) $actionMatch << 15
                | min(255, $codes) << 16 | (int) $isArray << 24 | (int) $success << 25 | (int) $hasHost << 26
                | (int) $hasAction << 27;
            $out[] = $micros;
        }
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a body holds a NUL byte.
     *
     * @param  list<string>  $bodies
     * @return list<int>
     */
    public static function assessResponsesNative(array $bodies, float $threshold, string $expectedAction = '', string $expectedHostname = ''): array
    {
        $count = \count($bodies);
        $ffi = $count === 0 ? null : (self::$w48Ffi instanceof FFI ? self::$w48Ffi : self::w48Ffi());
        $packed = implode("\0", $bodies);
        if ($ffi === null || substr_count($packed, "\0") !== $count - 1) {
            return self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
        }
        $bytes = ($count * 2 + 1) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_recaptcha_assess_batch($packed, \strlen($packed), $count, $threshold, $expectedAction, \strlen($expectedAction), $expectedHostname, \strlen($expectedHostname), $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::assessResponsesPhp($bodies, $threshold, $expectedAction, $expectedHostname);
    }

    private static function w48Ffi(): ?FFI
    {
        self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w48', self::W48_FFI_CDEF) ?? false;

        return self::$w48Ffi === false ? null : self::$w48Ffi;
    }

    // ========================================================================
    // WAVE 61.4 BATCH HELPER
    // ========================================================================

    /** Disabled (PHP retained): the minimum of four paired runs reached 1.14x common mean, 1.19x common p99, 1.07x uncommon mean and 1.14x uncommon p99 (marshalling the tokens costs about as much as PHP's C-level strspn() validation); see RustUpdateLockPasswordBreachAniListRecaptchaRegistrationLogW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = false;

    private const string W61_RECAPTCHA_FFI_CDEF = 'ssize_t nntmux_w614_recaptcha_verification_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per verificationPlanRowBatch() record. */
    public const int W61_ROW_WORDS = 5;

    /** int64 summary words after the verificationPlanRowBatch() records (none for an empty batch). */
    public const int W61_TRAILER_WORDS = 7;

    /** Upper clamp for epoch-second timestamps. */
    private const int W61_TIME_MAX = 1 << 40;

    /** Siteverify tokens expire two minutes after the challenge. */
    private const int W61_TOKEN_MAX_AGE = 120;

    private const string W61_TOKEN_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';

    private const string W61_ACTION_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_/';

    private const string W61_DIGITS = '0123456789';

    private const string W61_IPV6_BYTES = '0123456789abcdefABCDEF:';

    /**
     * reCAPTCHA verification plan rows for [token, secret, remote ip, action, expected action,
     * comma-separated error codes, score micros (-1 none), threshold micros, challenge time,
     * now] items (times clamp to 0..2^40, the threshold to 0..10^6).
     *
     * Tokens are 0 empty, 1 20-4096 bytes of `[A-Za-z0-9_-]`, 2 over 4096 bytes or 3 otherwise;
     * secrets 0 empty, 1 forty `[A-Za-z0-9_-]` bytes or 2 otherwise; addresses 0 empty, 1 IPv4
     * (masked to `a << 16 | b << 8 | c`), 2 IPv6-shaped (masked to its ':' count) or 3 other;
     * actions 0 empty, 1 up to 100 `[A-Za-z0-9_/]` bytes or 2 otherwise. Error codes are
     * trimmed and mapped through W48_ERROR_CODES (others bit 7); the category is 1 secret, 3
     * replay, 2 response, 4 transient, 5 unknown or 0 none, in that precedence. Scores are 0
     * absent, 1 within 0..10^6 or 2 invalid. A challenge time after now is future; one over 120
     * seconds old is expired.
     *
     * Outcome: 1 missing token or secret, 2 malformed token or secret, 3 error codes, 4 expired
     * or future, 5 action differs from a non-empty expectation, 7 invalid score, 6 score below
     * the threshold, 0 accepted. Rows [outcome | token << 4 | secret << 6 | ip << 8 | action <<
     * 10 | category << 12 | action match << 15 | error mask << 16 | codes << 24 | score state <<
     * 32 | future << 34 | expired << 35, score micros (-1), age (-1), token bytes, masked ip],
     * then the trailer [accepted, rejected, error coded, expired, low score, action mismatch,
     * FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function verificationPlanRowBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::verificationPlanRowBatchNative($items) : self::verificationPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function verificationPlanRowBatchPhp(array $items): array
    {
        self::w61Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$token, $secret, $ip, $action, $expected, $errors, $micros, $threshold, $ts, $now]) {
            $tokenLength = \strlen($token);
            $tokenClass = $tokenLength === 0 ? 0 : ($tokenLength > 4096 ? 2 : ($tokenLength >= 20 && strspn($token, self::W61_TOKEN_BYTES) === $tokenLength ? 1 : 3));
            $secretLength = \strlen($secret);
            $secretClass = $secretLength === 0 ? 0 : ($secretLength === 40 && strspn($secret, self::W61_TOKEN_BYTES) === 40 ? 1 : 2);
            [$ipClass, $masked] = self::w61Ip($ip);
            $actionLength = \strlen($action);
            $actionClass = $actionLength === 0 ? 0 : ($actionLength <= 100 && strspn($action, self::W61_ACTION_BYTES) === $actionLength ? 1 : 2);
            $matched = $expected !== '' && $action === $expected;
            $mask = $codes = 0;
            if ($errors !== '') {
                foreach (explode(',', $errors) as $code) {
                    $code = trim($code);
                    if ($code !== '') {
                        $codes++;
                        $mask |= 1 << (self::W48_ERROR_CODES[$code] ?? 7);
                    }
                }
            }
            $scoreState = $micros === -1 ? 0 : ($micros >= 0 && $micros <= 1_000_000 ? 1 : 2);
            $threshold = max(0, min(1_000_000, $threshold));
            $ts = max(0, min(self::W61_TIME_MAX, $ts));
            $now = max(0, min(self::W61_TIME_MAX, $now));
            $future = $ts > $now;
            $expired = $ts > 0 && ! $future && $now - $ts > self::W61_TOKEN_MAX_AGE;
            $age = $ts === 0 || $future ? -1 : $now - $ts;
            $category = match (true) {
                ($mask & 3) !== 0 => 1,
                ($mask & 32) !== 0 => 3,
                ($mask & 12) !== 0 => 2,
                ($mask & 80) !== 0 => 4,
                ($mask & 128) !== 0 => 5,
                default => 0,
            };
            $outcome = match (true) {
                $tokenClass === 0 || $secretClass === 0 => 1,
                $tokenClass !== 1 || $secretClass !== 1 => 2,
                $codes > 0 => 3,
                $future || $expired => 4,
                $expected !== '' && ! $matched => 5,
                $scoreState === 2 => 7,
                $scoreState === 1 && $micros < $threshold => 6,
                default => 0,
            };
            $word = $outcome | $tokenClass << 4 | $secretClass << 6 | $ipClass << 8 | $actionClass << 10 | $category << 12 | ($matched ? 1 << 15 : 0)
                | $mask << 16 | min(255, $codes) << 24 | $scoreState << 32 | ($future ? 1 << 34 : 0) | ($expired ? 1 << 35 : 0);
            $score = $scoreState === 1 ? $micros : -1;
            array_push($out, $word, $score, $age, $tokenLength, $masked);
            $summary[$outcome === 0 ? 0 : 1]++;
            if ($outcome === 3) {
                $summary[2]++;
            } elseif ($outcome === 4) {
                $summary[3]++;
            } elseif ($outcome === 6) {
                $summary[4]++;
            } elseif ($outcome === 5) {
                $summary[5]++;
            }
            $value = $word ^ $age << 8 ^ $score;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[6] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function verificationPlanRowBatchNative(array $items): array
    {
        self::w61Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w61Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3),
            array_column($items, 4), array_column($items, 5)], $count);
        $ints = pack('P*', ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8), ...array_column($items, 9));

        return self::w61Call($blob, $twin, $ints, $count, $count * self::W61_ROW_WORDS + self::W61_TRAILER_WORDS)
            ?? self::verificationPlanRowBatchPhp($items);
    }

    /**
     * (class, masked): 0 empty, 1 strict dotted-quad IPv4 (`a << 16 | b << 8 | c`), 2
     * IPv6-shaped (2-39 hex digits and colons, at least two colons, at most one '::'; masked to
     * the colon count), 3 other (-1).
     *
     * @return array{0: int, 1: int}
     */
    private static function w61Ip(string $ip): array
    {
        if ($ip === '') {
            return [0, -1];
        }
        $octets = explode('.', $ip);
        if (\count($octets) === 4) {
            $valid = true;
            foreach ($octets as $octet) {
                $length = \strlen($octet);
                if ($length === 0 || $length > 3 || strspn($octet, self::W61_DIGITS) !== $length || ($length > 1 && $octet[0] === '0') || (int) $octet > 255) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                return [1, (int) $octets[0] << 16 | (int) $octets[1] << 8 | (int) $octets[2]];
            }
        }
        $length = \strlen($ip);
        $colons = substr_count($ip, ':');

        return $length >= 2 && $length <= 39 && $colons >= 2 && substr_count($ip, '::') <= 1 && strspn($ip, self::W61_IPV6_BYTES) === $length
            ? [2, $colons] : [3, -1];
    }

    /** @param array<array-key, mixed> $items */
    private static function w61Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W61 reCAPTCHA verification plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 10 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_string($item[4]) || ! \is_string($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8]) || ! \is_int($item[9])) {
                throw new InvalidArgumentException('W61 reCAPTCHA verification plan batch items must be [string token, string secret, string remote ip, string action, string expected action, string error codes, int score micros, int threshold micros, int challenge ts, int now] lists.');
            }
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W61 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('enabled');
        self::w61RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w61Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w61Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w61RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w61', self::W61_RECAPTCHA_FFI_CDEF) ?? false;
            self::w61RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w61RustFfiState('out', $out);
                self::w61RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W61_RECAPTCHA_FFI_CDEF.)
            $written = $ffi->nntmux_w614_recaptcha_verification_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w61RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W61 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
