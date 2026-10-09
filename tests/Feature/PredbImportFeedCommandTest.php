<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedImporter;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class PredbImportFeedCommandTest extends TestCase
{
    private MockInterface $search;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'predb_feeds.enabled' => true,
            'predb_feeds.sources' => ['predb_club', 'predb_net', 'predb_me'],
            'predb_feeds.endpoints.predb_club' => 'https://predb.club/api/v1/',
            'predb_feeds.endpoints.predb_net' => 'https://api.predb.net/',
            'predb_feeds.endpoints.predb_me' => 'https://predb.me/?rss=1',
        ]);

        DB::purge();
        DB::reconnect();

        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('')->unique();
            $table->string('nfo')->nullable();
            $table->string('size', 50)->nullable();
            $table->string('category')->nullable();
            $table->dateTime('predate')->nullable();
            $table->string('source', 50)->default('');
            $table->unsignedInteger('requestid')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->tinyInteger('nuked')->default(0);
            $table->string('nukereason')->nullable();
            $table->string('files', 50)->nullable();
            $table->string('filename')->default('');
            $table->boolean('searched')->default(false);
        });

        $this->search = Search::spy();
    }

    #[Test]
    public function it_inserts_new_pres_from_every_source_and_indexes_them(): void
    {
        $this->fakeFeeds();

        $this->artisan('predb:import-feed')->assertSuccessful();

        // 3 predb.club + 1 new predb.net (the other duplicates predb.club) + 1 new predb.me RSS title.
        $this->assertSame(5, Predb::query()->count());
        $club = Predb::query()->where('title', 'WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS')->firstOrFail();
        $this->assertSame('predb.club', $club->source);
        $this->assertSame('TV-SD-FR', $club->category);
        $this->assertSame('457MB', $club->size);
        $this->assertSame(Predb::PRE_NUKED, (int) Predb::query()->where('title', 'Some.Movie.2026.1080p.WEB.H264-NUKED')->value('nuked'));
        $this->assertSame('predb.me', Predb::query()->where('title', 'Elsbeth.S04E01.1080p.HDTV.x264-SYNCOPY')->value('source'));

        $this->search->shouldHaveReceived('insertPredb')->times(5);
    }

    #[Test]
    public function existing_rows_only_gain_missing_details(): void
    {
        DB::table('predb')->insert([
            'title' => 'WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS',
            'source' => '#PreNNTmux',
            'category' => 'TV-IRC',
            'size' => null,
            'predate' => '2026-10-09 02:39:00',
        ]);
        $this->fakeFeeds();

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertSuccessful();

        $row = Predb::query()->where('title', 'WAR.2026.S01E02.FRENCH.WEB.H264-BAWLS')->firstOrFail();
        $this->assertSame('#PreNNTmux', $row->source);
        $this->assertSame('TV-IRC', $row->category);
        $this->assertSame('457MB', $row->size);
        $this->assertSame('32', $row->files);
        $this->search->shouldHaveReceived('updatePreDb')->once();
    }

    #[Test]
    public function a_failing_source_does_not_stop_the_others(): void
    {
        Http::fake([
            'predb.club/*' => Http::response('down', 503),
            'api.predb.net/*' => Http::response((string) file_get_contents($this->fixture('predb_net.json'))),
            'predb.me/*' => Http::response('', 500),
        ]);

        $this->artisan('predb:import-feed')->assertSuccessful();

        $this->assertSame(2, Predb::query()->count());
    }

    #[Test]
    public function it_fails_when_every_source_fails(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->artisan('predb:import-feed')->assertFailed();
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->fakeFeeds();

        $this->artisan('predb:import-feed', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
    }

    #[Test]
    public function unknown_sources_are_rejected(): void
    {
        $this->artisan('predb:import-feed', ['--source' => ['nope']])->assertFailed();
    }

    #[Test]
    public function the_schedule_entry_follows_the_enabled_flag(): void
    {
        $event = $this->scheduledEvent('predb:import-feed');

        config(['predb_feeds.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        config(['predb_feeds.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    #[Test]
    public function since_mode_pages_back_until_entries_are_older_than_the_cutoff(): void
    {
        config(['predb_feeds.request_delay_ms' => 0]);
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $requested[] = $page;
            $preAt = now()->subDays($page * 5)->getTimestamp();

            return Http::response(['data' => ['rows' => [['name' => 'Release.Page'.$page.'-GRP', 'cat' => 'TV', 'size' => 1, 'files' => 1, 'preAt' => $preAt, 'nuke' => null]]]]);
        });

        $this->artisan('predb:import-feed', ['--source' => ['predb_club'], '--since' => '14d'])->assertSuccessful();

        // Pages 1-2 are newer than 14 days; page 3 (15 days old) crosses the cutoff and is the last one fetched.
        $this->assertSame([1, 2, 3], $requested);
        $this->assertSame(3, Predb::query()->count());
    }

    #[Test]
    public function rate_limited_pages_are_retried_after_backing_off(): void
    {
        config(['predb_feeds.request_delay_ms' => 0, 'predb_feeds.rate_limit_wait_seconds' => 0]);
        Http::fakeSequence('predb.club/*')
            ->push(['message' => 'rate limit exceeded'], 429)
            ->push((string) file_get_contents($this->fixture('predb_club.json')));

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
    }

    #[Test]
    public function an_invalid_since_value_is_rejected(): void
    {
        $this->artisan('predb:import-feed', ['--since' => 'soon'])->assertFailed();
    }

    #[Test]
    public function a_429_is_left_to_the_backoff_loop_instead_of_being_retried_immediately(): void
    {
        config(['predb_feeds.request_delay_ms' => 0, 'predb_feeds.rate_limit_retries' => 0]);
        Http::fakeSequence('predb.club/*')
            ->push(['message' => 'rate limit exceeded'], 429)
            ->push((string) file_get_contents($this->fixture('predb_club.json')));

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertFailed();

        Http::assertSentCount(1);
    }

    #[Test]
    public function client_errors_are_not_retried_but_server_errors_are(): void
    {
        Http::fakeSequence('predb.club/*')->push('missing', 404);
        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertFailed();
        Http::assertSentCount(1);

        Http::fakeSequence('api.predb.net/*')
            ->push('oops', 503)
            ->push((string) file_get_contents($this->fixture('predb_net.json')));
        $this->artisan('predb:import-feed', ['--source' => ['predb_net']])->assertSuccessful();
        $this->assertSame(2, Predb::query()->count());
    }

    #[Test]
    public function an_api_error_envelope_fails_the_source(): void
    {
        Http::fake(['predb.club/*' => Http::response(['status' => 'error', 'message' => 'maintenance', 'data' => null])]);

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertFailed();
    }

    #[Test]
    public function an_unknown_predate_is_stored_as_null_and_filled_later(): void
    {
        Http::fake(['predb.me/*' => Http::response((string) file_get_contents($this->fixture('predb_me.xml')), 200, ['Content-Type' => 'application/xml'])]);
        $this->artisan('predb:import-feed', ['--source' => ['predb_me']])->assertSuccessful();
        $this->assertNull(Predb::query()->where('title', 'Nybble_and_Nibble_CD32_AMIGA-bADkARMA')->value('predate'));

        Http::fake(['predb.club/*' => Http::response((string) file_get_contents($this->fixture('predb_club.json')))]);
        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertSuccessful();
        $this->assertNotNull(Predb::query()->where('title', 'Nybble_and_Nibble_CD32_AMIGA-bADkARMA')->value('predate'));
    }

    #[Test]
    public function database_failures_fail_the_run_instead_of_being_counted_as_skipped(): void
    {
        Schema::drop('predb');
        $this->fakeFeeds();

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertFailed();
    }

    #[Test]
    public function filling_missing_fields_never_overwrites_a_value_written_meanwhile(): void
    {
        DB::table('predb')->insert(['title' => 'Race-GRP', 'source' => '#PreNNTmux', 'size' => '5MB', 'category' => null]);
        $id = (int) DB::table('predb')->where('title', 'Race-GRP')->value('id');

        $changed = (new ReflectionMethod(PredbFeedImporter::class, 'applyChanges'))
            ->invoke(app(PredbFeedImporter::class), $id, ['size' => '1MB', 'category' => 'TV']);

        $this->assertTrue($changed);
        $this->assertSame('5MB', DB::table('predb')->where('id', $id)->value('size'));
        $this->assertSame('TV', DB::table('predb')->where('id', $id)->value('category'));
    }

    private function fakeFeeds(): void
    {
        Http::fake([
            'predb.club/*' => Http::response((string) file_get_contents($this->fixture('predb_club.json'))),
            'api.predb.net/*' => Http::response((string) file_get_contents($this->fixture('predb_net.json'))),
            'predb.me/*' => Http::response((string) file_get_contents($this->fixture('predb_me.xml')), 200, ['Content-Type' => 'application/xml']),
        ]);
    }

    private function scheduledEvent(string $command): Event
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("No scheduled event for [{$command}].");
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__).'/Fixtures/predb/'.$name;
    }
}
