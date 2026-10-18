<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Services\AnimeProcessor;
use App\Services\BookService;
use App\Services\ConsoleService;
use App\Services\GamesService;
use App\Services\MovieService;
use App\Services\MusicService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** A title lookup finishing after a late-article upgrade renamed the release must not write its stale match. */
final class PartialReleaseTitleWriteGuardTest extends TestCase
{
    private const OLD = 'Old.Title.2020';

    private const NEW = 'New.Title.2020';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            $table->string('searchname')->default(PartialReleaseTitleWriteGuardTest::OLD);
            $table->unsignedInteger('categories_id')->default(Category::PC_GAMES);
            $table->string('imdbid')->nullable();
            $table->integer('musicinfo_id')->nullable();
            $table->integer('consoleinfo_id')->nullable();
            $table->integer('gamesinfo_id')->default(0);
            $table->integer('anidbid')->nullable();
        });
        DB::table('releases')->insert(['id' => 1]);
    }

    private function bare(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function invoke(object $service, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($service, $method);

        return $m->invoke($service, ...$args);
    }

    private function renamed(): void
    {
        DB::table('releases')->where('id', 1)->update(['searchname' => self::NEW]);
    }

    public function test_movie_not_found_marker_skips_a_renamed_release(): void
    {
        $service = $this->bare(MovieService::class);
        $this->renamed();

        $this->assertSame(0, $this->invoke($service, 'markImdbNotFound', 1, self::OLD));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('imdbid'));
    }

    public function test_movie_not_found_marker_applies_when_unchanged(): void
    {
        $service = $this->bare(MovieService::class);

        $this->assertSame(1, $this->invoke($service, 'markImdbNotFound', 1, self::OLD));
        $this->assertSame('', DB::table('releases')->where('id', 1)->value('imdbid'));
    }

    public function test_music_match_skips_a_renamed_release_and_applies_when_unchanged(): void
    {
        $service = $this->bare(MusicService::class);

        $this->assertSame(1, $this->invoke($service, 'recordMusicMatch', 1, 5, self::OLD));
        $this->assertSame(5, (int) DB::table('releases')->where('id', 1)->value('musicinfo_id'));

        DB::table('releases')->where('id', 1)->update(['musicinfo_id' => null]);
        $this->renamed();
        $this->assertSame(0, $this->invoke($service, 'recordMusicMatch', 1, 5, self::OLD));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('musicinfo_id'));
    }

    public function test_console_match_skips_a_renamed_release_and_applies_when_unchanged(): void
    {
        $service = $this->bare(ConsoleService::class);

        $this->assertSame(1, $this->invoke($service, 'recordConsoleMatch', 1, 7, self::OLD));
        $this->assertSame(7, (int) DB::table('releases')->where('id', 1)->value('consoleinfo_id'));

        DB::table('releases')->where('id', 1)->update(['consoleinfo_id' => null]);
        $this->renamed();
        $this->assertSame(0, $this->invoke($service, 'recordConsoleMatch', 1, 7, self::OLD));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('consoleinfo_id'));
    }

    public function test_games_batch_skips_a_renamed_release_and_applies_when_unchanged(): void
    {
        $service = $this->bare(GamesService::class);

        $this->invoke($service, 'batchUpdateReleases', [1 => 9], [1 => self::OLD]);
        $this->assertSame(9, (int) DB::table('releases')->where('id', 1)->value('gamesinfo_id'));

        DB::table('releases')->where('id', 1)->update(['gamesinfo_id' => 0]);
        $this->renamed();
        $this->invoke($service, 'batchUpdateReleases', [1 => 9], [1 => self::OLD]);
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('gamesinfo_id'));
    }

    public function test_anime_match_skips_a_renamed_release_and_applies_when_unchanged(): void
    {
        $service = $this->bare(AnimeProcessor::class);

        $this->assertSame(1, $this->invoke($service, 'updateRelease', 42, 1, self::OLD));
        $this->assertSame(42, (int) DB::table('releases')->where('id', 1)->value('anidbid'));

        DB::table('releases')->where('id', 1)->update(['anidbid' => null]);
        $this->renamed();
        $this->assertSame(0, $this->invoke($service, 'updateRelease', 42, 1, self::OLD));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('anidbid'));
    }

    public function test_book_junk_reclassification_applies_only_to_the_title_it_parsed(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            $table->unsignedInteger('isrenamed')->default(0);
        });
        $title = 'Old.Book.Title.1080p';
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        $book = app(BookService::class);
        (fn () => $this->echooutput = false)->call($book);
        DB::table('releases')->update(['searchname' => 'Renamed.By.An.Upgrade', 'categories_id' => Category::BOOKS_EBOOK]);

        $book->parseTitle($title, 1, 'ebook');
        $this->assertSame(Category::BOOKS_EBOOK, (int) DB::table('releases')->value('categories_id'), 'Renamed meanwhile: left alone.');

        DB::table('releases')->update(['searchname' => $title]);
        $book->parseTitle($title, 1, 'ebook');
        $this->assertSame(Category::BOOKS_UNKNOWN, (int) DB::table('releases')->value('categories_id'));
    }
}
