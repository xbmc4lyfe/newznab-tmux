<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class Game extends BaseModel
{
    protected const string ENDPOINT = 'games';

    public const string LOOKUP_FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_igdb_game_lookup_spec(
            int64_t limit,
            uint32_t has_platform,
            int64_t platform_id,
            const char *title_ptr,
            size_t title_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIgdbBookTvProviderMetadataParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const int LIMIT_MIN = 1;

    private const int LIMIT_MAX = 500;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Toggles only the helper below; BaseModel keeps its own endpoint/key toggle.
     */
    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Builds an IGDB game search: the title is trimmed with whitespace runs collapsed, quotes and
     * backslashes are escaped for the Apicalypse `search` clause, the limit is clamped to 1-500 and
     * a non-positive platform id is dropped.
     *
     * @return array{search: string, escaped: string, limit: int, platform_id: ?int, query: string, is_valid: bool}
     */
    public static function normalizeGameLookupSpec(string $searchTitle, int $limit = 10, ?int $platformId = null): array
    {
        return self::usesRustFfi()
            ? self::normalizeGameLookupSpecNative($searchTitle, $limit, $platformId)
            : self::normalizeGameLookupSpecPhp($searchTitle, $limit, $platformId);
    }

    /**
     * @return array{search: string, escaped: string, limit: int, platform_id: ?int, query: string, is_valid: bool}
     */
    public static function normalizeGameLookupSpecNative(string $searchTitle, int $limit = 10, ?int $platformId = null): array
    {
        $fields = self::callNative('nntmux_w35_igdb_game_lookup_spec', [$limit, $platformId === null ? 0 : 1, $platformId ?? 0, $searchTitle, strlen($searchTitle)]);
        if ($fields === null || count($fields) !== 6 || $fields[0] === null || $fields[1] === null || $fields[2] === null || $fields[4] === null || $fields[5] === null) {
            return self::normalizeGameLookupSpecPhp($searchTitle, $limit, $platformId);
        }

        return [
            'search' => $fields[0],
            'escaped' => $fields[1],
            'limit' => (int) $fields[2],
            'platform_id' => $fields[3] === null ? null : (int) $fields[3],
            'query' => $fields[4],
            'is_valid' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{search: string, escaped: string, limit: int, platform_id: ?int, query: string, is_valid: bool}
     */
    public static function normalizeGameLookupSpecPhp(string $searchTitle, int $limit = 10, ?int $platformId = null): array
    {
        $search = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($searchTitle, self::WHITESPACE)) ?? '';
        $escaped = addcslashes($search, '"\\');
        $limit = max(self::LIMIT_MIN, min(self::LIMIT_MAX, $limit));
        $platformId = $platformId !== null && $platformId > 0 ? $platformId : null;
        $where = $platformId === null ? '' : "where platforms = ({$platformId}); ";

        return [
            'search' => $search,
            'escaped' => $escaped,
            'limit' => $limit,
            'platform_id' => $platformId,
            'query' => "search \"{$escaped}\"; fields *; {$where}limit {$limit};",
            'is_valid' => $search !== '',
        ];
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.04x, p99 1.78x; uncommon mean 2.40x, p99 2.11x; see the W48 parity test). */
    public const bool W48_GAME_QUALITY_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_igdb_game_quality_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W48_GAME_QUALITY_KEYS = ['total_games', 'untitled_count', 'summary_count', 'storyline_count', 'html_summary_count', 'rated_count', 'dated_count', 'genre_sum', 'platform_sum', 'quality_sum', 'best_quality', 'release_year_sum'];

    /** Last representable release timestamp (2099-12-31T23:59:59Z). */
    private const int W48_RELEASE_MAX = 4102444799;

    /** Row keys flattened as [name, summary, storyline, rating x100, release, genres, platforms, rating count]. */
    private const array W48_TEXT_KEYS = ['name', 'summary', 'storyline'];

    private static ?bool $w48UseRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW48GameQualityRustFfi(?bool $enable = true): void
    {
        self::$w48UseRustFfi = $enable;
    }

    public static function usesW48GameQualityRustFfi(): bool
    {
        return self::$w48UseRustFfi ?? self::W48_GAME_QUALITY_RUST_FFI_DEFAULT;
    }

    public static function resetW48GameQualityRustFfi(): void
    {
        self::$w48UseRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Score IGDB game rows for match quality: titles, summary and storyline
     * word counts, HTML in summaries, ratings (scaled by 100, 0-10000),
     * release timestamps up to 2099 and their Gregorian years, unique genres
     * and platforms (lists of ids/names or comma-separated text) and rating
     * counts, combined into a per-row quality score (sum and best; -1 when
     * empty). Rows are IGDB game maps or bare titles.
     *
     * @param  array<array-key, mixed>  $games
     * @return array<string, int>
     */
    public static function scoreGameQualityBatch(array $games): array
    {
        return self::usesW48GameQualityRustFfi()
            ? self::scoreGameQualityBatchNative($games)
            : self::scoreGameQualityBatchPhp($games);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $games
     * @return array<string, int>
     */
    public static function scoreGameQualityBatchNative(array $games): array
    {
        $fields = self::w48Fields($games);

        return self::w48Call($fields) ?? self::w48Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $games
     * @return array<string, int>
     */
    public static function scoreGameQualityBatchPhp(array $games): array
    {
        return self::w48Summary(self::w48Fields($games));
    }

    /**
     * @param  array<array-key, mixed>  $games
     * @return list<string>
     */
    private static function w48Fields(array $games): array
    {
        $fields = [];
        foreach ($games as $row) {
            if (! is_array($row)) {
                array_push($fields, is_string($row) ? $row : (is_int($row) ? (string) $row : ''), '', '', '', '', '', '', '');

                continue;
            }
            foreach (self::W48_TEXT_KEYS as $key) {
                $value = $row[$key] ?? null;
                $fields[] = is_string($value) ? $value : (is_int($value) ? (string) $value : '');
            }
            $rating = $row['rating'] ?? null;
            $fields[] = match (true) {
                is_int($rating) && $rating >= -1000000 && $rating <= 1000000 => (string) ($rating * 100),
                is_float($rating) && $rating >= -1000000 && $rating <= 1000000 => (string) (int) round($rating * 100),
                is_string($rating) => $rating,
                default => '',
            };
            $release = $row['first_release_date'] ?? null;
            $fields[] = is_string($release) ? $release : (is_int($release) ? (string) $release : '');
            foreach (['genres', 'platforms'] as $key) {
                $value = $row[$key] ?? null;
                if (is_array($value)) {
                    $items = [];
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $item = $item['id'] ?? $item['name'] ?? null;
                        }
                        $items[] = is_string($item) ? $item : (is_int($item) ? (string) $item : '');
                    }
                    $fields[] = implode(',', $items);
                } else {
                    $fields[] = is_string($value) ? $value : (is_int($value) ? (string) $value : '');
                }
            }
            $count = $row['rating_count'] ?? null;
            $fields[] = is_string($count) ? $count : (is_int($count) ? (string) $count : '');
        }

        return $fields;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private static function w48Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W48_GAME_QUALITY_KEYS, 0);
        $summary['best_quality'] = -1;
        for ($i = 0, $n = count($fields); $i < $n; $i += 8) {
            $summary['total_games']++;
            $named = trim($fields[$i], self::WHITESPACE) !== '';
            $summary['untitled_count'] += (int) ! $named;
            $summaryWords = (int) preg_match_all('/[^ \t\n\r\0\x0B\x0C]+/', $fields[$i + 1]);
            $storyWords = (int) preg_match_all('/[^ \t\n\r\0\x0B\x0C]+/', $fields[$i + 2]);
            $summary['summary_count'] += (int) ($summaryWords > 0);
            $summary['storyline_count'] += (int) ($storyWords > 0);
            $html = preg_match('/<[A-Za-z\/!]/', $fields[$i + 1]) === 1;
            $summary['html_summary_count'] += (int) $html;
            $text = $fields[$i + 3];
            $body = str_starts_with($text, '-') ? substr($text, 1) : $text;
            $rating = null;
            if ($body !== '' && strlen($body) <= 7 && ctype_digit($body)) {
                $rating = $body === $text ? (int) $body : -(int) $body;
                $rating = $rating >= 0 && $rating <= 10000 ? $rating : null;
            }
            $summary['rated_count'] += (int) ($rating !== null);
            $text = $fields[$i + 4];
            $year = null;
            if ($text !== '' && strlen($text) <= 10 && ctype_digit($text) && (int) $text <= self::W48_RELEASE_MAX) {
                $year = self::w48Year((int) $text);
            }
            $summary['dated_count'] += (int) ($year !== null);
            $genres = self::w48UniqueTokens($fields[$i + 5]);
            $platforms = self::w48UniqueTokens($fields[$i + 6]);
            $summary['genre_sum'] += $genres;
            $summary['platform_sum'] += $platforms;
            $text = $fields[$i + 7];
            $ratingCount = $text !== '' && strlen($text) <= 9 && ctype_digit($text) ? (int) $text : 0;
            $quality = ($named ? 10 : 0)
                + intdiv(min($summaryWords, 200), 10)
                + ($storyWords > 0 ? 10 : 0)
                + ($rating === null ? 0 : intdiv($rating, 500))
                + ($year === null ? 0 : 10)
                + min($genres, 4) * 5
                + min($platforms, 5) * 4
                + ($ratingCount >= 10 ? 5 : 0)
                - ($html ? 5 : 0);
            $summary['quality_sum'] += $quality;
            $summary['best_quality'] = max($summary['best_quality'], $quality);
            $summary['release_year_sum'] += $year ?? 0;
        }

        return $summary;
    }

    /** Unique comma-separated tokens after trim(), ignoring ASCII case. */
    private static function w48UniqueTokens(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $seen = [];
        foreach (explode(',', $value) as $token) {
            $token = strtolower(trim($token, self::WHITESPACE));
            if ($token !== '') {
                $seen[$token] = true;
            }
        }

        return count($seen);
    }

    /** Proleptic Gregorian year of a non-negative Unix timestamp (Hinnant's civil_from_days). */
    private static function w48Year(int $timestamp): int
    {
        $z = intdiv($timestamp, 86400) + 719468;
        $era = intdiv($z, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));

        return $yoe + $era * 400 + (intdiv(5 * $doy + 2, 153) >= 10 ? 1 : 0);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>|null
     */
    private static function w48Call(array $fields): ?array
    {
        try {
            $input = serialize($fields);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w48', self::W48_FFI_CDEF) ?? false;
            $ffi = self::$w48Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 96 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 96);
            // @phpstan-ignore method.notFound (Declared by W48_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w48_igdb_game_quality_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 8) {
                return null;
            }

            return array_combine(self::W48_GAME_QUALITY_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::LOOKUP_FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    // ---------------------------------------------------------------------
    // Wave 54.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 3.91x, p99 2.25x; uncommon mean 4.36x, p99 3.06x); see RustXxxPipeIgdbClientCompanyGamePlatformW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w544_igdb_game_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * normalizeGameLookupSpecPhp() for many lookups at once, as lengths and offsets instead of
     * copies. Each (title, limit, platform id) yields five words: the search as a span (trimmed
     * offset << 32 | collapsed length), the escaped length, the clamped limit, the platform id
     * (-1 when not positive) and the Apicalypse query length.
     *
     * @param  list<string>  $titles
     * @param  list<int>  $limits
     * @param  list<int>  $platformIds
     * @return list<int>
     */
    public static function gameLookupRowBatch(array $titles, array $limits, array $platformIds): array
    {
        return self::usesW54RustFfi()
            ? self::gameLookupRowBatchNative($titles, $limits, $platformIds)
            : self::gameLookupRowBatchPhp($titles, $limits, $platformIds);
    }

    /**
     * @param  list<string>  $titles
     * @param  list<int>  $limits
     * @param  list<int>  $platformIds
     * @return list<int>
     */
    public static function gameLookupRowBatchNative(array $titles, array $limits, array $platformIds): array
    {
        $count = self::w54AssertColumns($titles, $limits, $platformIds);
        if ($count === 0) {
            return [];
        }

        return self::w54Call(implode("\0", $titles), pack('P*', ...$limits, ...$platformIds), $count, 5)
            ?? self::gameLookupRowBatchPhp($titles, $limits, $platformIds);
    }

    /**
     * @param  list<string>  $titles
     * @param  list<int>  $limits
     * @param  list<int>  $platformIds
     * @return list<int>
     */
    public static function gameLookupRowBatchPhp(array $titles, array $limits, array $platformIds): array
    {
        self::w54AssertColumns($titles, $limits, $platformIds);
        $out = [];
        foreach ($titles as $i => $title) {
            $search = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($title, self::WHITESPACE)) ?? '';
            $escaped = addcslashes($search, '"\\');
            $limit = max(self::LIMIT_MIN, min(self::LIMIT_MAX, $limits[$i]));
            $platformId = $platformIds[$i];
            $where = $platformId > 0 ? "where platforms = ({$platformId}); " : '';
            array_push(
                $out,
                strspn($title, self::WHITESPACE) << 32 | \strlen($search),
                \strlen($escaped),
                $limit,
                $platformId > 0 ? $platformId : -1,
                \strlen("search \"{$escaped}\"; fields *; {$where}limit {$limit};"),
            );
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $first
     * @param  array<array-key, mixed>  $second
     * @param  array<array-key, mixed>  $third
     */
    private static function w54AssertColumns(array $first, array $second, array $third): int
    {
        self::w54AssertList($first);
        self::w54AssertList($second);
        self::w54AssertList($third);
        $count = \count($first);
        if (\count($second) !== $count || \count($third) !== $count) {
            throw new \InvalidArgumentException('W54 batch columns must have the same length.');
        }

        return $count;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function w54AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W54 batch input must be a list.');
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W54 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('enabled');
        self::w54RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns and packed int64 columns in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w54Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w54RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w54', self::W54_FFI_CDEF) ?? false;
                self::w54RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w54RustFfiState('out', $out);
                self::w54RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w544_igdb_game_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W54 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }

    // ========================================================================
    // WAVE 67.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four 1000-round paired runs reached 2.78x common mean, 2.36x common p99, 2.21x uncommon mean and 2.22x uncommon p99; see RustIgdbCompanyGamePlatformInvitationIsbnDbW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_FFI_CDEF = 'ssize_t nntmux_w674_igdb_game_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w67IgdbGamePlanBatch() record. */
    public const int W67_ROW_WORDS = 4;

    /** int64 summary words after the w67IgdbGamePlanBatch() records (none for an empty batch). */
    public const int W67_TRAILER_WORDS = 9;

    /** Lower-cased IGDB genre names => genre bit. */
    private const array W67_GENRES = [
        'adventure' => 1, 'arcade' => 2, 'fighting' => 4, "hack and slash/beat 'em up" => 4, 'indie' => 8, 'platform' => 16, 'puzzle' => 32, 'racing' => 64,
        'role-playing (rpg)' => 128, 'rpg' => 128, 'shooter' => 256, 'simulator' => 512, 'sport' => 1024, 'strategy' => 2048, 'real time strategy (rts)' => 2048,
        'turn-based strategy (tbs)' => 2048, 'tactical' => 2048,
    ];

    private const string W67_LOWER_ALNUM = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * Game match plan rows for [title, search query, comma-separated platform IDs,
     * comma-separated genre names, cover image ID, first release timestamp, query year, query
     * platform ID, rating x100, rating votes] items.
     *
     * Title and query split into lower-cased maximal ASCII alphanumeric tokens: exact (same token
     * list), prefix (the query is a shorter leading token list) and Jaccard similarity of the
     * unique tokens in thousandths. The release year (timestamps 1 to W48_RELEASE_MAX, else 0)
     * against a query year 1-9999: 0 no query year, 1 undated, 2 same, 3 one off, 4 further.
     * Platform IDs are trimmed 1-6 digit numbers (others count as invalid); genres map through
     * W67_GENRES (others count as unknown). Cover: 0 empty, 1 up to 32 [a-z0-9], 2 other. Rating
     * tier: 0 no votes or rating outside 0-10000, 1 under 5 votes, 2 under 50, 3 more; the
     * weighted rating is rating x min(votes, 100) / 100. Score: similarity + 100 same year, 50 one
     * off, -200 further, 100 platform match, 20 cover. Decisions: 1 no title tokens, 2 no query
     * tokens, 3 score 800+, 4 score 500+, 5 below.
     *
     * Rows [decision | year code << 3 | platform match << 6 | cover << 7 | tier << 9 | exact << 11
     * | prefix << 12 | similarity << 13 | year << 24 | weighted rating << 36 | title tokens << 50 |
     * query tokens << 58, score, genre bits | unknown genres << 16 | platforms << 24 | invalid
     * platforms << 32, fnv1a32(title tokens joined by ' ')], then the trailer [records, the five
     * decision counts, exact matches, platform matches, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w67IgdbGamePlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67IgdbGamePlanBatchNative($items) : self::w67IgdbGamePlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w67IgdbGamePlanBatchPhp(array $items): array
    {
        self::w67Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$title, $query, $platformIds, $genreNames, $coverId, $released, $queryYear, $queryPlatform, $rating, $votes]) {
            $titleTokens = preg_split('/[^a-z0-9]+/', strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $queryTokens = preg_split('/[^a-z0-9]+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $titleCount = \count($titleTokens);
            $queryCount = \count($queryTokens);
            $titleHash = self::w67Fnv(implode(' ', $titleTokens));
            $exact = $queryCount > 0 && $titleTokens === $queryTokens;
            $prefix = $queryCount > 0 && $queryCount < $titleCount && \array_slice($titleTokens, 0, $queryCount) === $queryTokens;
            $titleSet = array_flip($titleTokens);
            $querySet = array_flip($queryTokens);
            $inter = \count(array_intersect_key($titleSet, $querySet));
            $union = \count($titleSet) + \count($querySet) - $inter;
            $similarity = $union > 0 ? intdiv($inter * 1000, $union) : 0;

            $year = 0;
            if ($released >= 1 && $released <= self::W48_RELEASE_MAX) {
                $z = intdiv($released, 86_400) + 719_468;
                $era = intdiv($z, 146_097);
                $doe = $z - $era * 146_097;
                $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36_524) - intdiv($doe, 146_096), 365);
                $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
                $year = $yoe + $era * 400 + (intdiv(5 * $doy + 2, 153) >= 10 ? 1 : 0);
            }
            $yearCode = $queryYear < 1 || $queryYear > 9999 ? 0 : ($year === 0 ? 1 : match (abs($year - $queryYear)) {
                0 => 2,
                1 => 3,
                default => 4,
            });

            $platforms = $invalid = 0;
            $platformMatch = false;
            foreach (explode(',', $platformIds) as $platform) {
                $platform = trim($platform);
                if ($platform === '') {
                    continue;
                }
                if (\strlen($platform) <= 6 && ctype_digit($platform)) {
                    $platforms++;
                    $platformMatch = $platformMatch || ($queryPlatform > 0 && (int) $platform === $queryPlatform);
                } else {
                    $invalid++;
                }
            }

            $genres = $unknown = 0;
            foreach (explode(',', $genreNames) as $genre) {
                $genre = trim($genre);
                if ($genre === '') {
                    continue;
                }
                $bit = self::W67_GENRES[strtolower($genre)] ?? 0;
                if ($bit === 0) {
                    $unknown++;
                } else {
                    $genres |= $bit;
                }
            }

            $cover = trim($coverId);
            $coverCode = $cover === '' ? 0 : (\strlen($cover) <= 32 && strspn($cover, self::W67_LOWER_ALNUM) === \strlen($cover) ? 1 : 2);

            $tier = $votes <= 0 || $rating < 0 || $rating > 10_000 ? 0 : ($votes < 5 ? 1 : ($votes < 50 ? 2 : 3));
            $weighted = $tier === 0 ? 0 : intdiv($rating * min($votes, 100), 100);

            $score = $similarity + match ($yearCode) {
                2 => 100,
                3 => 50,
                4 => -200,
                default => 0,
            } + ($platformMatch ? 100 : 0) + ($coverCode === 1 ? 20 : 0);
            $decision = $titleCount === 0 ? 1 : ($queryCount === 0 ? 2 : ($score >= 800 ? 3 : ($score >= 500 ? 4 : 5)));
            $word = $decision | $yearCode << 3 | (int) $platformMatch << 6 | $coverCode << 7 | $tier << 9 | (int) $exact << 11 | (int) $prefix << 12
                | $similarity << 13 | $year << 24 | $weighted << 36 | min($titleCount, 255) << 50 | min($queryCount, 31) << 58;
            $detail = $genres | min($unknown, 255) << 16 | min($platforms, 255) << 24 | min($invalid, 255) << 32;
            array_push($out, $word, $score, $detail, $titleHash);
            $t[$decision]++;
            $t[6] += (int) $exact;
            $t[7] += (int) $platformMatch;
            $value = $word ^ $score << 4 ^ $titleHash;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[8] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w67IgdbGamePlanBatchNative(array $items): array
    {
        self::w67Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w67Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3), array_column($items, 4)]);
        $ints = pack('P*', ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7), ...array_column($items, 8), ...array_column($items, 9));

        return self::w67Call($blob, $twin, $ints, $count, $count * self::W67_ROW_WORDS + self::W67_TRAILER_WORDS)
            ?? self::w67IgdbGamePlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w67Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W67 IGDB game plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 10 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2])
                || ! \is_string($item[3]) || ! \is_string($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])
                || ! \is_int($item[9])) {
                throw new \InvalidArgumentException('W67 IGDB game plan batch items must be [string title, string query, string platform IDs, string genres, string cover ID, int release timestamp, int query year, int query platform, int rating, int votes] lists.');
            }
        }
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', false);
    }

    /** Drop the W67 override, binding, output buffer and declined-call count; routing returns to W67_RUST_FFI_DEFAULT. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    /**
     * Run $callback with the W67 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('enabled');
        self::w67RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w67Join(array $columns): array
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
    private static function w67Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w67RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w67', self::W67_FFI_CDEF) ?? false;
            self::w67RustFfiState('ffi', $ffi);
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
                self::w67RustFfiState('out', $out);
                self::w67RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W67_FFI_CDEF.)
            $written = $ffi->nntmux_w674_igdb_game_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w67RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W67 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
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
    private static function w67Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
