<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ReleaseNameFixed
{
    use Dispatchable;
    use SerializesModels;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_name_fixed_summarize_delta(
            uint32_t flags,
            int64_t release_id,
            int64_t old_category_id,
            int64_t group_id,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustCategorizationProvidersEventsInformParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    private const string INT_MAX_DIGITS = '9223372036854775807';

    private const int GROUP_IS_STRING = 1;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /** @param array{id:int,guid:string,name:?string,searchname:?string}|null $expectedSnapshot */
    public function __construct(
        public readonly int $releaseId,
        public readonly string $oldName,
        public readonly string $newName,
        public readonly int $oldCategoryId,
        public readonly int|string $groupId,
        public readonly string $poster = '',
        public readonly ?array $expectedSnapshot = null,
    ) {}

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Summarizes a rename: names are trimmed, the group id is normalized like the categorization
     * facade, and the delta reports whether (and how) the name changed. A summary is valid for a
     * positive release id with a non-empty, changed new name.
     *
     * @return array{release_id: int, old_name: string, new_name: string, old_category_id: int, group_id: int, changed: bool, case_only: bool, length_delta: int, common_prefix_length: int, is_valid: bool}
     */
    public static function summarizeRenameDelta(int $releaseId, string $oldName, string $newName, int $oldCategoryId, int|string $groupId): array
    {
        return self::usesRustFfi()
            ? self::summarizeRenameDeltaNative($releaseId, $oldName, $newName, $oldCategoryId, $groupId)
            : self::summarizeRenameDeltaPhp($releaseId, $oldName, $newName, $oldCategoryId, $groupId);
    }

    /**
     * @return array{release_id: int, old_name: string, new_name: string, old_category_id: int, group_id: int, changed: bool, case_only: bool, length_delta: int, common_prefix_length: int, is_valid: bool}
     */
    public static function summarizeRenameDeltaNative(int $releaseId, string $oldName, string $newName, int $oldCategoryId, int|string $groupId): array
    {
        $input = (is_string($groupId) ? $groupId : '').self::FIELD_SEPARATOR.$oldName.self::FIELD_SEPARATOR.$newName;
        $fields = self::callNative(
            'nntmux_release_name_fixed_summarize_delta',
            is_string($groupId) ? self::GROUP_IS_STRING : 0,
            [$releaseId, $oldCategoryId, is_int($groupId) ? $groupId : 0],
            $input,
        );
        if ($fields === null || count($fields) !== 10 || ! is_numeric($fields[0]) || ! is_numeric($fields[3])
            || ! ctype_digit($fields[4]) || ! is_numeric($fields[7]) || ! ctype_digit($fields[8])) {
            return self::summarizeRenameDeltaPhp($releaseId, $oldName, $newName, $oldCategoryId, $groupId);
        }

        return [
            'release_id' => (int) $fields[0],
            'old_name' => $fields[1],
            'new_name' => $fields[2],
            'old_category_id' => (int) $fields[3],
            'group_id' => (int) $fields[4],
            'changed' => $fields[5] === '1',
            'case_only' => $fields[6] === '1',
            'length_delta' => (int) $fields[7],
            'common_prefix_length' => (int) $fields[8],
            'is_valid' => $fields[9] === '1',
        ];
    }

    /**
     * @return array{release_id: int, old_name: string, new_name: string, old_category_id: int, group_id: int, changed: bool, case_only: bool, length_delta: int, common_prefix_length: int, is_valid: bool}
     */
    public static function summarizeRenameDeltaPhp(int $releaseId, string $oldName, string $newName, int $oldCategoryId, int|string $groupId): array
    {
        $old = trim($oldName, self::TRIM_CHARS);
        $new = trim($newName, self::TRIM_CHARS);
        $changed = $old !== $new;

        return [
            'release_id' => $releaseId,
            'old_name' => $old,
            'new_name' => $new,
            'old_category_id' => $oldCategoryId,
            'group_id' => self::normalizeGroupId($groupId),
            'changed' => $changed,
            'case_only' => $changed && strtolower($old) === strtolower($new),
            'length_delta' => strlen($new) - strlen($old),
            'common_prefix_length' => strspn($old ^ $new, "\0"),
            'is_valid' => $releaseId > 0 && $new !== '' && $changed,
        ];
    }

    private static function normalizeGroupId(int|string $groupId): int
    {
        if (is_int($groupId)) {
            return max(0, $groupId);
        }
        $value = trim($groupId, self::TRIM_CHARS);
        if ($value === '' || ! ctype_digit($value)) {
            return 0;
        }
        $digits = ltrim($value, '0');
        $length = strlen($digits);
        if ($length === 0 || $length > 19 || ($length === 19 && strcmp($digits, self::INT_MAX_DIGITS) > 0)) {
            return 0;
        }

        return (int) $digits;
    }

    /**
     * @param  array{int, int, int}  $ints
     * @return list<string>|null
     */
    private static function callNative(string $symbol, int $flags, array $ints, string $input): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol($flags, ...[...$ints, $input, strlen($input), $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($flags, ...[...$ints, $input, strlen($input), $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
