<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Facades\Search;
use App\Services\Releases\ReleaseSearchService;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

final class ExactPosterSearchMariaDbTest extends TestCase
{
    private ?PDO $administrator = null;

    private string $database = '';

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['database.default', 'database.connections.poster_qualification', 'cache.default', 'nntmux.mysql_search_fallback'] as $key) {
            $this->originalConfig[$key] = config($key);
        }
        $host = (string) getenv('POSTER_SEARCH_TEST_HOST');
        if ($host === '') {
            $this->markTestSkipped('Set POSTER_SEARCH_TEST_HOST to an isolated MariaDB test server.');
        }
        $port = (int) (getenv('POSTER_SEARCH_TEST_PORT') ?: 3306);
        $username = (string) (getenv('POSTER_SEARCH_TEST_USERNAME') ?: 'root');
        $password = (string) getenv('POSTER_SEARCH_TEST_PASSWORD');
        $this->administrator = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password);
        $this->database = 'nntmux_poster_test_'.bin2hex(random_bytes(8));
        $this->administrator->exec('CREATE DATABASE `'.$this->database.'`');
        $this->administrator->exec('USE `'.$this->database.'`');
        // Exercise the complete release schema, foreign keys and joined tables.
        $this->administrator->exec(file_get_contents(database_path('schema/mariadb-schema.sql')));
        config([
            'database.default' => 'poster_qualification',
            'database.connections.poster_qualification' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $this->database, 'username' => $username, 'password' => $password,
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => false,
            ],
            'cache.default' => 'array', 'nntmux.mysql_search_fallback' => false,
        ]);
        DB::purge('poster_qualification');
        Cache::flush();
        (new ConfigurationSeeder)->run();
        Search::shouldReceive('isAvailable')->never();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->administrator !== null && preg_match('/^nntmux_poster_test_[a-f0-9]{16}$/', $this->database) === 1) {
                DB::purge('poster_qualification');
                $this->administrator->exec('DROP DATABASE `'.$this->database.'`');
            }
        } finally {
            config($this->originalConfig);
            parent::tearDown();
        }
    }

    public function test_exact_poster_pages_and_counts_use_full_schema_joins_and_active_filters(): void
    {
        $poster = ' -VIP- Alice+Usenet@example.test ';
        DB::table('root_categories')->insert(['id' => 2000, 'title' => 'Movies']);
        DB::table('categories')->insert([
            ['id' => 2040, 'title' => 'HD', 'root_categories_id' => 2000],
            ['id' => 2050, 'title' => 'Excluded', 'root_categories_id' => 2000],
        ]);
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.poster.test'], ['id' => 2, 'name' => 'alt.binaries.other.test'],
        ]);
        $base = [
            'fromname' => $poster, 'categories_id' => 2040, 'groups_id' => 1,
            'size' => 25, 'totalpart' => 4, 'grabs' => 2, 'comments' => 0, 'passwordstatus' => 0,
            'postdate' => '2026-10-01 12:00:00', 'adddate' => '2026-10-02 12:00:00', 'leftguid' => 'a',
        ];
        foreach ([
            1 => [], 2 => [], 3 => [],
            4 => ['fromname' => trim($poster)], 5 => ['fromname' => strtolower($poster)],
            6 => ['fromname' => $poster.'.invalid'], 7 => ['groups_id' => 2],
            8 => ['categories_id' => 2050], 9 => ['size' => 31], 10 => ['passwordstatus' => 2],
            11 => ['comments' => 1], 12 => ['grabs' => 4], 13 => ['totalpart' => 6],
            14 => ['adddate' => '2026-10-03 00:00:00'],
        ] as $id => $overrides) {
            DB::table('releases')->insert(array_replace($base, [
                'id' => $id, 'guid' => sprintf('a0000000-0000-0000-0000-%012d', $id),
                'name' => 'Poster fixture '.$id, 'searchname' => sprintf('Release %02d', $id),
            ], $overrides));
        }
        DB::table('users')->insert(['id' => 1, 'username' => 'poster-test', 'email' => 'poster@example.test', 'password' => 'unused', 'api_token' => 'unused']);
        DB::table('release_reports')->insert([
            ['releases_id' => 1, 'users_id' => 1, 'reason' => 'first', 'status' => 'pending'],
            ['releases_id' => 1, 'users_id' => 1, 'reason' => 'second', 'status' => 'reviewed'],
        ]);
        DB::table('release_nfos')->insert(['releases_id' => 1, 'nfo' => 'Fixture NFO']);
        DB::table('video_data')->insert(['releases_id' => 1, 'containerformat' => 'Matroska']);
        DB::table('dnzb_failures')->insert(['release_id' => 1, 'users_id' => 1, 'failed' => 1]);
        $filters = ['filter_only' => true, 'poster' => $poster, 'ranges' => [
            'size' => ['min' => 20, 'max' => 30], 'comments' => ['max' => 0],
            'grabs' => ['min' => 1, 'max' => 3], 'totalpart' => ['min' => 1, 'max' => 5],
            'adddate_ts' => ['min' => strtotime('2026-10-02 00:00:00 UTC'), 'max' => strtotime('2026-10-02 23:59:59 UTC')],
        ]];
        $service = new ReleaseSearchService;
        $first = $service->search([], 'alt.binaries.poster.test', -1, -1, -1, -1,
            0, 2, 'name_asc', excludedCats: [2050], cat: [2040, 2050], filters: $filters);
        $this->assertSame([1, 2], $first->modelKeys());
        $this->assertSame(3, $first[0]->_totalrows);
        $this->assertSame($poster, $first[0]->fromname);
        $this->assertSame('Movies > HD', $first[0]->category_name);
        $this->assertSame('alt.binaries.poster.test', $first[0]->group_name);
        $this->assertSame(2, (int) $first[0]->report_count);
        $this->assertSame(1, (int) $first[0]->failed_count);
        $this->assertSame(1, (int) $first[0]->nfoid);
        $this->assertSame(1, (int) $first[0]->reid);
        $second = $service->search([], 'alt.binaries.poster.test', -1, -1, -1, -1,
            2, 2, 'name_asc', excludedCats: [2050], cat: [2040, 2050], filters: $filters);
        $this->assertSame([3], $second->modelKeys());
        $this->assertSame(3, $second[0]->_totalrows);
        $edited = $service->search([], 'alt.binaries.poster.test', -1, -1, -1, -1,
            0, 2, 'name_asc', excludedCats: [2050], cat: [2040, 2050],
            filters: array_replace($filters, ['poster' => trim($poster)]));
        $this->assertSame([4], $edited->modelKeys());
        $this->assertSame(1, $edited[0]->_totalrows);
        DB::table('releases')->where('id', 1)->delete();
        Cache::flush();
        $afterDeletion = $service->search([], 'alt.binaries.poster.test', -1, -1, -1, -1,
            0, 2, 'name_asc', excludedCats: [2050], cat: [2040, 2050], filters: $filters);
        $this->assertSame([2, 3], $afterDeletion->modelKeys());
        $this->assertSame(2, $afterDeletion[0]->_totalrows);
        $this->assertTrue($service->search([], 'alt.binaries.missing.test', -1, -1, -1, -1, filters: $filters)->isEmpty());
        $indexes = DB::select("SHOW INDEX FROM releases WHERE Key_name = 'ix_releases_fromname'");
        $this->assertSame('fromname', $indexes[0]->Column_name);
    }
}
