<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** Standalone actual SQLite/PDO/Laravel faults, prepared only; no application/provider boot. */
final class PredbIdentityRebuildCustodyTest extends TestCase
{
    use CertifiedPredbSqlFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCertifiedSqlFixture();
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Rebuild.Custody-GRP', 'filename' => '']);
        DB::connection()->setEventDispatcher(new Dispatcher(Container::getInstance()));
    }

    protected function tearDown(): void
    {
        try {
            $this->tearDownCertifiedSqlFixture();
        } finally {
            parent::tearDown();
        }
    }

    public function test_actual_native_commit_rejection_cleans_captured_context_and_discards_callbacks(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = $this->manager();
        DB::statement('PRAGMA foreign_keys = ON');
        DB::statement('CREATE TABLE rebuild_fault_parents (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE rebuild_fault_children (parent_id INTEGER REFERENCES rebuild_fault_parents(id) DEFERRABLE INITIALLY DEFERRED)');
        $before = $this->snapshot($pdo);
        $writes = 0;
        $queuedCalls = 0;
        $deferredInsertSucceeded = false;
        $this->dispatcher()->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $pdo, &$writes, &$queuedCalls, &$deferredInsertSucceeded): void {
            if (! self::isStateWrite($event, $connection)) {
                return;
            }
            $writes++;
            $connection->afterCommit(static function () use (&$queuedCalls): void {
                $queuedCalls++;
            });
            // An unrelated fault table leaves installed identity attestation unchanged.
            // The deferred statement succeeds; only real native COMMIT rejects it.
            $pdo->exec('INSERT INTO rebuild_fault_children(parent_id) VALUES (123)');
            $deferredInsertSucceeded = true;
        });
        try {
            $failure = $this->capture(static fn () => (new PredbIdentityRebuild)->start($connection));
            self::assertInstanceOf(PDOException::class, $failure);
            self::assertSame(PDOException::class, $failure::class);
            self::assertStringContainsString('FOREIGN KEY constraint failed', $failure->getMessage());
            self::assertTrue($deferredInsertSucceeded);
            self::assertSame(1, $writes);
            self::assertFalse($pdo->inTransaction(), 'Rejected native COMMIT must release captured SQL custody.');
            $this->assertClean($connection, $pdo, $manager);
            self::assertSame($before, $this->snapshot($pdo));
            self::assertSame(0, (int) $pdo->query('SELECT count(*) FROM rebuild_fault_children')->fetchColumn());
            self::assertSame(0, $queuedCalls);
            $fresh = 0;
            $connection->transaction(static function () use ($connection, &$fresh): void {
                $connection->afterCommit(static function () use (&$fresh): void {
                    $fresh++;
                });
            });
            self::assertSame(1, $fresh);
            self::assertSame(0, $queuedCalls);
        } finally {
            $this->discardTestContext($connection, $pdo);
        }
    }

    public function test_concurrency_looking_callback_failure_never_retries_or_compensates_committed_start(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = $this->manager();
        $failure = new RuntimeException('database is locked');
        $writes = 0;
        $calls = 0;
        $committedSnapshot = null;
        $this->dispatcher()->listen(QueryExecuted::class, function (QueryExecuted $event) use ($connection, $pdo, $failure, &$writes, &$calls, &$committedSnapshot): void {
            if (! self::isStateWrite($event, $connection)) {
                return;
            }
            $writes++;
            $committedSnapshot = $this->snapshot($pdo);
            $connection->afterCommit(static function () use ($failure, &$calls): void {
                $calls++;
                throw $failure;
            });
        });
        try {
            self::assertSame($failure, $this->capture(static fn () => (new PredbIdentityRebuild)->start($connection)));
            self::assertSame(1, $writes);
            self::assertSame(1, $calls);
            $this->assertClean($connection, $pdo, $manager);
            self::assertSame($committedSnapshot, $this->snapshot($pdo));
            $connection->transaction(static fn (): bool => true);
            self::assertSame(1, $calls);
            self::assertSame($committedSnapshot, $this->snapshot($pdo));
        } finally {
            $this->discardTestContext($connection, $pdo);
        }
    }

    public function test_cleanup_does_not_consume_new_native_transaction_after_rollback_callback_custody_loss(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        DB::statement('CREATE TABLE rebuild_callback_owned_marker (value INTEGER NOT NULL)');
        $before = $this->snapshot($pdo);
        $bodyFailure = new RuntimeException('database is locked');
        $rollbackFailure = new RuntimeException('Synthetic second rollback callback failure.');
        $writes = 0;
        $effects = 0;
        $commitCalls = 0;
        $rollbackCalls = 0;
        $callbackPdoStates = [];
        $testOwnsNative = false;
        $this->dispatcher()->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $pdo, $bodyFailure, $rollbackFailure, &$writes, &$effects, &$commitCalls, &$rollbackCalls, &$callbackPdoStates, &$testOwnsNative): void {
            if (! self::isStateWrite($event, $connection)) {
                return;
            }
            $writes++;
            $connection->afterCommit(static function () use (&$commitCalls): void {
                $commitCalls++;
            });
            $connection->afterRollBack(static function () use ($pdo, &$effects, &$callbackPdoStates, &$testOwnsNative): void {
                $callbackPdoStates[] = $pdo->inTransaction();
                $pdo->beginTransaction();
                $testOwnsNative = true;
                $pdo->exec('INSERT INTO rebuild_callback_owned_marker(value) VALUES (17)');
                $effects++;
            });
            $connection->afterRollBack(static function () use ($rollbackFailure, &$rollbackCalls): void {
                $rollbackCalls++;
                throw $rollbackFailure;
            });
            throw $bodyFailure;
        });
        try {
            $observed = $this->capture(static fn () => (new PredbIdentityRebuild)->start($connection));
            self::assertSame(1, $effects);
            self::assertSame([false], $callbackPdoStates, 'Original native SQL rollback must precede callback-owned transaction.');
            self::assertSame(1, $rollbackCalls);
            self::assertSame(1, $writes);
            self::assertTrue($pdo->inTransaction(), 'Product cleanup cannot roll back the callback-owned native transaction.');
            self::assertSame([['value' => 17]], $pdo->query('SELECT value FROM rebuild_callback_owned_marker')->fetchAll(PDO::FETCH_ASSOC));
            self::assertSame($before, $this->snapshot($pdo));
            self::assertInstanceOf(RuntimeException::class, $observed);
            self::assertStringContainsString('discard this context', $observed->getMessage());
            self::assertSame($bodyFailure, $observed->getPrevious());
            self::assertSame(0, $connection->transactionLevel());
            foreach (['getPdo', 'getReadPdo', 'getDirectPdo'] as $getter) {
                $blocked = $this->capture(static fn () => $connection->{$getter}());
                self::assertInstanceOf(RuntimeException::class, $blocked);
                self::assertStringContainsString('discard this context', $blocked->getMessage());
            }
            self::assertSame(0, $commitCalls);
        } finally {
            if ($testOwnsNative && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->discardTestContext($connection, $pdo);
        }
    }

    public static function retryFailures(): array
    {
        return ['two clean failures then commit' => [2], 'exhaust three attempts' => [3]];
    }

    #[DataProvider('retryFailures')]
    public function test_three_attempt_policy_retries_only_after_clean_owned_context(int $failures): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $manager = $this->manager();
        $name = $connection->getName();
        $before = $this->snapshot($pdo);
        $failure = new RuntimeException('database is locked');
        $writes = 0;
        $commits = 0;
        $callbackAttempts = [];
        $this->dispatcher()->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($connection, $manager, $name, $pdo, &$writes, &$callbackAttempts): void {
            if (! self::isStateWrite($event, $connection)) {
                return;
            }
            $writes++;
            self::assertSame(1, $connection->transactionLevel());
            self::assertTrue($pdo->inTransaction());
            self::assertCount(1, $manager->getPendingTransactions()->filter(static fn ($record): bool => $record->connection === $name));
            self::assertFalse($manager->getCommittedTransactions()->contains(static fn ($record): bool => $record->connection === $name));
            $attempt = $writes;
            $connection->afterCommit(static function () use ($attempt, &$callbackAttempts): void {
                $callbackAttempts[] = $attempt;
            });
        });
        $this->dispatcher()->listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($connection, $failure, $failures, &$commits): void {
            if ($event->connection === $connection && ++$commits <= $failures) {
                throw $failure;
            }
        });
        try {
            $observed = $this->capture(static fn () => (new PredbIdentityRebuild)->start($connection));
            self::assertSame($failures === 3 ? $failure : null, $observed);
            self::assertSame(3, $writes);
            self::assertSame(3, $commits);
            self::assertSame($failures === 3 ? [] : [3], $callbackAttempts);
            $this->assertClean($connection, $pdo, $manager);
            if ($failures === 3) {
                self::assertSame($before, $this->snapshot($pdo));
            } else {
                self::assertNotSame($before['predb_identity_state'][0]['generation'], $this->snapshot($pdo)['predb_identity_state'][0]['generation']);
            }
        } finally {
            $this->discardTestContext($connection, $pdo);
        }
    }

    private static function isStateWrite(QueryExecuted $event, Connection $connection): bool
    {
        return $event->connection === $connection && str_starts_with($event->sql, 'update "predb_identity_state"');
    }

    private function dispatcher(): Dispatcher
    {
        $dispatcher = DB::connection()->getEventDispatcher();
        self::assertInstanceOf(Dispatcher::class, $dispatcher);

        return $dispatcher;
    }

    private function manager(): DatabaseTransactionsManager
    {
        $manager = (new ReflectionProperty(DB::connection(), 'transactionsManager'))->getValue(DB::connection());
        self::assertInstanceOf(DatabaseTransactionsManager::class, $manager);

        return $manager;
    }

    private function assertClean(Connection $connection, PDO $pdo, DatabaseTransactionsManager $manager): void
    {
        self::assertSame($pdo, $connection->getRawPdo());
        self::assertSame($manager, (new ReflectionProperty($connection, 'transactionsManager'))->getValue($connection));
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($pdo->inTransaction());
        $owned = static fn ($record): bool => $record->connection === $connection->getName();
        self::assertFalse($manager->getPendingTransactions()->contains($owned));
        self::assertFalse($manager->getCommittedTransactions()->contains($owned));
    }

    private function snapshot(PDO $pdo): array
    {
        $result = [];
        foreach (['predb' => 'id', 'predb_identity_state' => 'id', 'predb_identity_revisions' => 'predb_id', 'predb_identity_keys' => 'generation,kind,key_digest,predb_id'] as $table => $order) {
            $result[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    private function capture(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function discardTestContext(Connection $connection, PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Test teardown discards uncertain callback records without executing them.
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        $connection->unsetEventDispatcher();
        $connection->setPdo($pdo)->setReadPdo(null)->setDirectPdo(null);
    }
}
