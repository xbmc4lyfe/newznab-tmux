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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.14x, p99 4.50x; uncommon mean 6.26x, p99 4.69x); see RustGamesProcessorGdprNotificationGenreIgdbClientExceptionW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    public const string W67_GDPR_NOTIFICATION_FFI_CDEF = <<<'C'
        ssize_t nntmux_w673_gdpr_notification_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 67.3 batch. */
    public const int W67_GDPR_NOTIFICATION_TRAILER = 33;

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the GdprNotificationService Wave 67 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 67.3 GDPR notification plan. Each item is `type|event|recipient|status|ageSeconds|attempts`; type, event
     * and status are matched caselessly after trimming (0 empty, 1-based index, or one past the list): types export,
     * erasure, rectification, restriction; events submitted, export_ready, erasure_completed, rejected,
     * retention_expiring, retention_expired; statuses pending, processing, completed, rejected, cancelled. The trimmed
     * recipient is 0 blank, 1 `local@domain.tld` shaped (planNotificationBatchPhp()'s rule) or 2 malformed. Age (10
     * digits) and attempts (3) are 0 empty, 1 digits or 2 other. Channel 0 none, 1 requester, 2 requester plus admin
     * copy (erasure submitted, shouldSendAdminCopyPhp()) or 3 admin only (retention_expired). The event must match
     * the status (and the type for export_ready and erasure_completed). Attempts back off 300 * 4^(n - 1) seconds,
     * capped at 86400. Decision 6 malformed (unknown type, event or status, malformed age or attempts), 0 skipped (no
     * event or cancelled), 1 status mismatch, 2 undeliverable (requester channel without a shaped recipient), 3
     * exhausted (W67_GDPR_MAX_ATTEMPTS), 4 waiting or 5 dispatch. Per item `type | event << 3 | status << 6 |
     * recipientState << 9 | ageState << 11 | attemptsState << 13 | channel << 15 | consistent << 17 | windowOpen << 18
     * | decision << 19 | attempts (cap 255) << 22 | wait (cap 2^24 - 1) << 30 | recipientLength (cap 255) << 54`. Then
     * the seven decision, four channel, six type, eight event and three recipient counts, the dispatched requester
     * mails and admin copies, the shortest wait, the distinct lower-cased recipient domains and the 1-based first
     * dispatched item.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67GdprNotificationPlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67GdprNotificationPlanBatchNative($items) : self::w67GdprNotificationPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67GdprNotificationPlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_GDPR_NOTIFICATION_TRAILER) ?? self::w67GdprNotificationPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67GdprNotificationPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0, 0];
        $channels = [0, 0, 0, 0];
        $types = [0, 0, 0, 0, 0, 0];
        $events = [0, 0, 0, 0, 0, 0, 0, 0];
        $recipients = [0, 0, 0];
        $requester = $admin = $minWait = $firstDispatch = 0;
        $domains = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawType, $rawEvent, $rawRecipient, $rawStatus, $rawAge, $rawAttempts] = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $type = self::w67Keyword($rawType, self::W67_GDPR_TYPES);
            $event = self::w67Keyword($rawEvent, self::W67_GDPR_EVENTS);
            $status = self::w67Keyword($rawStatus, self::W67_GDPR_STATUSES);
            $email = trim($rawRecipient);
            $recipientState = 0;
            if ($email !== '') {
                $at = strpos($email, '@');
                $dot = strrpos($email, '.');
                if ($at !== false && $at > 0 && strpos($email, '@', $at + 1) === false && strcspn($email, " \t\n\r\0\x0B") === \strlen($email)
                    && $dot !== false && $dot > $at + 1 && $dot < \strlen($email) - 1) {
                    $recipientState = 1;
                    $domains[strtolower(substr($email, $at + 1))] = true;
                } else {
                    $recipientState = 2;
                }
            }
            [$ageState, $age] = self::w67Count($rawAge, 10);
            [$attemptsState, $attempts] = self::w67Count($rawAttempts, 3);
            $channel = match (true) {
                $event === 0 || $event === 7 => 0,
                $event === 6 => 3,
                $event === 1 && $type === 2 => 2,
                default => 1,
            };
            $consistent = match ($event) {
                1 => $status === 1 || $status === 2,
                2 => $type === 1 && $status === 3,
                3 => $type === 2 && $status === 3,
                4 => $status === 4,
                5, 6 => $status === 3,
                default => true,
            };
            $delay = $attempts === 0 ? 0 : min(300 << (2 * min($attempts - 1, 10)), 86400);
            $windowOpen = $age >= $delay;
            $wait = max(0, $delay - $age);
            if ($type === 5 || $event === 7 || $status === 6 || $ageState === 2 || $attemptsState === 2) {
                $decision = 6;
            } elseif ($event === 0 || $status === 5) {
                $decision = 0;
            } elseif (! $consistent) {
                $decision = 1;
            } elseif ($channel !== 3 && $recipientState !== 1) {
                $decision = 2;
            } elseif ($attempts >= self::W67_GDPR_MAX_ATTEMPTS) {
                $decision = 3;
            } elseif (! $windowOpen) {
                $decision = 4;
            } else {
                $decision = 5;
            }
            $decisions[$decision]++;
            $channels[$channel]++;
            $types[$type]++;
            $events[$event]++;
            $recipients[$recipientState]++;
            if ($decision === 5) {
                $requester += $channel === 1 || $channel === 2 ? 1 : 0;
                $admin += $channel === 2 || $channel === 3 ? 1 : 0;
                if ($firstDispatch === 0) {
                    $firstDispatch = $index + 1;
                }
            }
            if ($decision === 4 && ($minWait === 0 || $wait < $minWait)) {
                $minWait = $wait;
            }
            $out[] = $type | $event << 3 | $status << 6 | $recipientState << 9 | $ageState << 11 | $attemptsState << 13 | $channel << 15
                | ($consistent ? 1 : 0) << 17 | ($windowOpen ? 1 : 0) << 18 | $decision << 19 | min($attempts, 255) << 22 | min($wait, 16_777_215) << 30
                | min(\strlen($email), 255) << 54;
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, ...$channels);
        array_push($out, ...$types);
        array_push($out, ...$events);
        array_push($out, ...$recipients);
        array_push($out, $requester, $admin, $minWait, \count($domains), $firstDispatch);

        return $out;
    }

    /** Wave 67.3 request types, in plan order. */
    private const array W67_GDPR_TYPES = [GdprRequest::TYPE_EXPORT, GdprRequest::TYPE_ERASURE, GdprRequest::TYPE_RECTIFICATION, GdprRequest::TYPE_RESTRICTION];

    /** Wave 67.3 notification events, in plan order. */
    private const array W67_GDPR_EVENTS = ['submitted', 'export_ready', 'erasure_completed', 'rejected', 'retention_expiring', 'retention_expired'];

    /** Wave 67.3 request statuses, in plan order. */
    private const array W67_GDPR_STATUSES = [GdprRequest::STATUS_PENDING, GdprRequest::STATUS_PROCESSING, GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_REJECTED, GdprRequest::STATUS_CANCELLED];

    /** Notification attempts after which a Wave 67.3 row is exhausted. */
    public const int W67_GDPR_MAX_ATTEMPTS = 5;

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w67Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * The trimmed field, lower-cased and matched against $words: 0 empty, 1-based index, or the word count + 1.
     *
     * @param  list<string>  $words
     */
    private static function w67Keyword(string $text, array $words): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $position = array_search(strtolower($text), $words, true);

        return $position === false ? \count($words) + 1 : $position + 1;
    }

    /**
     * Wave 67 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var FFI\CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var FFI\CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('services_w673', self::W67_GDPR_NOTIFICATION_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 67.3 GdprNotificationService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w67Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w67RustFfiState('ffi');
            $out = self::w67RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof FFI\CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w67RustFfiState('lens', $count);
                if (! $column instanceof FFI\CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W67_GDPR_NOTIFICATION_FFI_CDEF.)
            $written = $ffi->nntmux_w673_gdpr_notification_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
