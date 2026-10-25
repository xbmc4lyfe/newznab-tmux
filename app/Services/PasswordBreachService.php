<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class PasswordBreachService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_password_breach_threshold(int64_t threshold, int64_t *out);
        int32_t nntmux_password_breach_should_check(const char *password, size_t len);
        C;

    /** Opt-in only; see the paired measurements in RustPasskeyCaptchaRuleParityTest. */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Minimum number of times a password must appear in breaches to be considered compromised.
     */
    protected int $threshold;

    public function __construct(int $threshold = 1)
    {
        $this->threshold = self::normalizeThreshold($threshold);
    }

    /**
     * Check if a password has been found in data breaches.
     *
     * This method uses Laravel's Password validation rule with uncompromised() which queries
     * the Have I Been Pwned API using k-Anonymity (only first 5 chars of SHA-1 hash are sent).
     *
     * @param  string  $password  The password to check
     * @return bool True if the password has been found in breaches, false otherwise
     */
    public function isPasswordBreached(string $password): bool
    {
        if (! self::shouldCheckPassword($password)) {
            return false;
        }

        try {
            $validator = Validator::make(
                ['password' => $password],
                ['password' => Password::min(1)->uncompromised($this->threshold)]
            );

            // If validation fails, the password is compromised
            return $validator->fails();
        } catch (\Exception $e) {
            // Log the error but don't block login (fail open)
            Log::error('Password breach check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Negative counts are normalized to Laravel's zero-tolerance threshold. */
    public static function normalizeThreshold(int $threshold): int
    {
        return self::$useRustFfi ? self::normalizeThresholdNative($threshold) : self::normalizeThresholdPhp($threshold);
    }

    public static function normalizeThresholdPhp(int $threshold): int
    {
        return max(0, $threshold);
    }

    public static function normalizeThresholdNative(int $threshold): int
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                $out = $ffi->new('int64_t[1]');
                // @phpstan-ignore method.notFound
                $status = $ffi->nntmux_password_breach_threshold($threshold, $out);
                if ($status === 0) {
                    return (int) $out[0];
                }
            } catch (Throwable) {
                // Preserve breach-check availability without FFI.
            }
        }

        return self::normalizeThresholdPhp($threshold);
    }

    public static function shouldCheckPassword(string $password): bool
    {
        return self::$useRustFfi ? self::shouldCheckPasswordNative($password) : self::shouldCheckPasswordPhp($password);
    }

    public static function shouldCheckPasswordPhp(string $password): bool
    {
        return $password !== '';
    }

    public static function shouldCheckPasswordNative(string $password): bool
    {
        $ffi = self::ffi();
        if ($ffi !== null) {
            try {
                // @phpstan-ignore method.notFound
                $status = $ffi->nntmux_password_breach_should_check($password, strlen($password));
                if ($status === 0 || $status === 1) {
                    return $status === 1;
                }
            } catch (Throwable) {
                // Preserve byte-string semantics without FFI.
            }
        }

        return self::shouldCheckPasswordPhp($password);
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W48 parity test). */
    public const bool W48_BREACH_RANGE_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_password_breach_range_batch(const char *passwords, size_t passwords_len, const char *bodies, size_t bodies_len, size_t count, int64_t threshold, char *out, size_t cap);
        C;

    private const string W48_HEX = '0123456789ABCDEFabcdef';

    private static ?bool $w48BreachRangeRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark default; enableRustFfi() is untouched. */
    public static function useW48BreachRangeRustFfi(?bool $enabled = true): void
    {
        self::$w48BreachRangeRustFfi = $enabled;
    }

    public static function usesW48BreachRangeRustFfi(): bool
    {
        return self::$w48BreachRangeRustFfi ?? self::W48_BREACH_RANGE_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW48BreachRangeRustFfi(): void
    {
        self::$w48BreachRangeRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Batch k-anonymity lookups: each password's uppercase SHA-1 splits into a five-character
     * range prefix and a 35-character suffix that is looked up in the matching Pwned Passwords
     * range body (`SUFFIX:COUNT` lines, any case, trimmed; other non-blank lines are
     * malformed). Two words per item: `count | found << 40 | breached << 41` (the first
     * matching line wins; breached when the count exceeds $threshold, like Laravel's
     * `uncompromised()`), then `prefix | lines << 20 | malformed << 36 | padding << 52` where
     * padding counts `:0` entries. A final word holds the FNV-1a 64 digest of the item words.
     *
     * @param  list<string>  $passwords
     * @param  list<string>  $rangeBodies  Range response bodies, parallel to $passwords.
     * @return list<int>
     */
    public static function lookupBreachCounts(array $passwords, array $rangeBodies, int $threshold = 0): array
    {
        return self::usesW48BreachRangeRustFfi()
            ? self::lookupBreachCountsNative($passwords, $rangeBodies, $threshold)
            : self::lookupBreachCountsPhp($passwords, $rangeBodies, $threshold);
    }

    /**
     * @param  list<string>  $passwords
     * @param  list<string>  $rangeBodies
     * @return list<int>
     */
    public static function lookupBreachCountsPhp(array $passwords, array $rangeBodies, int $threshold = 0): array
    {
        $out = [];
        foreach ($passwords as $i => $password) {
            $hash = strtoupper(sha1($password));
            $suffix = substr($hash, 5);
            $found = false;
            $count = $lines = $malformed = $padding = 0;
            foreach (explode("\n", $rangeBodies[$i] ?? '') as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $colon = strpos($line, ':');
                $candidate = $colon === false ? $line : substr($line, 0, $colon);
                $tally = $colon === false ? '' : substr($line, $colon + 1);
                if (\strlen($candidate) !== 35 || strspn($candidate, self::W48_HEX) !== 35
                    || $tally === '' || \strlen($tally) > 10 || strspn($tally, '0123456789') !== \strlen($tally)) {
                    $malformed++;

                    continue;
                }
                $lines++;
                $n = (int) $tally;
                if ($n === 0) {
                    $padding++;
                }
                if (! $found && strcasecmp($candidate, $suffix) === 0) {
                    $found = true;
                    $count = $n;
                }
            }
            $out[] = $count | (int) $found << 40 | (int) ($found && $count > $threshold) << 41;
            $out[] = (int) hexdec(substr($hash, 0, 5)) | min(65535, $lines) << 20 | min(65535, $malformed) << 36 | min(2047, $padding) << 52;
        }
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('fnv1a64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a value holds a NUL byte.
     *
     * @param  list<string>  $passwords
     * @param  list<string>  $rangeBodies
     * @return list<int>
     */
    public static function lookupBreachCountsNative(array $passwords, array $rangeBodies, int $threshold = 0): array
    {
        $count = \count($passwords);
        $ffi = $count === 0 ? null : (self::$w48Ffi instanceof FFI ? self::$w48Ffi : self::w48Ffi());
        $packedPasswords = implode("\0", $passwords);
        $packedBodies = implode("\0", $rangeBodies);
        if ($ffi === null || \count($rangeBodies) !== $count
            || substr_count($packedPasswords, "\0") !== $count - 1 || substr_count($packedBodies, "\0") !== $count - 1) {
            return self::lookupBreachCountsPhp($passwords, $rangeBodies, $threshold);
        }
        $bytes = ($count * 2 + 1) * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::lookupBreachCountsPhp($passwords, $rangeBodies, $threshold);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_password_breach_range_batch($packedPasswords, \strlen($packedPasswords), $packedBodies, \strlen($packedBodies), $count, $threshold, $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::lookupBreachCountsPhp($passwords, $rangeBodies, $threshold);
    }

    private static function w48Ffi(): ?FFI
    {
        self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w48', self::W48_FFI_CDEF) ?? false;

        return self::$w48Ffi === false ? null : self::$w48Ffi;
    }
}
