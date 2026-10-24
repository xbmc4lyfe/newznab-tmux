<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\UserRequest;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonInterface;
use FFI;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Backoff([1, 5, 30])]
#[FailOnTimeout]
final class RecordApiUsage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string FFI_CDEF = 'int32_t nntmux_w25_api_audit_account_job(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Opt-in experiment: retain PHP unless every performance gate qualifies.
    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    /**
     * @internal Bounded scalar-only transport shared by this slice's helpers.
     *
     * @param  list<int|string|null>  $args
     */
    public static function transformNative(int $operation, array $args): mixed
    {
        $maximum = 67_108_864;
        try {
            foreach ($args as $arg) {
                if ($arg !== null && ! is_int($arg) && ! is_string($arg)) {
                    return null;
                }
            }
            $input = serialize($args);
            if (strlen($input) > $maximum) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('w25-api-audit-account-job', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, min($maximum, max(256, strlen($input) + 256)), $capacity);
            if ($scratch === null) {
                return null;
            }
            $capacity = min($capacity, $maximum);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w25_api_audit_account_job($operation, $input, strlen($input), $scratch, $capacity);
            if (is_int($written) && $written < -1 && -$written > $capacity && -$written <= $maximum) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                $capacity = min($capacity, $maximum);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w25_api_audit_account_job($operation, $input, strlen($input), $scratch, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            $wire = FFI::string($scratch, $written);
            $result = @unserialize($wire, ['allowed_classes' => false]);

            return $result === false && $wire !== 'b:0;' ? null : $result;
        } catch (Throwable) {
            return null;
        }
    }

    public static function cacheKey(int $userId): string
    {
        return self::$useRustFfi ? self::cacheKeyNative($userId) : self::cacheKeyPhp($userId);
    }

    public static function cacheKeyPhp(int $userId): string
    {
        return 'api_access_update:'.$userId;
    }

    public static function cacheKeyNative(int $userId): string
    {
        $result = self::transformNative(6, [$userId]);

        return is_string($result) ? $result : self::cacheKeyPhp($userId);
    }

    public static function normalizeInterval(int $interval): int
    {
        return self::$useRustFfi ? self::normalizeIntervalNative($interval) : self::normalizeIntervalPhp($interval);
    }

    public static function normalizeIntervalPhp(int $interval): int
    {
        return max(1, $interval);
    }

    public static function normalizeIntervalNative(int $interval): int
    {
        $result = self::transformNative(7, [$interval]);

        return is_int($result) && $result >= 1 ? $result : self::normalizeIntervalPhp($interval);
    }

    /** @return array{key: string, interval: int} */
    public static function buildAccessBucket(int $userId, int $interval): array
    {
        return self::$useRustFfi ? self::buildAccessBucketNative($userId, $interval) : self::buildAccessBucketPhp($userId, $interval);
    }

    /** @return array{key: string, interval: int} */
    public static function buildAccessBucketPhp(int $userId, int $interval): array
    {
        return ['key' => self::cacheKeyPhp($userId), 'interval' => self::normalizeIntervalPhp($interval)];
    }

    /** @return array{key: string, interval: int} */
    public static function buildAccessBucketNative(int $userId, int $interval): array
    {
        $result = self::transformNative(0, [$userId, $interval]);

        return is_array($result) && array_keys($result) === ['key', 'interval'] && is_string($result['key']) && is_int($result['interval']) && $result['interval'] >= 1
            ? $result : self::buildAccessBucketPhp($userId, $interval);
    }

    /** @return array{users_id: int, request: string, timestamp: string} */
    public static function buildAuditPayload(int $userId, string $requestUri, string $occurredAt): array
    {
        return self::$useRustFfi ? self::buildAuditPayloadNative($userId, $requestUri, $occurredAt) : self::buildAuditPayloadPhp($userId, $requestUri, $occurredAt);
    }

    /** @return array{users_id: int, request: string, timestamp: string} */
    public static function buildAuditPayloadPhp(int $userId, string $requestUri, string $occurredAt): array
    {
        return ['users_id' => $userId, 'request' => $requestUri, 'timestamp' => $occurredAt];
    }

    /** @return array{users_id: int, request: string, timestamp: string} */
    public static function buildAuditPayloadNative(int $userId, string $requestUri, string $occurredAt): array
    {
        $result = self::transformNative(1, [$userId, $requestUri, $occurredAt]);

        return is_array($result) && array_keys($result) === ['users_id', 'request', 'timestamp'] && is_int($result['users_id']) && is_string($result['request']) && is_string($result['timestamp'])
            ? $result : self::buildAuditPayloadPhp($userId, $requestUri, $occurredAt);
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributes(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        return self::$useRustFfi ? self::buildUpdateAttributesNative($occurredAt, $ip) : self::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributesPhp(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        $update = ['apiaccess' => $occurredAt];
        if ($ip !== null) {
            $update['host'] = $ip;
        }

        return $update;
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributesNative(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        // Dates stay in PHP: retain Carbon identity, timezone and microseconds.
        $result = self::transformNative(2, [is_string($occurredAt) ? $occurredAt : '', $ip]);
        if (is_array($result) && array_keys($result) === ($ip === null ? ['apiaccess'] : ['apiaccess', 'host'])
            && is_string($result['apiaccess']) && ($ip === null || is_string($result['host']))) {
            $result['apiaccess'] = $occurredAt;

            return $result;
        }

        return self::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    public int $tries = 4;

    public int $timeout = 15;

    public function __construct(
        public readonly int $userId,
        public readonly string $requestUri,
        public readonly ?string $ip,
        public readonly string $occurredAt,
    ) {
        $this->onQueue((string) config('nntmux.api.audit_queue', 'api-audit'));
    }

    public function handle(): void
    {
        // Legacy job retained while pre-deployment audit jobs drain.
        $payload = self::buildAuditPayload($this->userId, $this->requestUri, $this->occurredAt);
        UserRequest::recordApiRequest($payload['users_id'], $payload['request'], $payload['timestamp']);

        $bucket = self::buildAccessBucket($this->userId, (int) config('nntmux.api.access_update_interval', 60));
        try {
            if (! Cache::add($bucket['key'], true, $bucket['interval'])) {
                return;
            }
        } catch (Throwable) {
            // Cache degradation must not retry and duplicate the durable audit row.
        }

        $update = self::buildUpdateAttributes($this->occurredAt, $this->ip);

        DB::table('users')->where('id', $this->userId)->update($update);
    }
}
