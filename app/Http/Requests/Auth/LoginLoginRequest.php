<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\CaptchaHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class LoginLoginRequest extends FormRequest
{
    public const string FFI_CDEF = 'ptrdiff_t nntmux_w36_auth_requests_confirm_password(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    private const int NATIVE_MAX_BYTES = 16777216;

    private const int NATIVE_MAX_PARTS = 64;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustAuthRequestsConfirmPasswordParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $redirectRoute = 'login';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CaptchaHelper::getValidationRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cf-turnstile-response.required' => 'Please complete the Cloudflare human verification before signing in.',
        ];
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
     * A trimmed login (username or email), the remember flag, a trimmed captcha response (null when
     * blank) and whether the attempt carries everything it needs.
     *
     * @return array{login: string, remember: bool, captcha: ?string, captcha_present: bool, valid: bool}
     */
    public static function normalizeLoginAttemptInput(?string $usernameOrEmail, bool $remember, ?string $captchaResponse, bool $requireCaptcha = false): array
    {
        return self::usesRustFfi()
            ? self::normalizeLoginAttemptInputNative($usernameOrEmail, $remember, $captchaResponse, $requireCaptcha)
            : self::normalizeLoginAttemptInputPhp($usernameOrEmail, $remember, $captchaResponse, $requireCaptcha);
    }

    /**
     * @return array{login: string, remember: bool, captcha: ?string, captcha_present: bool, valid: bool}
     */
    public static function normalizeLoginAttemptInputPhp(?string $usernameOrEmail, bool $remember, ?string $captchaResponse, bool $requireCaptcha = false): array
    {
        $login = trim($usernameOrEmail ?? '');
        $captcha = self::trimmedOrNull($captchaResponse);

        return [
            'login' => $login,
            'remember' => $remember,
            'captcha' => $captcha,
            'captcha_present' => $captcha !== null,
            'valid' => $login !== '' && (! $requireCaptcha || $captcha !== null),
        ];
    }

    /**
     * @return array{login: string, remember: bool, captcha: ?string, captcha_present: bool, valid: bool}
     */
    public static function normalizeLoginAttemptInputNative(?string $usernameOrEmail, bool $remember, ?string $captchaResponse, bool $requireCaptcha = false): array
    {
        $result = self::evaluateNative(1, [
            ...self::nullableParts($usernameOrEmail),
            $remember ? '1' : '0',
            ...self::nullableParts($captchaResponse),
            $requireCaptcha ? '1' : '0',
        ]);
        if ($result !== null && \count($result) === 6 && self::allFlags([$result[1], $result[4], $result[5]])) {
            $captcha = self::decodeNullable($result[2], $result[3]);
            if ($captcha !== false && ($captcha !== null) === ($result[4] === '1')) {
                return [
                    'login' => $result[0],
                    'remember' => $result[1] === '1',
                    'captcha' => $captcha,
                    'captcha_present' => $result[4] === '1',
                    'valid' => $result[5] === '1',
                ];
            }
        }

        return self::normalizeLoginAttemptInputPhp($usernameOrEmail, $remember, $captchaResponse, $requireCaptcha);
    }

    /** PHP `trim()`, with null for null or blank input. */
    public static function trimmedOrNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A nullable string crosses as a `0`/`1` presence flag followed by its bytes.
     *
     * @return array{0: string, 1: string}
     */
    public static function nullableParts(?string $value): array
    {
        return [$value === null ? '0' : '1', $value ?? ''];
    }

    /** The decoded nullable string, or false when the flag/value pair is malformed. */
    public static function decodeNullable(string $flag, string $value): string|false|null
    {
        return match (true) {
            $flag === '1' => $value,
            $flag === '0' && $value === '' => null,
            default => false,
        };
    }

    /**
     * @param  list<string>  $values
     */
    public static function allFlags(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== '0' && $value !== '1') {
                return false;
            }
        }

        return true;
    }

    /**
     * Shared Wave 36.5 bridge: count- and length-prefixed (little-endian u32) packets preserve PHP byte
     * strings. Returns the output parts, or null when Rust declined the input or is unavailable.
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateNative(int $mode, array $parts): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = \strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w36_auth_requests_confirm_password($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackNative(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackNative(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > self::NATIVE_MAX_PARTS || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $size = unpack('V', $packet, $offset);
            $size = \is_array($size) ? $size[1] : null;
            $offset += 4;
            if (! \is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
