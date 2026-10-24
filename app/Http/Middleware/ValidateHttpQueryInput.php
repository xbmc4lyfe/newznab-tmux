<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Api\ApiInputCanonicalizer;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Strict input contract for HTTP QUERY (RFC 10008) API requests.
 *
 * Validates the complete effective input (URL and JSON body together), then
 * rewrites both parameter bags into the canonical form a GET request would
 * produce, so controllers keep their legacy parsing. For v2, other methods get
 * the same URL value-type check; v1 keeps its legacy array parameters.
 *
 * Laravel decodes JSON bodies while capturing the request, so the size check
 * here is a contract limit; the resource limit belongs to the web server.
 */
final class ValidateHttpQueryInput
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_http_is_json_media_type(const char *input, size_t input_len);
        ssize_t nntmux_http_validate_query(const char *content_type, size_t content_type_len, uint32_t has_content_type, const char *raw_body, size_t raw_body_len, size_t max_body_bytes, const char *query_keys_nul, size_t query_keys_len, size_t query_keys_count, int32_t *out_status, char *out_buf, size_t out_cap);
        C;

    private const MAX_PARAMETERS = 64;

    private const KEY_PATTERN = '/^[A-Za-z0-9_]{1,32}$/';

    private static FFI|false|null $ffi = null;

    private static ?CData $outStatus = null;

    private static bool $useRustFfi = true;

    public function __construct(private readonly ApiInputCanonicalizer $canonicalizer) {}

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  Closure(Request): (Response)  $next
     * @param  string  $version  `v1` renders XML errors, `v2` renders JSON errors
     */
    public function handle(Request $request, Closure $next, string $version = 'v2'): Response
    {
        if (! $request->isMethod('QUERY')) {
            $error = $version === 'v2' ? $this->validateUrlValues($request->query->all()) : null;
            if ($error !== null) {
                return $this->errorResponse($version, $error['status'], $error['message']);
            }

            return $next($request);
        }

        $error = $this->validate($request);
        if ($error !== null) {
            return $this->errorResponse($version, $error['status'], $error['message']);
        }

        $body = $this->canonicalizer->canonicalize($request->json()->all());
        $request->query->replace($this->canonicalizer->canonicalize($request->query->all()));
        $request->json()->replace($body);
        $request->request->replace($body);

        return $next($request);
    }

    /**
     * @return array{status: int, message: string}|null
     */
    public function validate(Request $request): ?array
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = $this->validateNative($request, $supported);
            if ($supported) {
                return $native;
            }
        }

        return $this->validatePhp($request);
    }

    /**
     * @return array{status: int, message: string}|null
     */
    public function validatePhp(Request $request): ?array
    {
        if (! $this->isJsonMediaTypePhp($request->headers->get('Content-Type'))) {
            return ['status' => 415, 'message' => 'QUERY requests must use Content-Type: application/json'];
        }

        $raw = $request->getContent();
        if (strlen($raw) > (int) config('nntmux.api.query_max_body_bytes', 8192)) {
            return ['status' => 413, 'message' => 'QUERY body exceeds the maximum size'];
        }

        // Decode with objects preserved: associative decoding would turn {"0":1} into a list.
        try {
            $decoded = json_decode($raw, false, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['status' => 400, 'message' => 'QUERY body must be valid JSON without nested values'];
        }

        if (! $decoded instanceof stdClass) {
            return ['status' => 400, 'message' => 'QUERY body must be a JSON object'];
        }

        $body = get_object_vars($decoded);
        $query = $request->query->all();
        $keys = array_unique(array_map('strval', [...array_keys($query), ...array_keys($body)]));

        foreach ($keys as $key) {
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                return ['status' => 400, 'message' => 'Parameter names may only contain letters, digits and underscores (max 32)'];
            }
        }

        if (count($keys) > self::MAX_PARAMETERS) {
            return ['status' => 400, 'message' => 'QUERY requests accept at most '.self::MAX_PARAMETERS.' parameters'];
        }

        // Keys are safe to name in messages from here on: they passed KEY_PATTERN.
        $duplicates = array_keys(array_intersect_key($query, $body));
        if ($duplicates !== []) {
            return ['status' => 400, 'message' => 'Parameter '.$duplicates[0].' must not be sent in both the URL and the body'];
        }

        foreach ($body as $key => $value) {
            if (! $this->isValidBodyValue((string) $key, $value)) {
                return ['status' => 400, 'message' => 'Parameter '.$key.' has an unsupported type'];
            }
        }

        return $this->validateUrlValues($query);
    }

    /**
     * @param-out bool $supported
     *
     * @return array{status: int, message: string}|null
     */
    public function validateNative(Request $request, bool &$supported = false): ?array
    {
        $supported = false;
        if (! self::$useRustFfi) {
            return null;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false || self::$outStatus === null) {
            return null;
        }

        $contentType = $request->headers->get('Content-Type');
        $hasContentType = $contentType !== null ? 1 : 0;
        $ctStr = $contentType ?? '';

        $raw = $request->getContent();
        $maxBodyBytes = max(0, (int) config('nntmux.api.query_max_body_bytes', 8192));
        $query = $request->query->all();
        $queryCount = \count($query);
        $queryKeysNul = '';
        if ($queryCount > 0) {
            $qKeys = [];
            foreach ($query as $k => $v) {
                $ks = (string) $k;
                if (\str_contains($ks, "\0")) {
                    return null;
                }
                $qKeys[] = $ks;
            }
            $queryKeysNul = implode("\0", $qKeys);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_http_validate_query(
                $ctStr,
                \strlen($ctStr),
                $hasContentType,
                $raw,
                \strlen($raw),
                $maxBodyBytes,
                $queryKeysNul,
                \strlen($queryKeysNul),
                $queryCount,
                FFI::addr(self::$outStatus),
                $scratch,
                $cap,
            );
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_http_validate_query(
                    $ctStr,
                    \strlen($ctStr),
                    $hasContentType,
                    $raw,
                    \strlen($raw),
                    $maxBodyBytes,
                    $queryKeysNul,
                    \strlen($queryKeysNul),
                    $queryCount,
                    FFI::addr(self::$outStatus),
                    $scratch,
                    $cap,
                );
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
            }
            // @phpstan-ignore property.notFound
            $status = (int) self::$outStatus->cdata;
        } catch (Throwable) {
            return null;
        }

        $supported = true;
        if ($status !== 0) {
            return [
                'status' => $status,
                'message' => $written > 0 ? FFI::string($scratch, $written) : '',
            ];
        }

        return $queryCount === 0 ? null : $this->validateUrlValues($query);
    }

    /**
     * Reject URL parameters sent as arrays (`?id[]=x`) unless they are list
     * parameters, so scalar filters never reach the controllers as arrays.
     *
     * @param  array<array-key, mixed>  $query
     * @return array{status: int, message: string}|null
     */
    private function validateUrlValues(array $query): ?array
    {
        foreach ($query as $key => $value) {
            if (! $this->isValidUrlValue((string) $key, $value)) {
                $name = preg_match(self::KEY_PATTERN, (string) $key) === 1 ? (string) $key : 'value';

                return ['status' => 400, 'message' => 'Parameter '.$name.' has an unsupported type'];
            }
        }

        return null;
    }

    public function isJsonMediaType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        if (! \str_contains($contentType, ';')) {
            return strtolower(trim($contentType)) === 'application/json';
        }

        if (self::$useRustFfi) {
            $native = self::isJsonMediaTypeNative($contentType);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->isJsonMediaTypePhp($contentType);
    }

    public function isJsonMediaTypePhp(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $parts = array_map('trim', explode(';', $contentType));
        if (strtolower((string) array_shift($parts)) !== 'application/json') {
            return false;
        }

        foreach ($parts as $parameter) {
            if ($parameter === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            if (strtolower(trim($name)) !== 'charset' || strtolower(trim(trim($value), '"')) !== 'utf-8') {
                return false;
            }
        }

        return true;
    }

    public static function isJsonMediaTypeNative(?string $contentType): ?bool
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($contentType === null) {
            return false;
        }

        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_http_is_json_media_type($contentType, \strlen($contentType));

            return \is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outStatus = $ffi->new('int32_t');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    private function isValidBodyValue(string $key, mixed $value): bool
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return true;
        }

        // JSON arrays decode to PHP lists; JSON objects stay stdClass and are rejected.
        return ApiInputCanonicalizer::isListParameter($key)
            && is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_string($item) || is_int($item) || is_float($item));
    }

    private function isValidUrlValue(string $key, mixed $value): bool
    {
        // null is an empty parameter such as `?id=` after ConvertEmptyStringsToNull.
        if ($value === null || is_string($value)) {
            return true;
        }

        return ApiInputCanonicalizer::isListParameter($key)
            && is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => $item === null || is_string($item));
    }

    private function errorResponse(string $version, int $status, string $message): Response
    {
        $response = $version === 'v1' ? showApiError(201, $message) : apiJsonError(201, $message);
        if (! $response instanceof Response) {
            $response = response($message, $status);
        }

        return $response->setStatusCode($status);
    }
}
