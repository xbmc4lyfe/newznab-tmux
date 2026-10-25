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
}
