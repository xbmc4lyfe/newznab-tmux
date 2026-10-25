<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Manticoresearch\Client as ManticoreClient;
use Throwable;

final class ManticoreClientFactory
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_manticore_cfg_normalize_hosts(const char *input, size_t len, char *out, size_t out_cap);
        int32_t nntmux_manticore_cfg_parse_host(const char *input, size_t len, int64_t *out);
        C;

    private static FFI|false|null $factoryFfi = null;

    private static ?CData $hostFields = null;

    private static ?bool $useRustFfi = null;

    private static bool $adoptNormalizeHostsFfi = false;

    private static bool $adoptParseHostFfi = false;

    /**
     * Rust stays opt-in for the Wave 42.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchOutboxElasticManticoreSupportW42ParityTest.php.
     */
    public const bool W425_RUST_FFI_DEFAULT = false;

    private static ?bool $useW425RustFfi = null;

    /**
     * Build a Manticore PHP client from app config.
     *
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config): ManticoreClient
    {
        return new ManticoreClient(self::clientConfig($config));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function clientConfig(array $config): array
    {
        $retries = (int) ($config['retries'] ?? 2);
        $hosts = self::normalizeHosts($config['hosts'] ?? '');

        if ($hosts !== []) {
            return [
                'connections' => array_map(
                    static fn (string|array $host): array => self::connectionConfig($config, $host),
                    $hosts,
                ),
                'retries' => max(1, $retries),
            ];
        }

        return self::connectionConfig($config);
    }

    /**
     * Build Guzzle options for raw HTTP calls to Manticore.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function guzzleOptions(array $config, array $options = []): array
    {
        $headers = $options['headers'] ?? [];
        unset($options['headers']);

        $token = self::stringOrNull($config['token'] ?? $config['bearer_token'] ?? null);
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        } else {
            $username = self::stringOrNull($config['username'] ?? null);
            $password = self::stringOrNull($config['password'] ?? null);
            if ($username !== null && $password !== null) {
                $options['auth'] = [$username, $password];
            }
        }

        if ($headers !== []) {
            $options['headers'] = $headers;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  string|array<string, mixed>|null  $hostOverride
     * @return array<string, mixed>
     */
    private static function connectionConfig(array $config, string|array|null $hostOverride = null): array
    {
        $connection = self::parseHost($config, $hostOverride);
        $scheme = strtolower((string) ($connection['scheme'] ?? $config['scheme'] ?? 'http'));

        if ($scheme === 'https') {
            $connection['transport'] = 'Https';
        }

        foreach (['timeout', 'connect_timeout', 'proxy', 'persistent'] as $key) {
            if (array_key_exists($key, $config)) {
                $connection[$key] = $config[$key];
            }
        }

        $token = self::stringOrNull($config['token'] ?? $config['bearer_token'] ?? null);
        if ($token !== null) {
            $connection['headers'] = self::headersForManticoreClient($config['headers'] ?? [], $token);

            return $connection;
        }

        $username = self::stringOrNull($config['username'] ?? null);
        $password = self::stringOrNull($config['password'] ?? null);
        if ($username !== null && $password !== null) {
            $connection['username'] = $username;
            $connection['password'] = $password;
        }

        if (! empty($config['headers'])) {
            $connection['headers'] = self::headersForManticoreClient($config['headers']);
        }

        return $connection;
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::$adoptNormalizeHostsFfi;
    }

    public static function factoryFfi(): ?FFI
    {
        return self::resolveFactoryFfi();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  string|array<string, mixed>|null  $hostOverride
     * @return array<string, mixed>
     */
    public static function parseHost(array $config, string|array|null $hostOverride): array
    {
        if (self::$useRustFfi ?? self::$adoptParseHostFfi) {
            $supported = false;
            $native = self::parseHostNative($config, $hostOverride, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::parseHostPhp($config, $hostOverride);
    }

    /**
     * Handles plain `[scheme://]host[:port][/path]` strings; anything else defers to
     * {@see parseHostPhp()} and its `parse_url()` semantics.
     *
     * @param  array<string, mixed>  $config
     * @param  string|array<string, mixed>|null  $hostOverride
     *
     * @param-out bool $supported
     *
     * @return array<string, mixed>|null
     */
    public static function parseHostNative(array $config, string|array|null $hostOverride, bool &$supported = false): ?array
    {
        $supported = false;
        if (self::$useRustFfi === false || \is_array($hostOverride)) {
            return null;
        }
        $ffi = self::resolveFactoryFfi();
        if ($ffi === null || self::$hostFields === null) {
            return null;
        }

        $host = $hostOverride ?? (string) ($config['host'] ?? '127.0.0.1');
        $defaultPort = (int) ($config['port'] ?? 9308);
        $defaultScheme = (string) ($config['scheme'] ?? 'http');

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_manticore_cfg_parse_host($host, \strlen($host), self::$hostFields);
        } catch (Throwable) {
            return null;
        }
        if ($rc !== 1) {
            return null;
        }
        $fields = self::$hostFields;
        $f = [];
        for ($i = 0; $i < 7; $i++) {
            $f[] = (int) $fields[$i];
        }
        $supported = true;

        $connection = [
            'host' => substr($host, $f[2], $f[3]),
            'port' => $f[4] >= 0 ? $f[4] : $defaultPort,
            'scheme' => $f[1] >= 0 ? substr($host, $f[0], $f[1]) : $defaultScheme,
        ];
        if ($f[6] > 1) {
            $connection['path'] = substr($host, $f[5], $f[6]);
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  string|array<string, mixed>|null  $hostOverride
     * @return array<string, mixed>
     */
    public static function parseHostPhp(array $config, string|array|null $hostOverride): array
    {
        if (\is_array($hostOverride)) {
            return $hostOverride + [
                'host' => (string) ($config['host'] ?? '127.0.0.1'),
                'port' => (int) ($config['port'] ?? 9308),
            ];
        }

        $host = $hostOverride ?? (string) ($config['host'] ?? '127.0.0.1');
        $defaultPort = (int) ($config['port'] ?? 9308);
        $defaultScheme = (string) ($config['scheme'] ?? 'http');

        $parseTarget = str_contains($host, '://') ? $host : '//'.$host;
        $parts = parse_url($parseTarget);

        if ($parts === false || empty($parts['host'])) {
            return [
                'host' => $host,
                'port' => $defaultPort,
                'scheme' => $defaultScheme,
            ];
        }

        $connection = [
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : $defaultPort,
            'scheme' => $parts['scheme'] ?? $defaultScheme,
        ];

        if (! empty($parts['path']) && $parts['path'] !== '/') {
            $connection['path'] = $parts['path'];
        }

        return $connection;
    }

    /**
     * @return list<string|array<string, mixed>>
     */
    public static function normalizeHosts(mixed $hosts): array
    {
        if (self::$useRustFfi ?? self::$adoptNormalizeHostsFfi) {
            $supported = false;
            $native = self::normalizeHostsNative($hosts, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::normalizeHostsPhp($hosts);
    }

    /**
     * String host lists only; arrays and other types defer to {@see normalizeHostsPhp()}.
     *
     * @param-out bool $supported
     *
     * @return list<string>|null
     */
    public static function normalizeHostsNative(mixed $hosts, bool &$supported = false): ?array
    {
        $supported = false;
        if (self::$useRustFfi === false || ! \is_string($hosts)) {
            return null;
        }
        $ffi = self::resolveFactoryFfi();
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($hosts);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $len + 1, $capacity);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_manticore_cfg_normalize_hosts($hosts, $len, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! \is_int($written) || $written < 0) {
            return null;
        }
        $supported = true;

        return $written === 0 ? [] : explode(',', FFI::string($scratch, $written));
    }

    /**
     * @return list<string|array<string, mixed>>
     */
    public static function normalizeHostsPhp(mixed $hosts): array
    {
        if (\is_array($hosts)) {
            return array_values(array_filter($hosts));
        }

        if (! \is_string($hosts) || trim($hosts) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $hosts))));
    }

    public static function useW425RustFfi(bool $enable = true): void
    {
        self::$useW425RustFfi = $enable;
    }

    public static function usesW425RustFfi(): bool
    {
        return self::$useW425RustFfi ?? self::W425_RUST_FFI_DEFAULT;
    }

    public static function resetW425RustFfi(): void
    {
        self::$useW425RustFfi = null;
    }

    /**
     * Summarizes host lists as {@see normalizeHostsPhp()} returns them. Lists that are neither strings
     * nor arrays are invalid; `empty_lists` counts valid lists without hosts. `https_hosts` and
     * `distinct_hosts` cover string hosts (ASCII case-insensitive `https://` prefix, exact bytes).
     *
     * @param  array<array-key, mixed>  $hostLists
     * @return array{lists: int, invalid: int, empty_lists: int, hosts: int, structured_hosts: int, https_hosts: int, distinct_hosts: int, max_hosts_per_list: int}
     */
    public static function summarizeHostListBatch(array $hostLists): array
    {
        return self::usesW425RustFfi() ? self::summarizeHostListBatchNative($hostLists) : self::summarizeHostListBatchPhp($hostLists);
    }

    /**
     * @param  array<array-key, mixed>  $hostLists
     * @return array{lists: int, invalid: int, empty_lists: int, hosts: int, structured_hosts: int, https_hosts: int, distinct_hosts: int, max_hosts_per_list: int}
     */
    public static function summarizeHostListBatchPhp(array $hostLists): array
    {
        $invalid = 0;
        $empty = 0;
        $hosts = 0;
        $structured = 0;
        $https = 0;
        $widest = 0;
        $distinct = [];
        foreach ($hostLists as $list) {
            if (! \is_string($list) && ! \is_array($list)) {
                $invalid++;

                continue;
            }
            $normalized = self::normalizeHostsPhp($list);
            $found = \count($normalized);
            foreach ($normalized as $host) {
                if (\is_string($host)) {
                    $https += (int) (strncasecmp($host, 'https://', 8) === 0);
                    $distinct[$host] = true;
                } elseif (\is_array($host)) {
                    $structured++;
                }
            }
            $hosts += $found;
            $empty += (int) ($found === 0);
            $widest = max($widest, $found);
        }

        return [
            'lists' => \count($hostLists),
            'invalid' => $invalid,
            'empty_lists' => $empty,
            'hosts' => $hosts,
            'structured_hosts' => $structured,
            'https_hosts' => $https,
            'distinct_hosts' => \count($distinct),
            'max_hosts_per_list' => $widest,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $hostLists
     * @return array{lists: int, invalid: int, empty_lists: int, hosts: int, structured_hosts: int, https_hosts: int, distinct_hosts: int, max_hosts_per_list: int}
     */
    public static function summarizeHostListBatchNative(array $hostLists): array
    {
        $parts = [];
        foreach ($hostLists as $list) {
            if (\is_string($list)) {
                array_push($parts, '1', $list);

                continue;
            }
            if (! \is_array($list)) {
                $parts[] = '0';

                continue;
            }
            $items = array_filter($list);
            array_push($parts, '2', (string) \count($items));
            foreach ($items as $item) {
                $parts[] = match (true) {
                    \is_string($item) => 's'.$item,
                    \is_array($item) => 'a',
                    default => 'o',
                };
            }
        }
        $fields = ElasticsearchResponseHelper::evaluateW425Native(4, $parts);
        if ($fields === null || \count($fields) !== 8) {
            return self::summarizeHostListBatchPhp($hostLists);
        }

        return [
            'lists' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'empty_lists' => (int) $fields[2],
            'hosts' => (int) $fields[3],
            'structured_hosts' => (int) $fields[4],
            'https_hosts' => (int) $fields[5],
            'distinct_hosts' => (int) $fields[6],
            'max_hosts_per_list' => (int) $fields[7],
        ];
    }

    /**
     * @return list<string>
     */
    private static function headersForManticoreClient(mixed $headers, ?string $bearerToken = null): array
    {
        $normalized = [];

        if (\is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (\is_int($key)) {
                    $header = self::stringOrNull($value);
                    if ($header !== null) {
                        $normalized[] = $header;
                    }

                    continue;
                }

                $headerValue = self::stringOrNull($value);
                if ($headerValue !== null) {
                    $normalized[] = $key.': '.$headerValue;
                }
            }
        }

        if ($bearerToken !== null) {
            $normalized[] = 'Authorization: Bearer '.$bearerToken;
        }

        return $normalized;
    }

    private static function resolveFactoryFfi(): ?FFI
    {
        if (self::$factoryFfi !== null) {
            return self::$factoryFfi === false ? null : self::$factoryFfi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        if ($ffi === null) {
            self::$factoryFfi = false;

            return null;
        }
        try {
            self::$hostFields = $ffi->new('int64_t[7]');
            self::$factoryFfi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$factoryFfi = false;

            return null;
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
