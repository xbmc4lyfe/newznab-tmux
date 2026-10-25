<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service for backfilling Usenet groups with historical articles.
 *
 * This service handles downloading older articles from Usenet groups
 * to fill in historical data. It supports:
 * - Backfilling by article count or target date
 * - Safe backfill with date-based targeting
 * - Automatic group disable when backfill limit is reached
 */
final class BackfillService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_backfill_calculate(uint32_t mode, const int64_t *input, size_t len, int64_t *out, size_t cap);
        C;

    // PHP remains the default unless every paired common/uncommon mean and p99 gains 1.5x.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  list<int>  $values
     * @return list<int>|null
     */
    private static function calculateNative(int $mode, array $values, int $size): ?array
    {
        try {
            $ffi = self::ffi();
            if ($ffi === null || count($values) > 1024) {
                return null;
            }
            $input = $ffi->new('int64_t['.max(1, count($values)).']');
            foreach ($values as $i => $value) {
                $input[$i] = $value;
            }
            $out = $ffi->new('int64_t['.$size.']');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_backfill_calculate($mode, $input, count($values), $out, $size);
            if ($written !== $size) {
                return null;
            }
            $result = [];
            for ($i = 0; $i < $size; $i++) {
                $result[] = (int) $out[$i];
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private const DEFAULT_ARTICLE_COUNT = 20000;

    private BackfillConfig $config;

    private BinariesService $binaries;

    private NNTPService $nntp;

    public function __construct(
        ?BackfillConfig $config = null,
        ?BinariesService $binaries = null,
        ?NNTPService $nntp = null,
    ) {
        $this->config = $config ?? BackfillConfig::fromSettings();
        $this->binaries = $binaries ?? new BinariesService;
        $this->nntp = $nntp ?? new NNTPService;
    }

    /**
     * Backfill all groups or a specific group.
     *
     * @param  string  $groupName  Optional specific group to backfill
     * @param  int|string  $articles  Number of articles to backfill, or empty for date-based
     * @param  string  $type  Backfill type filter
     *
     * @throws Throwable
     */
    public function backfillAllGroups(string $groupName = '', int|string $articles = '', string $type = ''): void
    {
        $groups = $this->getGroupsToBackfill($groupName, $type);

        if ($groups === []) {
            $this->log('No groups specified. Ensure groups are added to database for updating.', 'warning');

            return;
        }

        $groupCount = \count($groups);
        $this->logBackfillStart($groupCount);

        $articles = $this->normalizeArticleCount($articles);
        $startTime = now();

        foreach ($groups as $index => $group) {
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                break;
            }
            $this->logGroupProgress($groupName, $index + 1, $groupCount); // @phpstan-ignore binaryOp.invalid
            $this->backfillGroup($group->toArray(), $groupCount - $index - 1, $articles); // @phpstan-ignore binaryOp.invalid
        }

        $this->logBackfillComplete($startTime);
    }

    /**
     * Backfill a single group.
     *
     * @param  array<string, mixed>  $groupArr  Group data array
     * @param  int  $remainingGroups  Number of groups remaining after this one
     * @param  int|string  $articles  Number of articles to backfill, or empty for date-based
     *
     * @throws Throwable
     */
    public function backfillGroup(array $groupArr, int $remainingGroups, int|string $articles = ''): void
    {
        if (app(ProcessingRuntimeStateRepository::class)->stopRequested() || ! app(CandidateRepository::class)->capacityAvailable()) {
            return;
        }
        $startTime = now();
        $this->binaries->logIndexerStart();

        $shortGroupName = $this->getShortGroupName($groupArr['name']);

        if (! $this->validateGroupState($groupArr, $shortGroupName)) {
            return;
        }

        $serverData = $this->selectNntpGroup($groupArr['name']);
        if ($serverData === null) {
            return;
        }
        $this->binaries->setNntp($this->nntp);

        $this->log("Processing {$shortGroupName}", 'primary');

        $targetPost = $this->calculateTargetPost($groupArr, $articles, $serverData);

        if (! $this->validateTargetPost($groupArr, $targetPost, $serverData, $shortGroupName)) {
            return;
        }

        $this->logGroupInfo($groupArr, $serverData, $targetPost, $shortGroupName);

        $this->processBackfillChunks($groupArr, $targetPost, $remainingGroups, $shortGroupName);

        $this->logGroupComplete($shortGroupName, $startTime);
    }

    /**
     * Safe backfill - backfill groups that haven't reached the safe backfill date.
     *
     * @param  int|string  $articles  Number of articles to backfill
     *
     * @throws Throwable
     */
    public function safeBackfill(int|string $articles = ''): void
    {
        $group = UsenetGroup::query()
            ->whereBetween('first_record_postdate', [Carbon::createFromDate((int) $this->config->safeBackFillDate), now()])
            ->where('backfill', '=', 1)
            ->select(['name'])
            ->orderBy('name')
            ->first();

        if ($group === null) {
            $message = sprintf(
                'No groups to backfill, they are all at the target date %s, or you have not enabled them to be backfilled in the groups page.',
                $this->config->safeBackFillDate
            );
            exit($message.PHP_EOL);
        }

        $this->backfillAllGroups($group->name, $articles);
    }

    /**
     * Get groups to backfill based on criteria.
     *
     * @return array<string, mixed>
     */
    private function getGroupsToBackfill(string $groupName, string $type): array
    {
        if ($groupName !== '') {
            $group = UsenetGroup::getByName($groupName);

            return $group ? [$group] : [];
        }

        return UsenetGroup::getActiveBackfill($type)->all();
    }

    /**
     * Normalize article count parameter.
     */
    private function normalizeArticleCount(int|string $articles): int|string
    {
        if ($articles !== '' && ! is_numeric($articles)) {
            return self::DEFAULT_ARTICLE_COUNT;
        }

        return $articles;
    }

    /**
     * Get shortened group name for display.
     */
    private function getShortGroupName(string $groupName): string
    {
        return str_replace('alt.binaries', 'a.b', $groupName);
    }

    /**
     * Validate that group is in a valid state for backfilling.
     *
     * @param  array<string, mixed>  $groupArr
     */
    private function validateGroupState(array $groupArr, string $shortGroupName): bool
    {
        if ($groupArr['first_record'] <= 0) {
            $this->log(
                "You need to run update_binaries on {$shortGroupName}. Otherwise the group is dead, you must disable it.",
                'error'
            );

            return false;
        }

        return true;
    }

    /**
     * Select NNTP group and return server data.
     *
     * @return array<string, mixed>
     */
    private function selectNntpGroup(string $groupName): ?array
    {
        $data = $this->nntp->selectGroup($groupName);

        if ($this->nntp->isError($data)) {
            $data = $this->nntp->dataError($this->nntp, $groupName);
            if ($this->nntp->isError($data)) {
                return null;
            }
        }

        return $data;
    }

    /**
     * Calculate target post number based on articles count or date.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $serverData
     */
    private function calculateTargetPost(array $groupArr, int|string $articles, array $serverData): int
    {
        if ($articles !== '') {
            return self::targetArticle((int) $groupArr['first_record'], (int) $articles, (int) $serverData['first']);
        }

        return self::clampDatePost((int) $this->binaries->daytopost($groupArr['backfill_target'], $serverData), (int) $serverData['first']);
    }

    /**
     * Validate that target post is achievable.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $serverData
     */
    private function validateTargetPost(array $groupArr, int $targetPost, array $serverData, string $shortGroupName): bool
    {
        if ($targetPost >= $groupArr['first_record'] || $groupArr['first_record'] <= $serverData['first']) {
            $message = "We have hit the maximum we can backfill for {$shortGroupName}";
            $message .= $this->config->disableBackfillGroup
                ? ', disabling backfill on it.'
                : ', skipping it, consider disabling backfill on it.';

            if ($this->config->disableBackfillGroup) {
                UsenetGroup::updateGroupStatus($groupArr['id'], 'backfill', 0);
            }

            $this->log($message, 'notice');

            return false;
        }

        return true;
    }

    /**
     * Process backfill in chunks.
     *
     * @param  array<string, mixed>  $groupArr
     */
    private function processBackfillChunks(array $groupArr, int $targetPost, int $remainingGroups, string $shortGroupName): void
    {
        $messageBuffer = $this->binaries->getMessageBuffer();
        [$first, $last] = self::backfillChunk((int) $groupArr['first_record'], $targetPost, $messageBuffer);

        while (true) {
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested() || ! app(CandidateRepository::class)->capacityAvailable()) {
                return;
            }
            $this->logChunkProgress($first, $last, $shortGroupName, $remainingGroups, $targetPost);

            flush();
            $scanResult = $this->binaries->scan($groupArr, $first, $last, $this->config->safePartRepair);
            if ($scanResult === [] && $this->binaries->completedRangeEnd() === null) {
                throw new \RuntimeException('Backfill overview failed; the range must be retried.');
            }

            $this->updateGroupRecord($groupArr, $first, $scanResult);

            if ($first === $targetPost) {
                break;
            }

            // Move to next chunk
            [$first, $last] = self::backfillChunk($first, $targetPost, $messageBuffer);
        }
    }

    /**
     * Update group record with new first_record and postdate.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $scanResult
     */
    private function updateGroupRecord(array $groupArr, int $first, ?array $scanResult): void
    {
        $newDate = isset($scanResult['firstArticleDate'])
            ? strtotime($scanResult['firstArticleDate'])
            : $this->binaries->postdate($first, $this->nntp->selectGroup($groupArr['name']));

        DB::update(
            'UPDATE usenet_groups SET first_record_postdate = FROM_UNIXTIME(?), first_record = ?, last_updated = NOW() WHERE id = ?',
            [$newDate, $first, $groupArr['id']]
        );
    }

    /**
     * Log message with appropriate styling.
     */
    private function log(string $message, string $type = 'primary'): void
    {
        if (! $this->config->echoCli) {
            return;
        }

        match ($type) {
            'header' => cli()->header($message),
            'warning' => cli()->warning($message),
            'error' => cli()->error($message),
            'notice' => cli()->notice($message),
            default => cli()->primary($message),
        };
    }

    /**
     * Log backfill start information.
     */
    private function logBackfillStart(int $groupCount): void
    {
        $compressionStatus = $this->config->compressedHeaders ? 'Yes' : 'No';
        $this->log("Backfilling: {$groupCount} group(s) - Using compression? {$compressionStatus}", 'header');
    }

    /**
     * Log group progress.
     */
    private function logGroupProgress(string $groupName, int $current, int $total): void
    {
        if ($groupName === '') {
            $this->log("Starting group {$current} of {$total}", 'header');
        }
    }

    /**
     * Log backfill completion.
     */
    private function logBackfillComplete(Carbon $startTime): void
    {
        $duration = now()->diffInSeconds($startTime, true);
        $this->log("Backfilling completed in {$duration} seconds.");
    }

    /**
     * Log group info before processing.
     *
     * @param  array<string, mixed>  $groupArr
     * @param  array<string, mixed>  $serverData
     */
    private function logGroupInfo(array $groupArr, array $serverData, int $targetPost, string $shortGroupName): void
    {
        $this->log(sprintf(
            "Group %s's oldest article is %s, newest is %s. Our target article is %s. Our oldest article is article %s.",
            $shortGroupName,
            number_format((float) $serverData['first']),
            number_format((float) $serverData['last']),
            number_format($targetPost),
            number_format((float) $groupArr['first_record'])
        ));
    }

    /**
     * Log chunk progress.
     */
    private function logChunkProgress(int $first, int $last, string $shortGroupName, int $remainingGroups, int $targetPost): void
    {
        $this->log(sprintf(
            'Getting %s articles from %s, %d group(s) left. (%s articles in queue)',
            number_format($last - $first + 1),
            $shortGroupName,
            $remainingGroups,
            number_format($first - $targetPost)
        ), 'header');
    }

    /**
     * Log group completion.
     */
    private function logGroupComplete(string $shortGroupName, Carbon $startTime): void
    {
        $duration = number_format(now()->timestamp - $startTime->timestamp, 2);
        $this->log(PHP_EOL."Group {$shortGroupName} processed in {$duration} seconds.");
    }

    public static function targetArticle(int $first, int $articles, int $oldest): int
    {
        return self::$useRustFfi ? self::targetArticleNative($first, $articles, $oldest) : self::targetArticlePhp($first, $articles, $oldest);
    }

    public static function targetArticleNative(int $first, int $articles, int $oldest): int
    {
        return self::calculateNative(0, [$first, $articles, $oldest], 1)[0] ?? self::targetArticlePhp($first, $articles, $oldest);
    }

    public static function targetArticlePhp(int $first, int $articles, int $oldest): int
    {
        return max((int) round($first - $articles), $oldest);
    }

    public static function clampDatePost(int $target, int $oldest): int
    {
        return self::$useRustFfi ? self::clampDatePostNative($target, $oldest) : self::clampDatePostPhp($target, $oldest);
    }

    public static function clampDatePostNative(int $target, int $oldest): int
    {
        return self::calculateNative(1, [$target, $oldest], 1)[0] ?? self::clampDatePostPhp($target, $oldest);
    }

    public static function clampDatePostPhp(int $target, int $oldest): int
    {
        return max($target, $oldest);
    }

    /** @return array{int, int} */
    public static function backfillChunk(int $previousFirst, int $target, int $buffer): array
    {
        return self::$useRustFfi ? self::backfillChunkNative($previousFirst, $target, $buffer) : self::backfillChunkPhp($previousFirst, $target, $buffer);
    }

    /** @return array{int, int} */
    public static function backfillChunkNative(int $previousFirst, int $target, int $buffer): array
    {
        $values = self::calculateNative(2, [$previousFirst, $target, $buffer], 2);

        return $values === null ? self::backfillChunkPhp($previousFirst, $target, $buffer) : [$values[0], $values[1]];
    }

    /** @return array{int, int} */
    public static function backfillChunkPhp(int $previousFirst, int $target, int $buffer): array
    {
        $last = $previousFirst - 1;

        return [max($last - $buffer + 1, $target), $last];
    }

    /**
     * PHP stays the default for summarizeBackfillChunkPlanBatch(): the paired benchmark in
     * tests/Unit/RustBinariesTransactionMissedPartBackfillW42ParityTest.php does not clear 1.5x.
     */
    public const bool W421_RUST_FFI_DEFAULT = false;

    private const array W421_CHUNK_KEYS = ['groups', 'invalid_rows', 'bad_bounds', 'exhausted', 'runnable', 'total_chunks', 'total_articles',
        'max_chunks'];

    private static ?bool $w421UseRustFfi = null;

    private static FFI|false|null $w421Ffi = null;

    /**
     * Summarizes backfill chunk plans (`first_record`, `target`, `oldest`): a bound that is not a non-negative int (or
     * 1-18 digit string) is bad; like validateTargetPost(), a target at or above the first record, or a first record
     * at or below the oldest server article, is exhausted. A runnable group backfills `first_record - max(target,
     * oldest)` articles in ceil(articles / max(1, `$buffer`)) backfillChunk() steps; the totals saturate at
     * PHP_INT_MAX and the largest step count is reported. Non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $groups
     * @return array<string, int>
     */
    public static function summarizeBackfillChunkPlanBatch(array $groups, int $buffer = self::DEFAULT_ARTICLE_COUNT): array
    {
        return (self::usesW421RustFfi() ? self::summarizeBackfillChunkPlanBatchNative($groups, $buffer) : null)
            ?? self::summarizeBackfillChunkPlanBatchPhp($groups, $buffer);
    }

    /**
     * @param  array<array-key, mixed>  $groups
     * @return array<string, int>
     */
    public static function summarizeBackfillChunkPlanBatchPhp(array $groups, int $buffer = self::DEFAULT_ARTICLE_COUNT): array
    {
        $summary = array_fill_keys(self::W421_CHUNK_KEYS, 0);
        $summary['groups'] = \count($groups);
        $size = max(1, $buffer);
        foreach ($groups as $group) {
            if (! \is_array($group)) {
                $summary['invalid_rows']++;

                continue;
            }
            $first = BinariesService::w411Int($group['first_record'] ?? null);
            $target = BinariesService::w411Int($group['target'] ?? null);
            $oldest = BinariesService::w411Int($group['oldest'] ?? null);
            if ($first === null || $target === null || $oldest === null || min($first, $target, $oldest) < 0) {
                $summary['bad_bounds']++;
            } elseif ($target >= $first || $first <= $oldest) {
                $summary['exhausted']++;
            } else {
                $summary['runnable']++;
                $articles = $first - max($target, $oldest);
                $chunks = HeaderStorageTransaction::w421Chunks($articles, $size);
                $summary['total_chunks'] = HeaderStorageTransaction::w421SaturatingAdd($summary['total_chunks'], $chunks);
                $summary['total_articles'] = HeaderStorageTransaction::w421SaturatingAdd($summary['total_articles'], $articles);
                $summary['max_chunks'] = max($summary['max_chunks'], $chunks);
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $groups
     * @return array<string, int>|null
     */
    public static function summarizeBackfillChunkPlanBatchNative(array $groups, int $buffer = self::DEFAULT_ARTICLE_COUNT): ?array
    {
        $words = [$buffer, \count($groups)];
        $blob = [];
        foreach ($groups as $group) {
            if (\is_array($group)) {
                BinariesService::w411PackRow($words, $blob, [$group['first_record'] ?? null, $group['target'] ?? null, $group['oldest'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = HeaderStorageTransaction::w421Invoke(self::$w421Ffi, 5, $words, $blob);

        return $values !== null && \count($values) === \count(self::W421_CHUNK_KEYS) ? array_combine(self::W421_CHUNK_KEYS, $values) : null;
    }

    public static function useW421RustFfi(bool $enable = true): void
    {
        self::$w421UseRustFfi = $enable;
    }

    public static function usesW421RustFfi(): bool
    {
        return self::$w421UseRustFfi ?? self::W421_RUST_FFI_DEFAULT;
    }

    public static function resetW421RustFfi(): void
    {
        self::$w421UseRustFfi = null;
        self::$w421Ffi = null;
    }
}
