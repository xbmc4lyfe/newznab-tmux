<?php

declare(strict_types=1);

namespace App\Jobs;

use Carbon\CarbonInterface;
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
final class UpdateUserApiAccess implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    /** @return array{key: string, interval: int} */
    public static function buildAccessBucket(int $userId, int $interval): array
    {
        return self::$useRustFfi ? self::buildAccessBucketNative($userId, $interval) : self::buildAccessBucketPhp($userId, $interval);
    }

    /** @return array{key: string, interval: int} */
    public static function buildAccessBucketPhp(int $userId, int $interval): array
    {
        return RecordApiUsage::buildAccessBucketPhp($userId, $interval);
    }

    /** @return array{key: string, interval: int} */
    public static function buildAccessBucketNative(int $userId, int $interval): array
    {
        return RecordApiUsage::buildAccessBucketNative($userId, $interval);
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributes(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        return self::$useRustFfi ? self::buildUpdateAttributesNative($occurredAt, $ip) : self::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributesPhp(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        return RecordApiUsage::buildUpdateAttributesPhp($occurredAt, $ip);
    }

    /** @return array{apiaccess: string|CarbonInterface, host?: string} */
    public static function buildUpdateAttributesNative(string|CarbonInterface $occurredAt, ?string $ip): array
    {
        return RecordApiUsage::buildUpdateAttributesNative($occurredAt, $ip);
    }

    public int $tries = 4;

    public int $timeout = 15;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $ip,
        public readonly string $occurredAt,
    ) {
        $this->onQueue((string) config('nntmux.api.audit_queue', 'api-audit'));
    }

    public function handle(): void
    {
        $bucket = self::buildAccessBucket($this->userId, (int) config('nntmux.api.access_update_interval', 60));

        try {
            if (! Cache::add($bucket['key'], true, $bucket['interval'])) {
                return;
            }
        } catch (Throwable) {
            // Cache degradation must not prevent the metadata update.
        }

        $update = self::buildUpdateAttributes($this->occurredAt, $this->ip);

        DB::table('users')->where('id', $this->userId)->update($update);
    }
}
