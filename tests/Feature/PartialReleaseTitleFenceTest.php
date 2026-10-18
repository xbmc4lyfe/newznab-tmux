<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TvLookupStatus;
use App\Models\Category;
use App\Services\BookProcessingCandidateQuery;
use App\Services\MovieService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Title-based metadata lookups must never pick a partial that a late-article upgrade may still rename and reset. */
final class PartialReleaseTitleFenceTest extends TestCase
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
            $table->string('searchname')->default('Some.Title.2020');
            $table->unsignedInteger('categories_id')->default(Category::MOVIE_HD);
            $table->string('imdbid')->nullable();
            $table->integer('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(TvLookupStatus::Pending->value);
            $table->dateTime('tv_lookup_retry_at')->nullable();
            $table->unsignedBigInteger('size')->default(2097152);
            $table->unsignedInteger('isrenamed')->default(0);
            $table->unsignedInteger('bookinfo_id')->nullable();
            $table->unsignedInteger('book_lookup_attempts')->default(0);
            $table->dateTime('book_lookup_retry_at')->nullable();
            $table->dateTime('book_name_normalized_at')->nullable();
            $table->string('name')->default('x');
        });
    }

    public function test_movie_selection_excludes_a_partial(): void
    {
        DB::table('releases')->insert(['id' => 1, 'late_article_candidate_id' => 7]);
        $service = (new \ReflectionClass(MovieService::class))->newInstanceWithoutConstructor();
        $service->movieqty = 100;
        $service->echooutput = false;
        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $service->processMovieReleases();

        $selects = array_values(array_filter($sql, static fn (string $q): bool => str_contains($q, 'from "releases"') && str_contains($q, 'imdbid')));
        $this->assertNotEmpty($selects);
        $this->assertStringContainsString('"releases"."late_article_candidate_id" is null', $selects[0]);
    }

    public function test_tv_and_book_work_conditions_exclude_a_partial(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'categories_id' => Category::TV_HD, 'late_article_candidate_id' => 7],
            ['id' => 2, 'categories_id' => Category::TV_HD, 'late_article_candidate_id' => null],
            ['id' => 3, 'categories_id' => Category::BOOKS_EBOOK, 'late_article_candidate_id' => 7],
            ['id' => 4, 'categories_id' => Category::BOOKS_EBOOK, 'late_article_candidate_id' => null],
        ]);

        $this->assertSame([2], DB::table('releases')->whereRaw(TvProcessingCandidateQuery::workCondition(1))->pluck('id')->all());
        $this->assertSame([4], DB::table('releases')->whereRaw(BookProcessingCandidateQuery::workCondition(1))->pluck('id')->all());
    }

    public function test_tv_retry_and_reprocessing_skip_a_partial(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            $table->unsignedInteger('tv_lookup_attempts')->default(0);
            $table->string('guid')->default('g');
            $table->dateTime('adddate')->nullable();
            $table->dateTime('postdate')->nullable();
        });
        DB::table('releases')->insert([
            ['id' => 1, 'categories_id' => Category::TV_HD, 'videos_id' => 5, 'tv_episodes_id' => TvLookupStatus::EpisodeMissing->value, 'late_article_candidate_id' => 7],
            ['id' => 2, 'categories_id' => Category::TV_HD, 'videos_id' => 5, 'tv_episodes_id' => TvLookupStatus::EpisodeMissing->value, 'late_article_candidate_id' => null],
            ['id' => 3, 'categories_id' => Category::TV_HD, 'videos_id' => 0, 'tv_episodes_id' => TvLookupStatus::Pending->value, 'late_article_candidate_id' => 7],
        ]);

        $this->assertSame([2], DB::table('releases')->whereRaw(TvProcessingCandidateQuery::episodeRetryCondition(true))->pluck('id')->all());
        $this->artisan('tv:reprocess-unmatched', ['--dry-run' => true])->expectsOutput('No unmatched TV releases found.')->assertSuccessful();
    }
}
