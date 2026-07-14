<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Api\DetailsData;
use App\Data\Api\ReleaseData;
use App\Facades\Search;
use App\Models\Release;
use App\Models\User;
use App\Services\Api\ApiReleaseRowCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiPerformanceHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();
    }

    public function test_release_row_cache_reuses_cached_null_values(): void
    {
        Search::shouldReceive('getCurrentDriver')->andReturn('testing');

        $calls = 0;
        $cache = new ApiReleaseRowCache;

        $first = $cache->remember('v2', 'details', ['guid' => 'missing'], function () use (&$calls): mixed {
            $calls++;

            return null;
        });
        $second = $cache->remember('v2', 'details', ['guid' => 'missing'], function () use (&$calls): mixed {
            $calls++;

            return null;
        });

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $calls);
    }

    public function test_release_row_cache_canonicalizes_equivalent_parameters(): void
    {
        Search::shouldReceive('getCurrentDriver')->twice()->andReturn('testing');

        $calls = 0;
        $cache = new ApiReleaseRowCache;
        $first = $cache->remember('v2', 'search', [
            'category' => [5030, 5040, 5030],
            'id' => ' ubuntu ',
        ], function () use (&$calls): array {
            $calls++;

            return ['result'];
        });
        $second = $cache->remember('v2', 'search', [
            'id' => 'ubuntu',
            'category' => [5040, 5030],
        ], function () use (&$calls): array {
            $calls++;

            return ['different'];
        });

        $this->assertSame(['result'], $first);
        $this->assertSame(['result'], $second);
        $this->assertSame(1, $calls);
    }

    public function test_get_by_guid_for_api_returns_plain_row_without_model_hydration(): void
    {
        $this->createReleaseDetailsSchema();

        DB::table('root_categories')->insert([
            'id' => 5000,
            'title' => 'TV',
        ]);
        DB::table('categories')->insert([
            'id' => 5030,
            'title' => 'SD',
            'root_categories_id' => 5000,
        ]);
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.test',
        ]);
        DB::table('releases')->insert([
            'id' => 1,
            'searchname' => 'Ubuntu.Release',
            'guid' => 'release-guid',
            'postdate' => '2026-01-02 00:00:00',
            'categories_id' => 5030,
            'size' => 123456,
            'totalpart' => 10,
            'fromname' => 'poster',
            'passwordstatus' => 0,
            'grabs' => 2,
            'comments' => 1,
            'adddate' => '2026-01-03 00:00:00',
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'haspreview' => 0,
            'nfostatus' => 0,
            'movieinfo_id' => 0,
            'musicinfo_id' => 0,
            'consoleinfo_id' => 0,
            'groups_id' => 1,
        ]);

        $row = Release::getByGuidForApi('release-guid');

        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame('release-guid', $row->guid);
        $this->assertSame('TV > SD', $row->category_name);
        $this->assertSame('alt.binaries.test', $row->group_name);
    }

    public function test_v2_details_query_omits_v1_compatibility_fields(): void
    {
        $this->createReleaseDetailsSchema();

        DB::table('root_categories')->insert(['id' => 5000, 'title' => 'TV']);
        DB::table('categories')->insert(['id' => 5030, 'title' => 'SD', 'root_categories_id' => 5000]);
        DB::table('releases')->insert([
            'id' => 1,
            'searchname' => 'Ubuntu.Release',
            'guid' => 'release-guid',
            'postdate' => '2026-01-02 00:00:00',
            'categories_id' => 5030,
            'size' => 123456,
            'totalpart' => 10,
            'passwordstatus' => 0,
            'grabs' => 2,
            'comments' => 1,
            'adddate' => '2026-01-03 00:00:00',
            'videos_id' => 0,
            'tv_episodes_id' => 0,
            'haspreview' => 0,
            'nfostatus' => 0,
            'movieinfo_id' => 0,
            'musicinfo_id' => 0,
            'consoleinfo_id' => 0,
        ]);

        $row = Release::getByGuidForApi('release-guid', false);

        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame('TV > SD', $row->category_name);
        $this->assertObjectNotHasProperty('group_name', $row);
        $this->assertObjectNotHasProperty('haspreview', $row);
        $this->assertObjectNotHasProperty('nfostatus', $row);
    }

    public function test_release_data_fast_array_matches_existing_data_output(): void
    {
        $release = (object) [
            'searchname' => 'Ubuntu.Release',
            'guid' => 'release-guid',
            'categories_id' => 5030,
            'category_name' => 'TV > SD',
            'adddate' => '2026-01-03 00:00:00',
            'size' => 123456,
            'totalpart' => 10,
            'grabs' => 0,
            'comments' => 0,
            'passwordstatus' => 0,
            'postdate' => '2026-01-02 00:00:00',
            'imdb' => '1234567',
            'tmdb' => 234,
            'trakt' => 345,
            'title' => 'Pilot',
            'series' => '1',
            'episode' => '1',
            'firstaired' => '2026-01-01',
            'tvdb' => 456,
            'tvrage' => 567,
            'tvmaze' => 678,
        ];
        $user = new User;
        $user->api_token = 'api-token';

        $this->assertSame(
            ReleaseData::fromRelease($release, $user)->toArray(),
            ReleaseData::toArrayFromRelease($release, $user, url('/details').'/', url('/getnzb'))
        );
    }

    public function test_details_data_fast_array_matches_existing_data_output(): void
    {
        $release = (object) [
            'searchname' => 'Ubuntu.Release',
            'guid' => 'release-guid',
            'categories_id' => 5030,
            'category_name' => 'TV > SD',
            'adddate' => '2026-01-03 00:00:00',
            'size' => 123456,
            'totalpart' => 10,
            'grabs' => 2,
            'comments' => 1,
            'passwordstatus' => 0,
            'postdate' => '2026-01-02 00:00:00',
            'imdb' => '1234567',
            'tmdb' => 234,
            'trakt' => 345,
            'firstaired' => '2026-01-01',
            'tvdb' => 456,
            'tvrage' => 567,
            'tvmaze' => 678,
        ];
        $user = new User;
        $user->api_token = 'api-token';

        $this->assertSame(
            DetailsData::fromRelease($release, $user)->toArray(),
            DetailsData::toArrayFromRelease($release, $user, url('/details').'/', url('/getnzb'))
        );
    }

    private function createReleaseDetailsSchema(): void
    {
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->unsignedInteger('root_categories_id')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('videos', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('tvdb')->nullable();
            $table->unsignedInteger('trakt')->nullable();
            $table->unsignedInteger('tvrage')->nullable();
            $table->unsignedInteger('tvmaze')->nullable();
            $table->string('imdb')->nullable();
            $table->unsignedInteger('tmdb')->nullable();
        });
        Schema::create('tv_episodes', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title')->nullable();
            $table->string('series')->nullable();
            $table->string('episode')->nullable();
            $table->date('firstaired')->nullable();
        });
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('imdbid')->nullable();
            $table->unsignedInteger('tmdbid')->nullable();
            $table->unsignedInteger('traktid')->nullable();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('searchname');
            $table->string('guid')->index();
            $table->dateTime('postdate');
            $table->unsignedInteger('categories_id');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('totalpart');
            $table->string('fromname')->nullable();
            $table->integer('passwordstatus')->default(0);
            $table->unsignedInteger('grabs')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->dateTime('adddate');
            $table->unsignedInteger('videos_id')->default(0);
            $table->unsignedInteger('tv_episodes_id')->default(0);
            $table->integer('haspreview')->default(0);
            $table->integer('nfostatus')->default(0);
            $table->unsignedInteger('movieinfo_id')->default(0);
            $table->unsignedInteger('musicinfo_id')->default(0);
            $table->unsignedInteger('consoleinfo_id')->default(0);
            $table->unsignedInteger('groups_id')->nullable();
        });
    }
}
