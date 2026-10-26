<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

final class TmuxCommand
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_command_arguments_nul(const char *socket, size_t socket_len, const char *args_nul, size_t args_len, size_t args_count, int64_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_command_monitor_nul(const char *php_binary, size_t php_binary_len, const char *artisan_path, size_t artisan_path_len, const char *session, size_t session_len, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public static function arguments(array $arguments): array
    {
        return self::$useRustFfi
            ? self::argumentsNative($arguments)
            : self::argumentsPhp($arguments);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public static function argumentsPhp(array $arguments, ?string $socket = null): array
    {
        $socket ??= (string) config('tmux.socket_name', '');

        return array_merge(['tmux', '-u'], $socket === '' ? [] : ['-L', $socket], $arguments);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public static function argumentsNative(array $arguments, ?string $socket = null): array
    {
        $socket ??= (string) config('tmux.socket_name', '');
        $ffi = self::mediaFfi();
        if ($ffi === null || str_contains($socket, "\0")) {
            return self::argumentsPhp($arguments, $socket);
        }

        foreach ($arguments as $arg) {
            if (str_contains($arg, "\0")) {
                return self::argumentsPhp($arguments, $socket);
            }
        }

        $argsCount = \count($arguments);
        $argsNul = $argsCount === 0 ? '' : implode("\0", $arguments);
        $argsLen = \strlen($argsNul);
        $socketLen = \strlen($socket);

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $socketLen + $argsLen + 32), $cap);
        if ($scratch === null) {
            return self::argumentsPhp($arguments, $socket);
        }

        $outCount = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_tmux_command_arguments_nul(
            $socket,
            $socketLen,
            $argsNul,
            $argsLen,
            $argsCount,
            $outCount,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return self::argumentsPhp($arguments, $socket);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_command_arguments_nul(
                $socket,
                $socketLen,
                $argsNul,
                $argsLen,
                $argsCount,
                $outCount,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written <= 0) {
            return self::argumentsPhp($arguments, $socket);
        }

        /** @var int $expectedCount */
        $expectedCount = $outCount[0];
        $parts = explode("\0", FFI::string($scratch, $written));

        return \count($parts) === $expectedCount ? $parts : self::argumentsPhp($arguments, $socket);
    }

    /** @return list<string> */
    public static function monitor(string $session): array
    {
        return self::$useRustFfi
            ? self::monitorNative($session)
            : self::monitorPhp($session);
    }

    /** @return list<string> */
    public static function monitorPhp(string $session, ?string $phpBinary = null, ?string $artisanPath = null): array
    {
        $phpBinary ??= PHP_BINARY;
        $artisanPath ??= base_path('artisan');

        return [$phpBinary, $artisanPath, 'tmux:monitor', '--session='.$session];
    }

    /** @return list<string> */
    public static function monitorNative(string $session, ?string $phpBinary = null, ?string $artisanPath = null): array
    {
        $phpBinary ??= PHP_BINARY;
        $artisanPath ??= base_path('artisan');

        $ffi = self::mediaFfi();
        if ($ffi === null || str_contains($phpBinary, "\0") || str_contains($artisanPath, "\0") || str_contains($session, "\0")) {
            return self::monitorPhp($session, $phpBinary, $artisanPath);
        }

        $phpLen = \strlen($phpBinary);
        $artisanLen = \strlen($artisanPath);
        $sessionLen = \strlen($session);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $phpLen + $artisanLen + $sessionLen + 64), $cap);
        if ($scratch === null) {
            return self::monitorPhp($session, $phpBinary, $artisanPath);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_tmux_command_monitor_nul(
            $phpBinary,
            $phpLen,
            $artisanPath,
            $artisanLen,
            $session,
            $sessionLen,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return self::monitorPhp($session, $phpBinary, $artisanPath);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_command_monitor_nul(
                $phpBinary,
                $phpLen,
                $artisanPath,
                $artisanLen,
                $session,
                $sessionLen,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written <= 0) {
            return self::monitorPhp($session, $phpBinary, $artisanPath);
        }

        $parts = explode("\0", FFI::string($scratch, $written));

        return \count($parts) === 4 ? $parts : self::monitorPhp($session, $phpBinary, $artisanPath);
    }

    // ========================================================================
    // WAVE 58.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.17x, p99 4.45x; uncommon mean 2.07x, p99 1.88x); see RustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_TMUX_COMMAND_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_tmux_command_line_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Bytes that need no shell quoting in w58CommandLineBatch(). */
    public const string W58_SAFE_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_@%+=:,./-';

    /** tmux subcommands that w58CommandLineBatch() classifies as class 3. */
    public const array W58_SUBCOMMANDS = ['new-session' => true, 'kill-session' => true, 'has-session' => true, 'attach' => true,
        'new-window' => true, 'select-window' => true, 'split-window' => true, 'select-pane' => true, 'respawn-pane' => true,
        'list-panes' => true, 'send-keys' => true, 'set-option' => true, 'display-message' => true, 'kill-server' => true];

    /** Trailer words after the per-item words of w58CommandLineBatch(). */
    public const int W58_COMMAND_TRAILER = 8;

    public static function usesW58RustFfi(): bool
    {
        $override = self::w58RustFfiState();

        return \is_bool($override) ? $override : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 58 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 58.3 tmux command lines. Each argument is kept as is when it is non-empty W58_SAFE_BYTES, else
     * single-quoted POSIX style (`'` becomes `'\''`). Per argument `class | quoted << 3 | target << 4 |
     * min(quotes, 255) << 6 | min(escapedLength, 2^24 - 1) << 14 | min(length, 2^20 - 1) << 38`: target is 1
     * for `session:window` and 2 for `session:window.pane` (1-64 byte `[A-Za-z0-9_-]` session, 1-3 digit
     * numbers); class is 0 empty, 1 option (`-` plus at least one byte), 3 W58_SUBCOMMANDS, 2 target, 4 safe
     * word, else 5. Then the six class counts, the assembled line's length (escaped arguments joined by
     * spaces) and its FNV-1a 32-bit hash.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58CommandLineBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::w58CommandLineBatchNative($items) : self::w58CommandLineBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58CommandLineBatchNative(array $items): array
    {
        return self::w58Invoke($items, \count($items) + self::W58_COMMAND_TRAILER) ?? self::w58CommandLineBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58CommandLineBatchPhp(array $items): array
    {
        $out = [];
        $classes = [0, 0, 0, 0, 0, 0];
        $escaped = [];
        foreach ($items as $arg) {
            $arg = (string) $arg;
            $length = \strlen($arg);
            $safe = $length > 0 && strspn($arg, self::W58_SAFE_BYTES) === $length;
            $quotes = substr_count($arg, "'");
            $line = $safe ? $arg : "'".str_replace("'", "'\\''", $arg)."'";
            $target = preg_match('/\A[A-Za-z0-9_-]{1,64}:[0-9]{1,3}(\.[0-9]{1,3})?\z/', $arg, $match) === 1 ? (isset($match[1]) ? 2 : 1) : 0;
            $class = match (true) {
                $length === 0 => 0,
                $length >= 2 && $arg[0] === '-' => 1,
                isset(self::W58_SUBCOMMANDS[$arg]) => 3,
                $target !== 0 => 2,
                $safe => 4,
                default => 5,
            };
            $classes[$class]++;
            $escaped[] = $line;
            $out[] = $class | ($safe ? 0 : 1) << 3 | $target << 4 | min($quotes, 255) << 6 | min(\strlen($line), (1 << 24) - 1) << 14
                | min($length, (1 << 20) - 1) << 38;
        }
        $line = implode(' ', $escaped);
        array_push($out, ...$classes);
        array_push($out, \strlen($line), (int) hexdec(hash('fnv1a32', $line)));

        return $out;
    }

    /**
     * Wave 58 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w58', self::W58_TMUX_COMMAND_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 58.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w58Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w58RustFfiState('ffi');
            $out = self::w58RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w58RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W58_TMUX_COMMAND_FFI_CDEF.)
            $written = $ffi->nntmux_w58_tmux_command_line_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }
}
