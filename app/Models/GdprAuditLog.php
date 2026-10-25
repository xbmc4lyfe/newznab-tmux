<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class GdprAuditLog extends Model
{
    const UPDATED_AT = null;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_gdpr_audit_log_normalize_record(
            uint32_t flags,
            int64_t request_id,
            int64_t user_id,
            int64_t actor_id,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustGdprSecurityRegistrationModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const int EVENT_MAX = 64;

    private const int HAS_REQUEST = 1;

    private const int HAS_USER = 2;

    private const int HAS_ACTOR = 4;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'gdpr_request_id',
        'user_id',
        'actor_id',
        'event',
        'description',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GdprRequest, $this>
     */
    public function gdprRequest(): BelongsTo
    {
        return $this->belongsTo(GdprRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
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
     * Normalizes an audit row: non-positive ids become null, the event becomes a lowercase
     * `[a-z0-9._]` slug of at most 64 bytes and the description is trimmed.
     *
     * @return array{gdpr_request_id: ?int, user_id: ?int, actor_id: ?int, event: string, description: string, is_self_service: bool, is_valid: bool}
     */
    public static function normalizeAuditRecord(string $event, string $description, ?int $requestId = null, ?int $userId = null, ?int $actorId = null): array
    {
        return self::usesRustFfi()
            ? self::normalizeAuditRecordNative($event, $description, $requestId, $userId, $actorId)
            : self::normalizeAuditRecordPhp($event, $description, $requestId, $userId, $actorId);
    }

    /**
     * @return array{gdpr_request_id: ?int, user_id: ?int, actor_id: ?int, event: string, description: string, is_self_service: bool, is_valid: bool}
     */
    public static function normalizeAuditRecordNative(string $event, string $description, ?int $requestId = null, ?int $userId = null, ?int $actorId = null): array
    {
        $flags = ($requestId === null ? 0 : self::HAS_REQUEST)
            | ($userId === null ? 0 : self::HAS_USER)
            | ($actorId === null ? 0 : self::HAS_ACTOR);
        $input = $event.self::FIELD_SEPARATOR.$description;
        $fields = self::callNative('nntmux_gdpr_audit_log_normalize_record', [$flags, $requestId ?? 0, $userId ?? 0, $actorId ?? 0, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 7 || $fields[3] === null || $fields[4] === null || $fields[5] === null || $fields[6] === null) {
            return self::normalizeAuditRecordPhp($event, $description, $requestId, $userId, $actorId);
        }

        return [
            'gdpr_request_id' => $fields[0] === null ? null : (int) $fields[0],
            'user_id' => $fields[1] === null ? null : (int) $fields[1],
            'actor_id' => $fields[2] === null ? null : (int) $fields[2],
            'event' => $fields[3],
            'description' => $fields[4],
            'is_self_service' => $fields[5] === '1',
            'is_valid' => $fields[6] === '1',
        ];
    }

    /**
     * @return array{gdpr_request_id: ?int, user_id: ?int, actor_id: ?int, event: string, description: string, is_self_service: bool, is_valid: bool}
     */
    public static function normalizeAuditRecordPhp(string $event, string $description, ?int $requestId = null, ?int $userId = null, ?int $actorId = null): array
    {
        $slug = preg_replace('/[^a-z0-9._]+/', '_', strtolower(trim($event, self::WHITESPACE))) ?? '';
        $slug = rtrim(substr(trim($slug, '_'), 0, self::EVENT_MAX), '_');
        $description = trim($description, self::WHITESPACE);
        $requestId = $requestId !== null && $requestId > 0 ? $requestId : null;
        $userId = $userId !== null && $userId > 0 ? $userId : null;
        $actorId = $actorId !== null && $actorId > 0 ? $actorId : null;

        return [
            'gdpr_request_id' => $requestId,
            'user_id' => $userId,
            'actor_id' => $actorId,
            'event' => $slug,
            'description' => $description,
            'is_self_service' => $userId !== null && $userId === $actorId,
            'is_valid' => $slug !== '' && $description !== '',
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
