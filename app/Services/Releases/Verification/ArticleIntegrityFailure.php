<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

final class ArticleIntegrityFailure extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_article_integrity_classify(
            uint32_t flags,
            const char *id_ptr,
            size_t id_len,
            int64_t expected_crc,
            int64_t actual_crc,
            int64_t decoded_bytes,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationBudgetIntegrityExceptionsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    /** Bytes a valid message id may not contain besides its single `@`. */
    private const string MESSAGE_ID_FORBIDDEN = " \t\n\r\x0B\x0C\0<>";

    private const int CRC_MAX = 0xFFFFFFFF;

    private const int HAS_MESSAGE_ID = 1;

    private const array KINDS = ['empty_payload', 'invalid_crc', 'crc_mismatch', 'none'];

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
     * Classifies a decoded-article integrity check. CRCs are compared as unsigned 32-bit values;
     * an out-of-range CRC or an empty payload is reported before a mismatch. The message id is
     * trimmed and one surrounding `<...>` pair removed (empty becomes null).
     *
     * @return array{message_id: ?string, has_message_id: bool, message_id_valid: bool, expected_crc: int, actual_crc: int, expected_hex: string, actual_hex: string, crc_in_range: bool, crc_match: bool, decoded_bytes: int, kind: string, is_failure: bool, retryable: bool}
     */
    public static function classifyIntegrityFailure(?string $messageId, int $expectedCrc, int $actualCrc, int $decodedBytes): array
    {
        return self::usesRustFfi()
            ? self::classifyIntegrityFailureNative($messageId, $expectedCrc, $actualCrc, $decodedBytes)
            : self::classifyIntegrityFailurePhp($messageId, $expectedCrc, $actualCrc, $decodedBytes);
    }

    /**
     * @return array{message_id: ?string, has_message_id: bool, message_id_valid: bool, expected_crc: int, actual_crc: int, expected_hex: string, actual_hex: string, crc_in_range: bool, crc_match: bool, decoded_bytes: int, kind: string, is_failure: bool, retryable: bool}
     */
    public static function classifyIntegrityFailureNative(?string $messageId, int $expectedCrc, int $actualCrc, int $decodedBytes): array
    {
        $id = $messageId ?? '';
        $fields = self::callNative(
            'nntmux_article_integrity_classify',
            [$messageId === null ? 0 : self::HAS_MESSAGE_ID, $id, strlen($id), $expectedCrc, $actualCrc, $decodedBytes],
            strlen($id) + 256,
            13,
        );
        if ($fields === null || count($fields) !== 13 || ! ctype_digit($fields[0]) || ! ctype_digit($fields[1])
            || ! ctype_digit($fields[6]) || ! in_array($fields[7], self::KINDS, true)) {
            return self::classifyIntegrityFailurePhp($messageId, $expectedCrc, $actualCrc, $decodedBytes);
        }
        $hasId = $fields[10] === '1';

        return [
            'message_id' => $hasId ? $fields[12] : null,
            'has_message_id' => $hasId,
            'message_id_valid' => $fields[11] === '1',
            'expected_crc' => (int) $fields[0],
            'actual_crc' => (int) $fields[1],
            'expected_hex' => $fields[2],
            'actual_hex' => $fields[3],
            'crc_in_range' => $fields[4] === '1',
            'crc_match' => $fields[5] === '1',
            'decoded_bytes' => (int) $fields[6],
            'kind' => $fields[7],
            'is_failure' => $fields[8] === '1',
            'retryable' => $fields[9] === '1',
        ];
    }

    /**
     * @return array{message_id: ?string, has_message_id: bool, message_id_valid: bool, expected_crc: int, actual_crc: int, expected_hex: string, actual_hex: string, crc_in_range: bool, crc_match: bool, decoded_bytes: int, kind: string, is_failure: bool, retryable: bool}
     */
    public static function classifyIntegrityFailurePhp(?string $messageId, int $expectedCrc, int $actualCrc, int $decodedBytes): array
    {
        $id = null;
        if ($messageId !== null) {
            $id = trim($messageId, self::TRIM_CHARS);
            $length = strlen($id);
            if ($length >= 2 && $id[0] === '<' && $id[$length - 1] === '>') {
                $id = substr($id, 1, -1);
            }
            if ($id === '') {
                $id = null;
            }
        }
        $idValid = false;
        if ($id !== null && substr_count($id, '@') === 1) {
            $at = strpos($id, '@');
            $idValid = $at > 0 && $at < strlen($id) - 1
                && strcspn($id, self::MESSAGE_ID_FORBIDDEN) === strlen($id);
        }
        $inRange = $expectedCrc >= 0 && $expectedCrc <= self::CRC_MAX && $actualCrc >= 0 && $actualCrc <= self::CRC_MAX;
        $expected = $expectedCrc & self::CRC_MAX;
        $actual = $actualCrc & self::CRC_MAX;
        $decoded = max(0, $decodedBytes);
        $match = $expected === $actual;
        $kind = match (true) {
            $decoded === 0 => 'empty_payload',
            ! $inRange => 'invalid_crc',
            ! $match => 'crc_mismatch',
            default => 'none',
        };

        return [
            'message_id' => $id,
            'has_message_id' => $id !== null,
            'message_id_valid' => $idValid,
            'expected_crc' => $expected,
            'actual_crc' => $actual,
            'expected_hex' => sprintf('%08x', $expected),
            'actual_hex' => sprintf('%08x', $actual),
            'crc_in_range' => $inRange,
            'crc_match' => $match,
            'decoded_bytes' => $decoded,
            'kind' => $kind,
            'is_failure' => $kind !== 'none',
            'retryable' => $kind === 'crc_mismatch' || $kind === 'empty_payload',
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
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
