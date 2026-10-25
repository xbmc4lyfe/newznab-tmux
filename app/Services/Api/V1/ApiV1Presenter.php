<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Http\Controllers\Api\XML_Response;
use App\Services\Api\ApiCapabilitiesService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Http\Response;
use Throwable;

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

    public const string W465_FFI_CDEF = 'int64_t nntmux_w465_api_v1_v2_presenter_payload_rows_xml_context(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * The Wave 46.5 JSON string summary defaults to Rust: every common and uncommon mean and p99 ratio
     * cleared 1.5x (min 1.84/1.52/1.94/1.92x); see
     * tests/Unit/RustApiV1V2PresenterPayloadRowsXmlContextW46ParityTest.php.
     */
    public const bool W465_RUST_FFI_DEFAULT = true;

    /** {@see JSON_ENCODING_OPTIONS} without JSON_THROW_ON_ERROR, so invalid UTF-8 yields false. */
    private const int JSON_SUMMARY_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const int W465_MAX_BYTES = 67108864;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?bool $useW465RustFfi = null;

    private static FFI|false|null $w465Ffi = null;

    private static ?CData $w465Out = null;

    private static int $w465OutCap = 0;

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

    /** Pass null to restore {@see W465_RUST_FFI_DEFAULT}. */
    public static function useW465RustFfi(?bool $enable = true): void
    {
        self::$useW465RustFfi = $enable;
    }

    public static function usesW465RustFfi(): bool
    {
        return self::$useW465RustFfi ?? self::W465_RUST_FFI_DEFAULT;
    }

    public static function resetW465RustFfi(): void
    {
        self::$useW465RustFfi = null;
    }

    /**
     * Summarizes how {@see output()} JSON-encodes string values: `invalid_utf8` strings json_encode()
     * rejects, `escaped` strings that need escapes, `encoded_bytes` and `max_encoded_bytes` (quotes
     * included) and `distinct_strings` among the encodable ones. Non-string values are invalid.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, invalid_utf8: int, escaped: int, encoded_bytes: int, max_encoded_bytes: int, distinct_strings: int}
     */
    public static function summarizeJsonStringBatch(array $values): array
    {
        return self::usesW465RustFfi() ? self::summarizeJsonStringBatchNative($values) : self::summarizeJsonStringBatchPhp($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, invalid_utf8: int, escaped: int, encoded_bytes: int, max_encoded_bytes: int, distinct_strings: int}
     */
    public static function summarizeJsonStringBatchPhp(array $values): array
    {
        $summary = ['values' => \count($values), 'invalid' => 0, 'invalid_utf8' => 0, 'escaped' => 0, 'encoded_bytes' => 0, 'max_encoded_bytes' => 0, 'distinct_strings' => 0];
        $distinct = [];
        foreach ($values as $value) {
            if (! \is_string($value)) {
                $summary['invalid']++;

                continue;
            }
            $json = json_encode($value, self::JSON_SUMMARY_OPTIONS);
            if ($json === false) {
                $summary['invalid_utf8']++;

                continue;
            }
            $length = \strlen($json);
            $summary['escaped'] += (int) ($length !== \strlen($value) + 2);
            $summary['encoded_bytes'] += $length;
            $summary['max_encoded_bytes'] = max($summary['max_encoded_bytes'], $length);
            $distinct[$value] = true;
        }
        $summary['distinct_strings'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{values: int, invalid: int, invalid_utf8: int, escaped: int, encoded_bytes: int, max_encoded_bytes: int, distinct_strings: int}
     */
    public static function summarizeJsonStringBatchNative(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        }
        $fields = self::evaluateW465Counters(1, self::w465Joined($strings), 5);
        if ($fields === null) {
            return self::summarizeJsonStringBatchPhp($values);
        }

        return ['values' => \count($values), 'invalid' => \count($values) - \count($strings)]
            + array_combine(['invalid_utf8', 'escaped', 'encoded_bytes', 'max_encoded_bytes', 'distinct_strings'], $fields);
    }

    /**
     * Shared Wave 46.5 bridge for the API v1/v2 presenter, payload builder, release rows and XML context
     * helpers: returns exactly $outBytes bytes, or null when the library is unavailable or declined the
     * batch so callers fall back to PHP.
     */
    public static function evaluateW465Native(int $mode, string $input, int $outBytes): ?string
    {
        $ffi = self::w465Ffi();
        $length = \strlen($input);
        if ($ffi === null || $mode < 1 || $outBytes < 0 || $outBytes > self::W465_MAX_BYTES || $length > self::W465_MAX_BYTES) {
            return null;
        }

        try {
            if (self::$w465Out === null || $outBytes > self::$w465OutCap) {
                $cap = max(1024, 1 << (int) ceil(log(max(1, $outBytes), 2)));
                self::$w465Out = $ffi->new('char['.$cap.']');
                self::$w465OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w465_api_v1_v2_presenter_payload_rows_xml_context($mode, $input, $length, self::$w465Out, self::$w465OutCap);
            if ($written !== $outBytes) {
                return null;
            }

            return $outBytes === 0 ? '' : FFI::string(self::$w465Out, $outBytes);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Exactly $fields i64 counters from a Wave 46.5 summary mode, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    public static function evaluateW465Counters(int $mode, string $input, int $fields): ?array
    {
        $raw = $fields < 1 ? null : self::evaluateW465Native($mode, $input, $fields * 8);
        $values = $raw === null ? false : unpack('q*', $raw);

        /** @var list<int>|null */
        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * `[u32 count][NUL-joined strings]`; Rust declines when a string holds a NUL.
     *
     * @param  list<string>  $strings
     */
    public static function w465Joined(array $strings): string
    {
        return pack('L', \count($strings)).implode("\0", $strings);
    }

    private static function w465Ffi(): ?FFI
    {
        if (self::$w465Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w465Ffi = RustPipelineLibrary::resolveDomain('search_w465', self::W465_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w465Out = null;
            self::$w465OutCap = 0;
        }

        return self::$w465Ffi instanceof FFI ? self::$w465Ffi : null;
    }
}
