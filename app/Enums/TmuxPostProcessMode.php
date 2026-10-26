<?php

declare(strict_types=1);

namespace App\Enums;

use App\Providers\HorizonServiceProvider;
use App\Support\RustPipelineLibrary;
use FFI\CData;

enum TmuxPostProcessMode: int
{
    case Disabled = 0;
    case Additional = 1;
    case Nfo = 2;
    case All = 3;

    public const string FFI_CDEF = HorizonServiceProvider::FFI_CDEF;

    // Retain PHP until paired caller benchmarks meet the 1.5x adoption gate.
    private const bool RUST_FFI_DEFAULT = false;

    public static function setUseRustFfi(?bool $use): void
    {
        HorizonServiceProvider::setRustFfiToggle(self::class, $use);
    }

    public static function usesRustFfi(): bool
    {
        return HorizonServiceProvider::rustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Accept backing integers, canonical single-digit strings, or this enum's cases.
     * Reject other types and unknown values, falling back to the safe zero mode.
     *
     * @return array{value: int, is_valid: bool}
     */
    public static function normalizePostProcessModeValue(mixed $value): array
    {
        return (self::usesRustFfi() ? self::normalizePostProcessModeValueNative($value) : null)
            ?? self::normalizePostProcessModeValuePhp($value);
    }

    /** @return array{value: int, is_valid: bool} */
    public static function normalizePostProcessModeValuePhp(mixed $value): array
    {
        $raw = $value instanceof self ? $value->value : $value;
        if (is_string($raw) && strlen($raw) === 1 && $raw >= '0' && $raw <= '9') {
            $raw = (int) $raw;
        }
        $resolved = is_int($raw) ? self::tryFrom($raw) : null;

        return ['value' => $resolved->value ?? 0, 'is_valid' => $resolved !== null];
    }

    /** @return array{value: int, is_valid: bool}|null */
    public static function normalizePostProcessModeValueNative(mixed $value): ?array
    {
        $result = HorizonServiceProvider::normalizeEnumNative(self::class, 2, $value);
        if ($result === null || ! is_int($result['value'])) {
            return null;
        }

        return $result;
    }

    // ---- Wave 59.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 10.18x/9.94x, uncommon 8.48x/8.25x); see RustEnumsTmuxPanePostProcessSequentialPredbKeySeasonPackW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_POST_PROCESS_MODE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w592_tmux_post_process_mode_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 59 routing, binding and output buffer, kept in function-local statics (enums cannot declare static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w59', self::W59_POST_PROCESS_MODE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w59RustFfiState('ffi');
                    $buffer = $resolved instanceof \FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planPostProcessModeBatch() routed to Rust ($enabled) or PHP,
     * then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w59Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w59Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', \FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    private const array W59_NAMES = ['disabled' => 0, 'additional' => 1, 'nfo' => 2, 'all' => 3];

    private const array W59_SCOPES = ['post' => 0, 'amazon' => 1, 'non' => 2];

    /** Work bits per scope (post, amazon, non, unknown) and mode: 1 additional, 2 NFO, 4 movie, 8 TV, 16 Amazon. */
    private const array W59_WORK = [[0, 1, 2, 15], [0, 16, 18, 28], [0, 4, 8, 12], [0, 0, 0, 0]];

    /**
     * `[mode, kind]`: kind 1 a canonical single digit, 2 another `[+-]?[0-9]+` spelling of a
     * backing value, 3 a case name (case-insensitive), 0 invalid (mode 0).
     *
     * @return array{int, int}
     */
    private static function w59Mode(string $value): array
    {
        $sign = $value !== '' && ($value[0] === '+' || $value[0] === '-') ? 1 : 0;
        $length = \strlen($value) - $sign;
        if ($length > 0 && strspn($value, '0123456789', $sign) === $length) {
            $digits = ltrim(substr($value, $sign), '0');
            if ($digits === '' || (\strlen($digits) === 1 && $value[0] !== '-' && (int) $digits <= 3)) {
                return [(int) $digits, \strlen($value) === 1 ? 1 : 2];
            }

            return [0, 0];
        }
        $mode = self::W59_NAMES[strtolower($value)] ?? null;

        return $mode === null ? [0, 0] : [$mode, 3];
    }

    /**
     * Batch post-process mode plan. An optional `scope:` prefix (before the first colon, trimmed,
     * case-insensitive) selects post (0, also without a colon), amazon (1), non (2) or unknown (3);
     * the trimmed rest resolves through w59Mode(). Per token `mode | kind << 2 | scope << 4 |
     * valid << 6 | work << 8 | min(len, 0x7FFFF) << 13 | crc32(strtolower(trim(token))) << 32`.
     * Then the per-mode counts, the invalid count, the union of work bits, the per-scope counts
     * and the XXH64 digest of all earlier words.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function planPostProcessModeBatch(array $tokens): array
    {
        return self::usesW59RustFfi() ? self::planPostProcessModeBatchNative($tokens) : self::planPostProcessModeBatchPhp($tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function planPostProcessModeBatchPhp(array $tokens): array
    {
        $out = [];
        $modes = [0, 0, 0, 0];
        $scopes = [0, 0, 0, 0];
        $invalid = $work = 0;
        foreach ($tokens as $raw) {
            $raw = (string) $raw;
            $scope = 0;
            $value = $raw;
            if (($colon = strpos($raw, ':')) !== false) {
                $scope = self::W59_SCOPES[strtolower(trim(substr($raw, 0, $colon)))] ?? 3;
                $value = substr($raw, $colon + 1);
            }
            [$mode, $kind] = self::w59Mode(trim($value));
            $valid = $kind !== 0 ? 1 : 0;
            $bits = self::W59_WORK[$scope][$mode];
            $modes[$mode]++;
            $scopes[$scope]++;
            $invalid += 1 - $valid;
            $work |= $bits;
            $out[] = $mode | $kind << 2 | $scope << 4 | $valid << 6 | $bits << 8 | min(\strlen($raw), 0x7FFFF) << 13
                | crc32(strtolower(trim($raw))) << 32;
        }
        array_push($out, ...$modes);
        array_push($out, $invalid, $work, ...$scopes);
        $out[] = self::w59Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, an item holds a NUL byte or the
     * column is not a list.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function planPostProcessModeBatchNative(array $tokens): array
    {
        $count = \count($tokens);
        $words = $count + 11;
        /** @var array{\FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($state === null || ! array_is_list($tokens)) {
            return self::planPostProcessModeBatchPhp($tokens);
        }
        $blob = implode("\0", $tokens);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W59_POST_PROCESS_MODE_FFI_CDEF.)
            $written = $ffi->nntmux_w592_tmux_post_process_mode_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }

        return self::w59Words($written, $out, $words) ?? self::planPostProcessModeBatchPhp($tokens);
    }
}
