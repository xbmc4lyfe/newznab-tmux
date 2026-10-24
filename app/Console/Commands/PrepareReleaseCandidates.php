<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\DirtyCandidateSettler;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareReleaseCandidates extends Command
{
    // Keep PHP as the production default: the JSON FFI round trip is slower than these helpers (< 1.5x).
    private static ?bool $useRustFfi = null;

    protected $signature = 'releases:prepare-candidates {--limit=5000 : Maximum metadata records inspected} {--max-seconds=30 : Stop starting metadata reads after this duration} {--diagnostics : Log bounded preparation counters and timings} {--dirty-limit=200 : Maximum dirty candidates whose late headers are archived} {--dirty-seconds=20 : Stop starting dirty-candidate archives after this duration}';

    protected $description = 'Separate complete header inventories from uploads waiting for articles';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    /** @return array{limit: int, max_seconds: int, dirty_limit: int, dirty_seconds: int} */
    public static function normalizePositiveOptions(mixed $limit, mixed $maxSeconds, mixed $dirtyLimit, mixed $dirtySeconds): array
    {
        return self::useRustFfi()
            ? self::normalizePositiveOptionsNative($limit, $maxSeconds, $dirtyLimit, $dirtySeconds)
            : self::normalizePositiveOptionsPhp($limit, $maxSeconds, $dirtyLimit, $dirtySeconds);
    }

    /** @return array{limit: int, max_seconds: int, dirty_limit: int, dirty_seconds: int} */
    public static function normalizePositiveOptionsPhp(mixed $limit, mixed $maxSeconds, mixed $dirtyLimit, mixed $dirtySeconds): array
    {
        return ['limit' => max(1, (int) $limit), 'max_seconds' => max(1, (int) $maxSeconds),
            'dirty_limit' => max(1, (int) $dirtyLimit), 'dirty_seconds' => max(1, (int) $dirtySeconds)];
    }

    /** @return array{limit: int, max_seconds: int, dirty_limit: int, dirty_seconds: int} */
    public static function normalizePositiveOptionsNative(mixed $limit, mixed $maxSeconds, mixed $dirtyLimit, mixed $dirtySeconds): array
    {
        if (GetArticleRange::transformNative(3, [$limit, $maxSeconds, $dirtyLimit, $dirtySeconds], $result) && \is_array($result)
            && array_keys($result) === ['limit', 'max_seconds', 'dirty_limit', 'dirty_seconds']
            && \is_int($result['limit']) && \is_int($result['max_seconds']) && \is_int($result['dirty_limit']) && \is_int($result['dirty_seconds'])) {
            return ['limit' => $result['limit'], 'max_seconds' => $result['max_seconds'],
                'dirty_limit' => $result['dirty_limit'], 'dirty_seconds' => $result['dirty_seconds']];
        }

        return self::normalizePositiveOptionsPhp($limit, $maxSeconds, $dirtyLimit, $dirtySeconds);
    }

    public static function formatClassifiedMessage(int $changed): string
    {
        return self::useRustFfi() ? self::formatClassifiedMessageNative($changed) : self::formatClassifiedMessagePhp($changed);
    }

    public static function formatClassifiedMessagePhp(int $changed): string
    {
        return 'Classified '.$changed.' candidate header inventories.';
    }

    public static function formatClassifiedMessageNative(int $changed): string
    {
        return GetArticleRange::transformNative(4, [$changed], $result) && \is_string($result) ? $result : self::formatClassifiedMessagePhp($changed);
    }

    public static function formatSettledDirtyMessage(int $settled, int $inspected, int $kept): string
    {
        return self::useRustFfi()
            ? self::formatSettledDirtyMessageNative($settled, $inspected, $kept)
            : self::formatSettledDirtyMessagePhp($settled, $inspected, $kept);
    }

    public static function formatSettledDirtyMessagePhp(int $settled, int $inspected, int $kept): string
    {
        return 'Settled '.$settled.' of '.$inspected.' dirty candidates ('.$kept.' late collections kept for a later pass).';
    }

    public static function formatSettledDirtyMessageNative(int $settled, int $inspected, int $kept): string
    {
        return GetArticleRange::transformNative(5, [$settled, $inspected, $kept], $result) && \is_string($result)
            ? $result
            : self::formatSettledDirtyMessagePhp($settled, $inspected, $kept);
    }

    public function handle(CandidatePreparationService $service, CandidateRepository $repository, DirtyCandidateSettler $settler): int
    {
        $locks = new VerificationWorkerLocks;
        if (! $locks->startPreparation()) {
            $this->info('Candidate preparation or exclusive maintenance is already running.');

            return self::SUCCESS;
        }
        $report = null;
        $observer = $this->option('diagnostics') ? static function (array $summary) use (&$report): void {
            $report = $summary;
        } : null;
        try {
            $options = self::normalizePositiveOptions($this->option('limit'), $this->option('max-seconds'),
                $this->option('dirty-limit'), $this->option('dirty-seconds'));
            $changed = $service->prepare($options['limit'], $options['max_seconds'],
                fn (bool $advance): bool => $locks->preferPending($advance), $observer);
            $this->info(self::formatClassifiedMessage($changed));
            $promoted = $repository->promotePartial($options['limit']);
            $this->info('Queued '.$promoted.' stalled incomplete uploads for partial publication.');
            // Late headers make a candidate dirty until archive() merges them; release creation may not reach
            // their collection for a long time on a large group.
            $dirty = $settler->settle($options['dirty_limit'], $options['dirty_seconds']);
            $this->info(self::formatSettledDirtyMessage($dirty['settled'], $dirty['inspected'], $dirty['kept']));
            if ($report !== null) {
                $report['dirty_settle'] = $dirty;
            }

            return self::SUCCESS;
        } finally {
            $locks->close();
            if ($report !== null) {
                try {
                    Log::info('Candidate preparation run completed.', $report);
                } catch (Throwable) {
                    // Logging cannot mask preparation failures or change successful command outcomes.
                }
            }
        }
    }

    private static function useRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }
}
