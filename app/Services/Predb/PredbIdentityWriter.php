<?php

declare(strict_types=1);

namespace App\Services\Predb;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\PredbSearchDocument;
use App\Traits\OwnsPredbIdentityTransactions;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Fixed ingestion operations. Maria preparation retains an explicit qualification HOLD.
 * Results contain no publication or commit claim.
 *
 * @phpstan-type WriterResult array{status: string, id: int|null, row: array<string, mixed>|null, identity_changed: bool, readiness: string, source_failure: QueryException|null}
 * @phpstan-type WriterState array{id: int, generation: string, epoch: int, built_epoch: int|null, policy_fingerprint: string|null, ready: 0|1, rebuild_epoch: int|null, rebuild_cursor: int, rebuild_until_id: int|null}
 * @phpstan-type Capability array{state: WriterState, policy: string, admitted: bool}
 */
final class PredbIdentityWriter
{
    use OwnsPredbIdentityTransactions;

    // Private preparation only: coordinator must reseal and add this definition to the policy.
    public const PREDB_IDENTITY_POLICY = '0c28ce35e3ba3fda74121d94aaa90455b7f55064cabe3d5718f9fa47b60301b8';

    public function __construct(private readonly Connection $connection) {}

    /** @return array{status: string, id: int|null, row: array<string, mixed>|null, identity_changed: bool, readiness: string, source_failure: QueryException|null} */
    public function insertFeed(string $title, PredbFeedEntry $entry): array
    {
        $failure = null;

        return $this->operation(function () use ($title, $entry, &$failure): array {
            $before = $this->capability();
            $values = [
                'title' => $title,
                'source' => mb_substr($entry->source, 0, 50),
                'category' => $entry->category !== null ? mb_substr($entry->category, 0, 255) : null,
                'size' => $entry->size !== null ? mb_substr($entry->size, 0, 50) : null,
                'files' => $entry->files !== null ? mb_substr($entry->files, 0, 50) : null,
                'predate' => $entry->predate?->format('Y-m-d H:i:s'),
                'nuked' => $entry->nuked,
                'nukereason' => $entry->nukeReason !== null ? mb_substr($entry->nukeReason, 0, 255) : null,
                'filename' => '',
            ];

            return $this->generatedInsert($values, $before, $failure);
        }, $failure);
    }

    /** @param array<string, mixed> $current
     * @return array{status: string, id: int|null, row: array<string, mixed>|null, identity_changed: bool, readiness: string, source_failure: QueryException|null}
     */
    public function insertLegacy(array $current): array
    {
        $failure = null;

        return $this->operation(function () use ($current, &$failure): array {
            $before = $this->capability();
            if (empty($current['title'])) {
                return $this->outcome('noop', null, null, false, 'unchanged');
            }
            if ($this->connection->table('predb')->where('title', $current['title'])->exists()) {
                return $this->outcome('exists', null, null, false, 'unchanged');
            }
            $values = $this->legacyValues($current);
            $values['predate'] = new Expression(! empty($current['predate']) ? $current['predate'] : 'NOW()');
            $values['title'] = $current['title'];

            return $this->generatedInsert($values, $before, $failure);
        }, $failure);
    }

    /** @param array<string, mixed> $current
     * @return array{status: string, id: int|null, row: array<string, mixed>|null, identity_changed: bool, readiness: string, source_failure: QueryException|null}
     */
    public function updateLegacy(int $id, string $observedTitle, array $current): array
    {
        $failure = null;

        return $this->operation(function () use ($id, $observedTitle, $current, &$failure): array {
            $before = $this->capability();
            if ($id < 1 || $id > 4294967295 || empty($current['title'])
                || PredbSearchDocument::exactValue($observedTitle) !== PredbSearchDocument::exactValue((string) $current['title'])) {
                return $this->outcome('stale', null, null, false, 'unchanged');
            }
            $source = $this->connection->table('predb')->where('id', $id)->lockForUpdate()->first();
            if ($source === null || $source->title !== $observedTitle) {
                return $this->outcome('stale', null, null, false, 'unchanged');
            }
            $values = $this->legacyValues($current);
            foreach (['source', 'category'] as $column) {
                if (! empty($source->{$column})) {
                    unset($values[$column]);
                }
            }
            if (! empty($current['predate'])) {
                $values['predate'] = new Expression($current['predate']);
            }
            if ($values === []) {
                return $this->outcome('noop', $id, null, false, 'unchanged');
            }
            $values['title'] = $current['title'];
            $changed = $source->title !== $values['title']
                || (array_key_exists('filename', $values) && $source->filename !== $values['filename']);
            $query = $this->connection->table('predb')->where('id', $id)
                ->whereRaw('HEX(title) = ?', [strtoupper(bin2hex($observedTitle))]);
            if ($source->filename === null) {
                $query->whereNull('filename');
            } else {
                $query->whereRaw('HEX(filename) = ?', [strtoupper(bin2hex((string) $source->filename))]);
            }
            try {
                $affected = $query->update($values);
            } catch (QueryException $error) {
                $this->recordSourceFailure($error, $failure);
                throw $error;
            }
            $row = $this->connection->table('predb')->where('id', $id)->first();
            if ($row === null || PredbSearchDocument::exactValue((string) $row->title) !== PredbSearchDocument::exactValue((string) $current['title'])
                || ($affected === 0 && $row->title !== $observedTitle)) {
                throw new RuntimeException('PreDB writer source ownership changed.');
            }
            $readiness = $this->finish($before, $id, $changed, $row);

            return $this->outcome($affected > 0 ? 'updated' : 'unchanged', $id, (array) $row, $changed, $readiness);
        }, $failure);
    }

    /** @param callable(): WriterResult $write
     * @return WriterResult
     */
    private function operation(callable $write, ?QueryException &$sourceFailure): array
    {
        $driver = $this->connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mariadb'], true) || PHP_INT_SIZE !== 8 || $this->connection->getTablePrefix() !== '') {
            throw new RuntimeException('PreDB writer driver is unqualified; MariaDB readiness is on HOLD.');
        }
        try {
            return $this->runOwnedPredbTransaction($this->connection, $write, 1);
        } catch (QueryException $error) {
            if ($error !== $sourceFailure) {
                throw $error;
            }

            return [...$this->outcome('source_rejected', null, null, false, 'unchanged'), 'source_failure' => $error];
        }
    }

    private function recordSourceFailure(QueryException $error, ?QueryException &$failure): void
    {
        if ($error instanceof UniqueConstraintViolationException || str_starts_with((string) $error->getCode(), '22')) {
            $failure = $error;
        }
    }

    /** @param array<string, mixed> $values
     * @param  Capability|null  $before
     * @return WriterResult
     */
    private function generatedInsert(array $values, ?array $before, ?QueryException &$failure): array
    {
        try {
            $generated = $this->connection->table('predb')->insertGetId($values);
        } catch (QueryException $error) {
            $this->recordSourceFailure($error, $failure);
            throw $error;
        }
        if ((! is_int($generated) && ! is_string($generated)) || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $generated) !== 1
            || (int) $generated > 4294967295) {
            throw new RuntimeException('Unsupported generated PreDB owner.');
        }
        $id = (int) $generated;
        $row = $this->connection->table('predb')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('Generated PreDB owner disappeared.');
        }
        $readiness = $this->finish($before, $id, true, $row);

        return $this->outcome('inserted', $id, (array) $row, true, $readiness);
    }

    /** @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function legacyValues(array $current): array
    {
        $values = [];
        foreach (['size' => 'size', 'category' => 'category', 'source' => 'source', 'reason' => 'nukereason',
            'files' => 'files', 'reqid' => 'requestid', 'group_id' => 'groups_id', 'nuked' => 'nuked', 'filename' => 'filename'] as $key => $column) {
            if (! empty($current[$key])) {
                $values[$column] = $current[$key];
            }
        }

        return $values;
    }

    /** Null means genuinely absent tracking; every other state must attest.
     * @return Capability|null
     */
    private function capability(): ?array
    {
        if ($this->connection->getDriverName() === 'mariadb') {
            return $this->mariaCapability();
        }
        $tables = ['predb_identity_state', 'predb_identity_revisions', 'predb_identity_keys'];
        $reserved = [...$tables, ...PredbIdentitySchema::TRIGGERS];
        // SQLite identifiers fold ASCII case; capability discovery must do the same.
        $shadows = $this->connection->table('temp.sqlite_schema')->where(function ($query) use ($reserved, $tables): void {
            $query->whereIn(new Expression('name COLLATE NOCASE'), [...$reserved, 'predb'])->orWhere(function ($query) use ($tables): void {
                $query->where('type', 'trigger')->whereIn(new Expression('tbl_name COLLATE NOCASE'), [...$tables, 'predb']);
            });
        })->exists();
        if ($shadows) {
            throw new RuntimeException('Temporary objects shadow PreDB writer capability.');
        }
        $installed = $this->connection->table('main.sqlite_schema')->whereIn(new Expression('name COLLATE NOCASE'), $reserved)->exists();
        if (! $installed) {
            $source = $this->connection->table('main.sqlite_schema')->whereRaw('name COLLATE NOCASE = ?', ['predb'])->where('type', 'table')->exists();
            if (! $source) {
                throw new RuntimeException('PreDB writer source is unavailable.');
            }

            return null;
        }
        (new PredbIdentitySchemaAttestation)->assertCurrent($this->connection);
        $rows = $this->connection->table('predb_identity_state')->limit(2)->lockForUpdate()->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException('PreDB writer singleton is unavailable.');
        }
        $state = (array) $rows[0];
        $this->assertState($state);
        $policy = (new PredbIdentityPolicy)->fingerprint();
        if ($policy === null || ! (new PredbIdentityPolicy)->definitionIsCurrent(self::class)) {
            throw new RuntimeException('PreDB writer loaded policy is unavailable.');
        }

        return ['state' => $state, 'policy' => $policy, 'admitted' => $state['ready'] === 1
            && $state['built_epoch'] === $state['epoch'] && $state['policy_fingerprint'] === $policy
            && $state['rebuild_epoch'] === null && $state['rebuild_until_id'] === null && $state['rebuild_cursor'] === 0];
    }

    /** @return Capability */
    private function mariaCapability(): array
    {
        $objects = $this->mariaCatalogue();
        if (! $objects) {
            // Installed-only qualification. Observed absence cannot prove account
            // visibility and must never authorize the untracked source-DML fallback.
            throw new RuntimeException('MariaDB PreDB writer HOLD: absent or hidden tracking metadata is unqualified.');
        }
        // Actual bounded attestation owns S and all table opens before even
        // physical identity types can be trusted. No duplicate raw prelude.
        (new PredbIdentitySchemaAttestation)->assertCurrent($this->connection);
        $rows = $this->connection->select('select * from '.$this->mariaTable('predb_identity_state').' limit 2 for update', [], false);
        if (count($rows) !== 1) {
            throw new RuntimeException('PreDB writer singleton is unavailable.');
        }
        $state = $this->stateValues((array) $rows[0]);
        $this->assertState($state);
        $policy = (new PredbIdentityPolicy)->fingerprint();
        if ($policy === null || ! (new PredbIdentityPolicy)->definitionIsCurrent(self::class)) {
            throw new RuntimeException('PreDB writer loaded policy is unavailable.');
        }

        return ['state' => $state, 'policy' => $policy, 'admitted' => $state['ready'] === 1
            && $state['built_epoch'] === $state['epoch'] && $state['policy_fingerprint'] === $policy
            && $state['rebuild_epoch'] === null && $state['rebuild_until_id'] === null && $state['rebuild_cursor'] === 0];
    }

    /**
     * True means installed/damaged capability. False is only observed absence:
     * exact-account metadata visibility must be qualified separately. It never
     * removes the public operation HOLD or establishes an absence certificate.
     */
    private function mariaCatalogue(): bool
    {
        $schema = $this->connection->getDatabaseName();
        $this->mariaTable('predb');
        $tables = ['predb', 'predb_identity_state', 'predb_identity_revisions', 'predb_identity_keys'];
        $rows = $this->connection->select('select TABLE_SCHEMA as schema_name, '
            .'OCTET_LENGTH(TABLE_NAME) as name_bytes, SUBSTRING(CAST(TABLE_NAME AS BINARY),1,65) as name, '
            .'TABLE_TYPE as kind, ENGINE as engine, `TEMPORARY` as temporary_flag '
            .'from information_schema.TABLES where TABLE_SCHEMA = ? and lower(TABLE_NAME) in (?,?,?,?) '
            .'order by TABLE_NAME, `TEMPORARY` limit 9', [$schema, ...$tables], false);
        if (count($rows) > 8) {
            throw new RuntimeException('MariaDB PreDB writer catalogue inventory exceeds its bound.');
        }
        $seen = [];
        foreach ($rows as $row) {
            if (! is_string($row->name) || $this->mariaCounter($row->name_bytes, 64) !== strlen($row->name)
                || ! in_array($row->name, $tables, true) || isset($seen[$row->name])
                || $row->schema_name !== $schema || $row->temporary_flag !== 'N'
                || $row->kind !== 'BASE TABLE' || ! is_string($row->engine) || strtoupper($row->engine) !== 'INNODB') {
                throw new RuntimeException('Unsupported MariaDB PreDB writer object or temporary shadow.');
            }
            $seen[$row->name] = true;
        }
        if (! isset($seen['predb'])) {
            throw new RuntimeException('PreDB writer source is unavailable.');
        }
        $triggers = $this->connection->select('select OCTET_LENGTH(TRIGGER_NAME) as name_bytes, '
            .'SUBSTRING(CAST(TRIGGER_NAME AS BINARY),1,65) as name '
            .'from information_schema.TRIGGERS where TRIGGER_SCHEMA = ? '
            .'and (lower(EVENT_OBJECT_TABLE) in (?,?,?,?) or lower(TRIGGER_NAME) in (?,?,?)) '
            .'order by TRIGGER_NAME limit 4', [$schema, ...$tables, ...PredbIdentitySchema::TRIGGERS], false);
        foreach ($triggers as $trigger) {
            if (! is_string($trigger->name) || $this->mariaCounter($trigger->name_bytes, 64) !== strlen($trigger->name)) {
                throw new RuntimeException('Unsupported MariaDB PreDB writer trigger metadata.');
            }
        }

        // Any partial reserved table/trigger or extra source trigger must attest;
        // metadata uncertainty never becomes the untracked fallback.
        return count($seen) !== 1 || $triggers !== [];
    }

    private function mariaTable(string $table): string
    {
        $schema = $this->connection->getDatabaseName();
        if ($schema === '' || strlen($schema) > 64 || preg_match('/[\x00-\x1f\x7f]/', $schema) !== 0) {
            throw new RuntimeException('Unavailable PreDB writer database.');
        }

        return '`'.str_replace('`', '``', $schema).'`.`'.$table.'`';
    }

    private function mariaCounter(mixed $value, int $maximum): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', (string) $value) !== 1) {
            throw new RuntimeException('Unsupported MariaDB PreDB writer counter.');
        }
        $digits = (string) $value;
        $bound = (string) $maximum;
        if (strlen($digits) > strlen($bound) || (strlen($digits) === strlen($bound) && strcmp($digits, $bound) > 0)) {
            throw new RuntimeException('MariaDB PreDB writer counter exceeds its bound.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stateValues(array $row): array
    {
        if ($this->connection->getDriverName() !== 'mariadb') {
            return $row;
        }
        foreach (['id' => 1, 'epoch' => PHP_INT_MAX - 1, 'ready' => 1, 'rebuild_cursor' => 4294967295] as $column => $maximum) {
            $row[$column] = $this->mariaCounter($row[$column] ?? null, $maximum);
        }
        foreach (['built_epoch', 'rebuild_epoch', 'rebuild_until_id'] as $column) {
            if (! array_key_exists($column, $row)) {
                throw new RuntimeException('Unsupported PreDB writer state counter.');
            }
            if ($row[$column] !== null) {
                $row[$column] = $this->mariaCounter($row[$column], $column === 'rebuild_until_id' ? 4294967295 : PHP_INT_MAX - 1);
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $state
     * @phpstan-assert WriterState $state
     */
    private function assertState(array $state): void
    {
        if (($state['id'] ?? null) !== 1 || ! is_string($state['generation'] ?? null)
            || preg_match('/\A[0-9a-f]{32}\z/D', $state['generation']) !== 1
            || ! is_int($state['epoch'] ?? null) || $state['epoch'] < 0 || $state['epoch'] >= PHP_INT_MAX - 1
            || ! in_array($state['ready'] ?? null, [0, 1], true)
            || ! is_int($state['rebuild_cursor'] ?? null) || $state['rebuild_cursor'] < 0 || $state['rebuild_cursor'] > 4294967295) {
            throw new RuntimeException('Unsupported PreDB writer state.');
        }
        foreach (['built_epoch', 'rebuild_epoch', 'rebuild_until_id'] as $column) {
            if (! array_key_exists($column, $state) || ($state[$column] !== null
                && (! is_int($state[$column]) || $state[$column] < 0 || $state[$column] > ($column === 'rebuild_until_id' ? 4294967295 : PHP_INT_MAX - 1)))) {
                throw new RuntimeException('Unsupported PreDB writer state counter.');
            }
        }
        if (! array_key_exists('policy_fingerprint', $state) || ($state['policy_fingerprint'] !== null
            && (! is_string($state['policy_fingerprint']) || preg_match('/\A[0-9a-f]{64}\z/D', $state['policy_fingerprint']) !== 1))) {
            throw new RuntimeException('Unsupported PreDB writer policy state.');
        }
    }

    /** @param Capability|null $before */
    private function finish(?array $before, int $id, bool $changed, object $source): string
    {
        if ($before === null) {
            if ($this->capability() !== null) {
                throw new RuntimeException('PreDB writer capability changed.');
            }

            return 'absent';
        }
        $state = $before['state'];
        $after = $this->connection->table('predb_identity_state')->where('id', 1)->first();
        $expected = $state;
        $expected['epoch'] += $changed ? 1 : 0;
        if ($changed) {
            $expected['ready'] = 0;
        }
        if ($after === null || $this->stateValues((array) $after) !== $expected) {
            throw new RuntimeException('PreDB writer epoch or certificate drift.');
        }
        $keys = (new PredbIdentityKeys)->forRow($source);
        $maintain = $before['admitted'] && $changed && $keys !== null;
        if ($changed) {
            $owner = $this->connection->table('predb_identity_revisions')->where('predb_id', $id)->first();
            if ($owner !== null && $this->connection->getDriverName() === 'mariadb') {
                $owner->revision = $this->mariaCounter($owner->revision, PHP_INT_MAX - 1);
                $owner->deleted = $this->mariaCounter($owner->deleted, 1);
            }
            if ($owner === null || $owner->revision !== $expected['epoch'] || $owner->deleted !== 0
                || $owner->projected_revision !== null || $owner->projected_generation !== null) {
                throw new RuntimeException('PreDB writer owner delta changed.');
            }
        }
        if ($maintain && $keys !== null) {
            (new PredbIdentityProjector)->replaceOwner($this->connection, $state['generation'], $id, $expected['epoch']);
            $actual = $this->connection->table('predb_identity_keys')->where('generation', $state['generation'])->where('predb_id', $id)
                ->orderBy('kind')->orderBy('key_digest')->limit(1000)->get(['kind', 'key_digest', 'source_revision'])
                ->map(static fn (object $row): array => (array) $row)->all();
            if ($this->connection->getDriverName() === 'mariadb') {
                foreach ($actual as &$key) {
                    $key['kind'] = $this->mariaCounter($key['kind'], 255);
                    $key['source_revision'] = $this->mariaCounter($key['source_revision'], PHP_INT_MAX - 1);
                }
                unset($key);
            }
            $wanted = array_map(static fn (array $key): array => [...$key, 'source_revision' => $expected['epoch']], $keys);
            usort($wanted, static fn (array $a, array $b): int => [$a['kind'], $a['key_digest']] <=> [$b['kind'], $b['key_digest']]);
            if (count($actual) >= 1000 || $actual !== $wanted) {
                throw new RuntimeException('Incomplete PreDB writer owner keys.');
            }
        }
        (new PredbIdentitySchemaAttestation)->assertCurrent($this->connection);
        if ((new PredbIdentityPolicy)->fingerprint() !== $before['policy']
            || ! (new PredbIdentityPolicy)->definitionIsCurrent(self::class)
            || $this->stateValues((array) $this->connection->table('predb_identity_state')->where('id', 1)->first()) !== $expected) {
            throw new RuntimeException('PreDB writer final authority changed.');
        }
        if ($maintain) {
            if ($this->connection->table('predb_identity_state')->where('id', 1)->where('epoch', $expected['epoch'])->where('ready', 0)
                ->update(['ready' => 1, 'built_epoch' => $expected['epoch']]) !== 1) {
                throw new RuntimeException('PreDB writer readiness restoration failed.');
            }

            return 'maintained';
        }

        return $changed ? 'invalidated' : 'unchanged';
    }

    /** @param array<string, mixed>|null $row
     * @return array{status: string, id: int|null, row: array<string, mixed>|null, identity_changed: bool, readiness: string, source_failure: QueryException|null}
     */
    private function outcome(string $status, ?int $id, ?array $row, bool $changed, string $readiness): array
    {
        return ['status' => $status, 'id' => $id, 'row' => $row, 'identity_changed' => $changed, 'readiness' => $readiness, 'source_failure' => null];
    }
}
