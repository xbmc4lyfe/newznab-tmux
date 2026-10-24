<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Models\Category;
use App\Models\Release;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI\CData;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * API v2 representation of a release search result.
 *
 * Replaces the legacy `App\Transformers\ApiTransformer`.
 *
 * Movie/TV-only fields are typed as Optional so they are omitted from the
 * serialised output for releases of other categories — matching the previous
 * Fractal `null()` primitive behaviour.
 */
#[TypeScript]
final class ReleaseData extends Data
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_api_v2_rss_dates_batch(const char *d1_ptr, size_t d1_len, const char *d2_ptr, size_t d2_len, const char *d3_ptr, size_t d3_len, size_t count, uint32_t tz_is_utc, uint8_t *out_buf, size_t out_cap);
        C;

    private static ?bool $useRustFfi = null;

    private static \FFI|false|null $ffi = null;

    private static ?CData $rssBuf = null;

    public static function setUseRustFfi(?bool $useRust): void
    {
        self::$useRustFfi = $useRust;
    }

    public static function usesRustFfi(): bool
    {
        if (self::$useRustFfi !== null) {
            return self::$useRustFfi;
        }

        return self::ffi() !== null;
    }

    public static function ffi(): ?\FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('api_v2_rss_dates', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(
        public string $title,
        public string $guid,
        public string $details,
        public string $url,
        public int $category,
        public ?string $category_name,
        public string $added,
        public int|string|null $size,
        public int|string|null $files,
        public int|string|null $grabs,
        public int|string|null $comments,
        public int|string|null $password,
        public string $usenetdate,
        // Movie/TV optional fields
        public Optional|int|string|null $imdbid = new Optional,
        public Optional|int|string|null $tmdbid = new Optional,
        public Optional|int|string|null $traktid = new Optional,
        // TV-only
        public Optional|string|null $episode_title = new Optional,
        public Optional|string|null $season = new Optional,
        public Optional|string|null $episode = new Optional,
        public Optional|string|null $tvairdate = new Optional,
        public Optional|int|string|null $tvdbid = new Optional,
        public Optional|int|string|null $tvrageid = new Optional,
        public Optional|int|string|null $tvmazeid = new Optional,
        // Partial publications only: verified articles and files present versus declared.
        public Optional|int|null $articles_found = new Optional,
        public Optional|int|null $articles_expected = new Optional,
        public Optional|int|null $files_found = new Optional,
        public Optional|int|null $files_expected = new Optional,
    ) {}

    /**
     * Build a ReleaseData from an Eloquent {@see Release} or stdClass row.
     */
    public static function fromRelease(Release|\stdClass $release, User $user): self
    {
        return new self(...self::toArrayFromRelease($release, $user));
    }

    /**
     * Build a ReleaseData from an associative release row array.
     *
     * @param  array<string, mixed>  $release
     */
    public static function fromArray(
        array $release,
        ?User $user = null,
        ?string $detailsBaseUrl = null,
        ?string $getNzbBaseUrl = null
    ): self {
        if ($user === null) {
            $user = new User;
            $user->api_token = isset($release['api_token']) && is_scalar($release['api_token'])
                ? (string) $release['api_token']
                : '';
        }

        return new self(...self::toArrayFromRelease($release, $user, $detailsBaseUrl, $getNzbBaseUrl));
    }

    /**
     * Build the API array directly for hot search response paths.
     *
     * @param  Release|\stdClass|array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function toArrayFromRelease(
        Release|\stdClass|array $release,
        User $user,
        ?string $detailsBaseUrl = null,
        ?string $getNzbBaseUrl = null
    ): array {
        if (self::$useRustFfi ?? true) {
            $native = self::toArrayFromReleaseNative($release, $user, $detailsBaseUrl, $getNzbBaseUrl);
            if ($native !== null) {
                return $native;
            }
        }

        return self::toArrayFromReleasePhp($release, $user, $detailsBaseUrl, $getNzbBaseUrl);
    }

    /**
     * @param  Release|\stdClass|array<string, mixed>  $release
     * @return array<string, mixed>|null
     */
    public static function toArrayFromReleaseNative(
        Release|\stdClass|array $release,
        User $user,
        ?string $detailsBaseUrl = null,
        ?string $getNzbBaseUrl = null
    ): ?array {
        if (is_array($release)) {
            $dates = self::formatRssDatesNative($release['adddate'] ?? null, $release['postdate'] ?? null);
            if ($dates === null) {
                return null;
            }

            return self::buildReleasePayloadFromArray($release, $user, $dates[0], $dates[1], $detailsBaseUrl, $getNzbBaseUrl);
        }

        $dates = self::formatRssDatesNative($release->adddate ?? null, $release->postdate ?? null);
        if ($dates === null) {
            return null;
        }

        return self::buildReleasePayloadFromObject($release, $user, $dates[0], $dates[1], $detailsBaseUrl, $getNzbBaseUrl);
    }

    /**
     * @param  Release|\stdClass|array<string, mixed>  $release
     * @return array<string, mixed>
     */
    public static function toArrayFromReleasePhp(
        Release|\stdClass|array $release,
        User $user,
        ?string $detailsBaseUrl = null,
        ?string $getNzbBaseUrl = null
    ): array {
        $get = is_array($release)
            ? static fn (string $key, mixed $default = null): mixed => $release[$key] ?? $default
            : static fn (string $key, mixed $default = null): mixed => $release->{$key} ?? $default;

        $categoriesId = (int) $get('categories_id', 0);
        $guid = (string) $get('guid', '');
        $detailsBaseUrl ??= url('/details').'/';
        $getNzbBaseUrl ??= url('/getnzb');

        $base = [
            'title' => (string) $get('searchname', ''),
            'guid' => $guid,
            'details' => $detailsBaseUrl.$guid,
            'url' => $getNzbBaseUrl.'?id='.$guid.'.nzb&r='.$user->api_token,
            'category' => $categoriesId,
            'category_name' => $get('category_name'),
            'added' => Carbon::parse($get('adddate'))->toRssString(),
            'size' => $get('size'),
            'files' => $get('totalpart'),
            'grabs' => self::nullIfZero($get('grabs')),
            'comments' => self::nullIfZero($get('comments')),
            'password' => $get('passwordstatus'),
            'usenetdate' => Carbon::parse($get('postdate'))->toRssString(),
        ];
        $base += self::partialCounts($get);

        if (in_array($categoriesId, Category::MOVIES_GROUP, true)) {
            return $base + [
                'imdbid' => self::nullIfZero($get('imdbid')),
                'tmdbid' => self::nullIfZero($get('tmdbid')),
                'traktid' => self::nullIfZero($get('traktid')),
            ];
        }

        if (in_array($categoriesId, Category::TV_GROUP, true)) {
            return $base + [
                'imdbid' => self::nullIfZero($get('imdb')),
                'tmdbid' => self::nullIfZero($get('tmdb')),
                'traktid' => self::nullIfZero($get('trakt')),
                'episode_title' => $get('title'),
                'season' => $get('series'),
                'episode' => $get('episode'),
                'tvairdate' => $get('firstaired'),
                'tvdbid' => self::nullIfZero($get('tvdb')),
                'tvrageid' => self::nullIfZero($get('tvrage')),
                'tvmazeid' => self::nullIfZero($get('tvmaze')),
            ];
        }

        return $base;
    }

    /**
     * Format `[added, usenetdate]` as RFC-2822 / RSS date strings.
     *
     * @return array{0: string, 1: string}
     */
    public static function formatRssDates(mixed $adddate, mixed $postdate): array
    {
        if (self::$useRustFfi ?? true) {
            $native = self::formatRssDatesNative($adddate, $postdate);
            if ($native !== null) {
                return $native;
            }
        }

        return self::formatRssDatesPhp($adddate, $postdate);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function formatRssDatesNative(mixed $adddate, mixed $postdate): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        if (! is_string($adddate) || ! is_string($postdate) || $adddate === '' || $postdate === '') {
            return self::formatRssDatesPhp($adddate, $postdate);
        }

        $tz = date_default_timezone_get();
        $tzIsUtc = ($tz === 'UTC' || $tz === '+00:00' || $tz === 'Z') ? 1 : 0;
        $buf = self::$rssBuf ??= $ffi->new('uint8_t[96]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_v2_rss_dates_batch(
            $adddate,
            strlen($adddate),
            $postdate,
            strlen($postdate),
            null,
            0,
            2,
            $tzIsUtc,
            $buf,
            96,
        );

        if ($written === 63) {
            $raw = \FFI::string($buf, 63);

            return [substr($raw, 0, 31), substr($raw, 32, 31)];
        }

        return self::formatRssDatesPhp($adddate, $postdate);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function formatRssDatesPhp(mixed $adddate, mixed $postdate): array
    {
        return [
            Carbon::parse($adddate)->toRssString(),
            Carbon::parse($postdate)->toRssString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildReleasePayloadFromObject(
        Release|\stdClass $release,
        User $user,
        string $added,
        string $usenetdate,
        ?string $detailsBaseUrl,
        ?string $getNzbBaseUrl
    ): array {
        $categoriesId = (int) ($release->categories_id ?? 0);
        $guid = (string) ($release->guid ?? '');
        $detailsBaseUrl ??= url('/details').'/';
        $getNzbBaseUrl ??= url('/getnzb');

        $base = [
            'title' => (string) ($release->searchname ?? ''),
            'guid' => $guid,
            'details' => $detailsBaseUrl.$guid,
            'url' => $getNzbBaseUrl.'?id='.$guid.'.nzb&r='.$user->api_token,
            'category' => $categoriesId,
            'category_name' => $release->category_name ?? null,
            'added' => $added,
            'size' => $release->size ?? null,
            'files' => $release->totalpart ?? null,
            'grabs' => self::nullIfZero($release->grabs ?? null),
            'comments' => self::nullIfZero($release->comments ?? null),
            'password' => $release->passwordstatus ?? null,
            'usenetdate' => $usenetdate,
        ];
        if (($release->articles_expected ?? null) !== null) {
            $base += [
                'articles_found' => (int) ($release->articles_found ?? 0),
                'articles_expected' => (int) $release->articles_expected,
                'files_found' => (int) ($release->files_found ?? 0),
                'files_expected' => (int) ($release->files_expected ?? 0),
            ];
        }

        if (in_array($categoriesId, Category::MOVIES_GROUP, true)) {
            return $base + [
                'imdbid' => self::nullIfZero($release->imdbid ?? null),
                'tmdbid' => self::nullIfZero($release->tmdbid ?? null),
                'traktid' => self::nullIfZero($release->traktid ?? null),
            ];
        }

        if (in_array($categoriesId, Category::TV_GROUP, true)) {
            return $base + [
                'imdbid' => self::nullIfZero($release->imdb ?? null),
                'tmdbid' => self::nullIfZero($release->tmdb ?? null),
                'traktid' => self::nullIfZero($release->trakt ?? null),
                'episode_title' => $release->title ?? null,
                'season' => $release->series ?? null,
                'episode' => $release->episode ?? null,
                'tvairdate' => $release->firstaired ?? null,
                'tvdbid' => self::nullIfZero($release->tvdb ?? null),
                'tvrageid' => self::nullIfZero($release->tvrage ?? null),
                'tvmazeid' => self::nullIfZero($release->tvmaze ?? null),
            ];
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private static function buildReleasePayloadFromArray(
        array $release,
        User $user,
        string $added,
        string $usenetdate,
        ?string $detailsBaseUrl,
        ?string $getNzbBaseUrl
    ): array {
        $categoriesId = (int) ($release['categories_id'] ?? 0);
        $guid = (string) ($release['guid'] ?? '');
        $detailsBaseUrl ??= url('/details').'/';
        $getNzbBaseUrl ??= url('/getnzb');

        $base = [
            'title' => (string) ($release['searchname'] ?? ''),
            'guid' => $guid,
            'details' => $detailsBaseUrl.$guid,
            'url' => $getNzbBaseUrl.'?id='.$guid.'.nzb&r='.$user->api_token,
            'category' => $categoriesId,
            'category_name' => $release['category_name'] ?? null,
            'added' => $added,
            'size' => $release['size'] ?? null,
            'files' => $release['totalpart'] ?? null,
            'grabs' => self::nullIfZero($release['grabs'] ?? null),
            'comments' => self::nullIfZero($release['comments'] ?? null),
            'password' => $release['passwordstatus'] ?? null,
            'usenetdate' => $usenetdate,
        ];
        if (($release['articles_expected'] ?? null) !== null) {
            $base += [
                'articles_found' => (int) ($release['articles_found'] ?? 0),
                'articles_expected' => (int) $release['articles_expected'],
                'files_found' => (int) ($release['files_found'] ?? 0),
                'files_expected' => (int) ($release['files_expected'] ?? 0),
            ];
        }

        if (in_array($categoriesId, Category::MOVIES_GROUP, true)) {
            return $base + [
                'imdbid' => self::nullIfZero($release['imdbid'] ?? null),
                'tmdbid' => self::nullIfZero($release['tmdbid'] ?? null),
                'traktid' => self::nullIfZero($release['traktid'] ?? null),
            ];
        }

        if (in_array($categoriesId, Category::TV_GROUP, true)) {
            return $base + [
                'imdbid' => self::nullIfZero($release['imdb'] ?? null),
                'tmdbid' => self::nullIfZero($release['tmdb'] ?? null),
                'traktid' => self::nullIfZero($release['trakt'] ?? null),
                'episode_title' => $release['title'] ?? null,
                'season' => $release['series'] ?? null,
                'episode' => $release['episode'] ?? null,
                'tvairdate' => $release['firstaired'] ?? null,
                'tvdbid' => self::nullIfZero($release['tvdb'] ?? null),
                'tvrageid' => self::nullIfZero($release['tvrage'] ?? null),
                'tvmazeid' => self::nullIfZero($release['tvmaze'] ?? null),
            ];
        }

        return $base;
    }

    /**
     * The found and declared counts of a partially published release; empty for a complete one.
     *
     * @param  callable(string, mixed=): mixed  $get
     * @return array<string, int>
     */
    public static function partialCounts(callable $get): array
    {
        if ($get('articles_expected') === null) {
            return [];
        }

        return ['articles_found' => (int) $get('articles_found'), 'articles_expected' => (int) $get('articles_expected'),
            'files_found' => (int) $get('files_found'), 'files_expected' => (int) $get('files_expected')];
    }

    private static function nullIfZero(mixed $value): mixed
    {
        return ($value !== null && $value !== 0 && $value !== '' && $value !== '0') ? $value : null;
    }
}
