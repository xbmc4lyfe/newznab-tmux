<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\CaptchaHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class ContactContactURequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_contact_request_build_rules(
        uint32_t captcha_enabled,
        const char *extra_keys_ptr,
        size_t extra_keys_len,
        const char *extra_vals_ptr,
        size_t extra_vals_len,
        size_t extra_count,
        size_t *out_pair_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_contact_request_validation_messages(
        size_t *out_pair_count,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminIncidentContentRequestParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $captchaEnabled = CaptchaHelper::isEnabled();
        $captchaRules = $captchaEnabled ? CaptchaHelper::getValidationRules() : [];

        return self::buildContactRules($captchaEnabled, $captchaRules);
    }

    /**
     * Get custom validation messages
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::contactValidationMessages();
    }

    /**
     * @param  array<string, mixed>  $captchaRules
     * @return array<string, mixed>
     */
    public static function buildContactRules(bool $captchaEnabled, array $captchaRules = []): array
    {
        return self::$useRustFfi
            ? self::buildContactRulesNative($captchaEnabled, $captchaRules)
            : self::buildContactRulesPhp($captchaEnabled, $captchaRules);
    }

    /**
     * @param  array<string, mixed>  $captchaRules
     * @return array<string, mixed>
     */
    public static function buildContactRulesNative(bool $captchaEnabled, array $captchaRules = []): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildContactRulesPhp($captchaEnabled, $captchaRules);
        }

        $extraKeys = [];
        $extraVals = [];
        $nonStringRules = [];

        if ($captchaEnabled) {
            foreach ($captchaRules as $key => $rule) {
                $keyStr = (string) $key;
                if ($keyStr === '' || str_contains($keyStr, "\0")) {
                    return self::buildContactRulesPhp($captchaEnabled, $captchaRules);
                }
                if (is_string($rule) && ! str_contains($rule, "\0")) {
                    $extraKeys[] = $keyStr;
                    $extraVals[] = $rule;
                } else {
                    $nonStringRules[$keyStr] = $rule;
                }
            }
        }

        $extraCount = count($extraKeys);
        $packedKeys = $extraCount === 0 ? '' : implode("\0", $extraKeys);
        $packedVals = $extraCount === 0 ? '' : implode("\0", $extraVals);

        try {
            $pairCountSlot = $ffi->new('size_t[1]');
            $capacity = 0;
            $requiredCap = max(256, strlen($packedKeys) + strlen($packedVals) + 160);
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, $requiredCap, $capacity);
            if ($outBuf === null) {
                return self::buildContactRulesPhp($captchaEnabled, $captchaRules);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_contact_request_build_rules',
                $captchaEnabled ? 1 : 0,
                $packedKeys,
                strlen($packedKeys),
                $packedVals,
                strlen($packedVals),
                $extraCount,
                $pairCountSlot,
                $outBuf,
                $capacity
            );

            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::buildContactRulesPhp($captchaEnabled, $captchaRules);
            }

            $pairCount = (int) $pairCountSlot[0];
            $decoded = self::decodeNulKeyValuePairs(
                $written === 0 ? '' : FFI::string($outBuf, $written),
                $pairCount
            );
            if ($decoded === null) {
                return self::buildContactRulesPhp($captchaEnabled, $captchaRules);
            }

            if ($nonStringRules !== []) {
                return array_merge($decoded, $nonStringRules);
            }

            return $decoded;
        } catch (Throwable) {
            return self::buildContactRulesPhp($captchaEnabled, $captchaRules);
        }
    }

    /**
     * @param  array<string, mixed>  $captchaRules
     * @return array<string, mixed>
     */
    public static function buildContactRulesPhp(bool $captchaEnabled, array $captchaRules = []): array
    {
        $rules = [
            'username' => 'required|string|max:255',
            'useremail' => 'required|email|max:255',
            'comment' => 'required|string|min:10',
        ];

        if ($captchaEnabled) {
            $rules = array_merge($rules, $captchaRules);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function contactValidationMessages(): array
    {
        return self::$useRustFfi
            ? self::contactValidationMessagesNative()
            : self::contactValidationMessagesPhp();
    }

    /**
     * @return array<string, string>
     */
    public static function contactValidationMessagesNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::contactValidationMessagesPhp();
        }

        try {
            $pairCountSlot = $ffi->new('size_t[1]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 512, $capacity);
            if ($outBuf === null) {
                return self::contactValidationMessagesPhp();
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_contact_request_validation_messages',
                $pairCountSlot,
                $outBuf,
                $capacity
            );

            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::contactValidationMessagesPhp();
            }

            $pairCount = (int) $pairCountSlot[0];
            $decoded = self::decodeNulKeyValuePairs(
                $written === 0 ? '' : FFI::string($outBuf, $written),
                $pairCount
            );
            if ($decoded !== null) {
                return $decoded;
            }
        } catch (Throwable) {
        }

        return self::contactValidationMessagesPhp();
    }

    /**
     * @return array<string, string>
     */
    public static function contactValidationMessagesPhp(): array
    {
        return [
            'username.required' => 'Please enter your name.',
            'useremail.required' => 'Please enter your email address.',
            'useremail.email' => 'Please enter a valid email address.',
            'comment.required' => 'Please enter your message.',
            'comment.min' => 'Your message must be at least 10 characters long.',
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private static function decodeNulKeyValuePairs(string $payload, int $expectedPairCount): ?array
    {
        if ($expectedPairCount === 0) {
            return $payload === '' ? [] : null;
        }

        $parts = explode("\0", $payload);
        if (count($parts) !== $expectedPairCount * 2) {
            return null;
        }

        $result = [];
        for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
            $result[$parts[$i]] = $parts[$i + 1];
        }

        return $result;
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
