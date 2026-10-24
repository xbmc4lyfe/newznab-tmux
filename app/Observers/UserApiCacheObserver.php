<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Throwable;

class UserApiCacheObserver
{
    /** User attributes that invalidate cached API users when they change. */
    public const array CACHE_RELEVANT_FIELDS = ['api_token', 'roles_id', 'rate_limit', 'verified', 'email_verified_at', 'deleted_at'];

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_user_api_cache_relevant_change(const char *keys, size_t keys_len, const size_t *lens, size_t count);
        intptr_t nntmux_user_api_token_cache_keys(const char *token, size_t token_len, int64_t user_id, char *out, size_t cap);
        C;

    /**
     * The Rust helpers stay opt-in: they did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchUnavailableObserversFailedRelease2faParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function updated(User $user): void
    {
        if (! self::hasCacheRelevantChange($user->getChanges())) {
            return;
        }

        $this->flushTokenCaches($user, $user->getOriginal('api_token'));
        $this->flushTokenCaches($user, $user->api_token);
    }

    public function deleted(User $user): void
    {
        $this->flushTokenCaches($user, $user->api_token);
    }

    public function restored(User $user): void
    {
        $this->flushTokenCaches($user, $user->api_token);
    }

    public function forceDeleted(User $user): void
    {
        $this->flushTokenCaches($user, $user->api_token);
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Whether the saved changes (`Model::getChanges()`) include a cache-relevant field,
     * matching `Model::wasChanged(CACHE_RELEVANT_FIELDS)`.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public static function hasCacheRelevantChange(array $changes): bool
    {
        return self::usesRustFfi()
            ? self::hasCacheRelevantChangeNative($changes)
            : self::hasCacheRelevantChangePhp($changes);
    }

    /**
     * @param  array<array-key, mixed>  $changes
     */
    public static function hasCacheRelevantChangePhp(array $changes): bool
    {
        foreach (self::CACHE_RELEVANT_FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $changes
     */
    public static function hasCacheRelevantChangeNative(array $changes): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::hasCacheRelevantChangePhp($changes);
        }

        try {
            $count = \count($changes);
            $lens = $ffi->new('size_t['.max(1, $count).']');
            $packed = '';
            $i = 0;
            foreach ($changes as $key => $_) {
                $key = (string) $key;
                $packed .= $key;
                $lens[$i++] = \strlen($key);
            }
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_user_api_cache_relevant_change($packed, \strlen($packed), $lens, $count);
        } catch (Throwable) {
            return self::hasCacheRelevantChangePhp($changes);
        }

        return $result === 0 || $result === 1
            ? $result === 1
            : self::hasCacheRelevantChangePhp($changes);
    }

    /**
     * Cache keys to forget for an API token, or none when the token is not a non-empty string.
     *
     * @return list<string>
     */
    public static function tokenCacheKeys(mixed $apiToken, int $userId): array
    {
        return self::usesRustFfi()
            ? self::tokenCacheKeysNative($apiToken, $userId)
            : self::tokenCacheKeysPhp($apiToken, $userId);
    }

    /**
     * @return list<string>
     */
    public static function tokenCacheKeysPhp(mixed $apiToken, int $userId): array
    {
        if (! is_string($apiToken) || $apiToken === '') {
            return [];
        }

        return [
            'api_user:'.md5($apiToken),
            'api_user_stats:'.$userId,
            User::categoryExclusionCacheKey($userId),
        ];
    }

    /**
     * @return list<string>
     */
    public static function tokenCacheKeysNative(mixed $apiToken, int $userId): array
    {
        if (! is_string($apiToken) || $apiToken === '') {
            return [];
        }

        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, 160, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::tokenCacheKeysPhp($apiToken, $userId);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_api_token_cache_keys($apiToken, \strlen($apiToken), $userId, $scratch, $cap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::tokenCacheKeysPhp($apiToken, $userId);
            }
            $keys = explode("\0", FFI::string($scratch, $written));
        } catch (Throwable) {
            return self::tokenCacheKeysPhp($apiToken, $userId);
        }

        return \count($keys) === 3 ? $keys : self::tokenCacheKeysPhp($apiToken, $userId);
    }

    private function flushTokenCaches(User $user, mixed $apiToken): void
    {
        foreach (self::tokenCacheKeys($apiToken, $user->id) as $key) {
            Cache::forget($key);
        }
    }
}
