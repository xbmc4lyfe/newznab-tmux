<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Mail\GdprRequestStatusMail;
use App\Models\GdprRequest;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Mail;
use Throwable;

class GdprNotificationService
{
    public const string FFI_CDEF = 'int32_t nntmux_w23_gdpr_export_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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

    /** @param list<string|null> $fields */
    private static function callNative(int $operation, array $fields): ?string
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave23-gdpr-export-mail', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }

            $input = '';
            foreach ($fields as $field) {
                if ($field !== null && strlen($field) >= 0xFFFFFFFF) {
                    return null;
                }
                $input .= pack('V', $field === null ? 0xFFFFFFFF : strlen($field)).($field ?? '');
            }

            $capacity = min(67108864, max(64, strlen($input)));
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = self::$ffi->new("char[$capacity]");
                // @phpstan-ignore method.notFound
                $written = self::$ffi->nntmux_w23_gdpr_export_mail($operation, $input, strlen($input), $buffer, $capacity);
                if (! is_int($written) || $written === -1) {
                    return null;
                }
                if ($written >= 0) {
                    return $written <= $capacity ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
                }
                $required = -$written;
                if ($required <= $capacity || $required > 67108864) {
                    return null;
                }
                $capacity = $required;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public static function normalizeRecipientEmail(string $email): ?string
    {
        return self::$useRustFfi ? self::normalizeRecipientEmailNative($email) : self::normalizeRecipientEmailPhp($email);
    }

    public static function normalizeRecipientEmailPhp(string $email): ?string
    {
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public static function normalizeRecipientEmailNative(string $email): ?string
    {
        $result = self::callNative(8, [$email]);
        if ($result === null) {
            return self::normalizeRecipientEmailPhp($email);
        }

        return $result === '' ? null : $result;
    }

    public static function shouldSendAdminCopy(string $type, string $event): bool
    {
        return self::$useRustFfi ? self::shouldSendAdminCopyNative($type, $event) : self::shouldSendAdminCopyPhp($type, $event);
    }

    public static function shouldSendAdminCopyPhp(string $type, string $event): bool
    {
        return $type === GdprRequest::TYPE_ERASURE && $event === 'submitted';
    }

    public static function shouldSendAdminCopyNative(string $type, string $event): bool
    {
        $result = self::callNative(9, [$type, $event]);

        return match ($result) {
            '1' => true,
            '0' => false,
            default => self::shouldSendAdminCopyPhp($type, $event),
        };
    }

    public function requestSubmitted(GdprRequest $gdprRequest): void
    {
        $this->sendToRequester($gdprRequest, 'submitted');

        if (self::shouldSendAdminCopy((string) $gdprRequest->type, 'submitted')) {
            $this->sendToAdmin($gdprRequest, 'submitted');
        }
    }

    public function exportReady(GdprRequest $gdprRequest): void
    {
        $this->sendToRequester($gdprRequest, 'export_ready');
    }

    public function erasureCompleted(GdprRequest $gdprRequest): void
    {
        $this->sendToRequester($gdprRequest, 'erasure_completed');
    }

    public function requestRejected(GdprRequest $gdprRequest): void
    {
        $this->sendToRequester($gdprRequest, 'rejected');
    }

    private function sendToRequester(GdprRequest $gdprRequest, string $event): void
    {
        $email = self::normalizeRecipientEmail((string) $gdprRequest->requester_email);
        if ($email === null) {
            return;
        }

        Mail::to($email)->send(new GdprRequestStatusMail($gdprRequest, $event));
    }

    private function sendToAdmin(GdprRequest $gdprRequest, string $event): void
    {
        $email = self::normalizeRecipientEmail((string) config('mail.from.address'));
        if ($email === null) {
            return;
        }

        Mail::to($email)->send(new GdprRequestStatusMail($gdprRequest, $event, adminCopy: true));
    }
}
