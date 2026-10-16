<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PDOException;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class ReleaseUpdateCommitFailureTest extends TestCase
{
    use Par2RenameTestDatabase;

    public function test_failed_root_commit_cleans_transaction_and_discards_queued_identity_events(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Release-GRP']);
        $release = $this->pendingPar2Release(['searchname' => 'obfuscated']);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $synchronized = [];
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$synchronized): void {
            $synchronized[] = $id;
        }));
        DB::statement('PRAGMA foreign_keys = ON');
        DB::statement('CREATE TABLE transaction_parents (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE transaction_children (parent_id INTEGER REFERENCES transaction_parents(id) DEFERRABLE INITIALLY DEFERRED)');
        DB::unprepared('CREATE TRIGGER fail_release_commit AFTER UPDATE ON releases BEGIN INSERT INTO transaction_children(parent_id) VALUES (123); END');
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $failure = null;
        try {
            try {
                $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
            } catch (PDOException $exception) {
                $failure = $exception;
            }
            self::assertInstanceOf(PDOException::class, $failure);
            self::assertStringContainsString('FOREIGN KEY constraint failed', $failure->getMessage());
            self::assertSame(0, $connection->transactionLevel());
            self::assertFalse($pdo->inTransaction());
            self::assertSame('obfuscated', DB::table('releases')->where('id', $release->id)->value('searchname'));
            self::assertNull($updater->lastCommittedIdentity());
            Event::assertNotDispatched(ReleaseNameFixed::class);
            DB::unprepared('DROP TRIGGER fail_release_commit');
            $fresh = false;
            DB::transaction(static function () use (&$fresh): void {
                DB::afterCommit(static function () use (&$fresh): void {
                    $fresh = true;
                });
            });
            self::assertTrue($fresh);
            self::assertNull($updater->lastCommittedIdentity());
            self::assertSame([], $synchronized);
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app('db.transactions')->rollback($connection->getName(), 0);
        }
    }

    public function test_callback_failure_after_successful_commit_preserves_the_write(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Release-GRP']);
        $release = $this->pendingPar2Release(['searchname' => 'obfuscated']);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {}));
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $callbackFailure = new RuntimeException('Synthetic postcommit failure.');
        $injected = false;
        $writes = 0;
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$injected, &$writes, $callbackFailure, $connection): void {
            if (str_starts_with($query->sql, 'update "releases"')) {
                $writes++;
                if (! $injected) {
                    $injected = true;
                    $connection->afterCommit(static fn () => throw $callbackFailure);
                }
            }
        });
        $failure = null;
        try {
            $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        self::assertSame($callbackFailure, $failure);
        self::assertTrue($injected);
        self::assertSame(1, $writes);
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($connection->getPdo()->inTransaction());
        self::assertSame('Synthetic.Release-GRP', DB::table('releases')->where('id', $release->id)->value('searchname'));
        DB::transaction(static fn (): bool => true);
        self::assertSame('Synthetic.Release-GRP', DB::table('releases')->where('id', $release->id)->value('searchname'));
    }

    public function test_replaced_connection_still_rolls_back_the_captured_owned_pdo(): void
    {
        [$updater, $input] = $this->updaterFixture();
        $connection = DB::connection();
        $captured = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $replacement = new PDO('sqlite::memory:');
        $injected = false;
        $isolated->listen(TransactionCommitting::class, static function () use ($connection, $replacement, &$injected): void {
            $injected = true;
            $connection->setPdo($replacement);
        });
        $failure = null;
        try {
            try {
                $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            self::assertTrue($injected);
            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertInstanceOf(PDOException::class, $failure->getPrevious());
            self::assertFalse($captured->inTransaction());
            self::assertSame('obfuscated', $captured->query('SELECT searchname FROM releases')->fetchColumn());
            self::assertFalse($replacement->inTransaction());
        } finally {
            if ($captured->inTransaction()) {
                $captured->rollBack();
            }
            $connection->setEventDispatcher($dispatcher);
            $connection->setPdo($captured)->setReadPdo(null);
            app('db.transactions')->rollback($connection->getName(), 0);
        }
    }

    public function test_nested_callbacks_cannot_precede_the_root_commit_sentinel(): void
    {
        [$updater, $input] = $this->updaterFixture();
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $injected = false;
        $ran = false;
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($connection, &$injected, &$ran): void {
            if (! $injected && str_starts_with($query->sql, 'update "releases"')) {
                $injected = true;
                $connection->transaction(static function () use ($connection, &$ran): void {
                    $connection->afterCommit(static function () use (&$ran): void {
                        $ran = true;
                        throw new RuntimeException('Synthetic nested callback failure.');
                    });
                });
            }
        });
        $failure = null;
        try {
            $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
        self::assertTrue($injected);
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('PreDB identity cannot own the callback boundary.', $failure->getMessage());
        self::assertFalse($ran);
        self::assertSame('obfuscated', DB::table('releases')->where('id', $input->releases_id)->value('searchname'));
        self::assertFalse($connection->getPdo()->inTransaction());
        self::assertSame(0, $connection->transactionLevel());
    }

    public function test_dirty_attached_manager_is_rejected_without_running_or_discarding_its_callbacks(): void
    {
        [$updater, $input] = $this->updaterFixture();
        $connection = DB::connection();
        $original = app('db.transactions');
        $foreign = new DatabaseTransactionsManager;
        $foreign->begin($connection->getName(), 1);
        $ran = false;
        $foreign->addCallbackForRollback(static function () use (&$ran): void {
            $ran = true;
        });
        $connection->setTransactionManager($foreign);
        $failure = null;
        try {
            try {
                $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertSame('PreDB identity requires a clean callback context.', $failure->getMessage());
            self::assertFalse($ran);
            self::assertSame(1, $foreign->getPendingTransactions()->count());
            self::assertFalse($connection->getPdo()->inTransaction());
            self::assertSame('obfuscated', DB::table('releases')->where('id', $input->releases_id)->value('searchname'));
        } finally {
            $connection->setTransactionManager($original);
            $foreign->rollback($connection->getName(), 0);
        }
    }

    public function test_clean_attached_manager_need_not_equal_the_container_binding(): void
    {
        [$updater, $input] = $this->updaterFixture();
        $connection = DB::connection();
        $original = app('db.transactions');
        $foreign = new DatabaseTransactionsManager;
        $connection->setTransactionManager($foreign);
        try {
            $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
            self::assertSame('Synthetic.Release-GRP', DB::table('releases')->where('id', $input->releases_id)->value('searchname'));
            self::assertNotNull($updater->lastCommittedIdentity());
            self::assertSame(0, $foreign->getPendingTransactions()->count());
            self::assertSame(0, $foreign->getCommittedTransactions()->count());
        } finally {
            $connection->setTransactionManager($original);
        }
    }

    public function test_failed_root_commit_preserves_separate_reader_and_direct_routing(): void
    {
        [$updater, $input] = $this->updaterFixture();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        DB::statement('PRAGMA foreign_keys = ON');
        DB::statement('CREATE TABLE transaction_parents (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE transaction_children (parent_id INTEGER REFERENCES transaction_parents(id) DEFERRABLE INITIALLY DEFERRED)');
        DB::unprepared('CREATE TRIGGER fail_release_commit AFTER UPDATE ON releases BEGIN INSERT INTO transaction_children(parent_id) VALUES (123); END');
        $originalRead = $connection->getRawReadPdo();
        $originalDirect = $connection->getRawDirectPdo();
        $reader = new PDO('sqlite::memory:');
        $direct = new PDO('sqlite::memory:');
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(TransactionCommitting::class, static function () use ($connection, $reader, $direct): void {
            $connection->setReadPdo($reader)->setDirectPdo($direct);
        });
        $failure = null;
        try {
            try {
                $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
            } catch (PDOException $exception) {
                $failure = $exception;
            }
            self::assertInstanceOf(PDOException::class, $failure);
            self::assertFalse($pdo->inTransaction());
            self::assertSame($reader, $connection->getRawReadPdo());
            self::assertSame($direct, $connection->getRawDirectPdo());
            self::assertSame('obfuscated', $pdo->query('SELECT searchname FROM releases')->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->setEventDispatcher($dispatcher);
            $connection->setReadPdo($originalRead)->setDirectPdo($originalDirect);
            app('db.transactions')->rollback($connection->getName(), 0);
        }
    }

    public function test_throwing_rollback_callback_blocks_all_connection_routes_and_preserves_original_failure(): void
    {
        [$updater, $input] = $this->updaterFixture();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        DB::statement('PRAGMA foreign_keys = ON');
        DB::statement('CREATE TABLE transaction_parents (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE transaction_children (parent_id INTEGER REFERENCES transaction_parents(id) DEFERRABLE INITIALLY DEFERRED)');
        DB::unprepared('CREATE TRIGGER fail_release_commit AFTER UPDATE ON releases BEGIN INSERT INTO transaction_children(parent_id) VALUES (123); END');
        $dispatcher = $connection->getEventDispatcher();
        self::assertNotNull($dispatcher);
        $isolated = clone $dispatcher;
        $connection->setEventDispatcher($isolated);
        $reader = new PDO('sqlite::memory:');
        $direct = new PDO('sqlite::memory:');
        $isolated->listen(TransactionCommitting::class, static function () use ($connection, $reader, $direct): void {
            $connection->setReadPdo($reader)->setDirectPdo($direct);
        });
        $injected = false;
        $isolated->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($connection, &$injected): void {
            if (! $injected && str_starts_with($query->sql, 'update "releases"')) {
                $injected = true;
                $connection->afterRollBack(static fn () => throw new RuntimeException('Synthetic rollback callback failure.'));
            }
        });
        $failure = null;
        try {
            try {
                $updater->updateRelease($input, 'Synthetic.Release-GRP', 'Title Match fixture', true, 'PAR2, ', true, false, 7);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            self::assertTrue($injected);
            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertSame('PreDB identity cleanup failed; discard this context.', $failure->getMessage());
            self::assertInstanceOf(PDOException::class, $failure->getPrevious());
            self::assertStringContainsString('FOREIGN KEY constraint failed', $failure->getPrevious()->getMessage());
            self::assertFalse($pdo->inTransaction());
            self::assertSame('obfuscated', $pdo->query('SELECT searchname FROM releases')->fetchColumn());
            foreach (['getPdo', 'getReadPdo', 'getDirectPdo'] as $getter) {
                $blocked = null;
                try {
                    $connection->{$getter}();
                } catch (RuntimeException $exception) {
                    $blocked = $exception;
                }
                self::assertInstanceOf(RuntimeException::class, $blocked);
                self::assertStringContainsString('discard this context', $blocked->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->setEventDispatcher($dispatcher);
            // Test-only teardown discards the deliberately poisoned callback manager.
            $connection->setTransactionManager(new DatabaseTransactionsManager);
            $connection->setPdo($pdo)->setReadPdo(null)->setDirectPdo(null);
        }
    }

    /** @return array{ReleaseUpdateService, object} */
    private function updaterFixture(): array
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::table('predb')->insert(['id' => 7, 'title' => 'Synthetic.Release-GRP']);
        $release = $this->pendingPar2Release(['searchname' => 'obfuscated']);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {}));

        return [$updater, $input];
    }
}
