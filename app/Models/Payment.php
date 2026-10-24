<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

class Payment extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_payment_normalize_filter_spec(
            uint32_t has_username,
            const char *username_ptr,
            size_t username_len,
            uint32_t has_email,
            const char *email_ptr,
            size_t email_len,
            uint32_t has_payment_status,
            const char *payment_status_ptr,
            size_t payment_status_len,
            uint32_t has_invoice_status,
            const char *invoice_status_ptr,
            size_t invoice_status_len,
            uint32_t has_start_date,
            uint32_t has_end_date,
            size_t *out_meta,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustRolePaymentGenreMetricModelParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $guarded = [];

    /** Stored from BTCPay webhook payload.payment.status; duplicate checks use Settled. */
    public const PAYMENT_STATUS_SETTLED = 'Settled';

    /** Migration default; webhook clears to Settled after role upgrade. */
    public const INVOICE_STATUS_PENDING = 'Pending';

    public const INVOICE_STATUS_SETTLED = 'Settled';

    public static function setUseRustFfi(bool $useRustFfi = true): void
    {
        self::$useRustFfi = $useRustFfi;
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
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @param  Builder<Payment>  $query
     * @param  array{username?: string, email?: string, payment_status?: string, invoice_status?: string, start_date?: string, end_date?: string}  $filters
     * @return Builder<Payment>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $spec = self::normalizeFilterSpec($filters);

        if ($spec['username_like'] !== null) {
            $query->where('username', 'like', $spec['username_like']);
        }

        if ($spec['email_like'] !== null) {
            $query->where('email', 'like', $spec['email_like']);
        }

        if ($spec['payment_status'] !== null) {
            $query->where('payment_status', $spec['payment_status']);
        }

        if ($spec['invoice_mode'] === 'pending_or_null') {
            $query->where(function (Builder $q): void {
                $q->whereNull('invoice_status')
                    ->orWhere('invoice_status', self::INVOICE_STATUS_PENDING);
            });
        } elseif ($spec['invoice_mode'] === 'exact' && $spec['invoice_status'] !== null) {
            $query->where('invoice_status', $spec['invoice_status']);
        }

        if ($spec['has_start_date'] && ! empty($filters['start_date'])) {
            try {
                $query->where('created_at', '>=', Carbon::parse($filters['start_date'])->startOfDay());
            } catch (\Exception) {
                // ignore invalid date input
            }
        }

        if ($spec['has_end_date'] && ! empty($filters['end_date'])) {
            try {
                $query->where('created_at', '<=', Carbon::parse($filters['end_date'])->endOfDay());
            } catch (\Exception) {
                // ignore invalid date input
            }
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     username_like: ?string,
     *     email_like: ?string,
     *     payment_status: ?string,
     *     invoice_mode: 'none'|'pending_or_null'|'exact',
     *     invoice_status: ?string,
     *     has_start_date: bool,
     *     has_end_date: bool
     * }
     */
    public static function normalizeFilterSpec(array $filters): array
    {
        return self::$useRustFfi
            ? self::normalizeFilterSpecNative($filters)
            : self::normalizeFilterSpecPhp($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     username_like: ?string,
     *     email_like: ?string,
     *     payment_status: ?string,
     *     invoice_mode: 'none'|'pending_or_null'|'exact',
     *     invoice_status: ?string,
     *     has_start_date: bool,
     *     has_end_date: bool
     * }
     */
    public static function normalizeFilterSpecNative(array $filters): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeFilterSpecPhp($filters);
        }

        $username = ! empty($filters['username']) ? (string) $filters['username'] : '';
        $email = ! empty($filters['email']) ? (string) $filters['email'] : '';
        $paymentStatus = ! empty($filters['payment_status']) ? (string) $filters['payment_status'] : '';
        $invoiceStatus = ! empty($filters['invoice_status']) ? (string) $filters['invoice_status'] : '';
        $hasStart = ! empty($filters['start_date']);
        $hasEnd = ! empty($filters['end_date']);

        try {
            $metaSlot = $ffi->new('size_t[10]');
            $capacity = 0;
            $requiredCap = max(
                128,
                strlen($username) + strlen($email) + strlen($paymentStatus) + strlen($invoiceStatus) + 32
            );
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, $requiredCap, $capacity);
            if ($outBuf === null) {
                return self::normalizeFilterSpecPhp($filters);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_payment_normalize_filter_spec',
                $username !== '' ? 1 : 0,
                $username,
                strlen($username),
                $email !== '' ? 1 : 0,
                $email,
                strlen($email),
                $paymentStatus !== '' ? 1 : 0,
                $paymentStatus,
                strlen($paymentStatus),
                $invoiceStatus !== '' ? 1 : 0,
                $invoiceStatus,
                strlen($invoiceStatus),
                $hasStart ? 1 : 0,
                $hasEnd ? 1 : 0,
                $metaSlot,
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                $uActive = ((int) $metaSlot[0]) === 1;
                $uLen = (int) $metaSlot[1];
                $eActive = ((int) $metaSlot[2]) === 1;
                $eLen = (int) $metaSlot[3];
                $pActive = ((int) $metaSlot[4]) === 1;
                $pLen = (int) $metaSlot[5];
                $iModeInt = (int) $metaSlot[6];
                $iLen = (int) $metaSlot[7];
                $startFlag = ((int) $metaSlot[8]) === 1;
                $endFlag = ((int) $metaSlot[9]) === 1;

                if ($uLen + $eLen + $pLen + $iLen === $written) {
                    $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
                    $pos = 0;

                    $uSlice = $uActive ? substr($payload, $pos, $uLen) : null;
                    $pos += $uLen;
                    $eSlice = $eActive ? substr($payload, $pos, $eLen) : null;
                    $pos += $eLen;
                    $pSlice = $pActive ? substr($payload, $pos, $pLen) : null;
                    $pos += $pLen;
                    $iSlice = $iModeInt > 0 ? substr($payload, $pos, $iLen) : null;

                    $invoiceMode = match ($iModeInt) {
                        1 => 'pending_or_null',
                        2 => 'exact',
                        default => 'none',
                    };

                    return [
                        'username_like' => $uSlice,
                        'email_like' => $eSlice,
                        'payment_status' => $pSlice,
                        'invoice_mode' => $invoiceMode,
                        'invoice_status' => $iSlice,
                        'has_start_date' => $startFlag,
                        'has_end_date' => $endFlag,
                    ];
                }
            }
        } catch (Throwable) {
        }

        return self::normalizeFilterSpecPhp($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     username_like: ?string,
     *     email_like: ?string,
     *     payment_status: ?string,
     *     invoice_mode: 'none'|'pending_or_null'|'exact',
     *     invoice_status: ?string,
     *     has_start_date: bool,
     *     has_end_date: bool
     * }
     */
    public static function normalizeFilterSpecPhp(array $filters): array
    {
        $invoiceStatus = ! empty($filters['invoice_status']) ? (string) $filters['invoice_status'] : null;

        return [
            'username_like' => ! empty($filters['username']) ? '%'.$filters['username'].'%' : null,
            'email_like' => ! empty($filters['email']) ? '%'.$filters['email'].'%' : null,
            'payment_status' => ! empty($filters['payment_status']) ? (string) $filters['payment_status'] : null,
            'invoice_mode' => $invoiceStatus === null
                ? 'none'
                : ($invoiceStatus === self::INVOICE_STATUS_PENDING ? 'pending_or_null' : 'exact'),
            'invoice_status' => $invoiceStatus,
            'has_start_date' => ! empty($filters['start_date']),
            'has_end_date' => ! empty($filters['end_date']),
        ];
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
