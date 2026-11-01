<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Models\Category;
use App\Models\Release;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * API v2 representation of a single release's full detail payload.
 *
 * Replaces the legacy `App\Transformers\DetailsTransformer`.
 */
#[TypeScript]
final class DetailsData extends Data
{
    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $useRust): void
    {
        self::$useRustFfi = $useRust;
    }

    public static function usesRustFfi(): bool
    {
        if (self::$useRustFfi !== null) {
            return self::$useRustFfi;
        }

        return ReleaseData::ffi() !== null;
    }

    public function __construct(
        public string $title,
        public string $details,
        public string $link,
        public int $category,
        public ?string $category_name,
        public string $added,
        public int|string|null $size,
        public int|string|null $files,
        public int|string|null $grabs,
        public int|string|null $comments,
        public int|string|null $password,
        public string $usenetdate,
        public Optional|int|string|null $imdbid = new Optional,
        public Optional|int|string|null $tmdbid = new Optional,
        public Optional|int|string|null $traktid = new Optional,
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

    public static function fromRelease(Release|\stdClass $release, User $user): self
    {
        return new self(...self::toArrayFromRelease($release, $user));
    }

    /**
     * Build a DetailsData from an associative release row array.
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
     * Build the API array directly for hot details response paths.
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

            return self::buildDetailsPayloadFromArray($release, $user, $dates[0], $dates[1], $detailsBaseUrl, $getNzbBaseUrl);
        }

        $dates = self::formatRssDatesNative($release->adddate ?? null, $release->postdate ?? null);
        if ($dates === null) {
            return null;
        }

        return self::buildDetailsPayloadFromObject($release, $user, $dates[0], $dates[1], $detailsBaseUrl, $getNzbBaseUrl);
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
            'details' => $detailsBaseUrl.$guid,
            'link' => $getNzbBaseUrl.'?id='.$guid.'.nzb&r='.$user->api_token,
            'category' => $categoriesId,
            'category_name' => $get('category_name'),
            'added' => Carbon::parse($get('adddate'))->toRssString(),
            'size' => $get('size'),
            'files' => $get('totalpart'),
            'grabs' => $get('grabs'),
            'comments' => $get('comments'),
            'password' => $get('passwordstatus'),
            'usenetdate' => Carbon::parse($get('postdate'))->toRssString(),
        ];
        $base += ReleaseData::partialCounts($get);

        if (in_array($categoriesId, Category::MOVIES_GROUP, true)) {
            return $base + [
                'imdbid' => $get('imdbid'),
            ];
        }

        if (in_array($categoriesId, Category::TV_GROUP, true)) {
            return $base + [
                'imdbid' => $get('imdb'),
                'tmdbid' => $get('tmdb'),
                'traktid' => $get('trakt'),
                'tvairdate' => $get('firstaired'),
                'tvdbid' => $get('tvdb'),
                'tvrageid' => $get('tvrage'),
                'tvmazeid' => $get('tvmaze'),
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
        return ReleaseData::formatRssDatesNative($adddate, $postdate);
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
    private static function buildDetailsPayloadFromObject(
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
            'details' => $detailsBaseUrl.$guid,
            'link' => $getNzbBaseUrl.'?id='.$guid.'.nzb&r='.$user->api_token,
            'category' => $categoriesId,
            'category_name' => $release->category_name ?? null,
            'added' => $added,
            'size' => $release->size ?? null,
            'files' => $release->totalpart ?? null,
            'grabs' => $release->grabs ?? null,
            'comments' => $release->comments ?? null,
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
                'imdbid' => $release->imdbid ?? null,
            ];
        }

        if (in_array($categoriesId, Category::TV_GROUP, true)) {
            return $base + [
                'imdbid' => $release->imdb ?? null,
                'tmdbid' => $release->tmdb ?? null,
                'traktid' => $release->trakt ?? null,
                'tvairdate' => $release->firstaired ?? null,
                'tvdbid' => $release->tvdb ?? null,
                'tvrageid' => $release->tvrage ?? null,
                'tvmazeid' => $release->tvmaze ?? null,
            ];
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private static function buildDetailsPayloadFromArray(
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
            'details' => $detailsBaseUrl.$guid,
            'link' => $getNzbBaseUrl.'?id='.$guid.'.nzb&r='.$user->api_token,
            'category' => $categoriesId,
            'category_name' => $release['category_name'] ?? null,
            'added' => $added,
            'size' => $release['size'] ?? null,
            'files' => $release['totalpart'] ?? null,
            'grabs' => $release['grabs'] ?? null,
            'comments' => $release['comments'] ?? null,
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
                'imdbid' => $release['imdbid'] ?? null,
            ];
        }

        if (in_array($categoriesId, Category::TV_GROUP, true)) {
            return $base + [
                'imdbid' => $release['imdb'] ?? null,
                'tmdbid' => $release['tmdb'] ?? null,
                'traktid' => $release['trakt'] ?? null,
                'tvairdate' => $release['firstaired'] ?? null,
                'tvdbid' => $release['tvdb'] ?? null,
                'tvrageid' => $release['tvrage'] ?? null,
                'tvmazeid' => $release['tvmaze'] ?? null,
            ];
        }

        return $base;
    }
}
