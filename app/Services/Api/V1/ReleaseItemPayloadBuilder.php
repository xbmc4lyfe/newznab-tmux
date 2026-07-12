<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

use Illuminate\Support\Carbon;

final readonly class ReleaseItemPayloadBuilder
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $server
     */
    public function __construct(
        private array $parameters,
        private array $server,
        private string $namespace = 'newznab',
    ) {}

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
        $serverUrl = $this->serverUrl();
        $guid = (string) $this->value($release, 'guid');
        $searchName = $this->value($release, 'searchname');
        $downloadUrl = $serverUrl.'/getnzb?id='.$guid.'.nzb&r='.$this->parameter('token').$this->delParam();

        $payload = [
            'title' => $searchName,
            'guid' => $serverUrl.'/details/'.$guid,
            'link' => $downloadUrl,
            'comments' => $serverUrl.'/details/'.$guid.'#comments',
            'pubDate' => date(DATE_RSS, strtotime((string) $this->value($release, 'adddate'))),
            'category' => $this->value($release, 'category_name'),
            'description' => $searchName,
        ];

        if (! isset($this->parameters['dl']) || (int) $this->parameters['dl'] === 1) {
            $payload['enclosure'] = [
                'url' => $downloadUrl,
                'length' => $this->value($release, 'size'),
                'type' => 'application/x-nzb',
            ];
        }

        $payload['attr'] = $this->attributes($release, $serverUrl);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(mixed $release, string $serverUrl): array
    {
        $attributes = [
            'category' => $this->value($release, 'categories_id'),
            'size' => $this->value($release, 'size'),
        ];

        $coverUrl = $this->value($release, 'coverurl');
        if (! empty($coverUrl)) {
            $attributes['coverurl'] = $serverUrl.'/covers/'.$coverUrl;
        }

        if ((int) $this->parameter('extended') !== 1) {
            return $attributes;
        }

        $attributes['files'] = $this->value($release, 'totalpart');

        if ($this->namespace === 'newznab' && $this->hasVideoInfo($release)) {
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
            $attributes['info'] = $serverUrl.'api?t=info&id='.$this->value($release, 'guid').'&r='.$this->parameter('token');
        }

        $attributes['grabs'] = $this->value($release, 'grabs');
        $attributes['comments'] = $this->value($release, 'comments');
        $attributes['password'] = $this->value($release, 'passwordstatus');
        $attributes['usenetdate'] = Carbon::parse($this->value($release, 'postdate'))->toRssString();

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
        return ($this->value($release, 'videos_id') !== null && $this->value($release, 'videos_id') > 0)
            || ($this->value($release, 'tv_episodes_id') !== null && $this->value($release, 'tv_episodes_id') > 0);
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

    private function parameter(string $key): mixed
    {
        return $this->parameters[$key] ?? '';
    }

    private function serverUrl(): string
    {
        return (string) ($this->server['server']['url'] ?? '');
    }

    private function delParam(): string
    {
        return (int) $this->parameter('del') === 1 ? '&del=1' : '';
    }
}
