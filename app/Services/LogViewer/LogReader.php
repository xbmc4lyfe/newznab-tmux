<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Generator;
use RuntimeException;
use SplDoublyLinkedList;
use Throwable;

/**
 * Reads log entries without loading whole files: the latest entries are collected by reading
 * backwards from a byte cursor in fixed-size chunks, and single entries are resolved around a byte offset.
 */
class LogReader
{
    public const int FULL_ENTRY_BYTES = 1_048_576;

    private const int HEADER_SEARCH_BYTES = 262_144;

    private const int TRAILING_SCAN_FACTOR = 8;

    /**
     * Rust parity exports; the split and cap helpers stay in PHP (below the 1.5x adoption gate, see
     * `tests/Unit/RustLogViewerAndAuditParserParityTest.php`).
     */
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_logviewer_cap_len(size_t line_len, int64_t max_bytes);
        ssize_t nntmux_logviewer_split_backward(const char *buf, size_t len, char *out_buf, size_t out_cap);
        C;

    /** Shared Wave 48.5 batch export for the log viewer and release search helpers. */
    public const string W48_FFI_CDEF = 'int64_t nntmux_w48_logviewer_entry_file_repo_reader_release_search(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Benchmark-gated default for {@see assembleRecordBatch()}: stays PHP because the p99 ratios fell
     * below 1.5x (1.44x common, 1.46x uncommon) although means were about 1.9x; see
     * tests/Unit/RustLogViewerEntryFileRepoReaderReleaseSearchW48ParityTest.php.
     */
    public const bool W48_LOG_READER_RUST_FFI_DEFAULT = false;

    private const int W48_MAX_BYTES = 67_108_864;

    /** Base byte offsets are clamped so line offsets never overflow. */
    private const int W48_MAX_BASE_OFFSET = 1 << 48;

    /** Page offsets and limits are clamped to this. */
    private const int W48_MAX_PAGE = 1 << 40;

    private static ?bool $useW48LogReaderRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    private static ?CData $w48Out = null;

    private static int $w48OutCap = 0;

    private static bool $useRustFfi = true;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    public function __construct(
        private readonly LogEntryParser $parser,
        private readonly int $chunkSize = 65_536,
        private readonly int $maxEntryBytes = 32_768,
        private readonly int $scanBudget = 33_554_432,
    ) {}

    /**
     * Newest-first entries that start before the `$before` byte cursor (or end of file).
     *
     * @param  list<string>  $levels  only return entries with these levels (empty = all)
     * @return array{entries: list<LogEntry>, before: int|null, scanned_bytes: int, budget_exhausted: bool}
     */
    public function latest(LogFile $file, ?int $before, int $limit, array $levels = []): array
    {
        $handle = $this->open($file);

        try {
            $size = $this->size($handle);
            $end = $before === null ? $size : min(max($before, 0), $size);

            $entries = [];
            $entryEnd = $end;
            $resume = null;
            $stoppedEarly = false;
            $lastOffset = $end;

            /** @var SplDoublyLinkedList<string> $pending continuation lines, bottom-most first */
            $pending = new SplDoublyLinkedList;
            $pendingBytes = 0;
            $pendingTop = null;
            $truncated = false;

            $lines = $this->linesBackward($handle, $end, $this->scanBudget);

            foreach ($lines as [$offset, $rawLine]) {
                $lastOffset = $offset;
                $line = rtrim($rawLine, "\r");

                if ($file->structured && ! $this->parser->isHeader($line)) {
                    [$line, $lineTruncated] = $this->cap($line, $this->maxEntryBytes);
                    $pending->push($line);
                    $pendingBytes += strlen($line) + 1;
                    $pendingTop = $offset;
                    $truncated = $truncated || $lineTruncated;

                    while ($pendingBytes > $this->maxEntryBytes && $pending->count() > 1) {
                        $pendingBytes -= strlen($pending->shift()) + 1;
                        $truncated = true;
                    }

                    continue;
                }

                if (! $file->structured && trim($line) === '') {
                    $entryEnd = $offset;

                    continue;
                }

                [$first, $firstTruncated] = $this->cap($line, $this->maxEntryBytes);
                $entry = $this->parser->make($offset, $entryEnd, $first, $this->joinPending($pending), $truncated || $firstTruncated, $file->structured);

                $pending = new SplDoublyLinkedList;
                $pendingBytes = 0;
                $pendingTop = null;
                $truncated = false;
                $entryEnd = $offset;

                if ($levels !== [] && ! in_array($entry->level, $levels, true)) {
                    continue;
                }

                $entries[] = $entry;

                if (count($entries) >= $limit) {
                    $stoppedEarly = true;
                    $resume = $offset > 0 ? $offset : null;

                    break;
                }
            }

            $exhausted = ! $stoppedEarly && $lines->getReturn() === true;

            if ($exhausted) {
                // Resume from the last complete entry boundary; if not even one entry fit in the budget, skip past what was scanned.
                $resume = $entryEnd < $end ? $entryEnd : $lastOffset;
                $resume = $resume > 0 ? $resume : null;
            } elseif (! $stoppedEarly && $pendingTop !== null && $pending->count() > 0) {
                // Continuation lines above the first header (file rotated mid-entry): show them as an orphan entry.
                $orphanLines = $this->pendingTopFirst($pending);
                $first = array_shift($orphanLines);
                $entry = $this->parser->make($pendingTop, $entryEnd, (string) $first, implode("\n", $orphanLines), $truncated, false);

                if ($levels === []) {
                    $entries[] = $entry;
                }
            }

            return [
                'entries' => $entries,
                'before' => $resume,
                'scanned_bytes' => $end - ($stoppedEarly || $exhausted ? $lastOffset : 0),
                'budget_exhausted' => $exhausted,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * The complete entry containing the byte at `$offset`, or null when the offset is past the end of the file.
     */
    public function entryAt(LogFile $file, int $offset, int $maxBytes = self::FULL_ENTRY_BYTES): ?LogEntry
    {
        $handle = $this->open($file);

        try {
            return $this->entryFromHandle($handle, $file, $offset, $maxBytes);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    public function entryFromHandle($handle, LogFile $file, int $offset, ?int $maxBytes = null, ?int $lineNumber = null): ?LogEntry
    {
        $size = $this->size($handle);

        if ($offset < 0 || $offset >= $size) {
            return null;
        }

        $start = $this->lineStart($handle, $offset);

        if ($file->structured) {
            $start = $this->headerStart($handle, $start);
        }

        return $this->readEntryForward($handle, $file, $start, $size, $maxBytes ?? $this->maxEntryBytes, $lineNumber);
    }

    /**
     * Complete entries from `$from` forward, oldest first, for incremental indexing.
     *
     * A structured entry is complete once the next header has been read; its trailing entry (and a final
     * line still missing its newline) is held back unless `$flushTail` is set, because a running process
     * may still be appending to it. Reading stops at the first entry boundary past `$budget` bytes.
     *
     * Yields `[entry, raw first line, lines consumed through the end of the entry]` and returns where the
     * next call should resume: a byte offset at an entry boundary and the number of lines before it.
     *
     * @return Generator<int, array{LogEntry, string, int}, mixed, array{offset: int, line: int}>
     */
    public function entriesForward(LogFile $file, int $from, int $startLine, int $budget, bool $flushTail): Generator
    {
        $handle = $this->open($file);

        try {
            $size = $this->size($handle);
            $position = min(max($from, 0), $size);
            $line = $startLine;
            fseek($handle, $position);

            /** @var array{offset: int, line: int, first: string, body: list<string>, bytes: int, truncated: bool, structured: bool}|null $pending */
            $pending = null;

            while ($position < $size) {
                [$raw, $complete, $terminated] = $this->readForwardLine($handle, $this->maxEntryBytes);

                if ($raw === null || (! $terminated && ! $flushTail)) {
                    break;
                }

                $next = (int) ftell($handle);
                $text = rtrim($raw, "\r");
                $isHeader = $file->structured && $this->parser->isHeader($text);

                if (! $file->structured || $isHeader) {
                    if ($pending !== null) {
                        yield [$this->finishPending($pending, $position), $pending['first'], $line];
                        $pending = null;
                    }

                    if ($position - $from >= $budget) {
                        return ['offset' => $position, 'line' => $line];
                    }
                }

                $line++;

                if (! $file->structured) {
                    if (trim($text) !== '') {
                        yield [$this->parser->make($position, $next, $text, '', ! $complete, false, $line), $text, $line];
                    }
                } elseif ($pending === null) {
                    // A header starts an entry; continuation lines before the first header form an orphan block.
                    $pending = ['offset' => $position, 'line' => $line, 'first' => $text, 'body' => [], 'bytes' => 0, 'truncated' => ! $complete, 'structured' => $isHeader];
                } elseif ($this->maxEntryBytes < $pending['bytes'] + strlen($text) + 1) {
                    $pending['truncated'] = true;
                } else {
                    $pending['body'][] = $text;
                    $pending['bytes'] += strlen($text) + 1;
                    $pending['truncated'] = $pending['truncated'] || ! $complete;
                }

                $position = $next;
            }

            if ($pending === null) {
                return ['offset' => $position, 'line' => $line];
            }

            if ($flushTail && $position >= $size) {
                yield [$this->finishPending($pending, $position), $pending['first'], $line];

                return ['offset' => $position, 'line' => $line];
            }

            return ['offset' => $pending['offset'], 'line' => $pending['line'] - 1];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    public function open(LogFile $file)
    {
        $handle = @fopen($file->absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Log file could not be opened.');
        }

        return $handle;
    }

    /**
     * Yields `[offset, line]` pairs from the line ending at `$end` back to the start of the file.
     * Returns true when it stopped because more than `$budget` bytes were read.
     *
     * @param  resource  $handle
     * @return Generator<int, array{int, string}, mixed, bool>
     */
    private function linesBackward($handle, int $end, int $budget): Generator
    {
        $position = $end;
        /** @var list<string> $carry chunks (in file order) of the line whose start is not yet known */
        $carry = [];
        $atRegionEnd = true;

        while ($position > 0) {
            if ($end - $position >= $budget) {
                return true;
            }

            $read = min($this->chunkSize, $position);
            $position -= $read;
            fseek($handle, $position);
            $chunk = (string) fread($handle, $read);

            if (! str_contains($chunk, "\n")) {
                array_unshift($carry, $chunk);

                continue;
            }

            [$head, $parts, $offsets] = self::splitBackwardChunk($chunk.implode('', $carry), $position);
            $carry = [$head];

            for ($index = count($parts) - 1; $index >= 0; $index--) {
                if ($atRegionEnd) {
                    $atRegionEnd = false;

                    if ($parts[$index] === '') {
                        continue;
                    }
                }

                yield [$offsets[$index], $parts[$index]];
            }
        }

        $top = implode('', $carry);

        if ($top !== '' || ! $atRegionEnd) {
            yield [0, $top];
        }

        return false;
    }

    /**
     * Split a backward chunk (read at byte `$position`) on `\n`: the leading partial line, the complete
     * lines after it, and each line's absolute byte offset.
     *
     * @return array{string, list<string>, list<int>}
     */
    public static function splitBackwardChunk(string $buffer, int $position): array
    {
        $parts = explode("\n", $buffer);
        $head = (string) array_shift($parts);

        $offsets = [];
        $cursor = $position + strlen($head) + 1;

        foreach ($parts as $index => $part) {
            $offsets[$index] = $cursor;
            $cursor += strlen($part) + 1;
        }

        return [$head, $parts, $offsets];
    }

    /**
     * Rust parity helper for {@see splitBackwardChunk()}; null when the library is unavailable or disabled.
     *
     * @return array{string, list<string>, list<int>}|null
     */
    public static function splitBackwardChunkNative(string $buffer, int $position): ?array
    {
        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        $length = strlen($buffer);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $length), $capacity);

        try {
            // @phpstan-ignore method.notFound
            $written = $scratch === null ? null : $ffi->nntmux_logviewer_split_backward($buffer, $length, $scratch, $capacity);

            if (is_int($written) && $written < 0) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                // @phpstan-ignore method.notFound
                $written = $scratch === null ? null : $ffi->nntmux_logviewer_split_backward($buffer, $length, $scratch, $capacity);
            }
        } catch (Throwable) {
            return null;
        }

        if ($scratch === null || ! is_int($written) || $written < 0) {
            return null;
        }

        /** @var list<int> $newlines */
        $newlines = $written === 0 ? [] : array_values((array) unpack('q*', FFI::string($scratch, $written)));
        $start = 0;
        $head = $buffer;
        $parts = [];
        $offsets = [];

        foreach ($newlines as $index => $newline) {
            if ($index === 0) {
                $head = substr($buffer, 0, $newline);
            } else {
                $parts[] = substr($buffer, $start, $newline - $start);
            }

            $offsets[] = $position + $newline + 1;
            $start = $newline + 1;
        }

        if ($newlines !== []) {
            $parts[] = substr($buffer, $start);
        }

        return [$head, $parts, $offsets];
    }

    /**
     * Rust parity helper for {@see cap()}; null when the library is unavailable or disabled.
     *
     * @return array{string, bool}|null
     */
    public static function capNative(string $line, int $maxBytes): ?array
    {
        $ffi = self::ffi();

        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $kept = $ffi->nntmux_logviewer_cap_len(strlen($line), $maxBytes);
        } catch (Throwable) {
            return null;
        }

        if (! is_int($kept)) {
            return null;
        }

        return $kept < 0 ? [substr($line, 0, -$kept - 1), true] : [$line, false];
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** Pass null to restore {@see W48_LOG_READER_RUST_FFI_DEFAULT}. */
    public static function useW48LogReaderRustFfi(?bool $enable = true): void
    {
        self::$useW48LogReaderRustFfi = $enable;
    }

    public static function usesW48LogReaderRustFfi(): bool
    {
        return self::$useW48LogReaderRustFfi ?? self::W48_LOG_READER_RUST_FFI_DEFAULT;
    }

    public static function resetW48LogReaderRustFfi(): void
    {
        self::$useW48LogReaderRustFfi = null;
    }

    /**
     * Folds file lines (split on "\n", top to bottom, starting at byte $baseOffset) into newest-first
     * records the way {@see latest()} walks them: a trailing empty line is ignored, each line drops its
     * trailing "\r" bytes, and in $structured mode lines that do not match {@see LogEntry::W48_HEADER_PATTERN}
     * join the header above them (lines above the first header form one orphan record). Every line keeps
     * at most $maxBytes bytes, and continuation lines are dropped from the bottom while the kept
     * continuation bytes (plus one newline each) exceed $maxBytes; kept_bytes counts one newline between
     * joined lines. Per record: `[start, end,
     * header_index, line_count, kept_bytes, truncated, severity, ordinal, in_page]`; records whose
     * severity reaches $minSeverity get consecutive ordinals (others -1) and are in the page for ordinals
     * in [$pageOffset, $pageOffset + $limit). The summary is `[records, matches, page_count, next_cursor,
     * truncated_records]`, where next_cursor is the last page record's start while more matches follow
     * (-1 otherwise).
     *
     * @param  list<string>  $lines
     * @return array{records: list<list<int>>, summary: list<int>}
     */
    public static function assembleRecordBatch(array $lines, int $baseOffset, bool $structured, int $maxBytes, int $minSeverity, int $pageOffset, int $limit): array
    {
        return self::usesW48LogReaderRustFfi()
            ? self::assembleRecordBatchNative($lines, $baseOffset, $structured, $maxBytes, $minSeverity, $pageOffset, $limit)
            : self::assembleRecordBatchPhp($lines, $baseOffset, $structured, $maxBytes, $minSeverity, $pageOffset, $limit);
    }

    /**
     * @param  list<string>  $lines
     * @return array{records: list<list<int>>, summary: list<int>}
     */
    public static function assembleRecordBatchPhp(array $lines, int $baseOffset, bool $structured, int $maxBytes, int $minSeverity, int $pageOffset, int $limit): array
    {
        $cursor = min(max(0, $baseOffset), self::W48_MAX_BASE_OFFSET);
        $maxBytes = max(1, $maxBytes);
        $pageOffset = min(max(0, $pageOffset), self::W48_MAX_PAGE);
        $pageEnd = $pageOffset + min(max(0, $limit), self::W48_MAX_PAGE);
        $offsets = [];

        foreach ($lines as $line) {
            $offsets[] = $cursor;
            $cursor += \strlen($line) + 1;
        }

        $last = \count($lines);
        if ($last > 0 && $lines[$last - 1] === '') {
            $last--;
        }

        $records = [];
        $pending = [];
        $head = 0;
        $pendingBytes = 0;
        $pendingTop = $pendingEnd = -1;
        $pendingTruncated = false;

        for ($index = $last - 1; $index >= 0; $index--) {
            $raw = $lines[$index];
            $line = rtrim($raw, "\r");
            $length = \strlen($line);
            $severity = $line !== '' && $line[0] === '[' && preg_match(LogEntry::W48_HEADER_PATTERN, $line, $match) === 1
                ? LogEntry::W48_SEVERITY[$match[2]] ?? 0
                : -1;
            $lineEnd = $offsets[$index] + \strlen($raw);
            $kept = min($length, $maxBytes);

            if ($structured && $severity < 0) {
                if ($pendingEnd < 0) {
                    $pendingEnd = $lineEnd;
                }
                $pending[] = $kept;
                $pendingBytes += $kept + 1;
                $pendingTop = $offsets[$index];
                $pendingTruncated = $pendingTruncated || $length > $maxBytes;

                while ($pendingBytes > $maxBytes && \count($pending) - $head > 1) {
                    $pendingBytes -= $pending[$head++] + 1;
                    $pendingTruncated = true;
                }

                continue;
            }

            $records[] = [
                $offsets[$index],
                $pendingEnd >= 0 ? $pendingEnd : $lineEnd,
                $severity >= 0 ? $index : -1,
                1 + \count($pending) - $head,
                $kept + $pendingBytes,
                $length > $maxBytes || $pendingTruncated ? 1 : 0,
                max(0, $severity),
            ];
            $pending = [];
            $head = $pendingBytes = 0;
            $pendingTop = $pendingEnd = -1;
            $pendingTruncated = false;
        }

        if (\count($pending) > $head) {
            $records[] = [$pendingTop, $pendingEnd, -1, \count($pending) - $head, $pendingBytes - 1, $pendingTruncated ? 1 : 0, 0];
        }

        $matches = $pageCount = $truncated = 0;
        $lastStart = -1;

        foreach ($records as $position => $record) {
            $ordinal = -1;
            $inPage = 0;

            if ($record[6] >= $minSeverity) {
                $ordinal = $matches++;

                if ($ordinal >= $pageOffset && $ordinal < $pageEnd) {
                    $inPage = 1;
                    $pageCount++;
                    $lastStart = $record[0];
                }
            }

            $truncated += $record[5];
            $records[$position][] = $ordinal;
            $records[$position][] = $inPage;
        }

        return [
            'records' => $records,
            'summary' => [\count($records), $matches, $pageCount, $pageCount > 0 && $matches > $pageEnd ? $lastStart : -1, $truncated],
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{records: list<list<int>>, summary: list<int>}
     */
    public static function assembleRecordBatchNative(array $lines, int $baseOffset, bool $structured, int $maxBytes, int $minSeverity, int $pageOffset, int $limit): array
    {
        $count = \count($lines);
        $raw = self::evaluateW48Native(
            4,
            pack('P*', $count, $baseOffset, $structured ? 1 : 0, $maxBytes, $minSeverity, $pageOffset, $limit).self::w48Strings($lines),
            72 * $count + 40,
        );
        $length = $raw === null ? 0 : \strlen($raw);

        if ($raw === null || $length < 40 || ($length - 40) % 72 !== 0) {
            return self::assembleRecordBatchPhp($lines, $baseOffset, $structured, $maxBytes, $minSeverity, $pageOffset, $limit);
        }

        return [
            'records' => self::w48Rows(substr($raw, 0, $length - 40), 9),
            'summary' => self::w48Rows($raw, 5, $length - 40)[0],
        ];
    }

    /**
     * Exactly $fields packed little-endian i64 fields from a Wave 48.5 mode, or null.
     *
     * @return list<int>|null
     */
    public static function evaluateW48Fields(int $mode, string $input, int $fields): ?array
    {
        $raw = $fields < 0 ? null : self::evaluateW48Native($mode, $input, $fields * 8);

        if ($raw === null || \strlen($raw) !== $fields * 8) {
            return null;
        }

        return $fields === 0 ? [] : self::w48Rows($raw, $fields)[0];
    }

    /**
     * Output (at most $capacity bytes of packed little-endian i64 fields) from a Wave 48.5 mode, or null
     * to fall back to PHP.
     */
    public static function evaluateW48Native(int $mode, string $input, int $capacity): ?string
    {
        $ffi = self::w48Ffi();
        $length = \strlen($input);

        if ($ffi === null || $mode < 1 || $capacity < 0 || $capacity > self::W48_MAX_BYTES || $length > self::W48_MAX_BYTES) {
            return null;
        }

        try {
            if (self::$w48Out === null || $capacity > self::$w48OutCap) {
                $cap = max(4096, $capacity);
                self::$w48Out = $ffi->new('char['.$cap.']');
                self::$w48OutCap = $cap;
            }

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w48_logviewer_entry_file_repo_reader_release_search($mode, $input, $length, self::$w48Out, $capacity);

            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string(self::$w48Out, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rows of $width fields from packed output.
     *
     * @return list<list<int>>
     */
    public static function w48Rows(string $raw, int $width, int $offset = 0): array
    {
        $values = \strlen($raw) > $offset ? unpack('P*', $raw, $offset) : [];

        /** @var list<list<int>> */
        return \is_array($values) && $width > 0 ? array_chunk($values, $width) : [];
    }

    /**
     * Length-prefixed strings: every byte length as an i64, then the concatenated bytes.
     *
     * @param  list<string>  $strings
     */
    public static function w48Strings(array $strings): string
    {
        return $strings === [] ? '' : pack('P*', ...array_map(\strlen(...), $strings)).implode('', $strings);
    }

    private static function w48Ffi(): ?FFI
    {
        if (self::$w48Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w48Ffi = RustPipelineLibrary::resolveDomain('search_w48', self::W48_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w48Out = null;
            self::$w48OutCap = 0;
        }

        return self::$w48Ffi instanceof FFI ? self::$w48Ffi : null;
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
     * @param  resource  $handle
     */
    private function lineStart($handle, int $offset): int
    {
        if ($offset === 0) {
            return 0;
        }

        fseek($handle, $offset - 1);

        if (fread($handle, 1) === "\n") {
            return $offset;
        }

        foreach ($this->linesBackward($handle, $offset, self::HEADER_SEARCH_BYTES) as [$lineOffset]) {
            return $lineOffset;
        }

        return $offset;
    }

    /**
     * Walk back from a line start to the Monolog header that owns it.
     *
     * @param  resource  $handle
     */
    private function headerStart($handle, int $lineStart): int
    {
        fseek($handle, $lineStart);
        [$line] = $this->readLine($handle, $this->maxEntryBytes);

        if ($line !== null && $this->parser->isHeader(rtrim($line, "\r"))) {
            return $lineStart;
        }

        $lines = $this->linesBackward($handle, $lineStart, self::HEADER_SEARCH_BYTES);

        foreach ($lines as [$offset, $candidate]) {
            if ($this->parser->isHeader(rtrim($candidate, "\r"))) {
                return $offset;
            }
        }

        // No header before the start of the file: the hit sits in an orphan block that begins at 0.
        return $lines->getReturn() === true ? $lineStart : 0;
    }

    /**
     * @param  resource  $handle
     */
    private function readEntryForward($handle, LogFile $file, int $start, int $size, int $maxBytes, ?int $lineNumber): LogEntry
    {
        fseek($handle, $start);
        [$first, $firstComplete] = $this->readLine($handle, $maxBytes);
        $first = rtrim((string) $first, "\r");
        $truncated = ! $firstComplete;
        $end = (int) ftell($handle);

        $bodyLines = [];
        $bodyBytes = 0;

        if ($file->structured) {
            $scanLimit = $start + ($maxBytes * self::TRAILING_SCAN_FACTOR);

            while (($position = (int) ftell($handle)) < $size) {
                if ($position >= $scanLimit) {
                    $truncated = true;
                    $end = $position;

                    break;
                }

                [$line, $complete] = $this->readLine($handle, $maxBytes);

                if ($line === null) {
                    break;
                }

                $line = rtrim($line, "\r");

                if ($this->parser->isHeader($line)) {
                    $end = $position;

                    break;
                }

                $end = (int) ftell($handle);

                if ($bodyBytes + strlen($line) + 1 > $maxBytes) {
                    $truncated = true;

                    continue;
                }

                $bodyLines[] = $line;
                $bodyBytes += strlen($line) + 1;
                $truncated = $truncated || ! $complete;
            }
        }

        return $this->parser->make($start, $end, $first, implode("\n", $bodyLines), $truncated, $file->structured, $lineNumber);
    }

    /**
     * Read one line (without its newline), keeping at most `$maxBytes` and discarding the rest.
     *
     * @param  resource  $handle
     * @return array{string|null, bool} the line (null at EOF) and whether it fit within the cap
     */
    private function readLine($handle, int $maxBytes): array
    {
        $line = fgets($handle, $maxBytes + 1);

        if ($line === false) {
            return [null, true];
        }

        if (str_ends_with($line, "\n") || feof($handle)) {
            return [rtrim($line, "\n"), true];
        }

        while (($rest = fgets($handle, 65_536)) !== false && ! str_ends_with($rest, "\n")) {
            // Skip the remainder of an oversized line.
        }

        return [$line, false];
    }

    /**
     * Like {@see readLine()}, but also reports whether the line was terminated by a newline.
     *
     * @param  resource  $handle
     * @return array{string|null, bool, bool} the line (null at EOF), whether it fit within the cap, and whether it ended with a newline
     */
    private function readForwardLine($handle, int $maxBytes): array
    {
        $line = fgets($handle, $maxBytes + 1);

        if ($line === false) {
            return [null, true, false];
        }

        if (str_ends_with($line, "\n")) {
            return [substr($line, 0, -1), true, true];
        }

        if (feof($handle)) {
            return [$line, true, false];
        }

        while (($rest = fgets($handle, 65_536)) !== false) {
            if (str_ends_with($rest, "\n")) {
                return [$line, false, true];
            }
        }

        return [$line, false, false];
    }

    /**
     * @param  array{offset: int, line: int, first: string, body: list<string>, bytes: int, truncated: bool, structured: bool}  $pending
     */
    private function finishPending(array $pending, int $end): LogEntry
    {
        return $this->parser->make($pending['offset'], $end, $pending['first'], implode("\n", $pending['body']), $pending['truncated'], $pending['structured'], $pending['line']);
    }

    /**
     * @return array{string, bool}
     */
    private function cap(string $line, int $maxBytes): array
    {
        return strlen($line) > $maxBytes ? [substr($line, 0, $maxBytes), true] : [$line, false];
    }

    /**
     * @param  SplDoublyLinkedList<string>  $pending
     */
    private function joinPending(SplDoublyLinkedList $pending): string
    {
        return $pending->count() === 0 ? '' : implode("\n", $this->pendingTopFirst($pending));
    }

    /**
     * @param  SplDoublyLinkedList<string>  $pending
     * @return list<string>
     */
    private function pendingTopFirst(SplDoublyLinkedList $pending): array
    {
        return array_reverse(iterator_to_array($pending, false));
    }

    /**
     * @param  resource  $handle
     */
    private function size($handle): int
    {
        $stat = fstat($handle);

        return $stat === false ? 0 : (int) $stat['size'];
    }
}
