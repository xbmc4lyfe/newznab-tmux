<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
