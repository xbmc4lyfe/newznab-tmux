<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnidbTitle;
use App\Models\Category;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
