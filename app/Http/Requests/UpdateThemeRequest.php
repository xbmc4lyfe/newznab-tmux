<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class UpdateThemeRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_theme_rule_spec(
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

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
        $spec = self::themeRuleSpec();

        return [
            'theme_preference' => ['sometimes', $spec['theme_preference_rule']],
            'color_scheme' => ['sometimes', $spec['color_scheme_rule']],
        ];
    }

    /**
     * @return array{theme_preference_rule: string, color_scheme_rule: string}
     */
    public static function themeRuleSpec(): array
    {
        return self::$useRustFfi ? self::themeRuleSpecNative() : self::themeRuleSpecPhp();
    }

    /**
     * @return array{theme_preference_rule: string, color_scheme_rule: string}
     */
    public static function themeRuleSpecNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::themeRuleSpecPhp();
        }

        try {
            $lensSlot = $ffi->new('size_t[2]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($outBuf === null) {
                return self::themeRuleSpecPhp();
            }

            $written = self::callFfi($ffi, 'nntmux_theme_rule_spec', $lensSlot, $outBuf, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::themeRuleSpecPhp();
            }

            $themeLen = (int) $lensSlot[0];
            $colorLen = (int) $lensSlot[1];
            if ($themeLen + $colorLen !== $written) {
                return self::themeRuleSpecPhp();
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);

            return [
                'theme_preference_rule' => (string) substr($payload, 0, $themeLen),
                'color_scheme_rule' => (string) substr($payload, $themeLen, $colorLen),
            ];
        } catch (Throwable) {
            return self::themeRuleSpecPhp();
        }
    }

    /**
     * @return array{theme_preference_rule: string, color_scheme_rule: string}
     */
    public static function themeRuleSpecPhp(): array
    {
        return [
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
