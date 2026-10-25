<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Models\Concerns\IsSingletonConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** @property RegistrationStatus $status */
final class RegistrationConfiguration extends Model
{
    use IsSingletonConfiguration;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_registration_configuration_normalize_status(
            uint32_t has_valid_invite,
            const char *raw_ptr,
            size_t raw_len,
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

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => RegistrationStatus::class];
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
     * Parses a raw registration status (a case value with optional leading zeros, or a case-insensitive
     * label). Unrecognized input fails closed to `RegistrationStatus::Closed` with `is_valid` false.
     *
     * @return array{status: RegistrationStatus, label: string, is_valid: bool, allows_registration: bool, requires_invite: bool, can_register: bool}
     */
    public static function normalizeRegistrationStatus(string $raw, bool $hasValidInvite = false): array
    {
        return self::usesRustFfi()
            ? self::normalizeRegistrationStatusNative($raw, $hasValidInvite)
            : self::normalizeRegistrationStatusPhp($raw, $hasValidInvite);
    }

    /**
     * @return array{status: RegistrationStatus, label: string, is_valid: bool, allows_registration: bool, requires_invite: bool, can_register: bool}
     */
    public static function normalizeRegistrationStatusNative(string $raw, bool $hasValidInvite = false): array
    {
        $fields = self::callNative('nntmux_registration_configuration_normalize_status', [$hasValidInvite ? 1 : 0, $raw, strlen($raw)]);
        $status = $fields !== null && count($fields) === 6 && $fields[0] !== null && ctype_digit($fields[0])
            ? RegistrationStatus::tryFrom((int) $fields[0])
            : null;
        if ($fields === null || $status === null || $fields[1] !== $status->labelPhp()
            || $fields[2] === null || $fields[3] === null || $fields[4] === null || $fields[5] === null) {
            return self::normalizeRegistrationStatusPhp($raw, $hasValidInvite);
        }

        return [
            'status' => $status,
            'label' => $fields[1],
            'is_valid' => $fields[2] === '1',
            'allows_registration' => $fields[3] === '1',
            'requires_invite' => $fields[4] === '1',
            'can_register' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{status: RegistrationStatus, label: string, is_valid: bool, allows_registration: bool, requires_invite: bool, can_register: bool}
     */
    public static function normalizeRegistrationStatusPhp(string $raw, bool $hasValidInvite = false): array
    {
        $value = strtolower(trim($raw, self::WHITESPACE));
        if ($value !== '' && strspn($value, '0123456789') === strlen($value)) {
            $digits = ltrim($value, '0');
            $parsed = $digits === '' ? RegistrationStatus::Open : (strlen($digits) === 1 ? RegistrationStatus::tryFrom((int) $digits) : null);
        } else {
            $parsed = match ($value) {
                'open' => RegistrationStatus::Open,
                'invite' => RegistrationStatus::Invite,
                'closed' => RegistrationStatus::Closed,
                default => null,
            };
        }
        $status = $parsed ?? RegistrationStatus::Closed;

        return [
            'status' => $status,
            'label' => $status->labelPhp(),
            'is_valid' => $parsed !== null,
            'allows_registration' => $status !== RegistrationStatus::Closed,
            'requires_invite' => $status === RegistrationStatus::Invite,
            'can_register' => $status === RegistrationStatus::Open || ($status === RegistrationStatus::Invite && $hasValidInvite),
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
