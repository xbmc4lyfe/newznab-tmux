<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Tmux\CollectionDowntimeCompensator;
use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use FFI;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Orchestrates the header storage process.
 *
 * This service coordinates the CollectionHandler, BinaryHandler, PartHandler,
 * and HeaderStorageTransaction to store parsed headers into the database.
 */
final class HeaderStorageService
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_header_extract_file_numbers(const char *subject_ptr, size_t subject_len);
        int64_t nntmux_header_get_file_count_span(const char *subject_ptr, size_t subject_len);
        ssize_t nntmux_header_extract_file_numbers_batch_nul(const char *input_ptr, size_t input_len, size_t count, char *out_ptr, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private const int LOCK_RETRY_MAX = 10;

    /** @var array<int, true> */
    private array $verificationCollectionIds = [];

    private CollectionHandler $collectionHandler;

    private BinaryHandler $binaryHandler;

    private PartHandler $partHandler;

    private BinariesConfig $config;

    /** @var array<int, int|string> Article numbers that failed to insert */
    private array $failedInserts = [];

    private ?Throwable $lastStorageException = null;

    public function __construct(
        ?CollectionHandler $collectionHandler = null,
        ?BinaryHandler $binaryHandler = null,
        ?PartHandler $partHandler = null,
        ?BinariesConfig $config = null
    ) {
        $this->config = $config ?? BinariesConfig::fromSettings();
        $this->collectionHandler = $collectionHandler ?? new CollectionHandler(sqlChunkSize: $this->config->sqlChunkSize);
        $this->binaryHandler = $binaryHandler ?? new BinaryHandler($this->config->sqlChunkSize);
        $this->partHandler = $partHandler ?? new PartHandler(
            $this->config->sqlChunkSize,
            true
        );
    }

    /**
     * Store parsed headers to the database.
     *
     * @param  array<int, array<string, mixed>>  $headers  Parsed headers with 'matches' already populated
     * @param  array<string, mixed>  $groupMySQL  Group info from database
     * @param  bool  $addToPartRepair  Caller repair mode; failures are returned in every mode
     * @return array<int, int|string> Article numbers that failed to insert
     *
     * @phpstan-impure
     */
    public function store(array $headers, array $groupMySQL, bool $addToPartRepair = true): array
    {
        if (empty($headers)) {
            $this->noteIngestion(); // A caught-up group is still live ingestion.

            return [];
        }

        $this->failedInserts = [];

        // Header and SQL chunking are configured independently, but both are
        // clamped by BinariesConfig so generated statements remain bounded.
        $chunkSize = max(1, $this->config->headerChunkSize);

        // Walk the array with offset slicing instead of array_chunk() so we
        // don't materialize every chunk simultaneously in memory.
        $total = \count($headers);
        for ($offset = 0; $offset < $total; $offset += $chunkSize) {
            $chunk = \array_slice($headers, $offset, $chunkSize);
            $validHeaders = [];
            $rejections = [];
            foreach ($chunk as $header) {
                $reason = PartHandler::validationError($header);
                if ($reason === null) {
                    $validHeaders[] = $header;

                    continue;
                }

                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;
                if (isset($header['Number'])) {
                    $this->failedInserts[] = $header['Number'];
                }
            }
            if ($rejections !== []) {
                Log::warning('Invalid binary headers rejected', [
                    'groups_id' => $groupMySQL['id'],
                    'article_count' => array_sum($rejections),
                    'reasons' => $rejections,
                ]);
            }
            if ($validHeaders !== []) {
                $this->storeChunk($validHeaders, $groupMySQL, $addToPartRepair);
            }
            unset($chunk);
        }

        $this->noteIngestion();

        return array_values(array_unique($this->failedInserts));
    }

    /**
     * Store one bounded header chunk inside its own transaction.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     */
    private function storeChunk(array $headers, array $groupMySQL, bool $addToPartRepair): void
    {
        $attempt = 0;
        do {
            $failedInsertCount = \count($this->failedInserts);
            if ($this->storeChunkAttempt($headers, $groupMySQL, $addToPartRepair)) {
                return;
            }

            $attempt++;
            if ($attempt >= self::LOCK_RETRY_MAX || ! $this->isRetryableStorageError($this->lastStorageException)) {
                if ($this->lastStorageException !== null) {
                    Log::error('Binary header storage chunk rolled back', [
                        'groups_id' => $groupMySQL['id'],
                        'article_count' => \count($headers),
                        'attempts' => $attempt,
                        'reason' => match (true) {
                            $this->isTransientLockError($this->lastStorageException) => 'Lock retries exhausted',
                            $this->isMissingParentRow($this->lastStorageException) => 'Parent row retries exhausted',
                            default => 'Storage failed',
                        },
                        'exception' => $this->lastStorageException::class,
                        'code' => $this->lastStorageException->getCode(),
                        ...$this->constraintContext($this->lastStorageException),
                    ]);
                }

                return;
            }

            $this->failedInserts = array_slice($this->failedInserts, 0, $failedInsertCount);
            usleep((min(2000, 100 * $attempt) + random_int(0, 25)) * 1000);
        } while (true);
    }

    /**
     * The driver error number and the key or column a constraint failure names, without
     * the statement, its bindings or the offending value.
     *
     * @return array{driver_code?: int, constraint?: string}
     */
    private function constraintContext(Throwable $exception): array
    {
        if (! $exception instanceof QueryException) {
            return [];
        }

        $context = ['driver_code' => (int) ($exception->errorInfo[1] ?? 0)];
        $message = (string) ($exception->errorInfo[2] ?? '');
        if (preg_match("/for key '([^']+)'|Column '([^']+)' cannot be null|constraint failed: (\\S+)|CONSTRAINT `([^`]+)`/", $message, $match) === 1) {
            $context['constraint'] = (string) (array_values(array_filter(array_slice($match, 1), static fn (string $part): bool => $part !== ''))[0] ?? '');
        }

        return $context;
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     */
    private function storeChunkAttempt(array $headers, array $groupMySQL, bool $addToPartRepair): bool
    {
        $this->lastStorageException = null;
        $this->verificationCollectionIds = [];
        $this->collectionHandler->reset();
        $this->binaryHandler->reset();
        $this->partHandler->reset();
        $this->partHandler->setAddToPartRepair($addToPartRepair);

        $chunkNumbers = [];
        foreach ($headers as $header) {
            if (isset($header['Number']) && (\is_int($header['Number']) || \is_string($header['Number']))) {
                $chunkNumbers[] = $header['Number'];
            }
        }

        // Create transaction
        $transaction = new HeaderStorageTransaction(
            $this->collectionHandler,
            $this->binaryHandler
        );

        $transaction->begin();

        try {
            $this->processHeaderChunk($headers, $groupMySQL, $transaction);

            // Flush remaining parts
            if ($this->partHandler->hasPending()) {
                if (! $this->partHandler->flush()) {
                    $transaction->markError();
                }
            }

            // Flush binary aggregate updates
            if (! $transaction->hasErrors()) {
                if (! $this->binaryHandler->refreshAggregates(
                    $this->partHandler->getTouchedBinaryIds(),
                    $this->config->sqlChunkSize
                )) {
                    $transaction->markError();
                }
                if (! $transaction->hasErrors() && ! $this->collectionHandler->refreshAggregates(
                    $this->collectionHandler->getAllIds(),
                    $this->config->sqlChunkSize
                )) {
                    $transaction->markError();
                }
            }

            if (! $transaction->hasErrors()) {
                $this->invalidateVerificationCandidates();
            }
        } catch (Throwable $e) {
            $this->lastStorageException = $e;
            $transaction->markError();
        }

        // Finish transaction
        if (! $transaction->finish()) {
            $this->lastStorageException ??= $transaction->getLastException()
                ?? $this->partHandler->getLastException()
                ?? $this->binaryHandler->getLastException()
                ?? $this->collectionHandler->getLastException();
            $this->failedInserts = array_merge(
                $this->failedInserts,
                $chunkNumbers,
                $this->partHandler->getFailedNumbers()
            );

            return false;
        }

        $this->failedInserts = array_merge(
            $this->failedInserts,
            $this->partHandler->getFailedNumbers()
        );

        return true;
    }

    private function isTransientLockError(?Throwable $exception): bool
    {
        return $exception !== null && SqlError::isTransientLock($exception);
    }

    /**
     * Lock conflicts, and a collection or binary deleted by the releases or cleanup pass after this
     * attempt resolved its id. A fresh attempt re-resolves (or recreates) the parent row.
     */
    private function isRetryableStorageError(?Throwable $exception): bool
    {
        return $this->isTransientLockError($exception) || $this->isMissingParentRow($exception);
    }

    /**
     * MySQL/MariaDB 1452: a child row's foreign key names a parent row that no longer exists.
     */
    private function isMissingParentRow(?Throwable $exception): bool
    {
        return $exception instanceof QueryException && (int) ($exception->errorInfo[1] ?? 0) === 1452;
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     */
    private function processHeaderChunk(array $headers, array $groupMySQL, HeaderStorageTransaction $transaction): void
    {
        $totalFilesByIndex = [];
        $fileNumbersByIndex = [];

        foreach ($headers as $index => $header) {
            [$fileNumber, $totalFiles] = $this->extractFileNumberAndTotal($header);
            $fileNumbersByIndex[$index] = $fileNumber;
            $totalFilesByIndex[$index] = $totalFiles;
        }

        $collectionIds = $this->collectionHandler->getOrCreateCollections(
            $headers,
            $groupMySQL['id'],
            $groupMySQL['name'],
            $totalFilesByIndex,
            $transaction->getBatchNoise()
        );

        if (config('release_verification.required', true)) {
            $ids = array_values(array_unique($collectionIds));
            sort($ids);
            $locked = DB::table('collections')
                ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('PRIMARY'))
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if (Schema::hasTable('release_verification_candidates')) {
                $hashes = DB::table('collections')->whereIn('id', $ids)->pluck('collectionhash', 'id');
                $identities = $hashes->map(static fn ($hash): string => bin2hex($hash))->all();
                // A published partial keeps its inventory; its late articles must merge promptly too.
                $active = DB::table('release_verification_candidates')->whereIn('source_identity', $identities)
                    ->where(fn ($query) => $query->whereNull('release_id')->orWhere('manifest_path', '<>', ''))
                    ->whereNotIn('status', ['expired'])->pluck('source_identity')->all();
                $tracked = $hashes->filter(static fn ($hash): bool => in_array(bin2hex($hash), $active, true))->keys()->all();
                foreach ($tracked as $id) {
                    $this->verificationCollectionIds[(int) $id] = true;
                }
            }
            if (count($locked) !== count($ids)) {
                throw new \RuntimeException('Collection was archived during header resolution; retry ingestion.');
            }
        }

        $binaryRecords = [];
        foreach ($headers as $index => $header) {
            if (! isset($collectionIds[$index])) {
                $this->markHeaderFailed($header, $transaction);

                continue;
            }

            $binaryRecords[$index] = [
                'header' => $header,
                'collection_id' => $collectionIds[$index],
                'file_number' => $fileNumbersByIndex[$index],
            ];
        }

        $binaryIds = $this->binaryHandler->getOrCreateBinaries($binaryRecords, $groupMySQL['id']);

        $partIndexes = [];
        foreach ($binaryRecords as $index => $record) {
            if (! isset($binaryIds[$index])) {
                $this->markHeaderFailed($record['header'], $transaction);

                continue;
            }

            $partIndexes[] = $index;
        }

        // The part handler flushes every SQL chunk inside this transaction, so parts are queued in
        // primary-key order (binaries_id, partnumber) across all flushes. Concurrent workers then
        // take shared part locks in one order instead of deadlocking across flush boundaries.
        usort($partIndexes, static fn (int $a, int $b): int => [$binaryIds[$a], (int) ($binaryRecords[$a]['header']['matches'][2] ?? 0)]
            <=> [$binaryIds[$b], (int) ($binaryRecords[$b]['header']['matches'][2] ?? 0)]);

        foreach ($partIndexes as $index) {
            $header = $binaryRecords[$index]['header'];
            if (! $this->partHandler->addPart($binaryIds[$index], $header)) {
                $this->markHeaderFailed($header, $transaction);
            }
        }
    }

    private function invalidateVerificationCandidates(): void
    {
        if ($this->verificationCollectionIds !== []) {
            app(CandidateRepository::class)->invalidateHotCollections(array_keys($this->verificationCollectionIds));
        }
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array{0: int, 1: int}
     */
    public function extractFileNumberAndTotal(array $header): array
    {
        return $this->extractFileNumberAndTotalPhp($header);
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array{0: int, 1: int}
     */
    public function extractFileNumberAndTotalPhp(array $header): array
    {
        $fileCount = $this->getFileCountPhp((string) ($header['matches'][1] ?? ''));

        return [(int) $fileCount[1], (int) $fileCount[3]];
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array{0: int, 1: int}|null
     */
    public function extractFileNumberAndTotalNative(array $header): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $subject = (string) ($header['matches'][1] ?? '');

        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_header_extract_file_numbers($subject, \strlen($subject));
            if (! \is_int($packed) || $packed < 0) {
                return null;
            }
            if ($packed === 0) {
                return [0, 0];
            }

            return [$packed & 0xFFFFF, ($packed >> 20) & 0xFFFFF];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch extract `[fileNumbersByIndex, totalFilesByIndex]` for a header chunk.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    public function extractFileNumbersAndTotalsBatch(array $headers): array
    {
        return $this->extractFileNumbersAndTotalsBatchPhp($headers);
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    public function extractFileNumbersAndTotalsBatchPhp(array $headers): array
    {
        $fileNumbersByIndex = [];
        $totalFilesByIndex = [];
        foreach ($headers as $index => $header) {
            [$fileNumber, $totalFiles] = $this->extractFileNumberAndTotalPhp($header);
            $fileNumbersByIndex[$index] = $fileNumber;
            $totalFilesByIndex[$index] = $totalFiles;
        }

        return [$fileNumbersByIndex, $totalFilesByIndex];
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @return array{0: array<int, int>, 1: array<int, int>}|null
     */
    public function extractFileNumbersAndTotalsBatchNative(array $headers): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $count = \count($headers);
        if ($count === 0) {
            return [[], []];
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $isList = array_is_list($headers);
        $subjects = [];
        $indices = [];
        if ($isList) {
            foreach ($headers as $header) {
                $subj = (string) ($header['matches'][1] ?? '');
                if (\str_contains($subj, "\0")) {
                    return $this->extractFileNumbersAndTotalsBatchFallbackNative($headers);
                }
                $subjects[] = $subj;
            }
        } else {
            foreach ($headers as $index => $header) {
                $subj = (string) ($header['matches'][1] ?? '');
                if (\str_contains($subj, "\0")) {
                    return $this->extractFileNumbersAndTotalsBatchFallbackNative($headers);
                }
                $subjects[] = $subj;
                $indices[] = $index;
            }
        }

        $joined = \implode("\0", $subjects);
        $needed = $count * 8;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $needed), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_header_extract_file_numbers_batch_nul($joined, \strlen($joined), $count, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_header_extract_file_numbers_batch_nul($joined, \strlen($joined), $count, $scratch, $cap);
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
            }
            if ($written !== $needed) {
                return null;
            }

            /** @var array<int, int>|false $values */
            $values = \unpack('L*', FFI::string($scratch, $written));
            if ($values === false) {
                return null;
            }

            $fileNumbersByIndex = [];
            $totalFilesByIndex = [];
            if ($isList) {
                for ($pos = 0; $pos < $count; $pos++) {
                    $fileNumbersByIndex[$pos] = $values[$pos * 2 + 1];
                    $totalFilesByIndex[$pos] = $values[$pos * 2 + 2];
                }
            } else {
                foreach ($indices as $pos => $index) {
                    $fileNumbersByIndex[$index] = $values[$pos * 2 + 1];
                    $totalFilesByIndex[$index] = $values[$pos * 2 + 2];
                }
            }

            return [$fileNumbersByIndex, $totalFilesByIndex];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @return array{0: array<int, int>, 1: array<int, int>}|null
     */
    private function extractFileNumbersAndTotalsBatchFallbackNative(array $headers): ?array
    {
        $fileNumbersByIndex = [];
        $totalFilesByIndex = [];
        foreach ($headers as $index => $header) {
            $pair = $this->extractFileNumberAndTotalNative($header);
            if ($pair === null) {
                return null;
            }
            $fileNumbersByIndex[$index] = $pair[0];
            $totalFilesByIndex[$index] = $pair[1];
        }

        return [$fileNumbersByIndex, $totalFilesByIndex];
    }

    /** @param  array<string, mixed>  $header */
    private function markHeaderFailed(array $header, HeaderStorageTransaction $transaction): void
    {
        $transaction->markError();
        if (isset($header['Number']) && (\is_int($header['Number']) || \is_string($header['Number']))) {
            $this->failedInserts[] = $header['Number'];
        }
    }

    /**
     * @return array<int, int|string>
     */
    public function getFileCount(string $subject): array
    {
        return $this->getFileCountPhp($subject);
    }

    /**
     * @return array<int, int|string>
     */
    public function getFileCountPhp(string $subject): array
    {
        if (! preg_match(HeaderParser::FILE_COUNTER_PATTERN, $subject, $fileCount)) {
            $fileCount[1] = $fileCount[3] = 0;
        }

        return $fileCount;
    }

    /**
     * @return array<int, int|string>|null
     */
    public function getFileCountNative(string $subject): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_header_get_file_count_span($subject, \strlen($subject));
            if (! \is_int($packed) || $packed < 0) {
                return null;
            }
            if ($packed === 0) {
                return [3 => 0, 1 => 0];
            }

            $start = $packed & 0xFFFFFFFF;
            $d1Len = ($packed >> 32) & 0x7;
            $sepLen = ($packed >> 35) & 0x7;
            $d2Len = ($packed >> 38) & 0x7;
            $fullLen = 1 + $d1Len + $sepLen + $d2Len + 1;

            return [
                0 => \substr($subject, $start, $fullLen),
                1 => \substr($subject, $start + 1, $d1Len),
                2 => \substr($subject, $start + 1 + $d1Len, $sepLen),
                3 => \substr($subject, $start + 1 + $d1Len + $sepLen, $d2Len),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Headers were stored: ingestion is running even if the tmux monitor is not, so a
     * monitor-only recovery doesn't count as downtime (see CollectionDowntimeCompensator).
     */
    private function noteIngestion(): void
    {
        try {
            app(CollectionDowntimeCompensator::class)->noteIngestion();
        } catch (Throwable $e) {
            Log::debug('Unable to record ingestion activity: '.$e->getMessage());
        }
    }
}
