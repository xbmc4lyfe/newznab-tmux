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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;
use Throwable;

final class PredbFilenameCompletionRollbackNewTransactionTest extends TestCase
{
    use Par2RenameTestDatabase;

    public function test_public_completion_cleanup_never_consumes_the_rollback_callbacks_new_raw_transaction(): void
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
        $extractor->expects(self::once())->method('extractFromFile')->with($filename)->willReturn(null);
        $syncCalls = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$syncCalls): void {
            $syncCalls[] = $id;
        });
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updater, fileExtractor: $extractor);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        DB::statement('CREATE TABLE callback_owned_marker (value INTEGER NOT NULL)');
        $before = $this->rawSnapshot($pdo);
        $writeFailure = new RuntimeException('Synthetic original completion write failure.');
        $rollbackFailure = new RuntimeException('Synthetic second rollback callback failure.');
        $writes = 0;
        $effectCalls = 0;
        $commitCallbackCalls = 0;
        $firstCallbackPdoStates = [];
        $rollbackFailuresThrown = [];
        $writeWasThrown = false;
        $testOwnedTransactionStarted = false;
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $pdo, $writeFailure, $rollbackFailure, &$writes, &$effectCalls, &$commitCallbackCalls, &$firstCallbackPdoStates, &$rollbackFailuresThrown, &$writeWasThrown, &$testOwnedTransactionStarted): void {
            if ($event->connection !== $connection || ! str_starts_with($event->sql, 'update "releases"') || ! str_contains($event->sql, '"proc_files"')) {
                return;
            }
            $writes++;
            $connection->afterCommit(static function () use (&$commitCallbackCalls): void {
                $commitCallbackCalls++;
            });
            $connection->afterRollBack(static function () use ($pdo, &$effectCalls, &$firstCallbackPdoStates, &$testOwnedTransactionStarted): void {
                $firstCallbackPdoStates[] = $pdo->inTransaction();
                // Original SQL has rolled back. This raw transaction belongs to
                // this test callback, never to completion's failed root write.
                $pdo->beginTransaction();
                $testOwnedTransactionStarted = true;
                $pdo->exec('INSERT INTO callback_owned_marker (value) VALUES (17)');
                $effectCalls++;
            });
            $connection->afterRollBack(static function () use ($rollbackFailure, &$rollbackFailuresThrown): void {
                $rollbackFailuresThrown[] = $rollbackFailure;
                throw $rollbackFailure;
            });
            $writeWasThrown = true;
            throw $writeFailure;
        });
        try {
            $observed = null;
            try {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } catch (Throwable $error) {
                $observed = $error;
            }

            self::assertSame(1, $effectCalls, 'The callback that starts a new transaction must run exactly once.');
            self::assertSame([false], $firstCallbackPdoStates, 'The failed root SQL must roll back before the callback takes its own custody.');
            self::assertTrue($writeWasThrown);
            self::assertSame([$rollbackFailure], $rollbackFailuresThrown);
            self::assertSame(1, $writes, 'Rollback callback failure must prevent retry.');
            self::assertTrue($pdo->inTransaction(), 'Cleanup must not roll back the callback-owned raw transaction.');
            self::assertSame([['value' => 17]], $pdo->query('SELECT value FROM callback_owned_marker')->fetchAll(PDO::FETCH_ASSOC));
            self::assertSame(0, $connection->transactionLevel());
            self::assertSame($before, $this->rawSnapshot($pdo), 'Original completion data must be restored while the callback-owned transaction stays active.');
            self::assertInstanceOf(RuntimeException::class, $observed);
            self::assertStringContainsString('discard this context', $observed->getMessage());
            self::assertSame($writeFailure, $observed->getPrevious(), 'The captured original write failure must survive the cleanup wrapper.');
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
            self::assertSame(0, $commitCallbackCalls);
            self::assertSame([], $syncCalls);
            self::assertSame(0, $updater->fixed);
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } finally {
            if ($testOwnedTransactionStarted && $pdo->inTransaction()) {
                // Explicit test custody only. Product cleanup must not perform
                // this rollback, and the uncertain manager must never be replayed.
                $pdo->rollBack();
            }
            $connection->setEventDispatcher($dispatcher);
            // Test-only teardown discards the uncertain manager; never replay it.
            $connection->setTransactionManager(new DatabaseTransactionsManager);
            $connection->setPdo($pdo)->setReadPdo(null)->setDirectPdo(null);
        }
    }

    private function rawSnapshot(PDO $pdo): array
    {
        $result = [];
        foreach ([
            'releases' => ['id'], 'release_files' => ['releases_id', 'name'], 'predb' => ['id'],
            'predb_identity_state' => ['id'], 'predb_identity_revisions' => ['predb_id'],
            'predb_identity_keys' => ['generation', 'kind', 'key_digest', 'predb_id'],
        ] as $table => $columns) {
            $result[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY '.implode(', ', $columns))->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }
}
