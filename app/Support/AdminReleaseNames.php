<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Illuminate\Support\HtmlString;
use JsonException;
use Throwable;

/** Presentation only. Laravel owns release selection, routes and authorization. */
final class AdminReleaseNames
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w381_obfuscation_gate_router_admin_names(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustObfuscationGateRouterAdminNamesParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private const int RUST_MAX_INPUT = 16_777_216;

    /**
     * Wave 46.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest.php measures
     * 5.24-5.61x mean / 4.66-5.96x p99 on the common 32-row batch and 8.60-9.03x mean /
     * 5.62-6.85x p99 on the uncommon 2000-row batch. PHP decides batches Rust declines.
     * Named W463 so it stays distinct from the class's existing Rust paths.
     */
    public const bool W463_RUST_FFI_DEFAULT = true;

    public const string W463_FFI_CDEF = <<<'C'
        ssize_t nntmux_w463_admin_name_display_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W463_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w463RustFfiOverride = null;

    private static FFI|false|null $w463RustFfiHandle = null;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /** RFC 3986 unreserved bytes: GUIDs made only of these are plain URL path segments. */
    private const string UNRESERVED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

    // Per-request native binding costs more than PHP for tiny result batches.
    public const int MIN_NATIVE_ROWS = 16;

    /**
     * @param  iterable<object>  $releases
     * @return list<HtmlString>
     */
    public function render(iterable $releases, bool $native = true): array
    {
        $rows = [];
        foreach ($releases as $release) {
            $rows[] = [
                'searchname' => $release->searchname,
                'name' => $release->name,
                'guid' => $release->guid,
            ];
        }
        if ($rows === []) {
            return [];
        }

        // Only the dependency-free, preloaded wrapper may access the native scope.
        if (count($rows) >= self::MIN_NATIVE_ROWS && $native && function_exists('nntmux_render_release_names')) {
            try {
                // Laravel resolves host, deployment prefix and route defaults once.
                // Rust only expands unreserved GUID segments; unusual GUIDs fall back.
                $marker = '__NNTMUX_RELEASE_GUID__';
                $template = route('details', ['guid' => $marker]);
                if (substr_count($template, $marker) !== 1) {
                    throw new JsonException('Ambiguous release URL template.');
                }
                [$prefix, $suffix] = explode($marker, $template, 2);
                $input = json_encode(['url_prefix' => $prefix, 'url_suffix' => $suffix, 'releases' => $rows], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $html = \nntmux_render_release_names($input);
                if (is_array($html) && array_is_list($html) && count($html) === count($rows)
                    && count(array_filter($html, is_string(...))) === count($rows)) {
                    return array_map(static fn (string $item): HtmlString => new HtmlString($item), $html);
                }
            } catch (JsonException) {
                // Invalid native output or unavailable scope must never break navigation.
            }
        }

        return array_map(static function (array $row): HtmlString {
            $display = e(ReleaseNameDisplay::clean($row['searchname']));
            $original = e(ReleaseNameDisplay::clean($row['name']));
            $html = '<a href="'.e(route('details', ['guid' => $row['guid']])).'" class="text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline" title="'.$display.'">'.$display.'</a>';
            if ($original !== '' && $original !== $display) {
                $html .= '<div class="text-gray-500 dark:text-gray-400 text-xs mt-1 max-w-md truncate" title="'.$original.'">'.$original.'</div>';
            }

            return new HtmlString($html);
        }, $rows);
    }

    /**
     * Summarizes a batch of `searchname`/`name`/`guid` rows before rendering: whether it reaches the
     * native renderer's MIN_NATIVE_ROWS, how many GUIDs are plain RFC 3986 unreserved segments, how
     * many rows hold a non-string field, how many rows repeat the search name as the original name
     * and the total name bytes to clean.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, native_eligible: bool, unreserved_guids: int, invalid_rows: int, identical_names: int, name_bytes: int}
     */
    public static function summarizeRenderBatch(array $rows, bool $native = true): array
    {
        return (self::usesRustFfi() ? self::summarizeRenderBatchNative($rows, $native) : null) ?? self::summarizeRenderBatchPhp($rows, $native);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, native_eligible: bool, unreserved_guids: int, invalid_rows: int, identical_names: int, name_bytes: int}
     */
    private static function summarizeRenderBatchPhp(array $rows, bool $native): array
    {
        $unreserved = $invalid = $identical = $bytes = 0;
        foreach ($rows as $row) {
            [$searchname, $name, $guid] = self::renderBatchFields($row);
            if (is_string($guid) && $guid !== '' && strspn($guid, self::UNRESERVED) === strlen($guid)) {
                $unreserved++;
            }
            if (! is_string($searchname) || ! is_string($name) || ! is_string($guid)) {
                $invalid++;
            }
            if (is_string($searchname) && $searchname === $name) {
                $identical++;
            }
            $bytes += (is_string($searchname) ? strlen($searchname) : 0) + (is_string($name) ? strlen($name) : 0);
        }

        return ['rows' => count($rows), 'native_eligible' => $native && count($rows) >= self::MIN_NATIVE_ROWS, 'unreserved_guids' => $unreserved,
            'invalid_rows' => $invalid, 'identical_names' => $identical, 'name_bytes' => $bytes];
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, native_eligible: bool, unreserved_guids: int, invalid_rows: int, identical_names: int, name_bytes: int}|null
     */
    private static function summarizeRenderBatchNative(array $rows, bool $native): ?array
    {
        $input = pack('q', count($rows));
        foreach ($rows as $row) {
            foreach (self::renderBatchFields($row) as $field) {
                $input .= is_string($field) ? self::packRustString($field) : pack('q', -1);
            }
        }
        $fields = self::unpackRustInts(self::callRustFfi(3, $native ? 1 : 0, $input), 6);
        if ($fields === null || $fields[1] & ~1) {
            return null;
        }

        return ['rows' => $fields[0], 'native_eligible' => $fields[1] === 1, 'unreserved_guids' => $fields[2], 'invalid_rows' => $fields[3],
            'identical_names' => $fields[4], 'name_bytes' => $fields[5]];
    }

    /**
     * @return array{mixed, mixed, mixed}
     */
    private static function renderBatchFields(mixed $row): array
    {
        return is_array($row) ? [$row['searchname'] ?? null, $row['name'] ?? null, $row['guid'] ?? null] : [null, null, null];
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    private static function packRustString(string $value): string
    {
        return pack('q', strlen($value)).$value;
    }

    /**
     * @return list<int>|null
     */
    private static function unpackRustInts(?string $bytes, int $count): ?array
    {
        if ($bytes === null || strlen($bytes) !== $count * 8) {
            return null;
        }
        $values = unpack('q*', $bytes);

        return $values === false ? null : array_values($values);
    }

    /**
     * Calls the Wave 38.1 export, retrying once with a larger scratch buffer when it reports `-required`.
     */
    private static function callRustFfi(int $operation, int $flags, string $input): ?string
    {
        if (strlen($input) > self::RUST_MAX_INPUT) {
            return null;
        }
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w381', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w381_obfuscation_gate_router_admin_names($operation, $flags, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Enables (true) or disables (false) the Wave 46.3 Rust helper; null restores
     * W463_RUST_FFI_DEFAULT.
     */
    public static function useW463RustFfi(?bool $enable = true): void
    {
        self::$w463RustFfiOverride = $enable;
    }

    public static function usesW463RustFfi(): bool
    {
        return self::$w463RustFfiOverride ?? self::W463_RUST_FFI_DEFAULT;
    }

    public static function resetW463RustFfi(): void
    {
        self::$w463RustFfiOverride = null;
        self::$w463RustFfiHandle = null;
    }

    /**
     * Name display summary for a batch of `searchname`/`name` rows as render() presents them:
     * rows whose search name or original name ReleaseNameDisplay::cleanPhp() changes, rows whose
     * cleaned search name is empty, rows that render the secondary original-name line, and rows
     * whose cleaned search name needs HTML escaping. Missing or non-string fields count as empty.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, display_cleaned: int, original_cleaned: int, empty_display: int, secondary: int, escaped: int}
     */
    public static function summarizeNameDisplayBatch(array $rows): array
    {
        return (self::usesW463RustFfi() ? self::summarizeNameDisplayBatchNative($rows) : null)
            ?? self::summarizeNameDisplayBatchPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, display_cleaned: int, original_cleaned: int, empty_display: int, secondary: int, escaped: int}
     */
    public static function summarizeNameDisplayBatchPhp(array $rows): array
    {
        $displayCleaned = $originalCleaned = $emptyDisplay = $secondary = $escaped = 0;
        foreach ($rows as $row) {
            [$searchname, $name] = self::renderBatchFields($row);
            $searchname = \is_string($searchname) ? $searchname : '';
            $name = \is_string($name) ? $name : '';
            $display = ReleaseNameDisplay::cleanPhp($searchname);
            $original = ReleaseNameDisplay::cleanPhp($name);
            $escapedDisplay = e($display);
            $displayCleaned += (int) ($display !== $searchname);
            $originalCleaned += (int) ($original !== $name);
            $emptyDisplay += (int) ($display === '');
            $secondary += (int) ($original !== '' && e($original) !== $escapedDisplay);
            $escaped += (int) ($escapedDisplay !== $display);
        }

        return [
            'rows' => \count($rows),
            'display_cleaned' => $displayCleaned,
            'original_cleaned' => $originalCleaned,
            'empty_display' => $emptyDisplay,
            'secondary' => $secondary,
            'escaped' => $escaped,
        ];
    }

    /**
     * Sends each row as a search name/name pair. Declines (null) when PCRE limits differ from their
     * defaults and when the library is unavailable; Rust also declines whole batches holding a
     * non-ASCII or 4096+ byte name, so escaping substitutions and PCRE locale tables stay on PHP.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, display_cleaned: int, original_cleaned: int, empty_display: int, secondary: int, escaped: int}|null
     */
    public static function summarizeNameDisplayBatchNative(array $rows): ?array
    {
        if (! self::w463PcreAtDefaults()) {
            return null;
        }
        $parts = [];
        $lengths = [];
        foreach ($rows as $row) {
            $searchname = \is_array($row) ? ($row['searchname'] ?? '') : '';
            $name = \is_array($row) ? ($row['name'] ?? '') : '';
            $searchname = \is_string($searchname) ? $searchname : '';
            $name = \is_string($name) ? $name : '';
            $parts[] = $searchname;
            $parts[] = $name;
            $lengths[] = \strlen($searchname);
            $lengths[] = \strlen($name);
        }
        $fields = self::callW463Native(implode('', $parts), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'rows' => (int) $fields[0],
            'display_cleaned' => (int) $fields[1],
            'original_cleaned' => (int) $fields[2],
            'empty_display' => (int) $fields[3],
            'secondary' => (int) $fields[4],
            'escaped' => (int) $fields[5],
        ];
    }

    /**
     * Whether PCRE runs with the limits Rust mirrors; restricted resources keep PHP's failure
     * behavior.
     */
    private static function w463PcreAtDefaults(): bool
    {
        return (int) ini_get('pcre.backtrack_limit') >= 1000000
            && (int) ini_get('pcre.recursion_limit') >= 100000
            && ini_get('pcre.jit') === '1';
    }

    /**
     * Calls the Wave 46.3 export with the shared scratch buffer, retrying once at the size it asks
     * for. Returns its fields only when they match W463_FIELDS_PATTERN.
     *
     * @return list<string>|null
     */
    private static function callW463Native(string $blob, string $lens): ?array
    {
        $ffi = self::w463RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 192, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w463_admin_name_display_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w463_admin_name_display_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W463_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w463RustFfi(): ?FFI
    {
        if (self::$w463RustFfiHandle === null) {
            self::$w463RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w463', self::W463_FFI_CDEF) ?? false;
        }

        return self::$w463RustFfiHandle === false ? null : self::$w463RustFfiHandle;
    }
}
