<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidEmailDomain;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class UpdateProfileRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_profile_rule_spec(
        uint8_t user_kind,
        int64_t user_int,
        const char *user_ptr,
        size_t user_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    private const EMAIL_UNIQUE_PREFIX = 'unique:users,email,';

    private const PASSWORD_REGEX_RULE = 'regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/';

    private const THEME_PREFERENCE_RULE = 'in:light,dark,system';

    private const COLOR_SCHEME_RULE = 'in:blue,indigo,cyan,teal,emerald,violet,pink,rose,red,orange,amber';

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustProfileThemeAdminRequestParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $spec = self::profileRuleSpec($this->user()?->id);

        return [
            'email' => ['nullable', 'string', 'email', 'max:255', $spec['email_unique_rule'], new ValidEmailDomain],
            'password' => ['nullable', 'string', 'min:8', 'confirmed', $spec['password_regex_rule']],
            'theme_preference' => ['sometimes', $spec['theme_preference_rule']],
            'color_scheme' => ['sometimes', $spec['color_scheme_rule']],
        ];
    }

    /**
     * @return array{email_unique_rule: string, password_regex_rule: string, theme_preference_rule: string, color_scheme_rule: string}
     */
    public static function profileRuleSpec(mixed $userId): array
    {
        return self::$useRustFfi
            ? self::profileRuleSpecNative($userId)
            : self::profileRuleSpecPhp($userId);
    }

    /**
     * @return array{email_unique_rule: string, password_regex_rule: string, theme_preference_rule: string, color_scheme_rule: string}
     */
    public static function profileRuleSpecNative(mixed $userId): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::profileRuleSpecPhp($userId);
        }

        if ($userId !== null && ! is_int($userId) && ! is_string($userId)) {
            return self::profileRuleSpecPhp($userId);
        }

        $kind = match (true) {
            $userId === null => 0,
            is_int($userId) => 1,
            default => 2,
        };
        $userInt = is_int($userId) ? $userId : 0;
        $userStr = is_string($userId) ? $userId : '';

        try {
            $lensSlot = $ffi->new('size_t[4]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 256 + strlen($userStr), $capacity);
            if ($outBuf === null) {
                return self::profileRuleSpecPhp($userId);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_profile_rule_spec',
                $kind,
                $userInt,
                $userStr,
                strlen($userStr),
                $lensSlot,
                $outBuf,
                $capacity
            );
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::profileRuleSpecPhp($userId);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $fields = [];
            $offset = 0;
            for ($i = 0; $i < 4; $i++) {
                $len = (int) $lensSlot[$i];
                if ($len < 0 || $offset + $len > $written) {
                    return self::profileRuleSpecPhp($userId);
                }
                $fields[] = (string) substr($payload, $offset, $len);
                $offset += $len;
            }
            if ($offset !== $written) {
                return self::profileRuleSpecPhp($userId);
            }

            return [
                'email_unique_rule' => $fields[0],
                'password_regex_rule' => $fields[1],
                'theme_preference_rule' => $fields[2],
                'color_scheme_rule' => $fields[3],
            ];
        } catch (Throwable) {
            return self::profileRuleSpecPhp($userId);
        }
    }

    /**
     * @return array{email_unique_rule: string, password_regex_rule: string, theme_preference_rule: string, color_scheme_rule: string}
     */
    public static function profileRuleSpecPhp(mixed $userId): array
    {
        return [
            'email_unique_rule' => self::EMAIL_UNIQUE_PREFIX.$userId,
            'password_regex_rule' => self::PASSWORD_REGEX_RULE,
            'theme_preference_rule' => self::THEME_PREFERENCE_RULE,
            'color_scheme_rule' => self::COLOR_SCHEME_RULE,
        ];
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
