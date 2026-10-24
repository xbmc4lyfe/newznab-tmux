<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use App\Support\SizeUnit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class SearchReleaseListRequest extends FormRequest
{
    /** Rust helpers are opt-in; none beat PHP by 1.5x in the paired benchmark. */
    private const array RANGE_FIELDS = ['minsize' => ['size', 'min'], 'maxsize' => ['size', 'max'], 'minfiles' => ['totalpart', 'min'], 'maxfiles' => ['totalpart', 'max'], 'mingrabs' => ['grabs', 'min'], 'maxgrabs' => ['grabs', 'max'], 'mincomments' => ['comments', 'min'], 'maxcomments' => ['comments', 'max']];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::trimmedInputs($this->all(), $this->exists('poster')));
        foreach (['search' => 'searchadvr', 't' => 'searchadvcat', 'group' => 'searchadvgroups', 'minage' => 'searchadvdaysnew', 'maxage' => 'searchadvdaysold'] as $field => $alias) {
            if (! $this->has($field) && $this->has($alias)) {
                $value = $this->input($alias);
                $this->merge([$field => $field !== 't' && $value === '-1' ? null : $value]);
            }
        }
        if (! $this->has('search') && ($this->has('subject') || $this->has('id'))) {
            $this->merge(['search' => $this->input('subject', $this->input('id'))]);
        }
        // Legacy buckets retain their existing byte bounds, displayed as MB in the modern form.
        $buckets = [1 => 100, 2 => 200, 3 => 500, 4 => 1000, 5 => 2000, 6 => 3000, 7 => 4000, 8 => 8000, 9 => 16000, 10 => 32000, 11 => 64000];
        foreach (['minsize' => 'searchadvsizefrom', 'maxsize' => 'searchadvsizeto'] as $field => $alias) {
            $value = $this->input($alias);
            if (! $this->has($field) && is_scalar($value) && isset($buckets[(int) $value])) {
                $this->merge([$field => (string) $buckets[(int) $value]]);
            }
        }
        $mode = $this->input('search_type', 'basic');
        if ($mode === null || $mode === 'basic') {
            $mode = $this->anyFilled(['group', 'searchadvposter', 'searchadvsubject', 'searchadvfilename', 'poster', 'poster_release_id', 'minage', 'maxage', 'minsize', 'maxsize', 'added_from', 'added_to', 'minfiles', 'maxfiles', 'mingrabs', 'maxgrabs', 'mincomments', 'maxcomments']) ? 'adv' : 'basic';
        }
        $this->merge(['search_type' => $mode, 'ob' => $this->input('ob') ?? 'posted_desc']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:1000'],
            't' => ['nullable', 'string', 'max:512', 'regex:/^(?:-1|\d+(?:,\d+)*)$/'],
            'group' => ['nullable', 'string', 'max:255'],
            'search_type' => ['nullable', 'string', 'in:basic,adv'],
            'ob' => ['nullable', 'string', 'in:'.implode(',', (new ReleaseBrowseService)->getBrowseOrdering())],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'poster_release_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'poster' => ['nullable', 'string', 'max:1000'],
            'added_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1970-01-01'],
            'added_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1970-01-01'],
        ];
        if ($this->filled('added_from')) {
            $rules['added_to'][] = 'after_or_equal:added_from';
        }
        foreach (['size', 'age', 'files', 'grabs', 'comments'] as $field) {
            $type = $field === 'size' ? 'numeric' : 'integer';
            $maximum = match ($field) {
                'size' => '8796093022207',
                'age' => '3650000',
                default => '4294967295',
            };
            $rules['min'.$field] = ['nullable', $type, 'min:0', 'max:'.$maximum];
            $rules['max'.$field] = ['nullable', $type, 'min:0', 'max:'.$maximum];
            if ($this->filled('min'.$field)) {
                $rules['max'.$field][] = 'gte:min'.$field;
            }
        }
        foreach (['id', 'subject', 'searchadvr', 'searchadvsubject', 'searchadvposter', 'searchadvfilename', 'searchadvcat', 'searchadvgroups'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:1000'];
        }
        foreach (['searchadvdaysnew', 'searchadvdaysold'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:-1', 'max:3650000'];
        }
        foreach (['searchadvsizefrom', 'searchadvsizeto'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:-1', 'max:11'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function listQuery(): array
    {
        $query = array_filter($this->safe()->except(['page', 'id', 'subject', 'searchadvr', 'searchadvcat', 'searchadvgroups', 'searchadvdaysnew', 'searchadvdaysold', 'searchadvsizefrom', 'searchadvsizeto']), static fn (mixed $value): bool => $value !== null && $value !== '');

        return $query + ['search' => '', 't' => '', 'ob' => 'posted_desc', 'search_type' => 'basic'];
    }

    /** @return list<int> */
    public function categoryIds(bool $useRustFfi = false): array
    {
        return self::categoryIdsFrom($this->validated('t'), $useRustFfi);
    }

    /** @return list<int> */
    public static function categoryIdsFrom(mixed $value, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::categoryIdsNative($value) : null) ?? self::categoryIdsPhp($value);
    }

    /** @return list<int> */
    public static function categoryIdsPhp(mixed $value): array
    {
        return is_string($value) && $value !== '' && $value !== '0' && $value !== '-1'
            ? array_values(array_unique(array_map('intval', explode(',', $value))))
            : [-1];
    }

    /** @return list<int>|null */
    public static function categoryIdsNative(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        static $ffi, $buffer, $capacity = 0;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_search_list_category_ids(const char *input, size_t len, int64_t *out, size_t cap);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $needed = substr_count($value, ',') + 1;
            if ($capacity < $needed) {
                $capacity = max(64, $needed * 2);
                $buffer = $ffi->new("int64_t[{$capacity}]");
            }
            $count = $ffi->nntmux_search_list_category_ids($value, strlen($value), $buffer, $capacity);
            if (! is_int($count) || $count < 1 || $count > $capacity) {
                return null;
            }
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = (int) $buffer[$i];
            }

            return $ids;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Request strings trimmed to null when blank; `poster` (and `searchadvposter` when
     * `poster` is present) keep their exact value.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, string|null>
     */
    public static function trimmedInputs(array $input, bool $preserveExactPoster, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::trimmedInputsNative($input, $preserveExactPoster) : null) ?? self::trimmedInputsPhp($input, $preserveExactPoster);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, string|null>
     */
    public static function trimmedInputsPhp(array $input, bool $preserveExactPoster): array
    {
        $trimmed = [];
        foreach ($input as $field => $value) {
            if ($field !== 'poster' && ! ($field === 'searchadvposter' && $preserveExactPoster) && is_string($value)) {
                $trimmed[$field] = trim($value) === '' ? null : trim($value);
            }
        }

        return $trimmed;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, string|null>|null
     */
    public static function trimmedInputsNative(array $input, bool $preserveExactPoster): ?array
    {
        $fields = [];
        foreach ($input as $field => $value) {
            if ($field !== 'poster' && ! ($field === 'searchadvposter' && $preserveExactPoster) && is_string($value)) {
                $fields[$field] = $value;
            }
        }
        $count = count($fields);
        if ($count === 0) {
            return [];
        }
        static $ffi, $lengths, $spans, $capacity = 0;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_search_list_trim_spans(const char *input, size_t len, const int64_t *lens, size_t count, int64_t *out);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            if ($capacity < $count) {
                $capacity = max(64, $count * 2);
                $lengths = $ffi->new("int64_t[{$capacity}]");
                $spans = $ffi->new('int64_t['.($capacity * 2).']');
            }
            $i = 0;
            foreach ($fields as $value) {
                $lengths[$i++] = strlen($value);
            }
            $joined = implode('', $fields);
            if ($ffi->nntmux_search_list_trim_spans($joined, strlen($joined), $lengths, $count, $spans) !== 0) {
                return null;
            }
            $trimmed = [];
            $i = 0;
            foreach ($fields as $field => $value) {
                $length = (int) $spans[2 * $i + 1];
                $trimmed[$field] = $length < 0 ? null : substr($joined, (int) $spans[2 * $i], $length);
                $i++;
            }

            return $trimmed;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Size (MB to bytes), file, grab and comment bounds keyed by index column.
     *
     * @param  array<string, mixed>  $values  validated `min*`/`max*` inputs
     * @return array<string, array<string, int>>
     */
    public static function numericRanges(array $values, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::numericRangesNative($values) : null) ?? self::numericRangesPhp($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array<string, int>>
     */
    public static function numericRangesPhp(array $values): array
    {
        $ranges = [];
        foreach (['size' => 'size', 'files' => 'totalpart', 'grabs' => 'grabs', 'comments' => 'comments'] as $field => $column) {
            foreach (['min', 'max'] as $bound) {
                $value = $values[$bound.$field] ?? null;
                if ($value !== null && $value !== '') {
                    $ranges[$column][$bound] = $field === 'size' ? SizeUnit::toBytes($value, 'MB') : (int) $value;
                }
            }
        }

        return $ranges;
    }

    /**
     * Native conversion of plain decimal/integer strings; null defers to PHP.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array<string, int>>|null
     */
    public static function numericRangesNative(array $values): ?array
    {
        $packed = '';
        $flags = 0;
        $bit = 0;
        foreach (self::RANGE_FIELDS as $key => $_) {
            $value = $values[$key] ?? null;
            if ($value !== null && $value !== '') {
                if (! is_string($value) || str_contains($value, "\0")) {
                    return null;
                }
                $flags |= 1 << $bit;
                $packed .= $value;
            }
            $packed .= "\0";
            $bit++;
        }
        if ($flags === 0) {
            return [];
        }
        static $ffi, $out;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_search_list_ranges(const char *input, size_t len, uint32_t flags, int64_t *out);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $out ??= $ffi->new('int64_t[8]');
            if ($ffi->nntmux_search_list_ranges($packed, strlen($packed), $flags, $out) !== 0) {
                return null;
            }
            $ranges = [];
            $bit = 0;
            foreach (self::RANGE_FIELDS as [$column, $bound]) {
                if ($flags & (1 << $bit)) {
                    $ranges[$column][$bound] = (int) $out[$bit];
                }
                $bit++;
            }

            return $ranges;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, array<string, int>> */
    public function ranges(bool $useRustFfi = false): array
    {
        $values = [];
        foreach (array_keys(self::RANGE_FIELDS) as $key) {
            $values[$key] = $this->validated($key);
        }
        $ranges = self::numericRanges($values, $useRustFfi);
        foreach (['from' => 'min', 'to' => 'max'] as $field => $bound) {
            $value = $this->validated('added_'.$field);
            if (is_string($value) && $value !== '') {
                $date = CarbonImmutable::parse($value, config('app.timezone', 'UTC'));
                $ranges['adddate_ts'][$bound] = ($bound === 'min' ? $date->startOfDay() : $date->endOfDay())->getTimestamp();
            }
        }

        return $ranges;
    }
}
