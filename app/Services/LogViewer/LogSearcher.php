<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Searches log files for matching lines and returns the newest matching entries per file.
 *
 * Matching lines come from GNU grep when it is available (fast, runs outside PHP) and from a
 * streaming PHP scanner otherwise. Each hit is a byte offset that is then resolved to its full
 * entry, so a match inside a stack trace is reported as the entry that owns it.
 *
 * Grep output parsing and plain / level-only scanning run in Rust (`nntmux_log_*` in
 * `rust/pipeline-ffi/src/media.rs`) when the pipeline library is available, keeping only the hits the
 * result window needs in a native ring; the PHP implementations remain the fallback and the parity reference.
 */
class LogSearcher
{
    private const int HIT_BUFFER_FACTOR = 4;

    private const int PHP_DEADLINE_CHECK_INTERVAL = 4096;

    private const int PHP_BACKTRACK_LIMIT = 100_000;

    private const int NATIVE_CHUNK_BYTES = 1_048_576;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_log_parse_grep_lines(const char *input, size_t input_len, int64_t before, size_t keep, int64_t *window);
        ssize_t nntmux_log_scan_chunk_hits(const char *chunk, size_t chunk_len, size_t start, uint32_t is_final, uint32_t mode, const char *term, size_t term_len, uint32_t level_mask, int64_t base_offset, int64_t line_number, int64_t before, size_t keep, int64_t *window, int64_t *state_out);
        C;

    private const int SCAN_CASE_SENSITIVE = 0;

    private const int SCAN_CASE_INSENSITIVE = 1;

    private const int SCAN_LEVEL_HEADER = 2;

    private static bool $useRustFfi = true;

    private static FFI|false|null $cachedFfi = null;

    private static ?CData $nativeState = null;

    /**
     * @var array{binary: string, gnu: bool, pcre: bool}|null
     */
    private ?array $grep = null;

    public function __construct(private readonly LogReader $reader) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /**
     * @return 'grep'|'php'
     */
    public function engine(bool $needsPcre = false): string
    {
        $grep = $this->grep();

        return $grep['gnu'] && (! $needsPcre || $grep['pcre']) ? 'grep' : 'php';
    }

    /**
     * @param  list<LogFile>  $files
     * @return array{
     *     engine: string,
     *     elapsed_ms: int,
     *     results: list<array{
     *         file: string,
     *         total_matches: int,
     *         has_more: bool,
     *         before: int|null,
     *         timed_out: bool,
     *         error: string|null,
     *         entries: list<array<string, mixed>>
     *     }>
     * }
     */
    public function search(SearchQuery $query, array $files, ?int $before, int $limitPerFile): array
    {
        $startedAt = hrtime(true);
        $deadline = microtime(true) + max(1, (int) config('nntmux.log_viewer.search_timeout', 20));
        $engine = $this->engine($query->needsPcreGrep());
        $results = [];

        foreach ($files as $file) {
            $results[] = $this->searchFile($query, $file, $before, $limitPerFile, $deadline, $engine);
        }

        return [
            'engine' => $engine,
            'elapsed_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'results' => $results,
        ];
    }

    /**
     * @return array{file: string, total_matches: int, has_more: bool, before: int|null, timed_out: bool, error: string|null, entries: list<array<string, mixed>>}
     */
    private function searchFile(SearchQuery $query, LogFile $file, ?int $before, int $limit, float $deadline, string $engine): array
    {
        $bufferSize = max(1, $limit * self::HIT_BUFFER_FACTOR);
        [$collect, $window, $result] = $this->hitWindow($before, $bufferSize);

        if (microtime(true) >= $deadline) {
            $status = ['timed_out' => true, 'error' => null];
        } else {
            $status = $engine === 'grep'
                ? $this->grepHits($query, $file, $deadline, $collect, $window)
                : $this->phpHits($query, $file, $deadline, $collect, $window);
        }

        [$totalMatches, $eligible, $hits] = $result();
        [$entries, $examined, $cursor] = $this->resolveEntries($query, $file, $hits, $limit, $status['error'] === null);

        $hasMore = $eligible > $examined;

        return [
            'file' => $file->path,
            'total_matches' => $totalMatches,
            'has_more' => $hasMore,
            'before' => $hasMore ? $cursor : null,
            'timed_out' => $status['timed_out'],
            'error' => $status['error'],
            'entries' => $entries,
        ];
    }

    /**
     * Collectors for one file's hits: total matches, matches before the cursor, and the newest $bufferSize of those.
     *
     * Rust scanners apply the same cursor and window in a native ring (header [oldest slot, kept hits, total,
     * eligible] then [offset, line] pairs); `flush` moves it into the PHP window and must run before PHP collects.
     *
     * @return array{
     *     callable(int, int): void,
     *     array{before: int|null, keep: int, ring: CData|null, flush: callable(): void},
     *     callable(): array{int, int, list<array{int, int}>}
     * } per-hit collector, window, result (total, eligible, hits as [offset, line number], oldest first)
     */
    private function hitWindow(?int $before, int $bufferSize): array
    {
        /** @var list<array{int, int}> $hits */
        $hits = [];
        $totalMatches = 0;
        $eligible = 0;

        $collect = function (int $offset, int $lineNumber) use (&$hits, &$totalMatches, &$eligible, $before, $bufferSize): void {
            $totalMatches++;

            if ($before !== null && $offset >= $before) {
                return;
            }

            $eligible++;
            $hits[] = [$offset, $lineNumber];

            if (count($hits) >= $bufferSize * 2) {
                $hits = array_slice($hits, -$bufferSize);
            }
        };

        $ring = self::nativeWindow($bufferSize);
        $flush = function () use ($ring, &$hits, &$totalMatches, &$eligible, $bufferSize): void {
            if ($ring === null || $ring[2] === 0) {
                return;
            }

            $totalMatches += $ring[2];
            $eligible += $ring[3];

            for ($index = 0, $kept = $ring[1]; $index < $kept; $index++) {
                $slot = 4 + 2 * (($ring[0] + $index) % $bufferSize);
                $hits[] = [$ring[$slot], $ring[$slot + 1]];
            }

            $ring[0] = $ring[1] = $ring[2] = $ring[3] = 0;

            if (count($hits) >= $bufferSize * 2) {
                $hits = array_slice($hits, -$bufferSize);
            }
        };

        $result = function () use ($flush, &$hits, &$totalMatches, &$eligible, $bufferSize): array {
            $flush();

            return [$totalMatches, $eligible, array_slice($hits, -$bufferSize)];
        };

        return [$collect, ['before' => $before, 'keep' => $bufferSize, 'ring' => $ring, 'flush' => $flush], $result];
    }

    /**
     * Turn hits (oldest first) into newest-first entries, skipping repeat hits inside the same entry.
     *
     * @param  list<array{int, int}>  $hits
     * @return array{list<array<string, mixed>>, int, int|null} entries, hits examined, cursor for older results
     */
    private function resolveEntries(SearchQuery $query, LogFile $file, array $hits, int $limit, bool $canRead): array
    {
        if ($hits === [] || ! $canRead) {
            return [[], 0, null];
        }

        $entries = [];
        $examined = 0;
        $cursor = null;
        $previous = null;
        $handle = $this->reader->open($file);

        try {
            for ($index = count($hits) - 1; $index >= 0; $index--) {
                [$offset, $lineNumber] = $hits[$index];

                if ($previous !== null && $offset >= $previous->offset && $offset < $previous->end) {
                    $examined++;

                    continue;
                }

                if (count($entries) >= $limit) {
                    break;
                }

                $examined++;
                $entry = $this->reader->entryFromHandle($handle, $file, $offset, null, $lineNumber);

                if ($entry === null) {
                    continue;
                }

                $previous = $entry;
                $cursor = $entry->offset;

                if (! $query->acceptsEntry($entry)) {
                    continue;
                }

                $entries[] = $entry->toArray($query);
            }
        } finally {
            fclose($handle);
        }

        return [$entries, $examined, $cursor];
    }

    /**
     * @param  callable(int, int): void  $collect
     * @param  array{before: int|null, keep: int, ring: CData|null, flush: callable(): void}  $window
     * @return array{timed_out: bool, error: string|null}
     */
    private function grepHits(SearchQuery $query, LogFile $file, float $deadline, callable $collect, array $window): array
    {
        $command = [$this->grep()['binary'], '-a', '-b', '-n', ...$query->grepArguments(), '--', $file->absolutePath];
        $process = new Process($command, null, ['LC_ALL' => 'C'], null, max(1.0, $deadline - microtime(true)));
        $buffer = '';

        try {
            $process->start();

            foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                $buffer .= $chunk;
                $lastNewline = strrpos($buffer, "\n");

                if ($lastNewline === false) {
                    continue;
                }

                $this->collectGrepLines(substr($buffer, 0, $lastNewline), $collect, $window);
                $buffer = substr($buffer, $lastNewline + 1);
            }

            $this->collectGrepLines($buffer, $collect, $window);
        } catch (ProcessTimedOutException) {
            $process->stop(0);

            return ['timed_out' => true, 'error' => null];
        } catch (Throwable $exception) {
            return ['timed_out' => false, 'error' => 'Search failed: '.$exception->getMessage()];
        }

        if ($process->getExitCode() === 2) {
            $message = trim($process->getErrorOutput());

            return ['timed_out' => false, 'error' => $message !== '' ? $message : 'grep failed to search this file.'];
        }

        return ['timed_out' => false, 'error' => null];
    }

    /**
     * @param  callable(int, int): void  $collect
     * @param  array{before: int|null, keep: int, ring: CData|null, flush: callable(): void}  $window
     */
    private function collectGrepLines(string $output, callable $collect, array $window): void
    {
        if ($output === '') {
            return;
        }

        $ffi = $window['ring'] !== null ? self::ffi() : null;

        // A negative result (a field PHP's (int) cast must decide) leaves the window untouched.
        // @phpstan-ignore method.notFound
        if ($ffi !== null && $ffi->nntmux_log_parse_grep_lines($output, strlen($output), $window['before'] ?? PHP_INT_MAX, $window['keep'], $window['ring']) >= 0) {
            return;
        }

        ($window['flush'])();
        $this->parseGrepLines($output, $collect);
    }

    /**
     * @param  callable(int, int): void  $collect
     */
    private function parseGrepLines(string $output, callable $collect): void
    {
        if ($output === '') {
            return;
        }

        foreach (explode("\n", $output) as $line) {
            // grep -n -b output: LINE:OFFSET:content (offset is the start of the matching line)
            $first = strpos($line, ':');
            $second = $first === false ? false : strpos($line, ':', $first + 1);

            if ($second === false) {
                continue;
            }

            $collect((int) substr($line, $first + 1, $second - $first - 1), (int) substr($line, 0, $first));
        }
    }

    /**
     * @param  callable(int, int): void  $collect
     * @param  array{before: int|null, keep: int, ring: CData|null, flush: callable(): void}  $window
     * @return array{timed_out: bool, error: string|null}
     */
    private function phpHits(SearchQuery $query, LogFile $file, float $deadline, callable $collect, array $window): array
    {
        $handle = @fopen($file->absolutePath, 'rb');

        if ($handle === false) {
            return ['timed_out' => false, 'error' => 'Log file could not be opened.'];
        }

        $previousBacktrackLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::PHP_BACKTRACK_LIMIT);
        $lineNumber = 0;

        try {
            $ffi = $window['ring'] !== null ? self::ffi() : null;
            $scan = $ffi !== null ? self::nativeScan($query) : null;

            if ($ffi !== null && $scan !== null) {
                $status = $this->nativeHits($ffi, $scan, $handle, $query, $deadline, $collect, $window, $lineNumber);

                if ($status !== null) {
                    return $status;
                }

                ($window['flush'])();
            }

            return $this->phpLineHits($handle, $query, $deadline, $collect, $lineNumber);
        } finally {
            fclose($handle);

            if ($previousBacktrackLimit !== false) {
                ini_set('pcre.backtrack_limit', $previousBacktrackLimit);
            }
        }
    }

    /**
     * Line-by-line scan from the handle's position; $lineNumber counts the lines before it.
     *
     * @param  resource  $handle
     * @param  callable(int, int): void  $collect
     * @return array{timed_out: bool, error: string|null}
     */
    private function phpLineHits($handle, SearchQuery $query, float $deadline, callable $collect, int $lineNumber): array
    {
        $usesPcre = $query->regex || ! $query->hasTerm();

        while (($offset = ftell($handle)) !== false && ($line = fgets($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber % self::PHP_DEADLINE_CHECK_INTERVAL === 0 && microtime(true) >= $deadline) {
                return ['timed_out' => true, 'error' => null];
            }

            if ($query->matches(rtrim($line, "\r\n"))) {
                $collect($offset, $lineNumber);
            }

            if ($usesPcre && preg_last_error() !== PREG_NO_ERROR) {
                return ['timed_out' => false, 'error' => 'Regular expression is too expensive: '.preg_last_error_msg()];
            }
        }

        return ['timed_out' => false, 'error' => null];
    }

    /**
     * Scans 1 MiB chunks in Rust. Returns null, with the handle and $lineNumber positioned at the first unscanned
     * line, when the PHP scanner must take over.
     *
     * @param  array{int, string, int}  $scan  mode, term, level mask
     * @param  resource  $handle
     * @param  callable(int, int): void  $collect
     * @param  array{before: int|null, keep: int, ring: CData|null, flush: callable(): void}  $window
     *
     * @param-out int  $lineNumber
     *
     * @return array{timed_out: bool, error: string|null}|null
     */
    private function nativeHits(FFI $ffi, array $scan, $handle, SearchQuery $query, float $deadline, callable $collect, array $window, int &$lineNumber): ?array
    {
        [$mode, $term, $levelMask] = $scan;
        $state = self::nativeState($ffi);

        if ($state === null) {
            return null;
        }

        $offset = 0;
        $readBytes = self::NATIVE_CHUNK_BYTES;

        while (true) {
            if (microtime(true) >= $deadline) {
                return ['timed_out' => true, 'error' => null];
            }

            if (fseek($handle, $offset) !== 0) {
                return ['timed_out' => false, 'error' => 'Log file could not be read.'];
            }

            $chunk = fread($handle, $readBytes);

            if ($chunk === false || $chunk === '') {
                return ['timed_out' => false, 'error' => null];
            }

            $length = strlen($chunk);
            $final = $length < $readBytes;
            $start = 0;

            while (true) {
                // @phpstan-ignore method.notFound
                $found = $ffi->nntmux_log_scan_chunk_hits(
                    $chunk, $length, $start, $final ? 1 : 0, $mode, $term, strlen($term), $levelMask,
                    $offset, $lineNumber, $window['before'] ?? PHP_INT_MAX, $window['keep'], $window['ring'], $state,
                );

                if ($found < 0) {
                    fseek($handle, $offset + $start);

                    return null;
                }

                $stop = (int) $state[0];
                $lineNumber = (int) $state[1];

                if ((int) $state[2] !== 1) {
                    break;
                }

                // A line Rust defers (e.g. one that could exhaust the PCRE backtrack limit) is matched in PHP.
                $newline = strpos($chunk, "\n", $stop);
                $line = $newline === false ? substr($chunk, $stop) : substr($chunk, $stop, $newline - $stop);
                $lineNumber++;

                if ($query->matches(rtrim($line, "\r\n"))) {
                    ($window['flush'])();
                    $collect($offset + $stop, $lineNumber);
                }

                if (preg_last_error() !== PREG_NO_ERROR) {
                    return ['timed_out' => false, 'error' => 'Regular expression is too expensive: '.preg_last_error_msg()];
                }

                $start = $newline === false ? $length : $newline + 1;
            }

            if ($final) {
                return ['timed_out' => false, 'error' => null];
            }

            if ($stop === 0) {
                // A single line longer than the chunk: read more of it.
                $readBytes *= 2;

                continue;
            }

            $offset += $stop;
            $readBytes = self::NATIVE_CHUNK_BYTES;
        }
    }

    /**
     * The Rust scan mode for plain-term and level-only searches; regular expressions stay in PHP.
     *
     * @return array{int, string, int}|null mode, term, level mask
     */
    private static function nativeScan(SearchQuery $query): ?array
    {
        if ($query->regex) {
            return null;
        }

        if ($query->hasTerm()) {
            if (strpbrk($query->term, "\r\n") !== false) {
                return null;
            }

            return [$query->caseSensitive ? self::SCAN_CASE_SENSITIVE : self::SCAN_CASE_INSENSITIVE, $query->term, 0];
        }

        // The level pattern's \s and \w are byte classes only under PCRE's default (C) character tables.
        if (! in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true)) {
            return null;
        }

        $mask = 0;

        foreach ($query->levels !== [] ? $query->levels : LogEntryParser::LEVELS as $level) {
            $index = array_search($level, LogEntryParser::LEVELS, true);

            if ($index === false) {
                return null;
            }

            $mask |= 1 << $index;
        }

        return [self::SCAN_LEVEL_HEADER, '', $mask];
    }

    /**
     * A zeroed native hit window for $keep hits, or null when the Rust library is unavailable.
     */
    private static function nativeWindow(int $keep): ?CData
    {
        try {
            return self::ffi()?->new('int64_t['.(4 + 2 * $keep).']');
        } catch (Throwable) {
            return null;
        }
    }

    private static function nativeState(FFI $ffi): ?CData
    {
        try {
            return self::$nativeState ??= $ffi->new('int64_t[3]');
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }

        self::$cachedFfi ??= RustPipelineLibrary::resolveDomain('log_searcher', self::FFI_CDEF) ?? false;

        return self::$cachedFfi === false ? null : self::$cachedFfi;
    }

    /**
     * @return array{binary: string, gnu: bool, pcre: bool}
     */
    private function grep(): array
    {
        if ($this->grep !== null) {
            return $this->grep;
        }

        $configured = trim((string) config('nntmux.log_viewer.grep_binary', 'grep'));
        $unavailable = ['binary' => '', 'gnu' => false, 'pcre' => false];

        if ($configured === '' || ! function_exists('proc_open')) {
            return $this->grep = $unavailable;
        }

        $binary = str_contains($configured, DIRECTORY_SEPARATOR)
            ? (is_executable($configured) ? $configured : null)
            : (new ExecutableFinder)->find($configured);

        if ($binary === null) {
            return $this->grep = $unavailable;
        }

        /** @var array{binary: string, gnu: bool, pcre: bool} $probe */
        $probe = Cache::remember('log_viewer:grep:'.md5($binary), now()->addDay(), static function () use ($binary): array {
            try {
                $version = new Process([$binary, '--version'], null, ['LC_ALL' => 'C'], null, 5);
                $version->run();
                $isGnu = $version->isSuccessful() && str_contains($version->getOutput(), 'GNU grep');

                // Exit code 1 means "no match", i.e. -P is compiled in; 2 means it is not supported.
                $pcre = new Process([$binary, '-P', '-q', '-e', 'x', '--', '/dev/null'], null, ['LC_ALL' => 'C'], null, 5);
                $pcre->run();

                return ['binary' => $binary, 'gnu' => $isGnu, 'pcre' => $isGnu && $pcre->getExitCode() === 1];
            } catch (Throwable) {
                return ['binary' => $binary, 'gnu' => false, 'pcre' => false];
            }
        });

        return $this->grep = $probe;
    }
}
