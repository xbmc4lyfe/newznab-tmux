<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\GdprAuditLog;
use App\Models\GdprRequest;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
