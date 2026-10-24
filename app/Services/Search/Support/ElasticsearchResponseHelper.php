<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Support\RustPipelineLibrary;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch as ElasticsearchResponse;
use FFI;
use Throwable;

final class ElasticsearchResponseHelper
{
    /**
     * Rust candidates for `asArray()` routing and `isNotFound()`. They stay opt-in:
     * neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchPageProxySortDropdownParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_es_response_as_array_mode(int32_t is_array);
        int32_t nntmux_es_is_not_found(int32_t is_client_response, int64_t code);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  array<string, mixed>|ElasticsearchResponse  $response
     * @return array<string, mixed>
     */
    public static function asArray(array|ElasticsearchResponse $response): array
    {
        return self::$useRustFfi ? self::asArrayNative($response) : self::asArrayPhp($response);
    }

    /**
     * @param  array<string, mixed>|ElasticsearchResponse  $response
     * @return array<string, mixed>
     */
    public static function asArrayPhp(array|ElasticsearchResponse $response): array
    {
        return is_array($response) ? $response : $response->asArray();
    }

    /**
     * @param  array<string, mixed>|ElasticsearchResponse  $response
     * @return array<string, mixed>
     */
    public static function asArrayNative(array|ElasticsearchResponse $response): array
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::asArrayPhp($response);
        }

        // @phpstan-ignore method.notFound
        $mode = $ffi->nntmux_es_response_as_array_mode((int) is_array($response));

        return match (true) {
            $mode === 0 && is_array($response) => $response,
            $mode === 1 && $response instanceof ElasticsearchResponse => $response->asArray(),
            default => self::asArrayPhp($response),
        };
    }

    /**
     * Execute a HEAD-style Elasticsearch call without throwing on 404.
     *
     * @param  callable(Client): ElasticsearchResponse  $callback
     */
    public static function boolResponse(Client $client, callable $callback): bool
    {
        $previousSetting = $client->getResponseException();
        $client->setResponseException(false);

        try {
            return $callback($client)->asBool();
        } finally {
            $client->setResponseException($previousSetting);
        }
    }

    public static function isNotFound(Throwable $throwable): bool
    {
        return self::$useRustFfi ? self::isNotFoundNative($throwable) : self::isNotFoundPhp($throwable);
    }

    public static function isNotFoundPhp(Throwable $throwable): bool
    {
        return $throwable instanceof ClientResponseException && $throwable->getCode() === 404;
    }

    public static function isNotFoundNative(Throwable $throwable): bool
    {
        $code = $throwable->getCode();
        $ffi = \is_int($code) ? self::searchFfi() : null;
        if ($ffi === null || ! \is_int($code)) {
            return self::isNotFoundPhp($throwable);
        }

        // @phpstan-ignore method.notFound
        $notFound = $ffi->nntmux_es_is_not_found((int) ($throwable instanceof ClientResponseException), $code);

        return \is_int($notFound) && $notFound >= 0 ? $notFound === 1 : self::isNotFoundPhp($throwable);
    }
}
