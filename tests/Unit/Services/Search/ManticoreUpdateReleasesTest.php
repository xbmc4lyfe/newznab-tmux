<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Support\ReleaseSearchIndexDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Manticoresearch\Client;
use Manticoresearch\Exceptions\ResponseException;
use Manticoresearch\Request;
use Manticoresearch\Response;
use Manticoresearch\Table;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class ManticoreUpdateReleasesTest extends TestCase
{
    /** @var list<string> */
    private const array TABLES = [
        'releases', 'usenet_groups', 'categories', 'root_categories', 'movieinfo', 'videos', 'tv_episodes',
        'release_nfos', 'video_data', 'media_infos', 'release_files', 'audio_data', 'release_subtitles', 'search_index_failures',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
        DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies']);
        DB::table('categories')->insert(['id' => 2040, 'title' => 'HD', 'root_categories_id' => 2000]);
        DB::table('movieinfo')->insert(['id' => 9, 'tmdbid' => 603, 'traktid' => 481]);
        $this->insertRelease(1, ['movieinfo_id' => 9, 'imdbid' => '0133093', 'nzbstatus' => 1]);
        $this->insertRelease(2, ['grabs' => 4]);
        DB::table('release_files')->insert([['releases_id' => 1, 'name' => 'movie.mkv'], ['releases_id' => 1, 'name' => 'movie.nfo']]);
        DB::table('video_data')->insert(['releases_id' => 2, 'containerformat' => 'Matroska', 'videoformat' => 'HEVC', 'videocodec' => 'V_MPEGH', 'videowidth' => 1920, 'videoheight' => 1080]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_sends_one_bulk_replace_with_the_documents_update_release_would_send(): void
    {
        DB::table('search_index_failures')->insert(['release_id' => 1, 'operation' => 'upsert', 'attempts' => 2, 'last_error' => 'x', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $expected = array_map(
            static fn (int $id): array => array_merge(['id' => $id], ReleaseSearchIndexDocument::normalizeForBulk(ReleaseIndexProjection::forId($id) ?? [])),
            [1, 2],
        );
        $table = $this->createMock(Table::class);
        $table->expects($this->once())->method('replaceDocuments')->with($this->callback(function (array $documents) use ($expected): bool {
            $this->assertEqualsCanonicalizing($expected, $documents);

            return true;
        }));
        $table->expects($this->never())->method('replaceDocument');
        $table->expects($this->never())->method('deleteDocumentsByIds');

        $this->driver($table)->updateReleases([2, 1, 2]);

        $this->assertNotNull(DB::table('search_index_failures')->where('release_id', 1)->value('resolved_at'));
    }

    public function test_removes_releases_that_no_longer_exist(): void
    {
        $table = $this->createMock(Table::class);
        $table->expects($this->once())->method('replaceDocuments')->with($this->callback(
            static fn (array $documents): bool => array_column($documents, 'id') === [1]
        ));
        $table->expects($this->once())->method('deleteDocumentsByIds')->with([98, 99]);

        $this->driver($table)->updateReleases([1, 98, 99]);
    }

    public function test_retries_one_by_one_when_the_bulk_replace_fails(): void
    {
        $request = $this->createStub(Request::class);
        $response = $this->createStub(Response::class);
        $response->method('getError')->willReturn('bulk rejected');
        $table = $this->createMock(Table::class);
        $table->expects($this->once())->method('replaceDocuments')->willThrowException(new ResponseException($request, $response));
        $replaced = [];
        $table->expects($this->exactly(2))->method('replaceDocument')->willReturnCallback(static function (array $document, int $id) use (&$replaced): array {
            $replaced[] = $id;

            return [];
        });

        $this->driver($table, retryAttempts: 1)->updateReleases([1, 2]);

        sort($replaced);
        $this->assertSame([1, 2], $replaced);
    }

    public function test_falls_back_to_the_single_release_path_when_the_projection_fails(): void
    {
        Schema::drop('video_data');
        $table = $this->createMock(Table::class);
        $table->expects($this->never())->method('replaceDocuments');
        $table->expects($this->never())->method('replaceDocument');

        $this->driver($table)->updateReleases([1, 2]);

        $failures = DB::table('search_index_failures')->orderBy('release_id')->get();
        $this->assertSame([1, 2], $failures->pluck('release_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(['updateRelease_query'], $failures->pluck('last_error')->unique()->values()->all());
    }

    private function driver(Table&MockObject $table, int $retryAttempts = 2): ManticoreSearchDriver
    {
        $client = $this->createStub(Client::class);
        $client->method('table')->willReturn($table);
        $driver = new ManticoreSearchDriver([
            'host' => '127.0.0.1',
            'port' => 9308,
            'retry_attempts' => $retryAttempts,
            'retry_delay_ms' => 0,
            'indexes' => ['releases' => 'releases_rt', 'predb' => 'predb_rt'],
        ]);
        $property = new \ReflectionProperty(ManticoreSearchDriver::class, 'manticoreSearch');
        $property->setValue($driver, $client);

        return $driver;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertRelease(int $id, array $overrides = []): void
    {
        DB::table('releases')->insert(array_merge([
            'id' => $id, 'guid' => str_repeat((string) $id, 40), 'name' => "Release.{$id}", 'searchname' => "Release {$id}",
            'fromname' => 'poster@example.com', 'categories_id' => 2040, 'groups_id' => 1, 'size' => 1048576 * $id,
            'postdate' => '2026-10-09 12:00:00', 'adddate' => '2026-10-10 08:00:00', 'totalpart' => 10, 'grabs' => 0, 'comments' => 0,
            'passwordstatus' => 0, 'nzbstatus' => 0, 'nfostatus' => -1, 'haspreview' => -1, 'jpgstatus' => 0, 'videos_id' => 0,
            'tv_episodes_id' => 0, 'movieinfo_id' => 0, 'imdbid' => null, 'anidbid' => null,
        ], $overrides));
    }

    private function createSchema(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            foreach (['guid', 'name', 'searchname', 'fromname', 'imdbid', 'anidbid'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['categories_id', 'groups_id', 'size', 'totalpart', 'grabs', 'comments', 'passwordstatus', 'nzbstatus', 'nfostatus', 'haspreview', 'jpgstatus', 'videos_id', 'tv_episodes_id', 'movieinfo_id'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
        });
        Schema::create('usenet_groups', static fn (Blueprint $table) => [$table->id(), $table->string('name')]);
        Schema::create('categories', static fn (Blueprint $table) => [$table->id(), $table->string('title'), $table->integer('root_categories_id')->nullable()]);
        Schema::create('root_categories', static fn (Blueprint $table) => [$table->id(), $table->string('title')]);
        Schema::create('movieinfo', static fn (Blueprint $table) => [$table->id(), $table->integer('tmdbid')->default(0), $table->integer('traktid')->default(0)]);
        Schema::create('videos', static function (Blueprint $table): void {
            $table->id();
            foreach (['tvdb', 'tvmaze', 'tvrage', 'trakt', 'tmdb'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->string('imdb')->default('');
        });
        Schema::create('tv_episodes', static fn (Blueprint $table) => [$table->id(), $table->string('title')->nullable(), $table->integer('series')->default(0), $table->integer('episode')->default(0), $table->date('firstaired')->nullable()]);
        Schema::create('release_nfos', static fn (Blueprint $table) => [$table->unsignedBigInteger('releases_id')->primary()]);
        Schema::create('video_data', static function (Blueprint $table): void {
            $table->unsignedBigInteger('releases_id')->primary();
            foreach (['containerformat', 'videoformat', 'videocodec'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('videowidth')->default(0);
            $table->integer('videoheight')->default(0);
        });
        Schema::create('media_infos', static fn (Blueprint $table) => [$table->id(), $table->unsignedBigInteger('releases_id'), $table->string('movie_name')->nullable(), $table->string('file_name')->nullable(), $table->string('unique_id')->nullable()]);
        Schema::create('release_files', static fn (Blueprint $table) => [$table->unsignedBigInteger('releases_id'), $table->string('name')]);
        Schema::create('audio_data', static fn (Blueprint $table) => [$table->unsignedBigInteger('releases_id'), $table->string('audioformat')->nullable(), $table->string('audiochannels')->nullable(), $table->string('audiolanguage')->nullable()]);
        Schema::create('release_subtitles', static fn (Blueprint $table) => [$table->unsignedBigInteger('releases_id'), $table->string('subslanguage')->nullable()]);
        Schema::create('search_index_failures', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id')->unique();
            $table->string('operation', 32)->default('upsert');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }
}
