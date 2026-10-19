<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\UsenetGroup;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Tmux\CollectionDowntimeCompensator;
use DariusIII\NetNntp\Error as NntpError;
use DariusIII\NetNntp\Protocol\ResponseCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for downloading and processing of Usenet binary headers.
 *
 * This service orchestrates the header processing workflow using:
 * - HeaderParser for parsing and filtering headers
 * - HeaderStorageService for storing headers to database
 * - MissedPartHandler for part repair tracking
 * - BinariesConfig for configuration
 */
class BinariesService
{
    private BinariesConfig $config;

    private HeaderParser $headerParser;

    private HeaderStorageService $headerStorage;

    private MissedPartHandler $missedPartHandler;

    private ?NNTPService $nntp = null;

    private ProcessingRuntimeStateRepository $runtimeState;

    // Timing metrics
    private float $timeHeaders = 0;

    private float $timeCleaning = 0;

    private float $timeInsert = 0;

    private Carbon $startLoop;

    private Carbon $startCleaning;

    private Carbon $startPR;

    private Carbon $startUpdate;

    // Scan state
    /**
     * @var array<string, mixed>
     */
    private array $groupMySQL = [];

    private int $first = 0;

    private int $last = 0;

    private ?int $completedRangeEnd = null;

    private int $notYEnc = 0;

    private int $headersBlackListed = 0;

    private int $headersObfuscated = 0;

    /** @var array<int, true> */
    private array $headersReceived = [];

    public function __construct(
        ?BinariesConfig $config = null,
        ?HeaderParser $headerParser = null,
        ?HeaderStorageService $headerStorage = null,
        ?MissedPartHandler $missedPartHandler = null,
        ?NNTPService $nntp = null,
        ?ProcessingRuntimeStateRepository $runtimeState = null,
    ) {
        $this->config = $config ?? BinariesConfig::fromSettings();
        $this->headerParser = $headerParser ?? new HeaderParser;
        $this->headerStorage = $headerStorage ?? new HeaderStorageService(config: $this->config);
        $this->missedPartHandler = $missedPartHandler ?? new MissedPartHandler(
            $this->config->partRepairLimit,
            $this->config->partRepairMaxTries,
            $this->config->sqlChunkSize
        );
        $this->nntp = $nntp;
        $this->runtimeState = $runtimeState ?? app(ProcessingRuntimeStateRepository::class);
        $this->startUpdate = Carbon::now();
    }

    /**
     * Set NNTP connection (for external injection).
     */
    public function setNntp(NNTPService $nntp): void
    {
        $this->nntp = $nntp;
    }

    /**
     * Get the NNTP connection, creating one if needed.
     */
    public function getNntp(): NNTPService
    {
        if ($this->nntp === null) {
            $this->nntp = new NNTPService;
        }

        return $this->nntp;
    }

    /**
     * Get the configuration object.
     */
    public function getConfig(): BinariesConfig
    {
        return $this->config;
    }

    /**
     * Get the message buffer size.
     */
    public function getMessageBuffer(): int
    {
        return $this->config->messageBuffer;
    }

    /** The requested range was fully downloaded and stored (or durably queued for repair). */
    public function completedRangeEnd(): ?int
    {
        return $this->completedRangeEnd;
    }

    /**
     * Download new headers for all active groups.
     *
     * @param  int  $maxHeaders  (Optional) How many headers to download max.
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function updateAllGroups(int $maxHeaders = 100000): void
    {
        $groups = UsenetGroup::getActive()->toArray();
        $groupCount = \count($groups);

        if ($groupCount === 0) {
            $this->log(
                'No groups specified. Ensure groups are added to NNTmux\'s database for updating.',
                __FUNCTION__,
                'warning'
            );

            return;
        }

        $counter = 1;
        $allTime = now();

        $this->log(
            'Updating: '.$groupCount.' group(s) - Using compression? '.($this->config->compressedHeaders ? 'Yes' : 'No'),
            __FUNCTION__,
            'header'
        );

        foreach ($groups as $group) {
            $this->log(
                'Starting group '.$counter.' of '.$groupCount,
                __FUNCTION__,
                'header'
            );

            try {
                $this->updateGroup($group, $maxHeaders);
            } catch (\Throwable $e) {
                $this->logError('Error updating group '.$group['name'].': '.$e->getMessage());
            }

            $counter++;
        }

        $endTime = now()->diffInSeconds($allTime, true);
        $this->log(
            'Updating completed in '.$endTime.Str::plural(' second', (int) $endTime),
            __FUNCTION__,
            'primary'
        );
    }

    /**
     * Log the indexer start time.
     */
    public function logIndexerStart(): void
    {
        $this->runtimeState->markBinaryRun();
    }

    /**
     * Download new headers for a single group.
     *
     * @param  array<string, mixed>  $groupMySQL  Array of MySQL results for a single group.
     * @param  int  $maxHeaders  (Optional) How many headers to download max.
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function updateGroup(array $groupMySQL, int $maxHeaders = 0): void
    {
        if (! app(CandidateRepository::class)->capacityAvailable()) {
            return;
        }
        $startGroup = now();
        // Ingestion is live even when the group turns out to be caught up (the early
        // returns below), so a monitor-only recovery doesn't count this as downtime.
        try {
            app(CollectionDowntimeCompensator::class)->noteIngestion();
        } catch (\Throwable) {
            // Best effort.
        }
        $this->logIndexerStart();

        $nntp = $this->getNntp();

        // Select the group on the NNTP server
        $groupNNTP = $this->selectNntpGroup($groupMySQL, $nntp);
        if ($groupNNTP === null) {
            return;
        }

        if ($this->config->echoCli) {
            cli()->primary('Processing '.$groupMySQL['name']);
        }

        // Attempt to repair any missing parts before grabbing new ones
        if ((int) $groupMySQL['last_record'] !== 0 && $this->config->partRepair) {
            if ($this->config->echoCli) {
                cli()->primary('Part repair enabled. Checking for missing parts.');
            }
            $this->partRepair($groupMySQL);
        } elseif ($this->config->echoCli && (int) $groupMySQL['last_record'] !== 0) {
            cli()->primary('Part repair disabled by user.');
        }

        // Generate postdate for first record, for those that upgraded
        if ($groupMySQL['first_record_postdate'] === null && (int) $groupMySQL['first_record'] !== 0) {
            $groupMySQL['first_record_postdate'] = $this->postdate($groupMySQL['first_record'], $groupNNTP);
            UsenetGroup::query()->where('id', $groupMySQL['id'])->update([
                'first_record_postdate' => Carbon::createFromTimestamp($groupMySQL['first_record_postdate'], date_default_timezone_get()),
            ]);
        }

        // Calculate article range
        $range = $this->calculateArticleRange($groupMySQL, $groupNNTP, $maxHeaders);

        if ($range['total'] <= 0) {
            $this->outputNoNewArticles($groupMySQL, $groupNNTP, $range);

            return;
        }

        $this->outputNewArticlesInfo($groupMySQL, $groupNNTP, $range);
        $this->processArticleRange($groupMySQL, $groupNNTP, $range);

        if ($this->config->echoCli) {
            $endGroup = now()->diffInSeconds($startGroup, true);
            cli()->primary(
                PHP_EOL.'Group '.$groupMySQL['name'].' processed in '.$endGroup.Str::plural(' second', (int) $endGroup)
            );
        }
    }

    /**
     * Loop over range of wanted headers, insert headers into DB.
     *
     * @param  array<string, mixed>  $groupMySQL  The group info from mysql.
     * @param  int  $first  The oldest wanted header.
     * @param  int  $last  The newest wanted header.
     * @param  string  $type  Is this part repair or update or backfill?
     * @param  array<int, mixed>|null  $missingParts  If we are running in part repair, the list of missing article numbers.
     * @return array<string, mixed> Empty on failure.
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function scan(array $groupMySQL, int $first, int $last, string $type = 'update', ?array $missingParts = null): array
    {
        $this->completedRangeEnd = null;
        try {
            if (! app(CandidateRepository::class)->capacityAvailable()) {
                throw new \RuntimeException('Verification backlog is full; scan paused before downloading headers.');
            }
        } catch (\Throwable $exception) {
            if ($type !== 'partrepair') {
                try {
                    $this->missedPartHandler->deferRange($first, $last, (int) $groupMySQL['id']);
                } catch (\Throwable $deferralFailure) {
                    throw new \RuntimeException('Unable to defer headers after capacity admission failed: '
                        .$exception->getMessage().'; '.$deferralFailure->getMessage(), 0, $exception);
                }
            }
            throw $exception;
        }
        $this->startLoop = Carbon::now();
        $this->groupMySQL = $groupMySQL;
        $this->last = $last;
        $this->first = $first;
        $this->notYEnc = $this->headersBlackListed = $this->headersObfuscated = 0;
        $this->headersReceived = [];

        $returnArray = [];
        $partRepair = ($type === 'partrepair');
        $addToPartRepair = ($type === 'update' && $this->config->partRepair);

        // Download headers from NNTP
        $headers = $this->downloadHeaders($partRepair);
        if ($headers === null) {
            if ($partRepair) {
                $this->missedPartHandler->incrementRangeAttempts($groupMySQL['id'], $first, $last);
            }

            return $returnArray;
        }

        $this->startCleaning = Carbon::now();
        $this->timeHeaders = $this->startCleaning->diffInSeconds($this->startLoop, true);

        $msgCount = \count($headers);
        if ($msgCount < 1) {
            $this->completedRangeEnd = $last;

            return $returnArray;
        }

        // Extract article range info
        $returnArray = $this->headerParser->getArticleRange($headers, (int) $this->first, (int) $this->last);

        // Parse and store one bounded chunk at a time. The previous flow built
        // a second full-size parsed header array before storage, doubling peak
        // memory for large XOVER responses.
        $this->startUpdate = Carbon::now();  // Reset before storage begins
        $this->timeCleaning = $this->startUpdate->diffInSeconds($this->startCleaning, true);

        $headersNotInserted = [];
        $repairedNumbers = [];
        $missingPartSet = $missingParts === null
            ? null
            : array_fill_keys(array_map('intval', $missingParts), true);
        $totalHeaders = \count($headers);
        // Detect across the whole range: one chunk can hold too few of an upload's articles.
        $this->headerParser->markPerArticleObfuscated(
            $headers,
            $partRepair ? 0 : $this->config->perArticleObfuscationMinArticles
        );
        for ($offset = 0; $offset < $totalHeaders; $offset += $this->config->headerChunkSize) {
            $rawChunk = array_slice($headers, $offset, $this->config->headerChunkSize);
            $this->headerParser->reset();
            $parseResult = $this->headerParser->parse($rawChunk, $groupMySQL['name'], $partRepair, $missingPartSet);

            foreach ($parseResult['received'] ?? [] as $number) {
                $this->headersReceived[(int) $number] = true;
            }
            foreach ($parseResult['repaired'] ?? [] as $number) {
                $repairedNumbers[(int) $number] = true;
            }
            $this->notYEnc += (int) $parseResult['notYEnc'];
            $this->headersBlackListed += (int) $parseResult['blacklisted'];
            $this->headersObfuscated += (int) ($parseResult['obfuscated'] ?? 0);
            $this->headerParser->flushBlacklistUpdates();

            if ($parseResult['headers'] !== []) {
                try {
                    $headersNotInserted = array_merge(
                        $headersNotInserted,
                        $this->headerStorage->store($parseResult['headers'], $groupMySQL, $addToPartRepair)
                    );
                } catch (\Throwable $e) {
                    Log::error('Binary header storage failed', [
                        'groups_id' => $groupMySQL['id'],
                        'article_count' => \count($parseResult['headers']),
                        'exception' => $e::class,
                        'code' => $e->getCode(),
                    ]);
                    foreach ($parseResult['headers'] as $failedHeader) {
                        if (isset($failedHeader['Number'])) {
                            $headersNotInserted[] = $failedHeader['Number'];
                        }
                    }
                }
            }

            unset($rawChunk, $parseResult);
        }
        unset($headers);

        if ($headersNotInserted !== [] && ! $this->config->partRepair && ! $partRepair) {
            throw new \RuntimeException('Header storage did not complete and part repair is disabled; retry this range.');
        }

        if ($this->config->echoCli && ! $partRepair) {
            $this->outputHeaderInitial();
        }

        $this->startPR = Carbon::now();
        $this->timeInsert = $this->startPR->diffInSeconds($this->startUpdate, true);

        // Handle repaired parts
        if ($partRepair && $repairedNumbers !== []) {
            $this->missedPartHandler->removeRepairedParts(
                array_values(array_diff(array_keys($repairedNumbers), $headersNotInserted)),
                $groupMySQL['id']
            );
        }

        // Handle part repair tracking
        if ($addToPartRepair) {
            $this->handlePartRepairTracking($headersNotInserted);
        } elseif ($type === 'backfill' && $this->config->partRepair && $headersNotInserted !== []) {
            // Headers lost to a storage rollback during backfill get repaired too.
            $this->missedPartHandler->addMissingParts($headersNotInserted, $this->groupMySQL['id']);
        }

        $this->outputHeaderDuration();

        $this->completedRangeEnd = $last;

        return $returnArray;
    }

    /**
     * Attempt to get missing article headers.
     *
     * @param  array<string, mixed>  $groupArr  The info for this group from mysql.
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function partRepair(array $groupArr): void
    {
        if ($this->runtimeState->stopRequested()) {
            return;
        }
        $missingParts = $this->missedPartHandler->getMissingParts($groupArr['id']);
        $missingCount = \count($missingParts);

        if ($missingCount === 0) {
            $this->missedPartHandler->cleanupExhaustedParts($groupArr['id']);

            return;
        }

        if ($this->config->echoCli) {
            cli()->primary('Attempting to repair '.number_format($missingCount).' parts.');
        }

        // Group into continuous ranges
        $ranges = $this->groupMissingPartsIntoRanges($missingParts);

        // Download missing parts in ranges
        $lastAttemptedPart = null;
        $attemptedCount = 0;
        foreach ($ranges as $range) {
            if ($this->runtimeState->stopRequested()) {
                break;
            }
            if ($this->config->echoCli) {
                echo \chr(random_int(45, 46)).PHP_EOL;
            }

            $this->scan($groupArr, $range['partfrom'], $range['partto'], 'partrepair', $range['partlist']);
            $lastAttemptedPart = $range['partto'];
            $attemptedCount += \count($range['partlist']);
        }
        if ($lastAttemptedPart === null) {
            return;
        }

        // Calculate parts repaired
        $lastPartNumber = $lastAttemptedPart;
        $remainingCount = $this->missedPartHandler->getCount($groupArr['id'], $lastPartNumber);
        $partsRepaired = $attemptedCount - $remainingCount;

        // Update attempts on remaining parts
        if (isset($missingParts[$missingCount - 1]->id)) {
            $this->missedPartHandler->incrementAttempts($groupArr['id'], $lastPartNumber);
        }

        if ($this->config->echoCli) {
            cli()->primary(PHP_EOL.number_format($partsRepaired).' parts repaired.');
        }

        // Remove articles that exceeded max tries
        $this->missedPartHandler->cleanupExhaustedParts($groupArr['id']);
    }

    /**
     * Returns unix time for an article number.
     *
     * @param  int|string  $post  The article number to get the time from.
     * @param  array<string, mixed>  $groupData  Usenet group info from NNTP selectGroup method.
     * @return int Timestamp.
     *
     * @throws \Exception
     */
    public function postdate(int|string $post, array $groupData): int
    {
        $nntp = $this->getNntp();
        $currentPost = (int) $post;
        $attempts = 0;
        $date = 0;

        do {
            // Try to get the article date locally first.
            //
            // This query joins parts -> binaries -> collections starting from
            // `parts.number = ?`. The parts table has PRIMARY KEY
            // (binaries_id, number), so without a separate index on `number`
            // the planner can only full-scan parts. The
            // `ix_parts_number` index added in the
            // 2026_05_05 add_cbp_query_indexes migration makes this an index
            // lookup; do not drop it without revisiting this call site.
            // LIMIT 1 is fine because we just need any matching collection
            // date for the article.
            $local = DB::select(
                'SELECT c.date AS date FROM collections c
                    INNER JOIN binaries b ON(c.id=b.collections_id)
                    INNER JOIN parts p ON(b.id=p.binaries_id)
                    WHERE p.number = ?
                    LIMIT 1',
                [$currentPost]
            );

            if (! empty($local)) {
                $date = $local[0]->date;
                break;
            }

            // Try usenet
            $header = $nntp->getXOVER((string) $currentPost);
            if (! NNTPService::isError($header) && isset($header[0]['Date']) && $header[0]['Date'] !== '') {
                $date = $header[0]['Date'];
                break;
            }

            // Try a different article number
            $currentPost = $this->getNextArticleToTry($currentPost, $groupData);
            if ($currentPost === null) {
                break;
            }
        } while ($attempts++ <= 20);

        if (! $date) {
            return time();
        }

        $timestamp = strtotime($date);

        return $timestamp !== false ? $timestamp : time();
    }

    /**
     * Returns article number based on # of days.
     *
     * @param  int  $days  How many days back we want to go.
     * @param  array<string, mixed>  $data  Group data from usenet.
     *
     * @throws \Exception
     */
    public function daytopost(int $days, array $data): string
    {
        foreach (['first', 'last'] as $bound) {
            $value = $data[$bound] ?? null;
            $number = (\is_int($value) || \is_string($value))
                ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($number === false) {
                throw new \RuntimeException('Invalid NNTP article bounds for date targeting.');
            }
            $data[$bound] = $number;
        }
        if ($data['first'] > $data['last']) {
            throw new \RuntimeException('Invalid NNTP article bounds for date targeting.');
        }
        $group = $data['group'] ?? null;
        if (! \is_string($group) || $group === '') {
            throw new \RuntimeException('Cannot resolve a date target without an NNTP group.');
        }
        $selected = $this->getNntp()->selectGroup($group);
        if (NNTPService::isError($selected) || ! \is_array($selected)) {
            throw new \RuntimeException('Cannot select the NNTP group for date targeting.');
        }
        $goalTime = now()->subDays($days)->timestamp;

        // The server's low and high watermarks often name no article (expired or removed), so the
        // bounds use the nearest existing article inside them.
        $first = $this->nearestPostdate($data['first'], $data['last']);
        if ($first === null) {
            throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
        }
        [$data['first'], $firstDate] = $first;
        if ($goalTime < $firstDate) {
            return (string) $data['first'];
        }

        $last = $this->nearestPostdate($data['last'], $data['first']);
        if ($last === null) {
            throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
        }
        [$data['last'], $lastDate] = $last;
        if ($goalTime > $lastDate) {
            return (string) $data['last'];
        }

        if ($this->config->echoCli) {
            cli()->primary(
                'Searching for an approximate article number for group '.$data['group'].' '.$days.' days back.'
            );
        }

        return $this->binarySearchArticleByDate($goalTime, $data);
    }

    /**
     * The exact (number, date) of the existing article nearest to $post toward $limit (inclusive)
     * on the selected provider/group.
     *
     * Usenet article numbers have gaps: expired, cancelled and removed articles, and watermarks that
     * name none. Only a number the server confirms missing is replaced, by the nearest existing one
     * found with overview ranges of growing size; its number and date are both exact, so no number
     * receives another article's date. Returns null when no article exists up to $limit; defers
     * (throws) on any other error, an unknown date, or when the bounded windows end before $limit.
     *
     * @return array{int, int}|null
     */
    private function nearestPostdate(int $post, int $limit): ?array
    {
        $exact = $this->targetPostdate($post);
        if ($exact !== null) {
            return [$post, $exact];
        }
        $upward = $limit >= $post;
        $from = $post;
        foreach ([100, 1000, 10000] as $span) {
            if ($from === $limit) {
                return null;
            }
            $from = $upward ? $from + 1 : $from - 1;
            $to = $upward ? ($limit - $from < $span ? $limit : $from + $span - 1) : ($from - $limit < $span ? $limit : $from - $span + 1);
            [$low, $high] = $upward ? [$from, $to] : [$to, $from];
            $nearest = $this->nearestOverviewRow($this->getNntp()->getXOVER($low.'-'.$high), $low, $high, $upward);
            if ($nearest !== null) {
                return $nearest;
            }
            $from = $to;
        }
        if ($from === $limit) {
            return null;
        }

        throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
    }

    /**
     * The exact (number, date) of the lowest (or highest) article in an overview range, null when the
     * server confirms the range is empty. The nearest article is chosen by number before its date is
     * read, so an existing article with an unknown date defers instead of being skipped.
     *
     * @return array{int, int}|null
     */
    private function nearestOverviewRow(mixed $rows, int $low, int $high, bool $upward): ?array
    {
        if (NNTPService::isError($rows)) {
            if (\in_array((int) $rows->getCode(), [420, 423], true)) {
                return null;
            }
            throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
        }
        if (! \is_array($rows)) {
            throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
        }
        $nearest = null;
        foreach ($rows as $row) {
            $number = \is_array($row) && isset($row['Number']) && (\is_int($row['Number']) || \is_string($row['Number']))
                ? filter_var($row['Number'], FILTER_VALIDATE_INT) : false;
            if ($number === false || $number < $low || $number > $high) {
                throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
            }
            if ($nearest === null || ($upward ? $number < $nearest[0] : $number > $nearest[0])) {
                $nearest = [$number, $row];
            }
        }
        if ($nearest === null) {
            return null;
        }
        $date = isset($nearest[1]['Date']) && \is_string($nearest[1]['Date']) && trim($nearest[1]['Date']) !== ''
            ? strtotime($nearest[1]['Date']) : false;
        if ($date === false) {
            throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
        }

        return [$nearest[0], $date];
    }

    /**
     * Admission needs the requested article's date on the selected provider/group.
     * Stored part numbers and neighboring probes cannot establish that identity. Returns null only
     * when the server confirms there is no such article (420/423 or an empty overview).
     */
    private function targetPostdate(int|string $post): ?int
    {
        $header = $this->getNntp()->getXOVER((string) $post);
        if (NNTPService::isError($header) && \in_array((int) $header->getCode(), [420, 423], true)) {
            return null;
        }
        if ($header === []) {
            return null;
        }
        if (\is_array($header)
            && isset($header[0]['Number'], $header[0]['Date'])
            && (\is_int($header[0]['Number']) || \is_string($header[0]['Number']))
            && (string) $header[0]['Number'] === (string) $post
            && \is_string($header[0]['Date'])
            && trim($header[0]['Date']) !== '') {
            $timestamp = strtotime($header[0]['Date']);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        throw new \RuntimeException('NNTP article date is unavailable; defer date-based indexing.');
    }

    // ==================== Private Helper Methods ====================

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @return array<string, mixed>
     */
    private function selectNntpGroup(array &$groupMySQL, NNTPService $nntp): ?array
    {
        $groupNNTP = $nntp->selectGroup($groupMySQL['name']);

        if (NNTPService::isError($groupNNTP)) {
            $groupNNTP = $nntp->dataError($nntp, $groupMySQL['name']);

            if (isset($groupNNTP['code']) && (int) $groupNNTP['code'] === 411) {
                UsenetGroup::disableIfNotExist($groupMySQL['id']);
            }

            if (NNTPService::isError($groupNNTP)) {
                return null;
            }
        }

        return $groupNNTP;
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @return array<string, mixed>
     */
    private function calculateArticleRange(array $groupMySQL, array $groupNNTP, int $maxHeaders): array
    {
        if ((int) $groupMySQL['last_record'] === 0) {
            $range = $this->calculateNewGroupRange($groupNNTP);
            if ($maxHeaders > 0 && $range['total'] > $maxHeaders) {
                $range['last'] = $range['groupLast'] = $range['first'] + $maxHeaders;
                $range['total'] = $maxHeaders;
            }

            return $range;
        }

        return $this->calculateExistingGroupRange($groupMySQL, $groupNNTP, $maxHeaders);
    }

    /**
     * @param  array<string, mixed>  $groupNNTP
     * @return array<string, mixed>
     */
    private function calculateNewGroupRange(array $groupNNTP): array
    {
        if ($this->config->newGroupScanByDays) {
            $first = (int) $this->daytopost($this->config->newGroupDaysToScan, $groupNNTP);
        } elseif ($groupNNTP['first'] >= ($groupNNTP['last'] - ($this->config->newGroupMessagesToScan + $this->config->messageBuffer))) {
            $first = (int) $groupNNTP['first'];
        } else {
            $first = (int) ($groupNNTP['last'] - ($this->config->newGroupMessagesToScan + $this->config->messageBuffer));
        }

        $leaveOver = $this->config->messageBuffer;
        $last = $groupLast = (int) ($groupNNTP['last'] - $leaveOver);

        if ($last < $first) {
            $last = $groupLast = $first;
        }

        $total = (int) ($groupLast - $first);
        $realTotal = (int) ($groupNNTP['last'] - $first);

        return [
            'first' => $first,
            'last' => $last,
            'groupLast' => $groupLast,
            'total' => $total,
            'realTotal' => $realTotal,
            'leaveOver' => $leaveOver,
            'isNew' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @return array<string, mixed>
     */
    private function calculateExistingGroupRange(array $groupMySQL, array $groupNNTP, int $maxHeaders): array
    {
        $first = (int) $groupMySQL['last_record'];
        $totalCount = (int) ($groupNNTP['last'] - $first);

        if ($totalCount > ($this->config->messageBuffer * 2)) {
            $leaveOver = (int) round($totalCount % $this->config->messageBuffer, 0, PHP_ROUND_HALF_DOWN) + $this->config->messageBuffer;
        } else {
            $leaveOver = (int) round($totalCount / 2, 0, PHP_ROUND_HALF_DOWN);
        }

        $last = $groupLast = (int) ($groupNNTP['last'] - $leaveOver);

        if ($last < $first) {
            $last = $groupLast = $first;
        }

        $total = (int) ($groupLast - $first);
        $realTotal = (int) ($groupNNTP['last'] - $first);

        // Apply max headers limit
        if ($maxHeaders > 0 && $maxHeaders < ($groupLast - $first)) {
            $groupLast = $last = (int) ($first + $maxHeaders);
            $total = (int) ($groupLast - $first);
        }

        return [
            'first' => $first,
            'last' => $last,
            'groupLast' => $groupLast,
            'total' => $total,
            'realTotal' => $realTotal,
            'leaveOver' => $leaveOver,
            'isNew' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @param  array<string, mixed>  $range
     */
    private function processArticleRange(array &$groupMySQL, array $groupNNTP, array $range): void
    {
        $first = (int) $range['first'];
        $last = (int) $range['last'];
        $groupLast = (int) $range['groupLast'];
        $done = false;

        while (! $done) {
            if ($this->runtimeState->stopRequested()) {
                return;
            }

            // Calculate chunk bounds
            if ($range['total'] > $this->config->messageBuffer) {
                $last = (int) min($first + $this->config->messageBuffer, $groupLast);
            }

            $first++;

            if ($this->config->echoCli) {
                cli()->header(
                    PHP_EOL.'Getting '.number_format($last - $first + 1).' articles ('.number_format($first).
                    ' to '.number_format($last).') from '.$groupMySQL['name'].' - ('.
                    number_format($groupLast - $last).' articles in queue).'
                );
            }

            // Scan this chunk
            $scanSummary = $this->scan($groupMySQL, $first, $last);
            if ($scanSummary === [] && $this->completedRangeEnd === null) {
                return;
            }

            // Update group record
            $this->updateGroupAfterScan($groupMySQL, $groupNNTP, $scanSummary, $last);

            if ($last === $groupLast) {
                $done = true;
            } else {
                $first = $last;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @param  array<string, mixed>  $scanSummary
     */
    private function updateGroupAfterScan(array &$groupMySQL, array $groupNNTP, array $scanSummary, int $last): void
    {
        if (! empty($scanSummary)) {
            // New group - update first record
            if ($groupMySQL['first_record_postdate'] === null && (int) $groupMySQL['first_record'] === 0) {
                $groupMySQL['first_record'] = $scanSummary['firstArticleNumber'];
                $firstArticleTimestamp = isset($scanSummary['firstArticleDate'])
                    ? strtotime($scanSummary['firstArticleDate'])
                    : $this->postdate($groupMySQL['first_record'], $groupNNTP);
                $groupMySQL['first_record_postdate'] = $firstArticleTimestamp !== false ? $firstArticleTimestamp : time();

                UsenetGroup::query()->where('id', $groupMySQL['id'])->update([
                    'first_record' => $scanSummary['firstArticleNumber'],
                    'first_record_postdate' => Carbon::createFromTimestamp($groupMySQL['first_record_postdate'], date_default_timezone_get()),
                ]);
            }

            $lastArticleTimestamp = isset($scanSummary['lastArticleDate'])
                ? strtotime($scanSummary['lastArticleDate'])
                : $this->postdate($scanSummary['lastArticleNumber'], $groupNNTP);
            $lastArticleDate = $lastArticleTimestamp !== false ? $lastArticleTimestamp : time();

            UsenetGroup::query()->where('id', $groupMySQL['id'])->update([
                'last_record' => $this->completedRangeEnd ?? $scanSummary['lastArticleNumber'],
                'last_record_postdate' => Carbon::createFromTimestamp($lastArticleDate, date_default_timezone_get()),
                'last_updated' => now(),
            ]);
        } else {
            UsenetGroup::query()->where('id', $groupMySQL['id'])->update([
                'last_record' => $last,
                'last_updated' => now(),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function downloadHeaders(bool $partRepair): ?array
    {
        $nntp = $this->getNntp();

        if ($partRepair) {
            $headers = $nntp->getOverview($this->first.'-'.$this->last, true, false);
        } else {
            $headers = $nntp->getXOVER($this->first.'-'.$this->last);
        }

        // For an overview range, 423 confirms that no articles exist in that window.
        if ($headers instanceof NntpError && $headers->getCode() === ResponseCode::NoSuchArticleNumber->value) {
            return [];
        }

        if (NNTPService::isError($headers) || ! \is_array($headers)) {
            if ($partRepair) {
                return null;
            }

            // Retry without compression
            if ($nntp->reconnect(false) !== true) {
                return null;
            }

            $selected = $nntp->selectGroup($this->groupMySQL['name']);
            if (NNTPService::isError($selected) || ! \is_array($selected)) {
                return null;
            }
            $headers = $nntp->getXOVER($this->first.'-'.$this->last);
            $nntp->enableCompression();

            if ($headers instanceof NntpError && $headers->getCode() === ResponseCode::NoSuchArticleNumber->value) {
                return [];
            }

            if (NNTPService::isError($headers) || ! \is_array($headers)) {
                if (\is_object($headers) && isset($headers->code, $headers->message)) {
                    $message = ((int) $headers->code === 0 ? 'Unknown error' : $headers->message);
                    $this->log("Code {$headers->code}: $message\nSkipping group: {$this->groupMySQL['name']}", __FUNCTION__, 'error');
                } else {
                    $this->log("Unknown error\nSkipping group: {$this->groupMySQL['name']}", __FUNCTION__, 'error');
                }

                return null;
            }
        }

        return $headers;
    }

    /**
     * @param  array<int, int|string>  $headersNotInserted
     */
    private function handlePartRepairTracking(array $headersNotInserted): void
    {
        $notInsertedCount = \count($headersNotInserted);
        if ($notInsertedCount > 0) {
            $this->missedPartHandler->addMissingParts($headersNotInserted, $this->groupMySQL['id']);
            $this->log($notInsertedCount.' articles failed to insert!', __FUNCTION__, 'warning');
        }

        // Find missing article numbers without materialising range($first, $last)
        // and a second array_diff() copy. Flush bounded batches directly.
        $missing = [];
        $notReceivedCount = 0;
        for ($number = $this->first; $number <= $this->last; $number++) {
            if (isset($this->headersReceived[$number])) {
                continue;
            }
            $missing[] = $number;
            $notReceivedCount++;
            if (\count($missing) >= $this->config->sqlChunkSize) {
                $this->missedPartHandler->addMissingParts($missing, $this->groupMySQL['id']);
                $missing = [];
            }
        }
        if ($missing !== []) {
            $this->missedPartHandler->addMissingParts($missing, $this->groupMySQL['id']);
        }

        if ($notReceivedCount > 0 && $this->config->echoCli) {
            cli()->alternate(
                'Server did not return '.$notReceivedCount.' articles from '.$this->groupMySQL['name'].'.'
            );
        }
    }

    /**
     * @param  array<int, \stdClass>  $missingParts
     * @return list<array{partfrom: mixed, partto: mixed, partlist: list<mixed>}>
     */
    private function groupMissingPartsIntoRanges(array $missingParts): array
    {
        $ranges = [];
        $partList = [];
        $firstPart = $lastNum = $missingParts[0]->numberid;

        foreach ($missingParts as $part) {
            if (($part->numberid - $firstPart) > ($this->config->messageBuffer / 4)) {
                $ranges[] = [
                    'partfrom' => $firstPart,
                    'partto' => $lastNum,
                    'partlist' => $partList,
                ];
                $firstPart = $part->numberid;
                $partList = [];
            }
            $partList[] = $part->numberid;
            $lastNum = $part->numberid;
        }

        $ranges[] = [
            'partfrom' => $firstPart,
            'partto' => $lastNum,
            'partlist' => $partList,
        ];

        return $ranges;
    }

    /**
     * @param  array<string, mixed>  $groupData
     * @return list<array<string, mixed>>
     */
    private function getNextArticleToTry(int $currentPost, array $groupData): ?int
    {
        if (abs($currentPost - $groupData['first']) > abs($groupData['last'] - $currentPost)) {
            $tempPost = (int) round($currentPost / (random_int(1005, 1012) / 1000), 0, PHP_ROUND_HALF_UP);
            if ($tempPost < $groupData['first']) {
                $tempPost = $groupData['first'];
            }
        } else {
            $tempPost = (int) round((random_int(1005, 1012) / 1000) * $currentPost, 0, PHP_ROUND_HALF_UP);
            if ($tempPost > $groupData['last']) {
                $tempPost = $groupData['last'];
            }
        }

        // If we got the same article number, give up
        if ($tempPost === $currentPost) {
            return null;
        }

        return $tempPost;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function binarySearchArticleByDate(int $goalTime, array $data): string
    {
        $wantedArticle = $this->articleMidpoint($data['first'], $data['last']);
        $aMax = $data['last'];
        $aMin = $data['first'];
        $oldArticle = $articleTime = null;
        $remainingProbes = 64;

        while (true) {
            // Stop once the probe would reach a bound: adjacent bounds are fully resolved.
            if ($wantedArticle <= $data['first'] || $wantedArticle >= $data['last'] || $wantedArticle <= $aMin || $wantedArticle >= $aMax) {
                break;
            }

            $reallyOldArticle = $oldArticle;
            $oldArticle = $wantedArticle;

            if ($remainingProbes-- === 0) {
                throw new \RuntimeException('NNTP date target did not converge; defer date-based indexing.');
            }
            // $aMin < $wantedArticle < $aMax, so the nearest-article search only moves upward.
            $found = $this->nearestPostdate($wantedArticle, $aMax - 1);
            if ($found === null) {
                // No article from the probe to the upper bound: the boundary lies below the probe.
                $aMax = $wantedArticle;
                $wantedArticle = $this->articleMidpoint($aMin, $wantedArticle);

                continue;
            }
            [$oldArticle, $articleTime] = $found;
            $wantedArticle = $oldArticle;

            if ($articleTime < $goalTime) {
                $aMin = $oldArticle;
                $wantedArticle = $this->articleMidpoint($oldArticle, $aMax);
                if ($this->config->echoCli) {
                    echo '-';
                }
            } elseif ($articleTime > $goalTime) {
                $aMax = $oldArticle;
                $wantedArticle = $this->articleMidpoint($aMin, $oldArticle);
                if ($this->config->echoCli) {
                    echo '+';
                }
            } else {
                break;
            }

            if ($reallyOldArticle === $wantedArticle && ($goalTime - $articleTime) <= 0) {
                break;
            }
        }

        if ($this->config->echoCli && $articleTime !== null) {
            $goalCarbon = Carbon::createFromTimestamp($goalTime, date_default_timezone_get());
            $articleCarbon = Carbon::createFromTimestamp($articleTime, date_default_timezone_get());
            $diffDays = $goalCarbon->diffInDays($articleCarbon, true);
            cli()->primary(
                PHP_EOL.'Found article #'.$wantedArticle.' which has a date of '.date('r', $articleTime).
                ', vs wanted date of '.date('r', $goalTime).'. Difference from goal is '.$diffDays.' days.'
            );
        }

        return (string) $wantedArticle;
    }

    private function articleMidpoint(int $minimum, int $maximum): int
    {
        $distance = $maximum - $minimum;

        return $minimum + intdiv($distance, 2) + ($distance % 2);
    }

    // ==================== Output Methods ====================

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @param  array<string, mixed>  $range
     */
    private function outputNoNewArticles(array $groupMySQL, array $groupNNTP, array $range): void
    {
        if ($this->config->echoCli) {
            cli()->primary(
                'No new articles for '.$groupMySQL['name'].' (first '.number_format((int) $range['first']).
                ', last '.number_format((int) $range['last']).', grouplast '.number_format((int) $groupMySQL['last_record']).
                ', total '.number_format((int) $range['total']).")\n".'Server oldest: '.number_format((int) $groupNNTP['first']).
                ' Server newest: '.number_format((int) $groupNNTP['last']).' Local newest: '.number_format((int) $groupMySQL['last_record'])
            );
        }
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @param  array<string, mixed>  $range
     */
    private function outputNewArticlesInfo(array $groupMySQL, array $groupNNTP, array $range): void
    {
        if (! $this->config->echoCli) {
            return;
        }

        $message = $range['isNew']
            ? 'New group '.$groupNNTP['group'].' starting with '.
              ($this->config->newGroupScanByDays
                  ? $this->config->newGroupDaysToScan.' days'
                  : number_format($this->config->newGroupMessagesToScan).' messages').' worth.'
            : 'Group '.$groupNNTP['group'].' has '.number_format((int) $range['realTotal']).' new articles.';

        cli()->primary(
            $message.
            ' Leaving '.number_format((int) $range['leaveOver']).
            " for next pass.\nServer oldest: ".number_format((int) $groupNNTP['first']).
            ' Server newest: '.number_format((int) $groupNNTP['last']).
            ' Local newest: '.number_format((int) $groupMySQL['last_record'])
        );
    }

    private function outputHeaderInitial(): void
    {
        cli()->primary(
            'Received '.\count($this->headersReceived).
            ' articles of '.number_format($this->last - $this->first + 1).' requested, '.
            $this->headersBlackListed.' blacklisted, '.$this->notYEnc.' not yEnc, '
            .$this->headersObfuscated.' obfuscated per article.'
        );
    }

    private function outputHeaderDuration(): void
    {
        if (! $this->config->echoCli) {
            return;
        }

        $currentMicroTime = now();
        cli()->alternateOver(number_format($this->timeHeaders, 2).'s').
        cli()->primaryOver(' to download articles, ').
        cli()->alternateOver(number_format($this->timeCleaning, 2).'s').
        cli()->primaryOver(' to process collections, ').
        cli()->alternateOver(number_format($this->timeInsert, 2).'s').
        cli()->primaryOver(' to insert binaries/parts, ').
        cli()->alternateOver(number_format($currentMicroTime->diffInSeconds($this->startPR, true), 2).'s').
        cli()->primaryOver(' for part repair, ').
        cli()->alternateOver(number_format($currentMicroTime->diffInSeconds($this->startLoop, true), 2).'s').
        cli()->primary(' total.');
    }

    // ==================== Logging Methods ====================

    private function log(string $message, string $method, string $color): void
    {
        if ($this->config->echoCli) {
            cli()->$color($message.' ['.__CLASS__."::$method]");
        }
    }

    private function logError(string $message): void
    {
        if ($this->config->echoCli) {
            cli()->error($message);
        }
        if (config('app.debug')) {
            Log::error($message);
        }
    }
}
