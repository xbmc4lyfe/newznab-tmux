<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class ReleaseItemPayloadBuilder
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_rfc2822_from_unix_utc(int64_t ts, char *out_buf, size_t out_cap);
        ssize_t nntmux_release_item_rss_dates(const char *adddate_ptr, size_t adddate_len, const char *postdate_ptr, size_t postdate_len, char *out_buf, size_t out_cap);
        C;

    /**
     * The Wave 46.5 RSS timestamp batch defaults to Rust: every common and uncommon mean and p99 ratio
     * cleared 1.5x (min 6.23/4.72/4.78/3.71x); see
     * tests/Unit/RustApiV1V2PresenterPayloadRowsXmlContextW46ParityTest.php.
     */
    public const bool W465_RUST_FFI_DEFAULT = true;

    /** Bytes of one `DATE_RSS` string with a four-digit year in UTC. */
    private const int W465_RSS_LENGTH = 31;

    private static FFI|false|null $ffi = null;

    private static ?CData $outBuf64 = null;

    private static bool $useRustFfi = true;

    private static ?bool $useW465RustFfi = null;

    private readonly string $serverUrl;

    private readonly mixed $token;

    private readonly string $delParam;

    private readonly bool $includeEnclosure;

    private readonly bool $extended;

    private readonly bool $isNewznab;

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $server
     */
    public function __construct(
        array $parameters,
        array $server,
        string $namespace = 'newznab',
        private readonly bool $useNative = true,
    ) {
        $this->serverUrl = (string) ($server['server']['url'] ?? '');
        $this->token = $parameters['token'] ?? '';
        $this->delParam = (int) ($parameters['del'] ?? '') === 1 ? '&del=1' : '';
        $this->includeEnclosure = ! isset($parameters['dl']) || (int) $parameters['dl'] === 1;
        $this->extended = (int) ($parameters['extended'] ?? '') === 1;
        $this->isNewznab = $namespace === 'newznab';
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * Retains native PHP `date(DATE_RSS, $timestamp)` in production under the < 1.5x FFI threshold
     * (`1.22x` p99 due to single-integer `FFI::string` allocation overhead); see `rfc2822FromUnixUtcNative`.
     */
    public static function rfc2822FromUnixUtc(int $timestamp): string
    {
        return date(DATE_RSS, $timestamp);
    }

    public static function rfc2822FromUnixUtcNative(int $timestamp): ?string
    {
        if (! self::$useRustFfi || \date_default_timezone_get() !== 'UTC') {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        $buf = self::$outBuf64;
        if ($ffi === null || $ffi === false || $buf === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_rfc2822_from_unix_utc($timestamp, $buf, 64);

            return $written === 31 ? FFI::string($buf, 31) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function rfc2822FromUnixUtcPhp(int $timestamp): string
    {
        return date(DATE_RSS, $timestamp);
    }

    /** Pass null to restore {@see W465_RUST_FFI_DEFAULT}. */
    public static function useW465RustFfi(?bool $enable = true): void
    {
        self::$useW465RustFfi = $enable;
    }

    public static function usesW465RustFfi(): bool
    {
        return self::$useW465RustFfi ?? self::W465_RUST_FFI_DEFAULT;
    }

    public static function resetW465RustFfi(): void
    {
        self::$useW465RustFfi = null;
    }

    /**
     * {@see rfc2822FromUnixUtcPhp()} over a batch of timestamps, in order.
     *
     * @param  list<int>  $timestamps
     * @return list<string>
     */
    public static function rfc2822FromUnixUtcBatch(array $timestamps): array
    {
        return self::usesW465RustFfi() ? self::rfc2822FromUnixUtcBatchNative($timestamps) : self::rfc2822FromUnixUtcBatchPhp($timestamps);
    }

    /**
     * @param  list<int>  $timestamps
     * @return list<string>
     */
    public static function rfc2822FromUnixUtcBatchPhp(array $timestamps): array
    {
        $dates = [];
        foreach ($timestamps as $timestamp) {
            $dates[] = date(DATE_RSS, $timestamp);
        }

        return $dates;
    }

    /**
     * Rust formats UTC timestamps with years 1000-9999 and leaves NUL slots for PHP to format; other
     * time zones and an unavailable library use PHP for the whole batch.
     *
     * @param  list<int>  $timestamps
     * @return list<string>
     */
    public static function rfc2822FromUnixUtcBatchNative(array $timestamps): array
    {
        $count = \count($timestamps);
        if ($count === 0 || \date_default_timezone_get() !== 'UTC') {
            return self::rfc2822FromUnixUtcBatchPhp($timestamps);
        }
        $raw = ApiV1Presenter::evaluateW465Native(2, pack('q*', ...$timestamps), $count * self::W465_RSS_LENGTH);
        if ($raw === null) {
            return self::rfc2822FromUnixUtcBatchPhp($timestamps);
        }
        $dates = str_split($raw, self::W465_RSS_LENGTH);
        if (str_contains($raw, "\0")) {
            foreach ($dates as $index => $date) {
                if ($date[0] === "\0") {
                    $dates[$index] = date(DATE_RSS, $timestamps[$index]);
                }
            }
        }

        return $dates;
    }

    /**
     * @return array{pubDate: string, usenetdate: string}|null
     */
    public static function rssDates(string $adddate, ?string $postdate = null): ?array
    {
        if (self::$useRustFfi && \date_default_timezone_get() === 'UTC') {
            $native = self::rssDatesNative($adddate, $postdate ?? '');
            if ($native !== null) {
                return $native;
            }
        }

        return self::rssDatesPhp($adddate, $postdate);
    }

    /**
     * @return array{pubDate: string, usenetdate: string}|null
     */
    public static function rssDatesNative(string $adddate, string $postdate = ''): ?array
    {
        if (! self::$useRustFfi || \date_default_timezone_get() !== 'UTC') {
            return null;
        }
        $addLen = \strlen($adddate);
        if ($addLen === 0) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        $buf = self::$outBuf64;
        if ($ffi === null || $ffi === false || $buf === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_item_rss_dates(
                $adddate,
                $addLen,
                $postdate,
                \strlen($postdate),
                $buf,
                64,
            );
            if ($written !== 63) {
                return null;
            }
            if ($postdate === '' || $postdate === $adddate) {
                $pub = FFI::string($buf, 31);

                return ['pubDate' => $pub, 'usenetdate' => $pub];
            }
            $raw = FFI::string($buf, 63);

            return [
                'pubDate' => \substr($raw, 0, 31),
                'usenetdate' => \substr($raw, 32, 31),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{pubDate: string, usenetdate: string}|null
     */
    public static function rssDatesPhp(string $adddate, ?string $postdate = null): ?array
    {
        $addTs = strtotime($adddate);
        if ($addTs === false) {
            return null;
        }
        $pubDate = date(DATE_RSS, $addTs);
        $postTs = ($postdate !== null && trim($postdate) !== '') ? strtotime($postdate) : false;
        $usenetTs = $postTs !== false ? $postTs : $addTs;

        return [
            'pubDate' => $pubDate,
            'usenetdate' => date(DATE_RSS, $usenetTs),
        ];
    }

    /**
     * @return array{
     *     title: mixed,
     *     guid: string,
     *     link: string,
     *     comments: string,
     *     pubDate: string,
     *     category: mixed,
     *     description: mixed,
     *     enclosure?: array{url: string, length: mixed, type: string},
     *     attr: array<string, mixed>
     * }
     */
    public function build(mixed $release): array
    {
        if (! $this->useNative || ! self::$useRustFfi || \date_default_timezone_get() !== 'UTC') {
            return $this->buildPhp($release);
        }

        $guid = (string) $this->value($release, 'guid');
        $searchName = $this->value($release, 'searchname');
        $downloadUrl = $this->serverUrl.'/getnzb?id='.$guid.'.nzb&r='.$this->token.$this->delParam;
        $adddate = $this->value($release, 'adddate');
        $postdate = $this->extended ? $this->value($release, 'postdate') : null;

        $rssDates = (\is_string($adddate) && ($postdate === null || \is_string($postdate)))
            ? self::rssDatesNative($adddate, \is_string($postdate) ? $postdate : '')
            : null;

        if ($rssDates !== null) {
            $pubDate = $rssDates['pubDate'];
            $precomputedUsenetDate = $rssDates['usenetdate'];
        } else {
            $onlyAdd = \is_string($adddate) ? self::rssDatesNative($adddate, '') : null;
            $pubDate = $onlyAdd !== null
                ? $onlyAdd['pubDate']
                : date(DATE_RSS, strtotime((string) $adddate));
            $precomputedUsenetDate = null;
        }

        $payload = [
            'title' => $searchName,
            'guid' => $this->serverUrl.'/details/'.$guid,
            'link' => $downloadUrl,
            'comments' => $this->serverUrl.'/details/'.$guid.'#comments',
            'pubDate' => $pubDate,
            'category' => $this->value($release, 'category_name'),
            'description' => $searchName,
        ];

        if ($this->includeEnclosure) {
            $payload['enclosure'] = [
                'url' => $downloadUrl,
                'length' => $this->value($release, 'size'),
                'type' => 'application/x-nzb',
            ];
        }

        $payload['attr'] = $this->attributes($release, $precomputedUsenetDate, true);

        return $payload;
    }

    /**
     * @return array{
     *     title: mixed,
     *     guid: string,
     *     link: string,
     *     comments: string,
     *     pubDate: string,
     *     category: mixed,
     *     description: mixed,
     *     enclosure?: array{url: string, length: mixed, type: string},
     *     attr: array<string, mixed>
     * }
     */
    public function buildPhp(mixed $release): array
    {
        $guid = (string) $this->value($release, 'guid');
        $searchName = $this->value($release, 'searchname');
        $downloadUrl = $this->serverUrl.'/getnzb?id='.$guid.'.nzb&r='.$this->token.$this->delParam;

        $payload = [
            'title' => $searchName,
            'guid' => $this->serverUrl.'/details/'.$guid,
            'link' => $downloadUrl,
            'comments' => $this->serverUrl.'/details/'.$guid.'#comments',
            'pubDate' => date(DATE_RSS, strtotime((string) $this->value($release, 'adddate'))),
            'category' => $this->value($release, 'category_name'),
            'description' => $searchName,
        ];

        if ($this->includeEnclosure) {
            $payload['enclosure'] = [
                'url' => $downloadUrl,
                'length' => $this->value($release, 'size'),
                'type' => 'application/x-nzb',
            ];
        }

        $payload['attr'] = $this->attributes($release, null, false);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(mixed $release, ?string $precomputedUsenetDate = null, bool $allowNative = true): array
    {
        $attributes = [
            'category' => $this->value($release, 'categories_id'),
            'size' => $this->value($release, 'size'),
        ];

        $coverUrl = $this->value($release, 'coverurl');
        if (! empty($coverUrl)) {
            $attributes['coverurl'] = $this->serverUrl.'/covers/'.$coverUrl;
        }

        if (! $this->extended) {
            return $attributes;
        }

        $attributes['files'] = $this->value($release, 'totalpart');

        // Published before the upload was complete: found of declared articles and files.
        if ($this->value($release, 'articles_expected') !== null) {
            foreach (['articles_found', 'articles_expected', 'files_found', 'files_expected'] as $key) {
                $attributes[$key] = $this->value($release, $key);
            }
        }

        if ($this->isNewznab && $this->hasVideoInfo($release)) {
            $attributes = array_merge($attributes, $this->tvAttributes($release));
        }

        $imdbId = $this->value($release, 'imdbid');
        if ($imdbId !== null && imdb_id_is_valid($imdbId)) {
            $attributes['imdb'] = $imdbId;
        }

        $anidbId = $this->value($release, 'anidbid');
        if ($anidbId !== null && $anidbId > 0) {
            $attributes['anidbid'] = $anidbId;
        }

        $predbId = $this->value($release, 'predb_id');
        if ($predbId !== null && $predbId > 0) {
            $attributes['prematch'] = '1';
        }

        if ((int) ($this->value($release, 'nfostatus') ?? 0) === 1) {
            $attributes['info'] = $this->serverUrl.'api?t=info&id='.$this->value($release, 'guid').'&r='.$this->token;
        }

        $attributes['grabs'] = $this->value($release, 'grabs');
        $attributes['comments'] = $this->value($release, 'comments');
        $attributes['password'] = $this->value($release, 'passwordstatus');
        if ($precomputedUsenetDate !== null) {
            $attributes['usenetdate'] = $precomputedUsenetDate;
        } else {
            $usenetTimestamp = $this->timestamp($this->value($release, 'postdate'))
                ?? $this->timestamp($this->value($release, 'adddate'));
            if ($usenetTimestamp !== null) {
                $attributes['usenetdate'] = $allowNative
                    ? self::rfc2822FromUnixUtc($usenetTimestamp)
                    : date(DATE_RSS, $usenetTimestamp);
            }
        }

        $groupName = $this->value($release, 'group_name');
        if (! empty($groupName)) {
            $attributes['group'] = $groupName;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function tvAttributes(mixed $release): array
    {
        $attributes = [];

        $title = $this->value($release, 'title');
        if (! empty($title)) {
            $attributes['title'] = $title;
        }

        $series = $this->value($release, 'series');
        if ($series !== null && $series > 0) {
            $attributes['season'] = $series;
        }

        $episodeNum = $this->scalarOrRelationValue($release, 'episode', 'episode');
        if (! empty($episodeNum) && $episodeNum > 0) {
            $attributes['episode'] = $episodeNum;
        }

        $firstAired = $this->value($release, 'firstaired');
        if (! empty($firstAired)) {
            $attributes['tvairdate'] = $firstAired;
        }

        foreach ([
            'tvdb' => 'tvdbid',
            'trakt' => 'traktid',
            'tvmaze' => 'tvmazeid',
            'tmdb' => 'tmdbid',
        ] as $source => $target) {
            $value = $this->value($release, $source);
            if ($value !== null && $value > 0) {
                $attributes[$target] = $value;
            }
        }

        $tvrage = $this->value($release, 'tvrage');
        if ($tvrage !== null && $tvrage > 0) {
            $attributes['tvrageid'] = $tvrage;
            $attributes['rageid'] = $tvrage;
        }

        $imdb = $this->value($release, 'imdb');
        if ($imdb !== null && imdb_id_is_valid($imdb)) {
            $attributes['imdbid'] = $imdb;
        }

        return $attributes;
    }

    private function hasVideoInfo(mixed $release): bool
    {
        $videosId = $this->value($release, 'videos_id');
        $tvEpisodesId = $this->value($release, 'tv_episodes_id');

        return ($videosId !== null && $videosId > 0)
            || ($tvEpisodesId !== null && $tvEpisodesId > 0);
    }

    private function scalarOrRelationValue(mixed $release, string $property, string $subProperty): mixed
    {
        $value = $this->value($release, $property);

        if ($value === null) {
            return null;
        }

        if (\is_scalar($value)) {
            return $value;
        }

        return \is_object($value) ? ($value->{$subProperty} ?? null) : null;
    }

    private function value(mixed $release, string $key): mixed
    {
        if (\is_array($release) && \array_key_exists($key, $release)) {
            return $release[$key];
        }

        if (\is_object($release) && isset($release->{$key})) {
            return $release->{$key};
        }

        return null;
    }

    private function timestamp(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (! \is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('release_item_payload_builder', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outBuf64 = $ffi->new('char[64]');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }
}
