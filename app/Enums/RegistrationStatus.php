<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum RegistrationStatus: int
{
    public const string FFI_CDEF = SignupError::FFI_CDEF;

    private const int FFI_KIND = 3;

    private const int DECLINE = -2147483648;

    case Open = 0;
    case Invite = 1;
    case Closed = 2;

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
            self::Open => 'Open',
            self::Invite => 'Invite',
            self::Closed => 'Closed',
        };
    }

    public function labelNative(): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->labelPhp();
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return $this->labelPhp();
            }
            $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $this->value, $scratch, $cap);
            if (\is_int($written) && $written < 0 && $written > self::DECLINE) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $this->value, $scratch, $cap);
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return FFI::string($scratch, $written);
            }
        } catch (Throwable) {
        }

        return $this->labelPhp();
    }

    /**
     * Resolve a case from its exact display label.
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

    // Rust is the default only while the paired W62.6 benchmark clears 1.5x on all four metrics.
    public const bool W62_RUST_FFI_DEFAULT = true;

    private const string W62_REGISTRATION_FFI_CDEF = <<<'C'
        int32_t nntmux_w626_registration_status_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t seats, int64_t *out, size_t out_cap);
        C;

    public const int W62_REGISTRATION_HEADER = 27;

    private const int W62_MAX_ITEMS = 1_048_576;

    private const int W62_SEAT_CAP = (1 << 40) - 1;

    /** Exact token => `case | source << 2`. */
    private const array W62_TOKENS = ['0' => 0, '1' => 1, '2' => 2, 'Open' => 4, 'Invite' => 5, 'Closed' => 6];

    /** Lowercased trimmed alias => case. */
    private const array W62_ALIASES = [
        'open' => 0, 'public' => 0, 'enabled' => 0, 'yes' => 0, 'true' => 0, 'on' => 0,
        'invite' => 1, 'invite-only' => 1, 'invite_only' => 1, 'invitation' => 1, 'invites' => 1,
        'closed' => 2, 'disabled' => 2, 'off' => 2, 'no' => 2, 'false' => 2, 'private' => 2,
    ];

    /**
     * Plan signup attempts in order. Statuses resolve by backing value (source 0), case name (1), an alias of
     * `strtolower(trim($status))` (2), else unknown case 3 (3). Invite states: 0 none, 1 valid, 2 expired, 3
     * used, anything else 4 invalid. `$seats` remaining signups (negative unlimited, else clamped to 2^40-1)
     * are consumed in order. Gates: 0 open, 1 invite accepted, 2 invite missing, 3 invite rejected, 4 closed, 5
     * unknown status (fails closed), 6 no seats left; gates 0 and 1 are eligible and invite signups consume
     * their invite.
     *
     * Layout: `[0]` count, `[1..4]` per case, `[5..8]` per source, `[9..13]` per invite state, `[14..20]` per
     * gate, `[21]` eligible, `[22]` invites consumed, `[23]` first eligible and `[24]` first denied index (-1),
     * `[25]` seats left (-1 unlimited), `[26]` FNV-1a digest; then per signup `case | source << 2 | invite << 4 |
     * gate << 7 | eligible << 10 | consumed << 11 | min(token bytes, 255) << 12 | (seats left + 1) << 20`.
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $invites
     * @return list<int>
     */
    public static function planRegistrationStatusBatch(array $statuses, array $invites, int $seats = -1): array
    {
        return (self::usesW62RustFfi() ? self::planRegistrationStatusBatchNative($statuses, $invites, $seats) : null)
            ?? self::planRegistrationStatusBatchPhp($statuses, $invites, $seats);
    }

    /**
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $invites
     * @return list<int>
     */
    public static function planRegistrationStatusBatchPhp(array $statuses, array $invites, int $seats = -1): array
    {
        $statuses = array_values($statuses);
        $count = \count($statuses);
        $invites = self::w62Column($invites, $count);
        $remaining = $seats < 0 ? -1 : min($seats, self::W62_SEAT_CAP);
        $out = array_fill(0, self::W62_REGISTRATION_HEADER, 0);
        $out[0] = $count;
        $out[23] = -1;
        $out[24] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($statuses as $at => $status) {
            $status = (string) $status;
            $code = self::W62_TOKENS[$status] ?? null;
            if ($code === null) {
                $case = self::W62_ALIASES[strtolower(trim($status))] ?? 3;
                $source = $case === 3 ? 3 : 2;
            } else {
                $case = $code & 3;
                $source = $code >> 2;
            }
            $invite = (int) $invites[$at];
            if ($invite < 0 || $invite > 3) {
                $invite = 4;
            }
            $gate = match ($case) {
                0 => 0,
                1 => match ($invite) {
                    1 => 1,
                    0 => 2,
                    default => 3,
                },
                2 => 4,
                default => 5,
            };
            if ($gate <= 1) {
                if ($remaining === 0) {
                    $gate = 6;
                } elseif ($remaining > 0) {
                    $remaining--;
                }
            }
            $eligible = $gate <= 1 ? 1 : 0;
            $consumed = $gate === 1 ? 1 : 0;
            $out[1 + $case]++;
            $out[5 + $source]++;
            $out[9 + $invite]++;
            $out[14 + $gate]++;
            $out[21] += $eligible;
            $out[22] += $consumed;
            $first = $eligible === 1 ? 23 : 24;
            if ($out[$first] < 0) {
                $out[$first] = $at;
            }
            $row = $case | $source << 2 | $invite << 4 | $gate << 7 | $eligible << 10 | $consumed << 11 | min(\strlen($status), 255) << 12 | ($remaining + 1) << 20;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[25] = $remaining;
        $out[26] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $statuses
     * @param  array<array-key, int>  $invites
     * @return list<int>|null
     */
    public static function planRegistrationStatusBatchNative(array $statuses, array $invites, int $seats = -1): ?array
    {
        $count = \count($statuses);
        if ($count > self::W62_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w62Strings(array_values($statuses));
        $ints = pack('q*', ...self::w62Column($invites, $count));
        $words = self::W62_REGISTRATION_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w62Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w626_registration_status_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $seats, $out, $words));
    }

    /**
     * Run `$callback` with the Wave 62.6 Rust batch path forced on or off, then restore the previous routing.
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

    public static function usesW62RustFfi(): bool
    {
        $forced = self::w62RustFfiState();

        return \is_bool($forced) ? $forced : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 62.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'binding'` returns the cached binding and an output buffer of at least `$val` values, or null.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w626', self::W62_REGISTRATION_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 62.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w62Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w62RustFfiState('binding', $fields);
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
    private static function w62Strings(array $items): array
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
    private static function w62Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
