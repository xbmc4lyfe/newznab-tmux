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
use FFI\CData;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52 parity test). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    private const string W52_FFI_CDEF = 'ssize_t nntmux_w521_backfill_article_date_batch(const char *dates, size_t dates_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 52 batch routed to Rust or PHP, then restores the routing. */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * strtotime() of each article `Date` header ({@see updateGroupRecord()} resolves the first
     * article date this way).
     *
     * @param  list<string>  $dates
     * @return list<int|false>
     */
    public static function articleDateTimestamps(array $dates): array
    {
        return self::usesW52RustFfi() ? self::articleDateTimestampsNative($dates) : self::articleDateTimestampsPhp($dates);
    }

    /**
     * @param  list<string>  $dates
     * @return list<int|false>
     */
    public static function articleDateTimestampsPhp(array $dates): array
    {
        $stamps = [];
        foreach ($dates as $date) {
            $stamps[] = strtotime($date);
        }

        return $stamps;
    }

    /**
     * Rust parses the RFC 5322 shape NNTP servers send (`[Www, ]D Mon YYYY HH:MM:SS ZONE`) and
     * reports every other row, which PHP resolves with strtotime(); falls back to PHP when the
     * library is missing or declines the batch (a date holding a NUL byte).
     *
     * @param  list<string>  $dates
     * @return list<int|false>
     */
    public static function articleDateTimestampsNative(array $dates): array
    {
        $count = \count($dates);
        $buffer = $count === 0 ? null : self::w52Buffer(2 * $count);
        if ($buffer === null) {
            return self::articleDateTimestampsPhp($dates);
        }
        [$ffi, $out, $cap] = $buffer;
        $dates = array_values($dates);
        $joined = implode("\0", $dates);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w52Decode($out, $ffi->nntmux_w521_backfill_article_date_batch($joined, \strlen($joined), $count, $out, $cap), 2 * $count);
        } catch (Throwable) {
            $words = null;
        }
        if ($words === null || \count($words) < $count) {
            return self::articleDateTimestampsPhp($dates);
        }
        $stamps = \array_slice($words, 0, $count);
        foreach (\array_slice($words, $count) as $index) {
            $stamps[$index] = strtotime($dates[$index]);
        }

        return $stamps;
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: CData, 2: int}|null
     */
    private static function w52Buffer(int $words): ?array
    {
        $buffer = self::w52RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w52Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 52 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('backfill_binaries_w52', self::W52_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    // ========================================================================
    // WAVE 66.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.80x, p99 7.91x; uncommon mean 7.00x, p99 5.55x); see RustBackfillBinariesConfigServiceHandlerW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    public const string W66_BACKFILL_SERVICE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w661_backfill_service_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w66BackfillServicePlanBatch(). */
    public const int W66_BACKFILL_SERVICE_TRAILER = 20;

    public static function usesW66RustFfi(): bool
    {
        $override = self::w66RustFfiState();

        return \is_bool($override) ? $override : self::W66_RUST_FFI_DEFAULT;
    }

    /** Forces the BackfillService Wave 66 Rust path on until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Forces the BackfillService Wave 66 PHP path until reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    /**
     * Runs $callback with the BackfillService Wave 66 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 66.1 group backfill plans, one per `first_record|server_first|server_last|articles|date_post|buffer` row (trimmed
     * fields of 1-15 digits; empty numbers 0, buffer empty or 0 is 20000). A non-empty article count targets
     * `max(first_record - articles, server_first)`; otherwise the date post targets `max(date_post, server_first)`. Decision:
     * 0 empty row, 1 malformed number, 2 no first record, 3 server range inverted, 4 first record at or below the oldest
     * article, 5 target already reached, 6 runnable. Runnable rows walk backwards over `first_record - target` articles in
     * `ceil(span / buffer)` chunks whose first window holds `min(span, buffer)` articles. Flags: 1 article count, 2 date post,
     * 4 buffer defaulted, 8 first record beyond the server's newest, 16 target clamped to the oldest article, 32 single chunk,
     * 64 non-ASCII, 128 control byte. Per item `decision | flags << 3 | min(chunks, 65535) << 11 | min(window, 1048575) << 27 |
     * bitLength(span) << 47 | bitLength(first_record) << 53`; then 7 decision and 8 flag counts, the chunk and span sums,
     * the largest span and chunk count, and the lowest runnable target.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BackfillServicePlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66BackfillServicePlanBatchNative($items) : self::w66BackfillServicePlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BackfillServicePlanBatchNative(array $items): array
    {
        return self::w66Invoke($items, \count($items) + self::W66_BACKFILL_SERVICE_TRAILER) ?? self::w66BackfillServicePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BackfillServicePlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W66_BACKFILL_SERVICE_TRAILER, 0);
        $minTarget = null;
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawFirst, $rawOldest, $rawNewest, $rawArticles, $rawDatePost, $rawBuffer] = explode('|', $item, 6) + ['', '', '', '', '', ''];
            $first = self::w66Uint($rawFirst, 0);
            $oldest = self::w66Uint($rawOldest, 0);
            $newest = self::w66Uint($rawNewest, 0);
            $articles = self::w66Uint($rawArticles, -2);
            $datePost = self::w66Uint($rawDatePost, 0);
            $buffer = self::w66Uint($rawBuffer, 20_000);
            $articlesMode = $articles !== -2;
            $malformed = $first < 0 || $oldest < 0 || $newest < 0 || $articles === -1 || $datePost < 0 || $buffer < 0;
            $defaulted = $buffer === 0;
            if ($defaulted) {
                $buffer = 20_000;
            }
            $rawTarget = $articlesMode ? $first - $articles : $datePost;
            $target = max($rawTarget, $oldest);
            $decision = match (true) {
                trim($item) === '' => 0,
                $malformed => 1,
                $first === 0 => 2,
                $newest < $oldest => 3,
                $first <= $oldest => 4,
                $target >= $first => 5,
                default => 6,
            };
            $span = $chunks = $window = 0;
            if ($decision === 6) {
                $span = $first - $target;
                $chunks = intdiv($span + $buffer - 1, $buffer);
                $window = min($span, $buffer);
            }
            $flags = ($articlesMode ? 1 : 2) | ($defaulted ? 4 : 0) | (! $malformed && $first > $newest ? 8 : 0)
                | (! $malformed && $rawTarget < $oldest ? 16 : 0) | ($decision === 6 && $chunks === 1 ? 32 : 0)
                | (preg_match('/[\x80-\xff]/', $item) === 1 ? 64 : 0) | (strcspn($item, self::W66_CONTROL_BYTES) !== \strlen($item) ? 128 : 0);
            $tail[$decision]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[7 + $b] += $flags >> $b & 1;
            }
            if ($decision === 6) {
                $tail[15] += min($chunks, 1 << 40);
                $tail[16] += min($span, 1 << 40);
                $tail[17] = max($tail[17], $span);
                $tail[18] = max($tail[18], $chunks);
                $minTarget = $minTarget === null ? $target : min($minTarget, $target);
            }
            $out[] = $decision | $flags << 3 | min($chunks, 65_535) << 11 | min($window, 1_048_575) << 27 | self::w66BitLength($span) << 47
                | self::w66BitLength($first) << 53;
        }
        $tail[19] = $minTarget ?? 0;
        array_push($out, ...$tail);

        return $out;
    }

    private const string W66_DIGITS = '0123456789';

    private const string W66_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w66Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W66_DIGITS) === $length ? (int) $value : -1;
    }

    private static function w66BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Wave 66 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('backfill_service_w661', self::W66_BACKFILL_SERVICE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 66.1 BackfillService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w66Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w66RustFfiState('ffi');
            $out = self::w66RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w66RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W66_BACKFILL_SERVICE_FFI_CDEF.)
            $written = $ffi->nntmux_w661_backfill_service_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
