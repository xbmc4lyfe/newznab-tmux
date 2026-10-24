<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Throwable;

/**
 * Parses SCRAPE_IRC_CHANNELS ("#chan1,#chan2:password") into the [channel => password|null]
 * map the IRC scraper joins. A missing leading "#" is added.
 */
final class IrcChannelList
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_irc_channel_list_parse(const char *input, size_t len, char *out_buf, size_t out_cap);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    /**
     * @return array<string, string|null>
     */
    public static function parse(?string $value, string $default = '#PreNNTmux'): array
    {
        // Retain native PHP parsing in production: reconstructing the associative array
        // from FFI NUL-separated slices achieves 1.03x-1.49x (< 1.5x threshold).
        return self::parsePhp($value, $default);
    }

    /**
     * @return array<string, string|null>|null
     */
    public static function parseNative(?string $value, string $default = '#PreNNTmux'): ?array
    {
        $raw = (string) $value;
        if ($raw === '') {
            return [$default => null];
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = strlen($raw);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 2 + 64), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_irc_channel_list_parse($raw, $len, $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_irc_channel_list_parse($raw, $len, $scratch, $cap);
                if (! is_int($written) || $written < 0) {
                    return null;
                }
            }
            if ($written === 0) {
                return [$default => null];
            }

            $parts = explode("\0", FFI::string($scratch, $written));
            $channels = [];
            for ($i = 0, $n = count($parts); $i + 1 < $n; $i += 2) {
                $channels[$parts[$i]] = $parts[$i + 1] === '' ? null : $parts[$i + 1];
            }

            return $channels === [] ? [$default => null] : $channels;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string|null>
     */
    private static function parsePhp(?string $value, string $default = '#PreNNTmux'): array
    {
        $channels = [];

        foreach (explode(',', (string) $value) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            [$name, $password] = array_pad(explode(':', $item, 2), 2, null);
            $name = trim($name);
            if ($name === '' || $name === '#') {
                continue;
            }

            $channels[str_starts_with($name, '#') ? $name : '#'.$name] = ($password === null || trim($password) === '') ? null : trim($password);
        }

        return $channels === [] ? [$default => null] : $channels;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('support_irc_channels', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }
}
