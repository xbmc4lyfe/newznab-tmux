<?php

declare(strict_types=1);

namespace App\Services\Predb\Irc;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

/**
 * Resolves the pre IRC networks in `irc_settings.networks` into connection profiles.
 *
 * The "synirc" network keeps reading the original top-level scrape_irc_* settings so existing
 * installs behave as before.
 */
final class IrcNetworks
{
    public const LEGACY = 'synirc';

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_irc_networks_filter_enabled_nul(const char *keys_nul, size_t keys_len, const char *flags, size_t count, int64_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_irc_networks_parse_legacy_channels(const char *input, size_t input_len, int64_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_irc_networks_resolve_profile(const char *key, size_t key_len, uint32_t has_type, const char *net_type, size_t net_type_len, uint32_t has_format, const char *format, size_t format_len, uint32_t has_source, const char *source, size_t source_len, uint32_t has_server, const char *server, size_t server_len, uint32_t has_port, int64_t port, uint32_t has_tls, uint32_t tls, uint32_t has_tls_verify_peer_name, uint32_t tls_verify_peer_name, uint32_t has_url, const char *url, size_t url_len, const char *channels_raw, size_t channels_len, size_t channels_count, int64_t *out_port, int32_t *out_flags, int64_t *out_channel_count, char *out_buf, size_t out_cap);
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
     * @param  array<int|string, mixed>|null  $networks
     * @return list<string>
     */
    public static function enabled(?array $networks = null): array
    {
        return self::$useRustFfi
            ? self::enabledNative($networks)
            : self::enabledPhp($networks);
    }

    /**
     * @param  array<int|string, mixed>|null  $networks
     * @return list<string>
     */
    public static function enabledPhp(?array $networks = null): array
    {
        $networks ??= (array) config('irc_settings.networks', []);
        $enabled = [];

        foreach ($networks as $key => $network) {
            if (is_array($network) && ($network['enabled'] ?? false)) {
                $enabled[] = (string) $key;
            }
        }

        return $enabled;
    }

    /**
     * @param  array<int|string, mixed>|null  $networks
     * @return list<string>
     */
    public static function enabledNative(?array $networks = null): array
    {
        $networks ??= (array) config('irc_settings.networks', []);
        if ($networks === []) {
            return [];
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::enabledPhp($networks);
        }

        $keys = [];
        $flags = '';
        foreach ($networks as $key => $network) {
            $keyStr = (string) $key;
            if (str_contains($keyStr, "\0")) {
                return self::enabledPhp($networks);
            }
            $keys[] = $keyStr;
            $flags .= (is_array($network) && ($network['enabled'] ?? false)) ? '1' : '0';
        }

        $count = \count($keys);
        $joined = implode("\0", $keys);
        $joinedLen = \strlen($joined);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $joinedLen + 32), $cap);
        if ($scratch === null) {
            return self::enabledPhp($networks);
        }

        $outCount = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_irc_networks_filter_enabled_nul(
            $joined,
            $joinedLen,
            $flags,
            $count,
            $outCount,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return self::enabledPhp($networks);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_irc_networks_filter_enabled_nul(
                $joined,
                $joinedLen,
                $flags,
                $count,
                $outCount,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written < 0) {
            return self::enabledPhp($networks);
        }

        /** @var int $keptCount */
        $keptCount = $outCount[0];
        if ($keptCount === 0) {
            return [];
        }

        $parts = explode("\0", $written === 0 ? '' : FFI::string($scratch, $written));

        return \count($parts) === $keptCount ? $parts : self::enabledPhp($networks);
    }

    /**
     * @return array{name: string, type: string, format: string, source: ?string, server: string, port: int, tls: bool, tls_verify_peer_name: ?bool, channels: array<string, ?string>, url: ?string}
     */
    public static function resolve(string $key): array
    {
        return self::$useRustFfi
            ? self::resolveNative($key)
            : self::resolvePhp($key);
    }

    /**
     * @return array{name: string, type: string, format: string, source: ?string, server: string, port: int, tls: bool, tls_verify_peer_name: ?bool, channels: array<string, ?string>, url: ?string}
     */
    public static function resolvePhp(string $key): array
    {
        $network = config("irc_settings.networks.{$key}");
        if (! is_array($network)) {
            throw new InvalidArgumentException("Unknown IRC network [{$key}]. Configured: ".implode(', ', array_keys((array) config('irc_settings.networks', []))));
        }

        if ($key === self::LEGACY) {
            return [
                'name' => $key,
                'type' => 'irc',
                'format' => 'nntmux',
                'source' => null,
                'server' => (string) config('irc_settings.scrape_irc_server'),
                'port' => (int) config('irc_settings.scrape_irc_port'),
                'tls' => (bool) config('irc_settings.scrape_irc_tls'),
                'tls_verify_peer_name' => null,
                'channels' => self::legacyChannelsPhp(),
                'url' => null,
            ];
        }

        $channels = [];
        foreach ((array) ($network['channels'] ?? []) as $channel => $password) {
            $channels[(string) $channel] = $password === null || $password === '' ? null : (string) $password;
        }

        return [
            'name' => $key,
            'type' => (string) ($network['type'] ?? 'irc'),
            'format' => (string) ($network['format'] ?? ''),
            'source' => isset($network['source']) ? (string) $network['source'] : $key,
            'server' => (string) ($network['server'] ?? ''),
            'port' => (int) ($network['port'] ?? 6667),
            'tls' => (bool) ($network['tls'] ?? false),
            'tls_verify_peer_name' => isset($network['tls_verify_peer_name']) ? (bool) $network['tls_verify_peer_name'] : null,
            'channels' => $channels,
            'url' => isset($network['url']) ? (string) $network['url'] : null,
        ];
    }

    /**
     * @return array{name: string, type: string, format: string, source: ?string, server: string, port: int, tls: bool, tls_verify_peer_name: ?bool, channels: array<string, ?string>, url: ?string}
     */
    public static function resolveNative(string $key): array
    {
        $network = config("irc_settings.networks.{$key}");
        if (! is_array($network)) {
            throw new InvalidArgumentException("Unknown IRC network [{$key}]. Configured: ".implode(', ', array_keys((array) config('irc_settings.networks', []))));
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::resolvePhp($key);
        }

        if ($key === self::LEGACY) {
            $server = (string) config('irc_settings.scrape_irc_server');
            $port = (int) config('irc_settings.scrape_irc_port');
            $tls = (bool) config('irc_settings.scrape_irc_tls');
            $configuredChannels = config('irc_settings.scrape_irc_channels');
            $rawChannels = $configuredChannels ? (string) $configuredChannels : '';

            return self::callResolveProfileFfi(
                $ffi,
                $key,
                false,
                '',
                false,
                '',
                false,
                '',
                true,
                $server,
                true,
                $port,
                true,
                $tls,
                false,
                false,
                false,
                '',
                $rawChannels,
                0,
            ) ?? self::resolvePhp($key);
        }

        $flatChannels = [];
        $channelCount = 0;
        foreach ((array) ($network['channels'] ?? []) as $channel => $password) {
            $chStr = (string) $channel;
            $pwStr = $password === null || $password === '' ? '' : (string) $password;
            if (str_contains($chStr, "\0") || str_contains($pwStr, "\0")) {
                return self::resolvePhp($key);
            }
            $flatChannels[] = $chStr;
            $flatChannels[] = $pwStr;
            $channelCount++;
        }

        $hasType = isset($network['type']);
        $typeStr = $hasType ? (string) $network['type'] : '';
        $hasFormat = isset($network['format']);
        $formatStr = $hasFormat ? (string) $network['format'] : '';
        $hasSource = isset($network['source']);
        $sourceStr = $hasSource ? (string) $network['source'] : '';
        $hasServer = isset($network['server']);
        $serverStr = $hasServer ? (string) $network['server'] : '';
        $hasPort = isset($network['port']);
        $portVal = $hasPort ? (int) $network['port'] : 6667;
        $hasTls = isset($network['tls']);
        $tlsVal = $hasTls && (bool) $network['tls'];
        $hasTlsVerify = isset($network['tls_verify_peer_name']);
        $tlsVerifyVal = $hasTlsVerify && (bool) $network['tls_verify_peer_name'];
        $hasUrl = isset($network['url']);
        $urlStr = $hasUrl ? (string) $network['url'] : '';

        return self::callResolveProfileFfi(
            $ffi,
            $key,
            $hasType,
            $typeStr,
            $hasFormat,
            $formatStr,
            $hasSource,
            $sourceStr,
            $hasServer,
            $serverStr,
            $hasPort,
            $portVal,
            $hasTls,
            $tlsVal,
            $hasTlsVerify,
            $tlsVerifyVal,
            $hasUrl,
            $urlStr,
            implode("\0", $flatChannels),
            $channelCount,
        ) ?? self::resolvePhp($key);
    }

    /**
     * @return array<string, ?string>
     */
    public static function legacyChannels(mixed $configured = null): array
    {
        $raw = \func_num_args() === 0 ? config('irc_settings.scrape_irc_channels') : $configured;

        return self::$useRustFfi
            ? self::legacyChannelsNative($raw)
            : self::legacyChannelsPhp($raw);
    }

    /**
     * @return array<string, ?string>
     */
    public static function legacyChannelsPhp(mixed $configured = null): array
    {
        $raw = \func_num_args() === 0 ? config('irc_settings.scrape_irc_channels') : $configured;

        try {
            $channels = $raw ? @unserialize((string) $raw, ['allowed_classes' => false]) : null;
        } catch (\ValueError) {
            $channels = null;
        }

        /** @var array<string, ?string> $result */
        $result = is_array($channels) && $channels !== [] ? $channels : ['#PreNNTmux' => null];

        return $result;
    }

    /**
     * @return array<string, ?string>
     */
    public static function legacyChannelsNative(mixed $configured = null): array
    {
        $raw = \func_num_args() === 0 ? config('irc_settings.scrape_irc_channels') : $configured;
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::legacyChannelsPhp($raw);
        }

        $input = $raw ? (string) $raw : '';
        $len = \strlen($input);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 64), $cap);
        if ($scratch === null) {
            return self::legacyChannelsPhp($raw);
        }

        $outCount = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_irc_networks_parse_legacy_channels(
            $input,
            $len,
            $outCount,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return self::legacyChannelsPhp($raw);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_irc_networks_parse_legacy_channels(
                $input,
                $len,
                $outCount,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written <= 0) {
            return self::legacyChannelsPhp($raw);
        }

        /** @var int $pairCount */
        $pairCount = $outCount[0];
        if ($pairCount <= 0) {
            return self::legacyChannelsPhp($raw);
        }

        $tokens = explode("\0", FFI::string($scratch, $written));
        if (\count($tokens) !== $pairCount * 2) {
            return self::legacyChannelsPhp($raw);
        }

        $channels = [];
        for ($i = 0; $i < $pairCount; $i++) {
            $ch = $tokens[$i * 2];
            $pwTok = $tokens[$i * 2 + 1];
            $channels[$ch] = $pwTok === '0' ? null : substr($pwTok, 1);
        }

        return $channels;
    }

    /**
     * @return array{name: string, type: string, format: string, source: ?string, server: string, port: int, tls: bool, tls_verify_peer_name: ?bool, channels: array<string, ?string>, url: ?string}|null
     */
    private static function callResolveProfileFfi(
        FFI $ffi,
        string $key,
        bool $hasType,
        string $typeStr,
        bool $hasFormat,
        string $formatStr,
        bool $hasSource,
        string $sourceStr,
        bool $hasServer,
        string $serverStr,
        bool $hasPort,
        int $portVal,
        bool $hasTls,
        bool $tlsVal,
        bool $hasTlsVerify,
        bool $tlsVerifyVal,
        bool $hasUrl,
        string $urlStr,
        string $channelsRaw,
        int $channelsCount,
    ): ?array {
        $keyLen = \strlen($key);
        $typeLen = \strlen($typeStr);
        $formatLen = \strlen($formatStr);
        $sourceLen = \strlen($sourceStr);
        $serverLen = \strlen($serverStr);
        $urlLen = \strlen($urlStr);
        $chanLen = \strlen($channelsRaw);
        $needed = $keyLen + $typeLen + $formatLen + $sourceLen + $serverLen + $urlLen + $chanLen + 256;

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(512, $needed), $cap);
        if ($scratch === null) {
            return null;
        }
        $outPort = $ffi->new('int64_t[1]');
        $outFlags = $ffi->new('int32_t[1]');
        $outChanCount = $ffi->new('int64_t[1]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_irc_networks_resolve_profile(
            $key,
            $keyLen,
            $hasType ? 1 : 0,
            $typeStr,
            $typeLen,
            $hasFormat ? 1 : 0,
            $formatStr,
            $formatLen,
            $hasSource ? 1 : 0,
            $sourceStr,
            $sourceLen,
            $hasServer ? 1 : 0,
            $serverStr,
            $serverLen,
            $hasPort ? 1 : 0,
            $portVal,
            $hasTls ? 1 : 0,
            $tlsVal ? 1 : 0,
            $hasTlsVerify ? 1 : 0,
            $tlsVerifyVal ? 1 : 0,
            $hasUrl ? 1 : 0,
            $urlStr,
            $urlLen,
            $channelsRaw,
            $chanLen,
            $channelsCount,
            $outPort,
            $outFlags,
            $outChanCount,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_irc_networks_resolve_profile(
                $key,
                $keyLen,
                $hasType ? 1 : 0,
                $typeStr,
                $typeLen,
                $hasFormat ? 1 : 0,
                $formatStr,
                $formatLen,
                $hasSource ? 1 : 0,
                $sourceStr,
                $sourceLen,
                $hasServer ? 1 : 0,
                $serverStr,
                $serverLen,
                $hasPort ? 1 : 0,
                $portVal,
                $hasTls ? 1 : 0,
                $tlsVal ? 1 : 0,
                $hasTlsVerify ? 1 : 0,
                $tlsVerifyVal ? 1 : 0,
                $hasUrl ? 1 : 0,
                $urlStr,
                $urlLen,
                $channelsRaw,
                $chanLen,
                $channelsCount,
                $outPort,
                $outFlags,
                $outChanCount,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written <= 0) {
            return null;
        }

        /** @var int $resolvedPort */
        $resolvedPort = $outPort[0];
        /** @var int $flags */
        $flags = $outFlags[0];
        /** @var int $resolvedChanCount */
        $resolvedChanCount = $outChanCount[0];

        $segs = explode("\0", FFI::string($scratch, $written));
        if (\count($segs) !== 6 + $resolvedChanCount * 2) {
            return null;
        }

        $channels = [];
        for ($i = 0; $i < $resolvedChanCount; $i++) {
            $ch = $segs[6 + $i * 2];
            $pwTok = $segs[6 + $i * 2 + 1];
            $channels[$ch] = $pwTok === '0' ? null : substr($pwTok, 1);
        }

        $verifyState = ($flags >> 1) & 0x3;
        $tlsVerifyPeerName = match ($verifyState) {
            1 => false,
            2 => true,
            default => null,
        };

        return [
            'name' => $segs[0],
            'type' => $segs[1],
            'format' => $segs[2],
            'source' => ($flags & 8) !== 0 ? $segs[3] : null,
            'server' => $segs[4],
            'port' => $resolvedPort,
            'tls' => ($flags & 1) !== 0,
            'tls_verify_peer_name' => $tlsVerifyPeerName,
            'channels' => $channels,
            'url' => ($flags & 16) !== 0 ? $segs[5] : null,
        ];
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W47 parity test). */
    public const bool W47_ENDPOINT_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_irc_endpoint_route_batch(const char *input, size_t len, size_t count, char *out, size_t cap);
        C;

    private const string W47_HOSTNAME = '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D';

    private static ?bool $w47EndpointRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark default; setUseRustFfi() is untouched. */
    public static function useW47EndpointRustFfi(?bool $enabled = true): void
    {
        self::$w47EndpointRustFfi = $enabled;
    }

    public static function usesW47EndpointRustFfi(): bool
    {
        return self::$w47EndpointRustFfi ?? self::W47_ENDPOINT_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW47EndpointRustFfi(): void
    {
        self::$w47EndpointRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Routes `[irc[s]://]host[:[+]port][/#chan,...]` endpoint specs, two ints per spec: a negative
     * reason (-1 empty, -2 host, -3 port, -4 bracketed IPv6) and 0, or
     * `1 | tls << 1 | kind << 2 | port << 8 | valid channels << 24 | invalid channels << 32`
     * (kind 1 hostname, 2 IPv4, 3 IPv6; counts capped at 255) and the CRC32 of
     * `strtolower(host).':'.port`. A `+` port prefix or `ircs://` selects TLS (default port 6697).
     *
     * @param  list<string>  $endpoints
     * @return list<int>
     */
    public static function routeEndpoints(array $endpoints): array
    {
        return self::usesW47EndpointRustFfi() ? self::routeEndpointsNative($endpoints) : self::routeEndpointsPhp($endpoints);
    }

    /**
     * @param  list<string>  $endpoints
     * @return list<int>
     */
    public static function routeEndpointsPhp(array $endpoints): array
    {
        $out = [];
        foreach ($endpoints as $spec) {
            [$out[], $out[]] = self::w47RouteEndpoint($spec);
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a spec holds a NUL byte.
     *
     * @param  list<string>  $endpoints
     * @return list<int>
     */
    public static function routeEndpointsNative(array $endpoints): array
    {
        $count = \count($endpoints);
        if ($count === 0) {
            return [];
        }
        $ffi = self::$w47Ffi instanceof FFI ? self::$w47Ffi : self::w47Ffi();
        $joined = implode("\0", $endpoints);
        if ($ffi === null || substr_count($joined, "\0") !== $count - 1) {
            return self::routeEndpointsPhp($endpoints);
        }
        $bytes = $count * 16;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::routeEndpointsPhp($endpoints);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_irc_endpoint_route_batch($joined, \strlen($joined), $count, $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::routeEndpointsPhp($endpoints);
    }

    private static function w47Ffi(): ?FFI
    {
        self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w47', self::W47_FFI_CDEF) ?? false;

        return self::$w47Ffi === false ? null : self::$w47Ffi;
    }

    /**
     * @return array{int, int}
     */
    private static function w47RouteEndpoint(string $spec): array
    {
        $spec = trim($spec);
        if ($spec === '') {
            return [-1, 0];
        }
        $tls = false;
        if (strncasecmp($spec, 'ircs://', 7) === 0) {
            $tls = true;
            $spec = substr($spec, 7);
        } elseif (strncasecmp($spec, 'irc://', 6) === 0) {
            $spec = substr($spec, 6);
        }
        $slash = strpos($spec, '/');
        $authority = $slash === false ? $spec : substr($spec, 0, $slash);
        $path = $slash === false ? '' : substr($spec, $slash + 1);
        if ($authority !== '' && $authority[0] === '[') {
            $close = strpos($authority, ']');
            if ($close === false) {
                return [-4, 0];
            }
            $host = substr($authority, 1, $close - 1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return [-4, 0];
            }
            $rest = substr($authority, $close + 1);
            if ($rest === '') {
                $portText = null;
            } elseif ($rest[0] === ':') {
                $portText = substr($rest, 1);
            } else {
                return [-3, 0];
            }
            $kind = 3;
        } else {
            $colon = strpos($authority, ':');
            $host = $colon === false ? $authority : substr($authority, 0, $colon);
            $portText = $colon === false ? null : substr($authority, $colon + 1);
            if ($host === '') {
                return [-2, 0];
            }
            if (strspn($host, '0123456789.') === \strlen($host)) {
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    return [-2, 0];
                }
                $kind = 2;
            } elseif (preg_match(self::W47_HOSTNAME, $host) === 1) {
                $kind = 1;
            } else {
                return [-2, 0];
            }
        }
        if ($portText === null) {
            $port = $tls ? 6697 : 6667;
        } else {
            if ($portText !== '' && $portText[0] === '+') {
                $tls = true;
                $portText = substr($portText, 1);
            }
            if ($portText === '' || \strlen($portText) > 5 || ! ctype_digit($portText)) {
                return [-3, 0];
            }
            $port = (int) $portText;
            if ($port < 1 || $port > 65535) {
                return [-3, 0];
            }
        }
        $valid = $invalid = 0;
        if ($path !== '') {
            foreach (explode(',', $path) as $channel) {
                if ($channel === '') {
                    continue;
                }
                $length = \strlen($channel);
                if (($channel[0] === '#' || $channel[0] === '&') && $length >= 2 && $length <= 50 && strcspn($channel, " \x07\r\n\t") === $length) {
                    $valid++;
                } else {
                    $invalid++;
                }
            }
        }

        return [
            1 | ($tls ? 2 : 0) | $kind << 2 | $port << 8 | min($valid, 255) << 24 | min($invalid, 255) << 32,
            crc32(strtolower($host).':'.$port),
        ];
    }

    // ========================================================================
    // WAVE 61.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.67x, p99 5.14x; uncommon mean 7.23x, p99 5.43x); see RustPredbFeedEntryImporterSourceFactoryIrcNetworksParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    public const string W61_IRC_NETWORKS_FFI_CDEF = <<<'C'
        ssize_t nntmux_w613_irc_networks_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 61.3 batch. */
    public const int W61_IRC_NETWORKS_TRAILER = 34;

    /** Bot formats a network may name; positions plus one are the w61NetworkPlanBatch() format codes. */
    public const array W61_FORMATS = ['corruptnet', 'zenet', 'predatabase', 'ngp', 'rizon', 'opentrackers', 'nntmux'];

    private const string W61_NAME_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_-';

    private const string W61_LABEL_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-';

    public static function usesW61RustFfi(): bool
    {
        $override = self::w61RustFfiState();

        return \is_bool($override) ? $override : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the IrcNetworks Wave 61 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 61.3 network plans. Each item is `name|host|port|tls|channels|format`, every field trimmed. Per item
     * `nameState | legacy << 2 | duplicate << 3 | hostKind << 4 | portState << 6 | port << 8 | tlsState << 24 |
     * tls << 26 | format << 27 | route << 31 | min(validChannels, 255) << 34 | min(invalidChannels, 255) << 42 |
     * min(hostLength, 255) << 50`: the name is 0 empty, 1 up to 32 `[a-z0-9_-]` bytes after lower-casing (legacy
     * when it is LEGACY, duplicate when an earlier valid name matched) or 2 invalid; the host is 0 empty, 1 a
     * hostname, 2 a dotted IPv4 address (no leading zeros) or 3 invalid; tls is a boolean token (w61Flag()) and a
     * `+` port prefix forces it; the port is 0 empty (6697 with TLS, else 6667), 1 a 1-5 digit port in 1-65535 or
     * 2 malformed (0); channels are comma-separated, trimmed, and valid when they start with `#` or `&`, hold 2-50
     * bytes and no blank, BEL, CR or LF; the format is 0 empty, 1-7 a W61_FORMATS entry or 8 unknown. The route
     * is 0 unroutable (invalid name, host or port), 1 idle (no valid channel), 2 the legacy bot (LEGACY or
     * nntmux), 3 the relay bot (opentrackers), 4 a scene pre bot and 5 an unknown format. Then the name, host,
     * port, TLS, format and route counts, the TLS networks, the valid and invalid channel sums, the duplicates,
     * the distinct valid hosts (case-insensitive) and the legacy count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61NetworkPlanBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::w61NetworkPlanBatchNative($items) : self::w61NetworkPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61NetworkPlanBatchNative(array $items): array
    {
        return self::w61Invoke($items, \count($items) + self::W61_IRC_NETWORKS_TRAILER) ?? self::w61NetworkPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61NetworkPlanBatchPhp(array $items): array
    {
        $out = [];
        $nameStates = $portStates = $tlsStates = [0, 0, 0];
        $hostKinds = [0, 0, 0, 0];
        $formats = array_fill(0, 9, 0);
        $routes = [0, 0, 0, 0, 0, 0];
        $tlsOn = $validSum = $invalidSum = $duplicates = $legacySum = 0;
        $names = $hosts = [];
        foreach ($items as $item) {
            [$rawName, $rawHost, $rawPort, $rawTls, $rawChannels, $rawFormat] = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $name = strtolower(trim($rawName));
            $nameLength = \strlen($name);
            $nameState = $nameLength === 0 ? 0 : ($nameLength <= 32 && strspn($name, self::W61_NAME_CHARS) === $nameLength ? 1 : 2);
            $legacy = $name === self::LEGACY ? 1 : 0;
            $duplicate = 0;
            if ($nameState === 1) {
                if (isset($names[$name])) {
                    $duplicate = 1;
                } else {
                    $names[$name] = true;
                }
            }
            $host = trim($rawHost);
            $kind = self::w61HostKind($host);
            [$tlsState, $tls] = match (strtolower(trim($rawTls))) {
                '' => [0, 0],
                '1', 'true', 'yes', 'on' => [1, 1],
                '0', 'false', 'no', 'off' => [1, 0],
                default => [2, 0],
            };
            $portText = trim($rawPort);
            $plus = $portText !== '' && $portText[0] === '+';
            if ($plus) {
                $tls = 1;
                $portText = substr($portText, 1);
            }
            $portLength = \strlen($portText);
            if ($portLength === 0 && ! $plus) {
                $portState = 0;
                $port = $tls === 1 ? 6697 : 6667;
            } else {
                $port = $portLength >= 1 && $portLength <= 5 && strspn($portText, '0123456789') === $portLength ? (int) $portText : 0;
                $portState = $port >= 1 && $port <= 65535 ? 1 : 2;
                if ($portState === 2) {
                    $port = 0;
                }
            }
            $valid = $invalid = 0;
            $channels = trim($rawChannels);
            if ($channels !== '') {
                foreach (explode(',', $channels) as $channel) {
                    $channel = trim($channel);
                    $channelLength = \strlen($channel);
                    if ($channelLength === 0) {
                        continue;
                    }
                    if (($channel[0] === '#' || $channel[0] === '&') && $channelLength >= 2 && $channelLength <= 50 && strcspn($channel, " \x07\r\n\t") === $channelLength) {
                        $valid++;
                    } else {
                        $invalid++;
                    }
                }
            }
            $formatName = strtolower(trim($rawFormat));
            $position = $formatName === '' ? false : array_search($formatName, self::W61_FORMATS, true);
            $format = $formatName === '' ? 0 : ($position === false ? 8 : $position + 1);
            $route = match (true) {
                $nameState !== 1 || ($kind !== 1 && $kind !== 2) || $portState === 2 => 0,
                $valid === 0 => 1,
                $legacy === 1 || $format === 7 => 2,
                $format === 6 => 3,
                $format >= 1 && $format <= 5 => 4,
                default => 5,
            };
            if ($kind === 1 || $kind === 2) {
                $hosts[strtolower($host)] = true;
            }
            $nameStates[$nameState]++;
            $hostKinds[$kind]++;
            $portStates[$portState]++;
            $tlsStates[$tlsState]++;
            $formats[$format]++;
            $routes[$route]++;
            $tlsOn += $tls;
            $validSum += $valid;
            $invalidSum += $invalid;
            $duplicates += $duplicate;
            $legacySum += $legacy;
            $out[] = $nameState | $legacy << 2 | $duplicate << 3 | $kind << 4 | $portState << 6 | $port << 8 | $tlsState << 24 | $tls << 26
                | $format << 27 | $route << 31 | min($valid, 255) << 34 | min($invalid, 255) << 42 | min(\strlen($host), 255) << 50;
        }
        array_push($out, ...$nameStates, ...$hostKinds, ...$portStates, ...$tlsStates, ...$formats, ...$routes);
        array_push($out, $tlsOn, $validSum, $invalidSum, $duplicates, \count($hosts), $legacySum);

        return $out;
    }

    /**
     * 0 empty, 1 hostname (labels of 1-63 alphanumerics and inner hyphens, at most 253 bytes), 2 dotted IPv4, 3 invalid.
     */
    private static function w61HostKind(string $host): int
    {
        $length = \strlen($host);
        if ($length === 0) {
            return 0;
        }
        if (strspn($host, '0123456789.') === $length) {
            $parts = explode('.', $host);
            if (\count($parts) !== 4) {
                return 3;
            }
            foreach ($parts as $part) {
                $partLength = \strlen($part);
                if ($partLength === 0 || $partLength > 3 || ($partLength > 1 && $part[0] === '0') || (int) $part > 255) {
                    return 3;
                }
            }

            return 2;
        }
        if ($length > 253) {
            return 3;
        }
        foreach (explode('.', $host) as $label) {
            $labelLength = \strlen($label);
            if ($labelLength === 0 || $labelLength > 63 || $label[0] === '-' || $label[-1] === '-' || strspn($label, self::W61_LABEL_CHARS) !== $labelLength) {
                return 3;
            }
        }

        return 1;
    }

    /**
     * Wave 61 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('predb_w613', self::W61_IRC_NETWORKS_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w61RustFfiState('ffi');
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
                $handle = self::w61RustFfiState('ffi');
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
     * Calls the Wave 61.3 IrcNetworks export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w61Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w61RustFfiState('ffi');
            $out = self::w61RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w61RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W61_IRC_NETWORKS_FFI_CDEF.)
            $written = $ffi->nntmux_w613_irc_networks_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
