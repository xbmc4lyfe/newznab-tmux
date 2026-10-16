<?php

declare(strict_types=1);

namespace App\Services\Predb;

use InvalidArgumentException;

/** Invalidation only: SQL triggers cannot compute the PHP literal identity grammar. */
final class PredbIdentitySchema
{
    public const PREDB_IDENTITY_POLICY = 'b248c5796e5e541279cc4f86ced033965ee5f555ab8344f92fd1eae94638cc55';

    public const TRIGGERS = ['predb_identity_insert', 'predb_identity_update', 'predb_identity_delete'];

    /** @return array<string, string> */
    public function definitions(string $driver): array
    {
        $definitions = [];
        foreach ($this->specifications($driver) as $name => $specification) {
            $definitions[$name] = 'CREATE TRIGGER '.$name.' '.$specification['timing'].' '.$specification['event']
                .' ON '.$specification['table'].' '.($driver === 'sqlite'
                    ? ($specification['when'] === null ? '' : 'WHEN '.$specification['when'].' ')
                    : 'FOR EACH ROW ').$specification['action_statement'];
        }

        return $definitions;
    }

    /** @return array<string, array{name: string, table: string, timing: string, event: string, orientation: string, when: string|null, action_statement: string}> */
    public function specifications(string $driver): array
    {
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Unsupported PreDB identity schema driver');
        }
        $sqlite = $driver === 'sqlite';
        $limit = (string) (PHP_INT_MAX - 1);
        $fence = $sqlite
            ? "SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM predb_identity_state WHERE id=1) THEN RAISE(ABORT, 'PreDB identity state unavailable') END;\n"
                ."UPDATE predb_identity_state SET epoch=epoch+1, ready=0 WHERE id=1 AND epoch<{$limit};\n"
                ."SELECT CASE WHEN changes()<>1 THEN RAISE(ABORT, 'PreDB identity epoch exhausted') END;\n"
            : "IF NOT EXISTS (SELECT 1 FROM predb_identity_state WHERE id=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity state unavailable'; END IF;\n"
                ."UPDATE predb_identity_state SET epoch=epoch+1, ready=0 WHERE id=1 AND epoch<{$limit};\n"
                ."IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PreDB identity epoch exhausted'; END IF;\n";
        $record = static function (string $id, bool $deleted) use ($sqlite): string {
            $flag = $deleted ? '1' : '0';
            $query = "INSERT INTO predb_identity_revisions (predb_id, revision, deleted, projected_revision, projected_generation) SELECT {$id}, epoch, {$flag}, NULL, NULL FROM predb_identity_state WHERE id=1 ";

            return $query.($sqlite
                ? 'ON CONFLICT(predb_id) DO UPDATE SET revision=excluded.revision, deleted=excluded.deleted, projected_revision=NULL, projected_generation=NULL;'
                : 'ON DUPLICATE KEY UPDATE revision=VALUES(revision), deleted=VALUES(deleted), projected_revision=NULL, projected_generation=NULL;')."\n";
        };
        $changed = $sqlite
            ? 'OLD.id IS NOT NEW.id OR (OLD.title COLLATE BINARY) IS NOT (NEW.title COLLATE BINARY) OR (OLD.filename COLLATE BINARY) IS NOT (NEW.filename COLLATE BINARY)'
            : 'NOT (OLD.id <=> NEW.id) OR NOT (CAST(OLD.title AS BINARY) <=> CAST(NEW.title AS BINARY)) OR NOT (CAST(OLD.filename AS BINARY) <=> CAST(NEW.filename AS BINARY))';
        $updateBody = $fence;
        if ($sqlite) {
            // Recording the old ID as deleted before the new row also handles ID reuse.
            $updateBody .= $record('OLD.id', true).$record('NEW.id', false);
        } else {
            $updateBody .= 'IF NOT (OLD.id <=> NEW.id) THEN '.$record('OLD.id', true).'END IF; '.$record('NEW.id', false);
        }

        $bodies = [
            'insert' => 'BEGIN '.$fence.$record('NEW.id', false).'END',
            'update' => $sqlite ? 'BEGIN '.$updateBody.'END' : 'BEGIN IF '.$changed.' THEN '.$updateBody.'END IF; END',
            'delete' => 'BEGIN '.$fence.$record('OLD.id', true).'END',
        ];
        $specifications = [];
        foreach ($bodies as $event => $body) {
            $name = 'predb_identity_'.$event;
            $specifications[$name] = ['name' => $name, 'table' => 'predb', 'timing' => 'AFTER',
                'event' => strtoupper($event), 'orientation' => 'ROW',
                'when' => $sqlite && $event === 'update' ? $changed : null, 'action_statement' => $body];
        }

        return $specifications;
    }
}
