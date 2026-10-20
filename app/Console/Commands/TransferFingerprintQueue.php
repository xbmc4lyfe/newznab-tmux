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
    protected $signature = 'releases:fingerprint-transfer {--limit=1000 : Maximum legacy jobs to transfer}
        {--dry-run : Validate sources without changing either queue}';

    protected $description = 'Transfer the retained Redis fingerprint backlog to database jobs for Rust workers';

    public function handle(RedisManager $manager): int
    {
        $redis = $manager->connection('default');
        $key = 'queues:release-fingerprint';
        if ((int) $redis->zcard($key.':reserved') !== 0) {
            $this->error('Drain the PHP fingerprint workers first; reserved jobs are still running.');

            return self::FAILURE;
        }
        $limit = max(1, min(10000, (int) $this->option('limit')));
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
                    $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                    if (($data['displayName'] ?? null) !== CollectReleaseFingerprintMetadata::class
                        || ! preg_match('/releaseId";i:([1-9][0-9]*);/', $data['data']['command'] ?? '', $matches)) {
                        throw new RuntimeException('Unexpected legacy payload; nothing was removed.');
                    }
                    $id = (int) $matches[1];
                    if (! $this->option('dry-run')) {
                        // Commit SQL first. A crash before Redis removal only repeats an idempotent enqueue.
                        if (! app(DatabaseFingerprintQueue::class)->enqueue($id)
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
        $this->info(($this->option('dry-run') ? 'Validated ' : 'Transferred ').$transferred.' fingerprint jobs.');

        return self::SUCCESS;
    }
}
