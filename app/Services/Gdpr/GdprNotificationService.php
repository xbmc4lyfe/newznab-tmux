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

    /**
     * Enabled: the paired benchmark in the W54 parity test measures 2.33x common mean, 1.84x common
     * p99, 2.50x uncommon mean and 1.99x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W54_RUST_FFI_DEFAULT = true;

    private const string W54_FFI_CDEF = 'ssize_t nntmux_w541_gdpr_notification_plan_batch(const char *types, size_t types_len, const char *events, size_t events_len, const char *recipients, size_t recipients_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 54 batch routed to Rust or PHP, then restores the routing. */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /** {@see planNotificationBatch()} keys, in the order the Rust export writes them. */
    private const array W54_PLAN_KEYS = ['rows', 'requester_mails', 'admin_copies', 'unknown_events', 'unknown_types', 'blank_recipients', 'malformed_recipients', 'deliverable'];

    private const array W54_TYPES = [GdprRequest::TYPE_EXPORT, GdprRequest::TYPE_ERASURE, GdprRequest::TYPE_RECTIFICATION, GdprRequest::TYPE_RESTRICTION];

    /** Events with a requester notification method. */
    private const array W54_EVENTS = ['submitted', 'export_ready', 'erasure_completed', 'rejected'];

    /**
     * Plans the notifications for a batch of GDPR requests (type, event, requester email): rows
     * with a known event send a requester mail, deliverable when the trimmed email has the
     * `local@domain.tld` shape (one `@` after a non-empty local part, no whitespace, and a last
     * `.` that is neither the first nor the last byte of the domain; a cheap pre-check, not
     * {@see normalizeRecipientEmailPhp()}), and an erasure submission also sends an admin copy
     * ({@see shouldSendAdminCopyPhp()}). Unknown events and request types are counted, as are
     * blank (trimming to '') and malformed (non-blank, unshaped) recipients across all rows.
     *
     * @param  array<array-key, string>  $types
     * @param  array<array-key, string>  $events
     * @param  array<array-key, string>  $recipients
     * @return array{rows: int, requester_mails: int, admin_copies: int, unknown_events: int, unknown_types: int, blank_recipients: int, malformed_recipients: int, deliverable: int}
     *
     * @throws \InvalidArgumentException when the columns differ in length
     */
    public static function planNotificationBatch(array $types, array $events, array $recipients): array
    {
        return self::usesW54RustFfi() ? self::planNotificationBatchNative($types, $events, $recipients) : self::planNotificationBatchPhp($types, $events, $recipients);
    }

    /**
     * @param  array<array-key, string>  $types
     * @param  array<array-key, string>  $events
     * @param  array<array-key, string>  $recipients
     * @return array{rows: int, requester_mails: int, admin_copies: int, unknown_events: int, unknown_types: int, blank_recipients: int, malformed_recipients: int, deliverable: int}
     */
    public static function planNotificationBatchPhp(array $types, array $events, array $recipients): array
    {
        self::w54AssertSameLength($types, $events, $recipients);
        $events = array_values($events);
        $recipients = array_values($recipients);
        $requester = $admin = $unknownEvents = $unknownTypes = $blank = $malformed = $deliverable = 0;
        foreach (array_values($types) as $i => $type) {
            $event = $events[$i];
            $email = trim($recipients[$i]);
            $shaped = false;
            if ($email === '') {
                $blank++;
            } else {
                $at = strpos($email, '@');
                $dot = strrpos($email, '.');
                $shaped = $at !== false && $at > 0 && strpos($email, '@', $at + 1) === false
                    && strcspn($email, " \t\n\r\v\0") === \strlen($email)
                    && $dot !== false && $dot > $at + 1 && $dot < \strlen($email) - 1;
                if (! $shaped) {
                    $malformed++;
                }
            }
            if (! \in_array($type, self::W54_TYPES, true)) {
                $unknownTypes++;
            }
            if (! \in_array($event, self::W54_EVENTS, true)) {
                $unknownEvents++;

                continue;
            }
            $requester++;
            if ($shaped) {
                $deliverable++;
            }
            if (self::shouldSendAdminCopyPhp($type, $event)) {
                $admin++;
            }
        }

        return ['rows' => \count($types), 'requester_mails' => $requester, 'admin_copies' => $admin, 'unknown_events' => $unknownEvents, 'unknown_types' => $unknownTypes, 'blank_recipients' => $blank, 'malformed_recipients' => $malformed, 'deliverable' => $deliverable];
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a value
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $types
     * @param  array<array-key, string>  $events
     * @param  array<array-key, string>  $recipients
     * @return array{rows: int, requester_mails: int, admin_copies: int, unknown_events: int, unknown_types: int, blank_recipients: int, malformed_recipients: int, deliverable: int}
     */
    public static function planNotificationBatchNative(array $types, array $events, array $recipients): array
    {
        self::w54AssertSameLength($types, $events, $recipients);
        $count = \count($types);
        $buffer = $count === 0 ? null : self::w54Buffer(8);
        if ($buffer === null) {
            return self::planNotificationBatchPhp($types, $events, $recipients);
        }
        [$ffi, $out, $cap] = $buffer;
        $joinedTypes = implode("\0", $types);
        $joinedEvents = implode("\0", $events);
        $joinedRecipients = implode("\0", $recipients);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w541_gdpr_notification_plan_batch($joinedTypes, \strlen($joinedTypes), $joinedEvents, \strlen($joinedEvents), $joinedRecipients, \strlen($joinedRecipients), $count, $out, $cap);
            $words = self::w54Decode($out, $written, 8);
        } catch (Throwable) {
            $words = null;
        }
        if ($words === null || \count($words) !== 8) {
            return self::planNotificationBatchPhp($types, $events, $recipients);
        }

        /** @var array{rows: int, requester_mails: int, admin_copies: int, unknown_events: int, unknown_types: int, blank_recipients: int, malformed_recipients: int, deliverable: int} */
        return array_combine(self::W54_PLAN_KEYS, $words);
    }

    /**
     * @param  array<array-key, string>  $types
     * @param  array<array-key, string>  $events
     * @param  array<array-key, string>  $recipients
     */
    private static function w54AssertSameLength(array $types, array $events, array $recipients): void
    {
        if (\count($types) !== \count($events) || \count($types) !== \count($recipients)) {
            throw new \InvalidArgumentException('GDPR notification columns must have the same length.');
        }
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: FFI\CData, 2: int}|null
     */
    private static function w54Buffer(int $words): ?array
    {
        $buffer = self::w54RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w54Decode(FFI\CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 54 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('gdpr_notification_w54', self::W54_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof FFI\CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
