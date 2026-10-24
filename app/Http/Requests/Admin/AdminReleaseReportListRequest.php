<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminReleaseReportListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:pending,reviewed,resolved,dismissed,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function status(): string
    {
        return self::statusFrom($this->validated('status', 'pending'));
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

    public static function statusFrom(mixed $value): string
    {
        return (self::$useRustFfi ? self::statusNative($value) : null) ?? self::statusPhp($value);
    }

    public static function statusPhp(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'pending';
    }

    public static function statusNative(mixed $value): ?string
    {
        return is_string($value) ? self::nativeCall(8, $value) : null;
    }
}
