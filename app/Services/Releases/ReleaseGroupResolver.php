<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\UsenetGroup;
use App\Services\Search\Support\ManticoreSchemaInspector;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class ReleaseGroupResolver
{
    /**
     * Rust stays opt-in for the Wave 43.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustManticoreSchemaReleaseBrowseDuplicateGroupMgmtW43ParityTest.php.
     */
    public const bool W43_RELEASE_GROUP_RUST_FFI_DEFAULT = false;

    private static ?bool $useW43RustFfi = null;

    /**
     * Resolve valid crosspost names, retaining the inactive defaults used by release processing.
     *
     * @param  list<string>  $names
     * @return array<string, int> IDs keyed by the original name, including normalized aliases.
     */
    public function idsByName(array $names): array
    {
        $normalized = [];
        foreach (array_unique($names) as $name) {
            $valid = UsenetGroup::isValidGroup($name);
            if (is_string($valid) && (self::$useRustFfi || strlen($valid) <= 255)) {
                $normalized[$name] = $valid;
            }
        }
        if (self::$useRustFfi) {
            $accepted = array_fill_keys(self::filterValidNames(array_values($normalized)), true);
            $normalized = array_filter($normalized, static fn (string $name): bool => isset($accepted[$name]));
        }
        $ids = UsenetGroup::query()->whereIn('name', array_values(array_unique($normalized)))
            ->pluck('id', 'name')->map(static fn ($id): int => (int) $id)->all();
        foreach (array_unique($normalized) as $name) {
            if (isset($ids[$name])) {
                continue;
            }
            $ids[$name] = (int) UsenetGroup::addGroup(self::inactiveAttributes($name));
        }
        $resolved = [];
        foreach ($normalized as $original => $name) {
            $resolved[$original] = $ids[$name];
        }

        return $resolved;
    }

    /** @param list<string> $names
     * @return list<string>
     */
    public static function filterValidNames(array $names): array
    {
        return (self::$useRustFfi ? self::filterValidNamesNative($names) : null)
            ?? array_values(array_unique(array_filter($names, static fn (string $name): bool => strlen($name) <= 255)));
    }

    /** @param list<string> $names
     * @return list<string>|null
     */
    public static function filterValidNamesNative(array $names): ?array
    {
        $input = self::packFrames($names);

        return $input === null ? null : self::unpackFrames(self::nativeCall(0, $input));
    }

    /** @return array<string, int|string> */
    public static function inactiveAttributes(string $name): array
    {
        return (self::$useRustFfi ? self::inactiveAttributesNative($name) : null) ?? [
            'name' => $name,
            'description' => 'Added by Release processing',
            'backfill_target' => 1,
            'first_record' => 0,
            'last_record' => 0,
            'active' => 0,
            'backfill' => 0,
            'minfilestoformrelease' => '',
            'minsizetoformrelease' => '',
        ];
    }

    /** @return array<string, int|string>|null */
    public static function inactiveAttributesNative(string $name): ?array
    {
        $fields = self::unpackFrames(self::nativeCall(1, $name));
        if ($fields === null || count($fields) !== 9) {
            return null;
        }

        return [
            'name' => $fields[0], 'description' => $fields[1], 'backfill_target' => (int) $fields[2],
            'first_record' => (int) $fields[3], 'last_record' => (int) $fields[4],
            'active' => (int) $fields[5], 'backfill' => (int) $fields[6],
            'minfilestoformrelease' => $fields[7], 'minsizetoformrelease' => $fields[8],
        ];
    }

    /** Pass null to restore {@see W43_RELEASE_GROUP_RUST_FFI_DEFAULT}. */
    public static function useW43RustFfi(?bool $enable = true): void
    {
        self::$useW43RustFfi = $enable;
    }

    public static function usesW43RustFfi(): bool
    {
        return self::$useW43RustFfi ?? self::W43_RELEASE_GROUP_RUST_FFI_DEFAULT;
    }

    /**
     * Summarizes crosspost names through the pure steps of {@see idsByName()}: exact duplicates are
     * dropped, {@see UsenetGroup::isValidGroupPhp()} rejects or normalizes (`aliased` counts expanded
     * `a.b.` prefixes) and names over 255 bytes are too long. Non-string names are invalid.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{names: int, invalid: int, duplicates: int, valid: int, rejected: int, too_long: int, aliased: int, distinct_groups: int, max_group_length: int}
     */
    public static function summarizeCrosspostNameBatch(array $names): array
    {
        return self::usesW43RustFfi() ? self::summarizeCrosspostNameBatchNative($names) : self::summarizeCrosspostNameBatchPhp($names);
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{names: int, invalid: int, duplicates: int, valid: int, rejected: int, too_long: int, aliased: int, distinct_groups: int, max_group_length: int}
     */
    public static function summarizeCrosspostNameBatchPhp(array $names): array
    {
        $summary = ['names' => \count($names), 'invalid' => 0, 'duplicates' => 0, 'valid' => 0, 'rejected' => 0, 'too_long' => 0, 'aliased' => 0, 'distinct_groups' => 0, 'max_group_length' => 0];
        $seen = [];
        $groups = [];
        foreach ($names as $name) {
            if (! \is_string($name)) {
                $summary['invalid']++;

                continue;
            }
            if (isset($seen[$name])) {
                $summary['duplicates']++;

                continue;
            }
            $seen[$name] = true;
            $group = UsenetGroup::isValidGroupPhp($name);
            if (! \is_string($group)) {
                $summary['rejected']++;

                continue;
            }
            if (\strlen($group) > 255) {
                $summary['too_long']++;

                continue;
            }
            $summary['valid']++;
            $summary['aliased'] += (int) ($group !== $name);
            $summary['max_group_length'] = max($summary['max_group_length'], \strlen($group));
            $groups[$group] = true;
        }
        $summary['distinct_groups'] = \count($groups);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{names: int, invalid: int, duplicates: int, valid: int, rejected: int, too_long: int, aliased: int, distinct_groups: int, max_group_length: int}
     */
    public static function summarizeCrosspostNameBatchNative(array $names): array
    {
        $parts = [];
        foreach ($names as $name) {
            if (\is_string($name)) {
                array_push($parts, '1', $name);
            } else {
                $parts[] = '0';
            }
        }
        $fields = ManticoreSchemaInspector::evaluateW43Native(4, $parts);
        if ($fields === null || \count($fields) !== 9) {
            return self::summarizeCrosspostNameBatchPhp($names);
        }

        return [
            'names' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'duplicates' => (int) $fields[2],
            'valid' => (int) $fields[3],
            'rejected' => (int) $fields[4],
            'too_long' => (int) $fields[5],
            'aliased' => (int) $fields[6],
            'distinct_groups' => (int) $fields[7],
            'max_group_length' => (int) $fields[8],
        ];
    }

    private static function nativeCall(int $mode, string $input): ?string
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave20-group', 'intptr_t nntmux_wave20_group(uint32_t mode, const char *input, size_t input_len, char *out, size_t out_cap);');
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 2 + 256), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_wave20_group($mode, $input, strlen($input), $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $values */
    private static function packFrames(array $values): ?string
    {
        $input = '';
        foreach ($values as $value) {
            if (strlen($value) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($value)).$value;
        }

        return $input;
    }

    /** @return list<string>|null */
    private static function unpackFrames(?string $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $values = [];
        for ($offset = 0, $length = strlen($input); $offset < $length;) {
            if ($length - $offset < 4) {
                return null;
            }
            $header = unpack('Vlength', substr($input, $offset, 4));
            if ($header === false || ! is_int($header['length']) || $header['length'] > $length - $offset - 4) {
                return null;
            }
            $offset += 4;
            $values[] = substr($input, $offset, $header['length']);
            $offset += $header['length'];
        }

        return $values;
    }

    // Single-call FFI must qualify on all four benchmark metrics before adoption.
    private static bool $useRustFfi = false;

    public static function useRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Wave 55.3 paired benchmarks decide this default; see RustReleaseImageBrowseDuplicateGroupManagementW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        ssize_t nntmux_w553_release_group_name_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    public static function usesW55RustFfi(): bool
    {
        $override = self::w55RustFfiState();

        return \is_bool($override) ? $override : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 55 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 55.3 crosspost group name plans. Per name `flags | segments << 8 | length << 16`, with flags
     * 1 rejected by UsenetGroup::isValidGroupPhp(), 2 `a.b.` alias expanded, 4 normalized name over 255
     * bytes and 8 exact repeat of an earlier name, `segments` the dot-separated parts (at most 255) and
     * `length` the normalized byte length (both 0 when rejected); then the four flag counts and the
     * distinct normalized groups that are valid and not too long.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w55GroupNamePlanBatch(array $names): array
    {
        return self::usesW55RustFfi() ? self::w55GroupNamePlanBatchNative($names) : self::w55GroupNamePlanBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w55GroupNamePlanBatchNative(array $names): array
    {
        return self::w55Invoke($names, \count($names) + 5) ?? self::w55GroupNamePlanBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w55GroupNamePlanBatchPhp(array $names): array
    {
        $out = [];
        $totals = [0, 0, 0, 0];
        $seen = [];
        $groups = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $word = isset($seen[$name]) ? 8 : 0;
            $seen[$name] = true;
            // Same pattern and alias rewrite as UsenetGroup::isValidGroupPhp().
            if (preg_match('/^([\w\-]+\.)+[\w\-]+$/i', $name) !== 1) {
                $word |= 1;
            } else {
                $group = (string) preg_replace('/^a\.b\./i', 'alt.binaries.', $name, 1);
                $length = \strlen($group);
                $word |= ($group !== $name ? 2 : 0) | ($length > 255 ? 4 : 0) | (min(0xFF, substr_count($name, '.') + 1) << 8) | ($length << 16);
                if ($length <= 255) {
                    $groups[$group] = true;
                }
            }
            $totals[0] += $word & 1;
            $totals[1] += ($word >> 1) & 1;
            $totals[2] += ($word >> 2) & 1;
            $totals[3] += ($word >> 3) & 1;
            $out[] = $word;
        }
        array_push($out, ...$totals);
        $out[] = \count($groups);

        return $out;
    }

    /**
     * Wave 55 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('release_w55', self::W55_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w55RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w55RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 55.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w55Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w55RustFfiState('ffi');
            $out = self::w55RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w55RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W55_FFI_CDEF.)
            $written = $ffi->nntmux_w553_release_group_name_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
