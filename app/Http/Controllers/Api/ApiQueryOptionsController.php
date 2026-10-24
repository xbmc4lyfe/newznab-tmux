<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Middleware\DecorateHttpQueryResponses;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Answers OPTIONS for QUERY-enabled API endpoints with Allow and Accept-Query.
 *
 * Laravel's implicit OPTIONS response skips route middleware and sends no
 * Accept-Query, so these endpoints register this explicit handler instead.
 * CORS preflights never reach it: HandleCors answers them before routing.
 */
class ApiQueryOptionsController
{
    public function __invoke(Request $request, Router $router): Response
    {
        $uri = $request->route()?->uri();

        $methods = collect($router->getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => $route->uri() === $uri)
            ->flatMap(static fn (Route $route): array => $route->methods())
            ->all();

        return response()->noContent()->withHeaders(self::buildOptionsHeaders(
            $methods, DecorateHttpQueryResponses::ACCEPT_QUERY,
        ));
    }

    // FFI serialization/allocation does not qualify for the 1.5x default-on gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public const string FFI_CDEF = 'ptrdiff_t nntmux_search_schema_log_steam(unsigned int mode, const char *input, size_t input_len, char *out, size_t cap);';

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? false;
    }

    public static function useRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search-schema-log-steam', self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Length-prefixed binary packets preserve PHP byte strings, including NULs.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                if (strlen($item) > 16777216) {
                    return null;
                }
                $input .= pack('V', strlen($item)).$item;
                if (strlen($input) > 16777216) {
                    return null;
                }
            }
            $cap = strlen($input) + 256;
            $out = $ffi->new("char[{$cap}]");
            $written = self::callFfi($ffi, 'nntmux_search_schema_log_steam', $mode, $input, strlen($input), $out, $cap);
            if (is_int($written) && $written < 0 && $written >= -16777216) {
                $cap = -$written;
                $out = $ffi->new("char[{$cap}]");
                $written = self::callFfi($ffi, 'nntmux_search_schema_log_steam', $mode, $input, strlen($input), $out, $cap);
            }
            if (! is_int($written) || $written < 4 || $written > $cap) {
                return null;
            }
            $packet = FFI::string($out, $written);
            $count = unpack('Vcount', $packet)['count'];
            $offset = 4;
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                if ($offset + 4 > $written) {
                    return null;
                }
                $length = unpack('Vlength', $packet, $offset)['length'];
                $offset += 4;
                if ($length > $written - $offset) {
                    return null;
                }
                $result[] = substr($packet, $offset, $length);
                $offset += $length;
            }

            return $offset === $written ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $methods */
    public static function formatAllowedMethodsHeader(array $methods): string
    {
        return self::useRustFfi() ? self::formatAllowedMethodsHeaderNative($methods) : self::formatAllowedMethodsHeaderPhp($methods);
    }

    /** @param array<array-key, mixed> $methods */
    public static function formatAllowedMethodsHeaderPhp(array $methods): string
    {
        return implode(', ', array_unique($methods));
    }

    /** @param array<array-key, mixed> $methods */
    public static function formatAllowedMethodsHeaderNative(array $methods): string
    {
        foreach ($methods as $method) {
            if (! is_scalar($method) && $method !== null) {
                return self::formatAllowedMethodsHeaderPhp($methods);
            }
        }

        return self::nativePacket(5, array_values(array_map('strval', $methods)))[0] ?? self::formatAllowedMethodsHeaderPhp($methods);
    }

    /**
     * @param  array<array-key, mixed>  $methods
     * @return array{Allow: string, Accept-Query: string}
     */
    public static function buildOptionsHeaders(array $methods, string $acceptQuery = '1'): array
    {
        return self::useRustFfi() ? self::buildOptionsHeadersNative($methods, $acceptQuery) : self::buildOptionsHeadersPhp($methods, $acceptQuery);
    }

    /**
     * @param  array<array-key, mixed>  $methods
     * @return array{Allow: string, Accept-Query: string}
     */
    public static function buildOptionsHeadersPhp(array $methods, string $acceptQuery = '1'): array
    {
        return ['Allow' => self::formatAllowedMethodsHeaderPhp($methods), 'Accept-Query' => $acceptQuery];
    }

    /**
     * @param  array<array-key, mixed>  $methods
     * @return array{Allow: string, Accept-Query: string}
     */
    public static function buildOptionsHeadersNative(array $methods, string $acceptQuery = '1'): array
    {
        foreach ($methods as $method) {
            if (! is_scalar($method) && $method !== null) {
                return self::buildOptionsHeadersPhp($methods, $acceptQuery);
            }
        }
        $row = self::nativePacket(6, [$acceptQuery, ...array_values(array_map('strval', $methods))]);

        return $row !== null && count($row) === 2 ? ['Allow' => $row[0], 'Accept-Query' => $row[1]] : self::buildOptionsHeadersPhp($methods, $acceptQuery);
    }
}
