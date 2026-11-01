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
}
