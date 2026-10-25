<?php

declare(strict_types=1);

namespace App\Services\LogViewer\Index;

use App\Models\LogIndexFile;
use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogFile;
use App\Services\LogViewer\LogFileRepository;
use App\Services\LogViewer\LogReader;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tails every file in the log directory into the Manticore log index.
 *
 * Each run reconciles the directory with `log_index_files` (new, renamed, truncated, replaced and vanished
 * files), then reads each file forward from where the previous run stopped, newest files first, within a
 * byte budget. Progress is saved after every bulk write, so an interrupted run loses at most one batch.
 */
class LogIndexer
{
    public const string STATE_INDEXED = 'indexed';

    public const string STATE_PARTIAL = 'partial';

    public const string STATE_NONE = 'none';

    private const int HEAD_BYTES = 1024;

    private const int MAX_BATCH_BYTES = 8_388_608;

    /**
     * Minutes without writes after which a file's last entry counts as finished.
     */
    private const int TAIL_FLUSH_MINUTES = 2;

    /**
     * Rust parity exports; the document builder stays in PHP (below the 1.5x adoption gate, see
     * `tests/Unit/RustLogViewerAndAuditParserParityTest.php`).
     */
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_logviewer_document_id(int64_t file_id, int64_t offset);
        ssize_t nntmux_logviewer_scrub_utf8(const char *input, size_t len, char *out_buf, size_t out_cap);
        C;

    /**
     * Benchmark-gated default for {@see scanChunkBatch()}: Rust cleared every 1.5x mean/p99 gate; see
     * tests/Unit/RustLogViewerIndexIndexerCoordinatorManticoreSearcherW47ParityTest.php.
     */
    public const bool W47_LOG_INDEXER_RUST_FFI_DEFAULT = true;

    /**
     * A Monolog entry header with a date-time, channel and upper-case level (byte classes only).
     */
    private const string W47_HEADER_PATTERN = '/^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}[^\]]*\] [A-Za-z0-9_.\-]+\.[A-Z]+:/';

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?bool $useW47LogIndexerRustFfi = null;

    public function __construct(
        private readonly LogIndex $index,
        private readonly LogFileRepository $logs,
        private readonly LogReader $reader,
    ) {}

    /**
     * @param  list<string>  $only  relative paths to index (empty = every file)
     * @return array{files: int, entries: int, bytes: int, purged: int}
     */
    public function run(array $only = []): array
    {
        $this->index->ensureTable();
        $stats = ['files' => 0, 'entries' => 0, 'bytes' => 0, 'purged' => 0];
        $tracked = $this->reconcile($this->logs->all(), $stats);
        $this->resetIfIndexWasWiped($tracked);

        $remaining = max(1, (int) config('nntmux.log_viewer.index.max_bytes_per_run', 268_435_456));
        $perFile = max(1, (int) config('nntmux.log_viewer.index.max_bytes_per_file', 67_108_864));

        foreach ($tracked as [$row, $file]) {
            if ($remaining <= 0) {
                break;
            }

            if ($only !== [] && ! in_array($file->path, $only, true)) {
                continue;
            }

            [$entries, $bytes] = $this->indexFile($row, $file, min($perFile, $remaining));
            $remaining -= $bytes;
            $stats['entries'] += $entries;
            $stats['bytes'] += $bytes;
            $stats['files'] += $entries > 0 ? 1 : 0;
        }

        return $stats;
    }

    /**
     * Drop the index and all progress; the next run recreates the table and indexes every file from the start.
     */
    public function reset(): void
    {
        $this->index->drop();
        LogIndexFile::query()->delete();
    }

    /**
     * Remove a file's documents ahead of truncating or deleting it, so stale hits disappear immediately.
     * When Manticore cannot be reached the next run notices the change and cleans up instead.
     */
    public function forget(LogFile $file): void
    {
        if (! $this->index->isEnabled()) {
            return;
        }

        $row = $this->rowsFor([$file])[$file->path] ?? null;

        if ($row === null) {
            return;
        }

        try {
            $this->index->purgeFile($row->id);
            $row->delete();
        } catch (Throwable $exception) {
            Log::warning('Log index: could not purge '.$file->path.': '.$exception->getMessage());
        }
    }

    /**
     * Tracking rows for files, keyed by file path (files never indexed are left out).
     *
     * @param  list<LogFile>  $files
     * @return array<string, LogIndexFile>
     */
    public function rowsFor(array $files): array
    {
        $identities = [];

        foreach ($files as $file) {
            $identity = $this->identity($file);

            if ($identity !== null) {
                $identities[$file->path] = $identity;
            }
        }

        if ($identities === []) {
            return [];
        }

        $rows = LogIndexFile::query()
            ->whereIn('inode', array_column($identities, 1))
            ->get()
            ->keyBy(static fn (LogIndexFile $row): string => $row->device.':'.$row->inode);

        $matched = [];

        foreach ($identities as $path => [$device, $inode]) {
            $row = $rows->get($device.':'.$inode);

            if ($row instanceof LogIndexFile) {
                $matched[$path] = $row;
            }
        }

        return $matched;
    }

    /**
     * How far each file is indexed, keyed by path.
     *
     * @param  list<LogFile>  $files
     * @return array<string, array{state: string, indexed_bytes: int, indexed_at: string|null}>
     */
    public function status(array $files): array
    {
        $rows = $this->rowsFor($files);
        $status = [];

        foreach ($files as $file) {
            $row = $rows[$file->path] ?? null;

            $status[$file->path] = [
                'state' => $this->state($row, $file),
                'indexed_bytes' => $row === null ? 0 : min($row->indexed_offset, $file->size),
                'indexed_at' => $row?->indexed_at?->toIso8601String(),
            ];
        }

        return $status;
    }

    /**
     * Whether searches over the file can be answered from the index: it has been indexed at least once
     * and its unindexed tail is within the lag tolerance.
     */
    public function isCaughtUp(LogIndexFile $row, LogFile $file): bool
    {
        return $this->state($row, $file) === self::STATE_INDEXED;
    }

    private function state(?LogIndexFile $row, LogFile $file): string
    {
        if ($row === null || $row->indexed_at === null) {
            return self::STATE_NONE;
        }

        $lagTolerance = max(0, (int) config('nntmux.log_viewer.index.lag_tolerance_bytes', 1_048_576));

        return $file->size - $row->indexed_offset <= $lagTolerance && $file->size >= $row->indexed_offset
            ? self::STATE_INDEXED
            : self::STATE_PARTIAL;
    }

    /**
     * @param  list<LogFile>  $files  newest first
     * @param  array{files: int, entries: int, bytes: int, purged: int}  $stats
     * @return list<array{LogIndexFile, LogFile}>
     */
    private function reconcile(array $files, array &$stats): array
    {
        $rows = LogIndexFile::query()->get()->keyBy(static fn (LogIndexFile $row): string => $row->device.':'.$row->inode);
        $tracked = [];
        $seen = [];

        foreach ($files as $file) {
            $identity = $this->identity($file);

            if ($identity === null) {
                continue;
            }

            [$device, $inode] = $identity;
            $row = $rows->get($device.':'.$inode);

            if ($row instanceof LogIndexFile && isset($seen[$row->id])) {
                continue;
            }

            if (! $row instanceof LogIndexFile) {
                $row = LogIndexFile::query()->create([
                    'path' => $file->path,
                    'device' => $device,
                    'inode' => $inode,
                    'structured' => $file->structured,
                    ...$this->head($file),
                ]);
            } elseif ($file->size < $row->indexed_offset || $row->structured !== $file->structured || ! $this->headMatches($row, $file)) {
                // Truncated, replaced under a reused inode, or now parsed differently: index it again.
                $this->index->purgeFile($row->id);
                $row->forceFill(['path' => $file->path, 'structured' => $file->structured, ...$this->head($file)]);
                $row->resetProgress();
                $stats['purged']++;
            } else {
                $row->path = $file->path;

                if ($row->head_length < self::HEAD_BYTES && $file->size > $row->head_length) {
                    $row->forceFill($this->head($file));
                }

                $row->save();
            }

            $seen[$row->id] = true;
            $tracked[] = [$row, $file];
        }

        foreach ($rows as $row) {
            if (! isset($seen[$row->id])) {
                $this->index->purgeFile($row->id);
                $row->delete();
                $stats['purged']++;
            }
        }

        return $tracked;
    }

    /**
     * The table was dropped or truncated behind our back (for example a Manticore rebuild): start over.
     *
     * @param  list<array{LogIndexFile, LogFile}>  $tracked
     */
    private function resetIfIndexWasWiped(array $tracked): void
    {
        $hasProgress = array_any($tracked, static fn (array $pair): bool => $pair[0]->indexed_offset > 0);

        if (! $hasProgress || $this->index->count() > 0) {
            return;
        }

        foreach ($tracked as [$row]) {
            $row->resetProgress();
        }
    }

    /**
     * @return array{int, int} entries written and bytes consumed
     */
    private function indexFile(LogIndexFile $row, LogFile $file, int $budget): array
    {
        $start = $row->indexed_offset;

        if ($start >= $file->size) {
            if ($row->indexed_at === null) {
                $this->saveProgress($row, $start, $row->indexed_line, $file);
            }

            return [0, 0];
        }

        $batchSize = max(1, (int) config('nntmux.log_viewer.index.batch_size', 1000));
        $entries = $this->reader->entriesForward($file, $start, $row->indexed_line, $budget, ! $file->isActive(self::TAIL_FLUSH_MINUTES));
        $batch = [];
        $batchBytes = 0;
        $written = 0;

        foreach ($entries as [$entry, $firstLine, $linesThrough]) {
            $batch[] = $this->document($row, $entry, $firstLine);
            $batchBytes += strlen($firstLine) + strlen($entry->body);

            if (count($batch) >= $batchSize || $batchBytes >= self::MAX_BATCH_BYTES) {
                $this->index->replace($batch);
                $written += count($batch);
                $this->saveProgress($row, $entry->end, $linesThrough, $file);
                $batch = [];
                $batchBytes = 0;
            }
        }

        $resume = $entries->getReturn();
        $this->index->replace($batch);
        $written += count($batch);
        $this->saveProgress($row, $resume['offset'], $resume['line'], $file);

        return [$written, max(0, $resume['offset'] - $start)];
    }

    private function saveProgress(LogIndexFile $row, int $offset, int $line, LogFile $file): void
    {
        $row->forceFill([
            'indexed_offset' => $offset,
            'indexed_line' => $line,
            'indexed_size' => $file->size,
            'indexed_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, int|string>
     */
    private function document(LogIndexFile $row, LogEntry $entry, string $firstLine): array
    {
        return self::documentFields($row->id, $entry, $firstLine);
    }

    /**
     * The Manticore document for one entry of the tracked file `$fileId`.
     *
     * @return array<string, int|string>
     */
    public static function documentFields(int $fileId, LogEntry $entry, string $firstLine): array
    {
        return [
            'id' => LogIndex::documentId($fileId, $entry->offset),
            'file_id' => $fileId,
            'byte_offset' => $entry->offset,
            'byte_end' => $entry->end,
            'line_no' => $entry->lineNumber ?? 0,
            'logged_at' => $entry->loggedAt() ?? 0,
            'level' => $entry->level ?? '',
            'channel' => $entry->channel ?? '',
            'structured' => $entry->channel !== null ? 1 : 0,
            'truncated' => $entry->truncated ? 1 : 0,
            'first_line' => mb_scrub($firstLine, 'UTF-8'),
            'body' => mb_scrub($entry->body, 'UTF-8'),
        ];
    }

    /**
     * Rust parity helper for {@see documentFields()}; null when the library is unavailable or disabled.
     *
     * @return array<string, int|string>|null
     */
    public static function documentFieldsNative(int $fileId, LogEntry $entry, string $firstLine): ?array
    {
        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $id = $ffi->nntmux_logviewer_document_id($fileId, $entry->offset);
            $first = self::scrubNative($ffi, $firstLine);
            $body = self::scrubNative($ffi, $entry->body);
        } catch (Throwable) {
            return null;
        }

        if (! is_int($id) || $first === null || $body === null) {
            return null;
        }

        return [
            'id' => $id,
            'file_id' => $fileId,
            'byte_offset' => $entry->offset,
            'byte_end' => $entry->end,
            'line_no' => $entry->lineNumber ?? 0,
            'logged_at' => $entry->loggedAt() ?? 0,
            'level' => $entry->level ?? '',
            'channel' => $entry->channel ?? '',
            'structured' => $entry->channel !== null ? 1 : 0,
            'truncated' => $entry->truncated ? 1 : 0,
            'first_line' => $first,
            'body' => $body,
        ];
    }

    /**
     * `mb_scrub($text, 'UTF-8')` through Rust.
     */
    public static function scrubUtf8Native(string $text): ?string
    {
        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        try {
            return self::scrubNative($ffi, $text);
        } catch (Throwable) {
            return null;
        }
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** Pass null to restore {@see W47_LOG_INDEXER_RUST_FFI_DEFAULT}. */
    public static function useW47LogIndexerRustFfi(?bool $enable = true): void
    {
        self::$useW47LogIndexerRustFfi = $enable;
    }

    public static function usesW47LogIndexerRustFfi(): bool
    {
        return self::$useW47LogIndexerRustFfi ?? self::W47_LOG_INDEXER_RUST_FFI_DEFAULT;
    }

    public static function resetW47LogIndexerRustFfi(): void
    {
        self::$useW47LogIndexerRustFfi = null;
    }

    /**
     * Scans raw log chunks for entry boundaries. Per chunk: `[lines, headers, continuations, first_header,
     * last_header, resume, longest_entry, crlf]` — complete lines, Monolog entry headers, stack-trace
     * continuation lines (`#<digit>`, leading space/tab, `Stack trace:` or `[stacktrace]`), byte offsets of
     * the first and last header (-1 without one), the offset after the last newline where the next read
     * resumes, the longest header-to-header (or header-to-resume) span and lines ending in CR.
     *
     * @param  list<string>  $chunks
     * @return list<list<int>>
     */
    public static function scanChunkBatch(array $chunks): array
    {
        return self::usesW47LogIndexerRustFfi() ? self::scanChunkBatchNative($chunks) : self::scanChunkBatchPhp($chunks);
    }

    /**
     * @param  list<string>  $chunks
     * @return list<list<int>>
     */
    public static function scanChunkBatchPhp(array $chunks): array
    {
        $rows = [];

        foreach ($chunks as $chunk) {
            $lines = explode("\n", $chunk);
            array_pop($lines);
            $headers = $continuations = $crlf = $longest = $offset = 0;
            $first = $last = -1;

            foreach ($lines as $line) {
                if (str_ends_with($line, "\r")) {
                    $crlf++;
                }

                $lead = $line[0] ?? '';

                if ($lead === '[' && preg_match(self::W47_HEADER_PATTERN, $line) === 1) {
                    $headers++;

                    if ($first < 0) {
                        $first = $offset;
                    }

                    if ($last >= 0) {
                        $longest = max($longest, $offset - $last);
                    }

                    $last = $offset;
                } elseif ($lead === ' ' || $lead === "\t" || ($lead === '#' && strspn($line, '0123456789', 1, 1) === 1)
                    || str_starts_with($line, 'Stack trace:') || str_starts_with($line, '[stacktrace]')) {
                    $continuations++;
                }

                $offset += \strlen($line) + 1;
            }

            if ($last >= 0) {
                $longest = max($longest, $offset - $last);
            }

            $rows[] = [\count($lines), $headers, $continuations, $first, $last, $offset, $longest, $crlf];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $chunks
     * @return list<list<int>>
     */
    public static function scanChunkBatchNative(array $chunks): array
    {
        $count = \count($chunks);

        if ($count === 0) {
            return [];
        }

        $raw = LogIndex::evaluateW47Native(2, pack('P', $count).LogIndex::w47Joined($chunks), 64 * $count);

        return $raw === null ? self::scanChunkBatchPhp($chunks) : LogIndex::w47Rows($raw, 8);
    }

    private static function scrubNative(FFI $ffi, string $text): ?string
    {
        $length = strlen($text);

        if ($length === 0) {
            return '';
        }

        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length, $capacity);

        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_logviewer_scrub_utf8($text, $length, $scratch, $capacity);

        if ($written === -1) {
            return $text;
        }

        return is_int($written) && $written >= 0 ? FFI::string($scratch, $written) : null;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }

        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * Device and inode of the file; files on filesystems without inodes fall back to a hash of the path.
     *
     * @return array{int, int}|null
     */
    private function identity(LogFile $file): ?array
    {
        clearstatcache(true, $file->absolutePath);
        $stat = @stat($file->absolutePath);

        if ($stat === false) {
            return null;
        }

        return (int) $stat['ino'] > 0 ? [(int) $stat['dev'], (int) $stat['ino']] : [0, crc32($file->path)];
    }

    /**
     * @return array{head_hash: string, head_length: int}
     */
    private function head(LogFile $file, ?int $length = null): array
    {
        $length ??= min(self::HEAD_BYTES, $file->size);
        $bytes = $length > 0 ? (string) @file_get_contents($file->absolutePath, false, null, 0, $length) : '';

        return ['head_hash' => sha1($bytes), 'head_length' => strlen($bytes)];
    }

    private function headMatches(LogIndexFile $row, LogFile $file): bool
    {
        return $file->size >= $row->head_length && $this->head($file, $row->head_length)['head_hash'] === $row->head_hash;
    }
}
