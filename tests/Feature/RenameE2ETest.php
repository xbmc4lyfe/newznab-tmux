<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\NameFixing\NameFixingService;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxTaskRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\ConfigurationTestBuilder;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

class RenameE2ETest extends TestCase
{
    use Par2RenameTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'tmux.fix_names.full_backlog' => true,
            'tmux.fix_names.full_backlog_interval_minutes' => 60,
            'tmux.fix_names.predbft' => false,
            'tmux.fix_names.predbft_scheduled' => false,
            'cache.stores.fixnames_claims' => ['driver' => 'array'],
            'tmux.fix_names.cache_store' => 'fixnames_claims',
        ]);
        Cache::flush();

        $this->createPar2RenameSchema();

        Schema::table('predb', function ($table): void {
            $table->string('filename')->default('');
        });
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();

        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);

        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('UNCOMPRESS', static fn (?string $value): ?string => $value);
        $pdo->sqliteCreateFunction('REGEXP', static fn (string $pattern, ?string $value): bool => $value !== null && (bool) preg_match('/'.str_replace('/', '\/', $pattern).'/', $value));
        $pdo->sqliteCreateFunction('MOD', static fn (int $a, int $b): int => $b !== 0 ? $a % $b : 0);

        if (! Schema::hasTable('root_categories')) {
            Schema::create('root_categories', function ($table): void {
                $table->increments('id');
                $table->string('title')->default('');
                $table->integer('status')->default(1);
            });
            DB::table('root_categories')->insert([
                ['id' => 1, 'title' => 'Other', 'status' => 1],
                ['id' => 2, 'title' => 'Movies', 'status' => 1],
                ['id' => 5, 'title' => 'TV', 'status' => 1],
            ]);
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function ($table): void {
                $table->increments('id');
                $table->string('title')->default('');
                $table->integer('root_categories_id')->default(1);
                $table->integer('status')->default(1);
            });
            DB::table('categories')->insert([
                ['id' => Category::OTHER_HASHED, 'title' => 'Other / Hashed', 'root_categories_id' => 1, 'status' => 1],
                ['id' => Category::OTHER_MISC, 'title' => 'Other / Misc', 'root_categories_id' => 1, 'status' => 1],
                ['id' => 5040, 'title' => 'TV / HD', 'root_categories_id' => 5, 'status' => 1],
                ['id' => 2040, 'title' => 'Movies / HD', 'root_categories_id' => 2, 'status' => 1],
            ]);
        }

        ConfigurationTestBuilder::installDefaults();

        Search::shouldReceive('updateRelease')->byDefault();
        Search::shouldReceive('isAvailable')->byDefault()->andReturnTrue();
        Search::shouldReceive('searchReleases')->byDefault()->andReturn([]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->byDefault()->andReturnFalse();
    }

    #[Test]
    public function test_nfo_pass_e2e_renames_obfuscated_release_and_updates_database(): void
    {
        $release = $this->pendingPar2Release([
            'name' => '039vphxklmk0hwouhsfx',
            'searchname' => '039vphxklmk0hwouhsfx',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('a', 40),
            'leftguid' => 'a',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_nfos')->insert([
            'releases_id' => $release->id,
            'nfo' => "Release Name: The.Matrix.1999.1080p.BluRay.x264-GROUP\nAudio: English\nVideo: 1920x1080",
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('The.Matrix.1999.1080p.BluRay.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
        $this->assertSame(1, (int) $fresh->proc_nfo);
    }

    #[Test]
    public function test_nfo_pass_dry_run_leaves_database_unmodified(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'obfuscated_hash_dryrun',
            'searchname' => 'obfuscated_hash_dryrun',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_nfos')->insert([
            'releases_id' => $release->id,
            'nfo' => "Release Name: The.Matrix.1999.1080p.BluRay.x264-GROUP\nAudio: English",
        ]);

        // Run without --update (dry-run mode)
        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--category' => 'other',
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('obfuscated_hash_dryrun', $fresh->searchname);
        $this->assertSame(0, (int) $fresh->isrenamed);
        $this->assertSame(0, (int) $fresh->proc_nfo);
    }

    #[Test]
    public function test_files_pass_e2e_renames_from_clean_file_evidence(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'e7b0c8d1f2a345678901',
            'searchname' => 'e7b0c8d1f2a345678901',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_files' => 0,
            'guid' => str_repeat('c', 40),
            'leftguid' => 'c',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_files')->insert([
            'releases_id' => $release->id,
            'name' => 'Inception.2010.1080p.BluRay.x264-GROUP.mkv',
            'crc32' => 'ABCD1234',
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '6',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Inception.2010.1080p.BluRay.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
        $this->assertSame(1, (int) $fresh->proc_files);
    }

    #[Test]
    public function test_files_pass_dry_run_leaves_database_unmodified(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'file_dry_run_candidate',
            'searchname' => 'file_dry_run_candidate',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_files' => 0,
            'guid' => str_repeat('d', 40),
            'leftguid' => 'd',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_files')->insert([
            'releases_id' => $release->id,
            'name' => 'Inception.2010.1080p.BluRay.x264-GROUP.mkv',
            'crc32' => 'ABCD1234',
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '6',
            '--category' => 'other',
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('file_dry_run_candidate', $fresh->searchname);
        $this->assertSame(0, (int) $fresh->isrenamed);
        $this->assertSame(0, (int) $fresh->proc_files);
    }

    #[Test]
    public function test_srr_pass_e2e_surfaces_query_sprintf_wildcard_bug(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'srr_obfuscated_target',
            'searchname' => 'srr_obfuscated_target',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_srr' => 0,
            'guid' => str_repeat('e', 40),
            'leftguid' => 'e',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_files')->insert([
            'releases_id' => $release->id,
            'name' => 'Breaking.Bad.S05E16.720p.HDTV.x264-GROUP.srr',
            'crc32' => null,
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '14',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertSuccessful();

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Breaking.Bad.S05E16.720p.HDTV.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
        $this->assertSame(1, (int) $fresh->proc_srr);
    }

    #[Test]
    public function test_srr_extension_matching_logic_renames_from_srr_filename(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'srr_obfuscated_target_2',
            'searchname' => 'srr_obfuscated_target_2',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_srr' => 0,
            'size' => 150000000,
        ]);

        $service = app(NameFixingService::class);
        $candidate = (object) [
            'id' => $release->id,
            'releases_id' => $release->id,
            'name' => $release->name,
            'searchname' => $release->searchname,
            'fromname' => $release->fromname,
            'groups_id' => $release->groups_id,
            'categories_id' => $release->categories_id,
            'size' => $release->size,
            'relsize' => $release->size,
            'guid' => $release->guid,
            'predb_id' => 0,
            'isrenamed' => 0,
            'textstring' => 'Breaking.Bad.S05E16.720p.HDTV.x264-GROUP.srr',
        ];

        $method = new ReflectionMethod(NameFixingService::class, 'srrNameCheck');
        $matched = $method->invoke($service, $candidate, true, 'SRR, ', true, false);

        $this->assertTrue($matched);
        $fresh = $release->fresh();
        $this->assertSame('Breaking.Bad.S05E16.720p.HDTV.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
    }

    #[Test]
    public function test_crc32_pass_e2e_renames_via_donor_release(): void
    {
        $crc = 'A1B2C3D4';

        // PreDB row for donor link
        DB::table('predb')->insert([
            'id' => 888,
            'title' => 'The.Dark.Knight.2008.1080p.BluRay.x264-GROUP',
        ]);

        // Donor release with confirmed predb_id and matching CRC
        $donor = $this->pendingPar2Release([
            'name' => 'The.Dark.Knight.2008.1080p.BluRay.x264-GROUP',
            'searchname' => 'The.Dark.Knight.2008.1080p.BluRay.x264-GROUP',
            'categories_id' => 2040,
            'predb_id' => 888,
            'isrenamed' => 1,
            'size' => 150000000,
            'guid' => str_repeat('1', 40),
            'leftguid' => '1',
        ]);

        DB::table('release_files')->insert([
            'releases_id' => $donor->id,
            'name' => 'tdk.mkv',
            'crc32' => $crc,
        ]);

        // Target obfuscated release sharing same CRC and size
        $target = $this->pendingPar2Release([
            'name' => 'hashed_crc_target',
            'searchname' => 'hashed_crc_target',
            'categories_id' => Category::OTHER_HASHED,
            'predb_id' => 0,
            'isrenamed' => 0,
            'proc_crc32' => 0,
            'size' => 150000000,
            'guid' => str_repeat('2', 40),
            'leftguid' => '2',
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_files')->insert([
            'releases_id' => $target->id,
            'name' => 'sample.mkv',
            'crc32' => $crc,
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '20',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $target->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('The.Dark.Knight.2008.1080p.BluRay.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
        $this->assertSame(1, (int) $fresh->proc_crc32);
    }

    #[Test]
    public function test_uid_pass_e2e_renames_via_media_info_unique_id(): void
    {
        $uniqueId = 'uid-movie-999-xyz';

        DB::table('predb')->insert([
            'id' => 777,
            'title' => 'Interstellar.2014.1080p.BluRay.x264-GROUP',
        ]);

        $donor = $this->pendingPar2Release([
            'name' => 'Interstellar.2014.1080p.BluRay.x264-GROUP',
            'searchname' => 'Interstellar.2014.1080p.BluRay.x264-GROUP',
            'categories_id' => 2040,
            'predb_id' => 777,
            'isrenamed' => 1,
            'size' => 200000000,
            'guid' => str_repeat('3', 40),
            'leftguid' => '3',
        ]);

        DB::table('media_infos')->insert([
            'releases_id' => $donor->id,
            'unique_id' => $uniqueId,
        ]);

        $target = $this->pendingPar2Release([
            'name' => 'hashed_uid_target',
            'searchname' => 'hashed_uid_target',
            'categories_id' => Category::OTHER_HASHED,
            'predb_id' => 0,
            'isrenamed' => 0,
            'proc_uid' => 0,
            'size' => 200000000,
            'guid' => str_repeat('4', 40),
            'leftguid' => '4',
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('media_infos')->insert([
            'releases_id' => $target->id,
            'unique_id' => $uniqueId,
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '10',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $target->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Interstellar.2014.1080p.BluRay.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
        $this->assertSame(1, (int) $fresh->proc_uid);
    }

    #[Test]
    public function test_media_movie_pass_e2e_renames_from_movie_name_field(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'hashed_movie_target',
            'searchname' => 'hashed_movie_target',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_uid' => 0,
            'guid' => str_repeat('5', 40),
            'leftguid' => '5',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('media_infos')->insert([
            'releases_id' => $release->id,
            'movie_name' => 'Gladiator.2000.1080p.BluRay.x264-GROUP',
            'file_name' => 'gladiator.mkv',
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '18',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Gladiator.2000.1080p.BluRay.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
        $this->assertSame(1, (int) $fresh->proc_uid);
    }

    #[Test]
    public function test_set_status_marks_unmatched_candidates_as_processed(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'unresolvable_candidate_1',
            'searchname' => 'unresolvable_candidate_1',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('6', 40),
            'leftguid' => '6',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_nfos')->insert([
            'releases_id' => $release->id,
            'nfo' => 'Just random text without any scene release title or movie metadata.',
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        // Name should not change, but status should be marked done
        $this->assertSame('unresolvable_candidate_1', $fresh->searchname);
        $this->assertSame(0, (int) $fresh->isrenamed);
        $this->assertSame(1, (int) $fresh->proc_nfo);
    }

    #[Test]
    public function test_without_set_status_leaves_unmatched_candidates_unprocessed(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'unresolvable_candidate_2',
            'searchname' => 'unresolvable_candidate_2',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('7', 40),
            'leftguid' => '7',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_nfos')->insert([
            'releases_id' => $release->id,
            'nfo' => 'Just random text without any scene release title.',
        ]);

        // Run without --set-status
        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'other',
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(0, (int) $fresh->isrenamed);
        $this->assertSame(0, (int) $fresh->proc_nfo);
    }

    #[Test]
    public function test_category_scope_expansion_processes_non_other_categories_with_all_flag(): void
    {
        // Release in OTHER_HASHED
        $otherRelease = $this->pendingPar2Release([
            'name' => 'other_cat_release',
            'searchname' => 'other_cat_release',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('8', 40),
            'leftguid' => '8',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);
        DB::table('release_nfos')->insert([
            'releases_id' => $otherRelease->id,
            'nfo' => "Release Name: Other.Movie.1999.1080p.BluRay.x264-GROUP\n",
        ]);

        // Release in TV_HD (5040), non-Other
        $tvRelease = $this->pendingPar2Release([
            'name' => 'non_other_tv_release',
            'searchname' => 'non_other_tv_release',
            'categories_id' => 5040,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('9', 40),
            'leftguid' => '9',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);
        DB::table('release_nfos')->insert([
            'releases_id' => $tvRelease->id,
            'nfo' => "Release Name: Fargo.S01E01.720p.HDTV.x264-GROUP\n",
        ]);

        // Running with --category=other should ONLY rename otherRelease
        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) $otherRelease->fresh()->isrenamed);
        $this->assertSame(0, (int) $tvRelease->fresh()->isrenamed);

        // Running with --category=all should process tvRelease
        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'all',
            '--set-status' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) $tvRelease->fresh()->isrenamed);
        $this->assertSame('Fargo.S01E01.720p.HDTV.x264-GROUP', $tvRelease->fresh()->searchname);
    }

    #[Test]
    public function test_time_window_filtering_distinguishes_recent_and_historical_passes(): void
    {
        // Recent release added 1 hour ago
        $recentRelease = $this->pendingPar2Release([
            'name' => 'recent_release_1h',
            'searchname' => 'recent_release_1h',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('a', 39).'1',
            'leftguid' => 'a',
            'size' => 150000000,
            'adddate' => CarbonImmutable::now()->subHour()->toDateTimeString(),
        ]);
        DB::table('release_nfos')->insert([
            'releases_id' => $recentRelease->id,
            'nfo' => "Release Name: Recent.Show.S01E01.720p.HDTV.x264-GROUP\n",
        ]);

        // Historical release added 24 hours ago
        $historicalRelease = $this->pendingPar2Release([
            'name' => 'historical_release_24h',
            'searchname' => 'historical_release_24h',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('a', 39).'2',
            'leftguid' => 'a',
            'size' => 150000000,
            'adddate' => CarbonImmutable::now()->subHours(24)->toDateTimeString(),
        ]);
        DB::table('release_nfos')->insert([
            'releases_id' => $historicalRelease->id,
            'nfo' => "Release Name: Historical.Show.S01E01.720p.HDTV.x264-GROUP\n",
        ]);

        // Method 3 (Recent NFO: past 6 hours)
        $this->artisan('releases:fix-names', [
            'method' => '3',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        // Recent release should be renamed, historical should remain untouched
        $this->assertSame(1, (int) $recentRelease->fresh()->isrenamed);
        $this->assertSame(0, (int) $historicalRelease->fresh()->isrenamed);

        // Method 4 (Historical full backlog) should rename the older release
        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) $historicalRelease->fresh()->isrenamed);
        $this->assertSame('Historical.Show.S01E01.720p.HDTV.x264-GROUP', $historicalRelease->fresh()->searchname);
    }

    #[Test]
    public function test_group_processing_standard_by_guid_prefix(): void
    {
        $releaseA = $this->pendingPar2Release([
            'name' => 'guid_a_candidate',
            'searchname' => 'guid_a_candidate',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_files' => 0,
            'guid' => 'a'.str_repeat('0', 39),
            'leftguid' => 'a',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);
        DB::table('release_files')->insert([
            'releases_id' => $releaseA->id,
            'name' => 'Dune.Part.Two.2024.1080p.BluRay.x264-GROUP.mkv',
            'crc32' => '11223344',
        ]);

        $releaseB = $this->pendingPar2Release([
            'name' => 'guid_b_candidate',
            'searchname' => 'guid_b_candidate',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_files' => 0,
            'guid' => 'b'.str_repeat('0', 39),
            'leftguid' => 'b',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);
        DB::table('release_files')->insert([
            'releases_id' => $releaseB->id,
            'name' => 'Civil.War.2024.1080p.BluRay.x264-GROUP.mkv',
            'crc32' => '55667788',
        ]);

        // Process only GUID prefix 'a'
        $this->artisan('releases:fix-names-group', [
            'type' => 'standard',
            '--guid-char' => 'a',
            '--limit' => 10,
        ])->assertExitCode(0);

        // Release A should be renamed, Release B should remain untouched
        $this->assertSame(1, (int) $releaseA->fresh()->isrenamed);
        $this->assertSame('Dune.Part.Two.2024.1080p.BluRay.x264-GROUP', $releaseA->fresh()->searchname);
        $this->assertSame(0, (int) $releaseB->fresh()->isrenamed);
    }

    #[Test]
    public function test_naming_pass_is_idempotent_on_already_renamed_releases(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'The.Shawshank.Redemption.1994.1080p.BluRay.x264-GROUP',
            'searchname' => 'The.Shawshank.Redemption.1994.1080p.BluRay.x264-GROUP',
            'categories_id' => 2040,
            'isrenamed' => 1,
            'proc_nfo' => 1,
            'guid' => str_repeat('f', 40),
            'leftguid' => 'f',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_nfos')->insert([
            'releases_id' => $release->id,
            'nfo' => "Release Name: The.Shawshank.Redemption.1994.1080p.BluRay.x264-GROUP\n",
        ]);

        // Run full NFO pass
        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'all',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('The.Shawshank.Redemption.1994.1080p.BluRay.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
        $this->assertSame(2040, (int) $fresh->categories_id);
    }

    #[Test]
    public function test_unplausible_garbage_names_are_rejected_and_not_applied(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'valid_release_to_protect',
            'searchname' => 'valid_release_to_protect',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('e', 39).'1',
            'leftguid' => 'e',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        // Unplausible garbage string that should fail plausibility check
        DB::table('release_nfos')->insert([
            'releases_id' => $release->id,
            'nfo' => "Release Name: !!!--__--@@@###$$$%%%^^^&&&***\n",
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        // Searchname must remain unchanged, not replaced with garbage
        $this->assertSame('valid_release_to_protect', $fresh->searchname);
        $this->assertSame(0, (int) $fresh->isrenamed);
    }

    #[Test]
    public function test_unicode_and_complex_scene_tags_are_handled_cleanly(): void
    {
        $release = $this->pendingPar2Release([
            'name' => 'obfuscated_unicode_french',
            'searchname' => 'obfuscated_unicode_french',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_files' => 0,
            'guid' => str_repeat('d', 39).'2',
            'leftguid' => 'd',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_files')->insert([
            'releases_id' => $release->id,
            'name' => 'Amélie.2001.REMASTERED.FRENCH.1080p.BluRay.DTS-HD.MA.5.1.x264-GROUP.mkv',
            'crc32' => '99887766',
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '6',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        $fresh = $release->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Amélie.2001.REMASTERED.FRENCH.1080p.BluRay.DTS-HD.MA.5.1.x264-GROUP', $fresh->searchname);
        $this->assertSame(1, (int) $fresh->isrenamed);
    }

    #[Test]
    public function test_tmux_task_runner_throttles_full_backlog_passes_when_configured(): void
    {
        $launched = [];
        $runner = new TmuxTaskRunner('fixnames-test');

        /** @var TmuxPaneManager&Stub $panes */
        $panes = $this->createStub(TmuxPaneManager::class);
        $panes->method('paneForRole')->willReturn('%1');
        $panes->method('isAlive')->willReturn(false);

        $capture = function (string $pane, string|array $command) use (&$launched): bool {
            $launched[] = is_array($command) ? implode(' ', $command) : $command;

            return true;
        };
        $panes->method('respawnPane')->willReturnCallback($capture);
        $panes->method('respawnLoggedPane')->willReturnCallback(static fn (string $pane, array $command, string $file): bool => $capture($pane, $command));
        (new ReflectionProperty(TmuxTaskRunner::class, 'paneManager'))->setValue($runner, $panes);

        $runMethod = new ReflectionMethod(TmuxTaskRunner::class, 'runFixNamesTask');

        // First cycle: claims full backlog slot and launches historical passes (e.g. 4, 6, 10, ...)
        $this->assertTrue($runMethod->invoke($runner, [
            'settings' => ['fix_names' => 1, 'fix_timer' => 0],
            'counts' => ['now' => ['processrenames' => 5]],
        ]));

        $this->assertTrue(Cache::store('fixnames_claims')->has('tmux:fix-names:full-backlog'));
        $this->assertStringContainsString('releases:fix-names 4 ', $launched[0]);

        // Second immediate cycle: full backlog is throttled, historical passes are omitted
        $this->assertTrue($runMethod->invoke($runner, [
            'settings' => ['fix_names' => 1, 'fix_timer' => 0],
            'counts' => ['now' => ['processrenames' => 5]],
        ]));

        $this->assertCount(2, $launched);
        $this->assertStringContainsString('releases:fix-names 3 ', $launched[1]);
        $this->assertStringNotContainsString('releases:fix-names 4 ', $launched[1]);
    }

    #[Test]
    public function test_tmux_task_runner_allows_continuous_full_backlog_when_throttling_disabled(): void
    {
        // When interval is 0, full backlog passes can run continuously
        config(['tmux.fix_names.full_backlog_interval_minutes' => 0]);

        $launched = [];
        $runner = new TmuxTaskRunner('fixnames-test');

        /** @var TmuxPaneManager&Stub $panes */
        $panes = $this->createStub(TmuxPaneManager::class);
        $panes->method('paneForRole')->willReturn('%1');
        $panes->method('isAlive')->willReturn(false);

        $capture = function (string $pane, string|array $command) use (&$launched): bool {
            $launched[] = is_array($command) ? implode(' ', $command) : $command;

            return true;
        };
        $panes->method('respawnPane')->willReturnCallback($capture);
        $panes->method('respawnLoggedPane')->willReturnCallback(static fn (string $pane, array $command, string $file): bool => $capture($pane, $command));
        (new ReflectionProperty(TmuxTaskRunner::class, 'paneManager'))->setValue($runner, $panes);

        $runMethod = new ReflectionMethod(TmuxTaskRunner::class, 'runFixNamesTask');

        // First run
        $this->assertTrue($runMethod->invoke($runner, [
            'settings' => ['fix_names' => 1, 'fix_timer' => 0],
            'counts' => ['now' => ['processrenames' => 5]],
        ]));

        $this->assertStringContainsString('releases:fix-names 4 ', $launched[0]);

        // Second immediate run: continuous processing allows full backlog passes without waiting
        $this->assertTrue($runMethod->invoke($runner, [
            'settings' => ['fix_names' => 1, 'fix_timer' => 0],
            'counts' => ['now' => ['processrenames' => 5]],
        ]));

        $this->assertCount(2, $launched);
        $this->assertStringContainsString('releases:fix-names 4 ', $launched[1]);
    }

    #[Test]
    public function test_release_name_fixed_event_is_dispatched_on_successful_rename(): void
    {
        Event::fake([ReleaseNameFixed::class]);

        $release = $this->pendingPar2Release([
            'name' => 'event_dispatch_test_hash',
            'searchname' => 'event_dispatch_test_hash',
            'fromname' => 'poster@example.com',
            'categories_id' => Category::OTHER_HASHED,
            'isrenamed' => 0,
            'proc_nfo' => 0,
            'guid' => str_repeat('c', 39).'9',
            'leftguid' => 'c',
            'size' => 150000000,
            'adddate' => now()->toDateTimeString(),
        ]);

        DB::table('release_nfos')->insert([
            'releases_id' => $release->id,
            'nfo' => "Release Name: Severance.S01E01.720p.HDTV.x264-GROUP\n",
        ]);

        $this->artisan('releases:fix-names', [
            'method' => '4',
            '--update' => true,
            '--category' => 'other',
            '--set-status' => true,
        ])->assertExitCode(0);

        Event::assertDispatched(ReleaseNameFixed::class, function (ReleaseNameFixed $event) use ($release): bool {
            return $event->releaseId === $release->id
                && $event->newName === 'Severance.S01E01.720p.HDTV.x264-GROUP';
        });
    }
}
