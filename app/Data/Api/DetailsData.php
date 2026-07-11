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
    ) {}

    public static function fromRelease(Release|\stdClass $release, User $user): self
    {
        $get = static fn (string $key, mixed $default = null): mixed => $release->{$key} ?? $default;

        $categoriesId = (int) $get('categories_id', 0);
        $guid = (string) $get('guid', '');
        $base = [
            'title' => (string) $get('searchname', ''),
            'details' => url('/').'/details/'.$guid,
            'link' => url('/').'/getnzb?id='.$guid.'.nzb&r='.$user->api_token,
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

        if (in_array($categoriesId, Category::MOVIES_GROUP, true)) {
            return new self(
                ...$base,
                imdbid: $get('imdbid'),
            );
        }

        if (in_array($categoriesId, Category::TV_GROUP, true)) {
            return new self(
                ...$base,
                imdbid: $get('imdb'),
                tmdbid: $get('tmdb'),
                traktid: $get('trakt'),
                tvairdate: $get('firstaired'),
                tvdbid: $get('tvdb'),
                tvrageid: $get('tvrage'),
                tvmazeid: $get('tvmaze'),
            );
        }

        return new self(...$base);
    }
}
