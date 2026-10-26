<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Models\Category;
use App\Models\Release;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

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

    public static function ffi(): ?FFI
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
            $raw = FFI::string($buf, 63);

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

    // Rust is the default only while the paired W65.6 benchmark clears 1.5x on all four metrics.
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_RELEASE_FFI_CDEF = <<<'C'
        int32_t nntmux_w656_release_data_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W65_RELEASE_HEADER = 24;

    /** Integer columns in the order Rust reads them. */
    public const array W65_RELEASE_COLUMNS = ['categories_id', 'size', 'grabs', 'comments', 'passwordstatus', 'imdbid', 'tmdbid', 'traktid', 'tvdb', 'tvmaze'];

    private const int W65_MAX_ITEMS = 1_048_576;

    /** Size clamp (2^50) and total cap (2^62). */
    private const int W65_SIZE_CAP = 1_125_899_906_842_624;

    private const int W65_SUM_CAP = 4_611_686_018_427_387_904;

    /** `Category::MOVIES_GROUP` ids => 1 and `Category::TV_GROUP` ids => 2. */
    private const array W65_MEDIA_GROUPS = [
        2000 => 1, 2010 => 1, 2030 => 1, 2040 => 1, 2045 => 1, 2050 => 1, 2060 => 1, 2070 => 1, 2080 => 1, 2090 => 1, 2999 => 1,
        5000 => 2, 5010 => 2, 5020 => 2, 5030 => 2, 5040 => 2, 5045 => 2, 5060 => 2, 5070 => 2, 5080 => 2, 5090 => 2, 5999 => 2,
    ];

    /** External ids each group emits: movies IMDb 1, TMDB 2, Trakt 4; TV also TVDB 8 and TVmaze 16. */
    private const array W65_EMITTED_IDS = [0, 0b00111, 0b11111];

    /**
     * Plan release item DTOs: GUIDs, titles and the `categories_id`, `size`, `grabs`, `comments`,
     * `passwordstatus`, `imdbid`, `tmdbid`, `traktid`, `tvdb` and `tvmaze` columns. Group 1 movies, 2 TV, 0
     * other. The id mask holds the external ids the DTO emits for the group that {@see nullIfZero()} keeps
     * (non-zero). The enclosure length is the size clamped to 0..2^50, classed 0 empty, 1 under 100 MiB, 2
     * under 1 GiB, 3 under 10 GiB, 4 larger. Password 0 none, 1 possible, 2 passworded (2 and up), 3 unknown
     * (negative). The escaped title adds 4 bytes per `&`, 3 per `<` or `>` and 5 per `"` or `'`.
     *
     * Layout: `[0]` count, `[1..4]` per group, `[4..9]` per id bit, `[9..14]` per size class, `[14..18]` per
     * password status, `[18]` valid GUIDs, `[19]` enclosure bytes (capped at 2^62), `[20]` escaped title bytes,
     * `[21]` titles needing escapes, `[22]` first invalid GUID index (-1), `[23]` FNV-1a digest; then per item
     * `group | ids << 2 | size class << 7 | password << 10 | grabs << 12 | comments << 13 | valid GUID << 14 |
     * min(escaped title, 2^16-1) << 15 | MiB << 31` (grabs and comments: non-zero).
     *
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, int|string|null>  $titles
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65ReleaseDataPlanBatch(array $guids, array $titles, array $metrics): array
    {
        return (self::usesW65RustFfi() ? self::w65ReleaseDataPlanBatchNative($guids, $titles, $metrics) : null)
            ?? self::w65ReleaseDataPlanBatchPhp($guids, $titles, $metrics);
    }

    /**
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, int|string|null>  $titles
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65ReleaseDataPlanBatchPhp(array $guids, array $titles, array $metrics): array
    {
        $guids = array_values($guids);
        $count = \count($guids);
        $titles = self::w65Column($titles, $count, '');
        [$categories, $sizes, $grabs, $comments, $passwords, $imdb, $tmdb, $trakt, $tvdb, $tvmaze] = self::w65Metrics($metrics, $count);
        $out = array_fill(0, self::W65_RELEASE_HEADER, 0);
        $out[0] = $count;
        $out[22] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($guids as $at => $guid) {
            $guid = (string) $guid;
            $group = self::W65_MEDIA_GROUPS[$categories[$at]] ?? 0;
            $size = max(0, min($sizes[$at], self::W65_SIZE_CAP));
            $password = $passwords[$at] < 0 ? 3 : min($passwords[$at], 2);
            $ids = (($imdb[$at] !== 0 ? 1 : 0) | ($tmdb[$at] !== 0 ? 2 : 0) | ($trakt[$at] !== 0 ? 4 : 0) | ($tvdb[$at] !== 0 ? 8 : 0)
                | ($tvmaze[$at] !== 0 ? 16 : 0)) & self::W65_EMITTED_IDS[$group];
            $class = match (true) {
                $size === 0 => 0,
                $size < 104_857_600 => 1,
                $size < 1_073_741_824 => 2,
                $size < 10_737_418_240 => 3,
                default => 4,
            };
            $title = (string) $titles[$at];
            $length = \strlen($title);
            $escaped = strpbrk($title, '&<>"\'') === false ? $length : $length + 4 * substr_count($title, '&')
                + 3 * (substr_count($title, '<') + substr_count($title, '>')) + 5 * (substr_count($title, '"') + substr_count($title, "'"));
            $valid = \strlen($guid) === 40 && strspn($guid, '0123456789abcdef') === 40;
            $out[1 + $group]++;
            for ($bit = 0; $bit < 5; $bit++) {
                $out[4 + $bit] += $ids >> $bit & 1;
            }
            $out[9 + $class]++;
            $out[14 + $password]++;
            $out[18] += (int) $valid;
            $out[19] = min($out[19] + $size, self::W65_SUM_CAP);
            $out[20] += $escaped;
            $out[21] += (int) ($escaped !== $length);
            if (! $valid && $out[22] < 0) {
                $out[22] = $at;
            }
            $row = $group | $ids << 2 | $class << 7 | $password << 10 | ($grabs[$at] !== 0 ? 4096 : 0) | ($comments[$at] !== 0 ? 8192 : 0)
                | ($valid ? 16384 : 0) | min($escaped, 0xFFFF) << 15 | ($size >> 20) << 31;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[23] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, int|string|null>  $titles
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>|null
     */
    public static function w65ReleaseDataPlanBatchNative(array $guids, array $titles, array $metrics): ?array
    {
        $count = \count($guids);
        if ($count > self::W65_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w65Strings([...array_values($guids), ...self::w65Column($titles, $count, '')]);
        $ints = self::w65Pack($metrics, $count);
        $words = self::W65_RELEASE_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w65Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w656_release_data_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $words));
    }

    /**
     * Route the Wave 65.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /**
     * Route the Wave 65.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 65.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $forced = self::w65RustFfiState();

        return \is_bool($forced) ? $forced : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 65.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.6 routing and binding in function statics, so the class gains no properties. No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('api_w656', self::W65_RELEASE_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 65.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w65Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w65RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string|null>  $items
     * @return array{0: string, 1: string}
     */
    private static function w65Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string|null
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w65Column(array $values, int $count, int|string|null $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }

    /**
     * The named integer columns of {@see W65_RELEASE_COLUMNS}, each cut or padded with 0 to `$count`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<list<int>>
     */
    private static function w65Metrics(array $metrics, int $count): array
    {
        $columns = [];
        foreach (self::W65_RELEASE_COLUMNS as $key) {
            $columns[] = self::w65Column($metrics[$key] ?? [], $count);
        }

        return $columns;
    }

    /**
     * The named integer columns packed one after another as machine-order `int64_t`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     */
    private static function w65Pack(array $metrics, int $count): string
    {
        $packed = '';
        foreach (self::w65Metrics($metrics, $count) as $column) {
            $packed .= pack('q*', ...$column);
        }

        return $packed;
    }
}
