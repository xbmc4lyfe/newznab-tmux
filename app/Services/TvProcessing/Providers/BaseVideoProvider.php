<?php

declare(strict_types=1);

/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:
 *
 * @link      <http://www.gnu.org/licenses/>.
 *
 * @author    niel
 * @copyright 2015 nZEDb
 */

namespace App\Services\TvProcessing\Providers;

use App\Models\TvInfo;
use App\Models\Video;
use App\Models\VideoAlias;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Parent class for TV/Film and any similar classes to inherit from.
 */
abstract class BaseVideoProvider
{
    // Video Type Identifiers
    public const TYPE_TV = 0; // Type of video is a TV Programme/Show

    public const TYPE_FILM = 1; // Type of video is a Film/Movie

    public const TYPE_ANIME = 2; // Type of video is a Anime

    public bool $echooutput;

    /**
     * @var list<string> The sites that we have an ID columns for in our video table.
     */
    private static array $sites = ['imdb', 'tmdb', 'trakt', 'tvdb', 'tvmaze', 'tvrage'];

    // Keep production normalization in PHP; the paired common-path gate failed.
    // *Native entry points remain available for differential testing.
    private const string NATIVE_CDEF = <<<'C'
        ssize_t nntmux_imdb_search_text(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        C;

    private ?FFI $native = null;

    private bool $nativeResolved = false;

    /** Wave 49.3 batch default (benchmark-gated; see tests/Unit/RustTvPipesTraktTvMazeTvdbAbstractBaseProviderW49ParityTest.php). */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        ssize_t nntmux_w49_video_title_plan_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49 Rust path forced on or off, then restores the previous setting. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /**
     * Four values per title, planning the getByTitle() lookups: a mask (1 non-empty title, 2 a
     * trailing `(YYYY)` year strips, 4 an ` and ` conjunction rewrites, 8 an `er` spelling rewrites,
     * 16 a loose LIKE lookup runs), the space-separated word count, the FNV-1a hash of the title
     * without its year (`/^(.+?)\s*\(\d{4}\)$/D`, trimmed) and the FNV-1a hash of the loose
     * patterns tried, joined by 0x1F. Then the loose count, the exact-only count (non-empty titles
     * without a loose lookup) and the max word count.
     *
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function w49TitlePlanBatch(array $titles): array
    {
        return self::usesW49RustFfi() ? self::w49TitlePlanBatchNative($titles) : self::w49TitlePlanBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function w49TitlePlanBatchNative(array $titles): array
    {
        $count = \count($titles);

        return self::w49Invoke(implode("\0", $titles), '', $count, $count * 4 + 3)
            ?? self::w49TitlePlanBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function w49TitlePlanBatchPhp(array $titles): array
    {
        $out = [];
        $looseCount = 0;
        $exactOnly = 0;
        $maxWords = 0;
        foreach ($titles as $title) {
            $title = (string) $title;
            $stripped = preg_match('/^(.+?)\s*\(\d{4}\)$/D', $title, $m) === 1 ? trim($m[1]) : null;
            $conjunction = str_ireplace(' and ', ' & ', $title);
            $spelling = str_ireplace('er', 're', $title);
            $loose = [];
            if ($conjunction !== $title) {
                $loose[] = self::w49LoosePattern($conjunction);
            }
            if ($spelling !== $title) {
                $loose[] = self::w49LoosePattern($spelling);
            } elseif (str_contains($title, ' ')) {
                $loose[] = self::w49LoosePattern($title);
            }
            $mask = ($title !== '' ? 1 : 0)
                | ($stripped !== null ? 2 : 0)
                | ($conjunction !== $title ? 4 : 0)
                | ($spelling !== $title ? 8 : 0)
                | ($loose !== [] ? 16 : 0);
            $words = substr_count($title, ' ') + 1;
            if ($loose !== []) {
                $looseCount++;
            } elseif ($title !== '') {
                $exactOnly++;
            }
            $maxWords = max($maxWords, $words);
            array_push($out, $mask, $words, self::w49Fnv($stripped ?? $title), self::w49Fnv(implode("\x1F", $loose)));
        }
        array_push($out, $looseCount, $exactOnly, $maxWords);

        return $out;
    }

    /** loosePattern(), without the instance. */
    private static function w49LoosePattern(string $title): string
    {
        return '%'.str_replace([' ', "'", '!'], ['%', '', ''], $title).'%';
    }

    private static function w49Fnv(string $bytes): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('N', hash('fnv1a32', $bytes, true));

        return $digest[1];
    }

    /**
     * Calls the Wave 49 export with NUL-joined row-major strings and packed `i64` integers. Null
     * when the library is missing or the call declines (for example a string holding a NUL byte),
     * so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w49Invoke(string $blob, string $ints, int $count, int $words): ?array
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w49', self::W49_FFI_CDEF) ?? false;
        $ffi = self::$w49Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w49Out === null || self::$w49OutCap < $words) {
                self::$w49OutCap = max(1024, $words * 2);
                self::$w49Out = $ffi->new('int64_t['.self::$w49OutCap.']');
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_video_title_plan_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w49Out, self::$w49OutCap);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w49Out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(private readonly bool $useRustFfi = true)
    {
        $this->echooutput = (bool) config('nntmux.echocli');
    }

    /**
     * @return false|mixed
     */
    public function getSiteIDFromVideoID(string $siteColumn, int $videoID): mixed
    {
        if (\in_array($siteColumn, self::$sites, true)) {
            $result = Video::query()->where('id', $videoID)->first([$siteColumn]);

            return $result !== null ? $result[$siteColumn] : false;
        }

        return false;
    }

    /**
     * Get TV show local timezone from a Video ID.
     *
     * @return string Empty string if no query return or tz style timezone
     */
    public function getLocalZoneFromVideoID(int $videoID): string
    {
        $zone = trim((string) TvInfo::query()->where('videos_id', $videoID)->value('localzone'));

        return $zone === "''" ? '' : $zone;
    }

    /**
     * Get video info from a Site ID and column.
     */
    protected function getVideoIDFromSiteID(string $siteColumn, int $siteID): bool|int
    {
        if ($siteID === 0 || ! \in_array($siteColumn, self::$sites, true)) {
            return false;
        }

        $videoId = Video::query()->where($siteColumn, $siteID)->orderBy('id')->value('id');

        return $videoId !== null ? (int) $videoId : false;
    }

    public function getByTitle(string $title, int $type, int $source = 0): int
    {
        // Check if we already have an entry for this show.
        $res = $this->getTitleExact($title, $type, $source);
        if ($res !== 0) {
            return $res;
        }

        // If title contains a year in parentheses, try without the year
        $titleWithoutYear = $this->titleWithoutYear($title);
        if ($titleWithoutYear !== $title) {
            $res = $this->getTitleExact($titleWithoutYear, $type, $source);
            if ($res !== 0) {
                return $res;
            }
        }

        // Check alt. title (Strip ' and :) Maybe strip more in the future.
        $res = $this->getAlternativeTitleExact($title, $type, $source);
        if ($res !== 0) {
            return $res;
        }

        $title2 = $this->normalizeTitleConjunction($title);
        if ($title !== $title2) {
            $res = $this->getTitleExact($title2, $type, $source);
            if ($res !== 0) {
                return $res;
            }
            $res = $this->getTitleLoose($this->loosePattern($title2), $type, $source);
            if ($res !== 0) {
                return $res;
            }
        }

        // Some words are spelled correctly 2 ways
        // example theatre and theater
        $title2 = $this->normalizeTitleSpelling($title);
        if ($title !== $title2) {
            $res = $this->getTitleExact($title2, $type, $source);
            if ($res !== 0) {
                return $res;
            }
            $res = $this->getTitleLoose($this->loosePattern($title2), $type, $source);
            if ($res !== 0) {
                return $res;
            }
        } elseif (\count(explode(' ', $title)) > 1) {
            // If there was not an exact title match, look for title with missing chars
            // example release name :Zorro 1990, tvrage name Zorro (1990)
            // Only search if the title contains more than one word to prevent incorrect matches
            $res = $this->getTitleLoose($this->loosePattern($title), $type, $source);
            if ($res !== 0) {
                return $res;
            }
        }

        return 0;
    }

    private function titleWithoutYear(string $title): string
    {
        return preg_match('/^(.+?)\s*\(\d{4}\)$/', $title, $match) === 1 ? trim($match[1]) : $title;
    }

    private function normalizeTitleConjunction(string $title): string
    {
        return str_ireplace(' and ', ' & ', $title);
    }

    private function normalizeTitleSpelling(string $title): string
    {
        return str_ireplace('er', 're', $title);
    }

    private function titleNativeOutput(string $title, int $mode): ?string
    {
        if (! $this->useRustFfi) {
            return null;
        }
        if (! $this->nativeResolved) {
            $this->native = RustPipelineLibrary::resolveDomain('media', self::NATIVE_CDEF);
            $this->nativeResolved = true;
        }
        $ffi = $this->native;
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, strlen($title) + 2, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_imdb_search_text($title, strlen($title), $mode, $scratch, $capacity);

            return $written < 0 ? null : ($written === 0 ? '' : FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    public function loosePatternNative(string $title): string
    {
        return $this->titleNativeOutput($title, 3) ?? $this->loosePattern($title);
    }

    public function titleWithoutYearNative(string $title): string
    {
        return $this->titleNativeOutput($title, 4) ?? $this->titleWithoutYear($title);
    }

    public function normalizeTitleConjunctionNative(string $title): string
    {
        return $this->titleNativeOutput($title, 5) ?? $this->normalizeTitleConjunction($title);
    }

    public function normalizeTitleSpellingNative(string $title): string
    {
        return $this->titleNativeOutput($title, 6) ?? $this->normalizeTitleSpelling($title);
    }

    private function loosePattern(string $title): string
    {
        $pattern = '%';
        foreach (explode(' ', $title) as $piece) {
            $pattern .= str_ireplace(["'", '!'], '', $piece).'%';
        }

        return $pattern;
    }

    public function getTitleExact(string $title, int $type, int $source = 0): int
    {
        if ($title === '') {
            return 0;
        }

        $videoId = Video::query()
            ->where(['title' => $title, 'type' => $type])
            ->when($source > 0, fn (Builder $query) => $query->where('source', $source))
            ->orderBy('id')
            ->value('id');

        if ($videoId === null) {
            $videoId = Video::query()
                ->join('videos_aliases', 'videos.id', '=', 'videos_aliases.videos_id')
                ->where(['videos_aliases.title' => $title, 'videos.type' => $type])
                ->when($source > 0, fn (Builder $query) => $query->where('videos.source', $source))
                ->orderBy('videos.id')
                ->value('videos.id');
        }

        return (int) $videoId;
    }

    /**
     * Match a LIKE pattern against titles and aliases, preferring the shortest (tightest) title.
     */
    public function getTitleLoose(mixed $title, mixed $type, int $source = 0): int
    {
        $pattern = rtrim((string) $title, '%');
        if ($pattern === '') {
            return 0;
        }

        $videoId = Video::query()
            ->where('title', 'like', $pattern)
            ->where('type', $type)
            ->when($source > 0, fn (Builder $query) => $query->where('source', $source))
            ->orderByRaw('LENGTH(title)')
            ->value('id');

        if ($videoId === null) {
            $videoId = Video::query()
                ->join('videos_aliases', 'videos.id', '=', 'videos_aliases.videos_id')
                ->where('videos_aliases.title', 'like', $pattern)
                ->where('videos.type', $type)
                ->when($source > 0, fn (Builder $query) => $query->where('videos.source', $source))
                ->orderByRaw('LENGTH(videos_aliases.title)')
                ->value('videos.id');
        }

        return (int) $videoId;
    }

    /**
     * Match a title against stored titles with apostrophes or colons removed.
     */
    public function getAlternativeTitleExact(string $title, int $type, int $source = 0): int
    {
        if ($title === '') {
            return 0;
        }

        return (int) Video::query()
            ->where('type', $type)
            ->when($source > 0, fn (Builder $query) => $query->where('source', $source))
            ->where(fn (Builder $query) => $query
                ->whereRaw("REPLACE(title, '''', '') = ?", [$title])
                ->orWhereRaw("REPLACE(title, ':', '') = ?", [$title]))
            ->orderBy('id')
            ->value('id');
    }

    /**
     * Inserts the aliases a video doesn't have yet.
     *
     * @param  array<int|string, mixed>  $aliases  Strings, or TVMaze style ['name' => ...] entries
     */
    public function addAliases(mixed $videoId, array $aliases = []): void
    {
        $videoId = (int) $videoId;
        if ($videoId <= 0 || $aliases === []) {
            return;
        }

        $titles = [];
        foreach ($aliases as $alias) {
            if (\is_array($alias)) {
                $alias = $alias['name'] ?? '';
            }
            if (! \is_string($alias)) {
                continue;
            }
            $alias = mb_substr(trim($alias), 0, 180);
            if ($alias !== '') {
                $titles[mb_strtolower($alias)] ??= $alias;
            }
        }

        if ($titles === []) {
            return;
        }

        $now = now();
        VideoAlias::query()->insertOrIgnore(array_map(
            static fn (string $alias): array => ['videos_id' => $videoId, 'title' => $alias, 'created_at' => $now, 'updated_at' => $now],
            array_values($titles),
        ));

        Cache::forget(md5((string) $videoId));
    }

    /**
     * Retrieves all aliases for a video, the aliases matching a title, or both.
     *
     * @return Collection<int, VideoAlias>|false
     */
    public function getAliases(int $videoId, string $alias = ''): Collection|false
    {
        if ($videoId <= 0 && $alias === '') {
            return false;
        }

        /** @var Collection<int, VideoAlias> $aliases */
        $aliases = Cache::remember(
            md5($videoId.$alias),
            now()->addMinutes((int) config('nntmux.cache_expiry_medium')),
            static fn (): Collection => VideoAlias::query()
                ->when($videoId > 0, fn (Builder $query) => $query->where('videos_id', $videoId))
                ->when($alias !== '', fn (Builder $query) => $query->where('title', $alias))
                ->get(),
        );

        return $aliases->isEmpty() ? false : $aliases;
    }

    // ========================================================================
    // WAVE 63.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.03x, p99 5.15x; uncommon mean 5.62x, p99 4.57x); see RustTvBaseVideoLocalDbTraktProviderContextAdderW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_BASE_VIDEO_PROVIDER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w633_base_video_provider_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 63.3 batch. */
    public const int W63_BASE_VIDEO_PROVIDER_TRAILER = 20;

    private const string W63_CONTROL_CHARS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** BaseVideoProvider::$sites in order, for the Wave 63.3 site column index. */
    private const array W63_SITES = ['imdb', 'tmdb', 'trakt', 'tvdb', 'tvmaze', 'tvrage'];

    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores W63_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the BaseVideoProvider Wave 63 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 63.3 getByTitle()/site-id lookup plans. Each item is `title|year|country|candidateTitle|candidateAlias|
     * candidateYear|candidateCountry|siteColumn|siteId`. The title is trimmed; a trailing `(YYYY)` (19xx/20xx) after at
     * least one byte strips (rtrim) to the base and supplies the year when the year field is not a 19xx/20xx year. Per item
     * `titleState | suffix << 2 | conjunction << 3 | spelling << 4 | loose << 5 | kind << 6 | yearCode << 9 |
     * countryCode << 11 | column << 13 | siteState << 16 | route << 18 | decision << 20 | min(score, 255) << 22 |
     * min(words, 255) << 30 | min(baseLength, 255) << 38 | min(baseTokens, 255) << 46`: the title state is 0 empty,
     * 1 up to 255 bytes without control bytes, 2 otherwise; conjunction and spelling mark a caseless ` and ` or `er`;
     * loose marks either or a space (the getByTitle() loose lookups); words count spaces plus one (0 when empty); base
     * tokens are lowercase `[a-z0-9]+` runs other than `and`. The kind is 0 without a valid title or with both
     * candidates empty, 1 the base equals the trimmed candidate title, 2 it equals the trimmed alias, then (with base
     * tokens) 3 equal title tokens, 4 equal alias tokens, 5 base tokens in order within either, else 6. The year code
     * is 0 unless both are years, then 1 equal, 2 one apart, 3 conflicting; the country code is 0 unless both trimmed
     * fields are two letters, then 1 equal (any case) or 2 differing. The column is 0 empty, 1-6 a `$sites` entry or 7
     * other; the site state is 0 empty, 1 a positive 1-10 digit id (or `tt` and 7-10 digits for imdb), 2 invalid; the
     * route is 1 a site lookup (column 1-6 and a valid id), 2 a title lookup (valid title), 0 nothing (empty title,
     * column and id), else 3. The score is 100/95/90/85/60 for kinds 1-5 + 10/5 for year codes 1/2 + 5 for an equal
     * country - 30 for a year conflict - 15 for a country conflict, at least 0; the decision is 2 from 100, 1 from 70,
     * else 0. Then the seven kind, three decision and four route counts, the distinct base token keys of valid titles,
     * the 1-based index of the first best score (0 when empty), that score, the score sum, the loose count and the
     * suffix count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63BaseVideoProviderPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63BaseVideoProviderPlanBatchNative($items) : self::w63BaseVideoProviderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63BaseVideoProviderPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_BASE_VIDEO_PROVIDER_TRAILER) ?? self::w63BaseVideoProviderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63BaseVideoProviderPlanBatchPhp(array $items): array
    {
        $out = [];
        $kinds = [0, 0, 0, 0, 0, 0, 0];
        $decisions = [0, 0, 0];
        $routes = [0, 0, 0, 0];
        $keys = [];
        $best = $bestScore = $scoreSum = $looses = $suffixes = $index = 0;
        foreach ($items as $item) {
            $index++;
            [$rawTitle, $rawYear, $rawCountry, $rawCandidate, $rawAlias, $rawCandidateYear, $rawCandidateCountry, $rawColumn, $rawSite]
                = explode('|', (string) $item, 9) + ['', '', '', '', '', '', '', '', ''];
            $t = trim($rawTitle);
            $titleState = self::w63TextState($t);
            $n = \strlen($t);
            $suffixYear = $n >= 7 && $t[$n - 1] === ')' && $t[$n - 6] === '(' ? self::w63Year(substr($t, $n - 5, 4)) : null;
            $suffix = $suffixYear !== null ? 1 : 0;
            $base = $suffix === 1 ? rtrim(substr($t, 0, $n - 6)) : $t;
            $conjunction = stripos($t, ' and ') !== false ? 1 : 0;
            $spelling = stripos($t, 'er') !== false ? 1 : 0;
            $loose = $conjunction === 1 || $spelling === 1 || str_contains($t, ' ') ? 1 : 0;
            $words = $n === 0 ? 0 : substr_count($t, ' ') + 1;
            $a = self::w63VideoTokens($base);
            $candidate = trim($rawCandidate);
            $alias = trim($rawAlias);
            if ($titleState !== 1 || ($candidate === '' && $alias === '')) {
                $kind = 0;
            } elseif ($base === $candidate) {
                $kind = 1;
            } elseif ($base === $alias) {
                $kind = 2;
            } elseif ($a === []) {
                $kind = 6;
            } else {
                $c = self::w63VideoTokens($candidate);
                $l = self::w63VideoTokens($alias);
                $kind = $a === $c ? 3 : ($a === $l ? 4 : (self::w63Subsequence($a, $c) || self::w63Subsequence($a, $l) ? 5 : 6));
            }
            $yearCode = self::w63YearCode(self::w63Year(trim($rawYear)) ?? $suffixYear, self::w63Year(trim($rawCandidateYear)));
            $countryCode = self::w63CountryCode($rawCountry, $rawCandidateCountry);
            $columnText = trim($rawColumn);
            $position = array_search($columnText, self::W63_SITES, true);
            $column = $columnText === '' ? 0 : ($position === false ? 7 : $position + 1);
            $siteState = $column === 1 ? self::w63ImdbState($rawSite) : self::w63IdState($rawSite)[0];
            $route = match (true) {
                $column >= 1 && $column <= 6 && $siteState === 1 => 1,
                $titleState === 1 => 2,
                $n === 0 && $column === 0 && $siteState === 0 => 0,
                default => 3,
            };
            $score = max(0, [0, 100, 95, 90, 85, 60, 0][$kind] + [0, 10, 5, 0][$yearCode] + ($countryCode === 1 ? 5 : 0)
                - ($yearCode === 3 ? 30 : 0) - ($countryCode === 2 ? 15 : 0));
            $decision = $score >= 100 ? 2 : ($score >= 70 ? 1 : 0);
            $kinds[$kind]++;
            $decisions[$decision]++;
            $routes[$route]++;
            if ($best === 0 || $score > $bestScore) {
                $best = $index;
                $bestScore = $score;
            }
            $scoreSum += $score;
            $looses += $loose;
            $suffixes += $suffix;
            if ($titleState === 1 && $a !== []) {
                $keys[implode(' ', $a)] = true;
            }
            $out[] = $titleState | $suffix << 2 | $conjunction << 3 | $spelling << 4 | $loose << 5 | $kind << 6 | $yearCode << 9
                | $countryCode << 11 | $column << 13 | $siteState << 16 | $route << 18 | $decision << 20 | min($score, 255) << 22
                | min($words, 255) << 30 | min(\strlen($base), 255) << 38 | min(\count($a), 255) << 46;
        }
        array_push($out, ...$kinds, ...$decisions, ...$routes);
        array_push($out, \count($keys), $best, $bestScore, $scoreSum, $looses, $suffixes);

        return $out;
    }

    /**
     * Title tokens other than `and`.
     *
     * @return list<string>
     */
    private static function w63VideoTokens(string $text): array
    {
        return array_values(array_diff(self::w63Tokens($text), ['and']));
    }

    /**
     * Every token of $a appears in $b in order.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function w63Subsequence(array $a, array $b): bool
    {
        $j = 0;
        $m = \count($b);
        foreach ($a as $token) {
            while ($j < $m && $b[$j] !== $token) {
                $j++;
            }
            if ($j === $m) {
                return false;
            }
            $j++;
        }

        return true;
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w63Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /** A 19xx/20xx four-digit year, else null. */
    private static function w63Year(string $text): ?int
    {
        return \strlen($text) === 4 && (str_starts_with($text, '19') || str_starts_with($text, '20')) ? self::w63Digits($text, 4, 4) : null;
    }

    /** 0 empty, 1 up to 255 bytes without C0 controls or DEL, 2 otherwise. */
    private static function w63TextState(string $text): int
    {
        $length = \strlen($text);

        return $length === 0 ? 0 : ($length <= 255 && strcspn($text, self::W63_CONTROL_CHARS) === $length ? 1 : 2);
    }

    /** Two ASCII letters. */
    private static function w63Country2(string $text): bool
    {
        return \strlen($text) === 2 && strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') === 2;
    }

    /**
     * Lowercase `[a-z0-9]+` tokens.
     *
     * @return list<string>
     */
    private static function w63Tokens(string $text): array
    {
        return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** 0 unless both years are set, then 1 equal, 2 one apart or 3 conflicting. */
    private static function w63YearCode(?int $a, ?int $b): int
    {
        return $a === null || $b === null ? 0 : ($a === $b ? 1 : (abs($a - $b) === 1 ? 2 : 3));
    }

    /** 0 unless both trimmed fields are two letters, then 1 equal (any case) or 2 differing. */
    private static function w63CountryCode(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);

        return self::w63Country2($a) && self::w63Country2($b) ? (strtolower($a) === strtolower($b) ? 1 : 2) : 0;
    }

    /**
     * (0 empty, 1 a trimmed positive 1-10 digit id, 2 invalid; the id).
     *
     * @return array{int, int}
     */
    private static function w63IdState(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $value = self::w63Digits($text, 1, 10);

        return $value !== null && $value > 0 ? [1, $value] : [2, 0];
    }

    /** As w63IdState(), also accepting `tt` (any case) and 7-10 digits. */
    private static function w63ImdbState(string $text): int
    {
        $text = trim($text);
        if (\strlen($text) >= 2 && strtolower(substr($text, 0, 2)) === 'tt') {
            $value = self::w63Digits(substr($text, 2), 7, 10);

            return $value !== null && $value > 0 ? 1 : 2;
        }

        return self::w63IdState($text)[0];
    }

    /**
     * Wave 63 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('tv_w633', self::W63_BASE_VIDEO_PROVIDER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w63RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w63RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 63.3 BaseVideoProvider export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w63Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w63RustFfiState('ffi');
            $out = self::w63RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w63RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W63_BASE_VIDEO_PROVIDER_FFI_CDEF.)
            $written = $ffi->nntmux_w633_base_video_provider_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
}
