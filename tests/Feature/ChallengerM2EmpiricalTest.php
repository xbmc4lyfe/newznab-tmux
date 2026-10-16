<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxTaskRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

class ChallengerM2EmpiricalTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const string SLOT = 'tmux:fix-names:full-backlog';

    /** @var list<string> */
    private array $launched = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'tmux.fix_names.full_backlog' => true,
            'tmux.fix_names.full_backlog_interval_minutes' => 60,
            'tmux.fix_names.batch_limit' => 1000,
            'tmux.fix_names.category' => 'other',
            'tmux.fix_names.par2_scheduled' => false,
            'tmux.fix_names.predbft' => false,
            'tmux.fix_names.predbft_scheduled' => false,
            'cache.stores.fixnames_claims' => ['driver' => 'array'],
            'tmux.fix_names.cache_store' => 'fixnames_claims',
        ]);
        Cache::flush();
        $this->createPar2RenameSchema();
    }

    // =========================================================================
    // AREA 1: THROTTLING REMOVAL STRESS TESTS
    // =========================================================================

    #[Test]
    public function area1_interval_zero_executes_historical_passes_over_100_consecutive_cycles(): void
    {
        config(['tmux.fix_names.full_backlog_interval_minutes' => 0]);

        $historicalLevels = [4, 6, 10, 12, 14, 16, 18, 20];

        for ($cycle = 0; $cycle < 100; $cycle++) {
            $this->launched = [];
            $result = $this->runTask(alive: false, renames: 5);
            $this->assertTrue($result, "Cycle {$cycle} must succeed");
            $this->assertCount(1, $this->launched, "Cycle {$cycle} must launch commands");

            $command = $this->launchedCommand();
            foreach ($historicalLevels as $lvl) {
                $this->assertStringContainsString(
                    "releases:fix-names {$lvl} ",
                    $command,
                    "Cycle {$cycle} must contain pass {$lvl}"
                );
            }
        }
    }

    #[Test]
    public function area1_negative_intervals_also_enable_continuous_full_backlog(): void
    {
        config(['tmux.fix_names.full_backlog_interval_minutes' => -1]);

        $this->assertTrue($this->runTask(alive: false, renames: 5));
        $this->assertTrue($this->runTask(alive: false, renames: 5));

        $this->assertCount(2, $this->launched);
        $this->assertStringContainsString('releases:fix-names 4 ', $this->launchedCommand(0));
        $this->assertStringContainsString('releases:fix-names 4 ', $this->launchedCommand(1));
    }

    #[Test]
    public function area1_interval_zero_bypasses_cache_store_completely(): void
    {
        config(['tmux.fix_names.full_backlog_interval_minutes' => 0]);

        // Pre-seed the cache slot as locked
        Cache::store('fixnames_claims')->put(self::SLOT, true, 3600);
        $this->assertTrue(Cache::store('fixnames_claims')->has(self::SLOT));

        // When interval is 0, claimFullBacklogSlot() must return true regardless of existing lock
        $runner = new TmuxTaskRunner('fixnames-test');
        $claimMethod = new ReflectionMethod(TmuxTaskRunner::class, 'claimFullBacklogSlot');
        $this->assertTrue($claimMethod->invoke($runner));

        // Even if cache store throws an exception, interval <= 0 must return true without failing
        Cache::shouldReceive('store')->with('fixnames_claims')->andThrow(new RuntimeException('Redis down'));
        $this->assertTrue($claimMethod->invoke($runner));
    }

    #[Test]
    public function area1_positive_interval_locks_historical_passes_after_first_cycle(): void
    {
        config(['tmux.fix_names.full_backlog_interval_minutes' => 60]);

        $this->assertTrue($this->runTask(alive: false, renames: 5));
        $this->assertTrue($this->runTask(alive: false, renames: 5));

        $this->assertCount(2, $this->launched);
        // Cycle 1 has historical passes
        $this->assertStringContainsString('releases:fix-names 4 ', $this->launchedCommand(0));
        // Cycle 2 is locked out and MUST NOT have historical passes
        $this->assertStringNotContainsString('releases:fix-names 4 ', $this->launchedCommand(1));
        $this->assertStringNotContainsString('releases:fix-names 6 ', $this->launchedCommand(1));
        $this->assertStringNotContainsString('releases:fix-names 10 ', $this->launchedCommand(1));
    }

    #[Test]
    public function area1_full_backlog_disabled_skips_historical_passes_even_with_interval_zero(): void
    {
        config([
            'tmux.fix_names.full_backlog' => false,
            'tmux.fix_names.full_backlog_interval_minutes' => 0,
        ]);

        $this->assertTrue($this->runTask(alive: false, renames: 5));

        $this->assertCount(1, $this->launched);
        $this->assertStringNotContainsString('releases:fix-names 4 ', $this->launchedCommand(0));
        $this->assertStringNotContainsString('releases:fix-names 6 ', $this->launchedCommand(0));
    }

    // =========================================================================
    // AREA 2: CONCURRENCY DECOUPLING STRESS TESTS
    // =========================================================================

    #[Test]
    public function area2_par2_passes_completely_excluded_from_pane_when_par2_scheduled_is_true(): void
    {
        config(['tmux.fix_names.par2_scheduled' => true]);

        $scenarios = [
            ['renames' => 5, 'fullBacklog' => true],
            ['renames' => 5, 'fullBacklog' => false],
            ['renames' => 0, 'fullBacklog' => true],
            ['renames' => 0, 'fullBacklog' => false],
        ];

        foreach ($scenarios as $scenario) {
            $this->launched = [];
            config(['tmux.fix_names.full_backlog' => $scenario['fullBacklog']]);
            $this->runTask(alive: false, renames: $scenario['renames']);

            if (! empty($this->launched)) {
                $command = $this->launchedCommand();
                $this->assertStringNotContainsString('releases:fix-names 7 ', $command, 'Pass 7 must be excluded');
                $this->assertStringNotContainsString('releases:fix-names 8 ', $command, 'Pass 8 must be excluded');
            }
        }
    }

    #[Test]
    public function area2_par2_passes_included_in_pane_when_par2_scheduled_is_false(): void
    {
        config(['tmux.fix_names.par2_scheduled' => false]);

        $this->assertTrue($this->runTask(alive: false, renames: 5));

        $this->assertCount(1, $this->launched);
        $command = $this->launchedCommand(0);
        $this->assertStringContainsString('releases:fix-names 7 ', $command);
        $this->assertStringContainsString('releases:fix-names 8 ', $command);
    }

    #[Test]
    public function area2_routes_console_registers_par2_scheduled_tasks_with_correct_cron_and_mutex(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $events = $schedule->events();
        $par2Pass7 = null;
        $par2Pass8 = null;

        foreach ($events as $event) {
            if (! $event instanceof ScheduleEvent) {
                continue;
            }
            if (str_contains($event->command ?? '', 'releases:fix-names 7')) {
                $par2Pass7 = $event;
            }
            if (str_contains($event->command ?? '', 'releases:fix-names 8')) {
                $par2Pass8 = $event;
            }
        }

        $this->assertNotNull($par2Pass7, 'releases:fix-names 7 must be registered in Schedule');
        $this->assertNotNull($par2Pass8, 'releases:fix-names 8 must be registered in Schedule');

        // Verify cron expressions
        $this->assertSame('*/5 * * * *', $par2Pass7->expression, 'Pass 7 must run every 5 minutes');
        $this->assertSame('* * * * *', $par2Pass8->expression, 'Pass 8 must run every minute');

        // Verify withoutOverlapping mutex
        $this->assertTrue($par2Pass7->withoutOverlapping, 'Pass 7 must prevent overlapping');
        $this->assertTrue($par2Pass8->withoutOverlapping, 'Pass 8 must prevent overlapping');

        // Verify runInBackground
        $this->assertTrue($par2Pass7->runInBackground, 'Pass 7 must run in background');
        $this->assertTrue($par2Pass8->runInBackground, 'Pass 8 must run in background');

        // Verify when() condition evaluates config('tmux.fix_names.par2_scheduled')
        config(['tmux.fix_names.par2_scheduled' => false]);
        $this->assertFalse($par2Pass7->filtersPass($this->app), 'Pass 7 must not run when par2_scheduled is false');
        $this->assertFalse($par2Pass8->filtersPass($this->app), 'Pass 8 must not run when par2_scheduled is false');

        config(['tmux.fix_names.par2_scheduled' => true]);
        $this->assertTrue($par2Pass7->filtersPass($this->app), 'Pass 7 must run when par2_scheduled is true');
        $this->assertTrue($par2Pass8->filtersPass($this->app), 'Pass 8 must run when par2_scheduled is true');
    }

    // =========================================================================
    // AREA 3: BATCH LIMIT STRESS TESTS
    // =========================================================================

    #[Test]
    public function area3_fix_release_names_command_parses_and_mutates_batch_limit(): void
    {
        $limits = [1, 500, 5000];

        foreach ($limits as $limit) {
            Artisan::call('releases:fix-names', [
                'method' => '3',
                '--limit' => (string) $limit,
            ]);

            $this->assertSame(
                $limit,
                config('tmux.fix_names.batch_limit'),
                "Batch limit should be updated to {$limit} by Artisan command"
            );
        }
    }

    #[Test]
    public function area3_fix_release_names_clamps_non_positive_batch_limits_to_one(): void
    {
        foreach ([0, -1, -500] as $invalidLimit) {
            Artisan::call('releases:fix-names', [
                'method' => '3',
                '--limit' => (string) $invalidLimit,
            ]);

            $this->assertSame(
                1,
                config('tmux.fix_names.batch_limit'),
                "Non-positive limit {$invalidLimit} must be clamped to 1"
            );
        }
    }

    #[Test]
    public function area3_tmux_task_runner_propagates_batch_limit_to_command_string(): void
    {
        config(['tmux.fix_names.batch_limit' => 500]);
        $this->runTask(alive: false, renames: 5);
        $this->assertStringContainsString('--limit=500', $this->launchedCommand(0));

        $this->launched = [];
        config(['tmux.fix_names.batch_limit' => 5000]);
        $this->runTask(alive: false, renames: 5);
        $this->assertStringContainsString('--limit=5000', $this->launchedCommand(0));

        $this->launched = [];
        config(['tmux.fix_names.batch_limit' => 0]);
        $this->runTask(alive: false, renames: 5);
        $this->assertStringNotContainsString('--limit', $this->launchedCommand(0));
    }

    #[Test]
    public function area3_candidate_batch_sql_bindings_and_row_count_clamping(): void
    {
        $service = $this->app->make(NameFixingQueryService::class);

        // Seed 15 test releases with nfo
        for ($i = 1; $i <= 15; $i++) {
            DB::table('releases')->insert([
                'id' => $i,
                'name' => "Test.Release.{$i}",
                'searchname' => "Test.Release.{$i}",
                'fromname' => 'Poster',
                'guid' => sprintf('%040x', $i),
                'leftguid' => sprintf('%02x', $i),
                'groups_id' => 1,
                'categories_id' => Category::OTHER_MISC,
                'size' => 1024,
                'adddate' => CarbonImmutable::now()->toDateTimeString(),
                'predb_id' => 0,
                'nfostatus' => 1,
                'proc_nfo' => 0,
                'proc_files' => 0,
                'proc_par2' => 0,
                'proc_uid' => 0,
                'proc_srr' => 0,
                'proc_hash16k' => 0,
                'proc_crc32' => 0,
                'isrenamed' => 0,
                'passwordstatus' => 0,
            ]);
            DB::table('release_nfos')->insert([
                'releases_id' => $i,
            ]);
        }

        // Test limit = 1
        config(['tmux.fix_names.batch_limit' => 1]);
        $batch = $service->candidateBatch('nfo', 1, 1);
        $this->assertCount(1, $batch);

        // Test limit = 5
        config(['tmux.fix_names.batch_limit' => 5]);
        $batch = $service->candidateBatch('nfo', 1, 1);
        $this->assertCount(5, $batch);

        // Test limit = 5000 (all 15 should be returned)
        config(['tmux.fix_names.batch_limit' => 5000]);
        $batch = $service->candidateBatch('nfo', 1, 1);
        $this->assertCount(15, $batch);

        // Test explicit limit overrides config
        $batch = $service->candidateBatch('nfo', 1, 1, 0, 3);
        $this->assertCount(3, $batch);
    }

    // =========================================================================
    // AREA 4: CATEGORY SCOPING STRESS TESTS
    // =========================================================================

    #[Test]
    public function area4_pending_work_condition_sql_scoping(): void
    {
        $service = $this->app->make(NameFixingQueryService::class);
        $method = new ReflectionMethod(NameFixingQueryService::class, 'pendingWorkCondition');

        // When category is 'other'
        config(['tmux.fix_names.category' => 'other']);
        $sqlOther = (string) $method->invoke($service);
        $this->assertStringContainsString('r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')', $sqlOther);

        // When category is 'all'
        config(['tmux.fix_names.category' => 'all']);
        $sqlAll = (string) $method->invoke($service);
        $this->assertStringNotContainsString('r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')', $sqlAll);
        $this->assertStringContainsString('r.predb_id = 0 AND (r.isrenamed = 0 OR r.categories_id IN ('.Category::OTHER_MISC.', '.Category::OTHER_HASHED.'))', $sqlAll);

        // When category is invalid, fallback to 'other' group
        config(['tmux.fix_names.category' => 'invalid_unrecognized_scope']);
        $sqlInvalid = (string) $method->invoke($service);
        $this->assertStringContainsString('r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')', $sqlInvalid);
    }

    #[Test]
    public function area4_standard_candidate_batch_sql_scoping(): void
    {
        // Intercept DB queries to verify SQL generated by standardCandidateBatch
        $service = $this->app->make(NameFixingQueryService::class);

        DB::enableQueryLog();

        config(['tmux.fix_names.category' => 'other']);
        $service->standardCandidateBatch('aa', 10);
        $queries = DB::getQueryLog();
        $this->assertNotEmpty($queries);
        $lastSqlOther = $queries[array_key_last($queries)]['query'];
        $this->assertStringContainsString('r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')', $lastSqlOther);

        DB::flushQueryLog();

        config(['tmux.fix_names.category' => 'all']);
        $service->standardCandidateBatch('aa', 10);
        $queries = DB::getQueryLog();
        $this->assertNotEmpty($queries);
        $lastSqlAll = $queries[array_key_last($queries)]['query'];
        $this->assertStringNotContainsString('r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')', $lastSqlAll);

        DB::disableQueryLog();
    }

    #[Test]
    public function area4_tmux_task_runner_category_escaping_and_propagation(): void
    {
        config(['tmux.fix_names.category' => 'all']);
        $this->runTask(alive: false, renames: 5);
        $this->assertStringContainsString('--category=\'all\'', $this->launchedCommand(0));

        $this->launched = [];
        config(['tmux.fix_names.category' => 'other']);
        $this->runTask(alive: false, renames: 5);
        $this->assertStringContainsString('--category=\'other\'', $this->launchedCommand(0));

        // Adversarial shell injection attempt
        $this->launched = [];
        config(['tmux.fix_names.category' => 'all; rm -rf /; echo hack']);
        $this->runTask(alive: false, renames: 5);
        $this->assertStringContainsString('--category=\'all; rm -rf /; echo hack\'', $this->launchedCommand(0));
    }

    #[Test]
    public function area1_transition_from_zero_to_positive_interval_engages_cache_lock(): void
    {
        // First run with interval = 0 (continuous)
        config(['tmux.fix_names.full_backlog_interval_minutes' => 0]);
        $this->assertTrue($this->runTask(alive: false, renames: 5));
        $this->assertStringContainsString('releases:fix-names 4 ', $this->launchedCommand(0));

        // Switch to interval = 60
        config(['tmux.fix_names.full_backlog_interval_minutes' => 60]);
        $this->launched = [];

        // Cycle 1 under interval = 60: executes and claims cache lock
        $this->assertTrue($this->runTask(alive: false, renames: 5));
        $this->assertStringContainsString('releases:fix-names 4 ', $this->launchedCommand(0));
        $this->assertTrue(Cache::store('fixnames_claims')->has(self::SLOT));

        // Cycle 2 under interval = 60: locked out
        $this->launched = [];
        $this->assertTrue($this->runTask(alive: false, renames: 5));
        $this->assertStringNotContainsString('releases:fix-names 4 ', $this->launchedCommand(0));
    }

    #[Test]
    public function area2_scheduled_events_have_distinct_mutex_names(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();
        $event7 = null;
        $event8 = null;

        foreach ($events as $event) {
            if (! $event instanceof ScheduleEvent) {
                continue;
            }
            if (str_contains($event->command ?? '', 'releases:fix-names 7')) {
                $event7 = $event;
            }
            if (str_contains($event->command ?? '', 'releases:fix-names 8')) {
                $event8 = $event;
            }
        }

        $this->assertNotNull($event7);
        $this->assertNotNull($event8);
        $this->assertNotSame($event7->mutexName(), $event8->mutexName(), 'Pass 7 and 8 must have distinct mutex names so they do not block each other');
    }

    #[Test]
    public function area3_artisan_fix_release_names_processes_candidate_batch_matching_limit(): void
    {
        // Seed 10 releases with NFO in test DB
        for ($i = 201; $i <= 210; $i++) {
            DB::table('releases')->insert([
                'id' => $i,
                'name' => "BatchTest.Release.{$i}",
                'searchname' => "BatchTest.Release.{$i}",
                'fromname' => 'Poster',
                'guid' => sprintf('%040x', $i),
                'leftguid' => sprintf('%02x', $i % 256),
                'groups_id' => 1,
                'categories_id' => Category::OTHER_MISC,
                'size' => 1024,
                'adddate' => CarbonImmutable::now()->toDateTimeString(),
                'predb_id' => 0,
                'nfostatus' => 1,
                'proc_nfo' => 0,
                'proc_files' => 0,
                'proc_par2' => 0,
                'proc_uid' => 0,
                'proc_srr' => 0,
                'proc_hash16k' => 0,
                'proc_crc32' => 0,
                'isrenamed' => 0,
                'passwordstatus' => 0,
            ]);
            DB::table('release_nfos')->insert(['releases_id' => $i]);
        }

        // Run releases:fix-names 3 with --limit=4
        Artisan::call('releases:fix-names', [
            'method' => '3',
            '--update' => true,
            '--set-status' => true,
            '--limit' => '4',
        ]);

        $processedCount = DB::table('releases')->whereBetween('id', [201, 210])->where('proc_nfo', 1)->count();
        $this->assertSame(4, $processedCount, 'Exactly 4 releases should have proc_nfo = 1 after --limit=4');

        // Run releases:fix-names 3 with --limit=3
        Artisan::call('releases:fix-names', [
            'method' => '3',
            '--update' => true,
            '--set-status' => true,
            '--limit' => '3',
        ]);

        $processedCountAfterSecond = DB::table('releases')->whereBetween('id', [201, 210])->where('proc_nfo', 1)->count();
        $this->assertSame(7, $processedCountAfterSecond, 'Total 7 releases should have proc_nfo = 1 after second run with --limit=3');
    }

    #[Test]
    public function area3_invalid_limit_strings_fallback_gracefully(): void
    {
        Artisan::call('releases:fix-names', [
            'method' => '3',
            '--limit' => 'not-a-number',
        ]);

        $this->assertSame(1, config('tmux.fix_names.batch_limit'));
    }

    #[Test]
    public function area4_empirical_category_scoping_candidate_selection_in_db(): void
    {
        $service = $this->app->make(NameFixingQueryService::class);

        // Release 1: in OTHERS_GROUP (Category::OTHER_MISC = 1090)
        DB::table('releases')->insert([
            'id' => 101,
            'name' => 'Release.Other',
            'searchname' => 'Release.Other',
            'fromname' => 'Poster',
            'guid' => sprintf('%040x', 101),
            'leftguid' => '10',
            'groups_id' => 1,
            'categories_id' => Category::OTHER_MISC,
            'size' => 1024,
            'adddate' => CarbonImmutable::now()->toDateTimeString(),
            'predb_id' => 0,
            'nfostatus' => 1,
            'proc_nfo' => 0,
            'proc_files' => 0,
            'proc_par2' => 0,
            'proc_uid' => 0,
            'proc_srr' => 0,
            'proc_hash16k' => 0,
            'proc_crc32' => 0,
            'isrenamed' => 0,
            'passwordstatus' => 0,
        ]);
        DB::table('release_nfos')->insert(['releases_id' => 101]);

        // Release 2: OUTSIDE OTHERS_GROUP (Category::TV_SD = 5030)
        DB::table('releases')->insert([
            'id' => 102,
            'name' => 'Release.TV.SD',
            'searchname' => 'Release.TV.SD',
            'fromname' => 'Poster',
            'guid' => sprintf('%040x', 102),
            'leftguid' => '10',
            'groups_id' => 1,
            'categories_id' => Category::TV_SD,
            'size' => 1024,
            'adddate' => CarbonImmutable::now()->toDateTimeString(),
            'predb_id' => 0,
            'nfostatus' => 1,
            'proc_nfo' => 0,
            'proc_files' => 0,
            'proc_par2' => 0,
            'proc_uid' => 0,
            'proc_srr' => 0,
            'proc_hash16k' => 0,
            'proc_crc32' => 0,
            'isrenamed' => 0,
            'passwordstatus' => 0,
        ]);
        DB::table('release_nfos')->insert(['releases_id' => 102]);

        // When categories = 1 (other) -> only release 101 is selected
        $batchOther = $service->candidateBatch('nfo', 1, 1);
        $idsOther = array_map(static fn (object $r): int => (int) $r->id, $batchOther);
        $this->assertContains(101, $idsOther);
        $this->assertNotContains(102, $idsOther);

        // When categories = 2 (all) -> both release 101 and 102 are selected
        $batchAll = $service->candidateBatch('nfo', 1, 2);
        $idsAll = array_map(static fn (object $r): int => (int) $r->id, $batchAll);
        $this->assertContains(101, $idsAll);
        $this->assertContains(102, $idsAll);

        // Now test standardCandidateBatch with category = 'other' vs 'all'
        config(['tmux.fix_names.category' => 'other']);
        $stdOther = $service->standardCandidateBatch('10', 10);
        $stdIdsOther = array_map(static fn (object $r): int => (int) $r->id, $stdOther);
        $this->assertContains(101, $stdIdsOther);
        $this->assertNotContains(102, $stdIdsOther);

        config(['tmux.fix_names.category' => 'all']);
        $stdAll = $service->standardCandidateBatch('10', 10);
        $stdIdsAll = array_map(static fn (object $r): int => (int) $r->id, $stdAll);
        $this->assertContains(101, $stdIdsAll);
        $this->assertContains(102, $stdIdsAll);
    }

    private function runTask(bool $alive, int $renames, bool $respawnSucceeds = true): bool
    {
        $runner = new TmuxTaskRunner('fixnames-test');
        /** @var TmuxPaneManager&Stub $panes */
        $panes = $this->createStub(TmuxPaneManager::class);
        $panes->method('paneForRole')->willReturn('%1');
        $panes->method('isAlive')->willReturn($alive);
        $capture = function (string $pane, string|array $command) use ($respawnSucceeds): bool {
            $this->launched[] = is_array($command) ? implode(' ', $command) : $command;

            return $respawnSucceeds;
        };
        $panes->method('respawnPane')->willReturnCallback($capture);
        $panes->method('respawnLoggedPane')->willReturnCallback(static fn (string $pane, array $command, string $file): bool => $capture($pane, $command));
        (new ReflectionProperty(TmuxTaskRunner::class, 'paneManager'))->setValue($runner, $panes);

        return (new ReflectionMethod(TmuxTaskRunner::class, 'runFixNamesTask'))->invoke($runner, [
            'settings' => ['fix_names' => 1, 'fix_timer' => 0],
            'counts' => ['now' => ['processrenames' => $renames]],
        ]);
    }

    private function launchedCommand(int $index = 0): string
    {
        return $this->launched[$index] ?? '';
    }
}
