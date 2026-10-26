<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\GdprAuditLog;
use App\Models\GdprRequest;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

class GdprAuditService
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_gdpr_audit_normalize_payload_nul(
    const char *event_ptr,
    size_t event_len,
    const char *desc_ptr,
    size_t desc_len,
    uint8_t has_subject_id,
    int64_t subject_id,
    uint8_t has_actor_id,
    int64_t actor_id,
    uint8_t has_request_id,
    int64_t request_id,
    size_t metadata_count,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Record a GDPR accountability/audit event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        string $description,
        ?User $subject = null,
        ?User $actor = null,
        ?GdprRequest $request = null,
        array $metadata = [],
    ): GdprAuditLog {
        return GdprAuditLog::create(self::buildAuditLogPayload(
            $event,
            $description,
            $subject !== null ? (int) $subject->id : null,
            $actor !== null ? (int) $actor->id : null,
            $request !== null ? (int) $request->id : null,
            $metadata,
        ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     gdpr_request_id: ?int,
     *     user_id: ?int,
     *     actor_id: ?int,
     *     event: string,
     *     description: string,
     *     metadata: ?array<string, mixed>
     * }
     */
    public static function buildAuditLogPayload(
        string $event,
        string $description,
        ?int $subjectId = null,
        ?int $actorId = null,
        ?int $requestId = null,
        array $metadata = [],
    ): array {
        if (self::$useRustFfi) {
            return self::buildAuditLogPayloadNative($event, $description, $subjectId, $actorId, $requestId, $metadata);
        }

        return self::buildAuditLogPayloadPhp($event, $description, $subjectId, $actorId, $requestId, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     gdpr_request_id: ?int,
     *     user_id: ?int,
     *     actor_id: ?int,
     *     event: string,
     *     description: string,
     *     metadata: ?array<string, mixed>
     * }
     */
    public static function buildAuditLogPayloadPhp(
        string $event,
        string $description,
        ?int $subjectId = null,
        ?int $actorId = null,
        ?int $requestId = null,
        array $metadata = [],
    ): array {
        return [
            'gdpr_request_id' => $requestId,
            'user_id' => $subjectId,
            'actor_id' => $actorId,
            'event' => $event,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     gdpr_request_id: ?int,
     *     user_id: ?int,
     *     actor_id: ?int,
     *     event: string,
     *     description: string,
     *     metadata: ?array<string, mixed>
     * }
     */
    public static function buildAuditLogPayloadNative(
        string $event,
        string $description,
        ?int $subjectId = null,
        ?int $actorId = null,
        ?int $requestId = null,
        array $metadata = [],
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildAuditLogPayloadPhp($event, $description, $subjectId, $actorId, $requestId, $metadata);
        }

        try {
            $cap = max(64, strlen($event) + strlen($description) + 16);
            $outScalars = $ffi->new('int64_t[4]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_gdpr_audit_normalize_payload_nul(
                $event,
                strlen($event),
                $description,
                strlen($description),
                $subjectId !== null ? 1 : 0,
                $subjectId ?? 0,
                $actorId !== null ? 1 : 0,
                $actorId ?? 0,
                $requestId !== null ? 1 : 0,
                $requestId ?? 0,
                count($metadata),
                $outScalars,
                $outBuf,
                $cap
            );
            $flags = $outScalars[0];
            $reqVal = $outScalars[1];
            $subVal = $outScalars[2];
            $actVal = $outScalars[3];

            if (! is_int($written) || $written <= 0 || ! is_int($flags) || ! is_int($reqVal) || ! is_int($subVal) || ! is_int($actVal)) {
                return self::buildAuditLogPayloadPhp($event, $description, $subjectId, $actorId, $requestId, $metadata);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 2) {
                return self::buildAuditLogPayloadPhp($event, $description, $subjectId, $actorId, $requestId, $metadata);
            }

            return [
                'gdpr_request_id' => ($flags & 1) !== 0 ? $reqVal : null,
                'user_id' => ($flags & 2) !== 0 ? $subVal : null,
                'actor_id' => ($flags & 4) !== 0 ? $actVal : null,
                'event' => $parts[0],
                'description' => $parts[1],
                'metadata' => ($flags & 8) !== 0 ? $metadata : null,
            ];
        } catch (Throwable) {
            return self::buildAuditLogPayloadPhp($event, $description, $subjectId, $actorId, $requestId, $metadata);
        }
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W49 parity test). */
    private const W49_RUST_FFI_DEFAULT = true;

    private const string W49_FFI_CDEF = 'ssize_t nntmux_w49_gdpr_audit_summarize_batch(const char *events, size_t events_len, const char *metadata, size_t metadata_len, size_t count, int64_t *out, size_t out_cap);';

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49 batch routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /** Known audit events => code (bits 0-3 of an audit word); any other event is 15. */
    public const array W49_AUDIT_EVENTS = [
        'request_submitted' => 0,
        'request_rejected' => 1,
        'export_generated' => 2,
        'erasure_soft_delete' => 3,
        'erasure_force_delete' => 4,
    ];

    /** Metadata keys whose presence sets bits 24-32 of an audit word. */
    public const array W49_AUDIT_KEYS = ['subject', 'force_delete', 'retained_records', 'path', 'expires_at', 'gdpr_minimized', 'email', 'ip_address', 'host'];

    /**
     * Batch summary of stored audit rows. Two words per row: `eventCode | isArray << 4 |
     * count << 8 | keyPresence << 24 | deletedUserSubject << 33 | forceDelete << 34 |
     * eventLength << 40 | hasPii << 48`, then crc32 of a string `subject` or -1. Metadata is
     * read as `json_decode($json, true)`; counts saturate at 0xFFFF and lengths at 255.
     *
     * @param  list<string>  $events
     * @param  list<?string>  $metadata  JSON metadata column values, parallel to $events.
     * @return list<int>
     */
    public static function summarizeAuditRows(array $events, array $metadata): array
    {
        return self::usesW49RustFfi()
            ? self::summarizeAuditRowsNative($events, $metadata)
            : self::summarizeAuditRowsPhp($events, $metadata);
    }

    /**
     * @param  list<string>  $events
     * @param  list<?string>  $metadata
     * @return list<int>
     */
    public static function summarizeAuditRowsPhp(array $events, array $metadata): array
    {
        $out = [];
        foreach ($events as $i => $event) {
            $decoded = json_decode($metadata[$i] ?? '', true);
            $word = (self::W49_AUDIT_EVENTS[$event] ?? 15) | min(255, \strlen($event)) << 40;
            $subjectCrc = -1;
            if (\is_array($decoded)) {
                $word |= 16 | min(0xFFFF, \count($decoded)) << 8;
                foreach (self::W49_AUDIT_KEYS as $bit => $key) {
                    if (\array_key_exists($key, $decoded)) {
                        $word |= 1 << (24 + $bit);
                    }
                }
                $subject = $decoded['subject'] ?? null;
                if (\is_string($subject)) {
                    $subjectCrc = crc32($subject);
                    if (str_starts_with($subject, 'deleted_user_')) {
                        $word |= 1 << 33;
                    }
                }
                if (($decoded['force_delete'] ?? null) === true) {
                    $word |= 1 << 34;
                }
                if (($word >> 30 & 7) !== 0) {
                    $word |= 1 << 48;
                }
            }
            $out[] = $word;
            $out[] = $subjectCrc;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a value holds a NUL byte.
     *
     * @param  list<string>  $events
     * @param  list<?string>  $metadata
     * @return list<int>
     */
    public static function summarizeAuditRowsNative(array $events, array $metadata): array
    {
        $count = \count($events);
        $ffi = $count === 0 ? null : self::w49Ffi();
        $packedEvents = implode("\0", $events);
        $packedMetadata = implode("\0", $metadata);
        if ($ffi === null || \count($metadata) !== $count) {
            return self::summarizeAuditRowsPhp($events, $metadata);
        }
        try {
            $words = self::w49Words($ffi, $count * 2, static fn (CData $out, int $cap): mixed => $ffi->nntmux_w49_gdpr_audit_summarize_batch( // @phpstan-ignore method.notFound
                $packedEvents, \strlen($packedEvents), $packedMetadata, \strlen($packedMetadata), $count, $out, $cap));
        } catch (Throwable) {
            $words = null;
        }

        return $words ?? self::summarizeAuditRowsPhp($events, $metadata);
    }

    private static function w49Ffi(): ?FFI
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('gdpr_w49', self::W49_FFI_CDEF) ?? false;

        return self::$w49Ffi === false ? null : self::$w49Ffi;
    }

    /**
     * Copies `$words` output words out of a reusable `int64_t` buffer after `$call` filled it.
     *
     * @param  callable(CData, int): mixed  $call  Receives the buffer and its capacity in words.
     * @return list<int>|null
     */
    private static function w49Words(FFI $ffi, int $words, callable $call): ?array
    {
        $buffer = self::$w49Out;
        if ($buffer === null || self::$w49OutCap < $words) {
            $cap = max(512, $words * 2);
            $buffer = self::$w49Out = $ffi->new("int64_t[{$cap}]");
            self::$w49OutCap = $cap;
        }
        if ($call($buffer, self::$w49OutCap) !== $words) {
            return null;
        }
        $decoded = unpack('P*', FFI::string($buffer, $words * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    // ========================================================================
    // WAVE 60.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs reached 2.44x common mean, 2.24x common p99, 1.80x uncommon mean and 1.61x uncommon p99; see RustGdprAuditInventoryErasureExportRetentionServiceW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_FFI_CDEF = 'ssize_t nntmux_w604_gdpr_audit_event_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per auditEventRowBatch() record. */
    public const int W60_ROW_WORDS = 6;

    /** int64 summary words after the auditEventRowBatch() records (none for an empty batch). */
    public const int W60_TRAILER_WORDS = 8;

    /** Upper clamp for epoch-second timestamps. */
    private const int W60_TIME_MAX = 1 << 40;

    private const string W60_LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const string W60_EVENT_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789_';

    private const string W60_DIGITS = '0123456789';

    private const string W60_IPV6_BYTES = '0123456789abcdefABCDEF:';

    private const string W60_CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /**
     * Audit event rows for [event, actor, ip address, description, subject id, actor id,
     * request id, created at, now] items (ids <= 0 are absent, times are epoch seconds clamped
     * to 0..2^40).
     *
     * Event codes are W49_AUDIT_EVENTS (0-4), 14 for another `[a-z][a-z0-9_]{2,63}` event and 15
     * otherwise. Actors are classed 0 empty, 1 `system`, 2 `user:N`, 3 `admin:N` (N 1-18 digits
     * without a leading zero) or 4 malformed. Addresses are classed 0 empty, 1 IPv4 (masked to
     * the first three octets `a << 16 | b << 8 | c`), 2 IPv6-shaped (2-39 hex digits and colons,
     * at least two colons and at most one '::'; masked to the colon count) or 3 other. The
     * retention window is 1095 days for requests, 30 for exports, 2190 for erasures, 365 for
     * other well-formed events and 90 for malformed ones; retention status is 0 within, 1
     * nearing (age >= 90% of the window), 2 expired or 3 created in the future.
     *
     * Rows [event | actor class << 4 | ip class << 8 | retention << 12 | flags << 16 (1 subject,
     * 2 actor id, 4 request, 8 self action, 16 control byte in the description, 32 description
     * not UTF-8, 64 description holds '@', 128 actor id disagrees with the actor string), actor
     * number (-1), masked ip (-1), age days, window days, description bytes], then the trailer
     * [valid, invalid, expired, nearing, future, rows holding personal data (an address or '@'),
     * oldest age days, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function auditEventRowBatch(array $items): array
    {
        return self::usesW60RustFfi() ? self::auditEventRowBatchNative($items) : self::auditEventRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function auditEventRowBatchPhp(array $items): array
    {
        self::w60Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$event, $actor, $ip, $description, $subject, $actorId, $request, $created, $now]) {
            $length = \strlen($event);
            $code = self::W49_AUDIT_EVENTS[$event] ?? ($length >= 3 && $length <= 64 && strspn($event, self::W60_LOWER, 0, 1) === 1
                && strspn($event, self::W60_EVENT_BYTES) === $length ? 14 : 15);
            [$actorClass, $actorNumber] = self::w60Actor($actor);
            [$ipClass, $masked] = self::w60Ip($ip);
            $created = max(0, min(self::W60_TIME_MAX, $created));
            $now = max(0, min(self::W60_TIME_MAX, $now));
            $window = match ($code) {
                0, 1 => 1095,
                2 => 30,
                3, 4 => 2190,
                14 => 365,
                default => 90,
            };
            if ($created > $now) {
                $status = 3;
                $age = 0;
            } else {
                $age = intdiv($now - $created, 86_400);
                $status = $age >= $window ? 2 : ($age * 10 >= $window * 9 ? 1 : 0);
            }
            $person = $actorClass === 2 || $actorClass === 3;
            $descriptionLength = \strlen($description);
            $at = str_contains($description, '@');
            $flags = ($subject > 0 ? 1 : 0) | ($actorId > 0 ? 2 : 0) | ($request > 0 ? 4 : 0)
                | ($person && $subject > 0 && $actorNumber === $subject ? 8 : 0)
                | (strcspn($description, self::W60_CONTROL) !== $descriptionLength ? 16 : 0)
                | (mb_check_encoding($description, 'UTF-8') ? 0 : 32) | ($at ? 64 : 0)
                | ($person && $actorId > 0 && $actorNumber !== $actorId ? 128 : 0);
            $word = $code | $actorClass << 4 | $ipClass << 8 | $status << 12 | $flags << 16;
            array_push($out, $word, $actorNumber, $masked, $age, $window, $descriptionLength);
            if ($code !== 15 && $actorClass !== 4 && $ipClass !== 3) {
                $summary[0]++;
            } else {
                $summary[1]++;
            }
            if ($status === 2) {
                $summary[2]++;
            } elseif ($status === 1) {
                $summary[3]++;
            } elseif ($status === 3) {
                $summary[4]++;
            }
            if ($ipClass === 1 || $ipClass === 2 || $at) {
                $summary[5]++;
            }
            $summary[6] = max($summary[6], $age);
            $value = $word ^ $masked << 20 ^ $age;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int, 8: int}>  $items
     * @return list<int>
     */
    public static function auditEventRowBatchNative(array $items): array
    {
        self::w60Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w60Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3)], $count);
        $ints = pack('P*', ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8));

        return self::w60Call($blob, $twin, $ints, $count, $count * self::W60_ROW_WORDS + self::W60_TRAILER_WORDS)
            ?? self::auditEventRowBatchPhp($items);
    }

    /** @return array{0: int, 1: int} */
    private static function w60Actor(string $actor): array
    {
        if ($actor === '') {
            return [0, -1];
        }
        if ($actor === 'system') {
            return [1, -1];
        }
        $colon = strpos($actor, ':');
        if ($colon === false) {
            return [4, -1];
        }
        $class = match (substr($actor, 0, $colon)) {
            'user' => 2,
            'admin' => 3,
            default => 0,
        };
        $number = substr($actor, $colon + 1);
        $length = \strlen($number);
        if ($class === 0 || $length === 0 || $length > 18 || $number[0] === '0' || strspn($number, self::W60_DIGITS) !== $length) {
            return [4, -1];
        }

        return [$class, (int) $number];
    }

    /** @return array{0: int, 1: int} */
    private static function w60Ip(string $ip): array
    {
        if ($ip === '') {
            return [0, -1];
        }
        $octets = explode('.', $ip);
        if (\count($octets) === 4) {
            $valid = true;
            foreach ($octets as $octet) {
                $length = \strlen($octet);
                if ($length === 0 || $length > 3 || strspn($octet, self::W60_DIGITS) !== $length || ($length > 1 && $octet[0] === '0') || (int) $octet > 255) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                return [1, (int) $octets[0] << 16 | (int) $octets[1] << 8 | (int) $octets[2]];
            }
        }
        $length = \strlen($ip);
        $colons = substr_count($ip, ':');

        return $length >= 2 && $length <= 39 && $colons >= 2 && substr_count($ip, '::') <= 1 && strspn($ip, self::W60_IPV6_BYTES) === $length
            ? [2, $colons] : [3, -1];
    }

    /** @param array<array-key, mixed> $items */
    private static function w60Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W60 audit event batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 9 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])
                || ! \is_int($item[7]) || ! \is_int($item[8])) {
                throw new InvalidArgumentException('W60 audit event batch items must be [string event, string actor, string ip address, string description, int subject id, int actor id, int request id, int created at, int now] lists.');
            }
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W60 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('enabled');
        self::w60RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w60Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w60Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w60RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w60', self::W60_FFI_CDEF) ?? false;
            self::w60RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w60RustFfiState('out', $out);
                self::w60RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W60_FFI_CDEF.)
            $written = $ffi->nntmux_w604_gdpr_audit_event_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w60RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W60 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
