<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Par2RenameOutcome;
use App\Models\Release;
use App\Services\NameFixing\NameFixingService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\PostProcessService;
use Carbon\CarbonImmutable;
use DariusIII\NetNntp\Error;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SimpleXMLElement;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

class Par2RenameBatchTest extends TestCase
{
    use Par2RenameTestDatabase;

    /** @var list<string> */
    private array $visited = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tmux.fix_names.cache_store' => 'array',
            'tmux.fix_names.par2_max_releases' => 2,
            'tmux.fix_names.par2_max_local_skips' => 0,
            'tmux.fix_names.par2_budget_seconds' => 30,
        ]);
        Cache::store('array')->flush();
        $this->createPar2RenameSchema();
    }

    public function test_a_download_pass_is_bounded_and_resumes_after_the_last_release(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->pendingPar2Release(['guid' => 'guid-'.$i]);
        }
        $this->bindNzb();
        $this->runBatch();
        $this->assertSame(['guid-1', 'guid-2'], $this->visited);
        $this->assertSame(2, Release::query()->where('proc_par2', 1)->count());
        $this->runBatch();
        $this->assertSame(['guid-1', 'guid-2', 'guid-3', 'guid-4'], $this->visited);
        $this->runBatch();
        $this->assertSame(5, Release::query()->where('proc_par2', 1)->count());
    }

    public function test_historical_sweep_processes_three_hundred_releases_before_resuming(): void
    {
        config(['tmux.fix_names.par2_max_releases' => 300]);
        for ($i = 1; $i <= 301; $i++) {
            $this->pendingPar2Release(['guid' => 'guid-'.$i]);
        }
        $this->bindNzb();

        $this->runBatch();

        $this->assertCount(300, $this->visited);
        $this->assertSame(300, Release::query()->where('proc_par2', 1)->count());

        $this->runBatch();

        $this->assertCount(301, $this->visited);
        $this->assertSame(301, Release::query()->where('proc_par2', 1)->count());
    }

    public function test_abandoned_historical_sweep_lock_expires_within_five_minutes(): void
    {
        $this->freezeTime();
        config([
            'nntmux_nntp.socket_timeout' => 120,
            'nntmux_nntp.alternate_server_socket_timeout' => 120,
        ]);
        $this->pendingPar2Release();
        $store = Cache::store('array')->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        $service = new class($store) extends NzbContentsService
        {
            public ?int $sweepLeaseSeconds = null;

            public function __construct(private ArrayStore $store) {}

            public function checkPar2Result(
                string $guid,
                int $relID,
                int $groupID,
                int $nameStatus,
                int $show,
                ?float $deadline = null,
                ?callable $onArticleReceived = null
            ): Par2RenameOutcome {
                $expiresAt = $this->store->locks['rename:par2:sweep:2:1:lock']['expiresAt'];
                $this->sweepLeaseSeconds = $expiresAt->getTimestamp() - now()->getTimestamp();

                return Par2RenameOutcome::NoMatch;
            }
        };
        $this->app->instance(NzbContentsService::class, $service);

        $this->runBatch();

        $this->assertSame(300, $service->sweepLeaseSeconds);
    }

    public function test_abandoned_historical_sweep_lock_can_be_reacquired_after_five_minutes(): void
    {
        $this->freezeTime();
        config([
            'nntmux_nntp.socket_timeout' => 120,
            'nntmux_nntp.alternate_server_socket_timeout' => 120,
        ]);
        $store = Cache::store('array')->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        $abandonedLock = $store->lock('rename:par2:sweep:2:1:lock', 300);
        $this->assertTrue($abandonedLock->get());

        CarbonImmutable::setTestNow(now()->addSeconds(301));

        $replacementLock = $store->lock('rename:par2:sweep:2:1:lock', 300);
        $this->assertTrue($replacementLock->get());
        $replacementLock->release();
    }

    public function test_active_sweep_refreshes_its_lease_when_the_article_reader_heartbeats(): void
    {
        $this->freezeTime();
        config([
            'nntmux_nntp.socket_timeout' => 120,
            'nntmux_nntp.alternate_server_socket_timeout' => 120,
        ]);
        $this->pendingPar2Release();
        $store = Cache::store('array')->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        $service = new class($store) extends NzbContentsService
        {
            public ?int $leaseAfterHeartbeat = null;

            public function __construct(private ArrayStore $store) {}

            public function checkPar2Result(
                string $guid,
                int $relID,
                int $groupID,
                int $nameStatus,
                int $show,
                ?float $deadline = null,
                ?callable $onArticleReceived = null
            ): Par2RenameOutcome {
                CarbonImmutable::setTestNow(now()->addSeconds(250));
                $onArticleReceived?->__invoke();
                $expiresAt = $this->store->locks['rename:par2:sweep:2:1:lock']['expiresAt'];
                $this->leaseAfterHeartbeat = $expiresAt->getTimestamp() - now()->getTimestamp();

                return Par2RenameOutcome::NoMatch;
            }
        };
        $this->app->instance(NzbContentsService::class, $service);

        $this->runBatch();

        $this->assertSame(300, $service->leaseAfterHeartbeat);
    }

    public function test_retryable_old_releases_do_not_starve_later_work_and_are_revisited(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->pendingPar2Release(['guid' => 'guid-'.$i]);
        }
        $this->bindNzb(['guid-1', 'guid-2']);
        $this->runBatch();
        $this->assertSame(['guid-1', 'guid-2'], $this->visited);
        $this->runBatch();
        $this->assertSame(['guid-1', 'guid-2', 'guid-3'], $this->visited);
        $this->runBatch();
        $this->assertSame(['guid-1', 'guid-2', 'guid-3', 'guid-1', 'guid-2'], $this->visited);
        $this->assertSame(2, Release::query()->where('proc_par2', 0)->count());
    }

    #[DataProvider('localSkipBounds')]
    public function test_zero_probe_retries_have_a_bounded_extra_allowance(int $bonus, int $expectedInspected): void
    {
        config(['tmux.fix_names.par2_max_local_skips' => $bonus]);
        $guids = [];
        $ids = [];
        for ($index = 0; $index < 8; $index++) {
            $guids[] = 'missing-'.$index;
            $ids[] = $this->pendingPar2Release(['guid' => 'missing-'.$index])->id;
        }
        $this->bindNzb($guids);

        $this->runBatch();

        $this->assertSame(array_slice($guids, 0, $expectedInspected), $this->visited);
        $this->assertSame($ids[$expectedInspected - 1], Cache::store('array')->get('rename:par2:sweep:2:1')['after_id']);
        $this->assertSame(8, Release::query()->where('proc_par2', 0)->count());
    }

    /** @return array<string, array{int, int}> */
    public static function localSkipBounds(): array
    {
        return ['disabled' => [0, 2], 'negative is disabled' => [-1, 2], 'one extra rejection' => [1, 3], 'three extra rejections' => [3, 5]];
    }

    public function test_local_retries_do_not_wrap_twice_or_extend_the_frozen_cohort(): void
    {
        config(['tmux.fix_names.par2_max_local_skips' => 10]);
        $this->pendingPar2Release(['guid' => 'old-1']);
        $lastOld = $this->pendingPar2Release(['guid' => 'old-2']);
        $this->bindNzb(['old-1', 'old-2'], onLoad: function (string $guid): void {
            if ($guid === 'old-1' && Release::query()->where('guid', 'new-3')->doesntExist()) {
                $this->pendingPar2Release(['guid' => 'new-3']);
            }
        });

        $this->runBatch();

        $this->assertSame(['old-1', 'old-2'], $this->visited);
        $this->assertSame($lastOld->id, Cache::store('array')->get('rename:par2:sweep:2:1')['through_id']);
        $this->assertSame(3, Release::query()->where('proc_par2', 0)->count());

        $this->runBatch();

        $this->assertSame(['old-1', 'old-2', 'old-1', 'old-2', 'new-3'], $this->visited);
        $this->assertSame(2, Release::query()->where('proc_par2', 0)->count());
    }

    public function test_streamed_pages_remain_small_without_adding_overflowing_quotas(): void
    {
        config(['tmux.fix_names.par2_max_releases' => PHP_INT_MAX, 'tmux.fix_names.par2_max_local_skips' => PHP_INT_MAX]);
        for ($index = 1; $index <= 3; $index++) {
            $this->pendingPar2Release(['guid' => 'missing-'.$index]);
        }
        $this->bindNzb(['missing-1', 'missing-2', 'missing-3']);
        $pages = [];
        $this->observePar2Pages($pages);

        $this->runBatch();

        $this->assertSame(['missing-1', 'missing-2', 'missing-3'], $this->visited);
        $this->assertNotEmpty($pages);
        foreach ($pages as $page) {
            $this->assertLessThanOrEqual(1000, $page['limit']);
            $this->assertGreaterThan(0, $page['limit']);
        }
        $this->assertSame(3, Release::query()->where('proc_par2', 0)->count());
    }

    public function test_deadline_after_a_local_retry_stops_before_fetching_another_page(): void
    {
        $this->freezeTime();
        config(['tmux.fix_names.par2_max_local_skips' => 10]);
        for ($index = 1; $index <= 3; $index++) {
            $this->pendingPar2Release(['guid' => 'missing-'.$index]);
        }
        $this->bindNzb(['missing-1', 'missing-2', 'missing-3'], onLoad: function (string $guid): void {
            if ($guid === 'missing-2') {
                $this->travel(31)->seconds();
            }
        });
        $pages = [];
        $this->observePar2Pages($pages);

        $this->runBatch();

        $this->assertSame(['missing-1', 'missing-2'], $this->visited);
        $this->assertCount(1, $pages);
        $this->assertSame(3, Release::query()->where('proc_par2', 0)->count());
        $this->bindNzb(['missing-1', 'missing-2', 'missing-3']);
        $this->runBatch();
        $this->assertSame(['missing-1', 'missing-2', 'missing-3'], $this->visited);
    }

    public function test_lease_loss_at_the_page_boundary_preserves_the_last_owned_cursor(): void
    {
        config(['tmux.fix_names.par2_max_local_skips' => 10]);
        $first = $this->pendingPar2Release(['guid' => 'missing-1']);
        $this->pendingPar2Release(['guid' => 'missing-2']);
        $this->pendingPar2Release(['guid' => 'missing-3']);
        $store = Cache::store('array')->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        $this->bindNzb(['missing-1', 'missing-2', 'missing-3'], onLoad: static function (string $guid) use ($store): void {
            if ($guid === 'missing-2') {
                $store->locks['rename:par2:sweep:2:1:lock']['owner'] = 'replacement-owner';
            }
        });
        $pages = [];
        $this->observePar2Pages($pages);

        $this->runBatch();

        $this->assertSame(['missing-1', 'missing-2'], $this->visited);
        $this->assertCount(1, $pages);
        $this->assertSame($first->id, Cache::store('array')->get('rename:par2:sweep:2:1')['after_id']);
        $this->assertSame('replacement-owner', $store->locks['rename:par2:sweep:2:1:lock']['owner']);
        $this->assertSame(3, Release::query()->where('proc_par2', 0)->count());
    }

    #[DataProvider('initialSweepStops')]
    public function test_an_expired_deadline_or_lost_lease_prevents_the_first_page_fetch(string $stop): void
    {
        $this->freezeTime();
        config(['tmux.fix_names.par2_max_local_skips' => 10]);
        $this->pendingPar2Release(['guid' => 'missing']);
        $this->bindNzb(['missing']);
        $store = Cache::store('array')->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);
        DB::listen(function (QueryExecuted $query) use ($stop, $store): void {
            if ($query->sql === 'SELECT MAX(id) AS highest_id FROM releases') {
                if ($stop === 'deadline') {
                    $this->travel(31)->seconds();
                } else {
                    $store->locks['rename:par2:sweep:2:1:lock']['owner'] = 'replacement-owner';
                }
            }
        });
        $pages = [];
        $this->observePar2Pages($pages);

        $this->runBatch();

        $this->assertSame([], $pages);
        $this->assertSame([], $this->visited);
        $this->assertNull(Cache::store('array')->get('rename:par2:sweep:2:1'));
        $this->assertSame(1, Release::query()->where('proc_par2', 0)->count());
    }

    /** @return array<string, array{string}> */
    public static function initialSweepStops(): array
    {
        return ['deadline' => ['deadline'], 'lease ownership' => ['lease']];
    }

    public function test_new_arrivals_do_not_extend_the_current_sweep_and_starve_retries(): void
    {
        $this->pendingPar2Release(['guid' => 'old-1']);
        $this->pendingPar2Release(['guid' => 'old-2']);
        $this->bindNzb(['old-1', 'old-2']);
        $this->runBatch();
        $this->pendingPar2Release(['guid' => 'new-3']);
        $this->runBatch();
        $this->assertSame(['old-1', 'old-2', 'old-1', 'old-2'], $this->visited);
    }

    public function test_grouped_retryable_result_does_not_count_as_a_rename_or_complete_the_check(): void
    {
        $release = $this->pendingPar2Release([
            'leftguid' => 'a', 'proc_nfo' => 1, 'proc_files' => 1, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1,
        ]);
        $stats = (new NameFixingService)->processStandardBatch('a', 100, false,
            static fn (object $release): Par2RenameOutcome => Par2RenameOutcome::Retryable);
        $this->assertSame(['checked' => 1, 'fixed' => 0], $stats);
        $this->assertSame(0, (int) $release->fresh()->proc_par2);
    }

    public function test_grouped_retryable_releases_rotate_to_older_work_and_wrap_across_processes(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->pendingPar2Release([
                'leftguid' => 'a', 'proc_nfo' => 1, 'proc_files' => 1, 'proc_uid' => 1,
                'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1,
            ])->id;
        }
        $visited = [];
        $processor = static function (object $release) use (&$visited): Par2RenameOutcome {
            $visited[] = (int) $release->releases_id;

            return Par2RenameOutcome::Retryable;
        };
        for ($i = 0; $i < 3; $i++) {
            (new NameFixingService)->processStandardBatch('a', 2, false, $processor);
        }
        $this->assertSame([$ids[2], $ids[1], $ids[0], $ids[2], $ids[1]], $visited);
        $this->assertSame(3, Release::query()->where('proc_par2', 0)->count());
    }

    public function test_sweep_cursor_survives_a_long_historical_cohort(): void
    {
        $this->freezeTime();
        for ($i = 1; $i <= 3; $i++) {
            $this->pendingPar2Release(['guid' => 'guid-'.$i]);
        }
        $this->bindNzb(['guid-1', 'guid-2']);
        $this->runBatch();
        $this->travel(8)->days();
        $this->runBatch();
        $this->assertSame(['guid-1', 'guid-2', 'guid-3'], $this->visited);
    }

    public function test_grouped_reservation_claim_defers_an_overlapping_selector(): void
    {
        $release = $this->pendingPar2Release(['leftguid' => 'a']);
        $store = Cache::store('array')->getStore();
        self::assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock('rename:standard:cursor:a:lock', 120);
        $this->assertTrue($lock->get());
        try {
            $result = (new NameFixingService)->processStandardBatch('a', 1, false,
                static fn (): never => throw new RuntimeException('Must not process claimed work'));
            $this->assertSame(['checked' => 0, 'fixed' => 0], $result);
            $this->assertSame(0, (int) $release->fresh()->proc_par2);
        } finally {
            $lock->release();
        }
    }

    public function test_interrupted_grouped_work_does_not_repeat_the_newest_slice_forever(): void
    {
        $older = $this->pendingPar2Release([
            'leftguid' => 'a', 'proc_nfo' => 1, 'proc_files' => 1, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1,
        ]);
        $newer = $this->pendingPar2Release([
            'leftguid' => 'a', 'proc_nfo' => 1, 'proc_files' => 1, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1,
        ]);
        try {
            (new NameFixingService)->processStandardBatch('a', 1, false,
                static fn (): never => throw new RuntimeException('Worker interrupted'));
            $this->fail('Expected interrupted processing.');
        } catch (RuntimeException $e) {
            $this->assertSame('Worker interrupted', $e->getMessage());
        }
        $visited = [];
        $processor = static function (object $release) use (&$visited): Par2RenameOutcome {
            $visited[] = (int) $release->releases_id;

            return Par2RenameOutcome::Retryable;
        };
        (new NameFixingService)->processStandardBatch('a', 1, false, $processor);
        (new NameFixingService)->processStandardBatch('a', 1, false, $processor);
        $this->assertSame([$older->id, $newer->id], $visited);
        $this->assertSame(2, Release::query()->where('proc_par2', 0)->count());
    }

    public function test_time_budget_yields_and_the_next_batch_resumes(): void
    {
        $this->freezeTime();
        for ($i = 1; $i <= 3; $i++) {
            $this->pendingPar2Release(['guid' => 'guid-'.$i]);
        }
        $this->bindNzb(onLoad: function (string $guid): void {
            if ($guid === 'guid-1') {
                $this->travel(31)->seconds();
            }
        });
        $this->runBatch();
        $this->assertSame(['guid-1'], $this->visited);
        $this->runBatch();
        $this->assertSame(['guid-1', 'guid-2', 'guid-3'], $this->visited);
    }

    public function test_a_cache_outage_stops_the_batch_with_an_error_and_preserves_work(): void
    {
        $release = $this->pendingPar2Release();
        Cache::shouldReceive('store')->with('array')->andThrow(new RuntimeException('cache unavailable'));
        try {
            $this->runBatch();
            $this->fail('The batch must report a failed coordination backend.');
        } catch (RuntimeException $e) {
            $this->assertSame('cache unavailable', $e->getMessage());
        }
        $this->assertSame(0, (int) $release->fresh()->proc_par2);
    }

    public function test_grouped_connection_failure_leaves_par2_pending(): void
    {
        $release = $this->pendingPar2Release([
            'leftguid' => 'a', 'proc_nfo' => 1, 'proc_files' => 1, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1,
        ]);
        $this->app->instance(NNTPService::class, new class('Connection limit reached') extends NNTPService
        {
            public function __construct(private string $connectionError) {}

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                return new Error($this->connectionError, 502);
            }

            public function doQuit(bool $force = false): mixed
            {
                return true;
            }
        });
        Artisan::call('releases:fix-names-group', ['type' => 'standard', '--guid-char' => 'a', '--limit' => 1]);
        $this->assertSame(0, (int) $release->fresh()->proc_par2);
        $this->assertStringContainsString('Fixed 0 release names', Artisan::output());
    }

    /** @param list<string> $unavailable */
    private function bindNzb(array $unavailable = [], ?\Closure $onLoad = null): void
    {
        $load = function (string $guid) use ($unavailable, $onLoad): SimpleXMLElement|false {
            $this->visited[] = $guid;
            if ($onLoad !== null) {
                $onLoad($guid);
            }

            return in_array($guid, $unavailable, true) ? false : new SimpleXMLElement('<nzb><file subject="movie.mkv yEnc (1/10)"><segments><segment bytes="1000" number="1">video-id</segment></segments></file></nzb>');
        };
        $this->app->instance(NzbContentsService::class, new class($load) extends NzbContentsService
        {
            public function __construct(private \Closure $load)
            {
                $this->parserService = new NzbParserService;
                $this->postProcessService = app(PostProcessService::class);
            }

            public function loadNzb(string $guid): SimpleXMLElement|false
            {
                return ($this->load)($guid);
            }
        });
    }

    /** @param list<array{limit:int, after:int, through:int}> $pages */
    private function observePar2Pages(array &$pages): void
    {
        DB::listen(static function (QueryExecuted $query) use (&$pages): void {
            if (str_contains($query->sql, 'r.nzbstatus = 1') && str_contains($query->sql, 'ORDER BY r.id ASC')) {
                $count = count($query->bindings);
                $pages[] = ['limit' => (int) $query->bindings[$count - 1], 'after' => (int) $query->bindings[$count - 3], 'through' => (int) $query->bindings[$count - 2]];
            }
        });
    }

    private function runBatch(): void
    {
        $nntp = $this->createMock(NNTPService::class);
        $nntp->expects($this->never())->method('getMessageBounded');
        $nntp->expects($this->never())->method('getMessages');
        (new NameFixingService)->fixNamesWithPar2(2, true, 1, true, false, $nntp);
    }
}
