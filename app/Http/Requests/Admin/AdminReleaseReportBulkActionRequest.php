<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminReleaseReportBulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:dismiss,resolve,reviewed,delete,revert'],
            'report_ids' => ['required', 'array', 'min:1'],
            'report_ids.*' => ['integer', 'exists:release_reports,id'],
        ];
    }

    /**
     * @return list<int>
     */
    public function reportIds(): array
    {
        return self::reportIdsFrom($this->validated('report_ids', []));
    }

    public function actionName(): string
    {
        return self::actionNameFrom($this->validated('action'));
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

    /** @param list<string|null> $values */
    private static function packValues(array $values): ?string
    {
        $packed = '';
        foreach ($values as $value) {
            if ($value !== null && strlen($value) >= 0xFFFFFFFF) {
                return null;
            }
            $packed .= pack('V', $value === null ? 0xFFFFFFFF : strlen($value)).($value ?? '');
        }

        return $packed;
    }

    /** @return list<string|null>|null */
    private static function unpackValues(string $packed): ?array
    {
        $values = [];
        $offset = 0;
        $size = strlen($packed);
        while ($offset < $size) {
            if ($size - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', $packed, $offset);
            if ($header === false) {
                return null;
            }
            $length = $header['length'];
            $offset += 4;
            if ($length === 0xFFFFFFFF) {
                $values[] = null;
            } else {
                if ($length > $size - $offset) {
                    return null;
                }
                $values[] = substr($packed, $offset, $length);
                $offset += $length;
            }
        }

        return $values;
    }

    /** @return list<int> */
    public static function reportIdsFrom(mixed $value): array
    {
        return (self::$useRustFfi ? self::reportIdsNative($value) : null) ?? self::reportIdsPhp($value);
    }

    /** @return list<int> */
    public static function reportIdsPhp(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', is_array($value) ? $value : [])));
    }

    /** @return list<int>|null */
    public static function reportIdsNative(mixed $value): ?array
    {
        if (PHP_INT_SIZE !== 8 || ! is_array($value)) {
            return null;
        }
        $fields = [];
        foreach ($value as $id) {
            if (! is_string($id) && ! is_int($id)) {
                return null;
            }
            $fields[] = (string) $id;
        }
        $packed = self::packValues($fields);
        $output = $packed === null ? null : self::nativeCall(6, $packed);
        $values = $output === null ? null : self::unpackValues($output);
        if ($values === null || in_array(null, $values, true)) {
            return null;
        }

        return array_map(intval(...), $values);
    }

    public static function actionNameFrom(mixed $value): string
    {
        return (self::$useRustFfi ? self::actionNameNative($value) : null) ?? self::actionNamePhp($value);
    }

    public static function actionNamePhp(mixed $value): string
    {
        return (string) $value;
    }

    public static function actionNameNative(mixed $value): ?string
    {
        return is_string($value) ? self::nativeCall(7, $value) : null;
    }
}
