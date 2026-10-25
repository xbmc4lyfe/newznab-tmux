<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Auth\LoginLoginRequest;
use Illuminate\Foundation\Http\FormRequest;

class Disable2faPasswordSecurityRequest extends FormRequest
{
    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustAuthRequestsConfirmPasswordParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['current-password' => [
            'required',
        ], ];
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Shape checks for the current password (never trimmed or returned): presence, byte length against a
     * non-negative minimum, and validity (present, long enough, no NUL byte).
     *
     * @return array{present: bool, bytes: int, min_length: int, meets_min_length: bool, valid: bool}
     */
    public static function normalizeDisable2faPayload(?string $currentPassword, int $minLength = 8): array
    {
        return self::usesRustFfi()
            ? self::normalizeDisable2faPayloadNative($currentPassword, $minLength)
            : self::normalizeDisable2faPayloadPhp($currentPassword, $minLength);
    }

    /**
     * @return array{present: bool, bytes: int, min_length: int, meets_min_length: bool, valid: bool}
     */
    public static function normalizeDisable2faPayloadPhp(?string $currentPassword, int $minLength = 8): array
    {
        $password = $currentPassword ?? '';
        $bytes = \strlen($password);
        $minLength = max(0, $minLength);
        $present = $password !== '';
        $meets = $bytes >= $minLength;

        return [
            'present' => $present,
            'bytes' => $bytes,
            'min_length' => $minLength,
            'meets_min_length' => $meets,
            'valid' => $present && $meets && ! str_contains($password, "\0"),
        ];
    }

    /**
     * @return array{present: bool, bytes: int, min_length: int, meets_min_length: bool, valid: bool}
     */
    public static function normalizeDisable2faPayloadNative(?string $currentPassword, int $minLength = 8): array
    {
        $result = LoginLoginRequest::evaluateNative(4, [...LoginLoginRequest::nullableParts($currentPassword), (string) $minLength]);
        if ($result !== null && \count($result) === 5 && LoginLoginRequest::allFlags([$result[0], $result[3], $result[4]])
            && preg_match('/^(0|[1-9][0-9]*)$/D', $result[1]) === 1 && preg_match('/^(0|[1-9][0-9]*)$/D', $result[2]) === 1
            && (int) $result[1] === \strlen($currentPassword ?? '')) {
            return [
                'present' => $result[0] === '1',
                'bytes' => (int) $result[1],
                'min_length' => (int) $result[2],
                'meets_min_length' => $result[3] === '1',
                'valid' => $result[4] === '1',
            ];
        }

        return self::normalizeDisable2faPayloadPhp($currentPassword, $minLength);
    }
}
