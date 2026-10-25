<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\GdprAuditLog;
use App\Models\GdprRequest;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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
}
