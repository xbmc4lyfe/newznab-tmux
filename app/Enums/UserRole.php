<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum UserRole: int
{
    public const string FFI_CDEF = SignupError::FFI_CDEF;

    private const int FFI_KIND = 1;

    private const int DECLINE = -2147483648;

    private const int FLAG_KNOWN = 1;

    private const int FLAG_ADMIN = 2;

    private const int FLAG_MODERATOR = 4;

    private const int FLAG_DISABLED = 8;

    private const int FLAG_ELEVATED = 16;

    case USER = 1;
    case ADMIN = 2;
    case DISABLED = 3;
    case MODERATOR = 4;

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

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::USER => 'User',
            self::ADMIN => 'Admin',
            self::DISABLED => 'Disabled',
            self::MODERATOR => 'Moderator',
        };
    }

    public function labelNative(): string
    {
        return self::nativeLabel($this->value) ?? $this->labelPhp();
    }

    public function isAdmin(): bool
    {
        return self::usesRustFfi() ? $this->isAdminNative() : $this->isAdminPhp();
    }

    public function isAdminPhp(): bool
    {
        return $this === self::ADMIN;
    }

    public function isAdminNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isAdminPhp() : ($flags & self::FLAG_ADMIN) !== 0;
    }

    public function isModerator(): bool
    {
        return self::usesRustFfi() ? $this->isModeratorNative() : $this->isModeratorPhp();
    }

    public function isModeratorPhp(): bool
    {
        return $this === self::MODERATOR;
    }

    public function isModeratorNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isModeratorPhp() : ($flags & self::FLAG_MODERATOR) !== 0;
    }

    public function isDisabled(): bool
    {
        return self::usesRustFfi() ? $this->isDisabledNative() : $this->isDisabledPhp();
    }

    public function isDisabledPhp(): bool
    {
        return $this === self::DISABLED;
    }

    public function isDisabledNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isDisabledPhp() : ($flags & self::FLAG_DISABLED) !== 0;
    }

    public function hasElevatedPrivileges(): bool
    {
        return self::usesRustFfi() ? $this->hasElevatedPrivilegesNative() : $this->hasElevatedPrivilegesPhp();
    }

    public function hasElevatedPrivilegesPhp(): bool
    {
        return in_array($this, [self::ADMIN, self::MODERATOR], true);
    }

    public function hasElevatedPrivilegesNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->hasElevatedPrivilegesPhp() : ($flags & self::FLAG_ELEVATED) !== 0;
    }

    /**
     * Resolve a case from its exact display label (e.g. a role name such as "Admin").
     */
    public static function tryFromLabel(string $label): ?self
    {
        return self::usesRustFfi() ? self::tryFromLabelNative($label) : self::tryFromLabelPhp($label);
    }

    public static function tryFromLabelPhp(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->labelPhp() === $label) {
                return $case;
            }
        }

        return null;
    }

    public static function tryFromLabelNative(string $label): ?self
    {
        $ffi = self::ffi();
        $slot = $ffi === null ? null : self::valueSlot($ffi);
        if ($ffi !== null && $slot !== null) {
            try {
                $rc = self::call($ffi, 'nntmux_signup_role_queue_resolve_label', self::FFI_KIND, $label, \strlen($label), $slot);
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

        return self::tryFromLabelPhp($label);
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

    // Rust is the default only while the paired W64.6 benchmark clears 1.5x on all four metrics.
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_ROLE_FFI_CDEF = <<<'C'
        int32_t nntmux_w646_user_role_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t quota, int64_t *out, size_t out_cap);
        C;

    public const int W64_ROLE_HEADER = 22;

    private const int W64_MAX_ITEMS = 1_048_576;

    /** Requests per window for a quota at or below 0, the quota cap (2^31) and the request clamp (2^32 - 1). */
    private const int W64_ROLE_QUOTA = 100;

    private const int W64_ROLE_QUOTA_CAP = 2_147_483_648;

    private const int W64_ROLE_REQUEST_CAP = 4_294_967_295;

    /** `nntmux_signup_role_queue_flags` bits per case in declaration order (unknown 0). */
    private const array W64_ROLE_FLAGS = [1, 19, 9, 21, 0];

    /** Exact token => `case | source << 3`. */
    private const array W64_ROLE_TOKENS = [
        '1' => 0, '2' => 1, '3' => 2, '4' => 3,
        'USER' => 8, 'ADMIN' => 9, 'DISABLED' => 10, 'MODERATOR' => 11,
        'User' => 16, 'Admin' => 17, 'Disabled' => 18, 'Moderator' => 19,
    ];

    /** Lowercased trimmed alias => case. */
    private const array W64_ROLE_ALIASES = [
        'user' => 0, 'users' => 0, 'member' => 0, 'regular' => 0,
        'admin' => 1, 'admins' => 1, 'administrator' => 1, 'root' => 1, 'superuser' => 1,
        'disabled' => 2, 'banned' => 2, 'suspended' => 2, 'inactive' => 2, 'locked' => 2,
        'moderator' => 3, 'moderators' => 3, 'mod' => 3, 'mods' => 3, 'staff' => 3,
    ];

    /**
     * Plan per-role request admission. Roles resolve by backing value (source 0), case name (1), label (2), an
     * alias of `strtolower(trim($role))` (3), else unknown case 4 (4). Cases follow declaration order (User 0,
     * Admin 1, Disabled 2, Moderator 3). Requests in the window clamp to 0..2^32-1; the base quota is 100 for a
     * quota at or below 0, else capped at 2^31. Users get the base quota, moderators five times it, admins no
     * limit. Action 0 allow, 1 throttle (requests at or above the limit), 2 deny (disabled), 3 reject (unknown).
     *
     * Layout: `[0]` count, `[1..6]` per case, `[6..11]` per source, `[11..15]` per action, `[15]` elevated roles,
     * `[16]` base quota, `[17]` total requests, `[18]` first throttled and `[19]` first rejected index (-1),
     * `[20]` mask of known cases, `[21]` FNV-1a digest; then per role `case | source << 3 | flags << 6 | action
     * << 11 | min(token bytes, 255) << 13 | requests << 21` (flags as `nntmux_signup_role_queue_flags`).
     *
     * @param  array<array-key, int|string>  $roles
     * @param  array<array-key, int>  $requests
     * @return list<int>
     */
    public static function w64UserRolePlanBatch(array $roles, array $requests, int $quota = 0): array
    {
        return (self::usesW64RustFfi() ? self::w64UserRolePlanBatchNative($roles, $requests, $quota) : null)
            ?? self::w64UserRolePlanBatchPhp($roles, $requests, $quota);
    }

    /**
     * @param  array<array-key, int|string>  $roles
     * @param  array<array-key, int>  $requests
     * @return list<int>
     */
    public static function w64UserRolePlanBatchPhp(array $roles, array $requests, int $quota = 0): array
    {
        $roles = array_values($roles);
        $count = \count($roles);
        $requests = self::w64Column($requests, $count);
        $base = $quota <= 0 ? self::W64_ROLE_QUOTA : min($quota, self::W64_ROLE_QUOTA_CAP);
        $out = array_fill(0, self::W64_ROLE_HEADER, 0);
        $out[0] = $count;
        $out[16] = $base;
        $out[18] = $out[19] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($roles as $at => $role) {
            $role = (string) $role;
            $code = self::W64_ROLE_TOKENS[$role] ?? null;
            if ($code === null) {
                $case = self::W64_ROLE_ALIASES[strtolower(trim($role))] ?? 4;
                $source = $case === 4 ? 4 : 3;
            } else {
                $case = $code & 7;
                $source = $code >> 3;
            }
            $used = max(0, min((int) $requests[$at], self::W64_ROLE_REQUEST_CAP));
            $action = match ($case) {
                1 => 0,
                2 => 2,
                4 => 3,
                default => $used >= ($case === 3 ? $base * 5 : $base) ? 1 : 0,
            };
            $flags = self::W64_ROLE_FLAGS[$case];
            $out[1 + $case]++;
            $out[6 + $source]++;
            $out[11 + $action]++;
            $out[15] += $flags >> 4 & 1;
            $out[17] += $used;
            if ($action === 1 && $out[18] < 0) {
                $out[18] = $at;
            }
            if ($action === 3 && $out[19] < 0) {
                $out[19] = $at;
            }
            if ($case < 4) {
                $out[20] |= 1 << $case;
            }
            $row = $case | $source << 3 | $flags << 6 | $action << 11 | min(\strlen($role), 255) << 13 | $used << 21;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[21] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $roles
     * @param  array<array-key, int>  $requests
     * @return list<int>|null
     */
    public static function w64UserRolePlanBatchNative(array $roles, array $requests, int $quota = 0): ?array
    {
        $count = \count($roles);
        if ($count > self::W64_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w64Strings(array_values($roles));
        $ints = pack('q*', ...self::w64Column($requests, $count));
        $words = self::W64_ROLE_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w64Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w646_user_role_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $quota, $out, $words));
    }

    /**
     * Route the Wave 64.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /**
     * Route the Wave 64.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 64.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $forced = self::w64RustFfiState();

        return \is_bool($forced) ? $forced : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 64.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 64.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w646', self::W64_ROLE_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 64.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w64Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w64RustFfiState('binding', $fields);
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
    private static function w64Strings(array $items): array
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
    private static function w64Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
