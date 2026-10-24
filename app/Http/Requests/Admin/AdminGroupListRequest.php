<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\UsenetGroup;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminGroupListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::trimmedInputs($this->all()));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = [
            'groupname' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'string', 'in:0,1'],
            'backfill' => ['nullable', 'string', 'in:0,1'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', array_keys(UsenetGroup::LIST_SORT_COLUMNS))],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        foreach (['first_post', 'last_post', 'last_updated'] as $field) {
            $rules[$field.'_from'] = ['nullable', 'date_format:Y-m-d'];
            $rules[$field.'_to'] = ['nullable', 'date_format:Y-m-d'];
            if ($this->filled($field.'_from')) {
                $rules[$field.'_to'][] = 'after_or_equal:'.$field.'_from';
            }
        }

        foreach (['releases', 'min_files', 'min_size', 'backfill_days'] as $field) {
            $type = $field === 'min_size' ? 'numeric' : 'integer';
            $maximum = $field === 'min_size' ? '8796093022207' : (string) PHP_INT_MAX;
            $rules[$field.'_min'] = ['nullable', $type, 'min:0', 'max:'.$maximum];
            $rules[$field.'_max'] = ['nullable', $type, 'min:0', 'max:'.$maximum];
            if ($this->filled($field.'_min')) {
                $rules[$field.'_max'][] = 'gte:'.$field.'_min';
            }
        }

        return $rules;
    }

    public function groupName(): string
    {
        return self::groupNameFrom($this->validated('groupname', ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function listOptions(): array
    {
        return self::listOptionsFrom($this->safe()->except(['groupname', 'page']));
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

    /** @param array<array-key, mixed> $input
     * @return array<array-key, string|null>
     */
    public static function trimmedInputs(array $input): array
    {
        return (self::$useRustFfi ? self::trimmedInputsNative($input) : null) ?? self::trimmedInputsPhp($input);
    }

    /** @param array<array-key, mixed> $input
     * @return array<array-key, string|null>
     */
    public static function trimmedInputsPhp(array $input): array
    {
        $trimmed = [];
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $trimmed[$key] = trim($value) === '' ? null : trim($value);
            }
        }

        return $trimmed;
    }

    /** @param array<array-key, mixed> $input
     * @return array<array-key, string|null>|null
     */
    public static function trimmedInputsNative(array $input): ?array
    {
        $fields = array_filter($input, is_string(...));
        $packed = self::packValues(array_values($fields));
        $output = $packed === null ? null : self::nativeCall(0, $packed);
        $values = $output === null ? null : self::unpackValues($output);
        if ($values === null || count($fields) !== count($values)) {
            return null;
        }

        return array_combine(array_keys($fields), $values);
    }

    public static function groupNameFrom(mixed $value): string
    {
        return (self::$useRustFfi ? self::groupNameNative($value) : null) ?? self::groupNamePhp($value);
    }

    public static function groupNamePhp(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function groupNameNative(mixed $value): ?string
    {
        return is_string($value) ? self::nativeCall(1, $value) : null;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function listOptionsFrom(array $values): array
    {
        return (self::$useRustFfi ? self::listOptionsNative($values) : null) ?? self::listOptionsPhp($values);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function listOptionsPhp(array $values): array
    {
        unset($values['groupname'], $values['page']);
        $options = array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $options + ['sort' => 'name', 'direction' => 'asc'];
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    public static function listOptionsNative(array $values): ?array
    {
        $fields = [];
        foreach ($values as $key => $value) {
            // Empty strings and null alone are omitted; bool/int zero remain present.
            if (! is_string($key) || (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null)) {
                return null;
            }
            $fields[] = $key;
            $fields[] = $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        $packed = self::packValues($fields);
        $output = $packed === null ? null : self::nativeCall(2, $packed);
        $keys = $output === null ? null : self::unpackValues($output);
        if ($keys === null) {
            return null;
        }
        $options = [];
        foreach ($keys as $key) {
            if ($key === null || ! array_key_exists($key, $values)) {
                return null;
            }
            $options[$key] = $values[$key];
        }

        return $options + ['sort' => 'name', 'direction' => 'asc'];
    }
}
