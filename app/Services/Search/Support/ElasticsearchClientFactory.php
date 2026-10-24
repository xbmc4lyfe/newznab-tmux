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
}
