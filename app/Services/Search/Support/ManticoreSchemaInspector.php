<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class ManticoreSchemaInspector
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_manticore_schema_compare_columns_nul(const char *actual_nul, size_t actual_len, size_t actual_count, const char *expected_nul, size_t expected_len, size_t expected_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_manticore_schema_missing_settings_nul(const char *actual_nul, size_t actual_len, size_t actual_count, const char *expected_nul, size_t expected_len, size_t expected_count, char *out_buf, size_t out_cap);
        C;

    public const string W43_FFI_CDEF = 'int32_t nntmux_w435_manticore_release_browse_duplicate_group_mgmt(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in for the Wave 43.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustManticoreSchemaReleaseBrowseDuplicateGroupMgmtW43ParityTest.php.
     */
    public const bool W43_MANTICORE_SCHEMA_RUST_FFI_DEFAULT = false;

    private const int W43_NATIVE_MAX_PARTS = 1 << 20;

    private const int W43_NATIVE_MAX_BYTES = 16777216;

    private const string SETTING_LINE = '/^\s*([a-z0-9_]+)\s*=\s*[\'\"]?([^\'\"]+?)[\'\"]?\s*$/i';

    private static FFI|false|null $ffi = null;

    private static ?CData $outBuf1024 = null;

    private static bool $useRustFfi = true;

    private static ?bool $useW43RustFfi = null;

    private static FFI|false|null $w43Ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    public static function missingSettings(mixed $actual, array $expected): array
    {
        if (self::$useRustFfi) {
            if (\is_array($actual) && \count($actual) === 1 && isset($actual['settings']) && \is_string($actual['settings'])) {
                $expPacked = null;
                $expLen = 0;
                $expCount = 0;
                if ($expected === ['min_infix_len' => 2, 'index_field_lengths' => 1]) {
                    $expPacked = "min_infix_len\x002\x00index_field_lengths\x001";
                    $expLen = 37;
                    $expCount = 2;
                } elseif ($expected === ['min_infix_len' => 2, 'exact_words' => 1, 'index_field_lengths' => 1]) {
                    $expPacked = "min_infix_len\x002\x00exact_words\x001\x00index_field_lengths\x001";
                    $expLen = 51;
                    $expCount = 3;
                }
                if ($expPacked !== null) {
                    $ffi = self::$ffi ?? self::resolveFfi();
                    $buf = self::$outBuf1024;
                    if ($ffi !== null && $ffi !== false && $buf !== null) {
                        $blob = $actual['settings'];
                        // @phpstan-ignore method.notFound
                        $written = $ffi->nntmux_manticore_schema_missing_settings_nul(
                            $blob,
                            \strlen($blob),
                            0xFFFFFFFF,
                            $expPacked,
                            $expLen,
                            $expCount,
                            $buf,
                            1024,
                        );
                        if (\is_int($written) && $written >= 0) {
                            return $written === 0 ? [] : \explode("\n", FFI::string($buf, $written));
                        }
                    }
                }
            }
            $native = self::missingSettingsNative($actual, $expected);
            if ($native !== null) {
                return $native;
            }
        }

        return self::missingSettingsPhp($actual, $expected);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>|null
     */
    public static function missingSettingsNative(mixed $actual, array $expected): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($expected === []) {
            return [];
        }

        $actualPacked = '';
        $actualCount = 0;
        if (\is_array($actual) && \count($actual) === 1 && isset($actual['settings']) && \is_string($actual['settings'])) {
            $actualPacked = $actual['settings'];
            $actualCount = 0xFFFFFFFF;
        } elseif (\is_array($actual)) {
            $actualFlat = [];
            foreach ($actual as $key => $value) {
                if (\is_string($key) && ! \is_array($value)) {
                    if ($key === "\x01") {
                        return null;
                    }
                    if (\strtolower($key) === 'settings' && \is_string($value)) {
                        $actualFlat[] = "\x01";
                        $actualFlat[] = $value;
                        $actualCount++;

                        continue;
                    }
                    if (! \is_scalar($value) && $value !== null && ! (\is_object($value) && \method_exists($value, '__toString'))) {
                        return null;
                    }
                    $actualFlat[] = $key;
                    $actualFlat[] = (string) $value;
                    $actualCount++;

                    continue;
                }

                if (! \is_array($value)) {
                    continue;
                }

                $name = $value['Setting_name'] ?? $value['setting_name'] ?? $value['Variable_name'] ?? $value['variable_name'] ?? $value['Name'] ?? $value['name'] ?? null;
                $settingValue = $value['Value'] ?? $value['value'] ?? null;
                if (\is_string($name) && $settingValue !== null) {
                    if ($name === "\x01" || (! \is_scalar($settingValue) && ! (\is_object($settingValue) && \method_exists($settingValue, '__toString')))) {
                        return null;
                    }
                    $actualFlat[] = $name;
                    $actualFlat[] = (string) $settingValue;
                    $actualCount++;

                    continue;
                }

                if (\is_string($key)) {
                    if ($key === "\x01") {
                        return null;
                    }
                    $nestedValue = $value['Value'] ?? $value['value'] ?? \reset($value);
                    if (\is_scalar($nestedValue)) {
                        $actualFlat[] = $key;
                        $actualFlat[] = (string) $nestedValue;
                        $actualCount++;
                    }
                }
            }

            $actualPacked = $actualCount === 0 ? '' : \implode("\0", $actualFlat);
            if ($actualCount > 0 && \substr_count($actualPacked, "\0") !== ($actualCount * 2 - 1)) {
                return null;
            }
        }

        if ($expected === ['min_infix_len' => 2, 'index_field_lengths' => 1]) {
            $expectedPacked = "min_infix_len\x002\x00index_field_lengths\x001";
            $expLen = 37;
            $expectedCount = 2;
        } elseif ($expected === ['min_infix_len' => 2, 'exact_words' => 1, 'index_field_lengths' => 1]) {
            $expectedPacked = "min_infix_len\x002\x00exact_words\x001\x00index_field_lengths\x001";
            $expLen = 51;
            $expectedCount = 3;
        } else {
            $expectedFlat = [];
            $expectedCount = 0;
            foreach ($expected as $setting => $expectedVal) {
                if (! \is_string($setting) || $setting === '' || (! \is_scalar($expectedVal) && $expectedVal !== null && ! (\is_object($expectedVal) && \method_exists($expectedVal, '__toString')))) {
                    return null;
                }
                $expectedFlat[] = $setting;
                $expectedFlat[] = (string) $expectedVal;
                $expectedCount++;
            }
            $expectedPacked = \implode("\0", $expectedFlat);
            if (\substr_count($expectedPacked, "\0") !== ($expectedCount * 2 - 1)) {
                return null;
            }
            $expLen = \strlen($expectedPacked);
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        $buf = self::$outBuf1024;
        if ($ffi === null || $ffi === false || $buf === null) {
            return null;
        }

        $actLen = \strlen($actualPacked);

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_schema_missing_settings_nul(
                $actualPacked,
                $actLen,
                $actualCount,
                $expectedPacked,
                $expLen,
                $expectedCount,
                $buf,
                1024,
            );
            if (! \is_int($written) || $written === -1 || $written === -2) {
                return null;
            }
            if ($written < -2) {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_manticore_schema_missing_settings_nul(
                    $actualPacked,
                    $actLen,
                    $actualCount,
                    $expectedPacked,
                    $expLen,
                    $expectedCount,
                    $scratch,
                    $cap,
                );
                if (! \is_int($written) || $written < 0) {
                    return null;
                }

                return $written === 0 ? [] : \explode("\n", FFI::string($scratch, $written));
            }

            return $written === 0 ? [] : \explode("\n", FFI::string($buf, $written));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    public static function missingSettingsPhp(mixed $actual, array $expected): array
    {
        $settings = self::normalizeSettings($actual);

        return array_values(array_filter(array_keys($expected), static function (string $setting) use ($settings, $expected): bool {
            return ! array_key_exists(strtolower($setting), $settings)
                || $settings[strtolower($setting)] !== strtolower((string) $expected[$setting]);
        }));
    }

    /**
     * Retains native PHP associative lookup in production under the < 1.5x FFI threshold
     * (`0.70x` mean / `0.54x` p99 on 35-column `releases_rt` schema due to nested PHP array
     * flattening and NUL-packing overhead); see `compareColumnsNative`.
     *
     * @param  array<int|string, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}
     */
    public static function compareColumns(array $actual, array $expected): array
    {
        return self::compareColumnsPhp($actual, $expected);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}|null
     */
    public static function compareColumnsNative(array $actual, array $expected): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($expected === []) {
            return ['missing' => [], 'incompatible' => []];
        }

        $actualFlat = [];
        $actualCount = 0;
        foreach ($actual as $key => $column) {
            $nameRaw = $column['Field'] ?? $column['field'] ?? (\is_string($key) ? $key : '');
            if (! \is_scalar($nameRaw)) {
                return null;
            }
            $name = (string) $nameRaw;
            if ($name !== '') {
                $typeRaw = $column['Type'] ?? $column['type'] ?? '';
                if (! \is_scalar($typeRaw)) {
                    return null;
                }
                $actualFlat[] = $name;
                $actualFlat[] = (string) $typeRaw;
                $actualCount++;
            }
        }
        $actualPacked = $actualCount === 0 ? '' : \implode("\0", $actualFlat);
        if ($actualCount > 0 && \substr_count($actualPacked, "\0") !== ($actualCount * 2 - 1)) {
            return null;
        }

        $expectedFlat = [];
        $expectedCount = 0;
        foreach ($expected as $name => $definition) {
            if (! \is_string($name) || $name === '') {
                return null;
            }
            $typeRaw = $definition['type'] ?? '';
            if (! \is_scalar($typeRaw)) {
                return null;
            }
            $expectedFlat[] = $name;
            $expectedFlat[] = (string) $typeRaw;
            $expectedCount++;
        }
        $expectedPacked = \implode("\0", $expectedFlat);
        if (\substr_count($expectedPacked, "\0") !== ($expectedCount * 2 - 1)) {
            return null;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        $buf = self::$outBuf1024;
        if ($ffi === null || $ffi === false || $buf === null) {
            return null;
        }

        $actLen = \strlen($actualPacked);
        $expLen = \strlen($expectedPacked);

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_schema_compare_columns_nul(
                $actualPacked,
                $actLen,
                $actualCount,
                $expectedPacked,
                $expLen,
                $expectedCount,
                $buf,
                1024,
            );
            if (! \is_int($written) || $written === -1 || $written === -2) {
                return null;
            }
            $rawOut = '';
            if ($written < -2) {
                $cap = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_manticore_schema_compare_columns_nul(
                    $actualPacked,
                    $actLen,
                    $actualCount,
                    $expectedPacked,
                    $expLen,
                    $expectedCount,
                    $scratch,
                    $cap,
                );
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
                if ($written > 0) {
                    $rawOut = FFI::string($scratch, $written);
                }
            } elseif ($written > 0) {
                $rawOut = FFI::string($buf, $written);
            }

            if ($rawOut === '') {
                return ['missing' => [], 'incompatible' => []];
            }

            $missing = [];
            $incompatible = [];
            foreach (\explode("\n", $rawOut) as $line) {
                $parts = \explode("\t", $line);
                if ($parts[0] === 'M' && isset($parts[1])) {
                    $missing[] = $parts[1];
                } elseif ($parts[0] === 'I' && isset($parts[1], $parts[2], $parts[3])) {
                    $incompatible[$parts[1]] = ['expected' => $parts[2], 'actual' => $parts[3]];
                }
            }

            return ['missing' => $missing, 'incompatible' => $incompatible];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $actual
     * @param  array<string, array<string, mixed>>  $expected
     * @return array{missing: list<string>, incompatible: array<string, array{expected: string, actual: string}>}
     */
    public static function compareColumnsPhp(array $actual, array $expected): array
    {
        $types = [];
        foreach ($actual as $key => $column) {
            $name = (string) ($column['Field'] ?? $column['field'] ?? (is_string($key) ? $key : ''));
            $type = strtolower((string) ($column['Type'] ?? $column['type'] ?? ''));
            if ($name !== '') {
                $types[$name] = $type;
            }
        }

        $missing = [];
        $incompatible = [];
        foreach ($expected as $name => $definition) {
            $expectedType = strtolower((string) $definition['type']);
            if (! isset($types[$name])) {
                $missing[] = $name;
            } elseif (! self::typesAreCompatible($types[$name], $expectedType)) {
                $incompatible[$name] = ['expected' => $expectedType, 'actual' => $types[$name]];
            }
        }

        return ['missing' => $missing, 'incompatible' => $incompatible];
    }

    /** Pass null to restore {@see W43_MANTICORE_SCHEMA_RUST_FFI_DEFAULT}. */
    public static function useW43RustFfi(?bool $enable = true): void
    {
        self::$useW43RustFfi = $enable;
    }

    public static function usesW43RustFfi(): bool
    {
        return self::$useW43RustFfi ?? self::W43_MANTICORE_SCHEMA_RUST_FFI_DEFAULT;
    }

    /**
     * Summarizes `SHOW TABLE SETTINGS` blobs as {@see normalizeSettings()} parses them. `lines` counts
     * `\R`-separated lines, `settings` the lines that parse, `distinct_keys` and `max_settings_per_blob`
     * the lowercased keys, and `compliant_blobs` the blobs for which {@see missingSettingsPhp()} reports
     * nothing missing. Non-string blobs are invalid.
     *
     * @param  array<array-key, mixed>  $blobs
     * @param  array<array-key, scalar|\Stringable|null>  $expected
     * @return array{blobs: int, invalid: int, lines: int, settings: int, distinct_keys: int, max_settings_per_blob: int, compliant_blobs: int}
     */
    public static function summarizeSettingsBlobBatch(array $blobs, array $expected = []): array
    {
        return self::usesW43RustFfi() ? self::summarizeSettingsBlobBatchNative($blobs, $expected) : self::summarizeSettingsBlobBatchPhp($blobs, $expected);
    }

    /**
     * @param  array<array-key, mixed>  $blobs
     * @param  array<array-key, scalar|\Stringable|null>  $expected
     * @return array{blobs: int, invalid: int, lines: int, settings: int, distinct_keys: int, max_settings_per_blob: int, compliant_blobs: int}
     */
    public static function summarizeSettingsBlobBatchPhp(array $blobs, array $expected = []): array
    {
        $wanted = [];
        foreach ($expected as $setting => $value) {
            $wanted[] = [strtolower((string) $setting), strtolower((string) $value)];
        }
        $invalid = 0;
        $lines = 0;
        $matched = 0;
        $widest = 0;
        $compliant = 0;
        $keys = [];
        foreach ($blobs as $blob) {
            if (! \is_string($blob)) {
                $invalid++;

                continue;
            }
            $settings = [];
            foreach (preg_split('/\R/', $blob) ?: [] as $line) {
                $lines++;
                if (preg_match(self::SETTING_LINE, $line, $match) === 1) {
                    $matched++;
                    $settings[strtolower($match[1])] = strtolower(trim($match[2]));
                }
            }
            $widest = max($widest, \count($settings));
            $ok = 1;
            foreach ($wanted as [$setting, $value]) {
                if (! isset($settings[$setting]) || $settings[$setting] !== $value) {
                    $ok = 0;
                    break;
                }
            }
            $compliant += $ok;
            $keys += $settings;
        }

        return [
            'blobs' => \count($blobs),
            'invalid' => $invalid,
            'lines' => $lines,
            'settings' => $matched,
            'distinct_keys' => \count($keys),
            'max_settings_per_blob' => $widest,
            'compliant_blobs' => $compliant,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $blobs
     * @param  array<array-key, scalar|\Stringable|null>  $expected
     * @return array{blobs: int, invalid: int, lines: int, settings: int, distinct_keys: int, max_settings_per_blob: int, compliant_blobs: int}
     */
    public static function summarizeSettingsBlobBatchNative(array $blobs, array $expected = []): array
    {
        $parts = [(string) \count($expected)];
        foreach ($expected as $setting => $value) {
            if (! \is_scalar($value) && $value !== null) {
                return self::summarizeSettingsBlobBatchPhp($blobs, $expected);
            }
            array_push($parts, (string) $setting, (string) $value);
        }
        foreach ($blobs as $blob) {
            if (\is_string($blob)) {
                array_push($parts, '1', $blob);
            } else {
                $parts[] = '0';
            }
        }
        $fields = self::evaluateW43Native(1, $parts);
        if ($fields === null || \count($fields) !== 7) {
            return self::summarizeSettingsBlobBatchPhp($blobs, $expected);
        }

        return [
            'blobs' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'lines' => (int) $fields[2],
            'settings' => (int) $fields[3],
            'distinct_keys' => (int) $fields[4],
            'max_settings_per_blob' => (int) $fields[5],
            'compliant_blobs' => (int) $fields[6],
        ];
    }

    /**
     * Shared Wave 43.5 bridge for the search and release summaries; null means the library is
     * unavailable or declined the packet, so callers fall back to PHP.
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateW43Native(int $mode, array $parts): ?array
    {
        $ffi = self::w43Ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::W43_NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::W43_NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = 1024;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::W43_NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w435_manticore_release_browse_duplicate_group_mgmt($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackW43(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::W43_NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackW43(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $size = unpack('V', $packet, $offset);
            $size = \is_array($size) ? $size[1] : null;
            $offset += 4;
            if (! \is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function w43Ffi(): ?FFI
    {
        if (self::$w43Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w43Ffi = RustPipelineLibrary::resolveDomain('search_w435', self::W43_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$w43Ffi instanceof FFI ? self::$w43Ffi : null;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('manticore_schema_inspector', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outBuf1024 = $ffi->new('char[1024]');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    private static function typesAreCompatible(string $actual, string $expected): bool
    {
        if ($expected === 'integer') {
            return str_contains($actual, 'integer') || str_contains($actual, 'uint');
        }

        return str_contains($actual, $expected);
    }

    /** @return array<string, string> */
    private static function normalizeSettings(mixed $actual): array
    {
        if (! is_array($actual)) {
            return [];
        }

        $settings = [];
        foreach ($actual as $key => $value) {
            if (is_string($key) && ! is_array($value)) {
                if (strtolower($key) === 'settings' && is_string($value)) {
                    foreach (preg_split('/\R/', $value) ?: [] as $line) {
                        if (preg_match(self::SETTING_LINE, $line, $match) === 1) {
                            $settings[strtolower($match[1])] = strtolower(trim($match[2]));
                        }
                    }

                    continue;
                }
                $settings[strtolower($key)] = strtolower((string) $value);

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $name = $value['Setting_name'] ?? $value['setting_name'] ?? $value['Variable_name'] ?? $value['variable_name'] ?? $value['Name'] ?? $value['name'] ?? null;
            $settingValue = $value['Value'] ?? $value['value'] ?? null;
            if (is_string($name) && $settingValue !== null) {
                $settings[strtolower($name)] = strtolower((string) $settingValue);

                continue;
            }

            if (is_string($key)) {
                $nestedValue = $value['Value'] ?? $value['value'] ?? reset($value);
                if (is_scalar($nestedValue)) {
                    $settings[strtolower($key)] = strtolower((string) $nestedValue);
                }
            }
        }

        return $settings;
    }

    public const string W59_FFI_CDEF = 'int64_t nntmux_w595_schema_describe_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W59.5 parity test's QUALIFICATION). */
    public const bool W59_RUST_FFI_DEFAULT = true;

    /**
     * Wave 59.5 routing and FFI cache, kept in function-local statics (no static properties, so every W59 class
     * follows the same shape). Without $op it reports the routing; 'set' swaps the override for $val and returns the
     * previous one; 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w59_5', self::W59_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W59_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW59RustFfi(): bool
    {
        return self::w59RustFfiState() === true;
    }

    /**
     * Runs $callback with describePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 59.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w59Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w59RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w595_schema_describe_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch `DESCRIBE` inspection over newline-separated lines: an expected `name:type` comma list, then rows
     * `field\ttype[\tproperties]` (empty lines skipped). Seven words per record: `[columns, text_fields, attributes,
     * missing, incompatible, id_present, flags]`. Every accepted row counts as a column; `text` types (ASCII
     * case-insensitive) are text fields and every other row, or one whose properties contain `attribute`, an
     * attribute. Each expected pair (empty pairs skipped) is missing without a row of that field, or incompatible when
     * the last such row's type fails {@see typesAreCompatible()} (both case-folded). Flags: 1 row with one field
     * (skipped), 2 row with an empty field name (skipped), 4 expected pair without `:` (skipped), 8 duplicate field,
     * 16 no columns.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function describePlanBatch(array $records): array
    {
        return self::usesW59RustFfi() ? self::describePlanBatchNative($records) : self::describePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function describePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $lines = explode("\n", $record);
            $spec = array_shift($lines);
            $types = [];
            $columns = $text = $attributes = $id = $flags = 0;
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $parts = explode("\t", $line);
                if (\count($parts) < 2) {
                    $flags |= 1;

                    continue;
                }
                $field = $parts[0];
                if ($field === '') {
                    $flags |= 2;

                    continue;
                }
                $type = strtolower($parts[1]);
                if (isset($types[$field])) {
                    $flags |= 8;
                }
                $types[$field] = $type;
                $columns++;
                if ($type === 'text') {
                    $text++;
                }
                if ($type !== 'text' || stripos($parts[2] ?? '', 'attribute') !== false) {
                    $attributes++;
                }
                if ($field === 'id') {
                    $id = 1;
                }
            }
            if ($columns === 0) {
                $flags |= 16;
            }
            $missing = $incompatible = 0;
            if ($spec !== '') {
                foreach (explode(',', $spec) as $pair) {
                    if ($pair === '') {
                        continue;
                    }
                    $colon = strpos($pair, ':');
                    if ($colon === false) {
                        $flags |= 4;

                        continue;
                    }
                    $name = substr($pair, 0, $colon);
                    if (! isset($types[$name])) {
                        $missing++;
                    } elseif (! self::typesAreCompatible($types[$name], strtolower(substr($pair, $colon + 1)))) {
                        $incompatible++;
                    }
                }
            }
            array_push($out, $columns, $text, $attributes, $missing, $incompatible, $id, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function describePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w59Words($count, implode("\0", $records), 7 * $count) ?? self::describePlanBatchPhp($records);
    }
}
