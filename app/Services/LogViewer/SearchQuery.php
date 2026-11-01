<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use Throwable;

/**
 * A log search: an optional term (plain or PCRE), case sensitivity, and level / channel / time filters.
 *
 * Matching is byte-wise (no `/u`), mirroring `grep` under `LC_ALL=C`, so both search engines
 * agree on what matches and case folding is ASCII-only.
 *
 * Highlighting stays in PHP: {@see plainRangesNative()} (Rust `nntmux_log_plain_ranges`) is kept for parity
 * tests only, because per-entry FFI calls did not reach the 1.5x speedup gate over `strpos` / `stripos`.
 */
final readonly class SearchQuery
{
    /**
     * Rust stays opt-in for the Wave 44.5 summary: uncommon p99 missed 1.5x because the framing copy of
     * large batches competes with memchr-backed strpos; see
     * tests/Unit/RustLogViewerIndexParserSearchPrometheusDocW44ParityTest.php.
     */
    public const bool W445_RUST_FFI_DEFAULT = false;

    private const string DELIMITER = "\x01";

    /**
     * Matches a Monolog header for the selected levels; used for level-only searches.
     */
    private const string LEVEL_HEADER_PCRE = '^\[[^\]]*\]\s+[\w.\-]+\.(?:%s):';

    private const string LEVEL_HEADER_ERE = '^\[[^]]*\][[:space:]]+[[:alnum:]_.-]+\.(%s):';

    private const int MAX_HIGHLIGHTS = 200;

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_log_plain_ranges(const char *text, size_t text_len, const char *term, size_t term_len, uint32_t case_sensitive, size_t max_ranges, char *out_buf, size_t out_cap);
        C;

    /**
     * @param  list<string>  $levels  lowercase Monolog level names
     * @param  list<string>  $channels  Monolog channel names
     */
    public function __construct(
        public string $term,
        public bool $regex = false,
        public bool $caseSensitive = false,
        public array $levels = [],
        public array $channels = [],
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}

    public static function isValidRegex(string $term): bool
    {
        if (str_contains($term, self::DELIMITER)) {
            return false;
        }

        return @preg_match(self::DELIMITER.$term.self::DELIMITER, '') !== false;
    }

    public function hasTerm(): bool
    {
        return $this->term !== '';
    }

    /**
     * The PCRE that selects candidate lines: the term, or the level header pattern for level-only searches.
     */
    public function pcre(): string
    {
        if (! $this->hasTerm()) {
            return self::DELIMITER.sprintf(self::LEVEL_HEADER_PCRE, $this->levelAlternation()).self::DELIMITER;
        }

        $pattern = $this->regex ? $this->term : preg_quote($this->term, self::DELIMITER);

        return self::DELIMITER.$pattern.self::DELIMITER.($this->caseSensitive ? '' : 'i');
    }

    /**
     * Arguments selecting the grep matcher and pattern (everything between the binary's fixed flags and `--`).
     *
     * @return list<string>
     */
    public function grepArguments(): array
    {
        if (! $this->hasTerm()) {
            return ['-E', '-e', sprintf(self::LEVEL_HEADER_ERE, $this->levelAlternation())];
        }

        $arguments = $this->caseSensitive ? [] : ['-i'];
        $arguments[] = $this->regex ? '-P' : '-F';
        $arguments[] = '-e';
        $arguments[] = $this->term;

        return $arguments;
    }

    public function hasTimeRange(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /**
     * Whether an entry passes the level, channel and time filters (the term is matched separately).
     * Entries without a timestamp never match a time range.
     */
    public function acceptsEntry(LogEntry $entry): bool
    {
        if ($this->levels !== [] && ! in_array($entry->level, $this->levels, true)) {
            return false;
        }

        if ($this->channels !== [] && ! in_array($entry->channel, $this->channels, true)) {
            return false;
        }

        if (! $this->hasTimeRange()) {
            return true;
        }

        $loggedAt = $entry->loggedAt();

        return $loggedAt !== null
            && ($this->from === null || $loggedAt >= $this->from->getTimestamp())
            && ($this->to === null || $loggedAt <= $this->to->getTimestamp());
    }

    public function needsPcreGrep(): bool
    {
        return $this->hasTerm() && $this->regex;
    }

    public function matches(string $line): bool
    {
        if ($this->hasTerm() && ! $this->regex) {
            return $this->caseSensitive
                ? str_contains($line, $this->term)
                : stripos($line, $this->term) !== false;
        }

        return preg_match($this->pcre(), $line) === 1;
    }

    /**
     * Split text into highlighted / plain runs for rendering without HTML injection.
     *
     * @return list<array{text: string, hit: bool}>
     */
    public function segments(string $text): array
    {
        if (! $this->hasTerm() || $text === '') {
            return [['text' => $text, 'hit' => false]];
        }

        $ranges = $this->regex ? $this->regexRanges($text) : $this->plainRanges($text);
        $segments = [];
        $cursor = 0;

        foreach ($ranges as [$start, $length]) {
            if ($start > $cursor) {
                $segments[] = ['text' => substr($text, $cursor, $start - $cursor), 'hit' => false];
            }

            $segments[] = ['text' => substr($text, $start, $length), 'hit' => true];
            $cursor = $start + $length;
        }

        if ($cursor < strlen($text)) {
            $segments[] = ['text' => substr($text, $cursor), 'hit' => false];
        }

        return $segments;
    }

    /**
     * @return list<array{int, int}>
     */
    private function plainRanges(string $text): array
    {
        $ranges = [];
        $length = strlen($this->term);
        $offset = 0;

        while (count($ranges) < self::MAX_HIGHLIGHTS) {
            $position = $this->caseSensitive
                ? strpos($text, $this->term, $offset)
                : stripos($text, $this->term, $offset);

            if ($position === false) {
                break;
            }

            $ranges[] = [$position, $length];
            $offset = $position + $length;
        }

        return $ranges;
    }

    /**
     * Rust equivalent of {@see plainRanges()} for parity tests and benchmarks; null when the library is unavailable.
     *
     * @return list<array{int, int}>|null
     */
    public function plainRangesNative(string $text): ?array
    {
        static $ffi = null;
        $ffi ??= RustPipelineLibrary::resolveDomain('log_search_query', self::FFI_CDEF) ?? false;

        if (! $ffi instanceof FFI || ! $this->hasTerm()) {
            return null;
        }

        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, self::MAX_HIGHLIGHTS * 16, $capacity);

        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_log_plain_ranges($text, strlen($text), $this->term, strlen($this->term), $this->caseSensitive ? 1 : 0, self::MAX_HIGHLIGHTS, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }

        if ($written < 0) {
            return null;
        }

        if ($written === 0) {
            return [];
        }

        /** @var list<int> $flat */
        $flat = array_values((array) unpack('q*', FFI::string($scratch, $written)));

        /** @var list<array{int, int}> */
        return array_chunk($flat, 2);
    }

    /** Pass null to restore {@see W445_RUST_FFI_DEFAULT}. */
    public static function useW445RustFfi(?bool $enable = true): void
    {
        $state = &self::w445State();
        $state['enabled'] = $enable;
    }

    public static function usesW445RustFfi(): bool
    {
        return self::w445State()['enabled'] ?? self::W445_RUST_FFI_DEFAULT;
    }

    public static function resetW445RustFfi(): void
    {
        self::useW445RustFfi(null);
    }

    /**
     * Summarizes how this query matches and highlights lines: `matched` lines ({@see matches()}),
     * `highlights` and `highlighted_bytes` from {@see segments()}' ranges, `max_highlights` per line and
     * `capped_lines` that reached the highlight limit. Non-strings are invalid. Rust serves plain terms;
     * regex and level-only queries run in PHP.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array{lines: int, invalid: int, matched: int, highlights: int, highlighted_bytes: int, max_highlights: int, capped_lines: int}
     */
    public function summarizeLineMatchBatch(array $lines): array
    {
        return self::usesW445RustFfi() ? $this->summarizeLineMatchBatchNative($lines) : $this->summarizeLineMatchBatchPhp($lines);
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array{lines: int, invalid: int, matched: int, highlights: int, highlighted_bytes: int, max_highlights: int, capped_lines: int}
     */
    public function summarizeLineMatchBatchPhp(array $lines): array
    {
        $summary = ['lines' => \count($lines), 'invalid' => 0, 'matched' => 0, 'highlights' => 0, 'highlighted_bytes' => 0, 'max_highlights' => 0, 'capped_lines' => 0];
        foreach ($lines as $line) {
            if (! \is_string($line)) {
                $summary['invalid']++;

                continue;
            }
            $summary['matched'] += (int) $this->matches($line);
            $ranges = $this->hasTerm() ? ($this->regex ? $this->regexRanges($line) : $this->plainRanges($line)) : [];
            $found = \count($ranges);
            $summary['highlights'] += $found;
            foreach ($ranges as [, $length]) {
                $summary['highlighted_bytes'] += $length;
            }
            $summary['max_highlights'] = max($summary['max_highlights'], $found);
            $summary['capped_lines'] += (int) ($found >= self::MAX_HIGHLIGHTS);
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array{lines: int, invalid: int, matched: int, highlights: int, highlighted_bytes: int, max_highlights: int, capped_lines: int}
     */
    public function summarizeLineMatchBatchNative(array $lines): array
    {
        if (! $this->hasTerm() || $this->regex) {
            return $this->summarizeLineMatchBatchPhp($lines);
        }
        $strings = array_values(array_filter($lines, 'is_string'));
        $fields = ReleaseSearchIndexDocument::evaluateW445Native(3, [$this->term, $this->caseSensitive ? '1' : '0', (string) self::MAX_HIGHLIGHTS, ...$strings], 5);
        if ($fields === null) {
            return $this->summarizeLineMatchBatchPhp($lines);
        }

        return ['lines' => \count($lines), 'invalid' => \count($lines) - \count($strings)]
            + array_combine(['matched', 'highlights', 'highlighted_bytes', 'max_highlights', 'capped_lines'], $fields);
    }

    /**
     * Readonly classes cannot declare static properties, so the Wave 44.5 toggle lives here.
     *
     * @return array{enabled: ?bool}
     */
    private static function &w445State(): array
    {
        static $state = ['enabled' => null];

        return $state;
    }

    /**
     * @return list<array{int, int}>
     */
    private function regexRanges(string $text): array
    {
        if (@preg_match_all($this->pcre(), $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $ranges = [];
        $cursor = 0;

        foreach ($matches[0] as [$match, $start]) {
            $length = strlen($match);

            if ($length === 0 || $start < $cursor) {
                continue;
            }

            $ranges[] = [$start, $length];
            $cursor = $start + $length;

            if (count($ranges) >= self::MAX_HIGHLIGHTS) {
                break;
            }
        }

        return $ranges;
    }

    private function levelAlternation(): string
    {
        $levels = $this->levels !== [] ? $this->levels : LogEntryParser::LEVELS;

        return implode('|', array_map(strtoupper(...), $levels));
    }

    public const string W55_FFI_CDEF = 'int64_t nntmux_w555_search_query_level_filter_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W55.5 parity test's QUALIFICATION). */
    private const bool W55_RUST_FFI_DEFAULT = true;

    /** Mask bit per {@see LogEntryParser::LEVELS} entry, in order. */
    private const array W55_LEVEL_BITS = ['debug' => 1, 'info' => 2, 'notice' => 4, 'warning' => 8, 'error' => 16, 'critical' => 32, 'alert' => 64, 'emergency' => 128];

    /**
     * Wave 55.5 routing and FFI cache, kept in function-local statics (readonly classes cannot declare static properties). Without $op it reports the
     * routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the cached
     * handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w55_5', self::W55_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W55_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW55RustFfi(): bool
    {
        return self::w55RustFfiState() === true;
    }

    /**
     * Runs $callback with levelFilterPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 55.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w55Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w55RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w555_search_query_level_filter_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch parse of comma-separated level filters. Items are trimmed and lowercased; empty items are
     * skipped. Four words per filter: `[mask, recognized, unknown, duplicates]`, where bit n of the mask
     * marks {@see LogEntryParser::LEVELS}[n].
     *
     * @param  list<string>  $filters
     * @return list<int>
     */
    public static function levelFilterPlanBatch(array $filters): array
    {
        return self::usesW55RustFfi() ? self::levelFilterPlanBatchNative($filters) : self::levelFilterPlanBatchPhp($filters);
    }

    /**
     * @param  list<string>  $filters
     * @return list<int>
     */
    public static function levelFilterPlanBatchPhp(array $filters): array
    {
        $out = [];
        foreach ($filters as $filter) {
            $mask = $recognized = $unknown = $duplicates = 0;
            foreach (explode(',', $filter) as $item) {
                $name = strtolower(trim($item));
                if ($name === '') {
                    continue;
                }
                $bit = self::W55_LEVEL_BITS[$name] ?? 0;
                if ($bit === 0) {
                    $unknown++;
                } elseif (($mask & $bit) !== 0) {
                    $duplicates++;
                } else {
                    $mask |= $bit;
                    $recognized++;
                }
            }
            array_push($out, $mask, $recognized, $unknown, $duplicates);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined values; falls back to PHP without the library or when a value holds
     * a NUL.
     *
     * @param  list<string>  $filters
     * @return list<int>
     */
    public static function levelFilterPlanBatchNative(array $filters): array
    {
        $count = \count($filters);
        if ($count === 0) {
            return [];
        }

        return self::w55Words($count, implode("\0", $filters), 4 * $count) ?? self::levelFilterPlanBatchPhp($filters);
    }
}
