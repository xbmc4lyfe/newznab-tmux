<?php

declare(strict_types=1);

namespace App\Services\Predb;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use PDO;
use RuntimeException;

/** Read-only installed-schema compatibility. Coverage and concurrent DDL require separate fences. */
final class PredbIdentitySchemaAttestation
{
    public const PREDB_IDENTITY_POLICY = 'd88b1ed8e2242683f98de576b7edbff364f4855a6d850737660190d191447337';

    /** @var array<string, array<string, array{string, int, string, int}>> */
    private const SQLITE_COLUMNS = [
        'predb' => ['id' => ['integer', 1, 'absent', 1], 'title' => ['varchar', 1, 'source_title', 0], 'filename' => ['varchar', 1, 'empty', 0]],
        'predb_identity_state' => [
            'id' => ['integer', 1, 'absent', 1], 'generation' => ['varchar', 1, 'absent', 0],
            'epoch' => ['integer', 1, 'zero', 0], 'built_epoch' => ['integer', 0, 'nullable', 0],
            'policy_fingerprint' => ['varchar', 0, 'nullable', 0], 'ready' => ['tinyint(1)', 1, 'zero', 0],
            'rebuild_epoch' => ['integer', 0, 'nullable', 0], 'rebuild_cursor' => ['integer', 1, 'zero', 0],
            'rebuild_until_id' => ['integer', 0, 'nullable', 0],
        ],
        'predb_identity_revisions' => [
            'predb_id' => ['integer', 1, 'absent', 1], 'revision' => ['integer', 1, 'absent', 0],
            'deleted' => ['tinyint(1)', 1, 'absent', 0], 'projected_revision' => ['integer', 0, 'nullable', 0],
            'projected_generation' => ['varchar', 0, 'nullable', 0],
        ],
        'predb_identity_keys' => [
            'generation' => ['varchar', 1, 'absent', 1], 'kind' => ['integer', 1, 'absent', 2],
            'key_digest' => ['varchar', 1, 'absent', 3], 'predb_id' => ['integer', 1, 'absent', 4],
            'source_revision' => ['integer', 1, 'absent', 0],
        ],
    ];

    public function assertCurrent(Connection $connection): void
    {
        if ($connection->getDriverName() === 'mariadb') {
            $this->assertMariaCurrent($connection);

            return;
        }
        // MySQL/MariaDB require their own qualified raw-metadata and trigger-mode contract.
        if (PHP_INT_SIZE !== 8 || $connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported PreDB schema attestation driver.');
        }
        $tables = array_keys(self::SQLITE_COLUMNS);
        if ($connection->table('temp.sqlite_schema')->where(static function ($query) use ($tables): void {
            $query->whereIn(new Expression('name COLLATE NOCASE'), array_merge($tables, PredbIdentitySchema::TRIGGERS))
                ->orWhere(static function ($query) use ($tables): void {
                    $query->where('type', 'trigger')->whereIn(new Expression('tbl_name COLLATE NOCASE'), $tables);
                });
        })->exists()) {
            throw new RuntimeException('Temporary objects shadow the PreDB identity schema.');
        }
        foreach (self::SQLITE_COLUMNS as $table => $expected) {
            $objects = $connection->table('main.sqlite_schema')->where('name', $table)->whereIn('type', ['table', 'view'])
                ->selectRaw('type, length(CAST(sql AS BLOB)) AS sql_bytes, substr(CAST(sql AS BLOB),1,65537) AS sql')->limit(2)->get();
            if ($objects->count() !== 1 || $objects[0]->type !== 'table' || ! is_string($objects[0]->sql)
                || (int) $objects[0]->sql_bytes > 65536
                || preg_match('/\ACREATE\s+TABLE\s/i', $objects[0]->sql) !== 1) {
                throw new RuntimeException('Unsupported PreDB schema table: '.$table);
            }
            if ($connection->select('SELECT id FROM pragma_foreign_key_list(?, ?) LIMIT 1', [$table, 'main']) !== []) {
                throw new RuntimeException('PreDB identity foreign-key dependencies changed: '.$table);
            }
            $this->assertColumns($connection, $table, $expected);
            $this->assertIndexes($connection, $table);
        }
        $definitions = (new PredbIdentitySchema)->definitions('sqlite');
        $triggers = $connection->table('main.sqlite_schema')->where('type', 'trigger')
            ->where(static function ($query) use ($tables): void {
                $query->whereIn(new Expression('tbl_name COLLATE NOCASE'), $tables)
                    ->orWhereIn(new Expression('name COLLATE NOCASE'), PredbIdentitySchema::TRIGGERS);
            })->selectRaw('substr(CAST(name AS BLOB),1,256) AS name, substr(CAST(tbl_name AS BLOB),1,256) AS tbl_name, length(CAST(sql AS BLOB)) AS sql_bytes, substr(CAST(sql AS BLOB),1,65537) AS sql')->limit(4)->get();
        if ($triggers->count() !== count($definitions)) {
            throw new RuntimeException('PreDB identity trigger inventory changed.');
        }
        foreach ($triggers as $trigger) {
            if ($trigger->tbl_name !== 'predb' || ! is_string($trigger->name) || ! is_string($trigger->sql)
                || (int) $trigger->sql_bytes > 65536 || ($definitions[$trigger->name] ?? null) !== $trigger->sql) {
                throw new RuntimeException('PreDB identity trigger definition changed.');
            }
        }
    }

    /**
     * Private qualification candidate for the exact observed MariaDB profile.
     * Caller retains transaction/COMMIT ownership; these table locks must survive to its end.
     * This does not qualify a deployed account or grant application publication authority.
     */
    private function assertMariaCurrent(Connection $connection): void
    {
        if (PHP_INT_SIZE !== 8 || $connection->getTablePrefix() !== '') {
            throw new RuntimeException('Unsupported MariaDB PreDB attestation context.');
        }
        $pdo = $connection->getRawPdo();
        $depth = $connection->transactionLevel();
        $schema = $connection->getDatabaseName();
        if (! $pdo instanceof PDO || $depth < 1 || ! $pdo->inTransaction()
            || ! is_string($schema) || $schema === '' || strlen($schema) > 64
            || preg_match('/[\x00-\x1f\x7f]/', $schema) !== 0) {
            throw new RuntimeException('MariaDB PreDB attestation requires an active owned writer transaction.');
        }
        $qualifiedSchema = '`'.str_replace('`', '``', $schema).'`';
        // S first. Bound identity bytes before trusting even the column types.
        // These real table reads retain MDL; binary probes never establish authority.
        // Repeat acquisition by Lookup/Rebuild callers is reentrant in this transaction.
        $singleton = $connection->select('select substring(cast(id as binary), 1, 21) as id from '.$qualifiedSchema.'.`predb_identity_state` limit 2 for update', [], false);
        if (count($singleton) !== 1 || $this->mariaInteger($singleton[0]->id) !== 1) {
            throw new RuntimeException('MariaDB PreDB singleton fence is unavailable.');
        }
        foreach (['predb' => 'id', 'predb_identity_keys' => 'predb_id', 'predb_identity_revisions' => 'predb_id'] as $table => $column) {
            $connection->select('select substring(cast('.$column.' as binary), 1, 21) as identity_id from '.$qualifiedSchema.'.`'.$table.'` limit 1', [], false);
        }
        $session = $connection->selectOne('SELECT SUBSTRING(VERSION(),1,257) AS version, DATABASE() AS schema_name, '
            .'@@session.tx_isolation AS isolation_level, @@session.collation_connection AS connection_collation, '
            .'@@session.character_set_client AS client_charset, @@lower_case_table_names AS lower_case_table_names, '
            .'OCTET_LENGTH(@@session.sql_mode) AS mode_bytes, SUBSTRING(CAST(@@session.sql_mode AS BINARY),1,4097) AS mode_raw', [], false);
        if ($session === null || $session->schema_name !== $schema || ! is_string($session->version) || strlen($session->version) > 256
            || ! in_array($session->version, ['11.4.8-MariaDB-ubu2404', '11.4.8-MariaDB-ubu2404-log'], true)
            || $session->isolation_level !== 'READ-COMMITTED' || $session->connection_collation !== 'utf8mb4_unicode_ci'
            || $session->client_charset !== 'utf8mb4' || $session->lower_case_table_names !== 0
            || ! $this->mariaModeMatches($session->mode_raw, $session->mode_bytes)) {
            throw new RuntimeException('MariaDB PreDB session contract is unqualified.');
        }
        $tables = array_keys(self::SQLITE_COLUMNS);
        $objects = $connection->select('SELECT TABLE_SCHEMA AS schema_name, TABLE_NAME AS name, TABLE_TYPE AS kind, '
            .'ENGINE AS engine, `TEMPORARY` AS temporary_flag, TABLE_COLLATION AS table_collation '
            .'FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?,?,?,?) '
            .'ORDER BY TABLE_NAME, `TEMPORARY` LIMIT 9', [$schema, ...$tables], false);
        if (count($objects) !== 4) {
            throw new RuntimeException('MariaDB PreDB permanent table or temporary-shadow inventory changed.');
        }
        $seen = [];
        foreach ($objects as $object) {
            if ($object->schema_name !== $schema || ! in_array($object->name, $tables, true) || isset($seen[$object->name])
                || $object->kind !== 'BASE TABLE' || $object->engine !== 'InnoDB' || $object->temporary_flag !== 'N'
                || $object->table_collation !== 'utf8mb4_unicode_ci') {
                throw new RuntimeException('Unsupported MariaDB PreDB table or session temporary shadow.');
            }
            $seen[$object->name] = true;
            $this->assertMariaColumns($connection, $schema, $object->name);
            $this->assertMariaIndexes($connection, $schema, $object->name);
        }
        if ($connection->select('SELECT 1 AS outgoing_foreign_key FROM information_schema.KEY_COLUMN_USAGE '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?,?,?,?) AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$schema, ...$tables], false) !== []) {
            throw new RuntimeException('MariaDB PreDB outgoing foreign-key dependencies changed.');
        }
        $this->assertMariaTriggers($connection, $schema, $tables);

        // Re-read native custody after all metadata calls; keep this independently checked.
        $this->assertMariaTransactionCustody($connection, $pdo, $depth);
    }

    private function assertMariaTransactionCustody(Connection $connection, PDO $pdo, int $depth): void
    {
        if ($connection->getRawPdo() !== $pdo || $connection->transactionLevel() !== $depth || ! $pdo->inTransaction()) {
            throw new RuntimeException('MariaDB PreDB attestation writer transaction changed.');
        }
    }

    /** @return array<string, array{string, bool, int|null, bool, string}> */
    private function mariaColumns(string $table): array
    {
        return match ($table) {
            'predb' => ['id' => ['int', true, null, false, 'absent'], 'title' => ['varchar', false, 255, false, 'source_title'],
                'filename' => ['varchar', false, 255, false, 'empty']],
            'predb_identity_state' => [
                'id' => ['tinyint', true, null, false, 'absent'], 'generation' => ['char', false, 32, false, 'absent'],
                'epoch' => ['bigint', true, null, false, 'zero'], 'built_epoch' => ['bigint', true, null, true, 'nullable'],
                'policy_fingerprint' => ['char', false, 64, true, 'nullable'], 'ready' => ['tinyint', false, 1, false, 'zero'],
                'rebuild_epoch' => ['bigint', true, null, true, 'nullable'], 'rebuild_cursor' => ['int', true, null, false, 'zero'],
                'rebuild_until_id' => ['int', true, null, true, 'nullable'],
            ],
            'predb_identity_revisions' => [
                'predb_id' => ['int', true, null, false, 'absent'], 'revision' => ['bigint', true, null, false, 'absent'],
                'deleted' => ['tinyint', false, 1, false, 'absent'], 'projected_revision' => ['bigint', true, null, true, 'nullable'],
                'projected_generation' => ['char', false, 32, true, 'nullable'],
            ],
            'predb_identity_keys' => [
                'generation' => ['char', false, 32, false, 'absent'], 'kind' => ['tinyint', true, null, false, 'absent'],
                'key_digest' => ['char', false, 64, false, 'absent'], 'predb_id' => ['int', true, null, false, 'absent'],
                'source_revision' => ['bigint', true, null, false, 'absent'],
            ],
            default => throw new RuntimeException('Unexpected MariaDB PreDB table.'),
        };
    }

    private function assertMariaColumns(Connection $connection, string $schema, string $table): void
    {
        $expected = $this->mariaColumns($table);
        $columns = $connection->select('SELECT COLUMN_NAME AS name, ORDINAL_POSITION AS position, DATA_TYPE AS data_type, '
            .'OCTET_LENGTH(COLUMN_TYPE) AS type_bytes, SUBSTRING(CAST(COLUMN_TYPE AS BINARY),1,65) AS column_type, '
            .'IS_NULLABLE AS nullable_flag, CHARACTER_MAXIMUM_LENGTH AS character_length, CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name, '
            .'COLUMN_DEFAULT IS NULL AS default_sql_null, OCTET_LENGTH(COLUMN_DEFAULT) AS default_bytes, '
            .'SUBSTRING(CAST(COLUMN_DEFAULT AS BINARY),1,65) AS default_raw, EXTRA AS extra, IS_GENERATED AS generated_flag, '
            .'OCTET_LENGTH(GENERATION_EXPRESSION) AS generation_bytes FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION LIMIT 129', [$schema, $table], false);
        if (count($columns) > 128 || ($table !== 'predb' && count($columns) !== count($expected))) {
            throw new RuntimeException('MariaDB PreDB column inventory changed.');
        }
        $seen = [];
        foreach ($columns as $position => $column) {
            if (! is_string($column->name) || strlen($column->name) > 64 || $this->mariaInteger($column->position) !== $position + 1) {
                throw new RuntimeException('Unsupported MariaDB PreDB column metadata.');
            }
            if (! isset($expected[$column->name])) {
                continue; // Existing nonidentity source columns remain permitted.
            }
            [$type, $unsigned, $length, $nullable, $default] = $expected[$column->name];
            if (isset($seen[$column->name]) || $column->data_type !== $type || ! is_string($column->column_type)
                || $this->mariaInteger($column->type_bytes) > 64 || strlen($column->column_type) !== $this->mariaInteger($column->type_bytes)
                || $column->nullable_flag !== ($nullable ? 'YES' : 'NO') || $column->generated_flag !== 'NEVER'
                || ! in_array($column->generation_bytes, [null, 0, '0'], true)
                || ! in_array($column->extra, $table === 'predb' && $column->name === 'id' ? ['', 'auto_increment'] : [''], true)) {
                throw new RuntimeException('Unsupported MariaDB PreDB column contract.');
            }
            if (in_array($type, ['char', 'varchar'], true)) {
                if ($column->column_type !== $type.'('.$length.')' || $this->mariaInteger($column->character_length) !== $length
                    || $column->charset_name !== 'utf8mb4' || $column->collation_name !== 'utf8mb4_unicode_ci') {
                    throw new RuntimeException('MariaDB PreDB text column contract changed.');
                }
            } elseif ($column->character_length !== null || $column->charset_name !== null || $column->collation_name !== null
                || preg_match('/\A'.preg_quote($type, '/').($length === null ? '(?:\([1-9][0-9]?\))?' : '\('.$length.'\)')
                    .($unsigned ? ' unsigned' : '').'\z/D', $column->column_type) !== 1) {
                throw new RuntimeException('MariaDB PreDB integer column contract changed.');
            }
            $kind = $this->mariaDefaultKind($column);
            $allowed = match ($default) {
                'source_title' => ['absent', 'empty'], 'nullable' => ['absent', 'null'], default => [$default],
            };
            if (! in_array($kind, $allowed, true)) {
                throw new RuntimeException('MariaDB PreDB default serialization is unsupported or unqualified.');
            }
            $seen[$column->name] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Required MariaDB PreDB column is unavailable.');
        }
    }

    /** Provisional literal-only forms; never evaluates an expression or grants admission. */
    private function mariaDefaultKind(object $column): ?string
    {
        if (in_array($column->default_sql_null, [1, '1'], true)) {
            return $column->default_raw === null && $column->default_bytes === null ? 'absent' : null;
        }
        if (! in_array($column->default_sql_null, [0, '0'], true) || ! is_string($column->default_raw)
            || $this->mariaInteger($column->default_bytes) > 64 || strlen($column->default_raw) !== $this->mariaInteger($column->default_bytes)) {
            return null;
        }

        return match ($column->default_raw) {
            '0', "'0'" => 'zero', "''" => 'empty', 'NULL' => 'null', default => null,
        };
    }

    private function assertMariaIndexes(Connection $connection, string $schema, string $table): void
    {
        $expected = match ($table) {
            'predb' => ['PRIMARY' => [0, ['id']]],
            'predb_identity_state' => ['PRIMARY' => [0, ['id']]],
            'predb_identity_revisions' => ['PRIMARY' => [0, ['predb_id']], 'predb_identity_revision_range' => [1, ['revision', 'predb_id']]],
            'predb_identity_keys' => ['PRIMARY' => [0, ['generation', 'kind', 'key_digest', 'predb_id']], 'predb_identity_key_owner' => [1, ['generation', 'predb_id']]],
            default => throw new RuntimeException('Unexpected MariaDB PreDB index table.'),
        };
        $parts = $connection->select('SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, '
            .'SUB_PART AS prefix_length, COLLATION AS direction, INDEX_TYPE AS kind, IGNORED AS ignored_flag '
            .'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX LIMIT 65', [$schema, $table], false);
        if (count($parts) > 64) {
            throw new RuntimeException('MariaDB PreDB index-part inventory exceeds its bound.');
        }
        $indexes = [];
        foreach ($parts as $part) {
            if (! is_string($part->name) || strlen($part->name) > 64 || ! is_string($part->column_name) || strlen($part->column_name) > 64
                || (($table !== 'predb' || isset($expected[$part->name]))
                    && ($part->prefix_length !== null || $part->direction !== 'A' || $part->kind !== 'BTREE' || $part->ignored_flag !== 'NO'))) {
                throw new RuntimeException('Unsupported MariaDB PreDB index part.');
            }
            $nonUnique = $this->mariaInteger($part->non_unique);
            $position = $this->mariaInteger($part->position);
            if (! in_array($nonUnique, [0, 1], true) || $position !== count($indexes[$part->name]['columns'] ?? []) + 1
                || (isset($indexes[$part->name]) && $indexes[$part->name]['non_unique'] !== $nonUnique)) {
                throw new RuntimeException('MariaDB PreDB index part sequence changed.');
            }
            $indexes[$part->name]['non_unique'] = $nonUnique;
            $indexes[$part->name]['columns'][] = $part->column_name;
        }
        foreach ($indexes as $name => $index) {
            if (! isset($expected[$name])) {
                if ($table !== 'predb' && $index['non_unique'] !== 1) {
                    throw new RuntimeException('Unexpected MariaDB PreDB uniqueness constraint.');
                }

                continue;
            }
            if ([$index['non_unique'], $index['columns']] !== $expected[$name]) {
                throw new RuntimeException('Required MariaDB PreDB index changed.');
            }
            unset($expected[$name]);
        }
        if ($expected !== []) {
            throw new RuntimeException('Required MariaDB PreDB index is unavailable.');
        }
    }

    /** @param list<string> $tables */
    private function assertMariaTriggers(Connection $connection, string $schema, array $tables): void
    {
        $triggers = $connection->select('SELECT TRIGGER_NAME AS name, EVENT_OBJECT_SCHEMA AS schema_name, EVENT_OBJECT_TABLE AS table_name, '
            .'EVENT_MANIPULATION AS event_name, ACTION_TIMING AS timing, ACTION_ORIENTATION AS orientation, ACTION_ORDER AS action_order, '
            .'ACTION_CONDITION AS action_condition, OCTET_LENGTH(ACTION_STATEMENT) AS action_bytes, '
            .'SUBSTRING(CAST(ACTION_STATEMENT AS BINARY),1,65537) AS action_raw, OCTET_LENGTH(SQL_MODE) AS mode_bytes, '
            .'SUBSTRING(CAST(SQL_MODE AS BINARY),1,4097) AS mode_raw, CHARACTER_SET_CLIENT AS client_charset, '
            .'COLLATION_CONNECTION AS connection_collation, DATABASE_COLLATION AS database_collation '
            .'FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND (EVENT_OBJECT_TABLE IN (?,?,?,?) '
            .'OR TRIGGER_NAME IN (?,?,?)) ORDER BY TRIGGER_NAME LIMIT 4', [$schema, ...$tables, ...PredbIdentitySchema::TRIGGERS], false);
        $expected = (new PredbIdentitySchema)->specifications('mariadb');
        if (count($triggers) !== count($expected)) {
            throw new RuntimeException('MariaDB PreDB trigger inventory changed or is hidden.');
        }
        foreach ($triggers as $trigger) {
            $specification = is_string($trigger->name) ? ($expected[$trigger->name] ?? null) : null;
            if ($specification === null || $trigger->schema_name !== $schema || $trigger->table_name !== $specification['table']
                || $trigger->event_name !== $specification['event'] || $trigger->timing !== $specification['timing']
                || $trigger->orientation !== $specification['orientation'] || $this->mariaInteger($trigger->action_order) !== 1
                || $trigger->action_condition !== null || ! is_string($trigger->action_raw)
                || $this->mariaInteger($trigger->action_bytes) > 65536 || strlen($trigger->action_raw) !== $this->mariaInteger($trigger->action_bytes)
                || $trigger->action_raw !== $specification['action_statement'] || ! $this->mariaModeMatches($trigger->mode_raw, $trigger->mode_bytes)
                || $trigger->client_charset !== 'utf8mb4' || $trigger->connection_collation !== 'utf8mb4_unicode_ci'
                || $trigger->database_collation !== 'utf8mb4_unicode_ci') {
                throw new RuntimeException('MariaDB PreDB trigger serialization, creation mode or definition is unsupported or unqualified.');
            }
            unset($expected[$trigger->name]);
        }
        if ($expected !== []) {
            throw new RuntimeException('Required MariaDB PreDB trigger is unavailable.');
        }
    }

    private function mariaModeMatches(mixed $raw, mixed $bytes): bool
    {
        if (! is_string($raw) || $this->mariaInteger($bytes) > 4096 || strlen($raw) !== $this->mariaInteger($bytes)) {
            return false;
        }
        $tokens = explode(',', $raw);
        foreach ($tokens as $token) {
            if (preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $token) !== 1) {
                return false;
            }
        }
        if (count(array_unique($tokens)) !== count($tokens)) {
            return false;
        }
        sort($tokens, SORT_STRING);

        return $tokens === ['NO_ENGINE_SUBSTITUTION'];
    }

    /** Metadata counters only: accept complete decimal PDO scalars, never coercive casts. */
    private function mariaInteger(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', (string) $value) !== 1) {
            throw new RuntimeException('Unsupported MariaDB PreDB metadata scalar serialization.');
        }

        return (int) $value;
    }

    /** Restricted metadata-literal classification; never evaluates SQL expressions. */
    public function defaultKind(mixed $value): ?string
    {
        if ($value === null) {
            return 'absent';
        }
        if (! is_string($value) || strlen($value) > 64) {
            return null;
        }
        $value = trim($value, " \t\r\n\v\f");
        for ($depth = 0; $depth < 8 && str_starts_with($value, '(') && str_ends_with($value, ')'); $depth++) {
            $value = trim(substr($value, 1, -1), " \t\r\n\v\f");
        }

        return match ($value) {
            '0', "'0'" => 'zero',
            "''" => 'empty',
            default => strcasecmp($value, 'NULL') === 0 ? 'null' : null,
        };
    }

    /** @param array<string, array{string, int, string, int}> $expected */
    private function assertColumns(Connection $connection, string $table, array $expected): void
    {
        $columns = $connection->select('SELECT name,type,"notnull",dflt_value,pk,hidden FROM pragma_table_xinfo(?, ?) ORDER BY cid LIMIT 129', [$table, 'main']);
        if (count($columns) > 128 || ($table !== 'predb' && count($columns) !== count($expected))) {
            throw new RuntimeException('PreDB identity column inventory changed: '.$table);
        }
        $seen = [];
        $primary = [];
        foreach ($columns as $column) {
            if (! is_string($column->name)) {
                throw new RuntimeException('Unsupported PreDB identity column metadata.');
            }
            if ((int) $column->pk !== 0) {
                $primary[(int) $column->pk] = $column->name;
            }
            if (! isset($expected[$column->name])) {
                continue;
            }
            [$type, $notNull, $default, $pk] = $expected[$column->name];
            $kind = $this->defaultKind($column->dflt_value);
            $allowed = match ($default) {
                'source_title' => ['absent', 'empty'], 'nullable' => ['absent', 'null'], default => [$default],
            };
            if (! is_string($column->type) || strtolower(trim($column->type)) !== $type
                || (int) $column->notnull !== $notNull || (int) $column->pk !== $pk || (int) $column->hidden !== 0
                || ! in_array($kind, $allowed, true)) {
                throw new RuntimeException('Unsupported PreDB column contract: '.$table.'.'.$column->name);
            }
            $seen[$column->name] = true;
        }
        $expectedPrimary = [];
        foreach ($expected as $name => $column) {
            if ($column[3] !== 0) {
                $expectedPrimary[$column[3]] = $name;
            }
        }
        ksort($primary);
        ksort($expectedPrimary);
        if (count($seen) !== count($expected) || $primary !== $expectedPrimary) {
            throw new RuntimeException('PreDB identity primary key or columns changed: '.$table);
        }
    }

    private function assertIndexes(Connection $connection, string $table): void
    {
        $expected = match ($table) {
            'predb_identity_revisions' => ['predb_identity_revision_range' => [0, ['revision', 'predb_id']]],
            'predb_identity_keys' => ['predb_identity_key_owner' => [0, ['generation', 'predb_id']],
                '@primary' => [1, ['generation', 'kind', 'key_digest', 'predb_id']]],
            default => [],
        };
        $indexes = $connection->select('SELECT substr(CAST(name AS BLOB),1,256) AS name,"unique",origin,partial FROM pragma_index_list(?, ?) ORDER BY name LIMIT 65', [$table, 'main']);
        if (count($indexes) > 64) {
            throw new RuntimeException('PreDB identity index inventory exceeds its bound.');
        }
        $seen = [];
        foreach ($indexes as $index) {
            $key = $index->origin === 'pk' ? '@primary' : $index->name;
            if (! is_string($key) || ! is_string($index->name) || strlen($index->name) > 255) {
                throw new RuntimeException('Unsupported PreDB identity index metadata.');
            }
            if (! isset($expected[$key])) {
                if ($index->origin === 'pk' || ($table !== 'predb' && (int) $index->unique !== 0)) {
                    throw new RuntimeException('Unexpected PreDB identity uniqueness constraint.');
                }

                continue;
            }
            [$unique, $names] = $expected[$key];
            if (isset($seen[$key]) || (int) $index->unique !== $unique || (int) $index->partial !== 0
                || $index->origin !== ($key === '@primary' ? 'pk' : 'c')) {
                throw new RuntimeException('Unsupported PreDB identity index: '.$key);
            }
            $parts = $connection->select('SELECT seqno,cid,substr(CAST(name AS BLOB),1,256) AS name,desc,substr(CAST(coll AS BLOB),1,7) AS coll,"key" FROM pragma_index_xinfo(?, ?) WHERE "key"=1 ORDER BY seqno LIMIT 65', [$index->name, 'main']);
            if (count($parts) !== count($names)) {
                throw new RuntimeException('PreDB identity index column inventory changed.');
            }
            foreach ($parts as $position => $part) {
                if ((int) $part->seqno !== $position || (int) $part->cid < 0 || $part->name !== $names[$position]
                    || (int) $part->desc !== 0 || $part->coll !== 'BINARY') {
                    throw new RuntimeException('PreDB identity index parts changed.');
                }
            }
            $seen[$key] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Required PreDB identity index is unavailable.');
        }
    }
}
