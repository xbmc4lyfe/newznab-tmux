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
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;
use Throwable;

final class PredbFilenameCompletionCallbackFailureTest extends TestCase
{
    use Par2RenameTestDatabase;

    public function test_public_absent_completion_callback_failure_preserves_committed_done(): void
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
        self::assertTrue($page['exhausted'], 'The empty catalogue requires one bounded rebuild page.');
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
        $manager = (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);
        $name = $connection->getName();
        self::assertIsString($name);
        $owned = static fn ($record): bool => $record->connection === $name;
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertFalse($manager->getPendingTransactions()->contains($owned));
        self::assertFalse($manager->getCommittedTransactions()->contains($owned));
        $expected = $this->snapshot();
        self::assertCount(1, $expected['releases']);
        $expected['releases'][0]['proc_files'] = NameFixingService::PROC_FILES_DONE;
        $callbackFailure = new RuntimeException('database is locked');
        $callbackCalls = 0;
        $completionWrites = 0;
        $rollbacks = 0;
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(TransactionRolledBack::class, static function (TransactionRolledBack $event) use ($connection, &$rollbacks): void {
            if ($event->connection === $connection) {
                $rollbacks++;
            }
        });
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $callbackFailure, &$callbackCalls, &$completionWrites): void {
            if ($event->connection === $connection && str_starts_with($event->sql, 'update "releases"') && str_contains($event->sql, '"proc_files"')) {
                $completionWrites++;
                PHPUnitTestCase::assertSame(1, $completionWrites);
                $connection->afterCommit(static function () use ($callbackFailure, &$callbackCalls): void {
                    $callbackCalls++;
                    throw $callbackFailure;
                });
            }
        });
        try {
            $failure = null;
            try {
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } catch (Throwable $error) {
                $failure = $error;
            }
            self::assertSame($callbackFailure, $failure, 'The original post-commit throwable must escape unchanged.');
            self::assertSame(1, $callbackCalls);
            self::assertSame(1, $completionWrites, 'A committed completion must never be retried.');
            self::assertSame(0, $rollbacks, 'Callback failure must not compensate the committed completion.');
            self::assertSame(0, $connection->transactionLevel());
            self::assertSame($pdo, $connection->getRawPdo());
            self::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
            self::assertFalse($pdo->inTransaction());
            self::assertFalse($manager->getPendingTransactions()->contains($owned));
            self::assertFalse($manager->getCommittedTransactions()->contains($owned));
            self::assertSame($expected, $this->snapshot());
            self::assertSame([], $syncCalls);
            self::assertSame(0, $updater->fixed);
            Event::assertNotDispatched(ReleaseNameFixed::class);
            $freshCallbackCalls = 0;
            $connection->transaction(static function () use ($connection, &$freshCallbackCalls): void {
                $connection->afterCommit(static function () use (&$freshCallbackCalls): void {
                    $freshCallbackCalls++;
                });
            });
            self::assertSame(1, $freshCallbackCalls);
            self::assertSame(1, $callbackCalls, 'The failing callback must not replay on an unrelated commit.');
            self::assertSame($expected, $this->snapshot());
        } finally {
            $connection->setEventDispatcher($dispatcher);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $manager->rollback($name, 0);
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach ([
            'releases' => ['id'],
            'release_files' => ['releases_id', 'name'],
            'predb' => ['id'],
            'predb_identity_state' => ['id'],
            'predb_identity_revisions' => ['predb_id'],
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
