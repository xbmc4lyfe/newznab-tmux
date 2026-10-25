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

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all five runs (min 2.34x; see the W45 parity test). */
    public const bool W451_RUST_FFI_DEFAULT = true;

    public const string W451_FFI_CDEF = <<<'C'
        ssize_t nntmux_w451_irc_channel_list_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    /** Largest Wave 45.1 input frame passed to Rust. */
    public const int W451_MAX_INPUT = 16777216;

    private const array W451_SUMMARY_KEYS = ['total_lists', 'empty_list_count', 'no_channel_count', 'password_count', 'unprefixed_count', 'duplicate_count', 'multi_channel_count'];

    private static ?bool $w451UseRustFfi = null;

    private static FFI|false|null $w451Ffi = null;

    /** Null restores the benchmark default. */
    public static function useW451RustFfi(?bool $enable = true): void
    {
        self::$w451UseRustFfi = $enable;
    }

    public static function usesW451RustFfi(): bool
    {
        return self::$w451UseRustFfi ?? self::W451_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW451RustFfi(): void
    {
        self::$w451UseRustFfi = null;
        self::$w451Ffi = null;
    }

    /**
     * Wave 45.1 entries: strings and integers as-is, the first non-null `$fields` value of an array row,
     * otherwise ''. Objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function w451Entries(array $rows, array $fields): array
    {
        $entries = [];
        foreach ($rows as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entries[] = \is_string($item) || \is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /**
     * Calls a Wave 45.1 export with `$prefix` and the w451Entries() of `$rows`, and decodes its `i64le`
     * counts. Entries are framed as `u32le count, u32le lengths, bytes`, assembled by one implode() so the
     * entry bytes are copied once and never scanned in PHP. `$ffi` is the caller's cached handle. Returns
     * null (fall back to PHP) when the frame is too large, the library or symbol is unavailable, Rust
     * declines the batch, or the output fails validation.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $fields
     * @return array<int, int>|null
     */
    public static function w451Invoke(FFI|false|null &$ffi, string $cdef, string $symbol, array $rows, array $fields, int $keys, string $prefix = ''): ?array
    {
        // Same extraction as w451Entries(), inlined to keep marshaling to one pass.
        $parts = [''];
        $lengths = [];
        $total = \strlen($prefix);
        foreach ($rows as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entry = \is_string($item) || \is_int($item) ? (string) $item : '';
            $parts[] = $entry;
            $lengths[] = $length = \strlen($entry);
            $total += $length;
        }
        $count = \count($lengths);
        if ($total + 4 * $count + 4 > self::W451_MAX_INPUT) {
            return null;
        }
        // A NUL-separated frame would need a PHP scan for embedded NULs, which binary frames carry.
        $parts[0] = $prefix.pack('V*', $count, ...$lengths);
        $input = implode('', $parts);
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w451', $cdef) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, $keys * 8, $capacity);
            if ($out === null) {
                return null;
            }
            $written = $ffi->{$symbol}($input, \strlen($input), $out, $capacity);
            if ($written !== $keys * 8) {
                return null;
            }
            $values = unpack('q*', FFI::string($out, $written));
            if (! \is_array($values) || $values[1] !== $count || min($values) < 0 || max($values) > $count) {
                return null;
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Summarizes SCRAPE_IRC_CHANNELS values with parsePhp(): empty values, values without a channel
     * (parsed as the default), and maps holding a password, a channel given without `#`, an item
     * overwriting an earlier channel, or two or more channels.
     * Entries come from strings, integers, or the first non-null channels, value array field; other
     * values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeChannelListBatch(array $rows): array
    {
        return self::usesW451RustFfi() ? self::summarizeChannelListBatchNative($rows) : self::summarizeChannelListBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeChannelListBatchNative(array $rows): array
    {
        $values = self::w451Invoke(self::$w451Ffi, self::W451_FFI_CDEF, 'nntmux_w451_irc_channel_list_batch', $rows, ['channels', 'value'], \count(self::W451_SUMMARY_KEYS));

        return $values === null ? self::summarizeChannelListBatchPhp($rows) : array_combine(self::W451_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeChannelListBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W451_SUMMARY_KEYS, 0);
        foreach (self::w451Entries($rows, ['channels', 'value']) as $entry) {
            $summary['total_lists']++;
            if ($entry === '') {
                $summary['empty_list_count']++;

                continue;
            }
            // parsePhp() with a sentinel default, also noting unprefixed and overwritten items.
            $channels = [];
            $items = 0;
            $unprefixed = false;
            foreach (explode(',', $entry) as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                [$name, $password] = array_pad(explode(':', $item, 2), 2, null);
                $name = trim($name);
                if ($name === '' || $name === '#') {
                    continue;
                }
                $items++;
                $prefixed = str_starts_with($name, '#');
                $unprefixed = $unprefixed || ! $prefixed;
                $channels[$prefixed ? $name : '#'.$name] = ($password === null || trim($password) === '') ? null : trim($password);
            }
            if ($channels === []) {
                $summary['no_channel_count']++;

                continue;
            }
            $summary['password_count'] += (int) (array_filter($channels, is_string(...)) !== []);
            $summary['unprefixed_count'] += (int) $unprefixed;
            $summary['duplicate_count'] += (int) (\count($channels) < $items);
            $summary['multi_channel_count'] += (int) (\count($channels) > 1);
        }

        return $summary;
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
