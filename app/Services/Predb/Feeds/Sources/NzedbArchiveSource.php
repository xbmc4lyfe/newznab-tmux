<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Services\Predb\Feeds\Contracts\CompleteCatalogueSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Manual historical nZEDbPre_Dumps catalogue: one retained gzip export, locally paged.
 * The producer's legacy schema has twelve MySQL OUTFILE fields, double-tab separators,
 * single-quote enclosures, backslash escapes and CRLF record endings.
 * https://github.com/nZEDb/nZEDb/blob/0.x/misc/testing/PreDB/dump_predb.php
 * Record creation clocks and upstream provider labels do not establish scene PRE times.
 */
final class NzedbArchiveSource extends BoundedHttpFeedSource implements CompleteCatalogueSource
{
    private const NATIVE_CDEF = <<<'C'
        ssize_t nntmux_predb_nzedb_parse_csv(const char *csv, size_t csv_len, size_t max_records, char *out_buf, size_t out_cap);
        C;

    private const NATIVE_ERRORS = [
        '1' => 'NZEDB_ARCHIVE export exceeds its record limit.',
        '2' => 'NZEDB_ARCHIVE returned a truncated or non-twelve-field CSV record.',
        '3' => 'NZEDB_ARCHIVE returned an invalid MySQL OUTFILE field.',
        '4' => 'NZEDB_ARCHIVE returned a truncated field escape.',
        '5' => 'NZEDB_ARCHIVE returned an invalid or truncated field escape.',
        '6' => 'NZEDB_ARCHIVE returned an unterminated CSV field.',
    ];

    private const NATIVE_REJECTIONS = [
        '1' => 'empty_title',
        '2' => 'outer_title_whitespace',
        '3' => 'overlength_title',
        '4' => 'control_title',
    ];

    private static FFI|false|null $native = null;

    /** @var list<PredbFeedEntry>|null */
    private ?array $entries = null;

    private int $acceptedCount = 0;

    /** @var array<string, int> */
    private array $rejections = [];

    public function __construct(
        string $endpoint,
        int $pageSize = 100,
        int $timeout = 15,
        string $userAgent = 'NNTmux-PreDB-Importer/1.0',
        int $maximumBodyBytes = 1048576,
        private readonly int $maximumCsvBytes = 8388608,
        private readonly int $maximumRecords = 5000,
        private readonly bool $useNative = true,
    ) {
        if ($maximumBodyBytes < 1 || $maximumBodyBytes > 8388608
            || $maximumCsvBytes < 1 || $maximumCsvBytes > 8388608
            || $maximumRecords < 1 || $maximumRecords > 5000) {
            throw new RuntimeException('NZEDB_ARCHIVE limits must be positive and within the complete-export bounds.');
        }
        parent::__construct($endpoint, $pageSize, $timeout, $userAgent, $maximumBodyBytes);
    }

    public function key(): string
    {
        return 'nzedb_archive';
    }

    /** @return list<PredbFeedEntry> */
    public function fetch(int $page = 1): array
    {
        if ($page < 1) {
            throw new RuntimeException('NZEDB_ARCHIVE local pages start at one.');
        }
        $this->entries ??= $this->parseGzip($this->downloadBody());
        if ($page > (int) ceil(count($this->entries) / $this->pageSize)) {
            return [];
        }

        return array_slice($this->entries, ($page - 1) * $this->pageSize, $this->pageSize);
    }

    public function acceptedCount(): int
    {
        return $this->acceptedCount;
    }

    /** @return array<string, int> */
    public function rejectionCounts(): array
    {
        return $this->rejections;
    }

    /** @return list<PredbFeedEntry> */
    public function parseGzip(string $gzip): array
    {
        $this->resetCatalogue();
        if (strlen($gzip) > $this->maximumBodyBytes) {
            throw $this->bodyLimitException();
        }
        if (! str_starts_with($gzip, "\x1f\x8b")) {
            throw new RuntimeException('NZEDB_ARCHIVE returned an invalid gzip export.');
        }
        $inflater = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) {
            throw new RuntimeException('NZEDB_ARCHIVE gzip decoder could not be initialized.');
        }
        $csv = '';
        $length = strlen($gzip);
        for ($offset = 0; $offset < $length; $offset += 1024) {
            $decoded = @inflate_add($inflater, substr($gzip, $offset, 1024), ZLIB_SYNC_FLUSH);
            if ($decoded === false) {
                throw new RuntimeException('NZEDB_ARCHIVE gzip export failed integrity validation.');
            }
            $csv .= $decoded;
            if (strlen($csv) > $this->maximumCsvBytes) {
                throw new RuntimeException('NZEDB_ARCHIVE decoded CSV exceeds its byte limit.');
            }
            if (inflate_get_status($inflater) === ZLIB_STREAM_END) {
                if (inflate_get_read_len($inflater) !== $length) {
                    throw new RuntimeException('NZEDB_ARCHIVE requires one gzip member without trailing bytes.');
                }
                break;
            }
        }
        if (inflate_get_status($inflater) !== ZLIB_STREAM_END) {
            throw new RuntimeException('NZEDB_ARCHIVE gzip export is truncated.');
        }

        return $this->parseCsv($csv);
    }

    /** @return list<PredbFeedEntry> */
    public function parseCsv(string $csv): array
    {
        $this->resetCatalogue();
        if ($csv === '' || strlen($csv) > $this->maximumCsvBytes || str_contains($csv, "\0")) {
            throw new RuntimeException('NZEDB_ARCHIVE returned an invalid or oversized CSV export.');
        }
        $native = $this->useNative ? $this->nativeRecords($csv) : null;
        if ($native !== null) {
            return $this->entriesFromNative($native);
        }
        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw new RuntimeException('NZEDB_ARCHIVE returned an invalid or oversized CSV export.');
        }
        $offset = 0;
        $records = 0;
        $entries = [];
        $identities = [];
        $rejections = [];
        while ($offset < strlen($csv)) {
            if (++$records > $this->maximumRecords) {
                throw new RuntimeException('NZEDB_ARCHIVE export exceeds its record limit.');
            }
            $fields = [];
            for ($column = 0; $column < 12; $column++) {
                $fields[] = $this->readField($csv, $offset);
                $separator = $column === 11 ? "\r\n" : "\t\t";
                if (substr($csv, $offset, 2) !== $separator) {
                    throw new RuntimeException('NZEDB_ARCHIVE returned a truncated or non-twelve-field CSV record.');
                }
                $offset += 2;
            }
            $title = $fields[0];
            $reason = $this->titleRejection($title);
            if ($reason !== null) {
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;

                continue;
            }
            $identity = PredbSearchDocument::exactValue($title);
            if (isset($identities[$identity])) {
                $rejections['duplicate_title'] = ($rejections['duplicate_title'] ?? 0) + 1;

                continue;
            }
            $identities[$identity] = true;
            $category = $fields[7];
            if ($category === '' || ($category !== null
                && (mb_strlen($category, 'UTF-8') > 255 || preg_match('/\p{Cc}/u', $category) !== 0))) {
                $category = null;
            }
            $entries[] = new PredbFeedEntry(title: $title, source: 'nzedb-pre-dumps', category: $category);
        }

        return $this->finishCatalogue($entries, $rejections, $records);
    }

    /**
     * @param  list<PredbFeedEntry>  $entries
     * @param  array<string, int>  $rejections
     * @return list<PredbFeedEntry>
     */
    private function finishCatalogue(array $entries, array $rejections, int $records): array
    {
        $this->acceptedCount = count($entries);
        $this->rejections = $rejections;
        if ($rejections !== []) {
            Log::warning('NZEDB_ARCHIVE omitted invalid or duplicate literal titles.', [
                'total_records' => $records,
                'accepted_records' => $this->acceptedCount,
                'rejected_records' => array_sum($rejections),
                'reasons' => $rejections,
            ]);
        }

        return $entries;
    }

    /**
     * Rust record reader output, or null when PHP must parse the export (library missing, or the
     * export is not valid UTF-8 so PHP raises its own error).
     */
    private function nativeRecords(string $csv): ?string
    {
        $ffi = self::$native ??= RustPipelineLibrary::resolveDomain('predb_feeds', self::NATIVE_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $length = strlen($csv);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 64, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_predb_nzedb_parse_csv($csv, $length, $this->maximumRecords, $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_predb_nzedb_parse_csv($csv, $length, $this->maximumRecords, $scratch, $capacity);
        }

        return is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
    }

    /** @return list<PredbFeedEntry> */
    private function entriesFromNative(string $output): array
    {
        if ($output[0] === 'E') {
            throw new RuntimeException(self::NATIVE_ERRORS[$output[1] ?? ''] ?? 'NZEDB_ARCHIVE returned an invalid or oversized CSV export.');
        }
        $lines = explode("\n", $output);
        $records = (int) substr($lines[0], 1);
        $entries = [];
        $identities = [];
        $rejections = [];
        for ($index = 1; $index <= $records; $index++) {
            $line = $lines[$index];
            if ($line[0] === 'R') {
                $reason = self::NATIVE_REJECTIONS[$line[1]];
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;

                continue;
            }
            $separator = strpos($line, "\x1f", 1);
            $title = substr($line, 1, $separator - 1);
            $identity = PredbSearchDocument::exactValue($title);
            if (isset($identities[$identity])) {
                $rejections['duplicate_title'] = ($rejections['duplicate_title'] ?? 0) + 1;

                continue;
            }
            $identities[$identity] = true;
            $category = substr($line, $separator + 1);
            $entries[] = new PredbFeedEntry(title: $title, source: 'nzedb-pre-dumps', category: $category === '' ? null : $category);
        }

        return $this->finishCatalogue($entries, $rejections, $records);
    }

    private function readField(string $csv, int &$offset): ?string
    {
        if (substr($csv, $offset, 2) === '\\N') {
            $offset += 2;

            return null;
        }
        if (($csv[$offset] ?? '') !== "'") {
            throw new RuntimeException('NZEDB_ARCHIVE returned an invalid MySQL OUTFILE field.');
        }
        $offset++;
        $value = '';
        $escapes = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", "'" => "'", '"' => '"', '\\' => '\\'];
        while ($offset < strlen($csv)) {
            $character = $csv[$offset++];
            if ($character === "'") {
                return $value;
            }
            if ($character === '\\') {
                if ($offset >= strlen($csv)) {
                    throw new RuntimeException('NZEDB_ARCHIVE returned a truncated field escape.');
                }
                $escaped = $csv[$offset++];
                if (! array_key_exists($escaped, $escapes)) {
                    throw new RuntimeException('NZEDB_ARCHIVE returned an invalid or truncated field escape.');
                }
                $character = $escapes[$escaped];
            }
            $value .= $character;
        }

        throw new RuntimeException('NZEDB_ARCHIVE returned an unterminated CSV field.');
    }

    private function titleRejection(?string $title): ?string
    {
        if ($title === null || trim($title) === '') {
            return 'empty_title';
        }
        if ($title !== trim($title)) {
            return 'outer_title_whitespace';
        }
        if (mb_strlen($title, 'UTF-8') > 255) {
            return 'overlength_title';
        }
        if (preg_match('/\p{Cc}/u', $title) !== 0) {
            return 'control_title';
        }

        return null;
    }

    private function resetCatalogue(): void
    {
        $this->entries = null;
        $this->acceptedCount = 0;
        $this->rejections = [];
    }
}
