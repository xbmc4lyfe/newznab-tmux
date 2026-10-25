<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\Categorization\CategorizationPipeline;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static array categorize(int|string $groupId, string $releaseName, ?string $poster = '', bool $debug = false)
 * @method static \Illuminate\Support\Collection getCategorizers()
 * @method static CategorizationPipeline addCategorizer(\App\Services\Categorization\Contracts\CategorizerInterface $categorizer)
 *
 * @see CategorizationPipeline
 */
class Categorization extends Facade // @phpstan-ignore missingType.iterableValue, missingType.generics
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_categorization_normalize_call_args(
            uint32_t flags,
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

    private const int HAS_POSTER = 2;

    private const int DEBUG = 4;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes `categorize()` arguments: the group id becomes a non-negative int (a padded
     * all-digit string that fits PHP_INT_MAX parses, anything else is 0), and the release name
     * and poster are trimmed (a null poster is empty).
     *
     * @return array{group_id: int, release_name: string, poster: string, debug: bool, has_group: bool, has_poster: bool, name_length: int, is_valid: bool}
     */
    public static function normalizeCategorizeCallArgs(int|string $groupId, string $releaseName, ?string $poster = '', bool $debug = false): array
    {
        return self::usesRustFfi()
            ? self::normalizeCategorizeCallArgsNative($groupId, $releaseName, $poster, $debug)
            : self::normalizeCategorizeCallArgsPhp($groupId, $releaseName, $poster, $debug);
    }

    /**
     * @return array{group_id: int, release_name: string, poster: string, debug: bool, has_group: bool, has_poster: bool, name_length: int, is_valid: bool}
     */
    public static function normalizeCategorizeCallArgsNative(int|string $groupId, string $releaseName, ?string $poster = '', bool $debug = false): array
    {
        $flags = (is_string($groupId) ? self::GROUP_IS_STRING : 0) | ($poster !== null ? self::HAS_POSTER : 0) | ($debug ? self::DEBUG : 0);
        $input = (is_string($groupId) ? $groupId : '').self::FIELD_SEPARATOR.$releaseName.self::FIELD_SEPARATOR.($poster ?? '');
        $fields = self::callNative('nntmux_categorization_normalize_call_args', $flags, is_int($groupId) ? $groupId : 0, $input);
        if ($fields === null || count($fields) !== 8 || ! ctype_digit($fields[0]) || ! ctype_digit($fields[6])) {
            return self::normalizeCategorizeCallArgsPhp($groupId, $releaseName, $poster, $debug);
        }

        return [
            'group_id' => (int) $fields[0],
            'release_name' => $fields[1],
            'poster' => $fields[2],
            'debug' => $fields[3] === '1',
            'has_group' => $fields[4] === '1',
            'has_poster' => $fields[5] === '1',
            'name_length' => (int) $fields[6],
            'is_valid' => $fields[7] === '1',
        ];
    }

    /**
     * @return array{group_id: int, release_name: string, poster: string, debug: bool, has_group: bool, has_poster: bool, name_length: int, is_valid: bool}
     */
    public static function normalizeCategorizeCallArgsPhp(int|string $groupId, string $releaseName, ?string $poster = '', bool $debug = false): array
    {
        $group = self::normalizeGroupId($groupId);
        $name = trim($releaseName, self::TRIM_CHARS);
        $poster = trim($poster ?? '', self::TRIM_CHARS);

        return [
            'group_id' => $group,
            'release_name' => $name,
            'poster' => $poster,
            'debug' => $debug,
            'has_group' => $group > 0,
            'has_poster' => $poster !== '',
            'name_length' => strlen($name),
            'is_valid' => $name !== '',
        ];
    }

    protected static function getFacadeAccessor(): string
    {
        return CategorizationPipeline::class;
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
     * @return list<string>|null
     */
    private static function callNative(string $symbol, int $flags, int $groupId, string $input): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 64, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol($flags, $groupId, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($flags, $groupId, $input, strlen($input), $buffer, $capacity);
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
