<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * App\Models\RoleExpirationEmail.
 *
 * @property int $id
 * @property int $users_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $day
 * @property int $week
 * @property int $month
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail whereDay($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail whereMonth($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail whereUsersId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RoleExpirationEmail whereWeek($value)
 *
 * @mixin \Eloquent
 */
class RoleExpirationEmail extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_role_expiration_email_warning_plan(
            uint32_t flags,
            int64_t expires_at,
            int64_t now,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustGdprSecurityRegistrationModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    /** 9999-12-31T23:59:59Z; timestamps are clamped to [0, this] so the difference never overflows. */
    private const int MAX_TIMESTAMP = 253402300799;

    private const int DAY_SECONDS = 86400;

    private const int WEEK_SECONDS = 604800;

    private const int MONTH_SECONDS = 2592000;

    private const int DAY_SENT = 1;

    private const int WEEK_SENT = 2;

    private const int MONTH_SENT = 4;

    private const array PERIODS = ['day', 'week', 'month'];

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
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
     * Plans the next role expiry reminder: only the tightest window (1, 7 or 30 days before
     * expiry) applies, and it is due once when its flag is not yet set. The returned flags
     * include the due period.
     *
     * @return array{seconds_remaining: int, is_expired: bool, due_period: ?string, day: bool, week: bool, month: bool}
     */
    public static function expiryWarningPlan(int $expiresAt, int $now, bool $daySent = false, bool $weekSent = false, bool $monthSent = false): array
    {
        return self::usesRustFfi()
            ? self::expiryWarningPlanNative($expiresAt, $now, $daySent, $weekSent, $monthSent)
            : self::expiryWarningPlanPhp($expiresAt, $now, $daySent, $weekSent, $monthSent);
    }

    /**
     * @return array{seconds_remaining: int, is_expired: bool, due_period: ?string, day: bool, week: bool, month: bool}
     */
    public static function expiryWarningPlanNative(int $expiresAt, int $now, bool $daySent = false, bool $weekSent = false, bool $monthSent = false): array
    {
        $flags = ($daySent ? self::DAY_SENT : 0) | ($weekSent ? self::WEEK_SENT : 0) | ($monthSent ? self::MONTH_SENT : 0);
        $fields = self::callNative('nntmux_role_expiration_email_warning_plan', [$flags, $expiresAt, $now]);
        if ($fields === null || count($fields) !== 6 || $fields[0] === null || $fields[1] === null
            || ($fields[2] !== null && ! in_array($fields[2], self::PERIODS, true))
            || $fields[3] === null || $fields[4] === null || $fields[5] === null) {
            return self::expiryWarningPlanPhp($expiresAt, $now, $daySent, $weekSent, $monthSent);
        }

        return [
            'seconds_remaining' => (int) $fields[0],
            'is_expired' => $fields[1] === '1',
            'due_period' => $fields[2],
            'day' => $fields[3] === '1',
            'week' => $fields[4] === '1',
            'month' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{seconds_remaining: int, is_expired: bool, due_period: ?string, day: bool, week: bool, month: bool}
     */
    public static function expiryWarningPlanPhp(int $expiresAt, int $now, bool $daySent = false, bool $weekSent = false, bool $monthSent = false): array
    {
        $remaining = max(0, min($expiresAt, self::MAX_TIMESTAMP)) - max(0, min($now, self::MAX_TIMESTAMP));
        $due = match (true) {
            $remaining <= 0 => null,
            $remaining <= self::DAY_SECONDS => $daySent ? null : 'day',
            $remaining <= self::WEEK_SECONDS => $weekSent ? null : 'week',
            $remaining <= self::MONTH_SECONDS => $monthSent ? null : 'month',
            default => null,
        };

        return [
            'seconds_remaining' => $remaining,
            'is_expired' => $remaining <= 0,
            'due_period' => $due,
            'day' => $daySent || $due === 'day',
            'week' => $weekSent || $due === 'week',
            'month' => $monthSent || $due === 'month',
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
