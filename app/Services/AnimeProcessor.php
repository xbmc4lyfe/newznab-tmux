<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Models\Category;
use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\PopulateAniListService as PaList;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class AnimeProcessor
{
    private const string CLEANING_CDEF = 'ssize_t nntmux_anime_fix_encoding(const char *text, size_t len, char *out, size_t cap);';

    private static FFI|false|null $cleaningFfi = null;

    private const PROC_EXTFAIL = -1; // Release Anime title/episode # could not be extracted from searchname

    private const PROC_NOMATCH = -2; // AniList ID was not found in anidb table using extracted title

    /** @var bool Whether to echo messages to CLI */
    public bool $echooutput;

    public PaList $palist;

    /** @var int|null number of AniDB releases to process */
    private ?int $aniqty = null;

    /** @var int|null The status of the release being processed */
    private ?int $status;

    /**
     * Simple cache of looked up titles -> anidbid to reduce repeat queries within one run.
     *
     * @var array<string,int>
     */
    private array $titleCache = [];

    /**
     * Simple cache of looked up titles -> anilist_id to reduce repeat queries within one run.
     *
     * @var array<string,int>
     *
     * @phpstan-ignore property.onlyWritten
     */
    private array $anilistIdCache = [];

    /**
     * @throws \Exception
     */
    public function __construct(bool $echooutput = true)
    {
        $this->echooutput = $echooutput && (bool) config('nntmux.echocli');
        $this->palist = new PaList;
        $this->status = null;
    }

    private function getAnimeQuantity(): int
    {
        if ($this->aniqty === null) {
            $quantity = (int) app(ConfigurationProvider::class)->metadata()->maxAnimeProcessed;
            $this->aniqty = $quantity > 0 ? $quantity : 100;
        }

        return $this->aniqty;
    }

    /**
     * Main entry point for processing anime releases.
     *
     * @param  string  $groupID  (Optional) ID of a group to work on.
     * @param  string  $guidChar  (Optional) First letter of a release GUID to use to get work.
     *
     * @throws \Exception
     */
    public function process(string $groupID = '', string $guidChar = ''): void
    {
        if ((int) app(ConfigurationProvider::class)->metadata()->animeLookup->value === 0) {
            return;
        }

        $this->processAnimeReleases($groupID, $guidChar);
    }

    /**
     * Queues anime releases for processing.
     *
     * @param  string  $groupID  (Optional) ID of a group to work on.
     * @param  string  $guidChar  (Optional) First letter of a release GUID to use to get work.
     *
     * @throws \Exception
     */
    public function processAnimeReleases(string $groupID = '', string $guidChar = ''): void
    {
        $query = Release::query()
            ->whereNull('anidbid')
            ->where('categories_id', Category::TV_ANIME);
        Release::whereNzbSettled($query);

        if ($guidChar !== '') {
            $query->where('leftguid', 'like', $guidChar.'%');
        }

        if ($groupID !== '') {
            $query->where('groups_id', $groupID);
        }

        $results = $query->orderByDesc('postdate')
            ->limit($this->getAnimeQuantity())
            ->get();

        if ($results->count() > 0) {
            // AniList rate limiting is handled internally in PopulateAniList

            foreach ($results as $release) {
                $matched = $this->matchAnimeRelease($release);
                if ($matched === false) {
                    // Persist status so we do not keep retrying hopeless releases immediately.
                    $this->updateRelease($this->status, (int) $release->id, (string) $release->searchname);
                }
            }
        } else {
            cli()->info('No anidb releases to process.');
        }
    }

    /**
     * Extracts anime title from release searchname.
     * Returns ['title' => string] on success else empty array.
     * Note: AniList doesn't support episode lookups, so we only extract the title.
     *
     * @return array<string, mixed>
     */
    private function extractTitleEpisode(string $cleanName = ''): array
    {
        $rustTitle = RustPipelineLibrary::animeExtractTitle($cleanName);
        if ($rustTitle !== null) {
            if ($rustTitle === '') {
                $this->status = self::PROC_EXTFAIL;

                return [];
            }

            return ['title' => $rustTitle];
        }

        // Fix UTF-8 encoding issues (double-encoding, corrupted sequences)
        $s = $this->fixEncoding($cleanName);

        // Normalize common separators
        $s = str_replace(['_', '.'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', (string) $s);
        $s = trim((string) $s);

        // Strip leading group tags like [Group]
        $s = preg_replace('/^(?:\[[^\]]+\]\s*)+/', '', $s);
        $s = trim((string) $s);

        // Remove language codes and tags (before extracting title)
        // Common language tags: [ENG], [JAP], [SUB], [DUB], [MULTI], etc.
        $s = preg_replace('/\[(?:ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR)\]/i', ' ', $s);
        $s = preg_replace('/\((?:ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR)\)/i', ' ', $s);

        // Remove episode patterns and extract title
        $title = '';

        // Try to extract title by removing episode patterns
        // 1) Look for " S01E01" or " S1E1" pattern
        if (preg_match('/\sS\d+E\d+/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        }
        // 2) Look for " 1x18" or " 2x05" pattern (season x episode)
        elseif (preg_match('/\s\d+x\d+/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        }
        // 3) Look for " - NNN" and extract title before it
        elseif (preg_match('/\s-\s*(\d{1,3})\b/', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        }
        // 4) If not found, look for " E0*NNN" or " Ep NNN"
        elseif (preg_match('/\sE(?:p(?:isode)?)?\s*0*(\d{1,3})\b/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        }
        // 4) Keywords Movie/OVA/Complete Series
        elseif (preg_match('/\b(Movie|OVA|Complete Series|Complete|Full Series)\b/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        }
        // 5) BD/resolution releases: pick title before next bracket token
        elseif (preg_match('/\[(?:BD|BDRip|BluRay|Blu-Ray|\d{3,4}[ipx]|HEVC|x264|x265|H264|H265)\]/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $title = substr($s, 0, (int) $m[0][1]);
        } else {
            // No episode pattern found, use the whole string as title
            $title = $s;
        }

        $title = $this->cleanTitle((string) $title);

        if ($title === '') {
            $this->status = self::PROC_EXTFAIL;

            return [];
        }

        return ['title' => $title];
    }

    /**
     * Fix UTF-8 encoding issues in strings (double-encoding, corrupted sequences).
     */
    private function fixEncoding(string $text): string
    {
        // The full caller misses the mean/p99 gate; keep Unicode cleanup in PHP.
        return $this->fixEncodingPhp($text);
    }

    /** Dependency-free Rust declines inputs requiring Unicode NFC normalization. */
    public function fixEncodingNative(string $text): ?string
    {
        if (self::$cleaningFfi === null) {
            self::$cleaningFfi = RustPipelineLibrary::resolveDomain('anime_fix_encoding', self::CLEANING_CDEF) ?? false;
        }
        $ffi = self::$cleaningFfi;
        if ($ffi === false) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($text) + 1, $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $written = $ffi->nntmux_anime_fix_encoding($text, \strlen($text), $scratch, $cap);
            if ($written < 0) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Exact PHP reference and fallback, including literal HTML entity handling. */
    public function fixEncodingPhp(string $text): string
    {
        // Remove common corrupted character sequences (encoding artifacts)
        // Pattern: Ã¢Â_Â, Ã¢Â Â, Ã¢Â, etc.
        $text = preg_replace('/Ã¢Â[_\sÂ]*/u', '', $text);
        $text = preg_replace('/Ã[¢Â©€£]/u', '', $text);

        // Remove standalone Â characters (common encoding artifact)
        $text = preg_replace('/Â+/u', '', $text);

        // Remove any remaining Ã sequences (encoding artifacts)
        $text = preg_replace('/Ã[^\s]*/u', '', $text);

        // Try to detect and fix double-encoding issues
        // Common patterns: Ã©, Ã, etc. (UTF-8 interpreted as ISO-8859-1)
        if (preg_match('/Ã[^\s]/u', $text)) {
            // Try ISO-8859-1 -> UTF-8 conversion (common double-encoding fix)
            $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            if ($converted !== false && ! preg_match('/Ã[^\s]/u', $converted)) {
                $text = $converted;
            }
        }

        // Remove any remaining non-printable or control characters except spaces
        $text = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Normalize Unicode (NFD -> NFC) if available
        if (function_exists('normalizer_normalize')) {
            $text = normalizer_normalize($text, \Normalizer::FORM_C);
        }

        // Final cleanup: remove any remaining isolated non-ASCII control-like characters
        // This catches any remaining encoding artifacts
        $text = preg_replace('/[\xC0-\xC1\xC2-\xC5]/u', '', $text);

        return $text;
    }

    /**
     * Strip stray separators, language codes, episode numbers, and other release tags from title.
     */
    private function cleanTitle(string $title): string
    {
        $rustTitle = RustPipelineLibrary::animeCleanTitle($title);
        if ($rustTitle !== null) {
            return $rustTitle;
        }

        // Fix encoding issues first
        $title = $this->fixEncoding($title);

        // Remove all bracketed tags (language, quality, etc.)
        $title = preg_replace('/\[[^\]]+\]/', ' ', $title);

        // Remove all parenthesized tags
        $title = preg_replace('/\([^)]+\)/', ' ', $title);

        // Remove language codes (standalone or with separators)
        $title = preg_replace('/\b(ENG|JAP|JPN|SUB|DUB|MULTI|RAW|HARDSUB|SOFTSUB|HARDDUB|SOFTDUB|ITA|SPA|FRE|GER|RUS|CHI|KOR)\b/i', ' ', $title);

        // Remove metadata words (JAV, Uncensored, Censored, etc.)
        $title = preg_replace('/\b(JAV|Uncensored|Censored|Mosaic|Mosaic-less|HD|SD|FHD|UHD)\b/i', ' ', $title);

        // Remove date patterns (6-digit dates like 091919, 200101, etc.)
        $title = preg_replace('/\b\d{6}\b/', ' ', $title);

        // Remove trailing numbers/underscores (like _01, 01, _001, etc.)
        $title = preg_replace('/[-_]\s*\d{1,4}\s*$/i', '', $title);
        $title = preg_replace('/\s+\d{1,4}\s*$/i', '', $title);

        // Remove episode patterns (including episode titles that follow)
        // Remove " - 1x18 - Episode Title" or " - 1x18" patterns
        $title = preg_replace('/\s*-\s*\d+x\d+.*$/i', '', $title);
        // Remove " S01E01" or " S1E1" pattern
        $title = preg_replace('/\s+S\d+E\d+.*$/i', '', $title);
        // Remove " - NNN" or " - NNN - Episode Title" patterns
        $title = preg_replace('/\s*-\s*\d{1,4}(?:\s*-\s*.*)?\s*$/i', '', $title);
        $title = preg_replace('/\s*-\s*$/i', '', $title);
        // Remove " E0*NNN" or " Ep NNN" patterns
        $title = preg_replace('/\s+E(?:p(?:isode)?)?\s*0*\d{1,4}\s*$/i', '', $title);

        // Remove quality/resolution tags
        $title = preg_replace('/\b(480p|720p|1080p|2160p|4K|BD|BDRip|BluRay|Blu-Ray|HEVC|x264|x265|H264|H265|WEB|WEBRip|DVDRip|TVRip)\b/i', ' ', $title);

        // Remove common release tags
        $title = preg_replace('/\b(PROPER|REPACK|RIP|ISO|CRACK|BETA|ALPHA|FINAL|COMPLETE|FULL)\b/i', ' ', $title);

        // Remove volume/chapter markers
        $title = preg_replace('/\s+Vol\.?\s*\d*\s*$/i', '', $title);
        $title = preg_replace('/\s+Ch\.?\s*\d*\s*$/i', '', $title);

        // Remove trailing dashes and separators
        $title = preg_replace('/\s*[-_]\s*$/', '', $title);

        // Normalize whitespace
        $title = preg_replace('/\s+/', ' ', $title);

        return trim((string) $title);
    }

    /**
     * Retrieve AniList anime by searching for title.
     * First checks local database, then searches AniList API if not found.
     */
    private function getAnidbByName(string $searchName = ''): ?AnidbTitle
    {
        if ($searchName === '') {
            return null;
        }

        $key = strtolower($searchName);

        // Check cache first
        if (isset($this->titleCache[$key])) {
            return AnidbTitle::query()->select(['anidbid', 'title'])->where('anidbid', $this->titleCache[$key])->first();
        }

        // Try exact match in local database first
        $exact = AnidbTitle::query()->whereRaw('LOWER(title) = ?', [$key])->select(['anidbid', 'title'])->first();
        if ($exact) {
            $this->titleCache[$key] = (int) $exact->anidbid;

            return $exact;
        }

        // Try partial match in local database
        $partial = AnidbTitle::query()->where('title', 'like', '%'.$searchName.'%')->select(['anidbid', 'title'])->first();
        if ($partial) {
            $this->titleCache[$key] = (int) $partial->anidbid;

            return $partial;
        }

        // Not found locally, search AniList API
        try {
            $searchResults = $this->palist->searchAnime($searchName, 1);
            if ($searchResults) {
                $anilistData = $searchResults[0]; // @phpstan-ignore offsetAccess.notFound
                $anilistId = $anilistData['id'] ?? null;

                if ($anilistId) {
                    // Use anilist_id as anidbid for new entries
                    $anidbid = AnidbInfo::query()->where('anilist_id', $anilistId)->value('anidbid');

                    if (! $anidbid) {
                        // Create new entry using anilist_id as anidbid
                        $anidbid = (int) $anilistId;
                        $this->palist->populateTable('info', $anilistId);
                    }

                    // Get the title from database after insertion
                    $title = AnidbTitle::query()
                        ->where('anidbid', $anidbid)
                        ->where('lang', 'en')
                        ->value('title');

                    if ($title) {
                        $this->titleCache[$key] = $anidbid;

                        return AnidbTitle::query()
                            ->where('anidbid', $anidbid)
                            ->where('title', $title)
                            ->first();
                    }
                }
            }
        } catch (\Exception $e) {
            if ($this->echooutput) {
                cli()->error('AniList search failed: '.$e->getMessage());
            }
        }

        return null;
    }

    /**
     * Matches the anime release to AniList Info; fetches remotely if needed.
     * Note: AniList doesn't support episode lookups, so we only match by title.
     *
     * @throws \Exception
     */
    private function matchAnimeRelease(mixed $release): bool
    {
        $matched = false;
        $type = 'Local';

        $cleanArr = $this->extractTitleEpisode((string) $release->searchname);
        if (empty($cleanArr)) {
            return false;
        }

        $title = $cleanArr['title'];

        if ($this->echooutput) {
            cli()->info('Looking Up: Title: '.$title);
        }

        $anidbTitle = $this->getAnidbByName($title);
        if (! $anidbTitle) {
            // Try with spaces replaced by % for broader matching
            $tmpName = preg_replace('/\s+/', '%', $title);
            $anidbTitle = $this->getAnidbByName($tmpName);
        }

        if ($anidbTitle && is_numeric($anidbTitle->anidbid) && (int) $anidbTitle->anidbid > 0) {
            $anidbId = (int) $anidbTitle->anidbid;

            // Check if we need to update info from AniList
            $info = AnidbInfo::query()->where('anidbid', $anidbId)->first();
            if (! $info || $this->shouldUpdateInfo($anidbId)) {
                // Try to get anilist_id if we have it
                $anilistId = $info->anilist_id ?? null;

                if (! $anilistId) {
                    // Search AniList for this title
                    try {
                        $searchResults = $this->palist->searchAnime($title, 1);
                        if ($searchResults) {
                            $anilistId = $searchResults[0]['id'] ?? null;
                        }
                    } catch (\Exception $e) {
                        if ($this->echooutput) {
                            cli()->warning('AniList search failed: '.$e->getMessage());
                        }
                    }
                }

                if ($anilistId) {
                    // Fetch remote info from AniList
                    $this->palist->populateTable('info', $anilistId);
                    $type = 'Remote';
                }
            }

            $this->updateRelease($anidbId, (int) $release->id, (string) $release->searchname);

            if ($this->echooutput) {
                cli()->headerOver('Matched '.$type.' AniList ID: ');
                cli()->primary((string) $anidbId);
                cli()->alternateOver('   Title: ');
                cli()->primary($anidbTitle->title);
            }
            $matched = true;
        } else {
            $this->status = self::PROC_NOMATCH;
        }

        return $matched;
    }

    private function updateRelease(?int $anidbId, int $relId, ?string $searchname = null): int
    {
        // Only for the title it was matched on: an upgrade may have renamed it meanwhile.
        return Release::query()->where('id', $relId)
            ->when($searchname !== null, static fn ($q) => $q->where('searchname', $searchname))
            ->update(['anidbid' => $anidbId]);
    }

    /**
     * Determine if we should attempt a remote AniDB info fetch (missing or stale > 1 week).
     */
    private function shouldUpdateInfo(int $anidbId): bool
    {
        $info = AnidbInfo::query()->where('anidbid', $anidbId)->first(['updated']);
        if ($info === null) {
            return true; // no info yet
        }

        return $info->updated < now()->subWeek();
    }

    /** Enabled: paired common and uncommon mean/p99 gains all reach 1.5x (see the W41 parity test). */
    public const bool W414_RUST_FFI_DEFAULT = true;

    public const string W414_FFI_CDEF = <<<'C'
        ssize_t nntmux_w414_anime_title_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W414_SUMMARY_KEYS = ['total_titles', 'empty_title_count', 'fansub_group_count', 'episode_tagged_count', 'batch_tagged_count', 'resolution_tagged_count', 'crc_tagged_count'];

    private const array W414_BATCH_TOKENS = ['BATCH' => true, 'COMPLETE' => true];

    private const array W414_RESOLUTION_TOKENS = ['480P' => true, '540P' => true, '576P' => true, '720P' => true, '1080P' => true, '1440P' => true, '2160P' => true, '4K' => true];

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
     * Summarize anime release titles: leading [group] tags, episode markers
     * (S01E02, E12, EP123 tokens or " - 05"), batch/complete tokens,
     * resolution tokens and bracketed 8-digit hexadecimal CRC tags.
     * Titles come from strings, integers, or the searchname, name or title
     * array field; other values (including objects) count as empty titles.
     * Tokens are maximal ASCII alphanumeric runs compared case-insensitively.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array<string, int>
     */
    public static function summarizeAnimeTitleBatch(array $titles): array
    {
        return self::usesW414RustFfi()
            ? self::summarizeAnimeTitleBatchNative($titles)
            : self::summarizeAnimeTitleBatchPhp($titles);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $titles
     * @return array<string, int>
     */
    public static function summarizeAnimeTitleBatchNative(array $titles): array
    {
        return self::w414Call(self::w414Titles($titles)) ?? self::summarizeAnimeTitleBatchPhp($titles);
    }

    /**
     * @param  array<array-key, mixed>  $titles
     * @return array<string, int>
     */
    public static function summarizeAnimeTitleBatchPhp(array $titles): array
    {
        $summary = array_fill_keys(self::W414_SUMMARY_KEYS, 0);
        foreach (self::w414Titles($titles) as $title) {
            $summary['total_titles']++;
            $title = trim($title);
            if ($title === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $summary['fansub_group_count'] += (int) (preg_match('/^\[[^\]]+\]/', $title) === 1);
            $summary['crc_tagged_count'] += (int) (preg_match('/[\[(][0-9A-Fa-f]{8}[\])]/', $title) === 1);
            $episode = preg_match('/ - \d{1,4}(?!\d)/', $title) === 1;
            $batch = $resolution = false;
            foreach (preg_split('/[^A-Za-z0-9]+/', strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $episode = $episode || preg_match('/^(?:S\d{1,2})?EP?\d{1,4}\z/', $token) === 1;
                $batch = $batch || isset(self::W414_BATCH_TOKENS[$token]);
                $resolution = $resolution || isset(self::W414_RESOLUTION_TOKENS[$token]);
            }
            $summary['episode_tagged_count'] += (int) $episode;
            $summary['batch_tagged_count'] += (int) $batch;
            $summary['resolution_tagged_count'] += (int) $resolution;
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
            $written = $ffi->nntmux_w414_anime_title_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W414_FFI_CDEF.)
                $written = $ffi->nntmux_w414_anime_title_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W414_SUMMARY_KEYS || $result['total_titles'] !== count($titles)) {
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
}
