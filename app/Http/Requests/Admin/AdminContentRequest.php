<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Content;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class AdminContentRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_content_normalize_inputs(
        uint32_t string_mask,
        const char *action_ptr,
        size_t action_len,
        const char *title_ptr,
        size_t title_len,
        const char *url_ptr,
        size_t url_len,
        const char *desc_ptr,
        size_t desc_len,
        const char *kw_ptr,
        size_t kw_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_content_is_submit_action(
        const char *method_ptr,
        size_t method_len,
        uint32_t action_is_str,
        const char *action_ptr,
        size_t action_len
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

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::normalizeContentInput([
            'action' => $this->input('action'),
            'title' => $this->input('title'),
            'url' => $this->input('url'),
            'metadescription' => $this->input('metadescription'),
            'metakeywords' => $this->input('metakeywords'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (! self::isSubmitAction($this->getMethod(), $this->input('action'))) {
            return [];
        }

        return [
            'action' => ['required', 'string', Rule::in(['submit'])],
            'id' => $this->filled('id') ? ['required', 'integer', 'exists:content,id'] : ['nullable'],
            'title' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string'],
            'metadescription' => ['nullable', 'string', 'max:1000'],
            'metakeywords' => ['nullable', 'string', 'max:1000'],
            'contenttype' => ['required', 'integer', Rule::in([Content::TYPE_USEFUL, Content::TYPE_INDEX])],
            'status' => ['required', 'integer', Rule::in([Content::STATUS_ENABLED, Content::STATUS_DISABLED])],
            'ordinal' => ['nullable', 'integer', 'min:0'],
            'role' => ['required', 'integer', Rule::in([Content::ROLE_EVERYONE, Content::ROLE_LOGGED_IN, Content::ROLE_ADMIN])],
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{action: mixed, title: mixed, url: mixed, metadescription: mixed, metakeywords: mixed}
     */
    public static function normalizeContentInput(array $inputs): array
    {
        return self::$useRustFfi
            ? self::normalizeContentInputNative($inputs)
            : self::normalizeContentInputPhp($inputs);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{action: mixed, title: mixed, url: mixed, metadescription: mixed, metakeywords: mixed}
     */
    public static function normalizeContentInputNative(array $inputs): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeContentInputPhp($inputs);
        }

        $fields = ['action', 'title', 'url', 'metadescription', 'metakeywords'];
        $mask = 0;
        $strVals = ['', '', '', '', ''];
        $totalBytes = 0;

        foreach ($fields as $index => $key) {
            $value = $inputs[$key] ?? null;
            if (is_string($value)) {
                $mask |= (1 << $index);
                $strVals[$index] = $value;
                $totalBytes += strlen($value);
            }
        }

        try {
            $lensSlot = $ffi->new('size_t[5]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(64, $totalBytes + 16), $capacity);
            if ($outBuf === null) {
                return self::normalizeContentInputPhp($inputs);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_admin_content_normalize_inputs',
                $mask,
                $strVals[0],
                strlen($strVals[0]),
                $strVals[1],
                strlen($strVals[1]),
                $strVals[2],
                strlen($strVals[2]),
                $strVals[3],
                strlen($strVals[3]),
                $strVals[4],
                strlen($strVals[4]),
                $lensSlot,
                $outBuf,
                $capacity
            );

            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::normalizeContentInputPhp($inputs);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $offset = 0;
            $normalized = [];

            foreach ($fields as $index => $key) {
                if (($mask & (1 << $index)) !== 0) {
                    $len = (int) $lensSlot[$index];
                    if ($len < 0 || $offset + $len > $written) {
                        return self::normalizeContentInputPhp($inputs);
                    }
                    $normalized[$key] = $len === 0 ? '' : substr($payload, $offset, $len);
                    $offset += $len;
                } else {
                    $normalized[$key] = $inputs[$key] ?? null;
                }
            }

            if ($offset !== $written) {
                return self::normalizeContentInputPhp($inputs);
            }

            /** @var array{action: mixed, title: mixed, url: mixed, metadescription: mixed, metakeywords: mixed} $normalized */
            return $normalized;
        } catch (Throwable) {
            return self::normalizeContentInputPhp($inputs);
        }
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{action: mixed, title: mixed, url: mixed, metadescription: mixed, metakeywords: mixed}
     */
    public static function normalizeContentInputPhp(array $inputs): array
    {
        $action = $inputs['action'] ?? null;
        $title = $inputs['title'] ?? null;
        $url = $inputs['url'] ?? null;
        $metadescription = $inputs['metadescription'] ?? null;
        $metakeywords = $inputs['metakeywords'] ?? null;

        return [
            'action' => is_string($action) ? trim($action) : $action,
            'title' => is_string($title) ? trim($title) : $title,
            'url' => is_string($url) ? trim($url) : $url,
            'metadescription' => is_string($metadescription) ? trim($metadescription) : $metadescription,
            'metakeywords' => is_string($metakeywords) ? trim($metakeywords) : $metakeywords,
        ];
    }

    public static function isSubmitAction(string $method, mixed $action): bool
    {
        return self::$useRustFfi
            ? self::isSubmitActionNative($method, $action)
            : self::isSubmitActionPhp($method, $action);
    }

    public static function isSubmitActionNative(string $method, mixed $action): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isSubmitActionPhp($method, $action);
        }

        $isStr = is_string($action);
        $actionStr = $isStr ? $action : '';

        try {
            $rc = self::callFfi(
                $ffi,
                'nntmux_admin_content_is_submit_action',
                $method,
                strlen($method),
                $isStr ? 1 : 0,
                $actionStr,
                strlen($actionStr)
            );
            if ($rc === 1) {
                return true;
            }
            if ($rc === 0) {
                return false;
            }
        } catch (Throwable) {
        }

        return self::isSubmitActionPhp($method, $action);
    }

    public static function isSubmitActionPhp(string $method, mixed $action): bool
    {
        return strcasecmp($method, 'POST') === 0 && $action === 'submit';
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
