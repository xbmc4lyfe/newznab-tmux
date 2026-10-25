<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\CaptchaHelper;
use Illuminate\Foundation\Http\FormRequest;

class ShowLinkRequestFormForgotPasswordRequest extends FormRequest
{
    private const string API_KEY_BYTES = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const array LOOKUPS = ['email', 'apikey', 'none'];

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
    public function rules()
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
     * A trimmed ASCII-lowercased email and API key (null when blank; keys must be ASCII alphanumeric),
     * which one the reset lookup uses (email first), and a trimmed captcha response (null when blank).
     *
     * @return array{email: ?string, apikey: ?string, lookup: 'email'|'apikey'|'none', captcha: ?string}
     */
    public static function normalizeResetLookupInput(?string $email, ?string $apikey, ?string $captchaResponse = null): array
    {
        return self::usesRustFfi()
            ? self::normalizeResetLookupInputNative($email, $apikey, $captchaResponse)
            : self::normalizeResetLookupInputPhp($email, $apikey, $captchaResponse);
    }

    /**
     * @return array{email: ?string, apikey: ?string, lookup: 'email'|'apikey'|'none', captcha: ?string}
     */
    public static function normalizeResetLookupInputPhp(?string $email, ?string $apikey, ?string $captchaResponse = null): array
    {
        $email = LoginLoginRequest::trimmedOrNull($email);
        $apikey = LoginLoginRequest::trimmedOrNull($apikey);
        if ($apikey !== null && strspn($apikey, self::API_KEY_BYTES) !== \strlen($apikey)) {
            $apikey = null;
        }

        return [
            'email' => $email === null ? null : strtolower($email),
            'apikey' => $apikey === null ? null : strtolower($apikey),
            'lookup' => $email !== null ? 'email' : ($apikey !== null ? 'apikey' : 'none'),
            'captcha' => LoginLoginRequest::trimmedOrNull($captchaResponse),
        ];
    }

    /**
     * @return array{email: ?string, apikey: ?string, lookup: 'email'|'apikey'|'none', captcha: ?string}
     */
    public static function normalizeResetLookupInputNative(?string $email, ?string $apikey, ?string $captchaResponse = null): array
    {
        $result = LoginLoginRequest::evaluateNative(3, [
            ...LoginLoginRequest::nullableParts($email),
            ...LoginLoginRequest::nullableParts($apikey),
            ...LoginLoginRequest::nullableParts($captchaResponse),
        ]);
        if ($result !== null && \count($result) === 7) {
            $nativeEmail = LoginLoginRequest::decodeNullable($result[0], $result[1]);
            $nativeKey = LoginLoginRequest::decodeNullable($result[2], $result[3]);
            $captcha = LoginLoginRequest::decodeNullable($result[5], $result[6]);
            $lookup = $result[4];
            if ($nativeEmail !== false && $nativeKey !== false && $captcha !== false && \in_array($lookup, self::LOOKUPS, true)) {
                return ['email' => $nativeEmail, 'apikey' => $nativeKey, 'lookup' => $lookup, 'captcha' => $captcha];
            }
        }

        return self::normalizeResetLookupInputPhp($email, $apikey, $captchaResponse);
    }
}
