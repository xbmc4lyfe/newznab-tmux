<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class NfoAuthoritativeIdentityTest extends TestCase
{
    use Par2RenameTestDatabase;

    /** Retained public 2026-10-11 HDEncode General Filename identity. */
    private const TITLE = 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None';

    private const SOURCE = 'Different.Show.S01E01.1080p.WEB-DL-SOURCE';

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->useEnvironmentPath(sys_get_temp_dir())->loadEnvironmentFrom('.nntmux-validation-no-env');
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        DB::connection()->getPdo()->sqliteCreateCollation('nfo_identity_ci', static fn (string $a, string $b): int => strcmp(
            mb_strtolower(str_replace(['í', 'Í'], 'i', $a), 'UTF-8'), mb_strtolower(str_replace(['í', 'Í'], 'i', $b), 'UTF-8')));
        DB::statement('DROP TABLE predb');
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT COLLATE nfo_identity_ci NOT NULL)');
        config(['nntmux.echocli' => false, 'tmux.fix_names.cache_store' => 'array']);
        Cache::store('array')->flush();
        Event::fake([ReleaseNameFixed::class]);
    }

    #[DataProvider('asyncNfoObservationChanges')]
    public function test_async_nfo_save_rejects_obsolete_content_and_never_changes_the_new_cohort_status(string $change): void
    {
        if (! Schema::hasColumn('releases', 'additional_pack_evidence')) {
            Schema::table('releases', static function (Blueprint $table): void {
                $table->json('additional_pack_evidence')->nullable();
            });
        }
        Schema::table('release_files', static function (Blueprint $table): void {
            $table->unsignedBigInteger('size')->default(0);
        });
        $guid = 'nfo-cohort-guid';
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'name' => 'Source', 'searchname' => 'Source',
            'groups_id' => 1, 'size' => 100, 'nfostatus' => -1, 'nzbstatus' => $change === 'initial-none' ? 0 : 1]);
        $contents = Mockery::mock(NzbContentsService::class);
        $contents->shouldReceive('setNntp', 'setNfo')->andReturnNull();
        $fetches = 0;
        $contents->shouldReceive('getNfoFromNzb')->andReturnUsing(static function () use ($change, $guid, &$fetches): string {
            $fetches++;
            $values = match ($change) {
                'epoch' => ['additional_pack_evidence' => json_encode(['v' => 1,
                    'identity_sha256' => hash('sha256', json_encode(['1', $guid], JSON_THROW_ON_ERROR)),
                    'epoch' => str_repeat('b', 64), 'state' => null], JSON_THROW_ON_ERROR)],
                'guid' => ['guid' => 'new-nfo-guid'],
                'identity' => ['searchname' => 'New.Identity'],
                'unpublished' => ['nzbstatus' => 0],
                default => [],
            };
            if ($values !== []) {
                DB::table('releases')->where('id', 1)->update($values);
            }

            return 'Release : Show.S01E01.1080p.WEB-DL-GRP';
        });
        $this->app->instance(NzbContentsService::class, $contents);
        $service = new NfoService;
        foreach (['nzbs' => 1, 'maxRetries' => -8, 'maxSize' => 0, 'minSize' => 0, 'echo' => false] as $property => $value) {
            (new ReflectionProperty(NfoService::class, $property))->setValue($service, $value);
        }

        $processed = $service->processNfoFiles($this->createStub(NNTPService::class));

        self::assertSame($change === 'initial-none' ? 0 : 1, $fetches);
        self::assertSame($change === 'unchanged' ? 1 : 0, $processed);
        self::assertSame($change === 'unchanged' ? 1 : 0, DB::table('release_nfos')->count());
        self::assertSame($change === 'unchanged' ? 1 : -1, (int) DB::table('releases')->value('nfostatus'));
    }

    public static function asyncNfoObservationChanges(): array
    {
        return ['epoch during fetch' => ['epoch'], 'GUID during fetch' => ['guid'], 'identity during fetch' => ['identity'],
            'unpublished during fetch' => ['unpublished'], 'unpublished before fetch' => ['initial-none'], 'valid save' => ['unchanged']];
    }

    /** @return iterable<string, array{string, string}> */
    public static function authoritativeFields(): iterable
    {
        foreach (['nfo', 'standard', 'checkName'] as $handler) {
            foreach ([
                'Release literal' => 'Release : '.self::TITLE."\n",
                'Filename literal' => 'Filename : '.self::TITLE."\n",
                'Filename media suffix' => 'Filename : '.self::TITLE.".mkv\n",
                'Filename Unicode case' => 'Filename : '.mb_strtoupper(self::TITLE, 'UTF-8').".MKV\n",
                'Filename Unix path' => 'Filename : /incoming/'.self::TITLE.".mkv\n",
                'Filename Windows path' => 'Filename : C:\\incoming\\'.self::TITLE.".mkv\n",
                'movie Release' => "Release : Example.Movie.2026.1080p.BluRay.x264-TARGET\n",
            ] as $label => $nfo) {
                yield $handler.' '.$label => [$handler, $nfo];
            }
        }
    }

    #[DataProvider('authoritativeFields')]
    public function test_public_handlers_link_existing_authoritative_fields_before_unconfirmed_extraction(string $handler, string $field): void
    {
        $title = str_starts_with($field, 'Release : Example.') ? 'Example.Movie.2026.1080p.BluRay.x264-TARGET' : self::TITLE;
        DB::table('predb')->insert([['id' => 6, 'title' => self::SOURCE], ['id' => 7, 'title' => $title]]);
        [$release, $updates] = $this->runHandler($handler, 'Source : '.self::SOURCE."\n".$field);

        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame(1, $updates->fixed);
        Event::assertDispatched(ReleaseNameFixed::class, 1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedEvidence(): iterable
    {
        foreach (['nfo', 'standard', 'checkName'] as $handler) {
            foreach (['alias', 'ambiguous', 'duplicate', 'invalid'] as $evidence) {
                yield $handler.' '.$evidence => [$handler, $evidence];
            }
        }
    }

    #[DataProvider('rejectedEvidence')]
    public function test_nonempty_invalid_or_ambiguous_authoritative_evidence_cannot_fall_into_unconfirmed_naming(string $handler, string $evidence): void
    {
        $rows = match ($evidence) {
            'alias' => [['id' => 7, 'title' => str_replace('Rosalía', 'Rosalia', self::TITLE)]],
            'duplicate' => [['id' => 7, 'title' => self::TITLE], ['id' => 8, 'title' => self::TITLE]],
            'invalid' => [['id' => 0, 'title' => self::TITLE]],
            default => [['id' => 7, 'title' => self::TITLE], ['id' => 8, 'title' => 'Another.Show.S01E01.1080p.WEB-DL-OTHER']],
        };
        DB::table('predb')->insert($rows);
        $nfo = 'Filename : '.self::TITLE.".mkv\n";
        if ($evidence === 'ambiguous') {
            $nfo .= "Release : Another.Show.S01E01.1080p.WEB-DL-OTHER\n";
        }
        [$release, $updates] = $this->runHandler($handler, $nfo);

        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->proc_nfo);
        self::assertSame(0, $updates->fixed);
        self::assertFalse($updates->matched);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{string}> */
    public static function handlers(): iterable
    {
        foreach (['nfo', 'standard', 'checkName'] as $handler) {
            yield $handler => [$handler];
        }
    }

    #[DataProvider('handlers')]
    public function test_true_miss_preserves_complete_unicode_filename_for_later_linking(string $handler): void
    {
        DB::table('predb')->insert(['id' => 6, 'title' => self::SOURCE]);
        [$release] = $this->runHandler($handler, 'Source : '.self::SOURCE."\nFilename : ".self::TITLE.".mkv\n");

        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame(0, $release->fresh()->predb_id);
    }

    #[DataProvider('handlers')]
    public function test_candidate_overflow_stays_pending_instead_of_using_a_partial_identity(string $handler): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        $nfo = 'Filename : '.self::TITLE."\n";
        foreach (range(1, 100) as $number) {
            $nfo .= 'Release : Another.Show.S01E'.$number.".1080p.WEB-DL-OTHER\n";
        }
        [$release, $updates] = $this->runHandler($handler, $nfo);

        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->proc_nfo);
        self::assertSame(0, $updates->fixed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('handlers')]
    public function test_lookup_callbacks_cannot_redirect_old_nfo_identity_to_a_different_caller(string $handler): void
    {
        $attributes = ['name' => 'Opaque.Subject', 'searchname' => 'Hashed.Title', 'leftguid' => 'a',
            'passwordstatus' => 0, 'nfostatus' => 1, 'proc_nfo' => 0, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1, 'proc_par2' => 1, 'proc_files' => 1];
        $target = $this->pendingPar2Release($attributes);
        $other = $this->pendingPar2Release(array_merge($attributes, ['leftguid' => 'b', 'name' => 'Other.Subject', 'searchname' => 'Other.Identity']));
        $redirect = (object) array_merge($other->getAttributes(), ['releases_id' => $other->id]);
        $nfo = 'Filename : '.self::TITLE.".mkv\n";
        DB::table('release_nfos')->insert(['releases_id' => $target->id, 'nfo' => $nfo]);
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        $database = DB::connection();
        $captured = null;
        $redirected = false;
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('select')->willReturnCallback(static function (string $sql, array $bindings = [], bool $useReadPdo = true) use ($database, $redirect, &$captured, &$redirected): array {
            if ($captured !== null && ! $redirected && str_contains($sql, 'FROM release_nfos rn')) {
                $redirected = true;
                foreach (get_object_vars($redirect) as $key => $value) {
                    $captured->{$key} = $value;
                }
            }
            $rows = $database->select($sql, $bindings, $useReadPdo);
            if (str_contains($sql, 'r.id AS releases_id')) {
                $captured = $rows[0] ?? null;
            }

            return $rows;
        });
        $queries = new NameFixingQueryService($connection);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, queries: $queries);
        if ($handler === 'checkName') {
            $input = (object) array_merge($target->getAttributes(), ['releases_id' => $target->id, 'textstring' => $nfo]);
            $fired = false;
            DB::connection()->beforeExecuting(static function (string $sql) use ($input, $redirect, &$fired): void {
                if (! $fired && str_contains($sql, 'from "predb"')) {
                    $fired = true;
                    foreach (get_object_vars($redirect) as $key => $value) {
                        $input->{$key} = $value;
                    }
                }
            });
            $service->checkName($input, true, 'NFO, ', true, false);
            self::assertTrue($fired);
        } elseif ($handler === 'nfo') {
            $service->fixNamesWithNfo(0, true, 2, true, false);
            self::assertTrue($redirected);
        } else {
            $service->processStandardBatch('a', 10, false);
            self::assertTrue($redirected);
        }

        self::assertSame(self::TITLE, $target->fresh()->searchname);
        self::assertSame(7, $target->fresh()->predb_id);
        self::assertSame('Other.Identity', $other->fresh()->searchname);
        self::assertSame(0, $other->fresh()->predb_id);
    }

    /** @return iterable<string, array{string}> */
    public static function reusedServicePasses(): iterable
    {
        yield 'preview followed by actual write' => ['preview'];
        yield 'rolled back write followed by retry' => ['rollback'];
    }

    #[DataProvider('reusedServicePasses')]
    public function test_reused_public_nfo_handler_applies_a_preview_or_rolled_back_name_on_the_next_real_pass(string $firstPass): void
    {
        $release = $this->unconfirmedNfoRelease('Filename : '.self::TITLE."\n");
        [$service, $updates] = $this->unconfirmedServices();
        if ($firstPass === 'rollback') {
            DB::beginTransaction();
        }
        try {
            $service->fixNamesWithNfo(0, $firstPass !== 'preview', 2, true, false);
        } finally {
            if ($firstPass === 'rollback') {
                DB::rollBack();
            }
        }
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        self::assertSame(0, $release->fresh()->proc_nfo);
        self::assertNull($updates->lastCommittedIdentity());
        Event::assertNotDispatched(ReleaseNameFixed::class);
        if ($firstPass === 'preview') {
            self::assertSame(1, $updates->fixed);
        }

        $service->fixNamesWithNfo(0, true, 2, true, false);

        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame(1, $release->fresh()->proc_nfo);
        self::assertSame($firstPass === 'preview' ? 2 : 1, $updates->fixed);
        Event::assertDispatched(ReleaseNameFixed::class, 1);
    }

    public function test_cumulative_actual_nfo_counts_keep_prior_commits_and_exclude_a_rollback_before_retry(): void
    {
        [$service, $updates] = $this->unconfirmedServices();
        $first = $this->unconfirmedNfoRelease('Filename : '.self::TITLE."\n");
        $service->fixNamesWithNfo(0, true, 2, true, false);
        self::assertSame(self::TITLE, $first->fresh()->searchname);
        self::assertSame(1, $updates->fixed);
        $secondTitle = 'Another.Scene.Movie.2026.1080p.WEB-DL-GRP';
        $second = $this->unconfirmedNfoRelease('Filename : '.$secondTitle."\n");
        DB::beginTransaction();
        try {
            $service->fixNamesWithNfo(0, true, 2, true, false);
            $during = $updates->fixed;
        } finally {
            DB::rollBack();
        }
        self::assertSame([1, 1, 'Hashed.Title', 0], [$during, $updates->fixed, $second->fresh()->searchname, $second->fresh()->proc_nfo]);
        Event::assertDispatched(ReleaseNameFixed::class, 1);

        $service->fixNamesWithNfo(0, true, 2, true, false);

        self::assertSame($secondTitle, $second->fresh()->searchname);
        self::assertSame(2, $updates->fixed);
        Event::assertDispatched(ReleaseNameFixed::class, 2);
    }

    /** @return array{NameFixingService, ReleaseUpdateService} */
    private function unconfirmedServices(): array
    {
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);

        return [new NameFixingService($updates), $updates];
    }

    private function unconfirmedNfoRelease(string $nfo): Release
    {
        $release = $this->pendingPar2Release([
            'name' => 'Opaque.Subject', 'searchname' => 'Hashed.Title', 'leftguid' => 'a',
            'passwordstatus' => 0, 'nfostatus' => 1, 'proc_nfo' => 0, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1, 'proc_par2' => 1, 'proc_files' => 1,
        ]);
        DB::table('release_nfos')->insert(['releases_id' => $release->id, 'nfo' => $nfo]);

        return $release;
    }

    /** @return array{Release, ReleaseUpdateService} */
    private function runHandler(string $handler, string $nfo): array
    {
        $release = $this->pendingPar2Release([
            'name' => 'Opaque.Subject', 'searchname' => 'Hashed.Title', 'leftguid' => 'a',
            'passwordstatus' => 0, 'nfostatus' => 1, 'proc_nfo' => 0, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_hash16k' => 1, 'proc_crc32' => 1, 'proc_par2' => 1, 'proc_files' => 1,
        ]);
        DB::table('release_nfos')->insert(['releases_id' => $release->id, 'nfo' => $nfo]);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates);
        if ($handler === 'nfo') {
            $service->fixNamesWithNfo(0, true, 2, true, false);
        } elseif ($handler === 'standard') {
            $service->processStandardBatch('a', 10, false);
        } else {
            $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id, 'relsize' => 5000, 'textstring' => $nfo]);
            $service->checkName($input, true, 'NFO, ', true, false);
        }

        return [$release, $updates];
    }
}
