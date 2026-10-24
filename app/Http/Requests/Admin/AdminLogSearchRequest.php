<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\SearchQuery;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class AdminLogSearchRequest extends FormRequest
{
    public const int BOUNDARY_EXACT = 0;

    public const int BOUNDARY_DAY = 1;

    public const int BOUNDARY_MINUTE = 2;

    /**
     * Rust parity export; the boundary shape check stays in PHP (below the 1.5x adoption gate, see
     * `tests/Unit/RustLogViewerAndAuditParserParityTest.php`).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_admin_log_boundary_shape(const char *value, size_t len);
        C;

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $query = $this->input('q');
        $merge = ['q' => is_string($query) ? trim($query) : $query];

        foreach (['from', 'to'] as $key) {
            if ($this->input($key) === '') {
                $merge[$key] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->filterRules(),
            'q' => [...$this->termRules(), 'required_without_all:levels,channels,from,to'],
            'before' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Rules shared with the facets request: the term, its options, files and filters.
     *
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'q' => $this->termRules(),
            'regex' => ['nullable', 'boolean'],
            'case' => ['nullable', 'boolean'],
            'files' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('nntmux.log_viewer.max_files_per_search', 25))],
            'files.*' => ['required', 'string', 'max:255', 'distinct'],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['string', Rule::in(LogEntryParser::LEVELS)],
            'channels' => ['nullable', 'array', 'max:50'],
            'channels.*' => ['string', 'max:64', 'regex:/^[\w.\-]+$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }

    /**
     * @return list<string>
     */
    private function termRules(): array
    {
        return ['nullable', 'string', 'min:2', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $term = $this->input('q');

                if ($this->boolean('regex') && is_string($term) && $term !== '' && ! SearchQuery::isValidRegex($term)) {
                    $validator->errors()->add('q', 'Invalid regular expression.');
                }

                if ($this->filled('before') && count((array) $this->input('files', [])) !== 1) {
                    $validator->errors()->add('before', 'Paging older matches is only supported for a single file.');
                }
            },
        ];
    }

    public function searchQuery(): SearchQuery
    {
        return new SearchQuery(
            term: (string) ($this->validated('q') ?? ''),
            regex: $this->boolean('regex'),
            caseSensitive: $this->boolean('case'),
            levels: array_values(array_unique(array_map('strval', (array) $this->validated('levels', [])))),
            channels: array_values(array_unique(array_map('strval', (array) $this->validated('channels', [])))),
            from: $this->boundary('from'),
            to: $this->boundary('to'),
        );
    }

    /**
     * A validated date input in the application timezone; an upper bound without seconds (or without a
     * time) covers that whole minute (or day), matching how `datetime-local` inputs submit it.
     */
    private function boundary(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::parseBoundary($key, $value);
    }

    /**
     * Parse a non-empty `from`/`to` value; see {@see boundary()}.
     */
    public static function parseBoundary(string $key, string $value): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value);

        if ($key !== 'to') {
            return $date;
        }

        return match (self::boundaryShape($value)) {
            self::BOUNDARY_DAY => $date->endOfDay(),
            self::BOUNDARY_MINUTE => $date->endOfMinute(),
            default => $date,
        };
    }

    /**
     * Whether an upper bound covers a whole day (`Y-m-d`), a whole minute (`… H:i`), or is exact.
     */
    public static function boundaryShape(string $value): int
    {
        return match (true) {
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => self::BOUNDARY_DAY,
            preg_match('/[T ]\d{2}:\d{2}$/', $value) === 1 => self::BOUNDARY_MINUTE,
            default => self::BOUNDARY_EXACT,
        };
    }

    /**
     * Rust parity helper for {@see boundaryShape()}; null when the library is unavailable or disabled.
     */
    public static function boundaryShapeNative(string $value): ?int
    {
        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $shape = $ffi->nntmux_admin_log_boundary_shape($value, strlen($value));
        } catch (Throwable) {
            return null;
        }

        return is_int($shape) && $shape >= 0 ? $shape : null;
    }

    /**
     * Rust parity helper for {@see parseBoundary()}; null when the library is unavailable or disabled.
     */
    public static function parseBoundaryNative(string $key, string $value): ?CarbonImmutable
    {
        $date = CarbonImmutable::parse($value);

        if ($key !== 'to') {
            return $date;
        }

        return match (self::boundaryShapeNative($value)) {
            null => null,
            self::BOUNDARY_DAY => $date->endOfDay(),
            self::BOUNDARY_MINUTE => $date->endOfMinute(),
            default => $date,
        };
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }

        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }
}
