<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityLookup;
use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;
use Throwable;

final class PredbFilenameCompletionRetryTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function rootFailures(): array
    {
        return ['retry then commit' => [1], 'exhaust three attempts' => [3]];
    }

    #[DataProvider('rootFailures')]
    public function test_public_completion_retries_only_after_clean_owned_failure(int $failures): void
    {
        [$service, $updater] = $this->fixture();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        $name = $connection->getName();
        self::assertIsString($name);
        $owned = static fn ($record): bool => $record->connection === $name;
        $before = $this->snapshot();
        $expected = $before;
        if ($failures < 3) {
            $expected['releases'][0]['proc_files'] = NameFixingService::PROC_FILES_DONE;
        }
        $writes = 0;
        $commits = 0;
        $sentinels = [];
        $callbacks = [];
        $failure = new RuntimeException('database is locked');
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $manager, $owned, $pdo, &$writes, &$sentinels, &$callbacks): void {
            if ($event->connection !== $connection || ! str_starts_with($event->sql, 'update "releases"') || ! str_contains($event->sql, '"proc_files"')) {
                return;
            }
            $writes++;
            PHPUnitTestCase::assertLessThanOrEqual(3, $writes);
            PHPUnitTestCase::assertSame($pdo, $connection->getRawPdo());
            PHPUnitTestCase::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
            PHPUnitTestCase::assertSame(1, $connection->transactionLevel());
            $pending = $manager->getPendingTransactions()->filter($owned)->values();
            PHPUnitTestCase::assertCount(1, $pending);
            PHPUnitTestCase::assertCount(1, $pending[0]->getCallbacks(), 'Only the fresh ownership sentinel precedes the test probe.');
            PHPUnitTestCase::assertFalse($manager->getCommittedTransactions()->contains($owned));
            $sentinel = $pending[0]->getCallbacks()[0];
            foreach ($sentinels as $previous) {
                PHPUnitTestCase::assertNotSame($previous, $sentinel, 'Every owned retry needs a fresh sentinel.');
            }
            $sentinels[] = $sentinel;
            $attempt = $writes;
            $connection->afterCommit(static function () use ($attempt, &$callbacks): void {
                $callbacks[] = $attempt;
            });
        });
        $isolated->listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($connection, $failure, $failures, &$writes, &$commits): void {
            // Public discovery also commits read transactions; arm only after a completion UPDATE.
            if ($event->connection === $connection && $writes > $commits) {
                $commits++;
                if ($commits <= $failures) {
                    throw $failure;
                }
            }
        });
        try {
            $observed = null;
            try {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } catch (Throwable $error) {
                $observed = $error;
            }
            self::assertSame($failures === 3 ? $failure : null, $observed);
            self::assertSame($failures === 3 ? 3 : 2, $writes);
            self::assertSame($writes, $commits);
            self::assertCount($writes, $sentinels);
            self::assertSame($failures === 3 ? [] : [2], $callbacks);
            self::assertSame($pdo, $connection->getRawPdo());
            self::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
            self::assertSame(0, $connection->transactionLevel());
            self::assertFalse($pdo->inTransaction());
            self::assertFalse($manager->getPendingTransactions()->contains($owned));
            self::assertFalse($manager->getCommittedTransactions()->contains($owned));
            self::assertSame($expected, $this->snapshot());
            self::assertSame(0, $updater->fixed);
            Event::assertNotDispatched(ReleaseNameFixed::class);
            $fresh = 0;
            $connection->transaction(static function () use ($connection, &$fresh): void {
                $connection->afterCommit(static function () use (&$fresh): void {
                    $fresh++;
                });
            });
            self::assertSame(1, $fresh);
            self::assertSame($failures === 3 ? [] : [2], $callbacks);
        } finally {
            $connection->setEventDispatcher($dispatcher);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $manager->rollback($name, 0);
        }
    }

    public function test_public_completion_inside_caller_transaction_never_retries_or_owns_root_cleanup(): void
    {
        [$service] = $this->fixture();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        $before = $this->snapshot();
        $callerCallbackCalls = 0;
        $connection->beginTransaction();
        $connection->afterCommit(static function () use (&$callerCallbackCalls): void {
            $callerCallbackCalls++;
        });
        $owner = $manager->getPendingTransactions()->sole();
        $ownerCallbacks = $owner->getCallbacks();
        $failure = new RuntimeException('database is locked');
        $writes = 0;
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $failure, &$writes): void {
            if ($event->connection === $connection && str_starts_with($event->sql, 'update "releases"') && str_contains($event->sql, '"proc_files"')) {
                $writes++;
                throw $failure;
            }
        });
        try {
            $observed = null;
            try {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } catch (Throwable $error) {
                $observed = $error;
            }
            self::assertInstanceOf(DeadlockException::class, $observed);
            self::assertSame($failure, $observed->getPrevious());
            self::assertSame(1, $writes);
            self::assertSame(1, $connection->transactionLevel());
            self::assertTrue($pdo->inTransaction());
            self::assertSame($pdo, $connection->getRawPdo());
            self::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
            self::assertSame($owner, $manager->getPendingTransactions()->sole());
            self::assertSame($ownerCallbacks, $owner->getCallbacks());
            self::assertSame(0, $callerCallbackCalls);
            // Caller retains custody; the synthetic SQLite deadlock does not claim a backend rollback.
            $connection->rollBack();
            self::assertSame($before, $this->snapshot());
            self::assertSame(0, $callerCallbackCalls);
            self::assertFalse($pdo->inTransaction());
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } finally {
            $connection->setEventDispatcher($dispatcher);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $manager->rollback($connection->getName(), 0);
        }
    }

    public static function nullGuardCases(): array
    {
        return ['valid SQL authority' => [false], 'changed SQL authority' => [true]];
    }

    #[DataProvider('nullGuardCases')]
    public function test_null_original_guard_still_requires_current_full_sql_authority(bool $changeAuthority): void
    {
        [$service, $updater] = $this->fixture(false);
        $filename = 'Synthetic.Absent.Release.2026-GRP.mkv';
        $release = DB::table('releases')->first();
        self::assertNotNull($release);
        $release->releases_id = $release->id;
        $pass = (new ReflectionMethod($service, 'collectPredbFilenamePass'))->invoke($service, [$filename]);
        if ($changeAuthority) {
            // Keep inventory unchanged while the actual catalogue trigger invalidates certification.
            DB::table('predb')->insert(['title' => 'Synthetic.New.Catalogue.Release-GRP', 'filename' => '']);
        }
        $expected = $this->snapshot();
        if (! $changeAuthority) {
            $expected['releases'][0]['proc_files'] = NameFixingService::PROC_FILES_DONE;
        }
        (new ReflectionMethod($service, 'completeFilenamePass'))->invoke($service, $release, [$filename], true, true, null, $pass);
        self::assertSame($expected, $this->snapshot());
        self::assertSame([$filename], DB::table('release_files')->pluck('name')->all());
        self::assertSame(0, $updater->fixed);
        self::assertSame(0, DB::connection()->transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_public_completion_cleanup_failure_poisons_all_routes_without_retry(): void
    {
        [$service, $updater] = $this->fixture();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $before = $this->snapshot();
        $failure = new RuntimeException('database is locked');
        $rollbackFailure = new RuntimeException('Synthetic completion rollback callback failure.');
        $writes = 0;
        $commits = 0;
        $commitCallbackCalls = 0;
        $rollbackCallbackCalls = 0;
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $rollbackFailure, &$writes, &$commitCallbackCalls, &$rollbackCallbackCalls): void {
            if ($event->connection === $connection && str_starts_with($event->sql, 'update "releases"') && str_contains($event->sql, '"proc_files"')) {
                $writes++;
                $connection->afterCommit(static function () use (&$commitCallbackCalls): void {
                    $commitCallbackCalls++;
                });
                $connection->afterRollBack(static function () use ($rollbackFailure, &$rollbackCallbackCalls): void {
                    $rollbackCallbackCalls++;
                    throw $rollbackFailure;
                });
            }
        });
        $isolated->listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($connection, $failure, &$writes, &$commits): void {
            if ($event->connection === $connection && $writes > $commits) {
                $commits++;
                throw $failure;
            }
        });
        try {
            $observed = null;
            try {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } catch (Throwable $error) {
                $observed = $error;
            }
            self::assertInstanceOf(RuntimeException::class, $observed);
            self::assertSame('PreDB identity cleanup failed; discard this context.', $observed->getMessage());
            self::assertSame($failure, $observed->getPrevious());
            self::assertSame(1, $writes, 'Cleanup failure must stop the completion retry loop.');
            self::assertSame(1, $commits);
            self::assertSame(1, $rollbackCallbackCalls);
            self::assertSame(0, $commitCallbackCalls);
            self::assertFalse($pdo->inTransaction());
            $rawAfter = [];
            foreach ([
                'releases' => ['id'], 'release_files' => ['releases_id', 'name'], 'predb' => ['id'],
                'predb_identity_state' => ['id'], 'predb_identity_revisions' => ['predb_id'],
                'predb_identity_keys' => ['generation', 'kind', 'key_digest', 'predb_id'],
            ] as $table => $columns) {
                $rawAfter[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY '.implode(', ', $columns))->fetchAll(\PDO::FETCH_ASSOC);
            }
            self::assertSame($before, $rawAfter, 'Captured writer must show the entire rejected completion rolled back.');
            foreach (['getPdo', 'getReadPdo', 'getDirectPdo'] as $getter) {
                $blocked = null;
                try {
                    $connection->{$getter}();
                } catch (RuntimeException $error) {
                    $blocked = $error;
                }
                self::assertInstanceOf(RuntimeException::class, $blocked);
                self::assertStringContainsString('discard this context', $blocked->getMessage());
            }
            self::assertSame(0, $updater->fixed);
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->setEventDispatcher($dispatcher);
            // Discard the deliberately poisoned test-only manager without replaying its callbacks.
            $connection->setTransactionManager(new DatabaseTransactionsManager);
            $connection->setPdo($pdo)->setReadPdo(null)->setDirectPdo(null);
        }
    }

    public function test_null_original_guard_rechecks_sql_authority_after_completion_write(): void
    {
        [$service, $updater] = $this->fixture(false);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        $name = $connection->getName();
        self::assertIsString($name);
        $owned = static fn ($record): bool => $record->connection === $name;
        $filename = 'Synthetic.Absent.Release.2026-GRP.mkv';
        $release = DB::table('releases')->first();
        self::assertNotNull($release);
        $release->releases_id = $release->id;
        $pass = (new ReflectionMethod($service, 'collectPredbFilenamePass'))->invoke($service, [$filename]);
        $before = $this->snapshot();
        $writes = 0;
        $mutationObserved = false;
        $callbackCalls = 0;
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, &$writes, &$mutationObserved, &$callbackCalls): void {
            if ($event->connection === $connection && str_starts_with($event->sql, 'update "releases"') && str_contains($event->sql, '"proc_files"')) {
                $writes++;
                PHPUnitTestCase::assertSame(1, $writes);
                $connection->afterCommit(static function () use (&$callbackCalls): void {
                    $callbackCalls++;
                });
                // Inventory is untouched; the real PreDB trigger invalidates certified SQL authority.
                $connection->table('predb')->insert(['title' => 'Synthetic.New.Catalogue.Release-GRP', 'filename' => '']);
                $mutationObserved = (int) $connection->table('predb_identity_state')->value('ready') === 0;
            }
        });
        try {
            (new ReflectionMethod($service, 'completeFilenamePass'))->invoke($service, $release, [$filename], true, true, null, $pass);
            self::assertSame(1, $writes, 'A stale postwrite authority result must roll back without retry.');
            self::assertTrue($mutationObserved);
            self::assertSame($before, $this->snapshot(), 'Completion and catalogue/projection mutations must roll back together.');
            self::assertSame([$filename], $connection->table('release_files')->pluck('name')->all());
            self::assertSame($pdo, $connection->getRawPdo());
            self::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
            self::assertSame(0, $connection->transactionLevel());
            self::assertFalse($pdo->inTransaction());
            self::assertFalse($manager->getPendingTransactions()->contains($owned));
            self::assertFalse($manager->getCommittedTransactions()->contains($owned));
            self::assertSame(0, $callbackCalls);
            self::assertSame(0, $updater->fixed);
            Event::assertNotDispatched(ReleaseNameFixed::class);
            $connection->transaction(static function (): void {});
            self::assertSame(0, $callbackCalls, 'Rejected completion callbacks must not leak into a fresh commit.');
        } finally {
            $connection->setEventDispatcher($dispatcher);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $manager->rollback($name, 0);
        }
    }

    private function fixture(bool $expectExtraction = true): array
    {
        $this->createPar2RenameSchema();
        DB::statement("ALTER TABLE predb ADD COLUMN filename VARCHAR NOT NULL DEFAULT ''");
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $release = $this->pendingPar2Release([
            'name' => '0123456789abcdef0123456789abcdef',
            'searchname' => '0123456789abcdef0123456789abcdef',
            'predb_id' => 0, 'proc_files' => NameFixingService::PROC_FILES_NONE,
            'proc_nfo' => 1, 'proc_par2' => 1, 'proc_uid' => 1,
            'proc_srr' => 1, 'proc_crc32' => 1, 'proc_hash16k' => 1,
        ]);
        $filename = 'Synthetic.Absent.Release.2026-GRP.mkv';
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        $page = $rebuild->chunk(DB::connection(), $start['generation']);
        self::assertTrue($page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
        self::assertSame('absent', (new PredbIdentityLookup)->lookup(DB::connection(), new OriginalFilenameEvidence($filename))['status']);
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects($expectExtraction ? self::once() : self::never())->method('extractFromFile')->with($filename)->willReturn(null);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {
            PHPUnitTestCase::fail('ABSENT completion must not synchronize search.');
        });
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);

        return [new NameFixingService($updater, fileExtractor: $extractor), $updater];
    }

    private function snapshot(): array
    {
        $result = [];
        foreach ([
            'releases' => ['id'], 'release_files' => ['releases_id', 'name'], 'predb' => ['id'],
            'predb_identity_state' => ['id'], 'predb_identity_revisions' => ['predb_id'],
            'predb_identity_keys' => ['generation', 'kind', 'key_digest', 'predb_id'],
        ] as $table => $columns) {
            $query = DB::table($table);
            foreach ($columns as $column) {
                $query->orderBy($column);
            }
            $result[$table] = $query->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
