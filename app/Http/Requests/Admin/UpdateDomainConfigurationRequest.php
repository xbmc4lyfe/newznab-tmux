<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ConfigurationDomain;
use App\Support\Configuration\SettingsPageCatalog;
use App\Support\RustPipelineLibrary;
use App\Support\SizeUnit;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

final class UpdateDomainConfigurationRequest extends FormRequest
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_domcfg_color_exclusions(const char *raw, size_t raw_len, int64_t *out, size_t cap);
        ssize_t nntmux_domcfg_color_exclusion_errors(const char *raw, size_t raw_len, int64_t start, int64_t end, char *out, size_t cap);
        int32_t nntmux_domcfg_range_invalid(int32_t kind, int64_t a, int64_t b);
        C;

    private const RANGE_COLORS = 0;

    private const RANGE_SIZE_PAIR = 1;

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $domain = ConfigurationDomain::from((string) $this->route('domain'));
        $rules = [];

        foreach (app(SettingsPageCatalog::class)->fieldsForPage($domain) as $field) {
            $rules[$field->column] = $field->rules;
            if ($domain === ConfigurationDomain::PostProcessing && in_array($field->column, [...SettingsPageCatalog::MOVIE_PROCESSING_COLUMNS, ...SettingsPageCatalog::VIDEO_PANE_COLUMNS], true)) {
                array_unshift($rules[$field->column], 'sometimes');
            }
            if ($field->control === 'bytes') {
                $rules[$field->column.'_unit'] = ['required', 'in:'.implode(',', SizeUnit::UNITS)];
            }
            if ($field->sensitive) {
                $rules['clear_'.$field->column] = ['nullable', 'boolean'];
            }
        }

        if ($domain === ConfigurationDomain::Site) {
            $rules['remove_site_logo'] = ['nullable', 'boolean'];
        }

        if ($domain === ConfigurationDomain::Tmux) {
            $rules['cleanup_rules.*'] = ['string', 'in:'.implode(',', SettingsPageCatalog::cleanupRules())];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $domain = ConfigurationDomain::from((string) $this->route('domain'));

            if ($domain === ConfigurationDomain::PostProcessing || $domain === ConfigurationDomain::Ingestion) {
                $this->validateSizePairs($validator, $domain);
            }

            if ($domain === ConfigurationDomain::PostProcessing) {
                $pattern = (string) $this->input('inner_file_blacklist', '');
                if ($pattern !== '' && @preg_match($pattern, '') === false) {
                    $validator->errors()->add('inner_file_blacklist', 'The inner file blacklist must be a valid regular expression.');
                }
            }

            if ($domain === ConfigurationDomain::Tmux) {
                $start = (int) $this->input('colors_start');
                $end = (int) $this->input('colors_end');
                if (self::colorRangeInvalid($start, $end)) {
                    $validator->errors()->add('colors_end', 'The ending color must be greater than the starting color.');
                }

                foreach (self::colorExclusionErrors((string) $this->input('color_exclusions', ''), $start, $end) as $message) {
                    $validator->errors()->add('color_exclusions', $message);
                }
            }
        }];
    }

    /** @return list<int> */
    public function colorExclusions(): array
    {
        return self::parseColorExclusions((string) $this->input('color_exclusions', ''));
    }

    private function validateSizePairs(Validator $validator, ConfigurationDomain $domain): void
    {
        $pairs = $domain === ConfigurationDomain::Ingestion
            ? [['min_size_to_form_release', 'max_size_to_form_release']]
            : [
                ['min_size_to_post_process', 'max_size_to_post_process'],
                ['min_size_to_process_nfo', 'max_size_to_process_nfo'],
            ];

        foreach ($pairs as [$minimum, $maximum]) {
            $minimumBytes = SizeUnit::toBytes($this->input($minimum), (string) $this->input($minimum.'_unit', 'MB'));
            $maximumBytes = SizeUnit::toBytes($this->input($maximum), (string) $this->input($maximum.'_unit', 'MB'));
            if (self::sizePairInvalid($minimumBytes, $maximumBytes)) {
                $validator->errors()->add($maximum, 'The maximum size must be zero (unlimited) or at least the minimum size.');
            }
        }
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    /**
     * Unique `(int)` values of a whitespace/comma separated exclusion list, in first-seen order.
     *
     * @return list<int>
     */
    public static function parseColorExclusions(string $raw): array
    {
        return self::usesRustFfi() ? self::parseColorExclusionsNative($raw) : self::parseColorExclusionsPhp($raw);
    }

    /**
     * @return list<int>
     */
    public static function parseColorExclusionsPhp(string $raw): array
    {
        $values = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map('intval', $values)));
    }

    /**
     * @return list<int>
     */
    public static function parseColorExclusionsNative(string $raw): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::parseColorExclusionsPhp($raw);
        }
        // Every token is followed by a separator or the end, so at most half the bytes (rounded up).
        $capacity = intdiv(\strlen($raw) + 1, 2) + 1;
        try {
            $out = $ffi->new("int64_t[{$capacity}]");
            // @phpstan-ignore method.notFound
            $count = $ffi->nntmux_domcfg_color_exclusions($raw, \strlen($raw), $out, $capacity);
            if (! \is_int($count) || $count < 0 || $count > $capacity) {
                return self::parseColorExclusionsPhp($raw);
            }
            $values = [];
            for ($i = 0; $i < $count; $i++) {
                $values[] = (int) $out[$i];
            }

            return $values;
        } catch (Throwable) {
            return self::parseColorExclusionsPhp($raw);
        }
    }

    /**
     * Validation messages for each excluded color that is not an integer from 0 through 255
     * or lies outside `[$start, $end]`, in input order.
     *
     * @return list<string>
     */
    public static function colorExclusionErrors(string $raw, int $start, int $end): array
    {
        return self::usesRustFfi() ? self::colorExclusionErrorsNative($raw, $start, $end) : self::colorExclusionErrorsPhp($raw, $start, $end);
    }

    /**
     * @return list<string>
     */
    public static function colorExclusionErrorsPhp(string $raw, int $start, int $end): array
    {
        $errors = [];
        $rawColors = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($rawColors as $rawColor) {
            if (preg_match('/^\d+$/', $rawColor) !== 1 || (int) $rawColor > 255) {
                $errors[] = "Excluded color {$rawColor} must be an integer from 0 through 255.";

                continue;
            }

            $color = (int) $rawColor;
            if ($color < $start || $color > $end) {
                $errors[] = "Excluded color {$color} must be inside the configured color range.";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public static function colorExclusionErrorsNative(string $raw, int $start, int $end): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::colorExclusionErrorsPhp($raw, $start, $end);
        }
        // Each token (at least two bytes with its separator) adds itself plus at most 63 bytes.
        $needed = \strlen($raw) * 35 + 80;
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, $needed, $capacity);
        if ($buffer === null) {
            return self::colorExclusionErrorsPhp($raw, $start, $end);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_domcfg_color_exclusion_errors($raw, \strlen($raw), $start, $end, $buffer, $capacity);
            if ($written === 0) {
                return [];
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::colorExclusionErrorsPhp($raw, $start, $end);
            }

            return explode("\n", FFI::string($buffer, $written));
        } catch (Throwable) {
            return self::colorExclusionErrorsPhp($raw, $start, $end);
        }
    }

    /**
     * Whether the tmux color range is empty or reversed.
     */
    public static function colorRangeInvalid(int $start, int $end): bool
    {
        return self::usesRustFfi() ? self::colorRangeInvalidNative($start, $end) : self::colorRangeInvalidPhp($start, $end);
    }

    public static function colorRangeInvalidPhp(int $start, int $end): bool
    {
        return $start >= $end;
    }

    public static function colorRangeInvalidNative(int $start, int $end): bool
    {
        return self::rangeInvalidNative(self::RANGE_COLORS, $start, $end) ?? self::colorRangeInvalidPhp($start, $end);
    }

    /**
     * Whether a bounded (non-zero) maximum size is below the minimum size.
     */
    public static function sizePairInvalid(int $minimumBytes, int $maximumBytes): bool
    {
        return self::usesRustFfi()
            ? self::sizePairInvalidNative($minimumBytes, $maximumBytes)
            : self::sizePairInvalidPhp($minimumBytes, $maximumBytes);
    }

    public static function sizePairInvalidPhp(int $minimumBytes, int $maximumBytes): bool
    {
        return $maximumBytes > 0 && $minimumBytes > $maximumBytes;
    }

    public static function sizePairInvalidNative(int $minimumBytes, int $maximumBytes): bool
    {
        return self::rangeInvalidNative(self::RANGE_SIZE_PAIR, $minimumBytes, $maximumBytes)
            ?? self::sizePairInvalidPhp($minimumBytes, $maximumBytes);
    }

    private static function rangeInvalidNative(int $kind, int $a, int $b): ?bool
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $invalid = $ffi->nntmux_domcfg_range_invalid($kind, $a, $b);
        } catch (Throwable) {
            return null;
        }

        return match ($invalid) {
            1 => true,
            0 => false,
            default => null,
        };
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
