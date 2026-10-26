<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Tmux\CollectionDowntimeCompensator;
use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use FFI;
use FFI\CData;
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

    /**
     * PHP stays the default for summarizeStorageChunkBatch(): the paired benchmark in
     * tests/Unit/RustBinariesConfigServiceHandlerHeaderW41ParityTest.php does not clear 1.5x.
     */
    public const bool W411_RUST_FFI_DEFAULT = false;

    private const array W411_STORAGE_KEYS = ['headers', 'invalid_rows', 'chunks', 'missing_message_id', 'bad_bytes', 'oversized_subject', 'total_bytes'];

    private static ?bool $w411UseRustFfi = null;

    private static FFI|false|null $w411Ffi = null;

    /**
     * Summarizes a header storage batch (`Message-ID`, `Bytes`, `Subject`): the batch splits into ceil(rows /
     * max(1, `$chunkSize`)) chunks, a message id that is not a string or trim()s to '' is missing, a byte count that is
     * not a non-negative int (or 1-18 digit string) is bad and other counts add to the total (saturating at
     * PHP_INT_MAX), and string subjects longer than 255 bytes are oversized. Non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $headers
     * @return array<string, int>
     */
    public static function summarizeStorageChunkBatch(array $headers, int $chunkSize = 500): array
    {
        return (self::usesW411RustFfi() ? self::summarizeStorageChunkBatchNative($headers, $chunkSize) : null)
            ?? self::summarizeStorageChunkBatchPhp($headers, $chunkSize);
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, int>
     */
    public static function summarizeStorageChunkBatchPhp(array $headers, int $chunkSize = 500): array
    {
        $summary = array_fill_keys(self::W411_STORAGE_KEYS, 0);
        $rows = \count($headers);
        $size = max(1, $chunkSize);
        $summary['headers'] = $rows;
        $summary['chunks'] = intdiv($rows, $size) + (int) ($rows % $size !== 0);
        foreach ($headers as $header) {
            if (! \is_array($header)) {
                $summary['invalid_rows']++;

                continue;
            }
            $messageId = $header['Message-ID'] ?? null;
            $summary['missing_message_id'] += (int) (! \is_string($messageId) || trim($messageId) === '');
            $bytes = BinariesService::w411Int($header['Bytes'] ?? null);
            if ($bytes === null || $bytes < 0) {
                $summary['bad_bytes']++;
            } else {
                $total = $summary['total_bytes'];
                $summary['total_bytes'] = $bytes > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $bytes;
            }
            $subject = $header['Subject'] ?? null;
            $summary['oversized_subject'] += (int) (\is_string($subject) && \strlen($subject) > 255);
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, int>|null
     */
    public static function summarizeStorageChunkBatchNative(array $headers, int $chunkSize = 500): ?array
    {
        $words = [$chunkSize, \count($headers)];
        $blob = [];
        foreach ($headers as $header) {
            if (\is_array($header)) {
                BinariesService::w411PackRow($words, $blob, [$header['Message-ID'] ?? null, $header['Bytes'] ?? null, $header['Subject'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = BinariesService::w411Invoke(self::$w411Ffi, 5, $words, $blob);

        return $values !== null && \count($values) === \count(self::W411_STORAGE_KEYS) ? array_combine(self::W411_STORAGE_KEYS, $values) : null;
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

    /**
     * Disabled: the paired common gains clear 1.5x but the uncommon mean and p99 stay near 1.1x
     * (see the W52 parity test): PHP short-circuits invalid rows while packing, the call and the
     * decode cost about as much.
     */
    private const bool W52_RUST_FFI_DEFAULT = false;

    private const string W52_FFI_CDEF = 'ssize_t nntmux_w521_header_storage_validation_batch(const char *parts, size_t parts_len, const char *message_ids, size_t message_ids_len, size_t count, int64_t *out, size_t out_cap);';

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

    /** {@see headerValidationCodes()} results: valid, invalid part number, invalid message ID. */
    public const array W52_VALIDATION_ERRORS = [0 => null, 1 => 'Invalid part number', 2 => 'Invalid message ID'];

    /**
     * PartHandler::validationErrorPhp() for each (part number, Message-ID) row as a code keyed
     * into {@see W52_VALIDATION_ERRORS}, as {@see store()} applies it to every header.
     *
     * @param  list<int>  $partNumbers
     * @param  list<string>  $messageIds
     * @return list<int>
     *
     * @throws \InvalidArgumentException when the columns differ in length
     */
    public static function headerValidationCodes(array $partNumbers, array $messageIds): array
    {
        return self::usesW52RustFfi() ? self::headerValidationCodesNative($partNumbers, $messageIds) : self::headerValidationCodesPhp($partNumbers, $messageIds);
    }

    /**
     * @param  list<int>  $partNumbers
     * @param  list<string>  $messageIds
     * @return list<int>
     */
    public static function headerValidationCodesPhp(array $partNumbers, array $messageIds): array
    {
        self::w52Count($partNumbers, $messageIds);
        $messageIds = array_values($messageIds);
        $codes = [];
        foreach (array_values($partNumbers) as $i => $part) {
            if ($part <= 0) {
                $codes[] = 1;

                continue;
            }
            $messageId = trim($messageIds[$i]);
            $codes[] = $messageId === '' || \strlen($messageId) > 255 || preg_match('/^[\x20-\x7E]+$/D', $messageId) !== 1 ? 2 : 0;
        }

        return $codes;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a
     * Message-ID holding a NUL byte).
     *
     * @param  list<int>  $partNumbers
     * @param  list<string>  $messageIds
     * @return list<int>
     */
    public static function headerValidationCodesNative(array $partNumbers, array $messageIds): array
    {
        $count = self::w52Count($partNumbers, $messageIds);
        $buffer = $count === 0 ? null : self::w52Buffer($count + 1);
        if ($buffer === null) {
            return self::headerValidationCodesPhp($partNumbers, $messageIds);
        }
        [$ffi, $out, $cap] = $buffer;
        // An empty table tells the kernel every part number is positive (the common batch).
        $parts = min($partNumbers) > 0 ? '' : pack('P*', ...array_values($partNumbers));
        $joined = implode("\0", $messageIds);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w52Decode($out, $ffi->nntmux_w521_header_storage_validation_batch($parts, \strlen($parts), $joined, \strlen($joined), $count, $out, $cap), $count + 1);
        } catch (Throwable) {
            $words = null;
        }
        $ones = $words[0] ?? -1;
        if ($words === null || $ones < 0 || $ones >= \count($words)) {
            return self::headerValidationCodesPhp($partNumbers, $messageIds);
        }
        $codes = array_fill(0, $count, 0);
        if (\count($words) === 1) {
            return $codes;
        }

        // Words: [ones, code-1 indices..., code-2 indices...].
        return array_replace($codes, array_fill_keys(\array_slice($words, 1, $ones), 1), array_fill_keys(\array_slice($words, 1 + $ones), 2));
    }

    /**
     * @param  array<mixed>  ...$columns
     */
    private static function w52Count(array ...$columns): int
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count) {
                throw new \InvalidArgumentException('Header validation columns must have the same length.');
            }
        }

        return $count;
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

    // ---- Wave 66.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.55x/5.25x, uncommon 5.91x/4.97x); see RustBinariesCollectionHeaderParserStorageTxMissedPartW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    private const string W66_HEADER_STORAGE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w662_header_storage_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W66_HEADER_STORAGE_TAIL = 15;

    /**
     * Wave 66 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w66', self::W66_HEADER_STORAGE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w66RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w66HeaderStoragePlanBatch() to Rust until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Routes w66HeaderStoragePlanBatch() to PHP until reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores W66_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    public static function usesW66RustFfi(): bool
    {
        $enabled = self::w66RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W66_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w66HeaderStoragePlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderStoragePlanBatch(array $rows): array
    {
        return self::usesW66RustFfi() ? self::w66HeaderStoragePlanBatchNative($rows) : self::w66HeaderStoragePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderStoragePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W66_HEADER_STORAGE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w66RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w66HeaderStoragePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W66_HEADER_STORAGE_FFI_CDEF.)
            $written = $ffi->nntmux_w662_header_storage_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w66HeaderStoragePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w66Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w66Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Parts, payload bytes or new collections that close a storage chunk. */
    private const int W66_STORAGE_CHUNK_PARTS = 500;

    private const int W66_STORAGE_CHUNK_BYTES = 8_388_608;

    private const int W66_STORAGE_CHUNK_COLLECTIONS = 100;

    /**
     * Wave 66.2 storage flush plan. Per `collection_key\tbinary_hash\tpart\tmessage_id\tbytes` row
     * `action | new_collection << 2 | new_binary << 3 | flushed << 4 | flush_reason << 5 |
     * min(collection, 65535) << 7 | min(binary, 65535) << 23 | min(chunk, 4095) << 39 |
     * min(part, 4095) << 51`. Collection key and message id are trimmed, the binary hash trimmed
     * and lowercased; part and bytes are w66Int() fields of 6 and 12 digits. No collection key,
     * binary hash or message id, no positive part or unparsable bytes is malformed (action 0); a
     * repeated message id is a duplicate (2), a repeated collection/binary/part a duplicate part
     * (3); otherwise the part is stored (1) under its collection and binary ordinals (first
     * sight in the batch sets the new flags) and joins the pending chunk. A stored part that
     * brings the chunk to W66_STORAGE_CHUNK_PARTS parts (reason 1), W66_STORAGE_CHUNK_BYTES bytes
     * (2) or W66_STORAGE_CHUNK_COLLECTIONS new collections (3) flushes it; chunk is the index of
     * the chunk the row belongs to. Then four action counts, collections, binaries, flushes, the
     * pending parts, bytes and new collections, the largest flushed chunk, stored bytes, the most
     * parts of one binary, distinct message ids and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderStoragePlanBatchPhp(array $rows): array
    {
        $out = $mids = $parts = $colls = $bins = [];
        $actions = array_fill(0, 4, 0);
        $flushes = $pendingParts = $pendingBytes = $pendingColls = $maxChunk = $newBytes = $maxBinParts = 0;
        foreach ($rows as $row) {
            [$ck, $bh, $part, $mid, $bytes] = explode("\t", (string) $row, 5) + ['', '', '', '', ''];
            $ck = trim($ck);
            $bh = strtolower(trim($bh));
            $mid = trim($mid);
            $part = self::w66Int($part, 6);
            $pn = \is_int($part) && $part > 0 ? $part : 0;
            $bytes = self::w66Int($bytes, 12);
            $b = \is_int($bytes) ? $bytes : 0;
            $newColl = $newBin = $flushed = false;
            $reason = $collOrd = $binOrd = 0;
            $chunk = $flushes;
            if ($ck === '' || $bh === '' || $pn === 0 || $mid === '' || $bytes === false) {
                $action = 0;
            } elseif (isset($mids[$mid])) {
                $action = 2;
            } else {
                $mids[$mid] = true;
                $binKey = \strlen($ck).':'.$ck.$bh;
                $partKey = $binKey.':'.$pn;
                if (isset($parts[$partKey])) {
                    $action = 3;
                } else {
                    $parts[$partKey] = true;
                    if (! isset($colls[$ck])) {
                        $colls[$ck] = \count($colls);
                        $newColl = true;
                    }
                    $collOrd = $colls[$ck];
                    if (! isset($bins[$binKey])) {
                        $bins[$binKey] = [\count($bins), 0];
                        $newBin = true;
                    }
                    $binOrd = $bins[$binKey][0];
                    $maxBinParts = max($maxBinParts, ++$bins[$binKey][1]);
                    $pendingParts++;
                    $pendingBytes += $b;
                    $pendingColls += (int) $newColl;
                    $newBytes += $b;
                    $reason = match (true) {
                        $pendingParts >= self::W66_STORAGE_CHUNK_PARTS => 1,
                        $pendingBytes >= self::W66_STORAGE_CHUNK_BYTES => 2,
                        $pendingColls >= self::W66_STORAGE_CHUNK_COLLECTIONS => 3,
                        default => 0,
                    };
                    if ($reason !== 0) {
                        $flushed = true;
                        $flushes++;
                        $maxChunk = max($maxChunk, $pendingParts);
                        $pendingParts = $pendingBytes = $pendingColls = 0;
                    }
                    $action = 1;
                }
            }
            $actions[$action]++;
            $out[] = $action | (int) $newColl << 2 | (int) $newBin << 3 | (int) $flushed << 4 | $reason << 5 | min($collOrd, 0xFFFF) << 7 | min($binOrd, 0xFFFF) << 23
                | min($chunk, 0xFFF) << 39 | min($pn, 0xFFF) << 51;
        }
        array_push($out, ...$actions);
        array_push($out, \count($colls), \count($bins), $flushes, $pendingParts, $pendingBytes, $pendingColls, $maxChunk, $newBytes, $maxBinParts, \count($mids));
        $out[] = self::w66Digest($out);

        return $out;
    }
}
