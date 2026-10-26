<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\UsenetGroup;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Tmux\CollectionDowntimeCompensator;
use App\Support\RustPipelineLibrary;
use DariusIII\NetNntp\Error as NntpError;
use DariusIII\NetNntp\Protocol\ResponseCode;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_binaries_calculate(uint32_t mode, const int64_t *input, size_t len, int64_t *out, size_t cap);
        ssize_t nntmux_scan_diagnostic(int64_t first, int64_t last, int64_t group_last, const char *name, size_t len, char *out, size_t cap);
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
            $written = $ffi->nntmux_binaries_calculate($mode, $input, count($values), $out, $size);
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
     * @throws Throwable
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
            } catch (Throwable $e) {
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
     * @throws Throwable
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
        } catch (Throwable) {
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
     * @throws Throwable
     */
    public function scan(array $groupMySQL, int $first, int $last, string $type = 'update', ?array $missingParts = null): array
    {
        $this->completedRangeEnd = null;
        try {
            if (! app(CandidateRepository::class)->capacityAvailable()) {
                throw new \RuntimeException('Verification backlog is full; scan paused before downloading headers.');
            }
        } catch (Throwable $exception) {
            if ($type !== 'partrepair') {
                try {
                    $this->missedPartHandler->deferRange($first, $last, (int) $groupMySQL['id']);
                } catch (Throwable $deferralFailure) {
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
        // Detect across the whole range: one chunk can hold too few of an upload's articles. A
        // sparse part repair lists scattered missing articles of real uploads, so it is exempt; a
        // repair listing every number of its range is a range deferred before download (or lost
        // whole) and is filtered like the scan it replaces.
        $sparseRepair = $partRepair && ($missingParts === null || \count($missingParts) !== $last - $first + 1);
        $obfuscated = $this->headerParser->markPerArticleObfuscated(
            $headers,
            $sparseRepair ? 0 : $this->config->perArticleObfuscationMinArticles
        );
        if ($obfuscated > 0) {
            Log::info('Skipping articles obfuscated per article', [
                'groups_id' => $groupMySQL['id'],
                'first' => $first,
                'last' => $last,
                'skipped' => $obfuscated,
                'received' => $totalHeaders,
            ]);
        }
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
                } catch (Throwable $e) {
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
     * @throws Throwable
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
        $dateFirst = $this->config->newGroupScanByDays
            ? (int) $this->daytopost($this->config->newGroupDaysToScan, $groupNNTP) : null;

        return self::newHeaderRange((int) $groupNNTP['first'], (int) $groupNNTP['last'], $this->config->newGroupMessagesToScan, $this->config->messageBuffer, $dateFirst);
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $groupNNTP
     * @return array<string, mixed>
     */
    private function calculateExistingGroupRange(array $groupMySQL, array $groupNNTP, int $maxHeaders): array
    {
        return self::existingHeaderRange((int) $groupMySQL['last_record'], (int) $groupNNTP['last'], $this->config->messageBuffer, $maxHeaders);
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

            [$first, $last] = self::scanChunk($first, $last, $groupLast, (int) $range['total'], $this->config->messageBuffer);

            if ($this->config->echoCli) {
                cli()->header(
                    self::scanDiagnostic($first, $last, $groupLast, $groupMySQL['name'])
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
        return self::postdateProbe($currentPost, (int) $groupData['first'], (int) $groupData['last'], random_int(1005, 1012));
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
        return self::$useRustFfi ? self::articleMidpointNative($minimum, $maximum) : self::articleMidpointPhp($minimum, $maximum);
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

    /** @return array{first: int, last: int, groupLast: int, total: int, realTotal: int, leaveOver: int, isNew: bool} */
    public static function existingHeaderRange(int $first, int $newest, int $buffer, int $limit): array
    {
        return self::$useRustFfi ? self::existingHeaderRangeNative($first, $newest, $buffer, $limit) : self::existingHeaderRangePhp($first, $newest, $buffer, $limit);
    }

    /** @return array{first: int, last: int, groupLast: int, total: int, realTotal: int, leaveOver: int, isNew: bool} */
    public static function existingHeaderRangeNative(int $first, int $newest, int $buffer, int $limit): array
    {
        $values = self::calculateNative(0, [$first, $newest, $buffer, $limit], 6);

        return $values === null ? self::existingHeaderRangePhp($first, $newest, $buffer, $limit) : self::headerRangeResult($values, false);
    }

    /** @return array{first: int, last: int, groupLast: int, total: int, realTotal: int, leaveOver: int, isNew: bool} */
    public static function existingHeaderRangePhp(int $first, int $newest, int $buffer, int $limit): array
    {
        $count = (int) ($newest - $first);
        $leave = $count > $buffer * 2
            ? (int) round($count % $buffer, 0, PHP_ROUND_HALF_DOWN) + $buffer
            : (int) round($count / 2, 0, PHP_ROUND_HALF_DOWN);
        $last = max((int) ($newest - $leave), $first);
        if ($limit > 0 && $limit < $last - $first) {
            $last = (int) ($first + $limit);
        }

        return self::headerRangeResult([$first, $last, $last, (int) ($last - $first), (int) ($newest - $first), $leave], false);
    }

    /** @return array{first: int, last: int, groupLast: int, total: int, realTotal: int, leaveOver: int, isNew: bool} */
    public static function newHeaderRange(int $oldest, int $newest, int $messages, int $buffer, ?int $dateFirst = null): array
    {
        return self::$useRustFfi ? self::newHeaderRangeNative($oldest, $newest, $messages, $buffer, $dateFirst) : self::newHeaderRangePhp($oldest, $newest, $messages, $buffer, $dateFirst);
    }

    /** @return array{first: int, last: int, groupLast: int, total: int, realTotal: int, leaveOver: int, isNew: bool} */
    public static function newHeaderRangeNative(int $oldest, int $newest, int $messages, int $buffer, ?int $dateFirst = null): array
    {
        $values = self::calculateNative(1, [$oldest, $newest, $messages, $buffer, (int) ($dateFirst !== null), $dateFirst ?? 0], 6);

        return $values === null ? self::newHeaderRangePhp($oldest, $newest, $messages, $buffer, $dateFirst) : self::headerRangeResult($values, true);
    }

    /** @return array{first: int, last: int, groupLast: int, total: int, realTotal: int, leaveOver: int, isNew: bool} */
    public static function newHeaderRangePhp(int $oldest, int $newest, int $messages, int $buffer, ?int $dateFirst = null): array
    {
        $first = $dateFirst ?? (int) max($oldest, $newest - ($messages + $buffer));
        $last = max((int) ($newest - $buffer), $first);

        return self::headerRangeResult([$first, $last, $last, (int) ($last - $first), (int) ($newest - $first), $buffer], true);
    }

    /**
     * @param  list<int>  $values
     * @return array{first: int, last: int, groupLast: int, total: int, realTotal: int, leaveOver: int, isNew: bool}
     */
    private static function headerRangeResult(array $values, bool $isNew): array
    {
        return ['first' => $values[0], 'last' => $values[1], 'groupLast' => $values[2], 'total' => $values[3], 'realTotal' => $values[4], 'leaveOver' => $values[5], 'isNew' => $isNew];
    }

    /** @return array{int, int} */
    public static function scanChunk(int $first, int $last, int $groupLast, int $total, int $buffer): array
    {
        return self::$useRustFfi ? self::scanChunkNative($first, $last, $groupLast, $total, $buffer) : self::scanChunkPhp($first, $last, $groupLast, $total, $buffer);
    }

    /** @return array{int, int} */
    public static function scanChunkNative(int $first, int $last, int $groupLast, int $total, int $buffer): array
    {
        $values = self::calculateNative(2, [$first, $last, $groupLast, $total, $buffer], 2);

        return $values === null ? self::scanChunkPhp($first, $last, $groupLast, $total, $buffer) : [$values[0], $values[1]];
    }

    /** @return array{int, int} */
    public static function scanChunkPhp(int $first, int $last, int $groupLast, int $total, int $buffer): array
    {
        return [$first + 1, $total > $buffer ? (int) min($first + $buffer, $groupLast) : $last];
    }

    public static function scanDiagnostic(int $first, int $last, int $groupLast, string $name): string
    {
        return self::$useRustFfi ? self::scanDiagnosticNative($first, $last, $groupLast, $name) : self::scanDiagnosticPhp($first, $last, $groupLast, $name);
    }

    public static function scanDiagnosticNative(int $first, int $last, int $groupLast, string $name): string
    {
        try {
            $ffi = self::ffi();
            if ($ffi !== null && strlen($name) <= 1_048_576) {
                $capacity = strlen($name) + 256;
                $out = $ffi->new('char['.$capacity.']');
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_scan_diagnostic($first, $last, $groupLast, $name, strlen($name), $out, $capacity);
                if (is_int($written) && $written >= 0 && $written <= $capacity) {
                    return FFI::string($out, $written);
                }
            }
        } catch (Throwable) {
            // Loading/symbol/ABI failures preserve the PHP path.
        }

        return self::scanDiagnosticPhp($first, $last, $groupLast, $name);
    }

    public static function scanDiagnosticPhp(int $first, int $last, int $groupLast, string $name): string
    {
        return PHP_EOL.'Getting '.number_format($last - $first + 1).' articles ('.number_format($first).' to '.number_format($last).') from '.$name.' - ('.number_format($groupLast - $last).' articles in queue).';
    }

    public static function articleMidpointNative(int $minimum, int $maximum): int
    {
        return self::calculateNative(3, [$minimum, $maximum], 1)[0] ?? self::articleMidpointPhp($minimum, $maximum);
    }

    public static function articleMidpointPhp(int $minimum, int $maximum): int
    {
        $distance = $maximum - $minimum;

        return $minimum + intdiv($distance, 2) + ($distance % 2);
    }

    public static function postdateProbe(int $current, int $first, int $last, int $factor): ?int
    {
        return self::$useRustFfi ? self::postdateProbeNative($current, $first, $last, $factor) : self::postdateProbePhp($current, $first, $last, $factor);
    }

    public static function postdateProbeNative(int $current, int $first, int $last, int $factor): ?int
    {
        $values = self::calculateNative(4, [$current, $first, $last, $factor], 1);
        if ($values === null) {
            return self::postdateProbePhp($current, $first, $last, $factor);
        }

        return $values[0] === $current ? null : $values[0];
    }

    public static function postdateProbePhp(int $current, int $first, int $last, int $factor): ?int
    {
        $probe = abs($current - $first) > abs($last - $current)
            ? max((int) round($current / ($factor / 1000), 0, PHP_ROUND_HALF_UP), $first)
            : min((int) round(($factor / 1000) * $current, 0, PHP_ROUND_HALF_UP), $last);

        return $probe === $current ? null : $probe;
    }

    public const string W411_FFI_CDEF = <<<'C'
        ssize_t nntmux_w411_binaries_config_service_handler_header(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP stays the default for summarizeScanRangeBatch(): the paired benchmark in
     * tests/Unit/RustBinariesConfigServiceHandlerHeaderW41ParityTest.php does not clear 1.5x.
     */
    public const bool W411_RUST_FFI_DEFAULT = false;

    private const int W411_RUST_MAX_INPUT = 16_777_216;

    private const array W411_RANGE_KEYS = ['ranges', 'invalid_rows', 'negative', 'empty', 'valid', 'capped', 'total_articles'];

    private static ?bool $w411UseRustFfi = null;

    private static FFI|false|null $w411Ffi = null;

    /**
     * Summarizes scan article ranges (`first`, `last`): rows whose bounds are not ints (or 1-18 digit strings) are
     * invalid, a negative bound is negative, `last < first` is empty, and other rows are valid. A valid range spans
     * `last - first + 1` articles (saturating at PHP_INT_MAX), is capped when `$maxHeaders` is positive and smaller
     * than the span, and adds the span to the article total (saturating).
     *
     * @param  array<array-key, mixed>  $ranges
     * @return array<string, int>
     */
    public static function summarizeScanRangeBatch(array $ranges, int $maxHeaders = 0): array
    {
        return (self::usesW411RustFfi() ? self::summarizeScanRangeBatchNative($ranges, $maxHeaders) : null)
            ?? self::summarizeScanRangeBatchPhp($ranges, $maxHeaders);
    }

    /**
     * @param  array<array-key, mixed>  $ranges
     * @return array<string, int>
     */
    public static function summarizeScanRangeBatchPhp(array $ranges, int $maxHeaders = 0): array
    {
        $summary = array_fill_keys(self::W411_RANGE_KEYS, 0);
        $summary['ranges'] = \count($ranges);
        foreach ($ranges as $range) {
            $first = \is_array($range) ? self::w411Int($range['first'] ?? null) : null;
            $last = \is_array($range) ? self::w411Int($range['last'] ?? null) : null;
            if ($first === null || $last === null) {
                $summary['invalid_rows']++;
            } elseif ($first < 0 || $last < 0) {
                $summary['negative']++;
            } elseif ($last < $first) {
                $summary['empty']++;
            } else {
                $summary['valid']++;
                $span = $last - $first;
                $span = $span === PHP_INT_MAX ? PHP_INT_MAX : $span + 1;
                $summary['capped'] += (int) ($maxHeaders > 0 && $span > $maxHeaders);
                $total = $summary['total_articles'];
                $summary['total_articles'] = $span > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $span;
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $ranges
     * @return array<string, int>|null
     */
    public static function summarizeScanRangeBatchNative(array $ranges, int $maxHeaders = 0): ?array
    {
        $words = [$maxHeaders, \count($ranges)];
        $blob = [];
        foreach ($ranges as $range) {
            if (\is_array($range)) {
                self::w411PackRow($words, $blob, [$range['first'] ?? null, $range['last'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = self::w411Invoke(self::$w411Ffi, 2, $words, $blob);

        return $values !== null && \count($values) === \count(self::W411_RANGE_KEYS) ? array_combine(self::W411_RANGE_KEYS, $values) : null;
    }

    public static function useW411RustFfi(bool $enable = true): void
    {
        self::$w411UseRustFfi = $enable;
    }

    public static function usesW411RustFfi(): bool
    {
        return self::$w411UseRustFfi ?? self::W411_RUST_FFI_DEFAULT;
    }

    public static function resetW411RustFfi(): void
    {
        self::$w411UseRustFfi = null;
        self::$w411Ffi = null;
    }

    /** Wave 41.1 int reading: an int, or a string of an optional `-` and 1-18 ASCII digits. */
    public static function w411Int(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (! \is_string($value)) {
            return null;
        }
        $digits = $value !== '' && $value[0] === '-' ? substr($value, 1) : $value;
        $length = \strlen($digits);

        return $length >= 1 && $length <= 18 && strspn($digits, '0123456789') === $length ? (int) $value : null;
    }

    /**
     * Appends an array row to a Wave 41.1 frame: marker 1, then per value its string length (bytes to the blob),
     * -2 and the int, -3 and a float's 8 bytes, or -1.
     *
     * @param  list<int>  $words
     * @param  list<string>  $blob
     * @param  list<mixed>  $values
     */
    public static function w411PackRow(array &$words, array &$blob, array $values): void
    {
        $words[] = 1;
        foreach ($values as $value) {
            if (\is_string($value)) {
                $words[] = \strlen($value);
                $blob[] = $value;
            } elseif (\is_int($value)) {
                $words[] = -2;
                $words[] = $value;
            } elseif (\is_float($value)) {
                $words[] = -3;
                $blob[] = pack('d', $value);
            } else {
                $words[] = -1;
            }
        }
    }

    /**
     * Calls the Wave 41.1 export with a frame, retrying once with a larger scratch buffer when it reports
     * `-required`; `$ffi` is the caller's cached library handle. Returns the decoded ints or null to fall back.
     *
     * @param  list<int>  $words
     * @param  list<string>  $blob
     * @return list<int>|null
     */
    public static function w411Invoke(FFI|false|null &$ffi, int $operation, array $words, array $blob): ?array
    {
        $input = pack('q', \count($words)).pack('q*', ...$words).implode('', $blob);
        if (\strlen($input) > self::W411_RUST_MAX_INPUT) {
            return null;
        }
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w411', self::W411_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w411_binaries_config_service_handler_header($operation, 0, $input, \strlen($input), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::W411_RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w411_binaries_config_service_handler_header($operation, 0, $input, \strlen($input), $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity || $written % 8 !== 0) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52 parity test). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    private const string W52_FFI_CDEF = 'ssize_t nntmux_w521_binaries_overview_nearest_batch(const char *numbers, size_t numbers_len, size_t count, int64_t low, int64_t high, uint32_t upward, int64_t *out, size_t out_cap);';

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
     * The overview row {@see nearestOverviewRow()} picks from an XOVER range's `Number` values:
     * the index of the lowest (`$upward`) or highest number, the first on ties; null for no
     * rows, false when a number fails filter_var(.., FILTER_VALIDATE_INT) or leaves
     * `[$low, $high]` (nearestOverviewRow() then defers).
     *
     * @param  list<int|string>  $numbers
     */
    public static function nearestOverviewIndex(array $numbers, int $low, int $high, bool $upward): int|false|null
    {
        return self::usesW52RustFfi()
            ? self::nearestOverviewIndexNative($numbers, $low, $high, $upward)
            : self::nearestOverviewIndexPhp($numbers, $low, $high, $upward);
    }

    /**
     * @param  list<int|string>  $numbers
     */
    public static function nearestOverviewIndexPhp(array $numbers, int $low, int $high, bool $upward): int|false|null
    {
        $nearest = $best = null;
        foreach (array_values($numbers) as $index => $raw) {
            $number = filter_var($raw, FILTER_VALIDATE_INT);
            if ($number === false || $number < $low || $number > $high) {
                return false;
            }
            if ($best === null || ($upward ? $number < $best : $number > $best)) {
                $nearest = $index;
                $best = $number;
            }
        }

        return $nearest;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a number
     * holding a NUL byte).
     *
     * @param  list<int|string>  $numbers
     */
    public static function nearestOverviewIndexNative(array $numbers, int $low, int $high, bool $upward): int|false|null
    {
        $count = \count($numbers);
        $buffer = $count === 0 ? null : self::w52Buffer(1);
        if ($buffer === null) {
            return self::nearestOverviewIndexPhp($numbers, $low, $high, $upward);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $numbers);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w521_binaries_overview_nearest_batch($joined, \strlen($joined), $count, $low, $high, (int) $upward, $out, $cap);
            $index = $written === 1 ? $out[0] : null;
        } catch (Throwable) {
            $index = null;
        }
        if (! \is_int($index) || $index < -2 || $index >= $count) {
            return self::nearestOverviewIndexPhp($numbers, $low, $high, $upward);
        }

        return match ($index) {
            -2 => false,
            -1 => null,
            default => $index,
        };
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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.03x, p99 6.40x; uncommon mean 7.08x, p99 5.39x); see RustBackfillBinariesConfigServiceHandlerW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    public const string W66_BINARIES_SERVICE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w661_binaries_service_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w66BinariesServicePlanBatch(). */
    public const int W66_BINARIES_SERVICE_TRAILER = 21;

    public static function usesW66RustFfi(): bool
    {
        $override = self::w66RustFfiState();

        return \is_bool($override) ? $override : self::W66_RUST_FFI_DEFAULT;
    }

    /** Forces the BinariesService Wave 66 Rust path on until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Forces the BinariesService Wave 66 PHP path until reset. */
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
     * Runs $callback with the BinariesService Wave 66 Rust path forced on or off, then restores the previous setting.
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
     * Wave 66.1 group scan plans, one per `last_record|server_first|server_last|max_headers|buffer|messages_to_scan|
     * missing_parts|repair_limit` row (trimmed fields of 1-15 digits; empty numbers 0, buffer empty or 0 is 20000, messages
     * empty 50000, repair limit empty 15000). A new group (no last record) scans from `max(server_first, server_last -
     * (messages + buffer))` to `server_last - buffer`; an existing group leaves `count % buffer + buffer` (or `count / 2` for
     * at most two buffers) of the `server_last - last_record` new articles. A positive header cap shortens the range. Decision:
     * 0 empty row, 1 malformed number, 2 server range inverted, 3 nothing to scan, 4 new group, 5 capped, 6 existing group.
     * Ranges split into `ceil(total / buffer)` chunks and part repair takes `min(missing, limit)` parts. Flags: 1 new group,
     * 2 capped, 4 buffer defaulted, 8 repair truncated, 16 last record beyond the server's newest, 32 last record below its
     * oldest, 64 non-ASCII, 128 control byte. Per item `decision | flags << 3 | min(chunks, 65535) << 11 | min(total, 1048575)
     * << 27 | min(repair, 32767) << 47`; then 7 decision and 8 flag counts, the article and chunk sums, the largest range, the
     * leave-over and repair sums and the largest chunk count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BinariesServicePlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66BinariesServicePlanBatchNative($items) : self::w66BinariesServicePlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BinariesServicePlanBatchNative(array $items): array
    {
        return self::w66Invoke($items, \count($items) + self::W66_BINARIES_SERVICE_TRAILER) ?? self::w66BinariesServicePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BinariesServicePlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W66_BINARIES_SERVICE_TRAILER, 0);
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawLast, $rawOldest, $rawNewest, $rawMax, $rawBuffer, $rawMessages, $rawMissing, $rawLimit] = explode('|', $item, 8)
                + ['', '', '', '', '', '', '', ''];
            $lastRecord = self::w66Uint($rawLast, 0);
            $oldest = self::w66Uint($rawOldest, 0);
            $newest = self::w66Uint($rawNewest, 0);
            $maxHeaders = self::w66Uint($rawMax, 0);
            $buffer = self::w66Uint($rawBuffer, 20_000);
            $messages = self::w66Uint($rawMessages, 50_000);
            $missing = self::w66Uint($rawMissing, 0);
            $limit = self::w66Uint($rawLimit, 15_000);
            $malformed = $lastRecord < 0 || $oldest < 0 || $newest < 0 || $maxHeaders < 0 || $buffer < 0 || $messages < 0 || $missing < 0 || $limit < 0;
            $defaulted = $buffer === 0;
            if ($defaulted) {
                $buffer = 20_000;
            }
            $isNew = $lastRecord === 0;
            $decision = match (true) {
                trim($item) === '' => 0,
                $malformed => 1,
                $newest < $oldest => 2,
                default => 3,
            };
            $total = $leave = 0;
            $capped = false;
            if ($decision === 3) {
                if ($isNew) {
                    $first = max($oldest, $newest - ($messages + $buffer));
                    $last = max($newest - $buffer, $first);
                    $leave = $buffer;
                    if ($maxHeaders > 0 && $last - $first > $maxHeaders) {
                        $last = $first + $maxHeaders;
                        $capped = true;
                    }
                } else {
                    $first = $lastRecord;
                    $count = $newest - $first;
                    $leave = $count > $buffer * 2 ? $count % $buffer + $buffer : intdiv($count, 2);
                    $last = max($newest - $leave, $first);
                    if ($maxHeaders > 0 && $maxHeaders < $last - $first) {
                        $last = $first + $maxHeaders;
                        $capped = true;
                    }
                }
                $total = $last - $first;
                $decision = match (true) {
                    $total <= 0 => 3,
                    $capped => 5,
                    $isNew => 4,
                    default => 6,
                };
            }
            $chunks = $total > 0 ? intdiv($total + $buffer - 1, $buffer) : 0;
            $repair = $malformed ? 0 : min($missing, $limit);
            $flags = ($decision >= 3 && $isNew ? 1 : 0) | ($capped ? 2 : 0) | ($defaulted ? 4 : 0) | (! $malformed && $missing > $limit ? 8 : 0)
                | (! $malformed && $lastRecord > $newest ? 16 : 0) | (! $malformed && $lastRecord > 0 && $lastRecord < $oldest ? 32 : 0)
                | (preg_match('/[\x80-\xff]/', $item) === 1 ? 64 : 0) | (strcspn($item, self::W66_CONTROL_BYTES) !== \strlen($item) ? 128 : 0);
            $tail[$decision]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[7 + $b] += $flags >> $b & 1;
            }
            $tail[15] += min($total, 1 << 40);
            $tail[16] += min($chunks, 1 << 40);
            $tail[17] = max($tail[17], $total);
            $tail[18] += max(0, min($leave, 1 << 40));
            $tail[19] += min($repair, 1 << 40);
            $tail[20] = max($tail[20], $chunks);
            $out[] = $decision | $flags << 3 | min($chunks, 65_535) << 11 | min($total, 1_048_575) << 27 | min($repair, 32_767) << 47;
        }
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
            $ffi ??= RustPipelineLibrary::resolveDomain('binaries_service_w661', self::W66_BINARIES_SERVICE_FFI_CDEF) ?? false;

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
     * Calls the Wave 66.1 BinariesService export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W66_BINARIES_SERVICE_FFI_CDEF.)
            $written = $ffi->nntmux_w661_binaries_service_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
