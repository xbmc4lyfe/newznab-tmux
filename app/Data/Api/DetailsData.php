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

    // Rust is the default only while the paired W65.6 benchmark clears 1.5x on all four metrics.
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_DETAILS_FFI_CDEF = <<<'C'
        int32_t nntmux_w656_details_data_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t now, int64_t *out, size_t out_cap);
        C;

    public const int W65_DETAILS_HEADER = 19;

    /** Integer columns in the order Rust reads them. */
    public const array W65_DETAILS_COLUMNS = ['categories_id', 'size', 'totalpart', 'grabs', 'comments', 'imdbid', 'tvdb', 'tmdb'];

    private const int W65_MAX_ITEMS = 1_048_576;

    /** Size clamp (2^50), count clamp (2^31 - 1) and total cap (2^62). */
    private const int W65_SIZE_CAP = 1_125_899_906_842_624;

    private const int W65_COUNT_CAP = 2_147_483_647;

    private const int W65_SUM_CAP = 4_611_686_018_427_387_904;

    /** `Category::MOVIES_GROUP` ids => 1 and `Category::TV_GROUP` ids => 2. */
    private const array W65_MEDIA_GROUPS = [
        2000 => 1, 2010 => 1, 2030 => 1, 2040 => 1, 2045 => 1, 2050 => 1, 2060 => 1, 2070 => 1, 2080 => 1, 2090 => 1, 2999 => 1,
        5000 => 2, 5010 => 2, 5020 => 2, 5030 => 2, 5040 => 2, 5045 => 2, 5060 => 2, 5070 => 2, 5080 => 2, 5090 => 2, 5999 => 2,
    ];

    /**
     * Plan release details metadata: GUIDs, `Y-m-d H:i:s` postdates (UTC, 1970-2099) and the `categories_id`,
     * `size`, `totalpart`, `grabs`, `comments`, `imdbid`, `tvdb` and `tmdb` columns, with the current epoch
     * (`$now` at or below 0 skips ages and future checks). Group 1 movies, 2 TV, 0 other (as {@see
     * toArrayFromReleasePhp()} picks its external ids). Sizes clamp to 0..2^50 and counts to 0..2^31-1. A
     * movie without an IMDb id or a TV release without a TVDB id misses its expected id.
     *
     * Layout: `[0]` count, `[1]` valid GUIDs, `[2]` valid postdates, `[3]` future, `[4..7]` per group, `[7]`
     * IMDb, `[8]` TVDB and `[9]` TMDB ids, `[10]` missing expected ids, `[11]` total size (capped at 2^62),
     * `[12]` grabs, `[13]` comments, `[14]` files, `[15]` newest and `[16]` oldest postdate epoch (0 without
     * one), `[17]` first index with an invalid GUID or postdate (-1), `[18]` FNV-1a digest; then per release
     * `guid | date << 1 | future << 2 | group << 3 | ids << 5 | missing << 8 | min(age days, 2^16-1) << 9 |
     * min(files, 2^16-1) << 25 | min(grabs, 2^16-1) << 41` (ids: 1 IMDb, 2 TVDB, 4 TMDB).
     *
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, string|null>  $postdates
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65DetailsDataPlanBatch(array $guids, array $postdates, array $metrics, int $now = 0): array
    {
        return (self::usesW65RustFfi() ? self::w65DetailsDataPlanBatchNative($guids, $postdates, $metrics, $now) : null)
            ?? self::w65DetailsDataPlanBatchPhp($guids, $postdates, $metrics, $now);
    }

    /**
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, string|null>  $postdates
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>
     */
    public static function w65DetailsDataPlanBatchPhp(array $guids, array $postdates, array $metrics, int $now = 0): array
    {
        $guids = array_values($guids);
        $count = \count($guids);
        $postdates = self::w65Column($postdates, $count, '');
        [$categories, $sizes, $files, $grabs, $comments, $imdb, $tvdb, $tmdb] = self::w65Metrics($metrics, $count);
        $out = array_fill(0, self::W65_DETAILS_HEADER, 0);
        $out[0] = $count;
        $out[17] = -1;
        $newest = $oldest = null;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($guids as $at => $guid) {
            $guid = (string) $guid;
            $valid = \strlen($guid) === 40 && strspn($guid, '0123456789abcdef') === 40;
            $epoch = self::w65Postdate((string) $postdates[$at]);
            $future = $now > 0 && $epoch !== null && $epoch > $now;
            $age = $epoch !== null && $now > 0 ? intdiv(max(0, $now - $epoch), 86_400) : 0;
            $group = self::W65_MEDIA_GROUPS[$categories[$at]] ?? 0;
            $size = max(0, min($sizes[$at], self::W65_SIZE_CAP));
            $file = max(0, min($files[$at], self::W65_COUNT_CAP));
            $grab = max(0, min($grabs[$at], self::W65_COUNT_CAP));
            $comment = max(0, min($comments[$at], self::W65_COUNT_CAP));
            $hasImdb = $imdb[$at] > 0;
            $hasTvdb = $tvdb[$at] > 0;
            $hasTmdb = $tmdb[$at] > 0;
            $missing = ($group === 1 && ! $hasImdb) || ($group === 2 && ! $hasTvdb);
            $out[1] += (int) $valid;
            $out[2] += (int) ($epoch !== null);
            $out[3] += (int) $future;
            $out[4 + $group]++;
            $out[7] += (int) $hasImdb;
            $out[8] += (int) $hasTvdb;
            $out[9] += (int) $hasTmdb;
            $out[10] += (int) $missing;
            $out[11] = min($out[11] + $size, self::W65_SUM_CAP);
            $out[12] += $grab;
            $out[13] += $comment;
            $out[14] += $file;
            if ($epoch !== null) {
                $newest = $newest === null ? $epoch : max($newest, $epoch);
                $oldest = $oldest === null ? $epoch : min($oldest, $epoch);
            }
            if ((! $valid || $epoch === null) && $out[17] < 0) {
                $out[17] = $at;
            }
            $row = (int) $valid | ($epoch !== null ? 2 : 0) | ($future ? 4 : 0) | $group << 3
                | (($hasImdb ? 1 : 0) | ($hasTvdb ? 2 : 0) | ($hasTmdb ? 4 : 0)) << 5 | ($missing ? 256 : 0)
                | min($age, 0xFFFF) << 9 | min($file, 0xFFFF) << 25 | min($grab, 0xFFFF) << 41;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[15] = $newest ?? 0;
        $out[16] = $oldest ?? 0;
        $out[18] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int|string>  $guids
     * @param  array<array-key, string|null>  $postdates
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<int>|null
     */
    public static function w65DetailsDataPlanBatchNative(array $guids, array $postdates, array $metrics, int $now = 0): ?array
    {
        $count = \count($guids);
        if ($count > self::W65_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w65Strings([...array_values($guids), ...self::w65Column($postdates, $count, '')]);
        $ints = self::w65Pack($metrics, $count);
        $words = self::W65_DETAILS_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w65Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w656_details_data_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $now, $out, $words));
    }

    /**
     * UTC epoch of a `Y-m-d H:i:s` postdate in 1970-2099, else null.
     */
    private static function w65Postdate(string $value): ?int
    {
        if (\strlen($value) !== 19 || preg_match('/\A(\d{4})-(\d\d)-(\d\d) (\d\d):(\d\d):(\d\d)\z/', $value, $match) !== 1) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', \array_slice($match, 1));
        if ($year < 1970 || $year > 2099 || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $epoch = gmmktime($hour, $minute, $second, $month, $day, $year);

        return $epoch === false ? null : $epoch;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('api_w656', self::W65_DETAILS_FFI_CDEF) ?? false;
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
     * The named integer columns of {@see W65_DETAILS_COLUMNS}, each cut or padded with 0 to `$count`.
     *
     * @param  array<string, array<array-key, int>>  $metrics
     * @return list<list<int>>
     */
    private static function w65Metrics(array $metrics, int $count): array
    {
        $columns = [];
        foreach (self::W65_DETAILS_COLUMNS as $key) {
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
