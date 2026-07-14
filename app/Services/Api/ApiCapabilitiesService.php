<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Data\Api\CategoryData;
use App\Models\Category;
use App\Models\Genre;
use App\Models\RootCategory;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\RegistrationStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final readonly class ApiCapabilitiesService
{
    public function __construct(private RegistrationStatusService $registrationStatus) {}

    /** @return array<string, mixed> */
    public function v1(bool $includeCatalogs): array
    {
        $data = Cache::remember('api_v1_server_menu', 600, static fn (): array => [
            'server' => [
                'title' => config('app.name'),
                'strapline' => Settings::settingValue('strapline'),
                'email' => config('mail.from.address'),
                'meta' => Settings::settingValue('metakeywords'),
                'url' => url('/'),
                'image' => url('/').'/assets/images/tmux_logo.png',
            ],
            'limits' => ['max' => 100, 'default' => 100],
            'searching' => [
                'search' => ['available' => 'yes', 'supportedParams' => 'q,group,minsize,maxsize,maxage,cat,limit,offset,attrs,extended,del,sort'],
                'tv-search' => ['available' => 'yes', 'supportedParams' => 'q,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort'],
                'movie-search' => ['available' => 'yes', 'supportedParams' => 'q,imdbid,tmdbid,traktid,genre,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort'],
                'audio-search' => ['available' => 'yes', 'supportedParams' => 'q,cat,minsize,maxsize,maxage,group,limit,offset,attrs,extended,del,sort'],
                'book-search' => ['available' => 'yes', 'supportedParams' => 'q,title,author,cat,minsize,maxsize,maxage,group,limit,offset,attrs,extended,del,sort'],
                'anime-search' => ['available' => 'yes', 'supportedParams' => 'q,anidbid,anilistid,cat,minsize,maxsize,maxage,limit,offset,attrs,extended,del,sort'],
            ],
        ]);

        $status = $this->registrationStatus->resolve();
        $data['registration'] = $this->registration($status);
        $data['categories'] = $includeCatalogs ? Category::getForMenu() : null;
        $data['groups'] = $includeCatalogs ? $this->groups() : null;
        $data['genres'] = $includeCatalogs ? $this->genres() : null;

        return $data;
    }

    /** @return array<string, mixed> */
    public function v2(): array
    {
        $data = Cache::remember('api_v2_capabilities', 600, function (): array {
            return [
                'server' => [
                    'title' => config('app.name'),
                    'strapline' => Settings::settingValue('strapline'),
                    'email' => config('mail.from.address'),
                    'url' => url('/'),
                ],
                'limits' => ['max' => 100, 'default' => 100],
                'searching' => [
                    'search' => ['available' => 'yes', 'supportedParams' => 'id,group,minsize,maxsize,maxage,cat,limit,offset,sort'],
                    'tv-search' => ['available' => 'yes', 'supportedParams' => 'id,vid,tvdbid,traktid,rid,tvmazeid,imdbid,tmdbid,season,ep,cat,minsize,maxsize,maxage,limit,offset,sort'],
                    'movie-search' => ['available' => 'yes', 'supportedParams' => 'id,imdbid,tmdbid,traktid,genre,cat,minsize,maxsize,maxage,limit,offset,sort'],
                    'audio-search' => ['available' => 'yes', 'supportedParams' => 'id,cat,minsize,maxsize,maxage,group,limit,offset,sort'],
                    'book-search' => ['available' => 'yes', 'supportedParams' => 'id,cat,minsize,maxsize,maxage,group,limit,offset,sort'],
                    'anime-search' => ['available' => 'yes', 'supportedParams' => 'id,anidbid,anilistid,cat,minsize,maxsize,maxage,limit,offset,sort'],
                ],
                'categories' => Category::getForApi()
                    ->map(static fn (RootCategory $category): array => CategoryData::fromCategory($category)->toArray())
                    ->values()->all(),
                'groups' => $this->groups(),
                'genres' => $this->genres(),
            ];
        });

        $status = $this->registrationStatus->resolve();
        $data['registration'] = $this->registration($status);

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function groups(): array
    {
        if (! Schema::hasTable('usenet_groups')) {
            return [];
        }

        return UsenetGroup::query()->where('active', 1)->orderBy('name')
            ->get(['name', 'description', 'last_updated'])
            ->map(static fn (UsenetGroup $group): array => [
                'name' => $group->name,
                'description' => (string) ($group->description ?? ''),
                'lastupdate' => $group->last_updated ? Carbon::parse($group->last_updated)->toRfc2822String() : '',
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function genres(): array
    {
        if (! Schema::hasTable('genres')) {
            return [];
        }

        return Genre::query()->enabled()->orderBy('title')->get(['id', 'title', 'type'])
            ->map(static fn (Genre $genre): array => [
                'id' => $genre->id,
                'name' => $genre->title,
                'categoryid' => (int) ($genre->type ?? 0),
            ])->values()->all();
    }

    /** @param array{available: bool, is_open: bool} $status
     * @return array{available: string, open: string}
     */
    private function registration(array $status): array
    {
        return [
            'available' => $status['available'] ? 'yes' : 'no',
            'open' => $status['is_open'] ? 'yes' : 'no',
        ];
    }
}
