<?php

declare(strict_types=1);

namespace App\Services\Predb;

use Illuminate\Database\Connection;
use RuntimeException;

/** Owner replacement only. The owning transaction must fence state before source mutations. */
final class PredbIdentityProjector
{
    public const PREDB_IDENTITY_POLICY = '02519d1907a8232fded70a917dca4a53ebe15e9390ee6c4e307cd9f96abb9b0c';

    public function replaceOwner(Connection $connection, string $generation, int $id, int $revision): void
    {
        if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1
            || $id < 1 || $id > 4294967295 || $revision < 0 || $revision >= PHP_INT_MAX) {
            throw new RuntimeException('Unsupported PreDB owner projection context.');
        }
        $grammar = $connection->getQueryGrammar();
        $table = static fn (string $name): string => $grammar->wrapTable($name);
        $column = static fn (string $name): string => $grammar->wrap($name);
        $lock = match ($connection->getDriverName()) {
            'sqlite' => '',
            'mysql', 'mariadb' => ' for update',
            default => throw new RuntimeException('Unsupported PreDB projector driver.'),
        };
        $state = $connection->selectOne('select * from '.$table('predb_identity_state').' where '.$column('id').' = ? limit 1'.$lock, [1], false);
        if ($state === null || $state->generation !== $generation) {
            throw new RuntimeException('PreDB projection generation changed.');
        }
        $source = $connection->selectOne('select '.implode(', ', array_map($column, ['id', 'title', 'filename'])).' from '.$table('predb').' where '.$column('id').' = ? limit 1'.$lock, [$id], false);
        $owner = $connection->selectOne('select * from '.$table('predb_identity_revisions').' where '.$column('predb_id').' = ? limit 1'.$lock, [$id], false);
        if ($owner === null || (string) $owner->revision !== (string) $revision
            || ! in_array($owner->deleted, [0, 1, '0', '1', false, true], true)
            || ($source === null) !== (bool) $owner->deleted) {
            throw new RuntimeException('PreDB owner revision or presence changed.');
        }
        $keys = $source === null ? [] : (new PredbIdentityKeys)->forRow($source);
        if ($keys === null) {
            throw new RuntimeException('PreDB owner identity cannot be projected completely.');
        }
        // Compute and validate everything before replacing any previously retained keys.
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = ['generation' => $generation, 'kind' => $key['kind'],
                'key_digest' => $key['key_digest'], 'predb_id' => $id, 'source_revision' => $revision];
        }
        $connection->delete('delete from '.$table('predb_identity_keys').' where '.$column('generation').' = ? and '.$column('predb_id').' = ?', [$generation, $id]);
        if ($rows !== []) {
            $columns = ['generation', 'key_digest', 'kind', 'predb_id', 'source_revision'];
            $bindings = [];
            foreach ($rows as $row) {
                foreach ($columns as $name) {
                    $bindings[] = $row[$name];
                }
            }
            $connection->insert('insert into '.$table('predb_identity_keys').' ('.implode(', ', array_map($column, $columns)).') values '.implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?)')), $bindings);
        }
        $connection->update('update '.$table('predb_identity_revisions').' set '.$column('projected_revision').' = ?, '.$column('projected_generation').' = ? where '.$column('predb_id').' = ? and '.$column('revision').' = ? and '.$column('deleted').' = ?', [$revision, $generation, $id, $revision, (bool) $owner->deleted]);
        $projected = $connection->selectOne('select * from '.$table('predb_identity_revisions').' where '.$column('predb_id').' = ? limit 1', [$id]);
        if ($projected === null || (string) $projected->revision !== (string) $revision
            || (string) $projected->projected_revision !== (string) $revision
            || $projected->projected_generation !== $generation) {
            throw new RuntimeException('PreDB owner projection marker was not persisted.');
        }
        // Never change readiness, epochs, source identity, another owner or an old generation here.
    }
}
