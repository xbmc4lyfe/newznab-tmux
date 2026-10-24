<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminUserListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole('Admin');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'integer', 'exists:roles,id'],
            'verified' => ['nullable', 'in:0,1'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'ob' => ['nullable', 'string', 'in:'.implode(',', getUserBrowseOrdering())],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{username: string, email: string, host: string, role: string, verified: string, created_from: string, created_to: string}
     */
    public function filters(): array
    {
        $values = [];
        foreach (self::FILTER_FIELDS as $key) {
            $values[$key] = $this->input($key, '');
        }

        return self::filtersFrom($values);
    }

    public function orderBy(): string
    {
        return self::orderByFrom($this->input('ob', ''));
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

    private const array FILTER_FIELDS = ['username', 'email', 'host', 'role', 'verified', 'created_from', 'created_to'];

    /** @param array<string, mixed> $values
     * @return array{username: string, email: string, host: string, role: string, verified: string, created_from: string, created_to: string}
     */
    public static function filtersFrom(array $values): array
    {
        return (self::$useRustFfi ? self::filtersNative($values) : null) ?? self::filtersPhp($values);
    }

    /** @param array<string, mixed> $values
     * @return array{username: string, email: string, host: string, role: string, verified: string, created_from: string, created_to: string}
     */
    public static function filtersPhp(array $values): array
    {
        $filters = [];
        foreach (self::FILTER_FIELDS as $key) {
            $filters[$key] = self::scalarValuePhp($values[$key] ?? '');
        }

        return $filters;
    }

    /** @param array<string, mixed> $values
     * @return array{username: string, email: string, host: string, role: string, verified: string, created_from: string, created_to: string}|null
     */
    public static function filtersNative(array $values): ?array
    {
        $fields = [];
        foreach (self::FILTER_FIELDS as $key) {
            $value = $values[$key] ?? '';
            if (is_float($value)) {
                return null;
            }
            $fields[] = is_scalar($value) ? (string) $value : '';
        }
        $packed = self::packValues($fields);
        $output = $packed === null ? null : self::nativeCall(9, $packed);
        $decoded = $output === null ? null : self::unpackValues($output);
        if ($decoded === null || count($decoded) !== 7 || in_array(null, $decoded, true)) {
            return null;
        }

        return array_combine(self::FILTER_FIELDS, $decoded);
    }

    public static function orderByFrom(mixed $value): string
    {
        return (self::$useRustFfi ? self::orderByNative($value) : null) ?? self::orderByPhp($value);
    }

    public static function orderByPhp(mixed $value): string
    {
        $orderBy = self::scalarValuePhp($value);

        return in_array($orderBy, getUserBrowseOrdering(), true) ? $orderBy : '';
    }

    public static function orderByNative(mixed $value): ?string
    {
        if (is_float($value)) {
            return null;
        }
        $packed = self::packValues([is_scalar($value) ? (string) $value : '', ...getUserBrowseOrdering()]);

        return $packed === null ? null : self::nativeCall(10, $packed);
    }

    private static function scalarValuePhp(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
