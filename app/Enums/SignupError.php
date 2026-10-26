<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum SignupError: int
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_signup_role_queue_label(uint32_t kind, int64_t value, char *out_buf, size_t out_cap);
        int32_t nntmux_signup_role_queue_flags(uint32_t kind, int64_t value);
        int32_t nntmux_signup_role_queue_resolve_label(uint32_t kind, const char *label_ptr, size_t label_len, int64_t *out_value);
        C;

    private const int FFI_KIND = 0;

    private const int DECLINE = -2147483648;

    private const int FLAG_KNOWN = 1;

    private const int FLAG_ERROR = 2;

    private const int FLAG_SUCCESS = 4;

    case BAD_USERNAME = -1;
    case BAD_PASSWORD = -2;
    case BAD_EMAIL = -3;
    case USERNAME_IN_USE = -4;
    case EMAIL_IN_USE = -5;
    case BAD_INVITE_CODE = -6;
    case SUCCESS = 1;

    /** Opt-in only; see the paired measurements in RustSignupRoleQueueEnumComposerParityTest. */
    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::rustFfiToggle($useRustFfi);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustFfiToggle();
    }

    public static function resetRustFfi(): void
    {
        self::rustFfiToggle(false);
        self::ffi(true);
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::ffi(true, $ffi);
    }

    public function message(): string
    {
        return self::usesRustFfi() ? $this->messageNative() : $this->messagePhp();
    }

    public function messagePhp(): string
    {
        return match ($this) {
            self::BAD_USERNAME => 'Invalid username provided.',
            self::BAD_PASSWORD => 'Invalid password provided.',
            self::BAD_EMAIL => 'Invalid email address provided.',
            self::USERNAME_IN_USE => 'Username is already in use.',
            self::EMAIL_IN_USE => 'Email address is already in use.',
            self::BAD_INVITE_CODE => 'Invalid or expired invite code.',
            self::SUCCESS => 'Registration successful.',
        };
    }

    public function messageNative(): string
    {
        return self::nativeLabel($this->value) ?? $this->messagePhp();
    }

    public function isError(): bool
    {
        return self::usesRustFfi() ? $this->isErrorNative() : $this->isErrorPhp();
    }

    public function isErrorPhp(): bool
    {
        return $this->value < 0;
    }

    public function isErrorNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isErrorPhp() : ($flags & self::FLAG_ERROR) !== 0;
    }

    public function isSuccess(): bool
    {
        return self::usesRustFfi() ? $this->isSuccessNative() : $this->isSuccessPhp();
    }

    public function isSuccessPhp(): bool
    {
        return $this === self::SUCCESS;
    }

    public function isSuccessNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isSuccessPhp() : ($flags & self::FLAG_SUCCESS) !== 0;
    }

    /**
     * Resolve a case from its exact user-facing message.
     */
    public static function tryFromMessage(string $message): ?self
    {
        return self::usesRustFfi() ? self::tryFromMessageNative($message) : self::tryFromMessagePhp($message);
    }

    public static function tryFromMessagePhp(string $message): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->messagePhp() === $message) {
                return $case;
            }
        }

        return null;
    }

    public static function tryFromMessageNative(string $message): ?self
    {
        $ffi = self::ffi();
        $slot = $ffi === null ? null : self::valueSlot($ffi);
        if ($ffi !== null && $slot !== null) {
            try {
                $rc = self::call($ffi, 'nntmux_signup_role_queue_resolve_label', self::FFI_KIND, $message, \strlen($message), $slot);
                if ($rc === 0) {
                    return null;
                }
                if ($rc === 1) {
                    $case = self::tryFrom((int) $slot[0]);
                    if ($case !== null) {
                        return $case;
                    }
                }
            } catch (Throwable) {
            }
        }

        return self::tryFromMessagePhp($message);
    }

    private static function nativeLabel(int $value): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return null;
            }
            $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $value, $scratch, $cap);
            if (\is_int($written) && $written < 0 && $written > self::DECLINE) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $value, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function nativeFlags(int $value): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $flags = self::call($ffi, 'nntmux_signup_role_queue_flags', self::FFI_KIND, $value);

            return \is_int($flags) && $flags > 0 && ($flags & self::FLAG_KNOWN) !== 0 ? $flags : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function call(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function valueSlot(FFI $ffi): ?CData
    {
        static $slot = null;
        static $owner = null;
        if ($owner !== $ffi || $slot === null) {
            try {
                $slot = $ffi->new('int64_t[1]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $slot;
    }

    private static function rustFfiToggle(?bool $set = null): bool
    {
        static $enabled = false;
        if ($set !== null) {
            $enabled = $set;
        }

        return $enabled;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;

            return null;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W63.6 benchmark clears 1.5x on all four metrics.
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_SIGNUP_FFI_CDEF = <<<'C'
        int32_t nntmux_w636_signup_error_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t max_attempts, int64_t *out, size_t out_cap);
        C;

    public const int W63_SIGNUP_HEADER = 27;

    private const int W63_MAX_ITEMS = 1_048_576;

    /** Attempt and attempt-limit clamp (2^20 - 1). */
    private const int W63_ATTEMPT_CAP = 1_048_575;

    /** Attempt limit used for a limit at or below 0. */
    private const int W63_SIGNUP_ATTEMPTS = 5;

    /** HTTP status per case (unknown 500). */
    private const array W63_HTTP = [422, 422, 422, 409, 409, 403, 201, 500];

    /** Offending field per case: 0 none, 1 username, 2 password, 3 email, 4 invite. */
    private const array W63_FIELD = [1, 2, 3, 1, 3, 4, 0, 0];

    /** Exact token => `case | source << 3`. */
    private const array W63_SIGNUP_TOKENS = [
        '-1' => 0, '-2' => 1, '-3' => 2, '-4' => 3, '-5' => 4, '-6' => 5, '1' => 6,
        'BAD_USERNAME' => 8, 'BAD_PASSWORD' => 9, 'BAD_EMAIL' => 10, 'USERNAME_IN_USE' => 11, 'EMAIL_IN_USE' => 12, 'BAD_INVITE_CODE' => 13,
        'SUCCESS' => 14,
        'Invalid username provided.' => 16, 'Invalid password provided.' => 17, 'Invalid email address provided.' => 18,
        'Username is already in use.' => 19, 'Email address is already in use.' => 20, 'Invalid or expired invite code.' => 21,
        'Registration successful.' => 22,
    ];

    /** Lowercased trimmed alias => case. */
    private const array W63_SIGNUP_ALIASES = [
        'bad_username' => 0, 'invalid_username' => 0, 'username' => 0,
        'bad_password' => 1, 'invalid_password' => 1, 'weak_password' => 1, 'password' => 1,
        'bad_email' => 2, 'invalid_email' => 2, 'email' => 2,
        'username_in_use' => 3, 'username_taken' => 3, 'duplicate_username' => 3,
        'email_in_use' => 4, 'email_taken' => 4, 'duplicate_email' => 4,
        'bad_invite_code' => 5, 'invalid_invite' => 5, 'invite_expired' => 5, 'invite' => 5,
        'success' => 6, 'ok' => 6, 'registered' => 6, 'created' => 6,
    ];

    /**
     * Plan signup outcomes in order. Outcomes resolve by backing value (source 0), case name (1), exact
     * `messagePhp()` (2), an alias of `strtolower(trim($code))` (3), else unknown case 7 (4); cases 0..5 are
     * BAD_USERNAME..BAD_INVITE_CODE and 6 SUCCESS. Attempts so far and the limit (at or below 0 means 5) clamp
     * to 0..2^20-1. HTTP 422 invalid input, 409 conflict, 403 invite, 201 success, 500 unknown. Disposition 0
     * accept, 1 retry, 2 throttle (this attempt reaches the limit), 3 reject (unknown); audit 0 none, 1 info
     * (success), 2 warning (throttle or invite failure), 3 error (unknown).
     *
     * Layout: `[0]` count, `[1..8]` per case, `[9..13]` per source, `[14..17]` per disposition, `[18..21]` per
     * audit level, `[22]` errors, `[23]` first throttle (-1), `[24]` first unknown (-1), `[25]` highest HTTP
     * status (0 when empty), `[26]` FNV-1a digest; then per attempt `case | source << 3 | http << 6 | field <<
     * 16 | disposition << 19 | audit << 21 | attempts << 23 | min(token bytes, 255) << 43`.
     *
     * @param  array<array-key, int|string>  $codes
     * @param  array<array-key, int>  $attempts
     * @return list<int>
     */
    public static function w63SignupErrorPlanBatch(array $codes, array $attempts, int $maxAttempts = self::W63_SIGNUP_ATTEMPTS): array
    {
        return (self::usesW63RustFfi() ? self::w63SignupErrorPlanBatchNative($codes, $attempts, $maxAttempts) : null)
            ?? self::w63SignupErrorPlanBatchPhp($codes, $attempts, $maxAttempts);
    }

    /**
     * @param  array<array-key, int|string>  $codes
     * @param  array<array-key, int>  $attempts
     * @return list<int>
     */
    public static function w63SignupErrorPlanBatchPhp(array $codes, array $attempts, int $maxAttempts = self::W63_SIGNUP_ATTEMPTS): array
    {
        $codes = array_values($codes);
        $count = \count($codes);
        $attempts = self::w63Column($attempts, $count);
        $limit = $maxAttempts <= 0 ? self::W63_SIGNUP_ATTEMPTS : min($maxAttempts, self::W63_ATTEMPT_CAP);
        $out = array_fill(0, self::W63_SIGNUP_HEADER, 0);
        $out[0] = $count;
        $out[23] = $out[24] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($codes as $at => $code) {
            $code = (string) $code;
            $token = self::W63_SIGNUP_TOKENS[$code] ?? null;
            if ($token === null) {
                $case = self::W63_SIGNUP_ALIASES[strtolower(trim($code))] ?? 7;
                $source = $case === 7 ? 4 : 3;
            } else {
                $case = $token & 7;
                $source = $token >> 3;
            }
            $tries = max(0, min((int) $attempts[$at], self::W63_ATTEMPT_CAP));
            $http = self::W63_HTTP[$case];
            $disposition = match (true) {
                $case === 6 => 0,
                $case === 7 => 3,
                $tries + 1 >= $limit => 2,
                default => 1,
            };
            $audit = match (true) {
                $case === 6 => 1,
                $case === 7 => 3,
                $disposition === 2 || $case === 5 => 2,
                default => 0,
            };
            $out[1 + $case]++;
            $out[9 + $source]++;
            $out[14 + $disposition]++;
            $out[18 + $audit]++;
            $out[22] += $case <= 5 ? 1 : 0;
            if ($disposition === 2 && $out[23] < 0) {
                $out[23] = $at;
            }
            if ($case === 7 && $out[24] < 0) {
                $out[24] = $at;
            }
            $out[25] = max($out[25], $http);
            $row = $case | $source << 3 | $http << 6 | self::W63_FIELD[$case] << 16 | $disposition << 19 | $audit << 21 | $tries << 23 | min(\strlen($code), 255) << 43;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[26] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $codes
     * @param  array<array-key, int>  $attempts
     * @return list<int>|null
     */
    public static function w63SignupErrorPlanBatchNative(array $codes, array $attempts, int $maxAttempts = self::W63_SIGNUP_ATTEMPTS): ?array
    {
        $count = \count($codes);
        if ($count > self::W63_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w63Strings(array_values($codes));
        $ints = pack('q*', ...self::w63Column($attempts, $count));
        $words = self::W63_SIGNUP_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w63Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w636_signup_error_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $maxAttempts, $out, $words));
    }

    /**
     * Route the Wave 63.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /**
     * Route the Wave 63.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 63.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $forced = self::w63RustFfiState();

        return \is_bool($forced) ? $forced : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 63.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 63.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w636', self::W63_SIGNUP_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 63.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w63Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w63RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w63Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w63Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
