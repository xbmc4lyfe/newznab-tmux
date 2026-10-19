<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Runners\PostProcessRunner;
use App\Services\Tmux\Tmux;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Readiness and dispatch checks must not count partials awaiting late articles, or idle workers keep spawning. */
final class PartialReleaseDispatchFenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('late_article_candidate_id')->nullable();
            $table->unsignedInteger('categories_id')->default(Category::MOVIE_HD);
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->integer('anidbid')->nullable();
            $table->unsignedInteger('isrenamed')->default(0);
            $table->string('leftguid')->default('a');
        });
    }

    public function test_movie_readiness_condition_counts_only_settled_releases(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'late_article_candidate_id' => 7],
            ['id' => 2, 'late_article_candidate_id' => null],
        ]);
        $tmux = (new \ReflectionClass(Tmux::class))->newInstanceWithoutConstructor();
        $condition = (new \ReflectionMethod(Tmux::class, 'readyLookupCondition'))->invoke(
            $tmux,
            'categories_id BETWEEN '.Category::MOVIE_ROOT.' AND '.Category::MOVIE_OTHER.' AND '.imdb_id_needs_lookup_sql('imdbid'),
            1,
            true,
        );

        $ready = DB::table('releases as r')->whereRaw($condition)->pluck('r.id')->all();

        $this->assertSame([2], $ready);
    }

    public function test_bucket_selection_excludes_a_partial(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'categories_id' => Category::MUSIC_MP3, 'late_article_candidate_id' => 7, 'leftguid' => 'a'],
            ['id' => 2, 'categories_id' => Category::MUSIC_MP3, 'late_article_candidate_id' => null, 'leftguid' => 'b'],
        ]);
        $runner = (new \ReflectionClass(PostProcessRunner::class))->newInstanceWithoutConstructor();

        $buckets = (new \ReflectionMethod(PostProcessRunner::class, 'getMusicBuckets'))->invoke($runner);

        $this->assertSame(['b'], array_map(static fn (object $row): string => $row->id, $buckets));
    }
}
