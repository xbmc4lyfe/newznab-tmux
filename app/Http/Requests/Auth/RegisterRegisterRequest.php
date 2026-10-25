<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\RegistrationStatus;
use App\Support\CaptchaHelper;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRegisterRequest extends FormRequest
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
        return CaptchaHelper::getValidationRules();
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
     * A trimmed username, a trimmed ASCII-lowercased email, a trimmed invite token (null when blank), the
     * registration status (unknown values fail closed) and whether this input may register.
     *
     * @return array{username: string, email: string, invite_token: ?string, status: int, invite_required: bool, closed: bool, allowed: bool}
     */
    public static function normalizeRegistrationInput(?string $username, ?string $email, ?string $inviteToken, int $registerStatus = 0): array
    {
        return self::usesRustFfi()
            ? self::normalizeRegistrationInputNative($username, $email, $inviteToken, $registerStatus)
            : self::normalizeRegistrationInputPhp($username, $email, $inviteToken, $registerStatus);
    }

    /**
     * @return array{username: string, email: string, invite_token: ?string, status: int, invite_required: bool, closed: bool, allowed: bool}
     */
    public static function normalizeRegistrationInputPhp(?string $username, ?string $email, ?string $inviteToken, int $registerStatus = 0): array
    {
        $token = LoginLoginRequest::trimmedOrNull($inviteToken);
        $status = (RegistrationStatus::tryFrom($registerStatus) ?? RegistrationStatus::Closed)->value;

        return [
            'username' => trim($username ?? ''),
            'email' => strtolower(trim($email ?? '')),
            'invite_token' => $token,
            'status' => $status,
            'invite_required' => $status === RegistrationStatus::Invite->value,
            'closed' => $status === RegistrationStatus::Closed->value,
            'allowed' => $status === RegistrationStatus::Open->value || ($status === RegistrationStatus::Invite->value && $token !== null),
        ];
    }

    /**
     * @return array{username: string, email: string, invite_token: ?string, status: int, invite_required: bool, closed: bool, allowed: bool}
     */
    public static function normalizeRegistrationInputNative(?string $username, ?string $email, ?string $inviteToken, int $registerStatus = 0): array
    {
        $result = LoginLoginRequest::evaluateNative(2, [
            ...LoginLoginRequest::nullableParts($username),
            ...LoginLoginRequest::nullableParts($email),
            ...LoginLoginRequest::nullableParts($inviteToken),
            (string) $registerStatus,
        ]);
        if ($result !== null && \count($result) === 8 && LoginLoginRequest::allFlags([$result[5], $result[6], $result[7]])
            && \in_array($result[4], ['0', '1', '2'], true)) {
            $token = LoginLoginRequest::decodeNullable($result[2], $result[3]);
            if ($token !== false) {
                return [
                    'username' => $result[0],
                    'email' => $result[1],
                    'invite_token' => $token,
                    'status' => (int) $result[4],
                    'invite_required' => $result[5] === '1',
                    'closed' => $result[6] === '1',
                    'allowed' => $result[7] === '1',
                ];
            }
        }

        return self::normalizeRegistrationInputPhp($username, $email, $inviteToken, $registerStatus);
    }
}
