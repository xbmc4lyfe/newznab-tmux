<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\DetectsConcurrencyErrors;
use PDO;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** Root transaction custody shared privately by fixed PreDB operations. */
trait OwnsPredbIdentityTransactions
{
    use DetectsConcurrencyErrors;

    public const PREDB_OWNED_TRANSACTION_POLICY = '278d54bdafba217b2070c93c6d9764a1fd643516bab6ef2b2de23c604d1c258d';

    /**
     * Root-owned cleanup only; existing caller-owned transactions retain their owner.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $write
     * @return TResult
     */
    private function runOwnedPredbTransaction(Connection $connection, callable $write, int $attempts = 1): mixed
    {
        if ($connection->transactionLevel() > 0) {
            return $connection->transaction(static fn () => $write());
        }
        for ($attempt = 1; ; $attempt++) {
            $pdo = $connection->getPdo();
            $name = $connection->getName();
            $manager = $this->predbAttachedTransactionManager($connection);
            if ($pdo->inTransaction() || ! is_string($name)) {
                throw new RuntimeException('PreDB identity requires a clean transaction context.');
            }
            $forConnection = static fn (DatabaseTransactionRecord $record): bool => $record->connection === $name;
            if ($manager->getPendingTransactions()->contains($forConnection)
                || $manager->getCommittedTransactions()->contains($forConnection)) {
                throw new RuntimeException('PreDB identity requires a clean callback context.');
            }
            $commitState = (object) ['committed' => false, 'bodyFailure' => null];
            $sentinel = static function () use ($commitState): void {
                $commitState->committed = true;
            };
            try {
                return $connection->transaction(function () use ($connection, $manager, $forConnection, $sentinel, $write, $pdo, $commitState) {
                    try {
                        $records = $manager->getPendingTransactions()->filter($forConnection)->values();
                        if ($records->count() !== 1 || $records[0]->level !== 1 || $records[0]->getCallbacks() !== []) {
                            throw new RuntimeException('PreDB identity cannot own the callback boundary.');
                        }
                        $connection->afterCommit($sentinel);
                        if ($this->predbCallbackSnapshot($records[0]) !== [$sentinel]) {
                            throw new RuntimeException('PreDB identity callback manager changed.');
                        }

                        $result = $write();
                        if ($connection->getRawPdo() !== $pdo || ! $pdo->inTransaction()
                            || $connection->transactionLevel() !== 1
                            || $this->predbAttachedTransactionManager($connection) !== $manager) {
                            throw new RuntimeException('PreDB identity connection ownership changed.');
                        }
                        $pending = $manager->getPendingTransactions()->filter($forConnection)->values();
                        if ($pending->count() !== 1 || $pending[0] !== $records[0]
                            || ($this->predbCallbackSnapshot($pending[0])[0] ?? null) !== $sentinel) {
                            throw new RuntimeException('PreDB identity cannot own the callback boundary.');
                        }
                        foreach ($manager->getCommittedTransactions()->filter($forConnection) as $child) {
                            if ($this->predbCallbackSnapshot($child) !== []) {
                                throw new RuntimeException('PreDB identity cannot own the callback boundary.');
                            }
                        }

                        return $result;
                    } catch (Throwable $bodyFailure) {
                        $commitState->bodyFailure = $bodyFailure;
                        throw $bodyFailure;
                    }
                }, 1);
            } catch (Throwable $failure) {
                if ($commitState->committed) {
                    // The SQL committed; callback failure must never retry or compensate that write.
                    throw $failure;
                }
                $failure = $commitState->bodyFailure ?? $failure;
                try {
                    $this->restorePredbTransactionContext($connection, $pdo, $manager, $name, $commitState->bodyFailure !== null);
                } catch (Throwable) {
                    $unusable = static fn () => throw new RuntimeException('PreDB identity connection cleanup failed; discard this context.');
                    $connection->setPdo($unusable)->setReadPdo($unusable)->setDirectPdo($unusable);
                    throw new RuntimeException('PreDB identity cleanup failed; discard this context.', 0, $failure);
                }

                if ($attempt < $attempts && $this->causedByConcurrencyError($failure)) {
                    continue;
                }

                throw $failure;
            }
        }
    }

    /**
     * Callback registration mutates the attached transaction record outside its public reader.
     *
     * @phpstan-impure
     *
     * @return array<callable>
     */
    private function predbCallbackSnapshot(DatabaseTransactionRecord $record): array
    {
        return $record->getCallbacks();
    }

    private function restorePredbTransactionContext(Connection $connection, PDO $pdo, DatabaseTransactionsManager $manager, string $name, bool $bodyFailed): void
    {
        $forConnection = static fn (DatabaseTransactionRecord $record): bool => $record->connection === $name;
        $records = $manager->getPendingTransactions()->filter($forConnection)
            ->merge($manager->getCommittedTransactions()->filter($forConnection));
        $pdoActive = $pdo->inTransaction();
        $uncertainCallbacks = ! $pdoActive && $records->isNotEmpty();
        if ($bodyFailed) {
            foreach ($records as $record) {
                if ($record->getCallbacksForRollback() !== []) {
                    $uncertainCallbacks = true;
                    break;
                }
            }
        }
        if ($uncertainCallbacks) {
            // A rollback callback may already have run or started a different transaction.
            throw new RuntimeException('PreDB identity rollback callback progress is uncertain.');
        }
        // The captured writer belongs to this operation even if the Connection was replaced.
        if ($pdoActive) {
            $pdo->rollBack();
        }
        $manager->rollback($name, 0);
        if ($connection->getRawPdo() !== $pdo || $connection->transactionLevel() !== 0
            || $this->predbAttachedTransactionManager($connection) !== $manager) {
            throw new RuntimeException('PreDB identity connection ownership changed.');
        }
        if ($pdo->inTransaction()
            || $manager->getPendingTransactions()->contains($forConnection)
            || $manager->getCommittedTransactions()->contains($forConnection)) {
            throw new RuntimeException('PreDB identity cleanup did not finish.');
        }
    }

    /** Installed Laravel has no public reader for the manager attached to a Connection. */
    private function predbAttachedTransactionManager(Connection $connection): DatabaseTransactionsManager
    {
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        if (! $manager instanceof DatabaseTransactionsManager) {
            throw new RuntimeException('Unsupported PreDB identity callback manager.');
        }

        return $manager;
    }
}
