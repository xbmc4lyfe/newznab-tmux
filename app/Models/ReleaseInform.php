<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ReleaseInform extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_inform_normalize_record(
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

    /** `relOName`/`relPName` are varchar(255) utf8mb4 columns. */
    private const int NAME_MAX_CHARS = 255;

    private const string TOKEN_PATTERN = '/\A[A-Za-z0-9]{1,255}\z/';

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
     * Normalizes an inform row: values are trimmed, names must be non-empty UTF-8 that fits the
     * 255-character columns, the token must be ASCII alphanumeric, and a valid record renames
     * the release to a different name.
     *
     * @return array{relOName: string, relPName: string, api_token: string, names_fit: bool, token_valid: bool, is_rename: bool, is_valid: bool}
     */
    public static function normalizeInformRecord(string $relOName, string $relPName, string $apiToken): array
    {
        return self::usesRustFfi()
            ? self::normalizeInformRecordNative($relOName, $relPName, $apiToken)
            : self::normalizeInformRecordPhp($relOName, $relPName, $apiToken);
    }

    /**
     * @return array{relOName: string, relPName: string, api_token: string, names_fit: bool, token_valid: bool, is_rename: bool, is_valid: bool}
     */
    public static function normalizeInformRecordNative(string $relOName, string $relPName, string $apiToken): array
    {
        $fields = self::callNative('nntmux_release_inform_normalize_record', $relOName.self::FIELD_SEPARATOR.$relPName.self::FIELD_SEPARATOR.$apiToken);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizeInformRecordPhp($relOName, $relPName, $apiToken);
        }

        return [
            'relOName' => $fields[0],
            'relPName' => $fields[1],
            'api_token' => $fields[2],
            'names_fit' => $fields[3] === '1',
            'token_valid' => $fields[4] === '1',
            'is_rename' => $fields[5] === '1',
            'is_valid' => $fields[6] === '1',
        ];
    }

    /**
     * @return array{relOName: string, relPName: string, api_token: string, names_fit: bool, token_valid: bool, is_rename: bool, is_valid: bool}
     */
    public static function normalizeInformRecordPhp(string $relOName, string $relPName, string $apiToken): array
    {
        $relO = trim($relOName, self::TRIM_CHARS);
        $relP = trim($relPName, self::TRIM_CHARS);
        $token = trim($apiToken, self::TRIM_CHARS);
        $fits = static fn (string $name): bool => $name !== ''
            && mb_check_encoding($name, 'UTF-8')
            && mb_strlen($name, 'UTF-8') <= self::NAME_MAX_CHARS;
        $namesFit = $fits($relO) && $fits($relP);
        $tokenValid = preg_match(self::TOKEN_PATTERN, $token) === 1;
        $isRename = $relO !== $relP;

        return [
            'relOName' => $relO,
            'relPName' => $relP,
            'api_token' => $token,
            'names_fit' => $namesFit,
            'token_valid' => $tokenValid,
            'is_rename' => $isRename,
            'is_valid' => $namesFit && $tokenValid && $isRename,
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function callNative(string $symbol, string $input): ?array
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
            $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
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
