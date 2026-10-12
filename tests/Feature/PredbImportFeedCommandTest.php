<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PredbImportFeed;
use App\Facades\Search;
use App\Models\Predb;
use App\Services\Predb\Feeds\Contracts\PredbFeedSource;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\PredbOvhSource;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class PredbImportFeedCommandTest extends TestCase
{
    private MockInterface $search;

    /** @var list<string> */
    private array $defaultSources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultSources = (array) config('predb_feeds.sources');

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
        Http::preventStrayRequests();
    }

    #[Test]
    public function local_scene_sql_archive_is_manual_only_and_never_requests_http(): void
    {
        config(['predb_feeds.scene_archive_sql_file' => $this->fixture('scene-archive-sql-subset.sql')]);
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('scene_archive_sql', $factory->availableKeys());
        $this->assertNotContains('scene_archive_sql', $this->defaultSources);
        $this->assertContains('scene_archive_sql', (array) config('predb_feeds.manual_only_sources'));
        $this->assertNotContains('scene_archive_sql', (array) config('predb_feeds.single_page_sources'));
        $this->assertSame('scene_archive_sql', $factory->makeOne('scene_archive_sql')->key());
        Http::assertNothingSent();
    }

    #[Test]
    public function local_scene_sql_import_preserves_literal_music_and_unknown_archive_metadata(): void
    {
        config(['predb_feeds.scene_archive_sql_file' => $this->fixture('scene-archive-sql-subset.sql'), 'predb_feeds.page_size' => 2]);
        $this->artisan('predb:import-feed', ['--source' => ['scene_archive_sql'], '--pages' => 10])->assertSuccessful();
        $this->assertSame(6, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA')->exists());
        $this->assertTrue(Predb::query()->where('title', 'Alcatraz-Give_Me_Love-(CATRAZ001)-Bootleg-Vinyl-2007-MPX')->exists());
        $this->assertSame(6, Predb::query()->where('source', 'scene-archive-sql')->count());
        foreach (['predate', 'size', 'files', 'category', 'nukereason'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function local_scene_sql_preview_does_not_execute_sql_or_claim_known_pre_history(): void
    {
        config(['predb_feeds.scene_archive_sql_file' => $this->fixture('scene-archive-sql-subset.sql')]);
        $this->artisan('predb:import-feed', ['--source' => ['scene_archive_sql'], '--dry-run' => true, '--since' => '2020-01-01'])
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->expectsOutputToContain('incomplete')
            ->assertSuccessful();
        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
        Http::assertNothingSent();
    }

    #[Test]
    public function historical_dats_catalogue_requires_explicit_manual_selection(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('dats_pciso_1998', $factory->availableKeys());
        $this->assertNotContains('dats_pciso_1998', $this->defaultSources);
        $this->assertContains('dats_pciso_1998', (array) config('predb_feeds.manual_only_sources'));
        $this->assertNotContains('dats_pciso_1998', (array) config('predb_feeds.single_page_sources'));
        $this->assertSame('https://dats.site/exports/dat-sets/267', config('predb_feeds.endpoints.dats_pciso_1998'));
        $this->assertSame('dats_pciso_1998', $factory->makeOne('dats_pciso_1998')->key());
    }

    #[Test]
    public function historical_dats_import_keeps_independent_literal_names_and_unknown_metadata(): void
    {
        config(['predb_feeds.page_size' => 2]);
        $url = (string) config('predb_feeds.endpoints.dats_pciso_1998');
        Http::fake([$url => Http::response((string) file_get_contents($this->fixture('dats-pciso-1998-subset.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['dats_pciso_1998'], '--pages' => 10])->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
        foreach (['F22.Air.Dominance.Fighter-GOBBLE', 'Alien.Earth-RAZORISO', 'Bundesliga_Manager_98_GERMAN_ISO_FF'] as $title) {
            $this->assertTrue(Predb::query()->where('title', $title)->exists());
        }
        $this->assertSame(3, Predb::query()->where('source', 'dats.site')->count());
        foreach (['predate', 'size', 'files', 'category'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function historical_dats_preview_does_not_claim_known_pre_history_or_write_rows(): void
    {
        $url = (string) config('predb_feeds.endpoints.dats_pciso_1998');
        Http::fake([$url => Http::response((string) file_get_contents($this->fixture('dats-pciso-1998-subset.xml')), 200)]);
        $this->artisan('predb:import-feed', ['--source' => ['dats_pciso_1998'], '--dry-run' => true, '--since' => '2000-01-01'])
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->expectsOutputToContain('incomplete')
            ->assertSuccessful();
        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
        Http::assertSentCount(1);
    }

    #[Test]
    public function historical_nzedb_archive_requires_explicit_manual_selection(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('nzedb_archive', $factory->availableKeys());
        $this->assertNotContains('nzedb_archive', $this->defaultSources);
        $this->assertContains('nzedb_archive', (array) config('predb_feeds.manual_only_sources'));
        $this->assertNotContains('nzedb_archive', (array) config('predb_feeds.single_page_sources'));
        $this->assertSame('https://raw.githubusercontent.com/nZEDb/nZEDbPre_Dumps/ae51410b04bb3c59749b68582fbc4cc1e1f02d68/dumps/1689156393/1714642832_predb_dump.csv.gz', config('predb_feeds.endpoints.nzedb_archive'));
        $this->assertSame('nzedb_archive', $factory->makeOne('nzedb_archive')->key());
    }

    #[Test]
    public function historical_nzedb_archive_import_preserves_literal_titles_with_unknown_dates(): void
    {
        config(['predb_feeds.page_size' => 2]);
        $url = (string) config('predb_feeds.endpoints.nzedb_archive');
        Http::fake([$url => Http::response(gzencode((string) file_get_contents($this->fixture('nzedb-2024-05-02-subset.csv'))), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['nzedb_archive'], '--pages' => 10])->assertSuccessful();

        $this->assertSame(5, Predb::query()->count());
        $this->assertSame('X264', Predb::query()->where('title', 'FC.Bayern.Generation.Wembley.S01E05.720p.BluRay.x264-GUACAMOLE')->value('category'));
        $this->assertSame('Movie HD-1080p', Predb::query()->where('title', 'Ueberall.nur.nicht.hier.1999.German.DL.1080p.DSNP.H264-Oergel')->value('category'));
        $this->assertSame(5, Predb::query()->where('source', 'nzedb-pre-dumps')->count());
        foreach (['predate', 'size', 'files'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function historical_archive_preview_does_not_write_or_claim_date_coverage(): void
    {
        $url = (string) config('predb_feeds.endpoints.nzedb_archive');
        Http::fake([$url => Http::response(gzencode((string) file_get_contents($this->fixture('nzedb-2024-05-02-subset.csv'))), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['nzedb_archive'], '--dry-run' => true, '--since' => '2020-01-01'])
            ->expectsOutputToContain('incomplete: catalogue dates are unknown; --since cannot verify coverage')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();
        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_factory_builds_the_opt_in_predb_org_source(): void
    {
        $factory = new PredbFeedSourceFactory;

        $this->assertSame('predb_org', $factory->makeOne('predb_org')->key());
        $this->assertNotContains('predb_org', (array) config('predb_feeds.sources'));
    }

    #[Test]
    public function the_factory_builds_the_new_opt_in_sources_without_enabling_them_by_default(): void
    {
        $factory = new PredbFeedSourceFactory;

        foreach (['scnsrc_me', 'eztv', 'predb_ovh', 'apibay'] as $key) {
            $this->assertContains($key, $factory->availableKeys());
            $this->assertSame($key, $factory->makeOne($key)->key());
            $this->assertNotContains($key, (array) config('predb_feeds.sources'));
            $this->assertNotSame('', (string) config("predb_feeds.endpoints.{$key}"));
        }
    }

    #[Test]
    public function nima4k_import_is_opt_in_and_reports_latest_only_coverage(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('nima4k', $factory->availableKeys());
        $this->assertNotContains('nima4k', $this->defaultSources);
        $this->assertNotContains('nima4k', array_map(static fn (PredbFeedSource $source): string => $source->key(), $factory->make()));
        $this->assertContains('nima4k', (array) config('predb_feeds.single_page_sources'));
        $endpoint = 'https://nima4k.org/';
        $this->assertSame($endpoint, config('predb_feeds.endpoints.nima4k'));
        Http::fake([$endpoint => Http::response((string) file_get_contents($this->fixture('nima4k.html')), 200, ['Content-Type' => 'text/html'])]);
        $this->assertSame([], $factory->makeOne('nima4k')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['nima4k'], '--pages' => 5, '--since' => '2020-01-01'])
            ->expectsOutputToContain('incomplete: history paging is disabled for this source')
            ->assertSuccessful();

        $this->assertSame(10, Predb::query()->count());
        $first = Predb::query()->where('title', 'Formel.1.2026.GP.Singapur.Rennen.German.2160p.UHDTV.HDR.HEVC-NIMA4K')->firstOrFail();
        $this->assertSame('nima4k.org', $first->source);
        $this->assertNull($first->predate);
        $this->assertNull($first->category);
        $this->assertNull($first->size);
        $this->assertNull($first->files);
        Http::assertSentCount(1);
    }

    #[Test]
    public function nfodb_is_opt_in_and_never_fetches_a_second_rss_page(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('nfodb', $factory->availableKeys());
        $this->assertNotContains('nfodb', $this->defaultSources);
        $this->assertSame('https://nfodb.net.ru/rss.xml', config('predb_feeds.endpoints.nfodb'));
        $this->assertContains('nfodb', (array) config('predb_feeds.single_page_sources'));
        $this->assertNotContains('nfodb', array_map(static fn (PredbFeedSource $source): string => $source->key(), $factory->make()));

        Http::fake(['https://nfodb.net.ru/rss.xml' => Http::response((string) file_get_contents($this->fixture('nfodb.xml')), 200, ['Content-Type' => 'application/rss+xml'])]);
        $source = $factory->makeOne('nfodb');
        $this->assertSame('nfodb', $source->key());
        $this->assertSame([], $source->fetch(2));
        Http::assertNothingSent();
        $entries = $source->fetch();
        $this->assertCount(3, $entries);
        $this->assertSame('nfodb.net.ru', $entries[0]->source);
        $this->assertNull($entries[0]->predate);
        $this->assertSame('2026-10-11 12:26:12', $entries[0]->listedAt?->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function nfodb_history_import_keeps_true_pre_dates_and_reports_incomplete_coverage(): void
    {
        $title = 'DJ_Trike-Berlin_EP-(SYNCRO75)-24BIT-WEB-FLAC-2026-AGITB';
        DB::table('predb')->insert(['title' => $title, 'source' => '#PreNNTmux', 'predate' => '2026-10-10 01:02:03']);
        Http::fake(['https://nfodb.net.ru/rss.xml' => Http::response((string) file_get_contents($this->fixture('nfodb.xml')), 200, ['Content-Type' => 'application/rss+xml'])]);

        $this->artisan('predb:import-feed', ['--source' => ['nfodb'], '--since' => '2026-10-01'])
            ->expectsOutputToContain('incomplete: history paging is disabled for this source')
            ->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
        $this->assertSame('2026-10-10 01:02:03', DB::table('predb')->where('title', $title)->value('predate'));
        $this->assertSame('#PreNNTmux', Predb::query()->where('title', $title)->value('source'));
        $chart = Predb::query()->where('title', 'VA_-_Beatport_Cuprite_-_Badaman_Chart-WEB-2026')->firstOrFail();
        $this->assertSame('nfodb.net.ru', $chart->source);
        $this->assertSame('Dance', $chart->category);
        $this->assertNull($chart->predate);
        $this->assertNull(Predb::query()->where('title', 'VA_-_Beatport_Sofian_-_D.Y.F.I.T_Chart-WEB-2026')->value('predate'));
        $this->assertFalse(Predb::query()->where('title', 'Music catalogue news and updates')->exists());
        $this->search->shouldHaveReceived('insertPredb')->times(2);
        Http::assertSentCount(1);
    }

    #[Test]
    #[DataProvider('specialistCatalogueSources')]
    public function specialist_catalogues_are_opt_in_and_fetch_only_their_public_rss_endpoint(string $key, string $label, int $count, ?string $listedAt, string $endpoint, ?string $category): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains($key, $factory->availableKeys());
        $this->assertNotContains($key, $this->defaultSources);
        $this->assertNotContains($key, array_map(static fn (PredbFeedSource $source): string => $source->key(), $factory->make()));
        $this->assertSame($endpoint, config('predb_feeds.endpoints.'.$key));
        $this->assertContains($key, (array) config('predb_feeds.single_page_sources'));
        config(['predb_feeds.user_agent' => 'Catalogue-Test/1.0']);
        Http::fake([$endpoint => Http::response((string) file_get_contents($this->fixture($key.'.xml')), 200, ['Content-Type' => 'application/rss+xml'])]);

        $source = $factory->makeOne($key);
        $this->assertSame($key, $source->key());
        $this->assertSame([], $source->fetch(2));
        Http::assertNothingSent();
        $entries = $source->fetch();
        $this->assertCount($count, $entries);
        $this->assertSame($label, $entries[0]->source);
        $this->assertSame($category, $entries[0]->category);
        $this->assertNull($entries[0]->predate);
        $this->assertSame($listedAt, $entries[0]->listedAt?->toDateTimeString());
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === $endpoint
            && $request->hasHeader('User-Agent', 'Catalogue-Test/1.0'));
        Http::assertSentCount(1);
    }

    /** @return array<string, array{string, string, int, ?string, string, ?string}> */
    public static function specialistCatalogueSources(): array
    {
        return [
            'techno dates are unknown' => ['one_techno', '1techno.org', 2, null, 'https://1techno.org/rss.xml', null],
            'gabba dates include a timezone' => ['one_gabba', '1gabba.pw', 1, '2026-10-11 02:14:20', 'https://1gabba.pw/rss.xml', 'Web'],
            'ddlvalley categories are not mapped' => ['ddlvalley', 'ddlvalley.me', 1, '2026-10-09 23:37:19', 'https://www.ddlvalley.me/feed/', null],
            'hdencode display sizes are not metadata' => ['hdencode', 'hdencode.org', 8, '2026-10-11 14:56:46', 'https://hdencode.org/tag/movies/feed/', null],
            'hdencode TV keeps listing metadata separate' => ['hdencode_tv', 'hdencode.org', 1, '2026-10-11 15:24:20', 'https://hdencode.org/tag/tv-shows/feed/', null],
            'mp3scene historical release days are not PRE times' => ['mp3scene', 'mp3scene.info', 2, '2026-10-10 22:19:13', 'https://www.mp3scene.info/feeds/releases/', null],
        ];
    }

    #[Test]
    public function hdencode_import_links_the_observed_exact_release_names_without_renaming(): void
    {
        config(['nntmux.echocli' => false]);
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('searchname');
            $table->unsignedInteger('predb_id')->default(0);
            $table->boolean('isrenamed')->default(false);
            $table->dateTime('adddate')->nullable();
            $table->timestamps();
        });
        $titles = [
            'Jackass.Best.and.Last.2026.2160p.iT.WEB-DL.DTS-HD.MA.5.1.DV.HDR10P.H.265-BTBN',
            'Little.Fockers.2010.UHD.BluRay.1080p.DDP.5.1.Atmos.DV.HDR.x265-faBR',
            'Almost.Dead.1994.1080p.WEB.h264-NoRBiT',
            'Bird.vs.Plane.Miracle.on.the.Hudson.2018.1080p.AMZN.WEB-DL.DDP2.0.H.264-Kitsune',
            'The.Fix.2026.720p.NOW.WEB-DL.DD+5.1.H.264-playWEB',
            'The.Revenant.2015.BluRay.1080p.DDP.5.1.x264-hallowed',
            'Cursed.Waters.2026.1080p.AMZN.WEB-DL.DD+2.0.H.264-BobDobbs',
            'Deep.Dive.2026.1080p.AMZN.WEB-DL.DD+2.0.H.264-BobDobbs',
        ];
        foreach ($titles as $title) {
            DB::table('releases')->insert(['searchname' => $title, 'adddate' => now()->subHours(12)]);
        }
        DB::table('predb')->insert(['id' => 900, 'title' => $titles[0], 'source' => '#PreNNTmux']);
        $linkedId = DB::table('releases')->insertGetId(['searchname' => $titles[0], 'predb_id' => 900, 'adddate' => now()->subHours(12)]);
        $mismatchId = DB::table('releases')->insertGetId(['searchname' => str_replace('1994', '1995', $titles[2]), 'adddate' => now()->subHours(12)]);
        $oldId = DB::table('releases')->insertGetId(['searchname' => $titles[0], 'adddate' => now()->subDays(4)]);
        Http::fake(['https://hdencode.org/tag/movies/feed/' => Http::response((string) file_get_contents($this->fixture('hdencode.xml')), 200, ['Content-Type' => 'application/rss+xml'])]);

        $this->artisan('predb:import-feed', ['--source' => ['hdencode'], '--pages' => 5])->assertSuccessful();
        $this->artisan('predb:check', ['limit' => 3])->assertSuccessful();

        foreach ($titles as $index => $title) {
            $release = DB::table('releases')->where('id', $index + 1)->first();
            $this->assertNotNull($release);
            $this->assertSame($title, $release->searchname);
            $this->assertSame((int) Predb::query()->where('title', $title)->value('id'), (int) $release->predb_id);
            $this->assertSame(0, (int) $release->isrenamed);
        }
        $this->assertSame(900, (int) DB::table('releases')->where('id', $linkedId)->value('predb_id'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', $mismatchId)->value('predb_id'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', $oldId)->value('predb_id'));
        $this->assertSame(8, Predb::query()->count());
        $this->assertSame(0, Predb::query()->whereNotNull('predate')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('size')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('category')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function hdencode_tv_import_links_an_already_renamed_release_without_changing_processing_flags(): void
    {
        config(['nntmux.echocli' => false]);
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('searchname');
            $table->unsignedInteger('predb_id')->default(0);
            $table->boolean('isrenamed')->default(false);
            $table->boolean('proc_files')->default(false);
            $table->boolean('proc_nfo')->default(false);
            $table->boolean('proc_par2')->default(false);
            $table->dateTime('adddate')->nullable();
            $table->timestamps();
        });
        $title = 'Formula1.2026.Round17.Singapore.Race.F1TV.WEB-DL.2160p.HLG.H265.Multi-MWR';
        $releaseId = DB::table('releases')->insertGetId([
            'searchname' => $title,
            'isrenamed' => 1,
            'proc_files' => 1,
            'adddate' => now()->subHours(12),
        ]);
        Http::fake(['https://hdencode.org/tag/tv-shows/feed/' => Http::response((string) file_get_contents($this->fixture('hdencode_tv.xml')), 200, ['Content-Type' => 'application/rss+xml'])]);

        $this->artisan('predb:import-feed', ['--source' => ['hdencode_tv'], '--pages' => 5])->assertSuccessful();
        $this->artisan('predb:check', ['limit' => 3])->assertSuccessful();

        $release = DB::table('releases')->where('id', $releaseId)->first();
        $this->assertNotNull($release);
        $predb = Predb::query()->where('title', $title)->firstOrFail();
        $this->assertSame((int) $predb->id, (int) $release->predb_id);
        $this->assertSame($title, $release->searchname);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->proc_files);
        $this->assertSame(0, (int) $release->proc_nfo);
        $this->assertSame(0, (int) $release->proc_par2);
        $this->assertSame('hdencode.org', $predb->source);
        $this->assertNull($predb->category);
        $this->assertNull($predb->size);
        $this->assertNull($predb->files);
        $this->assertNull($predb->predate);
        Http::assertSentCount(1);
    }

    #[Test]
    public function predb_ovh_sends_the_configured_page_size_and_parses_documented_rows(): void
    {
        Http::fake(['predb.ovh/*' => Http::response((string) file_get_contents($this->fixture('predb_ovh.json')), 200, ['Content-Type' => 'application/json'])]);

        $entries = (new PredbOvhSource((string) config('predb_feeds.endpoints.predb_ovh'), 100))->fetch(2);

        $this->assertCount(3, $entries);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'count=100') && str_contains($request->url(), 'page=2'));
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
    public function details_only_entries_fill_existing_rows_but_never_create_one(): void
    {
        DB::table('predb')->insert(['title' => 'Known.Release-GRP', 'source' => 'zenet', 'size' => null, 'files' => null]);
        $info = static fn (string $title): PredbFeedEntry => new PredbFeedEntry(title: $title, source: 'corrupt-net', size: '67MB', files: '14', enrichOnly: true);

        $result = app(PredbFeedImporter::class)->import([$info('Known.Release-GRP'), $info('Unknown.Release-GRP')]);

        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 1], $result);
        $row = Predb::query()->where('title', 'Known.Release-GRP')->firstOrFail();
        $this->assertSame(['zenet', '67MB', '14'], [$row->source, $row->size, $row->files]);
        $this->assertFalse(Predb::query()->where('title', 'Unknown.Release-GRP')->exists());
    }

    #[Test]
    public function predb_net_history_stops_at_its_page_cap_instead_of_failing(): void
    {
        Http::fake(['api.predb.net/*' => Http::response(['status' => 'error', 'message' => 'Invalid page number > Min: 1 > Max: 100'], 400)]);

        $this->assertSame([], app(PredbFeedSourceFactory::class)->makeOne('predb_net')->fetch(PredbNetSource::MAX_PAGE + 1));
        Http::assertNothingSent();
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
    public function nswdb_requires_explicit_selection_and_pages_one_complete_snapshot(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('nswdb', $factory->availableKeys());
        $this->assertNotContains('nswdb', $this->defaultSources);
        $this->assertContains('nswdb', (array) config('predb_feeds.manual_only_sources'));
        $this->assertNotContains('nswdb', (array) config('predb_feeds.single_page_sources'));
        config(['predb_feeds.page_size' => 2, 'predb_feeds.request_delay_ms' => 0]);
        Http::fake(['https://nswdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('nswdb.xml')), 200, ['Content-Type' => 'application/xml'])]);

        $this->artisan('predb:import-feed', ['--source' => ['nswdb'], '--pages' => 10])->assertSuccessful();

        $this->assertSame(6, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'MARIO_Plus_RABBIDS_SPARKS_OF_HOPE_NSW')->exists());
        $this->assertTrue(Predb::query()->where('title', 'Mega_Man_11_NSW-VENOM')->exists());
        $this->assertSame(0, Predb::query()->whereNotNull('predate')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('size')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('category')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function nswdb_page_limit_reports_remaining_cached_catalogue_entries(): void
    {
        config(['predb_feeds.page_size' => 2]);
        Http::fake(['https://nswdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('nswdb.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['nswdb']])
            ->expectsOutputToContain('incomplete: processed 2 of 6 catalogue entries; increase --pages')
            ->assertSuccessful();

        $this->assertSame(2, Predb::query()->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function nswdb_configured_automatic_polling_skips_the_export_and_keeps_other_feeds(): void
    {
        config(['predb_feeds.sources' => ['nswdb', 'predb_me']]);
        Http::fake(['https://predb.me/?rss=1' => Http::response((string) file_get_contents($this->fixture('predb_me.xml')))]);

        $this->artisan('predb:import-feed')
            ->expectsOutputToContain('Skipping manual-only catalogue nswdb; select it explicitly with --source.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $request): bool => $request->url() === 'https://nswdb.com/xml.php');
        $this->assertGreaterThan(0, Predb::query()->count());
    }

    #[Test]
    public function nswdb_partial_dry_run_reports_coverage_without_database_or_search_writes(): void
    {
        config(['predb_feeds.page_size' => 2]);
        Http::fake(['https://nswdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('nswdb.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['nswdb'], '--dry-run' => true])
            ->expectsOutputToContain('incomplete: processed 2 of 6 catalogue entries; increase --pages')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
        Http::assertSentCount(1);
    }

    #[Test]
    public function nswdb_undated_export_cannot_claim_a_history_cutoff(): void
    {
        config(['predb_feeds.page_size' => 2]);
        Http::fake(['https://nswdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('nswdb.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['nswdb'], '--since' => '2020-01-01'])
            ->expectsOutputToContain('incomplete: catalogue dates are unknown; --since cannot verify coverage')
            ->assertSuccessful();

        $this->assertSame(2, Predb::query()->count());
        $this->assertSame(0, Predb::query()->whereNotNull('predate')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function three_ds_db_requires_explicit_selection_and_pages_one_complete_snapshot(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('3dsdb', $factory->availableKeys());
        $this->assertNotContains('3dsdb', $this->defaultSources);
        $this->assertContains('3dsdb', (array) config('predb_feeds.manual_only_sources'));
        $this->assertNotContains('3dsdb', (array) config('predb_feeds.single_page_sources'));
        config(['predb_feeds.page_size' => 2, 'predb_feeds.request_delay_ms' => 0]);
        Http::fake(['https://3dsdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('3dsdb.xml')), 200, ['Content-Type' => 'application/xml'])]);

        $this->artisan('predb:import-feed', ['--source' => ['3dsdb'], '--pages' => 10])->assertSuccessful();

        $this->assertSame(7, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'Atooi.Collection_3DS-JRP')->exists());
        $this->assertTrue(Predb::query()->where('title', 'Fire_Emblem_Musou_JPN_New3DS-HR')->exists());
        $this->assertSame(0, Predb::query()->whereNotNull('predate')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('size')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('category')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function three_ds_db_page_limit_reports_remaining_cached_catalogue_entries(): void
    {
        config(['predb_feeds.page_size' => 2]);
        Http::fake(['https://3dsdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('3dsdb.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['3dsdb']])
            ->expectsOutputToContain('incomplete: processed 2 of 7 catalogue entries; increase --pages')
            ->assertSuccessful();

        $this->assertSame(2, Predb::query()->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function three_ds_db_configured_automatic_polling_skips_the_export_and_keeps_other_feeds(): void
    {
        config(['predb_feeds.sources' => ['3dsdb', 'predb_me']]);
        Http::fake(['https://predb.me/?rss=1' => Http::response((string) file_get_contents($this->fixture('predb_me.xml')))]);

        $this->artisan('predb:import-feed')
            ->expectsOutputToContain('Skipping manual-only catalogue 3dsdb; select it explicitly with --source.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $request): bool => $request->url() === 'https://3dsdb.com/xml.php');
        $this->assertGreaterThan(0, Predb::query()->count());
    }

    #[Test]
    public function three_ds_db_partial_dry_run_reports_coverage_without_database_or_search_writes(): void
    {
        config(['predb_feeds.page_size' => 2]);
        Http::fake(['https://3dsdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('3dsdb.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['3dsdb'], '--dry-run' => true])
            ->expectsOutputToContain('incomplete: processed 2 of 7 catalogue entries; increase --pages')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
        Http::assertSentCount(1);
    }

    #[Test]
    public function three_ds_db_undated_export_cannot_claim_a_history_cutoff(): void
    {
        config(['predb_feeds.page_size' => 2]);
        Http::fake(['https://3dsdb.com/xml.php' => Http::response((string) file_get_contents($this->fixture('3dsdb.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['3dsdb'], '--since' => '2020-01-01'])
            ->expectsOutputToContain('incomplete: catalogue dates are unknown; --since cannot verify coverage')
            ->assertSuccessful();

        $this->assertSame(2, Predb::query()->count());
        $this->assertSame(0, Predb::query()->whereNotNull('predate')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function g4u_import_keeps_only_literal_today_names_and_unknown_metadata(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('g4u', $factory->availableKeys());
        $this->assertNotContains('g4u', $this->defaultSources);
        $this->assertContains('g4u', (array) config('predb_feeds.single_page_sources'));
        config(['predb_feeds.page_size' => 3]);
        Http::fake(['https://g4u.to/' => Http::response((string) file_get_contents($this->fixture('g4u.html')), 200, ['Content-Type' => 'text/html'])]);
        $this->assertSame([], $factory->makeOne('g4u')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['g4u'], '--pages' => 5])->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'Granblue.Fantasy.Versus.Rising.Deluxe.Edition.v2.61.MULTi9-ElAmigos')->exists());
        $this->assertSame(0, Predb::query()->whereNotNull('predate')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('size')->count());
        $this->assertSame(0, Predb::query()->whereNotNull('category')->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function gabber_import_keeps_only_literal_latest_names_and_unknown_metadata(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('gabber', $factory->availableKeys());
        $this->assertNotContains('gabber', $this->defaultSources);
        $this->assertContains('gabber', (array) config('predb_feeds.single_page_sources'));
        config(['predb_feeds.page_size' => 3]);
        Http::fake(['https://gabber.od.ua/0day' => Http::response((string) file_get_contents($this->fixture('gabber.html')), 200)]);
        $this->assertSame([], $factory->makeOne('gabber')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['gabber'], '--pages' => 5])->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'A-Kriv_-_Concentrate_2-(TWS61)-WEB-FLAC-2026')->exists());
        foreach (['predate', 'size', 'category', 'files'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function gabber_throttling_does_not_trigger_a_command_level_fast_retry(): void
    {
        config(['predb_feeds.rate_limit_retries' => 5]);
        Http::fake(['https://gabber.od.ua/0day' => Http::sequence()
            ->push('rate limited', 429, ['Retry-After' => '0'])
            ->push((string) file_get_contents($this->fixture('gabber.html')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['gabber']])->assertFailed();

        Http::assertSentCount(1);
        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
    }

    #[Test]
    public function movieblog_import_preserves_literal_titles_and_listing_only_dates(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('movieblog', $factory->availableKeys());
        $this->assertNotContains('movieblog', $this->defaultSources);
        $this->assertContains('movieblog', (array) config('predb_feeds.single_page_sources'));
        Http::fake(['https://movieblog.to/feed/' => Http::response((string) file_get_contents($this->fixture('movieblog.xml')), 200)]);
        $this->assertSame([], $factory->makeOne('movieblog')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['movieblog'], '--pages' => 5])->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'Wildauge.The.Midwife.2015.German.720p.BluRay.x264-TiG')->exists());
        $this->assertTrue(Predb::query()->where('title', 'Wildauge.The.Midwife.2015.German.BDRip.x264-TiG')->exists());
        $this->assertTrue(Predb::query()->where('title', 'Camp.Rock.3.2026.German.DL.1080p.DV.HDR.WEBRip.AV1-WOTT')->exists());
        foreach (['predate', 'size', 'category', 'files'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function movieblog_dry_run_previews_titles_without_database_or_index_writes(): void
    {
        Http::fake(['https://movieblog.to/feed/' => Http::response((string) file_get_contents($this->fixture('movieblog.xml')), 200)]);

        $this->artisan('predb:import-feed', ['--source' => ['movieblog'], '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, Predb::query()->count());
        $this->search->shouldNotHaveReceived('insertPredb');
        Http::assertSentCount(1);
    }

    #[Test]
    public function scnlog_import_preserves_literal_names_and_published_categories(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('scnlog', $factory->availableKeys());
        $this->assertNotContains('scnlog', $this->defaultSources);
        $this->assertContains('scnlog', (array) config('predb_feeds.single_page_sources'));
        Http::fake(['https://scnlog.me/feed/' => Http::response((string) file_get_contents($this->fixture('scnlog.xml')), 200)]);
        $this->assertSame([], $factory->makeOne('scnlog')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['scnlog'], '--pages' => 5])->assertSuccessful();

        $this->assertSame(5, Predb::query()->count());
        $this->assertSame('Music', Predb::query()->where('title', 'Naza_x_JUL-Pas_Dproblemes-SINGLE-WEB-FR-2026-OND')->value('category'));
        $this->assertSame('HDTV', Predb::query()->where('title', 'Lanterns.S01E03.1080p.AMZN.WEBRip.HEVC.10bit.AAC5.1-LuCY')->value('category'));
        $this->assertSame('FLAC', Predb::query()->where('title', 'Boo_Williams-Tribulation-(BMM90)-24BIT-WEB-FLAC-2020-BABAS')->value('category'));
        foreach (['predate', 'size', 'files'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function knaben_import_keeps_literal_movie_names_and_unknown_metadata(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('knaben', $factory->availableKeys());
        $this->assertNotContains('knaben', $this->defaultSources);
        $this->assertContains('knaben', (array) config('predb_feeds.single_page_sources'));
        Http::fake(['https://api.knaben.org/v2/browse?*' => Http::response((string) file_get_contents($this->fixture('knaben-movies.json')), 200)]);
        $this->assertSame([], $factory->makeOne('knaben')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['knaben'], '--pages' => 5])->assertSuccessful();

        $this->assertSame(8, Predb::query()->count());
        $this->assertSame('Movies / HD', Predb::query()->where('title', 'Novocaine.2025.1080p.BluRay.x264-playHD_EniaHD')->value('category'));
        $this->assertSame(0, Predb::query()->where('title', 'Novocaine.2025.1080p.BluRay.x264-playHD')->count());
        foreach (['predate', 'size', 'files'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function crowdnfo_import_preserves_canonical_headings_without_inferred_metadata(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('crowdnfo', $factory->availableKeys());
        $this->assertNotContains('crowdnfo', $this->defaultSources);
        $this->assertContains('crowdnfo', (array) config('predb_feeds.single_page_sources'));
        Http::fake(['https://crowdnfo.net/' => Http::response((string) file_get_contents($this->fixture('crowdnfo-home.html')), 200)]);
        $this->assertSame([], $factory->makeOne('crowdnfo')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['crowdnfo'], '--pages' => 5])->assertSuccessful();

        $this->assertSame(20, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'American.Pickers.S02E06.Im.Bickerhimmel.GERMAN.DOKU.WS.HDTVRiP.XviD-TVP')->exists());
        foreach (['predate', 'size', 'files', 'category'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function seriousaboutsound_import_preserves_literal_music_titles_and_unknown_metadata(): void
    {
        $factory = new PredbFeedSourceFactory;
        $this->assertContains('seriousaboutsound', $factory->availableKeys());
        $this->assertNotContains('seriousaboutsound', $this->defaultSources);
        $this->assertContains('seriousaboutsound', (array) config('predb_feeds.single_page_sources'));
        $url = 'https://seriousaboutsound.net/forum/48-releases/?sortby=start_date&sortdirection=desc';
        Http::fake([$url => Http::response((string) file_get_contents($this->fixture('seriousaboutsound-releases.html')), 200)]);
        $this->assertSame([], $factory->makeOne('seriousaboutsound')->fetch(2));
        Http::assertNothingSent();

        $this->artisan('predb:import-feed', ['--source' => ['seriousaboutsound'], '--pages' => 5])->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
        $this->assertTrue(Predb::query()->where('title', 'Nerutto_-_Elements-(DP26)-SINGLE-WEB-2022-iDC')->exists());
        $this->assertFalse(Predb::query()->where('title', "Renegade's Corner")->exists());
        $this->assertSame(3, Predb::query()->where('source', 'seriousaboutsound.net')->count());
        foreach (['predate', 'size', 'files', 'category'] as $column) {
            $this->assertSame(0, Predb::query()->whereNotNull($column)->count());
        }
        Http::assertSentCount(1);
    }

    #[Test]
    public function new_catalogue_sources_preview_without_writes_or_history_completion_claims(): void
    {
        $fixtures = [
            'scnlog' => ['https://scnlog.me/feed/', 'scnlog.xml'],
            'knaben' => ['https://api.knaben.org/v2/browse?*', 'knaben-movies.json'],
            'crowdnfo' => ['https://crowdnfo.net/', 'crowdnfo-home.html'],
            'seriousaboutsound' => ['https://seriousaboutsound.net/forum/48-releases/?sortby=start_date&sortdirection=desc', 'seriousaboutsound-releases.html'],
        ];
        foreach ($fixtures as $key => [$url, $fixture]) {
            Http::fake([$url => Http::response((string) file_get_contents($this->fixture($fixture)), 200)]);
            $this->artisan('predb:import-feed', ['--source' => [$key], '--dry-run' => true, '--since' => '2020-01-01'])
                ->expectsOutputToContain('Dry run: nothing was written.')
                ->expectsOutputToContain('incomplete: history paging is disabled for this source')
                ->assertSuccessful();
            $this->assertSame(0, Predb::query()->count());
            Http::assertSentCount(1);
        }
        $this->search->shouldNotHaveReceived('insertPredb');
    }

    #[Test]
    public function configured_manual_catalogues_do_not_block_automatic_feed_polling(): void
    {
        config(['predb_feeds.sources' => ['predb_club', 'predb_me'], 'predb_feeds.manual_only_sources' => ['predb_club']]);
        Http::fake([
            'https://predb.club/api/v1/*' => Http::response((string) file_get_contents($this->fixture('predb_club.json'))),
            'https://predb.me/?rss=1' => Http::response((string) file_get_contents($this->fixture('predb_me.xml'))),
        ]);

        $this->artisan('predb:import-feed')
            ->expectsOutputToContain('Skipping manual-only catalogue predb_club; select it explicitly with --source.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertNotSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://predb.club/api/v1/'));
        $this->assertGreaterThan(0, Predb::query()->count());
    }

    #[Test]
    public function manual_catalogues_can_be_selected_explicitly(): void
    {
        config(['predb_feeds.manual_only_sources' => ['predb_club']]);
        Http::fake(['https://predb.club/api/v1/*' => Http::response((string) file_get_contents($this->fixture('predb_club.json')))]);

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertGreaterThan(0, Predb::query()->count());
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
    public function the_scheduled_tmux_health_check_follows_its_flag(): void
    {
        $event = $this->scheduledEvent('tmux:health-check --auto-restart');

        config(['tmux.scheduled_health_check' => true]);
        $this->assertTrue($event->filtersPass($this->app));

        config(['tmux.scheduled_health_check' => false]);
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

    #[Test]
    public function a_nuke_change_only_applies_while_the_observed_status_is_unchanged(): void
    {
        DB::table('predb')->insert(['title' => 'Nuke-GRP', 'source' => '#PreNNTmux', 'nuked' => Predb::PRE_UNNUKED]);
        $id = (int) DB::table('predb')->where('title', 'Nuke-GRP')->value('id');

        // The feed planned NONUKE -> NUKED, but IRC has since recorded UNNUKED.
        $changed = (new ReflectionMethod(PredbFeedImporter::class, 'applyChanges'))
            ->invoke(app(PredbFeedImporter::class), $id, ['nuked' => Predb::PRE_NUKED, 'nukereason' => 'dupe', 'nuked_from' => Predb::PRE_NONUKE]);

        $this->assertFalse($changed);
        $this->assertSame(Predb::PRE_UNNUKED, (int) DB::table('predb')->where('id', $id)->value('nuked'));
    }

    #[Test]
    public function a_missing_nuke_reason_is_filled_when_the_status_already_matches(): void
    {
        DB::table('predb')->insert(['title' => 'Some.Movie.2026.1080p.WEB.H264-NUKED', 'source' => '#PreNNTmux', 'nuked' => Predb::PRE_NUKED, 'nukereason' => null]);
        Http::fake(['predb.club/*' => Http::response((string) file_get_contents($this->fixture('predb_club.json')))]);

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertSuccessful();

        $this->assertSame('dupe.2026-10-01', Predb::query()->where('title', 'Some.Movie.2026.1080p.WEB.H264-NUKED')->value('nukereason'));
    }

    #[Test]
    public function rate_limit_waits_use_retry_after_or_the_reset_header_without_shortening(): void
    {
        $wait = new ReflectionMethod(PredbImportFeed::class, 'rateLimitWaitSeconds');
        $instance = app(PredbImportFeed::class);

        $this->assertSame(1200, $wait->invoke($instance, '1200', null, 60));
        $this->assertEqualsWithDelta(300, $wait->invoke($instance, now()->addSeconds(300)->toRfc7231String(), null, 60), 2);
        $this->assertEqualsWithDelta(120, $wait->invoke($instance, null, (string) now()->addSeconds(120)->getTimestamp(), 60), 2);
        $this->assertSame(60, $wait->invoke($instance, null, null, 60));
        $this->assertSame(60, $wait->invoke($instance, 'not-a-date', null, 60));
    }

    #[Test]
    public function a_rate_limit_longer_than_the_max_wait_fails_the_source_without_retrying_early(): void
    {
        config(['predb_feeds.request_delay_ms' => 0, 'predb_feeds.rate_limit_max_wait_seconds' => 900]);
        Http::fake(['predb.club/*' => Http::response(['message' => 'rate limit exceeded'], 429, ['Retry-After' => '3600'])]);

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertFailed();

        Http::assertSentCount(1);
    }

    #[Test]
    public function impossible_or_trailing_since_dates_are_rejected(): void
    {
        $this->artisan('predb:import-feed', ['--since' => '2026-02-31'])->assertFailed();
        $this->artisan('predb:import-feed', ['--since' => '2026-09-25garbage'])->assertFailed();
    }

    #[Test]
    public function a_dry_run_counts_a_title_seen_twice_as_one_insert(): void
    {
        $importer = app(PredbFeedImporter::class);
        $first = new PredbFeedEntry(title: 'Dup.Title-GRP', source: 'predb.club', category: 'TV');
        $second = new PredbFeedEntry(title: 'Dup.Title-GRP', source: 'predb.net', category: 'TV-HD', size: '5MB');

        $result = $importer->import([$first, $second, $first], dryRun: true);

        $this->assertSame(['inserted' => 1, 'updated' => 1, 'skipped' => 1], $result);
        $this->assertSame(0, Predb::query()->count());
    }

    #[Test]
    public function the_search_index_is_refreshed_from_the_current_row(): void
    {
        DB::table('predb')->insert(['title' => 'Index-GRP', 'source' => '#PreNNTmux', 'filename' => 'old.name', 'size' => null]);
        $id = (int) DB::table('predb')->where('title', 'Index-GRP')->value('id');
        // Simulate the IRC scraper renaming the file after the importer read the row.
        Predb::retrieved(static function (Predb $predb) use ($id): void {
            if ((int) $predb->id === $id) {
                DB::table('predb')->where('id', $id)->update(['filename' => 'new.name']);
            }
        });

        app(PredbFeedImporter::class)->import([new PredbFeedEntry(title: 'Index-GRP', source: 'predb.club', size: '5MB')]);

        $this->search->shouldHaveReceived('updatePreDb')->with(Mockery::on(static fn (array $doc): bool => $doc['filename'] === 'new.name'))->once();
    }

    #[Test]
    public function a_history_import_that_ends_before_the_cutoff_is_reported_incomplete(): void
    {
        config(['predb_feeds.request_delay_ms' => 0]);
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $rows = (int) ($query['page'] ?? 1) === 1
                ? [['name' => 'Recent-GRP', 'cat' => 'TV', 'size' => 1, 'files' => 1, 'preAt' => now()->subDay()->getTimestamp(), 'nuke' => null]]
                : [];

            return Http::response(['data' => ['rows' => $rows]]);
        });

        $this->artisan('predb:import-feed', ['--source' => ['predb_club'], '--since' => '14d'])
            ->expectsOutputToContain('incomplete')
            ->assertSuccessful();
    }

    #[Test]
    public function a_reason_update_keeps_a_reason_written_meanwhile(): void
    {
        DB::table('predb')->insert(['title' => 'Reason-GRP', 'source' => '#PreNNTmux', 'nuked' => Predb::PRE_NUKED, 'nukereason' => 'irc.newer']);
        $id = (int) DB::table('predb')->where('title', 'Reason-GRP')->value('id');

        $changed = (new ReflectionMethod(PredbFeedImporter::class, 'applyChanges'))
            ->invoke(app(PredbFeedImporter::class), $id, ['nukereason' => 'feed.reason', 'nuked_status' => Predb::PRE_NUKED, 'nukereason_from' => null]);

        $this->assertFalse($changed);
        $this->assertSame('irc.newer', DB::table('predb')->where('id', $id)->value('nukereason'));
    }

    #[Test]
    public function a_successful_response_without_the_expected_structure_fails_the_source(): void
    {
        Http::fake([
            'predb.club/*' => Http::response('<html>proxy error</html>'),
            'api.predb.net/*' => Http::response(['status' => 'success', 'unexpected' => true]),
        ]);

        $this->artisan('predb:import-feed', ['--source' => ['predb_club', 'predb_net']])->assertFailed();
    }

    #[Test]
    public function an_application_rate_limit_envelope_is_backed_off_and_retried(): void
    {
        config(['predb_feeds.request_delay_ms' => 0, 'predb_feeds.rate_limit_wait_seconds' => 0]);
        Http::fakeSequence('predb.club/*')
            ->push(['status' => 'error', 'message' => 'Rate limit exceeded, try again later', 'data' => null])
            ->push((string) file_get_contents($this->fixture('predb_club.json')));

        $this->artisan('predb:import-feed', ['--source' => ['predb_club']])->assertSuccessful();

        $this->assertSame(3, Predb::query()->count());
    }

    #[Test]
    public function xrel_requests_are_clamped_to_its_minimum_page_size(): void
    {
        config(['predb_feeds.page_size' => 2]);
        Http::fake(['api.xrel.to/*' => Http::response(['pagination' => ['total_pages' => 1], 'list' => []])]);

        $this->artisan('predb:import-feed', ['--source' => ['xrel']])->assertSuccessful();

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'per_page=5'));
    }

    #[Test]
    public function malformed_collections_fail_every_source(): void
    {
        Http::fake([
            'predb.club/*' => Http::response(['status' => 'success', 'data' => ['rows' => ['unexpected' => 'object']]]),
            'api.predb.net/*' => Http::response(['status' => 'success', 'data' => ['a' => 1]]),
            'api.srrdb.com/*' => Http::response('<html>blocked</html>'),
            'api.xrel.to/*' => Http::response(['pagination' => ['total_pages' => 3]]),
        ]);

        $this->artisan('predb:import-feed', ['--source' => ['predb_club', 'predb_net', 'srrdb', 'xrel']])->assertFailed();
    }

    #[Test]
    public function undated_history_pages_are_reported_incomplete(): void
    {
        Http::fake(['predb.me/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><item><title>No.Date-GRP</title></item></channel></rss>', 200, ['Content-Type' => 'application/xml'])]);

        $this->artisan('predb:import-feed', ['--source' => ['predb_me'], '--since' => '14d'])
            ->expectsOutputToContain('incomplete')
            ->assertSuccessful();
    }

    #[Test]
    public function a_dry_run_matches_titles_case_insensitively_like_the_database(): void
    {
        $result = app(PredbFeedImporter::class)->import([
            new PredbFeedEntry(title: 'Release-GRP', source: 'predb.club'),
            new PredbFeedEntry(title: 'release-grp', source: 'predb.net'),
        ], dryRun: true);

        $this->assertSame(1, $result['inserted']);
    }

    #[Test]
    public function since_mode_pages_by_listing_time_when_a_source_has_no_pre_time(): void
    {
        // srrDB is single-page by default; lift that here to exercise listedAt-based paging.
        config(['predb_feeds.request_delay_ms' => 0, 'predb_feeds.single_page_sources' => []]);
        $requested = [];
        Http::fake(function (Request $request) use (&$requested) {
            $requested[] = $request->url();
            $skip = preg_match('/skip:(\d+)/', $request->url(), $m) === 1 ? (int) $m[1] : 0;
            $days = $skip === 0 ? 1 : 20;

            return Http::response(['results' => [['release' => 'Srr.Page'.$skip.'-GRP', 'date' => now('Europe/Brussels')->subDays($days)->format('Y-m-d H:i:s'), 'size' => 1]]]);
        });

        $this->artisan('predb:import-feed', ['--source' => ['srrdb'], '--since' => '14d'])->assertSuccessful();

        $this->assertCount(2, $requested);
        $this->assertNull(Predb::query()->where('title', 'Srr.Page0-GRP')->value('predate'));
    }

    #[Test]
    public function an_unchanged_existing_entry_is_re_indexed_from_the_current_row(): void
    {
        DB::table('predb')->insert(['title' => 'Known-GRP', 'source' => '#PreNNTmux', 'category' => 'TV', 'filename' => 'old.mkv']);
        $id = (int) DB::table('predb')->where('title', 'Known-GRP')->value('id');
        // Simulate the IRC scraper renaming the file right after the importer's lookup.
        Predb::retrieved(static function (Predb $predb) use ($id): void {
            if ((int) $predb->id === $id) {
                DB::table('predb')->where('id', $id)->update(['filename' => 'known.mkv']);
            }
        });

        $result = app(PredbFeedImporter::class)->import([new PredbFeedEntry(title: 'Known-GRP', source: 'predb.club', category: 'TV')]);

        $this->assertSame(1, $result['skipped']);
        $this->search->shouldHaveReceived('updatePreDb')->with(Mockery::on(static fn (array $doc): bool => $doc['title'] === 'Known-GRP' && $doc['filename'] === 'known.mkv'))->once();
    }

    #[Test]
    public function srrdb_is_never_paged_deeply_and_is_not_a_default_source(): void
    {
        config(['predb_feeds.request_delay_ms' => 0]);
        Http::fake(['api.srrdb.com/*' => Http::response(['results' => [['release' => 'Srr.Only-GRP', 'date' => now('Europe/Brussels')->subDay()->format('Y-m-d H:i:s')]]])]);

        $this->artisan('predb:import-feed', ['--source' => ['srrdb'], '--since' => '14d'])
            ->expectsOutputToContain('incomplete')
            ->assertSuccessful();
        $this->artisan('predb:import-feed', ['--source' => ['srrdb'], '--pages' => 5])->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertNotContains('srrdb', (require base_path('config/predb_feeds.php'))['sources']);
    }

    private function fakeFeeds(): void
    {
        Http::fake([
            'predb.club/*' => Http::response((string) file_get_contents($this->fixture('predb_club.json'))),
            'api.predb.net/*' => Http::response((string) file_get_contents($this->fixture('predb_net.json'))),
            'predb.me/*' => Http::response((string) file_get_contents($this->fixture('predb_me.xml')), 200, ['Content-Type' => 'application/xml']),
        ]);
    }

    #[Test]
    public function the_predb_full_text_name_pass_runs_on_its_own_schedule(): void
    {
        $event = $this->scheduledEvent('multiprocessing:fixrelnames predbft');

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        // One launcher only: the scheduled run stops when the tmux cycle runs the pass instead, whatever a
        // legacy TMUX_FIX_NAMES_PREDBFT=true says.
        config(['tmux.fix_names.predbft' => true, 'tmux.fix_names.predbft_scheduled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
        config(['tmux.fix_names.predbft_scheduled' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        // An operator who turned the pass off keeps it off.
        config(['tmux.fix_names.predbft' => false, 'tmux.fix_names.predbft_scheduled' => true]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    #[Test]
    public function releases_are_linked_to_late_arriving_predb_titles_on_a_schedule(): void
    {
        $event = $this->scheduledEvent('predb:check 3');

        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
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
