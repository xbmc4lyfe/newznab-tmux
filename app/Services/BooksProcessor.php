<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class BooksProcessor
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_media_catalogue_processor_plan(
            uint32_t processor,
            uint32_t override_kind,
            int64_t override_int,
            const char *override_ptr,
            size_t override_len,
            uint32_t has_default,
            int64_t default_lookup,
            uint32_t echo_output,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustMediaCatalogueProcessorsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string FFI_SYMBOL = 'nntmux_w34_media_catalogue_processor_plan';

    private const int RUST_PROCESSOR = 1;

    private const string LABEL = 'Books';

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    private bool $echooutput;

    public function __construct(bool $echooutput)
    {
        $this->echooutput = $echooutput;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public function process(string $groupID = '', string $guidChar = ''): void
    {
        $plan = self::dispatchPlan((int) app(ConfigurationProvider::class)->metadata()->bookLookup->value, $this->echooutput);
        if ($plan['dispatch']) {
            (new BookService)->processBookReleases($groupID, $guidChar);
        }
    }

    /**
     * Resolves the book lookup gate, echo configuration and dispatch summary.
     *
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlan(int $lookupValue, bool $echoOutput): array
    {
        return self::usesRustFfi()
            ? self::dispatchPlanNative($lookupValue, $echoOutput)
            : self::dispatchPlanPhp($lookupValue, $echoOutput);
    }

    /**
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanNative(int $lookupValue, bool $echoOutput): array
    {
        $fields = self::callNative(self::FFI_SYMBOL, [self::RUST_PROCESSOR, 0, 0, null, 0, 1, $lookupValue, (int) $echoOutput]);

        return ($fields === null ? null : self::decodePlan($fields))
            ?? self::dispatchPlanPhp($lookupValue, $echoOutput);
    }

    /**
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}
     */
    public static function dispatchPlanPhp(int $lookupValue, bool $echoOutput): array
    {
        $dispatch = $lookupValue !== 0;

        return [
            'dispatch' => $dispatch,
            'mode' => $lookupValue,
            'echo' => $echoOutput,
            'source' => 'setting',
            'summary' => sprintf(
                '%s lookup: mode=%d source=setting dispatch=%s echo=%s',
                self::LABEL,
                $lookupValue,
                $dispatch ? 'yes' : 'no',
                $echoOutput ? 'on' : 'off',
            ),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array{dispatch: bool, mode: int, echo: bool, source: string, summary: string}|null
     */
    private static function decodePlan(array $fields): ?array
    {
        if (count($fields) !== 5
            || ! in_array($fields[0], ['0', '1'], true)
            || ! in_array($fields[2], ['0', '1'], true)
            || $fields[3] !== 'setting'
            || (string) (int) $fields[1] !== $fields[1]) {
            return null;
        }

        return [
            'dispatch' => $fields[0] === '1',
            'mode' => (int) $fields[1],
            'echo' => $fields[2] === '1',
            'source' => $fields[3],
            'summary' => $fields[4],
        ];
    }

    /**
     * @param  non-empty-string  $symbol
     * @param  list<int|string|null>  $args
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -2) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode("\x1F", FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** Enabled: paired common and uncommon mean/p99 gains all reach 1.5x (see the W41 parity test). */
    public const bool W414_RUST_FFI_DEFAULT = true;

    public const string W414_FFI_CDEF = <<<'C'
        ssize_t nntmux_w414_book_release_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W414_SUMMARY_KEYS = ['total_releases', 'empty_title_count', 'ebook_count', 'audiobook_count', 'comic_count', 'magazine_count', 'retail_count', 'year_tagged_count'];

    private const array W414_EBOOK_TOKENS = ['EPUB' => true, 'MOBI' => true, 'AZW' => true, 'AZW3' => true, 'PDF' => true, 'KF8' => true, 'LIT' => true, 'FB2' => true, 'DJVU' => true];

    private const array W414_AUDIOBOOK_TOKENS = ['AUDIOBOOK' => true, 'AUDIOBOOKS' => true, 'M4B' => true, 'UNABRIDGED' => true, 'ABRIDGED' => true, 'MP3' => true];

    private const array W414_COMIC_TOKENS = ['CBR' => true, 'CBZ' => true, 'CB7' => true, 'COMIC' => true, 'COMICS' => true];

    private const array W414_MAGAZINE_TOKENS = ['MAGAZINE' => true, 'MAGAZINES' => true, 'ISSUE' => true, 'WEEKLY' => true, 'MONTHLY' => true];

    private static ?bool $w414UseRustFfi = null;

    private static FFI|false|null $w414Ffi = null;

    public static function useW414RustFfi(bool $enable = true): void
    {
        self::$w414UseRustFfi = $enable;
    }

    public static function usesW414RustFfi(): bool
    {
        return self::$w414UseRustFfi ?? self::W414_RUST_FFI_DEFAULT;
    }

    public static function resetW414RustFfi(): void
    {
        self::$w414UseRustFfi = null;
        self::$w414Ffi = null;
    }

    /**
     * Summarize book release titles by ebook, audiobook, comic and magazine
     * tokens, RETAIL tags and 19xx/20xx year tokens.
     * Titles come from strings, integers, or the searchname, name or title
     * array field; other values (including objects) count as empty titles.
     * Tokens are maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeBookReleaseBatch(array $releases): array
    {
        return self::usesW414RustFfi()
            ? self::summarizeBookReleaseBatchNative($releases)
            : self::summarizeBookReleaseBatchPhp($releases);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeBookReleaseBatchNative(array $releases): array
    {
        return self::w414Call(self::w414Titles($releases)) ?? self::summarizeBookReleaseBatchPhp($releases);
    }

    /**
     * @param  array<array-key, mixed>  $releases
     * @return array<string, int>
     */
    public static function summarizeBookReleaseBatchPhp(array $releases): array
    {
        $summary = array_fill_keys(self::W414_SUMMARY_KEYS, 0);
        foreach (self::w414Titles($releases) as $title) {
            $summary['total_releases']++;
            $title = trim($title);
            if ($title === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $found = [false, false, false, false, false, false];
            foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $found[0] = $found[0] || isset(self::W414_EBOOK_TOKENS[$token]);
                $found[1] = $found[1] || isset(self::W414_AUDIOBOOK_TOKENS[$token]);
                $found[2] = $found[2] || isset(self::W414_COMIC_TOKENS[$token]);
                $found[3] = $found[3] || isset(self::W414_MAGAZINE_TOKENS[$token]);
                $found[4] = $found[4] || $token === 'RETAIL';
                $found[5] = $found[5] || (strlen($token) === 4 && strspn($token, '0123456789') === 4 && (str_starts_with($token, '19') || str_starts_with($token, '20')));
            }
            $summary['ebook_count'] += (int) $found[0];
            $summary['audiobook_count'] += (int) $found[1];
            $summary['comic_count'] += (int) $found[2];
            $summary['magazine_count'] += (int) $found[3];
            $summary['retail_count'] += (int) $found[4];
            $summary['year_tagged_count'] += (int) $found[5];
        }

        return $summary;
    }

    /** Only integer and string titles participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w414Titles(array $input): array
    {
        $titles = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['searchname'] ?? $item['name'] ?? $item['title'] ?? null;
            }
            $titles[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $titles;
    }

    /** @param list<string> $titles
     * @return array<string, int>|null
     */
    private static function w414Call(array $titles): ?array
    {
        try {
            $input = serialize([$titles]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w414Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w414', self::W414_FFI_CDEF) ?? false;
            $ffi = self::$w414Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W414_FFI_CDEF.)
            $written = $ffi->nntmux_w414_book_release_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W414_FFI_CDEF.)
                $written = $ffi->nntmux_w414_book_release_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W414_SUMMARY_KEYS || $result['total_releases'] !== count($titles)) {
                return null;
            }
            $summary = [];
            foreach (self::W414_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($titles)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 5.18-5.80x, p99 4.76-5.14x; uncommon mean 4.46-4.65x, p99 3.34-3.81x); see RustTvPipeBookServiceBooksConsolesGamesProcessorW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        ssize_t nntmux_w533_book_title_flag_batch(const char *blob, size_t blob_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public static function usesW53RustFfi(): bool
    {
        $override = self::w53RustFfiState();

        return \is_bool($override) ? $override : self::W53_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 53 Rust path forced on or off, then restores the previous setting. */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /** Per uppercased token: 2 ebook, 4 audiobook, 8 comic, 16 magazine and 32 RETAIL (the W414 token sets). */
    private const array W53_BOOK_TOKEN_FLAGS = [
        'EPUB' => 2, 'MOBI' => 2, 'AZW' => 2, 'AZW3' => 2, 'PDF' => 2, 'KF8' => 2, 'LIT' => 2, 'FB2' => 2, 'DJVU' => 2,
        'AUDIOBOOK' => 4, 'AUDIOBOOKS' => 4, 'M4B' => 4, 'UNABRIDGED' => 4, 'ABRIDGED' => 4, 'MP3' => 4,
        'CBR' => 8, 'CBZ' => 8, 'CB7' => 8, 'COMIC' => 8, 'COMICS' => 8,
        'MAGAZINE' => 16, 'MAGAZINES' => 16, 'ISSUE' => 16, 'WEEKLY' => 16, 'MONTHLY' => 16,
        'RETAIL' => 32,
    ];

    /**
     * Classifies book release titles: one word per trimmed title with `flags | year << 8`, flags 1
     * empty, 2 ebook, 4 audiobook, 8 comic, 16 magazine and 32 RETAIL tokens, and `year` the first
     * 19xx/20xx token (0 without one); then the six flag counts and the year-tagged count. Tokens are
     * maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w53BookTitleFlagBatch(array $titles): array
    {
        return self::usesW53RustFfi() ? self::w53BookTitleFlagBatchNative($titles) : self::w53BookTitleFlagBatchPhp($titles);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w53BookTitleFlagBatchNative(array $titles): array
    {
        return self::w533Invoke($titles, \count($titles) + 7) ?? self::w53BookTitleFlagBatchPhp($titles);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @return list<int>
     */
    public static function w53BookTitleFlagBatchPhp(array $titles): array
    {
        $out = [];
        $totals = [0, 0, 0, 0, 0, 0];
        $years = 0;
        foreach ($titles as $title) {
            $title = trim((string) $title);
            if ($title === '') {
                $word = 1;
            } else {
                $flags = 0;
                $year = 0;
                foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    $flags |= self::W53_BOOK_TOKEN_FLAGS[$token] ?? 0;
                    if ($year === 0 && \strlen($token) === 4 && ctype_digit($token) && (str_starts_with($token, '19') || str_starts_with($token, '20'))) {
                        $year = (int) $token;
                    }
                }
                $years += (int) ($year !== 0);
                $word = $flags | ($year << 8);
            }
            $totals[0] += $word & 1;
            $totals[1] += ($word >> 1) & 1;
            $totals[2] += ($word >> 2) & 1;
            $totals[3] += ($word >> 3) & 1;
            $totals[4] += ($word >> 4) & 1;
            $totals[5] += ($word >> 5) & 1;
            $out[] = $word;
        }
        array_push($out, ...$totals);
        $out[] = $years;

        return $out;
    }

    /**
     * Wave 53 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out'
     * returns an `int64_t` output buffer of at least $val words.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w53', self::W53_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w53RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }

        return $override;
    }

    /**
     * Calls the Wave 53.3 export with the NUL-joined $items. Null when the library is missing, the
     * call declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>|null
     */
    private static function w533Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w53RustFfiState('ffi');
            $out = self::w53RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $blob = implode("\0", $items);
            if (substr_count($blob, "\0") !== max(0, \count($items) - 1)) {
                // An item holds a NUL byte: substitute a space, which classifies identically (trim() strips both bytes and both split tokens).
                $blob = implode("\0", str_replace("\0", ' ', $items));
            }
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w533_book_title_flag_batch($blob, \strlen($blob), \count($items), $out, $words);
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

    // ========================================================================
    // WAVE 66.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four 1000-round paired runs reached 3.99x common mean, 3.48x common p99, 3.18x uncommon mean and 2.85x uncommon p99; see RustBooksProcessorCategorizationPipelineResultServicePipeW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    private const string W66_FFI_CDEF = 'ssize_t nntmux_w664_books_processor_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w66BooksProcessorPlanBatch() record. */
    public const int W66_ROW_WORDS = 3;

    /** int64 summary words after the w66BooksProcessorPlanBatch() records (none for an empty batch). */
    public const int W66_TRAILER_WORDS = 12;

    /** Per upper-cased token: 1 ebook, 2 audiobook, 4 comic, 8 magazine, 16 RETAIL. */
    private const array W66_BOOK_TOKENS = [
        'EPUB' => 1, 'MOBI' => 1, 'AZW' => 1, 'AZW3' => 1, 'PDF' => 1, 'KF8' => 1, 'LIT' => 1, 'FB2' => 1, 'DJVU' => 1,
        'AUDIOBOOK' => 2, 'AUDIOBOOKS' => 2, 'M4B' => 2, 'UNABRIDGED' => 2, 'ABRIDGED' => 2, 'MP3' => 2,
        'CBR' => 4, 'CBZ' => 4, 'CB7' => 4, 'COMIC' => 4, 'COMICS' => 4,
        'MAGAZINE' => 8, 'MAGAZINES' => 8, 'ISSUE' => 8, 'WEEKLY' => 8, 'MONTHLY' => 8,
        'RETAIL' => 16,
    ];

    /** Metadata provider codes, matched after trim() and strtolower(). */
    private const array W66_PROVIDERS = ['amazon' => 1, 'google' => 2, 'isbndb' => 3, 'openlibrary' => 4];

    private const int W66_LIMIT_CAP = 1_000_000;

    /**
     * Book lookup plan rows for [search name, comma-separated metadata providers, category ID,
     * bookinfo ID, cover flag, lookup mode, lookup attempts, batch limit] items.
     *
     * The trimmed name is split into maximal ASCII alphanumeric tokens, compared upper-cased:
     * token flags 1 ebook, 2 audiobook, 4 comic, 8 magazine and 16 RETAIL (the W414 sets), 32 a
     * check-digit valid ISBN-13 (978/979) or ISBN-10 token, and the first 19xx/20xx token is the
     * year. Providers (amazon 1, google 2, isbndb 3, openlibrary 4) keep their first order without
     * duplicates; unknown names are counted, no known provider falls back to [amazon, google] and
     * an ISBN moves isbndb first. Kind: 2 audiobook (3030), 1 book (7000-7999), 0 other. Attempts
     * clamp to [0, 255] and the limit to [0, 10^6] (0 unlimited). Decisions: 1 lookup disabled
     * (mode 0), 2 not a book (or an audiobook in ebook-only mode 2), 3 already matched (bookinfo
     * above zero), 4 empty name, 5 failed (bookinfo -2) after three attempts, 6 look up, 7
     * deferred (the eligible ordinal reached the record's limit).
     *
     * Rows [decision | metadata state << 4 (1 cover, 2 bookinfo) | kind << 6 | token flags << 8 |
     * providers << 14 | default providers << 17 | unknown providers << 18 | provider codes (4
     * bits each, first at bit 19) | attempts << 35 | year << 43, fnv1a32(strtolower(name)) (0
     * when empty), eligible ordinal (-1 for decisions 1-5)], then the trailer [records, the seven
     * decision counts, ebook titles, ISBN titles, covers, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w66BooksProcessorPlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66BooksProcessorPlanBatchNative($items) : self::w66BooksProcessorPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w66BooksProcessorPlanBatchPhp(array $items): array
    {
        self::w66Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $eligible = 0;
        foreach ($items as [$name, $providers, $category, $bookinfo, $cover, $mode, $attempts, $limit]) {
            $title = trim($name);
            $tokenFlags = $year = 0;
            $isbn = false;
            foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $tokenFlags |= self::W66_BOOK_TOKENS[$token] ?? 0;
                $length = \strlen($token);
                if ($year === 0 && $length === 4 && ctype_digit($token) && (str_starts_with($token, '19') || str_starts_with($token, '20'))) {
                    $year = (int) $token;
                }
                if (! $isbn && ($length === 10 || $length === 13) && self::w66Isbn($token)) {
                    $isbn = true;
                }
            }
            if ($isbn) {
                $tokenFlags |= 32;
            }
            $order = [];
            $unknown = 0;
            foreach (explode(',', $providers) as $provider) {
                $provider = strtolower(trim($provider));
                if ($provider === '') {
                    continue;
                }
                $code = self::W66_PROVIDERS[$provider] ?? 0;
                if ($code === 0) {
                    $unknown++;
                } elseif (! \in_array($code, $order, true)) {
                    $order[] = $code;
                }
            }
            $default = $order === [];
            if ($default) {
                $order = [1, 2];
            }
            if ($isbn && ($at = array_search(3, $order, true)) !== false && $at > 0) {
                array_splice($order, $at, 1);
                array_unshift($order, 3);
            }
            $packed = 0;
            foreach ($order as $k => $code) {
                $packed |= $code << (4 * $k);
            }
            $kind = $category === Category::MUSIC_AUDIOBOOK ? 2 : ($category >= 7000 && $category <= 7999 ? 1 : 0);
            $cover = $cover !== 0;
            $attempts = max(0, min($attempts, 255));
            $limit = max(0, min($limit, self::W66_LIMIT_CAP));
            $state = ($bookinfo > 0 ? 2 : 0) | ($cover ? 1 : 0);
            $ordinal = -1;
            if ($mode === 0) {
                $decision = 1;
            } elseif ($kind === 0 || ($mode === 2 && $kind === 2)) {
                $decision = 2;
            } elseif ($bookinfo > 0) {
                $decision = 3;
            } elseif ($title === '') {
                $decision = 4;
            } elseif ($bookinfo === -2 && $attempts >= 3) {
                $decision = 5;
            } else {
                $ordinal = $eligible++;
                $decision = $limit > 0 && $ordinal >= $limit ? 7 : 6;
            }
            $word = $decision | $state << 4 | $kind << 6 | $tokenFlags << 8 | \count($order) << 14 | ($default ? 1 : 0) << 17 | ($unknown > 0 ? 1 : 0) << 18
                | $packed << 19 | $attempts << 35 | $year << 43;
            $hash = self::w66Fnv(strtolower($title));
            array_push($out, $word, $hash, $ordinal);
            $t[$decision]++;
            $t[8] += $tokenFlags & 1;
            $t[9] += $isbn ? 1 : 0;
            $t[10] += $cover ? 1 : 0;
            $value = $word ^ $hash << 8 ^ $ordinal;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[11] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w66BooksProcessorPlanBatchNative(array $items): array
    {
        self::w66Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w66Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5),
            ...array_column($items, 6), ...array_column($items, 7));

        return self::w66Call($blob, $twin, $ints, $count, $count * self::W66_ROW_WORDS + self::W66_TRAILER_WORDS)
            ?? self::w66BooksProcessorPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w66Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W66 books processor plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 8 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2])
                || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7])) {
                throw new \InvalidArgumentException('W66 books processor plan batch items must be [string search name, string providers, int category, int bookinfo ID, int cover, int lookup mode, int attempts, int batch limit] lists.');
            }
        }
    }

    /** A check-digit valid ISBN-13 (978/979 prefix) or ISBN-10 (`X` check digit) upper-cased token. */
    private static function w66Isbn(string $token): bool
    {
        $sum = 0;
        if (\strlen($token) === 13) {
            if (! ctype_digit($token) || ! (str_starts_with($token, '978') || str_starts_with($token, '979'))) {
                return false;
            }
            for ($k = 0; $k < 13; $k++) {
                $sum += (int) $token[$k] * ($k % 2 === 0 ? 1 : 3);
            }

            return $sum % 10 === 0;
        }
        if (\strlen($token) !== 10 || ! ctype_digit(substr($token, 0, 9)) || ! ($token[9] === 'X' || ctype_digit($token[9]))) {
            return false;
        }
        for ($k = 0; $k < 10; $k++) {
            $sum += (10 - $k) * ($token[$k] === 'X' ? 10 : (int) $token[$k]);
        }

        return $sum % 11 === 0;
    }

    public static function usesW66RustFfi(): bool
    {
        $enabled = self::w66RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W66_RUST_FFI_DEFAULT;
    }

    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('enabled', true);
    }

    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('enabled', false);
    }

    /** Drop the W66 override, binding, output buffer and declined-call count; routing returns to W66_RUST_FFI_DEFAULT. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    /**
     * Run $callback with the W66 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('enabled');
        self::w66RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w66Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w66Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w66RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w66', self::W66_FFI_CDEF) ?? false;
            self::w66RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w66RustFfiState('out', $out);
                self::w66RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W66_FFI_CDEF.)
            $written = $ffi->nntmux_w664_books_processor_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w66RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W66 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w66Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
