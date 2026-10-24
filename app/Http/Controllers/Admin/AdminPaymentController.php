<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Controllers\BtcPaymentController;
use App\Models\Payment;
use App\Support\RustPipelineLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminPaymentController extends BasePageController
{
    // PHP remains the default unless every common and uncommon workload passes the 1.5x gate.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public const string FFI_CDEF = 'int32_t nntmux_w29_admin_settings_status_monitoring_payment(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    public static function normalizeFilters(array $filters): array
    {
        return self::$useRustFfi ? self::normalizeFiltersNative($filters) : self::normalizeFiltersPhp($filters);
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    public static function normalizeFiltersPhp(array $filters): array
    {
        $result = [];
        foreach (['username', 'email', 'payment_status', 'invoice_status', 'start_date', 'end_date'] as $key) {
            $result[$key] = Str::trim($filters[$key] ?? '');
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    public static function normalizeFiltersNative(array $filters): array
    {
        $result = AdminPaymentController::transformNative(8, [$filters]);

        return is_array($result) ? $result : self::normalizeFiltersPhp($filters);
    }

    public static function sortColumn(string $sort): string
    {
        return self::$useRustFfi ? self::sortColumnNative($sort) : self::sortColumnPhp($sort);
    }

    public static function sortColumnPhp(string $sort): string
    {
        return in_array($sort, self::ALLOWED_SORT_COLUMNS, true) ? $sort : 'created_at';
    }

    public static function sortColumnNative(string $sort): string
    {
        $result = AdminPaymentController::transformNative(9, [$sort, self::ALLOWED_SORT_COLUMNS]);

        return is_string($result) ? $result : self::sortColumnPhp($sort);
    }

    public static function sortDirection(string $order): string
    {
        return self::$useRustFfi ? self::sortDirectionNative($order) : self::sortDirectionPhp($order);
    }

    public static function sortDirectionPhp(string $order): string
    {
        return strtolower($order) === 'asc' ? 'asc' : 'desc';
    }

    public static function sortDirectionNative(string $order): string
    {
        $result = AdminPaymentController::transformNative(10, [$order]);

        return is_string($result) ? $result : self::sortDirectionPhp($order);
    }

    /**
     * @param  array<array-key, array{tx_count: int, invoice_total: float, value_total: float}>  $rows
     * @return array{tx_count: int, invoice_total: float, value_total: float}
     */
    public static function summaryTotals(array $rows): array
    {
        return self::$useRustFfi ? self::summaryTotalsNative($rows) : self::summaryTotalsPhp($rows);
    }

    /**
     * @param  array<array-key, array{tx_count: int, invoice_total: float, value_total: float}>  $rows
     * @return array{tx_count: int, invoice_total: float, value_total: float}
     */
    public static function summaryTotalsPhp(array $rows): array
    {
        $count = 0;
        $invoice = 0.0;
        $value = 0.0;
        foreach ($rows as $row) {
            $count += $row['tx_count'];
            $invoice += $row['invoice_total'];
            $value += $row['value_total'];
        }

        return ['tx_count' => (int) $count, 'invoice_total' => $invoice, 'value_total' => $value];
    }

    /**
     * @param  array<array-key, array{tx_count: int, invoice_total: float, value_total: float}>  $rows
     * @return array{tx_count: int, invoice_total: float, value_total: float}
     */
    public static function summaryTotalsNative(array $rows): array
    {
        $result = AdminPaymentController::transformNative(11, [$rows]);

        return is_array($result) && isset($result['tx_count'], $result['invoice_total'], $result['value_total']) ? $result : self::summaryTotalsPhp($rows);
    }

    /** @param list<mixed> $args */
    public static function transformNative(int $operation, array $args): mixed
    {
        try {
            $budget = 67108864;
            $nodes = 100000;
            if (! array_is_list($args) || ! self::validWireValue($args, 0, $budget, $nodes)) {
                return null;
            }
            $input = serialize($args);
            if (strlen($input) > 67108864) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('w29-admin-settings-status-monitoring-payment', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, min(67108864, max(256, strlen($input) + 256)), $capacity);
            if ($output === null) {
                return null;
            }
            $capacity = min($capacity, 67108864);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w29_admin_settings_status_monitoring_payment($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && -$written > $capacity && -$written <= 67108864) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                $capacity = min($capacity, 67108864);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w29_admin_settings_status_monitoring_payment($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $wire = \FFI::string($output, $written);
            $result = @unserialize($wire, ['allowed_classes' => false, 'max_depth' => 32]);
            $budget = 67108864;
            $nodes = 100000;
            if (! self::validWireValue($result, 0, $budget, $nodes) || serialize($result) !== $wire) {
                return null;
            }

            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function validWireValue(mixed $value, int $depth, int &$budget, int &$nodes): bool
    {
        if ($depth > 32 || --$nodes < 0) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null || ! self::validWireValue($key, $depth + 1, $budget, $nodes) || ! self::validWireValue($item, $depth + 1, $budget, $nodes)) {
                    return false;
                }
            }

            return true;
        }
        if (is_string($value)) {
            $budget -= strlen($value);

            return $budget >= 0;
        }

        return $value === null || is_int($value) || is_float($value) || is_bool($value);
    }

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SORT_COLUMNS = [
        'id',
        'created_at',
        'username',
        'email',
        'invoice_amount',
        'payment_status',
        'invoice_status',
        'order_id',
    ];

    public function index(Request $request): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Payments';

        $filters = self::normalizeFilters([
            'username' => $request->string('username')->value(),
            'email' => $request->string('email')->value(),
            'payment_status' => $request->string('payment_status')->value(),
            'invoice_status' => $request->string('invoice_status')->value(),
            'start_date' => $request->string('start_date')->value(),
            'end_date' => $request->string('end_date')->value(),
        ]);
        $sort = self::sortColumn($request->string('sort')->value());
        $order = self::sortDirection($request->string('order')->value());

        $payments = Payment::query()
            ->filter($filters)
            ->orderBy($sort, $order)
            ->paginate((int) config('nntmux.items_per_page'))
            ->withQueryString();

        $summary = Payment::query()
            ->filter($filters)
            ->selectRaw("
                COALESCE(NULLIF(payment_method, ''), 'Unknown') AS method,
                COUNT(*) AS tx_count,
                COALESCE(SUM(CAST(NULLIF(invoice_amount, '') AS DECIMAL(20,8))), 0) AS invoice_total,
                COALESCE(SUM(CAST(NULLIF(payment_value, '') AS DECIMAL(20,8))), 0) AS value_total
            ")
            ->groupBy('method')
            ->orderBy('method')
            ->get();

        $summaryTotals = self::summaryTotals($summary->map(static fn (Payment $payment): array => [
            'tx_count' => (int) $payment->getAttribute('tx_count'),
            'invoice_total' => (float) $payment->getAttribute('invoice_total'),
            'value_total' => (float) $payment->getAttribute('value_total'),
        ])->all());

        $paymentStatuses = BtcPaymentController::paymentStatusesForAdminFilter();
        $invoiceStatuses = BtcPaymentController::invoiceStatusesForAdminFilter();

        $this->viewData = array_merge($this->viewData, [
            'payments' => $payments,
            'summary' => $summary,
            'summaryTotals' => $summaryTotals,
            'filters' => $filters,
            'sort' => $sort,
            'order' => $order,
            'paymentStatuses' => $paymentStatuses,
            'invoiceStatuses' => $invoiceStatuses,
            'title' => $title,
            'meta_title' => $meta_title,
            'page_title' => $title,
        ]);

        return view('admin.payments.index', $this->viewData);
    }
}
