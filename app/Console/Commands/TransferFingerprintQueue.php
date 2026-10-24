<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use Illuminate\Console\Command;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class TransferFingerprintQueue extends Command
{
    private const string RELEASE_ID_PATTERN = '/releaseId";i:([1-9][0-9]*);/';

    // Keep PHP as the production default: the JSON FFI round trip is slower than these helpers (< 1.5x).
    private static ?bool $useRustFfi = null;

    protected $signature = 'releases:fingerprint-transfer {--limit=1000 : Maximum legacy jobs to transfer}
        {--import-legacy : Atomically import missing sources while transferring the existing backlog}
        {--dry-run : Validate sources without changing either queue}';

    protected $description = 'Transfer the retained Redis fingerprint backlog to database jobs for Rust workers';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    public static function clampBatchLimit(mixed $limit): int
    {
        return self::useRustFfi() ? self::clampBatchLimitNative($limit) : self::clampBatchLimitPhp($limit);
    }

    public static function clampBatchLimitPhp(mixed $limit): int
    {
        return max(1, min(10000, (int) $limit));
    }

    public static function clampBatchLimitNative(mixed $limit): int
    {
        return GetArticleRange::transformNative(9, [$limit], $result) && \is_int($result) ? $result : self::clampBatchLimitPhp($limit);
    }

    /** The release a legacy PHP fingerprint job targets; null for any other or malformed payload. */
    public static function extractReleaseIdFromQueuePayload(string $rawPayload): ?int
    {
        return self::useRustFfi() ? self::extractReleaseIdFromQueuePayloadNative($rawPayload) : self::extractReleaseIdFromQueuePayloadPhp($rawPayload);
    }

    public static function extractReleaseIdFromQueuePayloadPhp(string $rawPayload): ?int
    {
        $data = json_decode($rawPayload, true, 512);
        if (! \is_array($data) || ($data['displayName'] ?? null) !== CollectReleaseFingerprintMetadata::class) {
            return null;
        }
        $command = $data['data']['command'] ?? '';
        if (! \is_string($command) || preg_match(self::RELEASE_ID_PATTERN, $command, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public static function extractReleaseIdFromQueuePayloadNative(string $rawPayload): ?int
    {
        return GetArticleRange::transformNative(10, [$rawPayload], $result) && ($result === null || \is_int($result))
            ? $result
            : self::extractReleaseIdFromQueuePayloadPhp($rawPayload);
    }

    public static function formatCompletionSummary(bool $dryRun, int $transferred): string
    {
        return self::useRustFfi() ? self::formatCompletionSummaryNative($dryRun, $transferred) : self::formatCompletionSummaryPhp($dryRun, $transferred);
    }

    public static function formatCompletionSummaryPhp(bool $dryRun, int $transferred): string
    {
        return ($dryRun ? 'Validated ' : 'Transferred ').$transferred.' fingerprint jobs.';
    }

    public static function formatCompletionSummaryNative(bool $dryRun, int $transferred): string
    {
        return GetArticleRange::transformNative(11, [$dryRun, $transferred], $result) && \is_string($result)
            ? $result
            : self::formatCompletionSummaryPhp($dryRun, $transferred);
    }

    public function handle(RedisManager $manager): int
    {
        $redis = $manager->connection('default');
        $key = 'queues:release-fingerprint';
        if ((int) $redis->zcard($key.':reserved') !== 0) {
            $this->error('Drain the PHP fingerprint workers first; reserved jobs are still running.');

            return self::FAILURE;
        }
        $limit = self::clampBatchLimit($this->option('limit'));
        $transferred = 0;
        try {
            foreach (['ready', 'delayed'] as $kind) {
                $index = 0;
                while ($transferred < $limit) {
                    $payload = $kind === 'ready'
                        ? $redis->lindex($key, $this->option('dry-run') ? $index : 0)
                        : ($redis->zrange($key.':delayed', $this->option('dry-run') ? $index : 0, $this->option('dry-run') ? $index : 0)[0] ?? null);
                    if (! is_string($payload)) {
                        break;
                    }
                    $id = self::extractReleaseIdFromQueuePayload($payload) ?? self::rejectPayload($payload);
                    if (! $this->option('dry-run')) {
                        // Commit SQL first. A crash before Redis removal only repeats an idempotent enqueue.
                        if (! app(DatabaseFingerprintQueue::class)->enqueue($id, importLegacy: (bool) $this->option('import-legacy'))
                            && DB::table('releases')->where('id', $id)->exists()) {
                            throw new RuntimeException('Release '.$id.' has no database NZB; import it before transfer.');
                        }
                        $removed = $kind === 'ready' ? ($redis instanceof PhpRedisConnection
                            ? $redis->lrem($key, 1, $payload) : $redis->command('lrem', [$key, 1, $payload])) : $redis->zrem($key.':delayed', $payload);
                        if ((int) $removed !== 1) {
                            throw new RuntimeException('Legacy queue changed concurrently; SQL copy is retained.');
                        }
                    } elseif (DB::table('releases')->where('id', $id)->exists()
                        && ! DB::table('release_nzb_contents')->where('releases_id', $id)->exists()) {
                        throw new RuntimeException('Release '.$id.' has no database NZB.');
                    }
                    $transferred++;
                    $index++;
                }
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->line('Transferred '.$transferred.'; run again to resume.');

            return self::FAILURE;
        }
        $this->info(self::formatCompletionSummary((bool) $this->option('dry-run'), $transferred));

        return self::SUCCESS;
    }

    /**
     * Repeats the original checks for a rejected payload, so a decode or type error keeps its own message.
     */
    private static function rejectPayload(string $payload): never
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (($data['displayName'] ?? null) === CollectReleaseFingerprintMetadata::class) {
            preg_match(self::RELEASE_ID_PATTERN, $data['data']['command'] ?? '');
        }

        throw new RuntimeException('Unexpected legacy payload; nothing was removed.');
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
