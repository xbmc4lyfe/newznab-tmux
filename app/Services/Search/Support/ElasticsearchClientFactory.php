<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Support\RustPipelineLibrary;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use FFI;
use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;
use Throwable;

final class ElasticsearchClientFactory
{
    /** Rust helpers are opt-in; neither beat PHP by 1.5x in the paired benchmark. */
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_es_build_hosts(const char *input, size_t len, const int64_t *lens, const uint32_t *flags, size_t count, char *out, size_t cap, int64_t *out_lens);
        int32_t nntmux_es_positive_seconds(const char *input, size_t len, double *out);
        C;

    private const array HOST_FIELDS = ['host', 'scheme', 'port', 'user', 'pass'];

    /**
     * Rust stays opt-in for the Wave 42.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchOutboxElasticManticoreSupportW42ParityTest.php.
     */
    public const bool W425_RUST_FFI_DEFAULT = false;

    private static ?bool $useW425RustFfi = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config): Client
    {
        $hosts = self::buildHosts($config['hosts'] ?? []);

        if ($hosts === []) {
            throw new RuntimeException('No Elasticsearch hosts configured');
        }

        $builder = ClientBuilder::create()
            ->setHosts($hosts)
            ->setRetries((int) ($config['retries'] ?? 2));

        $builder->setHttpClient(new GuzzleClient(self::buildHttpClientOptions($config)));

        return $builder->build();
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $configHosts
     * @return list<string>
     */
    public static function buildHosts(array $configHosts, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::buildHostsNative($configHosts) : null) ?? self::buildHostsPhp($configHosts);
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $configHosts
     * @return list<string>
     */
    public static function buildHostsPhp(array $configHosts): array
    {
        $hosts = [];

        foreach ($configHosts as $host) {
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = $host;

                continue;
            }

            if (! is_array($host)) {
                continue;
            }

            $hostname = trim((string) ($host['host'] ?? ''));
            if ($hostname === '') {
                continue;
            }

            $scheme = trim((string) ($host['scheme'] ?? 'http')) ?: 'http';
            $port = $host['port'] ?? null;
            $portSuffix = $port !== null && $port !== '' ? ':'.(string) $port : '';
            $credentials = '';

            if (! empty($host['user']) && ! empty($host['pass'])) {
                $credentials = rawurlencode((string) $host['user']).':'.rawurlencode((string) $host['pass']).'@';
            }

            $hosts[] = sprintf('%s://%s%s%s', $scheme, $credentials, $hostname, $portSuffix);
        }

        return $hosts;
    }

    /**
     * Rust URL assembly; null (PHP decides) when FFI is unavailable or a host field
     * is neither scalar nor null, so PHP keeps its string-conversion errors.
     *
     * @param  array<int, array<string, mixed>|string>  $configHosts
     * @return list<string>|null
     */
    public static function buildHostsNative(array $configHosts): ?array
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $fields = [];
        $flags = [];
        foreach ($configHosts as $host) {
            if (is_string($host)) {
                array_push($fields, $host, '', '', '', '');
                $flags[] = 4;

                continue;
            }
            if (! is_array($host)) {
                continue;
            }
            foreach (self::HOST_FIELDS as $key) {
                if (isset($host[$key]) && ! is_scalar($host[$key])) {
                    return null;
                }
            }
            $port = $host['port'] ?? null;
            $hasPort = $port !== null && $port !== '';
            $hasCredentials = ! empty($host['user']) && ! empty($host['pass']);
            array_push(
                $fields,
                (string) ($host['host'] ?? ''),
                (string) ($host['scheme'] ?? ''),
                $hasPort ? (string) $port : '',
                $hasCredentials ? (string) $host['user'] : '',
                $hasCredentials ? (string) $host['pass'] : '',
            );
            $flags[] = ($hasPort ? 1 : 0) | ($hasCredentials ? 2 : 0);
        }
        $count = count($flags);
        if ($count === 0) {
            return [];
        }
        static $lengths, $flagBuffer, $outLengths, $out, $capacity = 0, $outCapacity = 0;
        try {
            if ($capacity < $count) {
                $capacity = max(16, $count * 2);
                $lengths = $ffi->new('int64_t['.($capacity * 5).']');
                $flagBuffer = $ffi->new("uint32_t[{$capacity}]");
                $outLengths = $ffi->new("int64_t[{$capacity}]");
            }
            foreach ($fields as $i => $field) {
                $lengths[$i] = strlen($field);
            }
            foreach ($flags as $i => $flag) {
                $flagBuffer[$i] = $flag;
            }
            $joined = implode('', $fields);
            $needed = strlen($joined) * 3 + $count * 16;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                if ($outCapacity < $needed) {
                    $outCapacity = max(1024, $needed);
                    $out = $ffi->new("char[{$outCapacity}]");
                }
                $written = $ffi->nntmux_es_build_hosts($joined, strlen($joined), $lengths, $flagBuffer, $count, $out, $outCapacity, $outLengths);
                if (! is_int($written) || $written === -1 || $written === -2) {
                    return null;
                }
                if ($written >= 0) {
                    $bytes = $written === 0 ? '' : FFI::string($out, $written);
                    $hosts = [];
                    $offset = 0;
                    for ($i = 0; $i < $count; $i++) {
                        $length = (int) $outLengths[$i];
                        if ($length >= 0) {
                            $hosts[] = substr($bytes, $offset, $length);
                            $offset += $length;
                        }
                    }

                    return $hosts;
                }
                $needed = -$written - 3;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, int|float|string|bool>
     */
    public static function buildHttpClientOptions(array $config, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::buildHttpClientOptionsNative($config) : null) ?? self::buildHttpClientOptionsPhp($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, int|float|string|bool>
     */
    public static function buildHttpClientOptionsPhp(array $config): array
    {
        $options = [];

        $timeout = $config['timeout'] ?? null;
        if (is_numeric($timeout) && (float) $timeout > 0) {
            $options['timeout'] = (float) $timeout;
        }

        $connectTimeout = $config['connect_timeout'] ?? null;
        if (is_numeric($connectTimeout) && (float) $connectTimeout > 0) {
            $options['connect_timeout'] = (float) $connectTimeout;
        }

        return $options;
    }

    /**
     * Rust `is_numeric()`/`(float)` for string timeouts; int and float values stay in PHP.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, int|float|string|bool>|null
     */
    public static function buildHttpClientOptionsNative(array $config): ?array
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        static $slot;
        $options = [];
        try {
            foreach (['timeout', 'connect_timeout'] as $key) {
                $value = $config[$key] ?? null;
                if (is_string($value)) {
                    $slot ??= $ffi->new('double[1]');
                    $status = $ffi->nntmux_es_positive_seconds($value, strlen($value), $slot);
                    if ($status === 1) {
                        $options[$key] = (float) $slot[0];
                    } elseif ($status !== 0) {
                        return null;
                    }
                } elseif ((is_int($value) || is_float($value)) && (float) $value > 0) {
                    $options[$key] = (float) $value;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return $options;
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
     * Summarizes configured hosts with the acceptance rules of `buildHostsPhp()`: non-blank strings and
     * arrays with a non-blank `host` are accepted, everything else (including arrays whose `host` or
     * `scheme` is neither scalar nor null) is skipped. `https` counts `https://` strings and arrays
     * whose trimmed scheme is `https` (ASCII case-insensitive); `distinct_hostnames` covers trimmed
     * array hostnames.
     *
     * @param  array<array-key, mixed>  $configHosts
     * @return array{entries: int, string_hosts: int, structured_hosts: int, skipped: int, https: int, with_port: int, with_credentials: int, distinct_hostnames: int}
     */
    public static function summarizeHostConfigBatch(array $configHosts): array
    {
        return self::usesW425RustFfi() ? self::summarizeHostConfigBatchNative($configHosts) : self::summarizeHostConfigBatchPhp($configHosts);
    }

    /**
     * @param  array<array-key, mixed>  $configHosts
     * @return array{entries: int, string_hosts: int, structured_hosts: int, skipped: int, https: int, with_port: int, with_credentials: int, distinct_hostnames: int}
     */
    public static function summarizeHostConfigBatchPhp(array $configHosts): array
    {
        $strings = 0;
        $structured = 0;
        $skipped = 0;
        $https = 0;
        $ports = 0;
        $credentials = 0;
        $hostnames = [];
        foreach ($configHosts as $host) {
            if (is_string($host)) {
                $trimmed = trim($host);
                if ($trimmed === '') {
                    $skipped++;

                    continue;
                }
                $strings++;
                $https += (int) (strncasecmp($trimmed, 'https://', 8) === 0);

                continue;
            }
            $fields = self::w425HostFields($host);
            $hostname = $fields === null ? '' : trim($fields[0]);
            if ($fields === null || $hostname === '') {
                $skipped++;

                continue;
            }
            $structured++;
            $scheme = trim($fields[1]) ?: 'http';
            $https += (int) (strcasecmp($scheme, 'https') === 0);
            $ports += (int) $fields[2];
            $credentials += (int) $fields[3];
            $hostnames[$hostname] = true;
        }

        return [
            'entries' => count($configHosts),
            'string_hosts' => $strings,
            'structured_hosts' => $structured,
            'skipped' => $skipped,
            'https' => $https,
            'with_port' => $ports,
            'with_credentials' => $credentials,
            'distinct_hostnames' => count($hostnames),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $configHosts
     * @return array{entries: int, string_hosts: int, structured_hosts: int, skipped: int, https: int, with_port: int, with_credentials: int, distinct_hostnames: int}
     */
    public static function summarizeHostConfigBatchNative(array $configHosts): array
    {
        $parts = [];
        foreach ($configHosts as $host) {
            if (is_string($host)) {
                array_push($parts, '1', $host, '', '0', '0');

                continue;
            }
            $fields = self::w425HostFields($host);
            if ($fields === null) {
                array_push($parts, '0', '', '', '0', '0');

                continue;
            }
            array_push($parts, '2', $fields[0], $fields[1], $fields[2] ? '1' : '0', $fields[3] ? '1' : '0');
        }
        $fields = ElasticsearchResponseHelper::evaluateW425Native(2, $parts);
        if ($fields === null || count($fields) !== 8) {
            return self::summarizeHostConfigBatchPhp($configHosts);
        }

        return [
            'entries' => (int) $fields[0],
            'string_hosts' => (int) $fields[1],
            'structured_hosts' => (int) $fields[2],
            'skipped' => (int) $fields[3],
            'https' => (int) $fields[4],
            'with_port' => (int) $fields[5],
            'with_credentials' => (int) $fields[6],
            'distinct_hostnames' => (int) $fields[7],
        ];
    }

    /**
     * Raw host, raw scheme (`http` when missing), port flag and credentials flag of an array host, or
     * null for anything `summarizeHostConfigBatch()` skips by type.
     *
     * @return array{string, string, bool, bool}|null
     */
    private static function w425HostFields(mixed $host): ?array
    {
        if (! is_array($host)) {
            return null;
        }
        $hostname = $host['host'] ?? '';
        $scheme = $host['scheme'] ?? 'http';
        if (! is_scalar($hostname) || ! is_scalar($scheme)) {
            return null;
        }
        $port = $host['port'] ?? null;

        return [(string) $hostname, (string) $scheme, $port !== null && $port !== '', ! empty($host['user']) && ! empty($host['pass'])];
    }
}
