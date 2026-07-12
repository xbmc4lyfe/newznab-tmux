<?php

declare(strict_types=1);

namespace App\Services\Api\V1;

final readonly class ReleaseItemPayloadBuilder
{
    private string $serverUrl;

    private mixed $token;

    private string $delParam;

    private bool $includeEnclosure;

    private bool $extended;

    private bool $isNewznab;

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $server
     */
    public function __construct(
        array $parameters,
        array $server,
        string $namespace = 'newznab',
    ) {
        $this->serverUrl = (string) ($server['server']['url'] ?? '');
        $this->token = $parameters['token'] ?? '';
        $this->delParam = (int) ($parameters['del'] ?? '') === 1 ? '&del=1' : '';
        $this->includeEnclosure = ! isset($parameters['dl']) || (int) $parameters['dl'] === 1;
        $this->extended = (int) ($parameters['extended'] ?? '') === 1;
        $this->isNewznab = $namespace === 'newznab';
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

        $payload['attr'] = $this->attributes($release);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(mixed $release): array
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
        $attributes['usenetdate'] = date(DATE_RSS, strtotime((string) $this->value($release, 'postdate')));

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
}
