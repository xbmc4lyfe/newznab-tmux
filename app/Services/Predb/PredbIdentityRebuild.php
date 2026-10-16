<?php

declare(strict_types=1);

namespace App\Services\Predb;

use App\Traits\OwnsPredbIdentityTransactions;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use RuntimeException;

/** Frozen-epoch traversal with a separate explicit installed-schema certification gate. */
final class PredbIdentityRebuild
{
    use OwnsPredbIdentityTransactions;

    public const PREDB_IDENTITY_POLICY = '80ec636f182571cce043ce3197e70a51542321a38d55c194e54d1d0853120e5f';

    /** @return array{generation: string, epoch: int, until_id: int} */
    public function start(Connection $connection): array
    {
        $policy = $this->policy();

        return $this->ownedTransaction($connection, $policy, function () use ($connection, $policy): array {
            $state = $this->state($connection);
            (new PredbIdentitySchemaAttestation)->assertCurrent($connection);
            $epoch = $this->counter($state->epoch, PHP_INT_MAX - 1);
            $until = 0;
            foreach (['predb' => 'id', 'predb_identity_revisions' => 'predb_id'] as $table => $column) {
                if ($connection->table($table)->whereNull($column)->exists()
                    || $connection->table($table)->where($column, '<=', 0)->exists()
                    || $connection->table($table)->where($column, '>', 4294967295)->exists()) {
                    throw new RuntimeException('Unsupported PreDB rebuild owner domain.');
                }
                $until = max($until, $this->counter($connection->table($table)->max($column) ?? 0, 4294967295));
            }
            $generation = bin2hex(random_bytes(16));
            $connection->table('predb_identity_state')->where('id', 1)->update([
                'generation' => $generation, 'ready' => false, 'built_epoch' => null,
                'policy_fingerprint' => $policy, 'rebuild_epoch' => $epoch,
                'rebuild_cursor' => 0, 'rebuild_until_id' => $until,
            ]);

            return ['generation' => $generation, 'epoch' => $epoch, 'until_id' => $until];
        });
    }

    /** @return array{generation: string, cursor: int, processed: int, exhausted: bool} */
    public function chunk(Connection $connection, string $generation, int $limit = 128): array
    {
        if ($limit < 1 || $limit > 256 || preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1) {
            throw new RuntimeException('Unsupported PreDB rebuild page.');
        }
        $policy = $this->policy();

        return $this->ownedTransaction($connection, $policy, function () use ($connection, $generation, $limit, $policy): array {
            $this->assertTraversalColumns($connection);
            [$epoch, $cursor, $until] = $this->activeBuild($connection, $generation, $policy);
            $ids = $this->nextOwners($connection, $cursor, $until, $limit);
            foreach ($ids as $id) {
                $owner = $connection->table('predb_identity_revisions')->where('predb_id', $id)->lockForUpdate()->first();
                if ($owner === null) {
                    if (! $connection->table('predb')->where('id', $id)->exists()) {
                        throw new RuntimeException('PreDB rebuild source owner changed.');
                    }
                    $connection->table('predb_identity_revisions')->insert([
                        'predb_id' => $id, 'revision' => $epoch, 'deleted' => false,
                    ]);
                    $revision = $epoch;
                } else {
                    $revision = $this->counter($owner->revision, $epoch);
                }
                (new PredbIdentityProjector)->replaceOwner($connection, $generation, $id, $revision);
                $cursor = $id;
            }
            $connection->table('predb_identity_state')->where('id', 1)->update(['rebuild_cursor' => $cursor]);

            return ['generation' => $generation, 'cursor' => $cursor, 'processed' => count($ids),
                'exhausted' => $this->nextOwners($connection, $cursor, $until, 1) === []];
        });
    }

    public function certify(Connection $connection, string $generation): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1) {
            throw new RuntimeException('Unsupported PreDB rebuild generation.');
        }
        $policy = $this->policy();
        $this->ownedTransaction($connection, $policy, function () use ($connection, $generation, $policy): void {
            [$epoch, $cursor, $until] = $this->activeBuild($connection, $generation, $policy);
            (new PredbIdentitySchemaAttestation)->assertCurrent($connection);
            if ($this->nextOwners($connection, $cursor, $until, 1) !== []) {
                throw new RuntimeException('PreDB rebuild inventory is incomplete.');
            }
            foreach (['predb' => 'id', 'predb_identity_revisions' => 'predb_id'] as $table => $column) {
                if ($connection->table($table)->whereNull($column)->exists()
                    || $connection->table($table)->where($column, '<=', 0)->exists()
                    || $connection->table($table)->where($column, '>', $until)->exists()) {
                    throw new RuntimeException('PreDB rebuild inventory changed.');
                }
            }
            $connection->table('predb_identity_state')->where('id', 1)->update([
                'ready' => true, 'built_epoch' => $epoch, 'rebuild_epoch' => null,
                'rebuild_cursor' => 0, 'rebuild_until_id' => null,
            ]);
        });
    }

    /** @return array{int, int, int} */
    private function activeBuild(Connection $connection, string $generation, string $policy): array
    {
        $state = $this->state($connection);
        $epoch = $this->counter($state->epoch, PHP_INT_MAX - 1);
        if ($state->generation !== $generation || ! in_array($state->ready, [0, '0', false], true)
            || $state->built_epoch !== null || $state->rebuild_epoch === null
            || $this->counter($state->rebuild_epoch, PHP_INT_MAX - 1) !== $epoch
            || $state->policy_fingerprint !== $policy) {
            throw new RuntimeException('PreDB rebuild generation, epoch or loaded policy changed.');
        }
        $cursor = $this->counter($state->rebuild_cursor, 4294967295);
        $until = $this->counter($state->rebuild_until_id, 4294967295);
        if ($cursor > $until) {
            throw new RuntimeException('PreDB rebuild cursor changed.');
        }

        return [$epoch, $cursor, $until];
    }

    /** @return list<int> */
    private function nextOwners(Connection $connection, int $cursor, int $until, int $limit): array
    {
        if ($connection->getDriverName() === 'mariadb') {
            return $this->mariaNextOwners($connection, $cursor, $until, $limit);
        }
        $ids = [];
        foreach (['predb' => 'id', 'predb_identity_revisions' => 'predb_id'] as $table => $column) {
            foreach ($connection->table($table)->where($column, '>', $cursor)->where($column, '<=', $until)
                ->orderBy($column)->limit($limit)->pluck($column) as $id) {
                $value = $this->counter($id, 4294967295);
                $ids[$value] = $value;
            }
        }
        sort($ids, SORT_NUMERIC);

        return array_slice($ids, 0, $limit);
    }

    private function policy(): string
    {
        $policy = (new PredbIdentityPolicy)->fingerprint();
        if ($policy === null) {
            throw new RuntimeException('PreDB loaded identity policy is unavailable.');
        }

        return $policy;
    }

    private function state(Connection $connection): object
    {
        if ($connection->getDriverName() === 'mariadb') {
            return $this->mariaState($connection);
        }
        $state = $connection->table('predb_identity_state')->where('id', 1)->lockForUpdate()->first();
        if ($state === null) {
            throw new RuntimeException('PreDB rebuild state is unavailable.');
        }

        return $state;
    }

    private function mariaState(Connection $connection): object
    {
        $rows = $connection->select('select id, epoch, built_epoch, ready, rebuild_epoch, rebuild_cursor, rebuild_until_id, '
            .'substring(cast(generation as binary), 1, 33) as generation, '
            .'substring(cast(policy_fingerprint as binary), 1, 65) as policy_fingerprint '
            .'from '.$this->mariaTable($connection, 'predb_identity_state').' limit 2 for update', [], false);
        if (count($rows) !== 1) {
            throw new RuntimeException('PreDB rebuild state is unavailable.');
        }
        $state = $rows[0];
        // Decode only fixed numeric fields. Never cast a whole row or collapse SQL NULL.
        foreach (['id' => 1, 'epoch' => PHP_INT_MAX - 1, 'built_epoch' => PHP_INT_MAX - 1,
            'ready' => 1, 'rebuild_epoch' => PHP_INT_MAX - 1, 'rebuild_cursor' => 4294967295,
            'rebuild_until_id' => 4294967295] as $field => $maximum) {
            if (in_array($field, ['built_epoch', 'rebuild_epoch', 'rebuild_until_id'], true) && $state->{$field} === null) {
                continue;
            }
            $state->{$field} = $this->counter($state->{$field}, $maximum);
        }
        if ($state->id !== 1 || ! is_string($state->generation)
            || preg_match('/\A[0-9a-f]{32}\z/D', $state->generation) !== 1
            || ($state->policy_fingerprint !== null && (! is_string($state->policy_fingerprint)
                || preg_match('/\A[0-9a-f]{64}\z/D', $state->policy_fingerprint) !== 1))) {
            throw new RuntimeException('Unsupported PreDB rebuild state.');
        }

        return $state;
    }

    /** @return list<int> */
    private function mariaNextOwners(Connection $connection, int $cursor, int $until, int $limit): array
    {
        $ids = [];
        foreach (['predb' => 'id', 'predb_identity_revisions' => 'predb_id'] as $table => $column) {
            $rows = $connection->select('select '.$column.' as owner_id from '.$this->mariaTable($connection, $table)
                .' where '.$column.' > ? and '.$column.' <= ? order by '.$column.' limit '.$limit, [$cursor, $until], false);
            if (count($rows) > $limit) {
                throw new RuntimeException('PreDB rebuild owner page exceeds its bound.');
            }
            foreach ($rows as $row) {
                $id = $this->counter($row->owner_id, 4294967295);
                if ($id < 1 || $id <= $cursor || $id > $until) {
                    throw new RuntimeException('Unsupported PreDB rebuild owner domain.');
                }
                $ids[$id] = $id;
            }
        }
        sort($ids, SORT_NUMERIC);

        return array_slice($ids, 0, $limit);
    }

    private function mariaTable(Connection $connection, string $table): string
    {
        $database = $connection->getDatabaseName();
        if ($database === '' || strlen($database) > 256 || preg_match('/[\x00-\x1f\x7f]/', $database) !== 0) {
            throw new RuntimeException('Unavailable PreDB rebuild database.');
        }

        return '`'.str_replace('`', '``', $database).'`.`'.$table.'`';
    }

    /** @template T
     * @param  callable(): T  $operation
     * @return T
     */
    private function ownedTransaction(Connection $connection, string $policy, callable $operation): mixed
    {
        if (PHP_INT_SIZE !== 8 || ! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)
            || $connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('PreDB rebuild requires its own supported transaction.');
        }

        return $this->runOwnedPredbTransaction($connection, function () use ($connection, $operation, $policy) {
            if ($connection->getDriverName() === 'mariadb') {
                if ($connection->getTablePrefix() !== '') {
                    throw new RuntimeException('Unsupported MariaDB PreDB rebuild table prefix.');
                }
                // Attestation owns bounded S/table opens before typed raw state reads.
                // Native MDL and exact metadata/profile qualification remain required.
                (new PredbIdentitySchemaAttestation)->assertCurrent($connection);
            }
            $result = $operation();
            if ($connection->getDriverName() === 'mariadb') {
                (new PredbIdentitySchemaAttestation)->assertCurrent($connection);
            }
            if ($this->policy() !== $policy) {
                throw new RuntimeException('PreDB rebuild loaded policy changed during the transaction.');
            }

            return $result;
        }, 3);
    }

    private function counter(mixed $value, int $maximum): int
    {
        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value) !== 1) {
            throw new RuntimeException('Unsupported PreDB rebuild counter.');
        }
        $digits = (string) $value;
        $bound = (string) $maximum;
        if (strlen($digits) > strlen($bound) || (strlen($digits) === strlen($bound) && strcmp($digits, $bound) > 0)) {
            throw new RuntimeException('PreDB rebuild counter exceeds its bound.');
        }

        return (int) $value;
    }

    private function assertTraversalColumns(Connection $connection): void
    {
        if ($connection->getDriverName() === 'mariadb') {
            // The complete Maria contract attests both ordinary NOT NULL unsigned
            // owner columns and their single-column primary keys, plus temp shadows.
            // It remains HOLD until raw metadata/account/MDL qualification is complete.
            (new PredbIdentitySchemaAttestation)->assertCurrent($connection);

            return;
        }
        // Paging must never coerce a TEXT/generated owner column into an incomplete integer inventory.
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported PreDB rebuild traversal metadata driver.');
        }
        foreach (['predb' => 'id', 'predb_identity_revisions' => 'predb_id'] as $table => $name) {
            $columns = $connection->select('SELECT name,type,pk,hidden FROM pragma_table_xinfo(?, ?) LIMIT 129', [$table, 'main']);
            $owners = array_values(array_filter($columns, static fn (object $column): bool => $column->name === $name));
            $primary = array_values(array_filter($columns, static fn (object $column): bool => (int) $column->pk !== 0));
            if (count($columns) > 128 || count($owners) !== 1 || ! is_string($owners[0]->type)
                || strtolower(trim($owners[0]->type)) !== 'integer' || (int) $owners[0]->hidden !== 0
                || count($primary) !== 1 || (int) $owners[0]->pk !== 1) {
                throw new RuntimeException('Unsupported PreDB rebuild owner column.');
            }
        }
        if ($connection->table('sqlite_temp_master')->where(static function ($query): void {
            $tables = ['predb', 'predb_identity_state', 'predb_identity_revisions', 'predb_identity_keys'];
            $query->whereIn(new Expression('name COLLATE NOCASE'), [...$tables, ...PredbIdentitySchema::TRIGGERS])
                ->orWhere(static function ($query) use ($tables): void {
                    $query->where('type', 'trigger')->whereIn(new Expression('tbl_name COLLATE NOCASE'), $tables);
                });
        })->exists()) {
            throw new RuntimeException('Temporary objects shadow the PreDB rebuild schema.');
        }
    }
}
