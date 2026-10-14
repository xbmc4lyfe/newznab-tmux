<?php

declare(strict_types=1);

namespace App\Services\Predb\Irc;

use InvalidArgumentException;

/**
 * Resolves the pre IRC networks in `irc_settings.networks` into connection profiles.
 *
 * The "synirc" network keeps reading the original top-level scrape_irc_* settings so existing
 * installs behave as before.
 */
final class IrcNetworks
{
    public const LEGACY = 'synirc';

    /**
     * @return list<string>
     */
    public static function enabled(): array
    {
        $enabled = [];

        foreach ((array) config('irc_settings.networks', []) as $key => $network) {
            if (is_array($network) && ($network['enabled'] ?? false)) {
                $enabled[] = (string) $key;
            }
        }

        return $enabled;
    }

    /**
     * @return array{name: string, type: string, format: string, source: ?string, server: string, port: int, tls: bool, tls_verify_peer_name: ?bool, channels: array<string, ?string>, url: ?string}
     */
    public static function resolve(string $key): array
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
                'channels' => self::legacyChannels(),
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
     * @return array<string, ?string>
     */
    private static function legacyChannels(): array
    {
        $configured = config('irc_settings.scrape_irc_channels');

        try {
            $channels = $configured ? unserialize((string) $configured, ['allowed_classes' => false]) : null;
        } catch (\ValueError) {
            $channels = null;
        }

        return is_array($channels) && $channels !== [] ? $channels : ['#PreNNTmux' => null];
    }
}
