<?php

declare(strict_types=1);

namespace App\Services\Predb\Irc;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
