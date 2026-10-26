<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
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

    // ========================================================================
    // WAVE 61.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs reached 2.49x common mean, 2.26x common p99, 2.18x uncommon mean and 2.11x uncommon p99; see RustUpdateLockPasswordBreachAniListRecaptchaRegistrationLogW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_PASSWORD_BREACH_FFI_CDEF = 'ssize_t nntmux_w614_password_breach_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per breachCheckPlanRowBatch() record. */
    public const int W61_ROW_WORDS = 5;

    /** int64 summary words after the breachCheckPlanRowBatch() records (none for an empty batch). */
    public const int W61_TRAILER_WORDS = 8;

    private const string W61_DIGITS = '0123456789';

    /**
     * k-anonymity breach check plan rows for [SHA-1 hex, range prefix sent, range response body,
     * threshold] items. The hash is 0 empty, 1 forty hex digits (any case) or 2 malformed; the
     * prefix 0 empty, 1 five hex digits or 2 malformed. Body lines are split on "\n" and
     * trimmed; blank lines are skipped, `SUFFIX:COUNT` lines (35 hex digits, 1-10 digit count)
     * are counted (`:0` lines are padding) and others are malformed. The first line whose
     * suffix matches the hash's last 35 digits case-insensitively supplies the count.
     *
     * Status: 2 malformed hash, 3 prefix missing, malformed or not the hash's first five
     * digits, 4 no valid range line, 1 breached (count above max(0, threshold)), 0 clean.
     * Rows [status | hash class << 4 | prefix class << 6 | found << 8 | padding match << 9 | CR
     * in the body << 10, count, prefix value (-1), lines | malformed << 20 | padding << 40 (each
     * capped at 2^20 - 1), body bytes], then the trailer [clean, breached, invalid hash, prefix
     * mismatch, empty range, found count sum, max count, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $items
     * @return list<int>
     */
    public static function breachCheckPlanRowBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::breachCheckPlanRowBatchNative($items) : self::breachCheckPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $items
     * @return list<int>
     */
    public static function breachCheckPlanRowBatchPhp(array $items): array
    {
        self::w61Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$hash, $prefix, $body, $threshold]) {
            $threshold = max(0, $threshold);
            $hashLength = \strlen($hash);
            $hashClass = $hashLength === 0 ? 0 : ($hashLength === 40 && strspn($hash, self::W48_HEX) === 40 ? 1 : 2);
            $prefixLength = \strlen($prefix);
            $prefixClass = $prefixLength === 0 ? 0 : ($prefixLength === 5 && strspn($prefix, self::W48_HEX) === 5 ? 1 : 2);
            $suffix = $hashClass === 1 ? substr($hash, 5) : '';
            $found = false;
            $count = $lines = $malformed = $padding = 0;
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $colon = strpos($line, ':');
                $candidate = $colon === false ? $line : substr($line, 0, $colon);
                $tally = $colon === false ? '' : substr($line, $colon + 1);
                $tallyLength = \strlen($tally);
                if (\strlen($candidate) !== 35 || strspn($candidate, self::W48_HEX) !== 35
                    || $tallyLength === 0 || $tallyLength > 10 || strspn($tally, self::W61_DIGITS) !== $tallyLength) {
                    $malformed++;

                    continue;
                }
                $lines++;
                $n = (int) $tally;
                if ($n === 0) {
                    $padding++;
                }
                if (! $found && $suffix !== '' && strcasecmp($candidate, $suffix) === 0) {
                    $found = true;
                    $count = $n;
                }
            }
            $status = match (true) {
                $hashClass !== 1 => 2,
                $prefixClass !== 1 || strncasecmp($prefix, $hash, 5) !== 0 => 3,
                $lines === 0 => 4,
                $found && $count > $threshold => 1,
                default => 0,
            };
            $word = $status | $hashClass << 4 | $prefixClass << 6 | ($found ? 256 : 0) | ($found && $count === 0 ? 512 : 0)
                | (str_contains($body, "\r") ? 1024 : 0);
            array_push($out, $word, $count, $hashClass === 1 ? (int) hexdec(substr($hash, 0, 5)) : -1,
                min(0xFFFFF, $lines) | min(0xFFFFF, $malformed) << 20 | min(0xFFFFF, $padding) << 40, \strlen($body));
            $summary[$status]++;
            $summary[5] += $count;
            $summary[6] = max($summary[6], $count);
            $value = $word ^ $count << 8 ^ $lines;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $items
     * @return list<int>
     */
    public static function breachCheckPlanRowBatchNative(array $items): array
    {
        self::w61Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w61Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3));

        return self::w61Call($blob, $twin, $ints, $count, $count * self::W61_ROW_WORDS + self::W61_TRAILER_WORDS)
            ?? self::breachCheckPlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w61Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W61 password breach plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 4 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_int($item[3])) {
                throw new InvalidArgumentException('W61 password breach plan batch items must be [string sha1 hex, string range prefix, string range body, int threshold] lists.');
            }
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W61 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('enabled');
        self::w61RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w61Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w61Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w61RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w61', self::W61_PASSWORD_BREACH_FFI_CDEF) ?? false;
            self::w61RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w61RustFfiState('out', $out);
                self::w61RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W61_PASSWORD_BREACH_FFI_CDEF.)
            $written = $ffi->nntmux_w614_password_breach_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w61RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W61 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
