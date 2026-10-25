<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class GdprConsent extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_gdpr_consent_normalize_state(
            uint32_t flags,
            int64_t consented_at,
            int64_t withdrawn_at,
            int64_t now,
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

    private const int TYPE_MAX = 64;

    private const int POLICY_MAX = 64;

    private const int IP_MAX = 45;

    private const string IP_CHARS = '0123456789abcdef.:';

    private const int HAS_POLICY = 1;

    private const int HAS_IP = 2;

    private const int HAS_CONSENTED = 4;

    private const int HAS_WITHDRAWN = 8;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public const TYPE_ESSENTIAL_COOKIES = 'essential_cookies';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'consent_type',
        'status',
        'policy_version',
        'consented_at',
        'withdrawn_at',
        'ip_address',
        'user_agent_hash',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Normalizes a consent row and evaluates its consent window at `$now`: the type becomes a
     * lowercase `[a-z0-9_]` slug, an unknown status is derived from the latest timestamp, the
     * policy version is trimmed to 64 characters and the IP address keeps only IPv4/IPv6 characters.
     *
     * @return array{consent_type: string, status: string, policy_version: ?string, ip_address: ?string, is_active: bool, is_valid: bool}
     */
    public static function normalizeConsentState(string $consentType, string $status, ?string $policyVersion, ?string $ipAddress, ?int $consentedAt, ?int $withdrawnAt, int $now): array
    {
        return self::usesRustFfi()
            ? self::normalizeConsentStateNative($consentType, $status, $policyVersion, $ipAddress, $consentedAt, $withdrawnAt, $now)
            : self::normalizeConsentStatePhp($consentType, $status, $policyVersion, $ipAddress, $consentedAt, $withdrawnAt, $now);
    }

    /**
     * @return array{consent_type: string, status: string, policy_version: ?string, ip_address: ?string, is_active: bool, is_valid: bool}
     */
    public static function normalizeConsentStateNative(string $consentType, string $status, ?string $policyVersion, ?string $ipAddress, ?int $consentedAt, ?int $withdrawnAt, int $now): array
    {
        $flags = ($policyVersion === null ? 0 : self::HAS_POLICY)
            | ($ipAddress === null ? 0 : self::HAS_IP)
            | ($consentedAt === null ? 0 : self::HAS_CONSENTED)
            | ($withdrawnAt === null ? 0 : self::HAS_WITHDRAWN);
        $input = implode(self::FIELD_SEPARATOR, [$consentType, $status, $policyVersion ?? '', $ipAddress ?? '']);
        $fields = self::callNative('nntmux_gdpr_consent_normalize_state', [$flags, $consentedAt ?? 0, $withdrawnAt ?? 0, $now, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 6 || $fields[0] === null
            || ($fields[1] !== self::STATUS_GRANTED && $fields[1] !== self::STATUS_WITHDRAWN)
            || $fields[4] === null || $fields[5] === null) {
            return self::normalizeConsentStatePhp($consentType, $status, $policyVersion, $ipAddress, $consentedAt, $withdrawnAt, $now);
        }

        return [
            'consent_type' => $fields[0],
            'status' => $fields[1],
            'policy_version' => $fields[2],
            'ip_address' => $fields[3],
            'is_active' => $fields[4] === '1',
            'is_valid' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{consent_type: string, status: string, policy_version: ?string, ip_address: ?string, is_active: bool, is_valid: bool}
     */
    public static function normalizeConsentStatePhp(string $consentType, string $status, ?string $policyVersion, ?string $ipAddress, ?int $consentedAt, ?int $withdrawnAt, int $now): array
    {
        $type = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($consentType, self::WHITESPACE))) ?? '';
        $type = rtrim(substr(trim($type, '_'), 0, self::TYPE_MAX), '_');

        $status = match (strtolower(trim($status, self::WHITESPACE))) {
            self::STATUS_GRANTED => self::STATUS_GRANTED,
            self::STATUS_WITHDRAWN => self::STATUS_WITHDRAWN,
            default => $withdrawnAt !== null && ($consentedAt === null || $withdrawnAt >= $consentedAt)
                ? self::STATUS_WITHDRAWN
                : self::STATUS_GRANTED,
        };

        $policy = $policyVersion === null ? null : mb_substr(trim($policyVersion, self::WHITESPACE), 0, self::POLICY_MAX, 'UTF-8');
        $ip = $ipAddress === null ? null : strtolower(trim($ipAddress, self::WHITESPACE));
        if ($ip !== null && ($ip === '' || strlen($ip) > self::IP_MAX || strspn($ip, self::IP_CHARS) !== strlen($ip))) {
            $ip = null;
        }

        $granted = $status === self::STATUS_GRANTED;

        return [
            'consent_type' => $type,
            'status' => $status,
            'policy_version' => $policy === '' ? null : $policy,
            'ip_address' => $ip,
            'is_active' => $granted && $consentedAt !== null && $consentedAt <= $now
                && ($withdrawnAt === null || $withdrawnAt < $consentedAt),
            'is_valid' => $type !== '' && ($granted ? $consentedAt !== null : $withdrawnAt !== null),
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
