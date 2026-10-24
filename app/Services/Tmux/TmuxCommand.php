<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Support\RustPipelineLibrary;
use FFI;

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
}
