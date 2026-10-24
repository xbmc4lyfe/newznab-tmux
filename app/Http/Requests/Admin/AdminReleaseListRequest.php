<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminReleaseListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $search = $this->input('search');

        $this->merge([
            'search' => self::trimSearch($search),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function searchTerm(): ?string
    {
        return self::searchTermFrom($this->validated('search'));
    }

    public function categoryId(): ?int
    {
        return self::categoryIdFrom($this->validated('category_id'));
    }

    /** See the paired benchmark in RustAdminGroupReleaseReportUserListRequestsParityTest. */
    private static bool $useRustFfi = false;

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
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function nativeCall(int $mode, string $input): ?string
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            static $ffi;
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning', 'ssize_t nntmux_wave22_admin_request(uint32_t mode, const char *input, size_t len, char *out, size_t cap);') ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(64, strlen($input) + 64), $capacity);
            if ($output === null) {
                return null;
            }
            $length = $ffi->nntmux_wave22_admin_request($mode, $input, strlen($input), $output, $capacity);

            return is_int($length) && $length >= 0 && $length <= $capacity
                ? ($length === 0 ? '' : FFI::string($output, $length)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function trimSearch(mixed $value): mixed
    {
        return (self::$useRustFfi ? self::trimSearchNative($value) : null) ?? self::trimSearchPhp($value);
    }

    public static function trimSearchPhp(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    public static function trimSearchNative(mixed $value): ?string
    {
        return is_string($value) ? self::nativeCall(3, $value) : null;
    }

    public static function searchTermFrom(mixed $value): ?string
    {
        return (self::$useRustFfi ? self::searchTermNative($value) : null) ?? self::searchTermPhp($value);
    }

    public static function searchTermPhp(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function searchTermNative(mixed $value): ?string
    {
        $output = is_string($value) ? self::nativeCall(4, $value) : null;

        return $output === '' ? null : $output;
    }

    public static function categoryIdFrom(mixed $value): ?int
    {
        return (self::$useRustFfi ? self::categoryIdNative($value) : null) ?? self::categoryIdPhp($value);
    }

    public static function categoryIdPhp(mixed $value): ?int
    {
        return $value === null || (int) $value === -1 ? null : (int) $value;
    }

    public static function categoryIdNative(mixed $value): ?int
    {
        if (PHP_INT_SIZE !== 8 || (! is_string($value) && ! is_int($value))) {
            return null;
        }
        $output = self::nativeCall(5, (string) $value);

        return $output === null || $output === '' ? null : (int) $output;
    }
}
