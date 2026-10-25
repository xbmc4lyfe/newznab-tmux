<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ApiUserResolver
{
    /**
     * Rust candidates for the cache key and credential classification. They stay
     * opt-in: neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_api_user_cache_key(const char *token, size_t token_len, char *out, size_t cap);
        int32_t nntmux_api_token_classify(const char *api_token, size_t api_token_len, int32_t api_token_kind, const char *apikey, size_t apikey_len, int32_t apikey_kind, int32_t *out_source);
        C;

    /** Credentials resolved through the v1 (`apikey`) lookup. */
    public const int VERSION_1 = 1;

    /** Credentials resolved through the v2 (`api_token`) lookup. */
    public const int VERSION_2 = 2;

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

    public function v1(string $token): ?User
    {
        return Cache::remember(self::cacheKey($token), 300, static function () use ($token): ?User {
            $user = User::findVerifiedByApiToken($token);
            $user?->load('role');

            return $user;
        });
    }

    public function v2(string $token): ?User
    {
        return Cache::remember(self::cacheKey($token), 300, static fn (): ?User => User::query()
            ->whereApiToken($token)
            ->with('role')
            ->first());
    }

    public static function cacheKey(string $token): string
    {
        return self::$useRustFfi ? self::cacheKeyNative($token) : self::cacheKeyPhp($token);
    }

    public static function cacheKeyPhp(string $token): string
    {
        return 'api_user:'.md5($token);
    }

    public static function cacheKeyNative(string $token): string
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, 64, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::cacheKeyPhp($token);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_user_cache_key($token, \strlen($token), $scratch, $cap);

        return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : self::cacheKeyPhp($token);
    }

    /**
     * Pick the API token from the `api_token` and `apikey` request inputs:
     * `api_token ?? apikey` must be a non-empty string, and it resolves through
     * v2 when `api_token` is `filled()` and v1 otherwise.
     *
     * @return array{token: string, version: int}|null
     */
    public static function resolveCredentials(mixed $apiToken, mixed $apikey): ?array
    {
        return self::$useRustFfi
            ? self::resolveCredentialsNative($apiToken, $apikey)
            : self::resolveCredentialsPhp($apiToken, $apikey);
    }

    /**
     * @return array{token: string, version: int}|null
     */
    public static function resolveCredentialsPhp(mixed $apiToken, mixed $apikey): ?array
    {
        $token = $apiToken ?? $apikey;

        if (! \is_string($token) || $token === '') {
            return null;
        }

        // Request::filled(): a string counts once trimmed; null/apikey fallback never does.
        $version = \is_string($apiToken) && trim($apiToken) !== '' ? self::VERSION_2 : self::VERSION_1;

        return ['token' => $token, 'version' => $version];
    }

    /**
     * @return array{token: string, version: int}|null
     */
    public static function resolveCredentialsNative(mixed $apiToken, mixed $apikey): ?array
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::resolveCredentialsPhp($apiToken, $apikey);
        }

        $apiTokenBytes = \is_string($apiToken) ? $apiToken : '';
        $apikeyBytes = \is_string($apikey) ? $apikey : '';
        $source = $ffi->new('int32_t[1]');
        // @phpstan-ignore method.notFound
        $version = $ffi->nntmux_api_token_classify(
            $apiTokenBytes,
            \strlen($apiTokenBytes),
            self::inputKind($apiToken),
            $apikeyBytes,
            \strlen($apikeyBytes),
            self::inputKind($apikey),
            $source,
        );
        if (! \is_int($version) || $version < 0) {
            return self::resolveCredentialsPhp($apiToken, $apikey);
        }
        if ($version === 0) {
            return null;
        }

        return ['token' => $source[0] === 0 ? $apiTokenBytes : $apikeyBytes, 'version' => $version];
    }

    /**
     * Request-input kind for the C ABI: 0 null, 1 string, 2 any other value.
     */
    private static function inputKind(mixed $value): int
    {
        return match (true) {
            $value === null => 0,
            \is_string($value) => 1,
            default => 2,
        };
    }

    public const string W54_FFI_CDEF = 'int64_t nntmux_w545_api_user_token_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W54.5 parity test's QUALIFICATION). */
    private const bool W54_RUST_FFI_DEFAULT = true;

    /**
     * Wave 54.5 routing and FFI cache, kept in function-local statics. Without $op it reports the routing;
     * 'set' swaps the override for $val and returns the previous one; 'reset' replaces the cached handle
     * with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]` with room
     * for at least $val words, or null without the library.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w54_5', self::W54_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W54_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW54RustFfi(): bool
    {
        return self::w54RustFfiState() === true;
    }

    /**
     * Runs $callback with tokenPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 54.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w54Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w54RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w545_api_user_token_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch shape of request API tokens. Four words per token: `[version` as {@see resolveCredentialsPhp()}
     * picks it for `api_token` (0 empty, 1 blank, 2 filled), trimmed length, flags (1 trimmed token is all
     * hex, 2 trimmed length 32, 4 padded, 8 holds a control byte), non-alphanumeric trimmed bytes`]`.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function tokenPlanBatch(array $tokens): array
    {
        return self::usesW54RustFfi() ? self::tokenPlanBatchNative($tokens) : self::tokenPlanBatchPhp($tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function tokenPlanBatchPhp(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $trimmed = trim($token);
            $length = \strlen($trimmed);
            $out[] = $token === '' ? 0 : ($length > 0 ? self::VERSION_2 : self::VERSION_1);
            $out[] = $length;
            $out[] = ($length > 0 && strspn($trimmed, '0123456789abcdefABCDEF') === $length ? 1 : 0)
                | ($length === 32 ? 2 : 0)
                | ($length !== \strlen($token) ? 4 : 0)
                | (preg_match('/[\x00-\x1f\x7f]/', $token) === 1 ? 8 : 0);
            $out[] = (int) preg_match_all('/[^A-Za-z0-9]/', $trimmed);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function tokenPlanBatchNative(array $tokens): array
    {
        $count = \count($tokens);
        if ($count === 0) {
            return [];
        }

        return self::w54Words($count, implode("\0", $tokens), 4 * $count) ?? self::tokenPlanBatchPhp($tokens);
    }
}
