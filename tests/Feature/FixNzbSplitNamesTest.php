<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Categorization\CategorizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixNzbSplitNamesTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('releases');

        parent::tearDown();
    }

    #[Test]
    public function the_reset_clears_book_and_movie_links_with_null(): void
    {
        Schema::dropIfExists('releases');
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('searchname');
            $table->string('fromname')->nullable();
            $table->unsignedInteger('groups_id')->default(1);
            $table->unsignedInteger('categories_id')->default(1);
            $table->unsignedTinyInteger('isrenamed')->default(0);
            $table->unsignedTinyInteger('iscategorized')->default(0);
            $table->unsignedInteger('videos_id')->default(0);
            $table->integer('tv_episodes_id')->default(0);
            $table->unsignedInteger('tv_lookup_attempts')->default(0);
            $table->timestamp('tv_lookup_retry_at')->nullable();
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('gamesinfo_id')->nullable();
            $table->integer('bookinfo_id')->nullable();
            $table->integer('movieinfo_id')->nullable();
            $table->integer('anidbid')->nullable();
            $table->timestamps();
        });
        $id = DB::table('releases')->insertGetId([
            'name' => 'Wrapped__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Some_Movie_2026_1080p.7z.001',
            'searchname' => 'Wrapped__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Some_Movie_2026_1080p.7z.001',
            'bookinfo_id' => 7,
            'movieinfo_id' => 9,
        ]);
        $this->mock(CategorizationService::class, static function ($mock): void {
            $mock->shouldReceive('determineCategory')->once()->andReturn(['categories_id' => 2040]);
        });
        Search::shouldReceive('updateRelease')->once()->with($id);

        $this->artisan('releases:fix-nzbsplit')->assertSuccessful();

        $release = DB::table('releases')->where('id', $id)->first();
        $this->assertNotNull($release);
        $this->assertNull($release->bookinfo_id);
        $this->assertNull($release->movieinfo_id);
        $this->assertSame(1, (int) $release->isrenamed);
    }
}
