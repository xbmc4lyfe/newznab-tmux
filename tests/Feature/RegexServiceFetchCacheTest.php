<?php

namespace Tests\Feature;

use App\Services\RegexService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RegexServiceFetchCacheTest extends TestCase
{
    private int $regexQueries = 0;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge();
        DB::reconnect();
        // SQLite turns `X REGEXP Y` into regexp(Y, X): pattern first, then subject.
        DB::connection()->getPdo()->sqliteCreateFunction(
            'regexp',
            static fn (?string $pattern, ?string $subject): int => (int) preg_match('/'.str_replace('/', '\/', (string) $pattern).'/i', (string) $subject),
            2
        );
        $this->createRegexTable();
        Cache::flush();

        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'FROM collection_regexes')) {
                $this->regexQueries++;
            }
        });
        Event::listen(CacheHit::class, fn () => $this->cacheHits++);
        Event::listen(CacheMissed::class, fn () => $this->cacheMisses++);
    }

    public function test_reuses_the_process_copy_for_repeated_headers(): void
    {
        $this->insertRegex(1, '^alt\.binaries\.test$', '/^(?P<name>.+?) \[\d+\/\d+\]/');
        $regexes = new RegexService('collection_regexes');

        for ($i = 1; $i <= 100; $i++) {
            $this->assertSame('Show.Name', $regexes->tryRegex("Show.Name [{$i}/100] yEnc", 'alt.binaries.test'));
            $this->assertSame(1, $regexes->matchedRegex);
        }

        $this->assertSame(1, $this->regexQueries);
        $this->assertSame(1, $this->cacheMisses);
        $this->assertSame(0, $this->cacheHits);
    }

    public function test_rechecks_the_shared_cache_after_a_minute(): void
    {
        $this->insertRegex(1, '^alt\.binaries\.test$', '/^(?P<name>.+?) \[\d+\/\d+\]/');
        $regexes = new RegexService('collection_regexes');

        $regexes->tryRegex('Show.Name [1/2] yEnc', 'alt.binaries.test');
        $this->travel(59)->seconds();
        $regexes->tryRegex('Show.Name [2/2] yEnc', 'alt.binaries.test');
        $this->assertSame(0, $this->cacheHits);

        $this->travel(2)->seconds();
        $this->assertSame('Show.Name', $regexes->tryRegex('Show.Name [2/2] yEnc', 'alt.binaries.test'));

        $this->assertSame(1, $this->cacheHits);
        $this->assertSame(1, $this->regexQueries);
    }

    public function test_picks_up_regex_edits_once_the_shared_copy_expires(): void
    {
        $this->insertRegex(1, '^alt\.binaries\.test$', '/^(?P<name>.+?) \[\d+\/\d+\]/');
        $regexes = new RegexService('collection_regexes');
        $this->assertSame('Show.Name', $regexes->tryRegex('Show.Name [1/2] yEnc', 'alt.binaries.test'));

        DB::table('collection_regexes')->where('id', 1)->update(['regex' => '/^(?P<name>Show)\./']);

        $this->travel(61)->seconds();
        $this->assertSame('Show.Name', $regexes->tryRegex('Show.Name [1/2] yEnc', 'alt.binaries.test'), 'The shared copy is still valid.');

        $this->travel((int) config('nntmux.cache_expiry_long'))->minutes();
        $this->assertSame('Show', $regexes->tryRegex('Show.Name [1/2] yEnc', 'alt.binaries.test'));
    }

    public function test_keeps_each_group_separately(): void
    {
        $this->insertRegex(1, '^alt\.binaries\.one$', '/^(?P<name>One\.[^ ]+)/');
        $this->insertRegex(2, '^alt\.binaries\.two$', '/^(?P<name>Two\.[^ ]+)/');
        $regexes = new RegexService('collection_regexes');

        $this->assertSame('One.Show', $regexes->tryRegex('One.Show [1/2] yEnc', 'alt.binaries.one'));
        $this->assertSame('', $regexes->tryRegex('One.Show [1/2] yEnc', 'alt.binaries.two'));
        $this->assertSame('Two.Show', $regexes->tryRegex('Two.Show [1/2] yEnc', 'alt.binaries.two'));
        $this->assertSame('One.Show', $regexes->tryRegex('One.Show [2/2] yEnc', 'alt.binaries.one'));

        $this->assertSame(2, $this->regexQueries);
    }

    public function test_caches_a_group_without_regexes(): void
    {
        $regexes = new RegexService('collection_regexes');

        $this->assertSame('', $regexes->tryRegex('Show.Name [1/2] yEnc', 'alt.binaries.none'));
        $this->assertSame('', $regexes->tryRegex('Show.Name [2/2] yEnc', 'alt.binaries.none'));
        $this->assertSame(0, $regexes->matchedRegex);

        $this->assertSame(1, $this->regexQueries);
    }

    public function test_does_not_cache_a_failed_fetch(): void
    {
        Schema::drop('collection_regexes');
        $regexes = new RegexService('collection_regexes');

        try {
            $regexes->tryRegex('Show.Name [1/2] yEnc', 'alt.binaries.test');
            $this->fail('The missing table should have failed the fetch.');
        } catch (RuntimeException) {
            // QueryException; the next call must fetch again rather than see an empty list.
        }

        $this->createRegexTable();
        $this->insertRegex(1, '^alt\.binaries\.test$', '/^(?P<name>.+?) \[\d+\/\d+\]/');

        $this->assertSame('Show.Name', $regexes->tryRegex('Show.Name [1/2] yEnc', 'alt.binaries.test'));
    }

    private function createRegexTable(): void
    {
        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(5000),
            status INTEGER DEFAULT 1,
            description VARCHAR(1000) DEFAULT \'\',
            ordinal INTEGER DEFAULT 0
        )');
    }

    private function insertRegex(int $id, string $groupRegex, string $regex): void
    {
        DB::table('collection_regexes')->insert(['id' => $id, 'group_regex' => $groupRegex, 'regex' => $regex, 'status' => 1, 'ordinal' => 0]);
    }
}
