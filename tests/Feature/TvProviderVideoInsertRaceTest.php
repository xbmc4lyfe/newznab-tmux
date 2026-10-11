<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Video;
use App\Services\TvProcessing\Providers\TraktProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TvProviderVideoInsertRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        Schema::create('videos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->integer('type')->default(0);
            $table->string('countries_id', 2)->default('');
            $table->dateTime('started')->nullable();
            $table->string('source')->default('0');
            foreach (['tvdb', 'trakt', 'tvrage', 'tvmaze', 'imdb', 'tmdb'] as $column) {
                $table->string($column)->default('0');
            }
            $table->unique(['title', 'type', 'started', 'countries_id'], 'ix_videos_title');
        });
    }

    /**
     * Two TV workers can both find no row by title and both insert; the loser used to die on the
     * unique key (1062 'ix_videos_title') and fail its whole batch.
     */
    public function test_a_show_inserted_by_another_worker_between_the_lookup_and_the_insert_is_reused(): void
    {
        $provider = new class extends TraktProvider
        {
            public int $updatedId = 0;

            public function update(int $videoId, array $show = []): void
            {
                $this->updatedId = $videoId;
            }
        };
        $raced = false;
        // Simulate the other worker: insert the row right before this worker's own insert.
        Video::creating(function (Video $video) use (&$raced): void {
            if (! $raced) {
                $raced = true;
                DB::table('videos')->insert(['title' => 'Less Than Evil', 'type' => 0, 'countries_id' => 'KR', 'started' => '2018-12-03 00:00:00']);
            }
        });

        $id = $provider->add([
            'type' => 0, 'title' => 'Less Than Evil', 'country' => 'KR', 'started' => '2018-12-03 00:00:00', 'source' => 5,
            'tvdb' => 0, 'trakt' => 0, 'tvrage' => 0, 'tvmaze' => 0, 'imdb' => 0, 'tmdb' => 0,
            'summary' => '', 'publisher' => '', 'localzone' => '', 'aliases' => '',
        ]);

        $this->assertTrue($raced);
        $this->assertSame(1, DB::table('videos')->count());
        $this->assertSame((int) DB::table('videos')->value('id'), $id);
        $this->assertSame($id, $provider->updatedId);
    }
}
