<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Http\Controllers\Api\XML_Response;
use App\Services\Api\ApiCapabilitiesService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Response;

final class ApiV1Presenter
{
    /**
     * Rust candidates for the pure helpers below. They stay opt-in: none beat PHP by
     * 1.5x in the paired benchmark (tests/Unit/RustSearchMediaApiCapabilitiesParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_api_v1_is_caps(const char *kind, size_t len);
        ssize_t nntmux_api_v1_response_headers(int32_t xml, size_t body_len, char *out, size_t cap);
        C;

    // JSON_HEX_TAG would turn category separators such as ">" into ">".
    private const JSON_ENCODING_OPTIONS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(private readonly ApiCapabilitiesService $capabilities) {}

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
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $headers
     */
    public function output(
        mixed $data,
        array $parameters,
        bool $xml,
        int $offset,
        string $type = '',
        array $headers = []
    ): Response {
        $response = new XML_Response([
            'Parameters' => $parameters,
            'Data' => $data,
            'Server' => $this->capabilities->v1(self::isCapsRequest($type)),
            'Offset' => $offset,
            'Type' => $type,
        ]);

        if ($xml) {
            $body = $response->returnXML();
        } else {
            $array = $response->returnArray();
            if ($array === false) {
                return showApiError(201);
            }
            $body = json_encode($array, self::JSON_ENCODING_OPTIONS);
        }

        if ($body === false) {
            return showApiError(201);
        }

        return response($body, 200, self::responseHeaders($xml, $body, $headers));
    }

    /**
     * Whether the request advertises catalogs (categories, groups, genres) in the server block.
     */
    public static function isCapsRequest(string $type): bool
    {
        return self::$useRustFfi ? self::isCapsRequestNative($type) : self::isCapsRequestPhp($type);
    }

    public static function isCapsRequestPhp(string $type): bool
    {
        return $type === 'caps';
    }

    public static function isCapsRequestNative(string $type): bool
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::isCapsRequestPhp($type);
        }

        // @phpstan-ignore method.notFound
        $caps = $ffi->nntmux_api_v1_is_caps($type, \strlen($type));

        return \is_int($caps) && $caps >= 0 ? $caps === 1 : self::isCapsRequestPhp($type);
    }

    /**
     * Content type and length for the XML or JSON body; caller headers override them.
     *
     * @param  array<string, string>  $headers
     * @return array<array-key, string>
     */
    public static function responseHeaders(bool $xml, string $body, array $headers = []): array
    {
        return self::$useRustFfi
            ? self::responseHeadersNative($xml, $body, $headers)
            : self::responseHeadersPhp($xml, $body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<array-key, string>
     */
    public static function responseHeadersPhp(bool $xml, string $body, array $headers = []): array
    {
        return array_merge([
            'Content-type' => $xml ? 'text/xml' : 'application/json',
            'Content-Length' => (string) strlen($body),
        ], $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<array-key, string>
     */
    public static function responseHeadersNative(bool $xml, string $body, array $headers = []): array
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi === null ? null : RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($ffi === null || $scratch === null) {
            return self::responseHeadersPhp($xml, $body, $headers);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_v1_response_headers((int) $xml, \strlen($body), $scratch, $cap);
        $pair = \is_int($written) && $written > 0 ? explode("\t", FFI::string($scratch, $written), 2) : [];
        if (\count($pair) !== 2) {
            return self::responseHeadersPhp($xml, $body, $headers);
        }

        return array_merge(['Content-type' => $pair[0], 'Content-Length' => $pair[1]], $headers);
    }
}
