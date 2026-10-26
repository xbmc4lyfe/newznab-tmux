<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnidbTitle;
use App\Models\Category;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service class for AniDB data fetching and processing.
 */
class AnidbService
{
    /** Disabled until correctness and the common-path and p99 benchmark gates qualify. */
    public const bool W394_RUST_FFI_DEFAULT = false;

    public const string W394_FFI_CDEF = <<<'C'
        ssize_t nntmux_w394_anidb_rate_limit_window(const char *input, size_t len, char *out, size_t cap);
        C;

    private static ?bool $w394UseRustFfi = null;

    private static FFI|false|null $w394Ffi = null;

    public static function useW394RustFfi(?bool $use = true): void
    {
        self::$w394UseRustFfi = $use;
        if ($use === null) {
            self::$w394Ffi = null;
        }
    }

    public static function usesW394RustFfi(): bool
    {
        return self::$w394UseRustFfi ?? self::W394_RUST_FFI_DEFAULT;
    }

    public static function resetW394RustFfi(): void
    {
        self::$w394UseRustFfi = null;
        self::$w394Ffi = null;
    }

    /**
     * Arguments contain only projected scalar fields, never caller-owned objects.
     *
     * @param  list<mixed>  $arguments
     * @return array<string, int|string|bool>|null
     */
    private static function w394Call(array $arguments): ?array
    {
        try {
            $input = serialize($arguments);
            if (strlen($input) > 16777216) {
                return null;
            }
            if (self::$w394Ffi === null) {
                self::$w394Ffi = RustPipelineLibrary::resolveDomain('metadata_w394', self::W394_FFI_CDEF) ?? false;
            }
            $ffi = self::$w394Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
            $written = $ffi->nntmux_w394_anidb_rate_limit_window($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
                $written = $ffi->nntmux_w394_anidb_rate_limit_window($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $types = ['elapsed_seconds' => 'int', 'required_wait_seconds' => 'int', 'remaining_sleep_seconds' => 'int', 'backoff_multiplier' => 'int', 'ready_to_request' => 'bool'];
            if (! is_array($result) || array_keys($result) !== array_keys($types)) {
                return null;
            }
            foreach ($types as $key => $type) {
                if (get_debug_type($result[$key]) !== $type) {
                    return null;
                }
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, int|string|bool> */
    public static function planAnidbRateLimitWindow(int $lastRequestUnix, int $nowUnix, int $consecutiveFailures, int $baseCooldownSeconds = 2): array
    {
        if (self::usesW394RustFfi()) {
            $native = self::w394Call([$lastRequestUnix, $nowUnix, $consecutiveFailures, $baseCooldownSeconds]);
            if ($native !== null) {
                return $native;
            }
        }

        return self::planAnidbRateLimitWindowPhp($lastRequestUnix, $nowUnix, $consecutiveFailures, $baseCooldownSeconds);
    }

    /** @return array<string, int|bool> */
    public static function planAnidbRateLimitWindowPhp(int $lastRequestUnix, int $nowUnix, int $consecutiveFailures, int $baseCooldownSeconds = 2): array
    {
        $lastRequestUnix = max(0, $lastRequestUnix);
        $nowUnix = max(0, $nowUnix);
        $baseCooldownSeconds = max(0, $baseCooldownSeconds);
        $elapsed = max(0, $nowUnix - $lastRequestUnix);
        $multiplier = 1 << min(30, max(0, $consecutiveFailures));
        $required = $baseCooldownSeconds > intdiv(PHP_INT_MAX, $multiplier) ? PHP_INT_MAX : $baseCooldownSeconds * $multiplier;
        $remaining = max(0, $required - $elapsed);

        return ['elapsed_seconds' => $elapsed, 'required_wait_seconds' => $required, 'remaining_sleep_seconds' => $remaining, 'backoff_multiplier' => $multiplier, 'ready_to_request' => $remaining === 0];
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.69x, p99 1.69x; uncommon mean 3.53x, p99 2.82x; see the W45 parity test). */
    public const bool W454_RUST_FFI_DEFAULT = true;

    public const string W454_FFI_CDEF = <<<'C'
        ssize_t nntmux_w454_anidb_title_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W454_SUMMARY_KEYS = ['total_titles', 'empty_title_count', 'bracket_tag_count', 'paren_tag_count', 'language_tag_count', 'adult_tag_count', 'date_code_count', 'quality_tag_count', 'release_tag_count', 'sxxexx_count', 'trailing_number_count', 'episode_suffix_count', 'volume_suffix_count', 'mojibake_count'];

    private static ?bool $w454UseRustFfi = null;

    private static FFI|false|null $w454Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW454RustFfi(?bool $enable = true): void
    {
        self::$w454UseRustFfi = $enable;
    }

    public static function usesW454RustFfi(): bool
    {
        return self::$w454UseRustFfi ?? self::W454_RUST_FFI_DEFAULT;
    }

    public static function resetW454RustFfi(): void
    {
        self::$w454UseRustFfi = null;
        self::$w454Ffi = null;
    }

    /**
     * Summarize raw anime titles by the tag families cleanTitlePhp() strips:
     * bracket/parenthesis tags, language, adult, six-digit date, quality and
     * release words, SxxEyy markers, trailing numbers, E/Ep/Episode and Vol/Ch
     * suffixes, and the mojibake fixEncodingPhp() repairs. Titles are trimmed
     * first; entries come from strings, integers, or the title, name or
     * searchname field, and other values count as empty. PCRE runs without /u,
     * so \b, \s and /i are ASCII-only.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeAnidbTitleBatch(array $entries): array
    {
        return self::usesW454RustFfi()
            ? self::summarizeAnidbTitleBatchNative($entries)
            : self::summarizeAnidbTitleBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeAnidbTitleBatchNative(array $entries): array
    {
        return self::w454Call(self::w454Entries($entries)) ?? self::summarizeAnidbTitleBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeAnidbTitleBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W454_SUMMARY_KEYS, 0);
        foreach (self::w454Entries($entries) as $title) {
            $summary['total_titles']++;
            $title = trim($title);
            if ($title === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $summary['bracket_tag_count'] += (int) (preg_match('/\[[^\]]+\]/', $title) === 1);
            $summary['paren_tag_count'] += (int) (preg_match('/\([^)]+\)/', $title) === 1);
            $summary['language_tag_count'] += (int) (preg_match('/\b(?:ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR)\b/i', $title) === 1);
            $summary['adult_tag_count'] += (int) (preg_match('/\b(?:JAV|Uncensored|Censored|Mosaic|Mosaic-less|HD|SD|FHD|UHD)\b/i', $title) === 1);
            $summary['date_code_count'] += (int) (preg_match('/\b\d{6}\b/', $title) === 1);
            $summary['quality_tag_count'] += (int) (preg_match('/\b(?:480p|720p|1080p|2160p|4K|BD|BDRip|BluRay|Blu-Ray|HEVC|x264|x265|H264|H265|WEB|WEBRip|DVDRip|TVRip)\b/i', $title) === 1);
            $summary['release_tag_count'] += (int) (preg_match('/\b(?:PROPER|REPACK|RIP|ISO|CRACK|BETA|ALPHA|FINAL|COMPLETE|FULL)\b/i', $title) === 1);
            $summary['sxxexx_count'] += (int) (preg_match('/\sS\d+E\d+/i', $title) === 1);
            $summary['trailing_number_count'] += (int) (preg_match('/(?:[-_]|\s)\s*\d{1,4}\z/', $title) === 1);
            $summary['episode_suffix_count'] += (int) (preg_match('/\sE(?:p(?:isode)?)?\s*0*\d{1,4}\z/i', $title) === 1);
            $summary['volume_suffix_count'] += (int) (preg_match('/\s(?:Vol|Ch)\.?\s*\d*\z/i', $title) === 1);
            $summary['mojibake_count'] += (int) (str_contains($title, "\xc3\x83") || str_contains($title, "\xc3\x82"));
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w454Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['title'] ?? $item['name'] ?? $item['searchname'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w454Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w454Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w454', self::W454_FFI_CDEF) ?? false;
            $ffi = self::$w454Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W454_FFI_CDEF.)
            $written = $ffi->nntmux_w454_anidb_title_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W454_FFI_CDEF.)
                $written = $ffi->nntmux_w454_anidb_title_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W454_SUMMARY_KEYS || $result['total_titles'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W454_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_anidb_clean_title(const char *ptr, size_t len, char *out, size_t cap);
        ssize_t nntmux_anidb_extract_base_title(const char *ptr, size_t len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? true;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Updates stored AniList entries in the database.
     * Note: AniList doesn't support episodes, so episode-related parameters are ignored.
     */
    public function updateTitle(
        int $anidbID,
        string $title,
        string $type,
        string $startdate,
        string $enddate,
        string $related,
        string $similar,
        string $creators,
        string $description,
        string $rating,
        string $categories,
        string $characters,
        string $epnos = '',
        string $airdates = '',
        string $episodetitles = ''
    ): void {
        DB::update(
            sprintf(
                '
				UPDATE anidb_titles at
				INNER JOIN anidb_info ai ON ai.anidbid = at.anidbid
				SET title = %s, type = %s, startdate = %s, enddate = %s,
					related = %s, similar = %s, creators = %s, description = %s, rating = %s,
					categories = %s, characters = %s WHERE anidbid = %d',
                escapeString($title),
                escapeString($type),
                escapeString($startdate),
                escapeString($enddate),
                escapeString($related),
                escapeString($similar),
                escapeString($creators),
                escapeString($description),
                escapeString($rating),
                escapeString($categories),
                escapeString($characters),
                $anidbID
            )
        );
    }

    /**
     * Deletes an AniDB title and associated info.
     *
     * @throws Throwable
     */
    public function deleteTitle(int $anidbID): void
    {
        DB::transaction(function () use ($anidbID) {
            DB::delete(
                sprintf(
                    '
				DELETE at, ai
				FROM anidb_titles AS at
				LEFT OUTER JOIN anidb_info ai USING (anidbid)
				WHERE anidbid = %d',
                    $anidbID
                )
            );
        }, 3);
    }

    /**
     * Retrieves a list of Anime titles, optionally filtered by starting character and title.
     *
     * @return array<string, mixed>
     */
    public function getAnimeList(string $letter = '', string $animeTitle = ''): array
    {
        $rsql = $tsql = '';

        if ($letter !== '') {
            if ($letter === '0-9') {
                $letter = '[0-9]';
            }
            $rsql .= sprintf('AND at.title REGEXP %s', escapeString('^'.$letter));
        }

        if ($animeTitle !== '') {
            $tsql .= sprintf('AND at.title LIKE %s', escapeString('%'.$animeTitle.'%'));
        }

        return DB::select(
            sprintf(
                '
				SELECT at.anidbid, at.title,
					ai.type, ai.categories, ai.rating, ai.startdate, ai.enddate
				FROM anidb_titles at
				LEFT JOIN anidb_info ai USING (anidbid)
				STRAIGHT_JOIN releases r ON at.anidbid = r.anidbid
				WHERE at.anidbid > 0 %s %s
				AND r.categories_id = %d
				GROUP BY at.anidbid
				ORDER BY at.title ASC',
                $rsql,
                $tsql,
                Category::TV_ANIME
            )
        );
    }

    /**
     * Retrieves a range of Anime titles for site display.
     */
    public function getAnimeRange(string $animeTitle = ''): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        $titleAggregate = DB::connection()->getDriverName() === 'sqlite'
            ? "GROUP_CONCAT(at.title, ', ') AS title"
            : "GROUP_CONCAT(at.title SEPARATOR ', ') AS title";

        $query = AnidbTitle::query()
            ->where('at.lang', '=', 'en');
        if ($animeTitle !== '') {
            $query->where('at.title', 'like', '%'.$animeTitle.'%');
        }
        $query->select([
            'at.anidbid',
            DB::raw($titleAggregate),
            'ai.description',
            'ai.type',
            'ai.startdate',
            'ai.enddate',
            'ai.rating',
        ])
            ->from('anidb_titles as at')
            ->leftJoin('anidb_info as ai', 'ai.anidbid', '=', 'at.anidbid')
            ->groupBy('at.anidbid')
            ->orderByDesc('at.anidbid');

        return $query->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Retrieves all info for a specific AniDB ID.
     * Note: AniList doesn't support episodes, so episode data is not included.
     */
    public function getAnimeInfo(?int $anidbID): mixed
    {
        if ($anidbID === null || $anidbID <= 0) {
            return null;
        }

        // Get main info with primary title (prefer English, fallback to any)
        $animeInfo = DB::select(
            sprintf(
                '
				SELECT at.anidbid, at.lang, at.title,
					ai.anilist_id, ai.mal_id, ai.country, ai.media_type, ai.episodes, ai.duration, ai.status, ai.source, ai.hashtag,
					ai.startdate, ai.enddate, ai.updated, ai.related, ai.creators, ai.description,
					ai.rating, ai.picture, ai.categories, ai.characters, ai.type, ai.similar
				FROM anidb_titles AS at
				LEFT JOIN anidb_info AS ai USING (anidbid)
				WHERE at.anidbid = %d
				ORDER BY CASE
					WHEN at.lang = "en" THEN 1
					WHEN at.lang = "x-jat" THEN 2
					ELSE 3
				END
				LIMIT 1',
                $anidbID
            )
        );

        $result = $animeInfo[0] ?? false;

        if ($result) {
            // Get English title separately
            $englishTitle = DB::selectOne(
                sprintf(
                    '
					SELECT title
					FROM anidb_titles
					WHERE anidbid = %d AND lang = "en"
					LIMIT 1',
                    $anidbID
                )
            );

            // Get native title (prefer ja, then x-jat, then any non-English title)
            $nativeTitle = DB::selectOne(
                sprintf(
                    '
					SELECT title, lang
					FROM anidb_titles
					WHERE anidbid = %d AND lang NOT IN ("en")
					ORDER BY CASE
						WHEN lang = "ja" THEN 1
						WHEN lang = "x-jat" THEN 2
						ELSE 3
					END
					LIMIT 1',
                    $anidbID
                )
            );

            // Get romaji title separately
            $romajiTitle = DB::selectOne(
                sprintf(
                    '
					SELECT title, lang
					FROM anidb_titles
					WHERE anidbid = %d AND lang = "x-jat"
					LIMIT 1',
                    $anidbID
                )
            );

            // If we found a native title, use it; otherwise use romaji as original
            $originalTitle = $nativeTitle;
            if (! $originalTitle && $romajiTitle) {
                $originalTitle = $romajiTitle;
            }

            // Add titles to result
            if ($englishTitle && isset($englishTitle->title)) {
                $result->english_title = $englishTitle->title;
            } else {
                $result->english_title = null;
            }

            if ($originalTitle && isset($originalTitle->title)) {
                $result->original_title = $originalTitle->title;
                $result->original_lang = $originalTitle->lang;
            } else {
                $result->original_title = null;
                $result->original_lang = null;
            }

            // Add romaji title separately (even if it's also the original)
            if ($romajiTitle && isset($romajiTitle->title)) {
                $result->romaji_title = $romajiTitle->title;
            } else {
                $result->romaji_title = null;
            }
        }

        return $result;
    }

    /**
     * Strip bracketed/parenthesized tags, language codes, episode markers, and quality noise from an anime title.
     */
    public function cleanTitle(string $title): string
    {
        return (self::$useRustFfi ?? true)
            ? $this->cleanTitleNative($title)
            : $this->cleanTitlePhp($title);
    }

    public function cleanTitleNative(string $title): string
    {
        return self::callNativeString('nntmux_anidb_clean_title', $title)
            ?? $this->cleanTitlePhp($title);
    }

    public function cleanTitlePhp(string $title): string
    {
        $title = $this->fixEncodingPhp($title);
        $title = (string) preg_replace('/\[[^\]]+\]/', ' ', $title);
        $title = (string) preg_replace('/\([^)]+\)/', ' ', $title);
        $title = (string) preg_replace('/\b(ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR)\b/i', ' ', $title);
        $title = (string) preg_replace('/\b(JAV|Uncensored|Censored|Mosaic|Mosaic-less|HD|SD|FHD|UHD)\b/i', ' ', $title);
        $title = (string) preg_replace('/\b\d{6}\b/', ' ', $title);
        $title = (string) preg_replace('/[-_]\s*\d{1,4}\s*$/i', '', $title);
        $title = (string) preg_replace('/\s+\d{1,4}\s*$/i', '', $title);
        $title = (string) preg_replace('/\s*-\s*\d+x\d+.*$/i', '', $title);
        $title = (string) preg_replace('/\s+S\d+E\d+.*$/i', '', $title);
        $title = (string) preg_replace('/\s*-\s*\d{1,4}(?:\s*-\s*.*)?\s*$/i', '', $title);
        $title = (string) preg_replace('/\s*-\s*$/i', '', $title);
        $title = (string) preg_replace('/\s+E(?:p(?:isode)?)?\s*0*\d{1,4}\s*$/i', '', $title);
        $title = (string) preg_replace('/\b(480p|720p|1080p|2160p|4K|BD|BDRip|BluRay|Blu-Ray|HEVC|x264|x265|H264|H265|WEB|WEBRip|DVDRip|TVRip)\b/i', ' ', $title);
        $title = (string) preg_replace('/\b(PROPER|REPACK|RIP|ISO|CRACK|BETA|ALPHA|FINAL|COMPLETE|FULL)\b/i', ' ', $title);
        $title = (string) preg_replace('/\s+Vol\.?\s*\d*\s*$/i', '', $title);
        $title = (string) preg_replace('/\s+Ch\.?\s*\d*\s*$/i', '', $title);
        $title = (string) preg_replace('/\s*[-_]\s*$/', '', $title);
        $title = (string) preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    /**
     * Extract the base anime title from a fansub/scene release name before episode/format markers.
     */
    public function extractBaseTitle(string $releaseName): string
    {
        return (self::$useRustFfi ?? true)
            ? $this->extractBaseTitleNative($releaseName)
            : $this->extractBaseTitlePhp($releaseName);
    }

    public function extractBaseTitleNative(string $releaseName): string
    {
        return self::callNativeString('nntmux_anidb_extract_base_title', $releaseName)
            ?? $this->extractBaseTitlePhp($releaseName);
    }

    public function extractBaseTitlePhp(string $releaseName): string
    {
        $s = $this->fixEncodingPhp($releaseName);
        $s = str_replace(['_', '.'], ' ', $s);
        $s = (string) preg_replace('/\s+/', ' ', $s);
        $s = trim($s);
        $s = (string) preg_replace('/^(?:\[[^\]]+\]\s*)+/', '', $s);
        $s = trim($s);
        $s = (string) preg_replace('/\[(?:ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR)\]/i', ' ', $s);
        $s = (string) preg_replace('/\((?:ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR)\)/i', ' ', $s);

        if (preg_match('/\sS\d+E\d+/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        } elseif (preg_match('/\s\d+x\d+/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        } elseif (preg_match('/\s-\s*(\d{1,3})\b/', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        } elseif (preg_match('/\sE(?:p(?:isode)?)?\s*0*(\d{1,3})\b/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        } elseif (preg_match('/\b(Movie|OVA|Complete Series|Complete|Full Series)\b/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        } elseif (preg_match('/\[(?:BD|BDRip|BluRay|Blu-Ray|\d{3,4}[ipx]|HEVC|x264|x265|H264|H265)\]/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        } else {
            $title = $s;
        }

        return $this->cleanTitlePhp($title);
    }

    protected function fixEncodingPhp(string $text): string
    {
        $text = (string) preg_replace('/Ã¢Â[_\sÂ]*/u', '', $text);
        $text = (string) preg_replace('/Ã[¢Â©€£]/u', '', $text);
        $text = (string) preg_replace('/Â+/u', '', $text);
        $text = (string) preg_replace('/Ã[^\s]*/u', '', $text);

        if (preg_match('/Ã[^\s]/u', $text)) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            if ($converted !== false && ! preg_match('/Ã[^\s]/u', $converted)) {
                $text = $converted;
            }
        }

        $text = (string) preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $text);

        if (function_exists('normalizer_normalize')) {
            $normalized = normalizer_normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        return (string) preg_replace('/[\xC0-\xC1\xC2-\xC5]/u', '', $text);
    }

    private static function callNativeString(string $symbol, string $input): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $len = strlen($input);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            $written = $ffi->$symbol($input, $len, $buffer, $capacity);
            if (! is_int($written) || $written === -1 || $written === PHP_INT_MIN) {
                return null;
            }
            if ($written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, $len, $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 53.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.83x, p99 2.17x; uncommon mean 2.45x, p99 2.00x); see RustAnidbArchiveMovieBrowseMovieServiceIgdbExW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        ssize_t nntmux_w534_anidb_episode_marker_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Episode markers for many release names at once, checked in extractBaseTitlePhp() priority
     * order after strtr($name, '_.', '  '). Each name yields four words: the marker kind (0 none,
     * 1 " SxxEyy", 2 " NxM", 3 " - NN", 4 " E/Ep/Episode NN", 5 a Movie/OVA/Complete keyword,
     * 6 a quality [tag]), the marker's byte offset (the name length when none), the episode
     * number (-1 for kinds 0, 5 and 6) and the number of leading [group] tags.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function anidbEpisodeMarkerBatch(array $names): array
    {
        return self::usesW53RustFfi()
            ? self::anidbEpisodeMarkerBatchNative($names)
            : self::anidbEpisodeMarkerBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function anidbEpisodeMarkerBatchNative(array $names): array
    {
        self::w53AssertList($names);
        if ($names === []) {
            return [];
        }

        return self::w53Call(implode("\0", $names), '', \count($names), 4)
            ?? self::anidbEpisodeMarkerBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function anidbEpisodeMarkerBatchPhp(array $names): array
    {
        self::w53AssertList($names);
        $out = [];
        foreach ($names as $name) {
            $s = strtr($name, '_.', '  ');
            if (preg_match('/\sS\d+E(\d+)/i', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
                array_push($out, 1, $m[0][1], (int) $m[1][0]);
            } elseif (preg_match('/\s\d+x(\d+)/i', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
                array_push($out, 2, $m[0][1], (int) $m[1][0]);
            } elseif (preg_match('/\s-\s*(\d{1,3})\b/', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
                array_push($out, 3, $m[0][1], (int) $m[1][0]);
            } elseif (preg_match('/\sE(?:p(?:isode)?)?\s*0*(\d{1,3})\b/i', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
                array_push($out, 4, $m[0][1], (int) $m[1][0]);
            } elseif (preg_match('/\b(?:Movie|OVA|Complete Series|Complete|Full Series)\b/i', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
                array_push($out, 5, $m[0][1], -1);
            } elseif (preg_match('/\[(?:BD|BDRip|BluRay|Blu-Ray|\d{3,4}[ipx]|HEVC|x264|x265|H264|H265)\]/i', $s, $m, PREG_OFFSET_CAPTURE) === 1) {
                array_push($out, 6, $m[0][1], -1);
            } else {
                array_push($out, 0, \strlen($s), -1);
            }
            $out[] = (int) preg_match_all('/\G\[[^\]]+\]\s*/', $s);
        }

        return $out;
    }

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W53 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('enabled');
        self::w53RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w53AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W53 batch input must be a list.');
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text fields and packed int64 columns in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w53Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w53RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w53', self::W53_FFI_CDEF) ?? false;
                self::w53RustFfiState('ffi', $ffi);
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
                self::w53RustFfiState('out', $out);
                self::w53RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w534_anidb_episode_marker_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
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
     * W53 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
    // WAVE 65.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 2.45x common mean, 2.70x common p99, 2.37x uncommon mean and 2.37x uncommon p99; see RustUsenetDownloadAdminSnapshotAnidbArchiveBlacklistW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_FFI_CDEF = 'ssize_t nntmux_w654_anidb_match_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w65AnidbMatchPlanBatch() record. */
    public const int W65_ROW_WORDS = 5;

    /** int64 summary words after the w65AnidbMatchPlanBatch() records (none for an empty batch). */
    public const int W65_TRAILER_WORDS = 12;

    private const string W65_BRACKETS = '/\[[^\]]*\]|\([^)]*\)/';

    private const string W65_NON_WORD = '/[^a-z0-9\x80-\xff]+/';

    private const string W65_EPISODE = '/^(?:s\d{1,2})?ep?(\d{1,4})\z/';

    /** Tokens that end the base title. */
    private const array W65_QUALITY = ['480p' => true, '720p' => true, '1080p' => true, '2160p' => true, '4k' => true, 'bd' => true, 'bdrip' => true,
        'bluray' => true, 'hevc' => true, 'x264' => true, 'x265' => true, 'h264' => true, 'h265' => true, 'web' => true, 'webrip' => true, 'webdl' => true,
        'dvdrip' => true, 'tvrip' => true, 'aac' => true, 'flac' => true, '10bit' => true, '8bit' => true, 'multi' => true, 'dual' => true];

    /**
     * AniDB match rows for [release name, romaji title, english title, kanji title, release
     * year, anime start year, episode count] items.
     *
     * Names and titles normalize the same way: `[...]` and `(...)` groups become spaces, ASCII
     * letters lower-case, runs of bytes other than a-z, 0-9 and 0x80-0xFF (UTF-8 kanji and
     * kana stay intact) become one space, and the ends are trimmed. The base title is the
     * release's tokens before the first all-digit, episode (`/^(?:s\d{1,2})?ep?\d{1,4}$/`) or
     * quality token; the first 1-4 digit token that is not a 1900-2099 year (or the first
     * episode token) is the episode and the first such year is the name year. Each non-empty
     * title scores `intdiv((longest - levenshtein) * 10000, longest)` against the base (0 when
     * the base is empty, -1 for an empty title); the best score wins with ties to the earlier
     * title. The release year is the positive column (capped at 9999), else the name year;
     * years match within one. Decisions: 1 matched (an exact title or 9000+), 2 probable (7500+
     * without a year mismatch), 3 weak (5000+), 4 none.
     *
     * Rows [decision | best source (1 romaji, 2 english, 3 kanji, 0 none) << 4 | year status
     * (0 unknown, 1 match, 2 mismatch) << 8 | episode status (0 none, 1 no count, 2 in range, 3
     * out of range) << 10 | flags << 12 (1 exact, 2 base starts with a title, 4 empty base, 8
     * episode, 16 year from the name) | base length << 20 (clamped to 2^16 - 1) | episode << 36
     * (0xFFFF when none), best score, scores (0xFFFF for an empty title) at bits 0/16/32,
     * fnv1a32(base) (0 when empty), release year], then the trailer [records, matched,
     * probable, weak, none, romaji, english and kanji best sources, year mismatches, episodes
     * out of range, best score total, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65AnidbMatchPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65AnidbMatchPlanBatchNative($items) : self::w65AnidbMatchPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65AnidbMatchPlanBatchPhp(array $items): array
    {
        self::w65Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$release, $romaji, $english, $kanji, $releaseYear, $animeYear, $episodes]) {
            $norm = self::w65AnidbNormalize($release);
            $tokens = $norm === '' ? [] : explode(' ', $norm);
            $stop = null;
            $episode = -1;
            $nameYear = 0;
            foreach ($tokens as $k => $token) {
                if (ctype_digit($token)) {
                    if (\strlen($token) <= 4) {
                        $n = (int) $token;
                        if (\strlen($token) === 4 && $n >= 1900 && $n <= 2099) {
                            if ($nameYear === 0) {
                                $nameYear = $n;
                            }
                        } elseif ($episode < 0) {
                            $episode = $n;
                        }
                    }
                    $isStop = true;
                } elseif (preg_match(self::W65_EPISODE, $token, $m) === 1) {
                    if ($episode < 0) {
                        $episode = (int) $m[1];
                    }
                    $isStop = true;
                } else {
                    $isStop = isset(self::W65_QUALITY[$token]);
                }
                if ($isStop && $stop === null) {
                    $stop = $k;
                }
            }
            $base = $stop === null ? $norm : implode(' ', \array_slice($tokens, 0, $stop));
            $sims = [-1, -1, -1];
            $exact = $prefix = false;
            foreach ([$romaji, $english, $kanji] as $k => $raw) {
                $title = self::w65AnidbNormalize($raw);
                if ($title === '') {
                    continue;
                }
                if ($base === '') {
                    $sims[$k] = 0;

                    continue;
                }
                $exact = $exact || $title === $base;
                $prefix = $prefix || str_starts_with($base, $title);
                $longest = max(\strlen($base), \strlen($title));
                $sims[$k] = intdiv(($longest - levenshtein($base, $title)) * 10_000, $longest);
            }
            $best = -1;
            $source = 0;
            foreach ($sims as $k => $sim) {
                if ($sim > $best) {
                    $best = $sim;
                    $source = $k + 1;
                }
            }
            $fromName = $releaseYear <= 0 && $nameYear > 0;
            $releaseYear = $releaseYear > 0 ? min($releaseYear, 9999) : $nameYear;
            $animeYear = max(0, min($animeYear, 9999));
            $episodes = max(0, min($episodes, 0xFFFF));
            $yearStatus = $releaseYear === 0 || $animeYear === 0 ? 0 : (abs($releaseYear - $animeYear) <= 1 ? 1 : 2);
            $episodeStatus = match (true) {
                $episode < 0 => 0,
                $episodes === 0 => 1,
                $episode >= 1 && $episode <= $episodes => 2,
                default => 3,
            };
            $decision = match (true) {
                $exact || $best >= 9000 => 1,
                $best >= 7500 && $yearStatus !== 2 => 2,
                $best >= 5000 => 3,
                default => 4,
            };
            $flags = ($exact ? 1 : 0) | ($prefix ? 2 : 0) | ($base === '' ? 4 : 0) | ($episode >= 0 ? 8 : 0) | ($fromName ? 16 : 0);
            $word = $decision | $source << 4 | $yearStatus << 8 | $episodeStatus << 10 | $flags << 12 | min(\strlen($base), 0xFFFF) << 20
                | ($episode < 0 ? 0xFFFF : $episode) << 36;
            $packed = ($sims[0] < 0 ? 0xFFFF : $sims[0]) | ($sims[1] < 0 ? 0xFFFF : $sims[1]) << 16 | ($sims[2] < 0 ? 0xFFFF : $sims[2]) << 32;
            $hash = self::w65Fnv($base);
            array_push($out, $word, $best, $packed, $hash, $releaseYear);
            $t[$decision]++;
            if ($source > 0) {
                $t[4 + $source]++;
            }
            if ($yearStatus === 2) {
                $t[8]++;
            }
            if ($episodeStatus === 3) {
                $t[9]++;
            }
            $t[10] += max($best, 0);
            $value = $word ^ $packed ^ $hash << 8;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[11] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function w65AnidbMatchPlanBatchNative(array $items): array
    {
        self::w65Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w65Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3)]);
        $ints = pack('P*', ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6));

        return self::w65Call($blob, $twin, $ints, $count, $count * self::W65_ROW_WORDS + self::W65_TRAILER_WORDS)
            ?? self::w65AnidbMatchPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w65Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W65 AniDB match plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 7 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])) {
                throw new \InvalidArgumentException('W65 AniDB match plan batch items must be [string release name, string romaji title, string english title, string kanji title, int release year, int anime year, int episodes] lists.');
            }
        }
    }

    public static function usesW65RustFfi(): bool
    {
        $enabled = self::w65RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W65_RUST_FFI_DEFAULT;
    }

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', false);
    }

    /** Drop the W65 override, binding, output buffer and declined-call count; routing returns to W65_RUST_FFI_DEFAULT. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    /**
     * Run $callback with the W65 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('enabled');
        self::w65RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w65Join(array $columns): array
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
    private static function w65Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w65RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w65', self::W65_FFI_CDEF) ?? false;
            self::w65RustFfiState('ffi', $ffi);
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
                self::w65RustFfiState('out', $out);
                self::w65RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W65_FFI_CDEF.)
            $written = $ffi->nntmux_w654_anidb_match_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w65RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W65 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
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

    /** Bracket groups to spaces, ASCII lower-case, other non-word byte runs to one space, trimmed. */
    private static function w65AnidbNormalize(string $value): string
    {
        $value = strtolower((string) preg_replace(self::W65_BRACKETS, ' ', $value));

        return trim((string) preg_replace(self::W65_NON_WORD, ' ', $value), ' ');
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w65Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
