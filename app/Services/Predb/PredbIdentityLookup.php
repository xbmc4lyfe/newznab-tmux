<?php

declare(strict_types=1);

namespace App\Services\Predb;

use App\Services\NameFixing\OriginalFilenameEvidence;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/** Certified nominations only; callers must repeat authority inside their write transaction. */
final class PredbIdentityLookup
{
    public const PREDB_IDENTITY_POLICY = 'be9783e8f74fb150e8ac026766da7e9f10d2ac63ed49160b1e9c4aa9857d443d';

    /** @return array{status: string, candidate?: array{id: int, title: string, filename: string}, tier?: string, witness?: array<string, mixed>} */
    public function lookup(Connection $connection, OriginalFilenameEvidence $evidence): array
    {
        return $this->lookupMany($connection, [$evidence])[0];
    }

    /**
     * One authority envelope for an ordered pass; no result escapes before its final fence.
     *
     * @param  array<array-key, mixed>  $evidences
     * @return list<array{status: string, candidate?: array{id: int, title: string, filename: string}, tier?: string, witness?: array<string, mixed>}>
     */
    public function lookupMany(Connection $connection, array $evidences): array
    {
        $unknown = array_fill(0, count($evidences), ['status' => 'unknown']);
        if ($evidences === []) {
            return [];
        }
        try {
            if (! array_is_list($evidences) || PHP_INT_SIZE !== 8 || ! in_array($connection->getDriverName(), ['sqlite', 'mariadb'], true) || $connection->getTablePrefix() !== '') {
                return $unknown;
            }
            $digests = [];
            foreach ($evidences as $evidence) {
                if (! $evidence instanceof OriginalFilenameEvidence) {
                    return $unknown;
                }
                $keys = $evidence->canonicalNominationKeys();
                if ($keys === null) {
                    return $unknown;
                }
                $digests[] = array_values(array_unique(array_map(static fn (string $key): string => hash('sha256', $key), $keys)));
            }
            /** @var list<OriginalFilenameEvidence> $evidences */
            $read = fn (): array => $this->observeMany($connection, $evidences, $digests);
            if ($connection->transactionLevel() > 0) {
                if (! $connection->getPdo()->inTransaction()) {
                    return $unknown;
                }

                return $read();
            }
            if ($connection->getPdo()->inTransaction()) {
                return $unknown;
            }

            return $connection->transaction($read);
        } catch (Throwable) {
            // Missing/incompatible schema, failed reads and incomplete evidence never establish absence.
            return $unknown;
        }
    }

    /**
     * @param  list<OriginalFilenameEvidence>  $evidences
     * @param  list<list<string>>  $digests
     * @return list<array{status: string, candidate?: array{id: int, title: string, filename: string}, tier?: string, witness?: array<string, mixed>}>
     */
    private function observeMany(Connection $connection, array $evidences, array $digests): array
    {
        $policy = (new PredbIdentityPolicy)->fingerprint();
        if ($policy === null) {
            throw new RuntimeException('Unavailable identity policy.');
        }
        $attestation = new PredbIdentitySchemaAttestation;
        // Maria attestation owns bounded S/table opens before any typed raw state read.
        $attestation->assertCurrent($connection);
        $state = $this->state($connection, $policy);
        $results = [];
        foreach ($evidences as $index => $evidence) {
            $results[] = $this->resolveObservation($connection, $evidence, $digests[$index], $state);
        }
        $attestation->assertCurrent($connection);
        $finalState = $this->state($connection, $policy);
        if ($finalState !== $state || (new PredbIdentityPolicy)->fingerprint() !== $policy) {
            throw new RuntimeException('Identity authority changed.');
        }

        return $results;
    }

    /**
     * @param  list<string>  $digests
     * @param  array{generation: string, epoch: int, policy: string}  $state
     * @return array{status: string, candidate?: array{id: int, title: string, filename: string}, tier?: string, witness?: array<string, mixed>}
     */
    private function resolveObservation(Connection $connection, OriginalFilenameEvidence $evidence, array $digests, array $state): array
    {
        $accepted = [];
        $tier = 'canonical';
        foreach ([1, 2] as $kind) {
            $accepted = $this->nominees($connection, $evidence, $digests, $state, $kind);
            if ($accepted !== []) {
                $tier = $kind === 1 ? 'canonical' : 'alias';
                break;
            }
        }
        if ($accepted === []) {
            return ['status' => 'absent'];
        }
        if (count($accepted) !== 1) {
            return ['status' => 'ambiguous'];
        }
        $row = array_values($accepted)[0];

        return ['status' => 'unique', 'candidate' => ['id' => $row['id'], 'title' => $row['title'], 'filename' => $row['filename']],
            'tier' => $tier, 'witness' => [...$state, 'id' => $row['id'], 'revision' => $row['revision'], 'title' => $row['title']]];
    }

    /** @return array{generation: string, epoch: int, policy: string} */
    private function state(Connection $connection, string $policy): array
    {
        if ($connection->getDriverName() === 'mariadb') {
            return $this->mariaState($connection, $policy);
        }
        $rows = $connection->select('select '.implode(', ', [
            $this->integerProjection('id'), $this->integerProjection('epoch'), $this->integerProjection('built_epoch'),
            $this->integerProjection('ready'), $this->integerProjection('rebuild_epoch'), 'typeof(rebuild_epoch) as rebuild_epoch_type',
            $this->integerProjection('rebuild_cursor'), $this->integerProjection('rebuild_until_id'), 'typeof(rebuild_until_id) as rebuild_until_type',
            $this->textProjection('generation', 33), $this->textProjection('policy_fingerprint', 65),
        ]).' from main.predb_identity_state limit 2', [], false);
        if (count($rows) !== 1) {
            throw new RuntimeException('Unavailable identity state.');
        }
        $row = $rows[0];
        $epoch = $this->counter($row->epoch);
        if ((string) $row->id !== '1' || ! in_array($row->ready, [1, '1', true], true)
            || $this->counter($row->built_epoch) !== $epoch
            || ! is_string($row->generation) || preg_match('/\A[0-9a-f]{32}\z/D', $row->generation) !== 1
            || $row->policy_fingerprint !== $policy || $row->rebuild_epoch !== null
            || $row->rebuild_until_id !== null || $row->rebuild_epoch_type !== 'null' || $row->rebuild_until_type !== 'null'
            || $this->counter($row->rebuild_cursor) !== 0) {
            throw new RuntimeException('Uncertified identity state.');
        }

        return ['generation' => $row->generation, 'epoch' => $epoch, 'policy' => $policy];
    }

    /** @param list<string> $digests
     * @param  array{generation: string, epoch: int, policy: string}  $state
     * @return array<int, array{id: int, title: string, filename: string, revision: int}>
     */
    private function nominees(Connection $connection, OriginalFilenameEvidence $evidence, array $digests, array $state, int $kind): array
    {
        if ($digests === []) {
            return [];
        }
        if ($connection->getDriverName() === 'mariadb') {
            return $this->mariaNominees($connection, $evidence, $digests, $state, $kind);
        }
        $sql = 'select '.$this->integerProjection('k.predb_id', 'predb_id').', k.key_digest, '
            .$this->integerProjection('k.source_revision', 'source_revision').', p.id as source_id, '
            .'typeof(p.title) as title_type, length(cast(p.title as blob)) as title_bytes, '
            ."case when length(cast(p.title as blob)) = 0 then '' else substr(cast(p.title as blob), 1, 1021) end as title, "
            .'typeof(p.filename) as filename_type, length(cast(p.filename as blob)) as filename_bytes, '
            ."case when length(cast(p.filename as blob)) = 0 then '' else substr(cast(p.filename as blob), 1, 1021) end as filename, "
            .implode(', ', [$this->integerProjection('r.revision', 'revision'), $this->integerProjection('r.deleted', 'deleted'),
                $this->integerProjection('r.projected_revision', 'projected_revision'), $this->textProjection('r.projected_generation', 33, 'projected_generation')]).' '
            .'from main.predb_identity_keys k left join main.predb p on p.id = k.predb_id '
            .'left join main.predb_identity_revisions r on r.predb_id = k.predb_id '
            .'where k.generation = ? and k.kind = ? and k.key_digest in ('.implode(', ', array_fill(0, count($digests), '?')).') '
            .'order by k.key_digest, k.predb_id limit 1000';
        $rows = $connection->select($sql, [$state['generation'], $kind, ...$digests], false);
        if (count($rows) >= 1000) {
            throw new RuntimeException('Incomplete identity nominations.');
        }
        $accepted = [];
        foreach ($rows as $row) {
            $id = $this->counter($row->predb_id);
            $revision = $this->counter($row->revision);
            if ($id < 1 || $id > 4294967295 || $this->counter($row->source_id) !== $id
                || $revision > $state['epoch'] || $this->counter($row->source_revision) !== $revision
                || $this->counter($row->projected_revision) !== $revision || ! in_array($row->deleted, [0, '0', false], true)
                || $row->projected_generation !== $state['generation']
                || $row->title_type !== 'text' || $row->filename_type !== 'text'
                || $this->counter($row->title_bytes) > 1020 || $this->counter($row->filename_bytes) > 1020
                || ! is_string($row->title) || ! is_string($row->filename)) {
                throw new RuntimeException('Stale identity nomination.');
            }
            $keys = (new PredbIdentityKeys)->forRow((object) ['id' => $id, 'title' => $row->title, 'filename' => $row->filename]);
            if ($keys === null || ! in_array(['kind' => $kind, 'key_digest' => $row->key_digest], $keys, true)) {
                throw new RuntimeException('Invalid identity key.');
            }
            if ($evidence->accepts($row->title, $kind === 1 ? '' : $row->filename)) {
                $accepted[$id] = ['id' => $id, 'title' => $row->title, 'filename' => $row->filename, 'revision' => $revision];
            }
        }

        return $accepted;
    }

    /** @return array{generation: string, epoch: int, policy: string} */
    private function mariaState(Connection $connection, string $policy): array
    {
        // Do not hide extra singleton rows with WHERE id = 1. The current read owns S
        // until the existing root/caller transaction ends; every read uses the writer PDO.
        $rows = $connection->select('select id, epoch, built_epoch, ready, rebuild_epoch, rebuild_cursor, rebuild_until_id, '
            .'substring(cast(generation as binary), 1, 33) as generation, '
            .'substring(cast(policy_fingerprint as binary), 1, 65) as policy_fingerprint '
            .'from '.$this->mariaTable($connection, 'predb_identity_state').' limit 2 for update', [], false);
        if (count($rows) !== 1) {
            throw new RuntimeException('Unavailable identity state.');
        }
        $row = $rows[0];
        $epoch = $this->counter($row->epoch);
        if ($this->counter($row->id) !== 1 || $this->counter($row->ready) !== 1
            || $this->counter($row->built_epoch) !== $epoch
            || ! is_string($row->generation) || preg_match('/\A[0-9a-f]{32}\z/D', $row->generation) !== 1
            || $row->policy_fingerprint !== $policy || $row->rebuild_epoch !== null
            || $row->rebuild_until_id !== null || $this->counter($row->rebuild_cursor) !== 0) {
            throw new RuntimeException('Uncertified identity state.');
        }

        return ['generation' => $row->generation, 'epoch' => $epoch, 'policy' => $policy];
    }

    /**
     * @param  list<string>  $digests
     * @param  array{generation: string, epoch: int, policy: string}  $state
     * @return array<int, array{id: int, title: string, filename: string, revision: int}>
     */
    private function mariaNominees(Connection $connection, OriginalFilenameEvidence $evidence, array $digests, array $state, int $kind): array
    {
        // SQL collation only nominates. Raw binary bytes, typed columns, nulls and
        // complete decimal counters are verified without coercive SQL numeric casts.
        $sql = 'select k.predb_id, substring(cast(k.generation as binary), 1, 33) as key_generation, '
            .'substring(cast(k.key_digest as binary), 1, 65) as key_digest, k.source_revision, p.id as source_id, '
            .'octet_length(p.title) as title_bytes, substring(cast(p.title as binary), 1, 1021) as title, '
            .'octet_length(p.filename) as filename_bytes, substring(cast(p.filename as binary), 1, 1021) as filename, '
            .'r.revision, r.deleted, r.projected_revision, '
            .'substring(cast(r.projected_generation as binary), 1, 33) as projected_generation '
            .'from '.$this->mariaTable($connection, 'predb_identity_keys').' k '
            .'left join '.$this->mariaTable($connection, 'predb').' p on p.id = k.predb_id '
            .'left join '.$this->mariaTable($connection, 'predb_identity_revisions').' r on r.predb_id = k.predb_id '
            .'where k.generation = ? and k.kind = ? and k.key_digest in ('.implode(', ', array_fill(0, count($digests), '?')).') '
            .'order by k.key_digest, k.predb_id limit 1000';
        $rows = $connection->select($sql, [$state['generation'], $kind, ...$digests], false);
        if (count($rows) >= 1000) {
            throw new RuntimeException('Incomplete identity nominations.');
        }
        $accepted = [];
        foreach ($rows as $row) {
            $id = $this->counter($row->predb_id);
            $revision = $this->counter($row->revision);
            if ($id < 1 || $id > 4294967295 || $this->counter($row->source_id) !== $id
                || $revision > $state['epoch'] || $this->counter($row->source_revision) !== $revision
                || $this->counter($row->projected_revision) !== $revision || $this->counter($row->deleted) !== 0
                || $row->key_generation !== $state['generation'] || $row->projected_generation !== $state['generation']
                || ! is_string($row->key_digest) || preg_match('/\A[0-9a-f]{64}\z/D', $row->key_digest) !== 1
                || ! is_string($row->title) || ! is_string($row->filename)
                || $this->counter($row->title_bytes) > 1020 || $this->counter($row->filename_bytes) > 1020
                || strlen($row->title) !== $this->counter($row->title_bytes)
                || strlen($row->filename) !== $this->counter($row->filename_bytes)) {
                throw new RuntimeException('Stale identity nomination.');
            }
            $keys = (new PredbIdentityKeys)->forRow((object) ['id' => $id, 'title' => $row->title, 'filename' => $row->filename]);
            if ($keys === null || ! in_array(['kind' => $kind, 'key_digest' => $row->key_digest], $keys, true)) {
                throw new RuntimeException('Invalid identity key.');
            }
            if ($evidence->accepts($row->title, $kind === 1 ? '' : $row->filename)) {
                $accepted[$id] = ['id' => $id, 'title' => $row->title, 'filename' => $row->filename, 'revision' => $revision];
            }
        }

        return $accepted;
    }

    private function mariaTable(Connection $connection, string $table): string
    {
        // The table names above are fixed. Quote the configured schema as one literal
        // identifier, including any dots/backticks, rather than grammar alias parsing.
        $database = $connection->getDatabaseName();
        if ($database === '' || strlen($database) > 256 || preg_match('/[\x00-\x1f\x7f]/', $database) !== 0) {
            throw new RuntimeException('Unavailable identity database.');
        }

        return '`'.str_replace('`', '``', $database).'`.`'.$table.'`';
    }

    private function integerProjection(string $column, ?string $alias = null): string
    {
        // Fixed internal identifiers only. SQLite affinity alone does not bound corrupt stored text.
        return "case when typeof($column) = 'integer' then $column else null end as ".($alias ?? $column);
    }

    private function textProjection(string $column, int $limit, ?string $alias = null): string
    {
        return "case when typeof($column) = 'text' then substr(cast($column as blob), 1, $limit) else null end as ".($alias ?? $column);
    }

    private function counter(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', (string) $value) !== 1
            || strlen((string) $value) === 19 && strcmp((string) $value, (string) (PHP_INT_MAX - 1)) > 0) {
            throw new RuntimeException('Unsupported identity counter.');
        }

        return (int) $value;
    }
}
