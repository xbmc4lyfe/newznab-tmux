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

    /** C signature of the Wave 61.5 {@see self::checkpointPlanBatch()} helper. */
    private const string W61_LOG_INDEXER_PLAN_FFI_CDEF = 'int64_t nntmux_w615_log_indexer_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 61.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 5.72/3.61/5.83/5.04x); see tests/Unit/RustGoogleBooksIgdbImdbScraperItunesLogIndexerW61ParityTest.php.
     */
    public const bool W61_RUST_FFI_DEFAULT = true;

    /**
     * Wave 61.5 routing and FFI cache, kept in function-local statics (no static properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w61_5', self::W61_LOG_INDEXER_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W61_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW61RustFfi(): bool
    {
        return self::w61RustFfiState() === true;
    }

    /**
     * Runs $callback with checkpointPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 61.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w61Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w61RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w615_log_indexer_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch log indexer checkpoint plan over `size\tindexed_offset\tindexed_line\thead_length\tstored_inode\tinode\tmtime\tnow
     * \tpending_bytes\tpending_entries\tbatch_size\tlag_tolerance`. Ten words per record: `[action, resume_offset, remaining,
     * flush, chunk_bytes, state, active, head_refresh, chunks, flags]`. Fields are trimmed 1-18 digits; empty or invalid
     * ones take their default (0, batch size 1000 floored at 1, lag tolerance 1048576). Action: 3 new (no stored inode),
     * 2 reset (inode changed or truncated below the offset), 1 index the unread tail, 0 caught up; resets resume at 0.
     * The remaining bytes are read in MAX_BATCH_BYTES chunks; the pending batch flushes at the batch size or
     * MAX_BATCH_BYTES. State is 0 none after a reset, 1 indexed within the lag tolerance, 2 partial. Active means
     * modified within TAIL_FLUSH_MINUTES; the head refreshes after a reset or while shorter than HEAD_BYTES of a longer
     * file. Flags: 1 invalid number, 2 truncated, 4 inode changed, 8 modified in the future, 16 offset without a line,
     * 32 byte flush, 64 entry flush, 128 not exactly twelve fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function checkpointPlanBatch(array $records): array
    {
        return self::usesW61RustFfi() ? self::checkpointPlanBatchNative($records) : self::checkpointPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function checkpointPlanBatchPhp(array $records): array
    {
        $out = [];
        $defaults = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1000, 1_048_576];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 12 ? 0 : 128;
            $values = $defaults;
            foreach ($defaults as $index => $default) {
                $text = trim($fields[$index] ?? '');
                if ($text === '') {
                    continue;
                }
                if (\strlen($text) <= 18 && ctype_digit($text)) {
                    $values[$index] = (int) $text;
                } else {
                    $flags |= 1;
                }
            }
            [$size, $offset, $line, $head, $stored, $inode, $mtime, $now, $bytes, $entries, $batch, $lag] = $values;
            $batch = max(1, $batch);
            if ($size < $offset) {
                $flags |= 2;
            }
            if ($stored > 0 && $inode > 0 && $inode !== $stored) {
                $flags |= 4;
            }
            $action = match (true) {
                $stored === 0 => 3,
                ($flags & 6) !== 0 => 2,
                $offset < $size => 1,
                default => 0,
            };
            $resume = $action >= 2 ? 0 : $offset;
            $remaining = $size - $resume;
            if ($entries >= $batch) {
                $flags |= 64;
            }
            if ($bytes >= self::MAX_BATCH_BYTES) {
                $flags |= 32;
            }
            $state = $action >= 2 ? 0 : ($remaining <= $lag ? 1 : 2);
            if ($now > 0 && $mtime > $now) {
                $flags |= 8;
            }
            $active = $mtime > 0 && $now >= $mtime && $now - $mtime < self::TAIL_FLUSH_MINUTES * 60 ? 1 : 0;
            $refresh = $action >= 2 || ($head < self::HEAD_BYTES && $size > $head) ? 1 : 0;
            if ($offset > 0 && $line === 0) {
                $flags |= 16;
            }
            array_push(
                $out,
                $action,
                $resume,
                $remaining,
                ($flags & 96) !== 0 ? 1 : 0,
                min($remaining, self::MAX_BATCH_BYTES),
                $state,
                $active,
                $refresh,
                intdiv($remaining + self::MAX_BATCH_BYTES - 1, self::MAX_BATCH_BYTES),
                $flags,
            );
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function checkpointPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w61Words($count, implode("\0", $records), 10 * $count) ?? self::checkpointPlanBatchPhp($records);
    }
}
