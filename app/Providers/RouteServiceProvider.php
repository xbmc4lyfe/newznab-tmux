<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Throwable;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/';

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w364_route_rate_limit_bucket(
            uint32_t flags,
            int64_t per_minute_limit,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustTvForumRoutePrivacyTermsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const int BUCKET_NAME_MAX = 64;

    private const int BUCKET_DEFAULT_LIMIT = 60;

    private const int BUCKET_LIMIT_MAX = 10000;

    private const int BUCKET_DECAY_SECONDS = 60;

    private const array BUCKET_BY_KEYS = ['ip', 'user', 'token'];

    private const int MAX_INPUT = 16_777_216;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot(): void
    {
        //

        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();

        $this->mapRssRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(base_path('routes/api.php'));
    }

    /**
     * Define the "rss" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapRssRoutes(): void
    {
        Route::prefix('rss')
            ->middleware('api')
            ->group(base_path('routes/rss.php'));
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a rate-limit bucket: the name becomes a `[a-z0-9-]` slug of at most 64 bytes
     * (`global` when empty), a non-positive limit becomes 60 and larger ones are capped at
     * 10000 per minute, and the key source must be `ip`, `user` or `token` (otherwise `ip`).
     *
     * @return array{name: string, per_minute: int, by: string, key: string, decay_seconds: int, is_default_name: bool, by_fallback: bool}
     */
    public static function normalizeRateLimitBucketSpec(?string $bucketName, int $perMinuteLimit, ?string $byKey): array
    {
        return self::usesRustFfi()
            ? self::normalizeRateLimitBucketSpecNative($bucketName, $perMinuteLimit, $byKey)
            : self::normalizeRateLimitBucketSpecPhp($bucketName, $perMinuteLimit, $byKey);
    }

    /**
     * @return array{name: string, per_minute: int, by: string, key: string, decay_seconds: int, is_default_name: bool, by_fallback: bool}
     */
    public static function normalizeRateLimitBucketSpecNative(?string $bucketName, int $perMinuteLimit, ?string $byKey): array
    {
        $input = ($bucketName ?? '').self::FIELD_SEPARATOR.($byKey ?? '');
        $flags = ($bucketName === null ? 0 : 1) | ($byKey === null ? 0 : 2);
        $fields = strlen($input) > self::MAX_INPUT ? null : self::callNative([$flags, $perMinuteLimit, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizeRateLimitBucketSpecPhp($bucketName, $perMinuteLimit, $byKey);
        }

        return [
            'name' => $fields[0],
            'per_minute' => (int) $fields[1],
            'by' => $fields[2],
            'key' => $fields[3],
            'decay_seconds' => (int) $fields[4],
            'is_default_name' => $fields[5] === '1',
            'by_fallback' => $fields[6] === '1',
        ];
    }

    /**
     * @return array{name: string, per_minute: int, by: string, key: string, decay_seconds: int, is_default_name: bool, by_fallback: bool}
     */
    public static function normalizeRateLimitBucketSpecPhp(?string $bucketName, int $perMinuteLimit, ?string $byKey): array
    {
        $name = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($bucketName ?? '')) ?? '', '-');
        if (strlen($name) > self::BUCKET_NAME_MAX) {
            $name = rtrim(substr($name, 0, self::BUCKET_NAME_MAX), '-');
        }
        $isDefaultName = $name === '';
        if ($isDefaultName) {
            $name = 'global';
        }
        $limit = $perMinuteLimit <= 0 ? self::BUCKET_DEFAULT_LIMIT : min($perMinuteLimit, self::BUCKET_LIMIT_MAX);
        $by = $byKey === null ? null : strtolower(trim($byKey, self::WHITESPACE));
        $byFallback = ! in_array($by, self::BUCKET_BY_KEYS, true);
        $by = $byFallback || $by === null ? 'ip' : $by;

        return [
            'name' => $name,
            'per_minute' => $limit,
            'by' => $by,
            'key' => 'rate:'.$name.':'.$by,
            'decay_seconds' => self::BUCKET_DECAY_SECONDS,
            'is_default_name' => $isDefaultName,
            'by_fallback' => $byFallback,
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<string>|null
     */
    private static function callNative(array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w364_route_rate_limit_bucket(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w364_route_rate_limit_bucket(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
