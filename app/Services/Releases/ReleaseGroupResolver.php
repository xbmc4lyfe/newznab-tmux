<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\UsenetGroup;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class ReleaseGroupResolver
{
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
}
