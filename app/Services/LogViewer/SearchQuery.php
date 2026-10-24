<?php

declare(strict_types=1);

namespace App\Services\LogViewer;

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
}
