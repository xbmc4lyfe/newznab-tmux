<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

/** The candidate's lease token no longer owns it; the holder's result can never be published. */
final class LeaseLostException extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_lease_lost_classify(
            uint32_t flags,
            const char *key_ptr,
            size_t key_len,
            const char *token_ptr,
            size_t token_len,
            int64_t elapsed_ms,
            int64_t ttl_ms,
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

    private const string TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    private const int TOKEN_MAX = 128;

    private const int HAS_CANDIDATE_KEY = 1;

    private const int HAS_OWNER_TOKEN = 2;

    private const array REASONS = ['missing_candidate', 'missing_owner', 'invalid_owner', 'expired', 'stolen'];

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
     * Classifies why a verification lease was lost. Key and token are trimmed (empty becomes
     * null); a token is valid when it has at most 128 `[A-Za-z0-9_-]` bytes. A lease with a
     * positive TTL that has elapsed is `expired` (reclaimable); a live lease was `stolen`.
     *
     * @return array{candidate_key: ?string, owner_token: ?string, has_candidate: bool, has_owner: bool, token_valid: bool, elapsed_ms: int, ttl_ms: int, expired: bool, overrun_ms: int, remaining_ms: int, reason: string, reclaimable: bool}
     */
    public static function classifyLeaseLoss(?string $candidateKey, ?string $ownerToken, int $elapsedMs, int $ttlMs): array
    {
        return self::usesRustFfi()
            ? self::classifyLeaseLossNative($candidateKey, $ownerToken, $elapsedMs, $ttlMs)
            : self::classifyLeaseLossPhp($candidateKey, $ownerToken, $elapsedMs, $ttlMs);
    }

    /**
     * @return array{candidate_key: ?string, owner_token: ?string, has_candidate: bool, has_owner: bool, token_valid: bool, elapsed_ms: int, ttl_ms: int, expired: bool, overrun_ms: int, remaining_ms: int, reason: string, reclaimable: bool}
     */
    public static function classifyLeaseLossNative(?string $candidateKey, ?string $ownerToken, int $elapsedMs, int $ttlMs): array
    {
        $key = $candidateKey ?? '';
        $token = $ownerToken ?? '';
        $flags = ($candidateKey === null ? 0 : self::HAS_CANDIDATE_KEY) | ($ownerToken === null ? 0 : self::HAS_OWNER_TOKEN);
        $fields = self::callNative(
            'nntmux_lease_lost_classify',
            [$flags, $key, strlen($key), $token, strlen($token), $elapsedMs, $ttlMs],
            strlen($key) + strlen($token) + 256,
            12,
        );
        // The tail is "<key>\x1F<token>" with the key length in field 10, so both may hold any byte.
        if ($fields === null || count($fields) !== 12 || ! ctype_digit($fields[3]) || ! ctype_digit($fields[4])
            || ! ctype_digit($fields[6]) || ! ctype_digit($fields[7]) || ! in_array($fields[8], self::REASONS, true)
            || ! ctype_digit($fields[10]) || strlen($fields[11]) <= (int) $fields[10]
            || $fields[11][(int) $fields[10]] !== self::FIELD_SEPARATOR) {
            return self::classifyLeaseLossPhp($candidateKey, $ownerToken, $elapsedMs, $ttlMs);
        }
        $keyLength = (int) $fields[10];
        $hasCandidate = $fields[0] === '1';
        $hasOwner = $fields[1] === '1';

        return [
            'candidate_key' => $hasCandidate ? substr($fields[11], 0, $keyLength) : null,
            'owner_token' => $hasOwner ? substr($fields[11], $keyLength + 1) : null,
            'has_candidate' => $hasCandidate,
            'has_owner' => $hasOwner,
            'token_valid' => $fields[2] === '1',
            'elapsed_ms' => (int) $fields[3],
            'ttl_ms' => (int) $fields[4],
            'expired' => $fields[5] === '1',
            'overrun_ms' => (int) $fields[6],
            'remaining_ms' => (int) $fields[7],
            'reason' => $fields[8],
            'reclaimable' => $fields[9] === '1',
        ];
    }

    /**
     * @return array{candidate_key: ?string, owner_token: ?string, has_candidate: bool, has_owner: bool, token_valid: bool, elapsed_ms: int, ttl_ms: int, expired: bool, overrun_ms: int, remaining_ms: int, reason: string, reclaimable: bool}
     */
    public static function classifyLeaseLossPhp(?string $candidateKey, ?string $ownerToken, int $elapsedMs, int $ttlMs): array
    {
        $key = $candidateKey === null ? null : trim($candidateKey, self::TRIM_CHARS);
        $key = $key === '' ? null : $key;
        $token = $ownerToken === null ? null : trim($ownerToken, self::TRIM_CHARS);
        $token = $token === '' ? null : $token;
        $tokenValid = $token !== null && strlen($token) <= self::TOKEN_MAX && strspn($token, self::TOKEN_CHARS) === strlen($token);
        $elapsed = max(0, $elapsedMs);
        $ttl = max(0, $ttlMs);
        $expired = $ttl > 0 && $elapsed >= $ttl;

        return [
            'candidate_key' => $key,
            'owner_token' => $token,
            'has_candidate' => $key !== null,
            'has_owner' => $token !== null,
            'token_valid' => $tokenValid,
            'elapsed_ms' => $elapsed,
            'ttl_ms' => $ttl,
            'expired' => $expired,
            'overrun_ms' => $expired ? $elapsed - $ttl : 0,
            'remaining_ms' => $ttl > 0 ? max(0, $ttl - $elapsed) : 0,
            'reason' => match (true) {
                $key === null => 'missing_candidate',
                $token === null => 'missing_owner',
                ! $tokenValid => 'invalid_owner',
                $expired => 'expired',
                default => 'stolen',
            },
            'reclaimable' => $expired,
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
