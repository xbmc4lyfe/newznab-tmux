<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

final class NzbArticleFingerprintUnavailable extends RuntimeException
{
    /** The Wave 37.2 export shared by every helper in this slice. */
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_w37_verification_capacity_prefile_singleton(
            uint32_t operation,
            uint32_t flags,
            const char *input,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string FFI_DOMAIN = 'nfo_w372';

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationCapacityPrefileSingletonParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /** Counts are clamped to [0, 2^40] so percentages cannot overflow. */
    private const int COUNT_MAX = 1099511627776;

    private const string TRIM_CHARS = " \t\n\r\0\x0B";

    private const array KINDS = ['disabled', 'unsupported', 'missing', 'timeout', 'unknown'];

    private const int HAS_MESSAGE_ID = 1;

    private const int HAS_REASON = 2;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function useRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
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

    /**
     * Classifies why an article fingerprint is unavailable by the first of "disabled", "unsupported"/"not supported",
     * "missing"/"not found"/"430" or "timed out"/"timeout" its reason contains (ASCII case-insensitive), else unknown.
     * Only timeouts and unknown causes are retryable. The message-id and reason are trimmed (the message-id also loses
     * one pair of enclosing angle brackets), null when empty. Counts are clamped to [0, 2^40], missing to probed.
     *
     * @return array{message_id: ?string, reason: ?string, kind: string, articles_probed: int, articles_missing: int, missing_percent: int, fully_missing: bool, retryable: bool}
     */
    public static function classifyUnavailability(?string $messageId, ?string $reason, int $articlesProbed, int $articlesMissing): array
    {
        return (self::usesRustFfi() ? self::classifyUnavailabilityNative($messageId, $reason, $articlesProbed, $articlesMissing) : null)
            ?? self::classifyUnavailabilityPhp($messageId, $reason, $articlesProbed, $articlesMissing);
    }

    /**
     * Runs one Wave 37.2 export operation and returns exactly `$slots` packed integers, or null when the library is
     * missing, the export declines (-1) or the reply is malformed. A too-small scratch buffer is grown once.
     *
     * @return list<int>|null
     */
    public static function evaluateW372(?FFI $ffi, int $operation, int $flags, string $input, int $slots): ?array
    {
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $slots * 8, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w37_verification_capacity_prefile_singleton($operation, $flags, $input, \strlen($input), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w37_verification_capacity_prefile_singleton($operation, $flags, $input, \strlen($input), $buffer, $capacity);
            }
            if (! \is_int($written) || $written !== $slots * 8 || $written > $capacity) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $written));

            return $values === false ? null : array_values(array_map('intval', $values));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The substring a native `[present, start, length]` range selects, null when absent, or false when invalid.
     */
    public static function nativeRange(?string $source, int $present, int $start, int $length): string|false|null
    {
        if ($present === 0 && $start === 0 && $length === 0) {
            return null;
        }
        if ($present !== 1 || $source === null || $start < 0 || $length < 1 || $start + $length > \strlen($source)) {
            return false;
        }

        return substr($source, $start, $length);
    }

    /**
     * @return array{message_id: ?string, reason: ?string, kind: string, articles_probed: int, articles_missing: int, missing_percent: int, fully_missing: bool, retryable: bool}|null
     */
    private static function classifyUnavailabilityNative(?string $messageId, ?string $reason, int $articlesProbed, int $articlesMissing): ?array
    {
        $flags = ($messageId === null ? 0 : self::HAS_MESSAGE_ID) | ($reason === null ? 0 : self::HAS_REASON);
        $input = pack('q4', \strlen($messageId ?? ''), \strlen($reason ?? ''), $articlesProbed, $articlesMissing).($messageId ?? '').($reason ?? '');
        $slots = self::evaluateW372(self::ffi(), 0, $flags, $input, 12);
        if ($slots === null) {
            return null;
        }
        $id = self::nativeRange($messageId, $slots[0], $slots[1], $slots[2]);
        $trimmed = self::nativeRange($reason, $slots[3], $slots[4], $slots[5]);
        if ($id === false || $trimmed === false || ! isset(self::KINDS[$slots[6]]) || $slots[7] < 0 || $slots[7] > self::COUNT_MAX
            || $slots[8] < 0 || $slots[8] > $slots[7] || $slots[9] < 0 || $slots[9] > 100 || $slots[10] > 1 || $slots[10] < 0
            || $slots[11] > 1 || $slots[11] < 0) {
            return null;
        }

        return [
            'message_id' => $id,
            'reason' => $trimmed,
            'kind' => self::KINDS[$slots[6]],
            'articles_probed' => $slots[7],
            'articles_missing' => $slots[8],
            'missing_percent' => $slots[9],
            'fully_missing' => $slots[10] === 1,
            'retryable' => $slots[11] === 1,
        ];
    }

    /**
     * @return array{message_id: ?string, reason: ?string, kind: string, articles_probed: int, articles_missing: int, missing_percent: int, fully_missing: bool, retryable: bool}
     */
    private static function classifyUnavailabilityPhp(?string $messageId, ?string $reason, int $articlesProbed, int $articlesMissing): array
    {
        $lower = strtolower($reason ?? '');
        $kind = match (true) {
            str_contains($lower, 'disabled') => 'disabled',
            str_contains($lower, 'unsupported') || str_contains($lower, 'not supported') => 'unsupported',
            str_contains($lower, 'missing') || str_contains($lower, 'not found') || str_contains($lower, '430') => 'missing',
            str_contains($lower, 'timed out') || str_contains($lower, 'timeout') => 'timeout',
            default => 'unknown',
        };
        $id = trim($messageId ?? '', self::TRIM_CHARS);
        if (\strlen($id) >= 2 && $id[0] === '<' && $id[-1] === '>') {
            $id = substr($id, 1, -1);
        }
        $trimmed = trim($reason ?? '', self::TRIM_CHARS);
        $probed = max(0, min(self::COUNT_MAX, $articlesProbed));
        $missing = min($probed, max(0, min(self::COUNT_MAX, $articlesMissing)));

        return [
            'message_id' => $id === '' ? null : $id,
            'reason' => $trimmed === '' ? null : $trimmed,
            'kind' => $kind,
            'articles_probed' => $probed,
            'articles_missing' => $missing,
            'missing_percent' => $probed > 0 ? intdiv($missing * 100, $probed) : 0,
            'fully_missing' => $probed > 0 && $missing === $probed,
            'retryable' => $kind === 'timeout' || $kind === 'unknown',
        ];
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain(self::FFI_DOMAIN, self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
