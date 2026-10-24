<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\AdminPaymentController;
use App\Models\Payment;
use App\Models\User;
use App\Services\AdminDashboardSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BtcPaymentController extends BasePageController
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

    public static function verifyWebhookSignature(string $body, string $secret, ?string $signature): bool
    {
        return self::$useRustFfi ? self::verifyWebhookSignatureNative($body, $secret, $signature) : self::verifyWebhookSignaturePhp($body, $secret, $signature);
    }

    public static function verifyWebhookSignaturePhp(string $body, string $secret, ?string $signature): bool
    {
        return $signature !== null && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }

    public static function verifyWebhookSignatureNative(string $body, string $secret, ?string $signature): bool
    {
        $result = AdminPaymentController::transformNative(12, [$body, $secret, $signature]);

        return is_bool($result) ? $result : self::verifyWebhookSignaturePhp($body, $secret, $signature);
    }

    /**
     * @return list<string>
     */
    public static function fallbackEmails(string $email): array
    {
        return self::$useRustFfi ? self::fallbackEmailsNative($email) : self::fallbackEmailsPhp($email);
    }

    /**
     * @return list<string>
     */
    public static function fallbackEmailsPhp(string $email): array
    {
        if (! str_ends_with($email, '@pm.me')) {
            return [];
        }

        $local = substr($email, 0, -6);

        return [$local.'@proton.me', $local.'@protonmail.com'];
    }

    /**
     * @return list<string>
     */
    public static function fallbackEmailsNative(string $email): array
    {
        $result = AdminPaymentController::transformNative(13, [$email]);

        return is_array($result) ? $result : self::fallbackEmailsPhp($email);
    }

    /**
     * @return array{role: string, addYears: ?int}
     */
    public static function parseItemDescription(string $description): array
    {
        return self::$useRustFfi ? self::parseItemDescriptionNative($description) : self::parseItemDescriptionPhp($description);
    }

    /**
     * @return array{role: string, addYears: ?int}
     */
    public static function parseItemDescriptionPhp(string $description): array
    {
        if (preg_match('/(?P<role>\w+(\s\+\+)?)[\s]+(?P<addYears>\d+)/i', $description, $matches)) {
            return ['role' => $matches['role'], 'addYears' => (int) $matches['addYears']];
        }

        return ['role' => $description, 'addYears' => null];
    }

    /**
     * @return array{role: string, addYears: ?int}
     */
    public static function parseItemDescriptionNative(string $description): array
    {
        $result = AdminPaymentController::transformNative(14, [$description]);

        return is_array($result) && isset($result['role']) && array_key_exists('addYears', $result) ? $result : self::parseItemDescriptionPhp($description);
    }

    /**
     * BtcPay callback.
     */
    public function btcPayCallback(Request $request): Response
    {
        if (! self::verifyWebhookSignature($request->getContent(), (string) config('nntmux.btcpay_webhook_secret'), $request->header('btcpay-sig'))) {
            Log::channel('btc_payment')->error('BTCPay webhook hash check failed: '.$request->header('btcpay-sig'));

            return response('Not Found', 404);
        }
        $payload = json_decode($request->getContent(), true);
        // We have received a payment for an invoice and user should be upgraded to a paid plan based on order
        if ($payload['type'] === 'InvoicePaymentSettled') {
            $user = User::query()->where('email', '=', $payload['metadata']['buyerEmail'])->first();
            if ($user) {
                $checkOrder = Payment::query()->where('invoice_id', '=', $payload['invoiceId'])->where('payment_status', '=', Payment::PAYMENT_STATUS_SETTLED)->first();
                if ($checkOrder !== null) {
                    Log::channel('btc_payment')->error('Duplicate BTCPay webhook: '.$payload['webhookId']);

                    return response('OK', 200);
                }

                Payment::create([
                    'email' => $payload['metadata']['buyerEmail'],
                    'username' => $user->username,
                    'item_description' => $payload['metadata']['itemDesc'],
                    'order_id' => $payload['metadata']['orderId'],
                    'payment_id' => $payload['payment']['id'],
                    'payment_status' => $payload['payment']['status'],
                    'invoice_amount' => $payload['metadata']['invoice_amount'],
                    'payment_method' => $payload['paymentMethodId'],
                    'payment_value' => $payload['payment']['value'],
                    'webhook_id' => $payload['webhookId'],
                    'invoice_id' => $payload['invoiceId'],
                ]);

                Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);

                return response('OK', 200);
            }

            Log::channel('btc_payment')->error('User not found for BTCPay webhook: '.$payload['metadata']['buyerEmail']);

            return response('Not Found', 404);
        }

        if ($payload['type'] === 'InvoiceSettled') {
            // Check if we have the invoice_id in payments table and if we do, update the user account
            $checkOrder = Payment::query()->where('invoice_id', '=', $payload['invoiceId'])->where('payment_status', '=', Payment::PAYMENT_STATUS_SETTLED)->where(function ($query) {
                return $query->where('invoice_status', Payment::INVOICE_STATUS_PENDING)->orWhereNull('invoice_status');
            })->first();
            if ($checkOrder !== null) {
                $user = User::query()->where('email', '=', $checkOrder->email)->first();

                if (! $user) {
                    foreach (self::fallbackEmails($checkOrder->email) as $email) {
                        $user = User::query()->where('email', '=', $email)->first();
                        if ($user) {
                            break;
                        }
                    }
                }
                if ($user) {
                    $description = self::parseItemDescription($checkOrder->item_description);
                    $roleName = $description['role'];
                    $addYears = $description['addYears'];

                    User::updateUserRole($user->id, $roleName, addYears: $addYears);
                    $checkOrder->update(['invoice_status' => Payment::INVOICE_STATUS_SETTLED]);
                    Log::channel('btc_payment')->info('User: '.$user->username.' upgraded to '.$roleName.' (+'.$addYears.' years) for BTCPay webhook: '.$checkOrder->webhook_id);

                    return response('OK', 200);
                }

                Log::channel('btc_payment')->error('User not found for BTCPay webhook: '.$checkOrder->webhook_id);

                return response('Not Found', 404);
            }

            $existingPayment = Payment::query()->where('invoice_id', '=', $payload['invoiceId'])->first();
            if ($existingPayment === null) {
                Log::channel('btc_payment')->error('InvoiceSettled webhook received but no payment recorded for invoice: '.$payload['invoiceId'].' (webhook: '.($payload['webhookId'] ?? 'unknown').', buyer: '.($payload['metadata']['buyerEmail'] ?? 'unknown').')');

                return response('Not Found', 404);
            }

            Log::channel('btc_payment')->info('Duplicate InvoiceSettled webhook for already settled invoice: '.$payload['invoiceId']);
        }

        return response('OK', 200);
    }

    /**
     * @return list<string>
     */
    public static function paymentStatusesForAdminFilter(): array
    {
        return self::$useRustFfi ? self::paymentStatusesForAdminFilterNative() : self::paymentStatusesForAdminFilterPhp();
    }

    /**
     * @return list<string>
     */
    public static function paymentStatusesForAdminFilterPhp(): array
    {
        return ['Pending', 'Processing', Payment::PAYMENT_STATUS_SETTLED, 'Invalid', 'Manual'];
    }

    /**
     * @return list<string>
     */
    public static function paymentStatusesForAdminFilterNative(): array
    {
        $result = AdminPaymentController::transformNative(15, []);

        return is_array($result) ? $result : self::paymentStatusesForAdminFilterPhp();
    }

    /**
     * @return list<string>
     */
    public static function invoiceStatusesForAdminFilter(): array
    {
        return self::$useRustFfi ? self::invoiceStatusesForAdminFilterNative() : self::invoiceStatusesForAdminFilterPhp();
    }

    /**
     * @return list<string>
     */
    public static function invoiceStatusesForAdminFilterPhp(): array
    {
        return [Payment::INVOICE_STATUS_PENDING, Payment::INVOICE_STATUS_SETTLED];
    }

    /**
     * @return list<string>
     */
    public static function invoiceStatusesForAdminFilterNative(): array
    {
        $result = AdminPaymentController::transformNative(16, []);

        return is_array($result) ? $result : self::invoiceStatusesForAdminFilterPhp();
    }
}
