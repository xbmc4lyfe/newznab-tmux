<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

/** One title could not be exhaustively confirmed; retain it for a later matching attempt. */
final class IncompletePredbMatchException extends RuntimeException
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbMatchExceptionIdentityTransactionsParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w383_incomplete_match_summary(
            const char *message_ptr,
            size_t message_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Message prefix shared by every incomplete PreDB fulltext match. */
    public const string MESSAGE_PREFIX = 'PreDB fulltext matching is incomplete: ';

    /** Known message details raised by name fixing, keyed by their reason code. */
    public const array REASONS = [
        'paging_unsupported' => 'the search driver does not support candidate paging.',
        'scan_budget' => 'the 210-candidate scan budget was reached.',
        'page_boundaries' => 'invalid candidate page boundaries.',
        'candidate_order' => 'candidate IDs did not advance in order.',
        'evidence_unconfirmed' => 'current release evidence could not be completely confirmed.',
    ];

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $rustFfiOverride = null;

    private static FFI|false|null $rustFfiHandle = null;

    public static function useRustFfi(?bool $enabled): void
    {
        self::$rustFfiOverride = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$rustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$rustFfiOverride = null;
        self::$rustFfiHandle = null;
    }

    /**
     * Classifies an exception message: `incomplete` messages carry MESSAGE_PREFIX, `reason` is the
     * REASONS key of a known detail (`other` for an unknown detail, `foreign` without the prefix),
     * and `detail` is the text after the prefix.
     *
     * @return array{incomplete: bool, reason: string, detail: string}
     */
    public static function summarizeIncompleteMatch(string $message): array
    {
        return (self::usesRustFfi() ? self::summarizeIncompleteMatchNative($message) : null) ?? self::summarizeIncompleteMatchPhp($message);
    }

    /**
     * @return array{incomplete: bool, reason: string, detail: string}|null
     */
    private static function summarizeIncompleteMatchNative(string $message): ?array
    {
        $fields = self::callNative('nntmux_w383_incomplete_match_summary', [$message, strlen($message)], strlen($message) + 32, 3);
        if ($fields === null || count($fields) !== 3 || ! in_array($fields[0], ['0', '1'], true)) {
            return null;
        }

        return ['incomplete' => $fields[0] === '1', 'reason' => $fields[1], 'detail' => $fields[2]];
    }

    /**
     * @return array{incomplete: bool, reason: string, detail: string}
     */
    private static function summarizeIncompleteMatchPhp(string $message): array
    {
        if (! str_starts_with($message, self::MESSAGE_PREFIX)) {
            return ['incomplete' => false, 'reason' => 'foreign', 'detail' => ''];
        }
        $detail = substr($message, strlen(self::MESSAGE_PREFIX));
        $reason = array_search($detail, self::REASONS, true);

        return ['incomplete' => true, 'reason' => is_string($reason) ? $reason : 'other', 'detail' => $detail];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::rustFfi();
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

    private static function rustFfi(): ?FFI
    {
        if (self::$rustFfiHandle === null) {
            self::$rustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w383', self::FFI_CDEF) ?? false;
        }

        return self::$rustFfiHandle === false ? null : self::$rustFfiHandle;
    }
}
