<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Predb\PredbIdentitySchema;
use App\Services\Predb\PredbIdentitySchemaAttestation;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PredbIdentitySchemaAttestationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('predb', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('filename')->default('');
        });
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
    }

    public function test_actual_installed_contract_passes_without_readiness_or_data_mutations(): void
    {
        $before = DB::table('predb_identity_state')->get()->all();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        (new PredbIdentitySchemaAttestation)->assertCurrent(DB::connection());
        self::assertNotEmpty($queries);
        foreach ($queries as $query) {
            self::assertStringStartsWith('select ', strtolower(ltrim($query)));
        }
        self::assertEquals($before, DB::table('predb_identity_state')->get()->all());
        self::assertSame(0, DB::table('predb_identity_keys')->count());
        self::assertSame(0, DB::table('predb_identity_revisions')->count());
    }

    /** @return list<array{string}> */
    public static function incompatibleContracts(): array
    {
        return [
            ['missing trigger'], ['changed trigger'], ['extra trigger'], ['partial index'], ['expression index'],
            ['reversed index'], ['collation index'], ['descending index'], ['extra unique'], ['temp shadow'],
            ['generated field'], ['text owner'], ['missing table'],
            ['helper trigger'], ['state trigger'], ['temp source trigger'], ['temp helper trigger'], ['oversized table SQL'],
            ['missing column'], ['wrong default'], ['wrong trigger target'], ['foreign key cascade'],
        ];
    }

    #[DataProvider('incompatibleContracts')]
    public function test_incompatible_contract_is_rejected_without_repairing_it(string $mutation): void
    {
        $indexChanges = [
            'partial index' => '(generation, predb_id) WHERE predb_id > 0',
            'expression index' => '(generation, abs(predb_id))',
            'reversed index' => '(predb_id, generation)',
            'collation index' => '(generation COLLATE NOCASE, predb_id)',
            'descending index' => '(generation, predb_id DESC)',
        ];
        if (isset($indexChanges[$mutation])) {
            DB::unprepared('DROP INDEX main.predb_identity_key_owner');
            DB::unprepared('CREATE INDEX main.predb_identity_key_owner ON predb_identity_keys '.$indexChanges[$mutation]);
        } elseif ($mutation === 'foreign key cascade') {
            Schema::create('external_owner', static function (Blueprint $table): void {
                $table->increments('id');
            });
            Schema::drop('predb_identity_keys');
            Schema::create('predb_identity_keys', static function (Blueprint $table): void {
                $table->char('generation', 32);
                $table->unsignedTinyInteger('kind');
                $table->char('key_digest', 64);
                $table->unsignedInteger('predb_id');
                $table->unsignedBigInteger('source_revision');
                $table->primary(['generation', 'kind', 'key_digest', 'predb_id']);
                $table->index(['generation', 'predb_id'], 'predb_identity_key_owner');
                $table->foreign('predb_id')->references('id')->on('external_owner')->cascadeOnDelete();
            });
        } elseif ($mutation === 'missing trigger') {
            DB::unprepared('DROP TRIGGER predb_identity_insert');
        } elseif ($mutation === 'changed trigger') {
            DB::unprepared('DROP TRIGGER predb_identity_insert');
            DB::unprepared('CREATE TRIGGER predb_identity_insert AFTER INSERT ON predb BEGIN SELECT 1; END');
        } elseif ($mutation === 'extra trigger') {
            DB::unprepared('CREATE TRIGGER unexpected_source_trigger AFTER INSERT ON predb BEGIN SELECT 1; END');
        } elseif ($mutation === 'extra unique') {
            DB::unprepared('CREATE UNIQUE INDEX incompatible_key_uniqueness ON predb_identity_keys (key_digest)');
        } elseif ($mutation === 'helper trigger') {
            DB::unprepared('CREATE TRIGGER unexpected_key_trigger AFTER INSERT ON predb_identity_keys BEGIN DELETE FROM predb_identity_keys WHERE predb_id=NEW.predb_id; END');
        } elseif ($mutation === 'state trigger') {
            DB::unprepared('CREATE TRIGGER unexpected_state_trigger AFTER UPDATE ON predb_identity_state BEGIN UPDATE predb_identity_state SET ready=1 WHERE id=NEW.id; END');
        } elseif ($mutation === 'temp source trigger') {
            DB::unprepared('CREATE TEMP TRIGGER unexpected_temp_source_trigger AFTER INSERT ON main.predb BEGIN SELECT 1; END');
        } elseif ($mutation === 'temp helper trigger') {
            DB::unprepared('CREATE TEMP TRIGGER unexpected_temp_key_trigger AFTER INSERT ON main.predb_identity_keys BEGIN SELECT 1; END');
        } elseif ($mutation === 'temp shadow') {
            DB::unprepared('CREATE TEMP TABLE predb (id INTEGER PRIMARY KEY, title VARCHAR, filename VARCHAR)');
        } elseif ($mutation === 'generated field') {
            DB::unprepared('DROP TABLE predb_identity_revisions');
            DB::unprepared("CREATE TABLE predb_identity_revisions (predb_id INTEGER NOT NULL PRIMARY KEY, revision INTEGER NOT NULL, deleted tinyint(1) NOT NULL, projected_revision INTEGER, projected_generation varchar GENERATED ALWAYS AS ('fixed') VIRTUAL)");
            DB::unprepared('CREATE INDEX predb_identity_revision_range ON predb_identity_revisions (revision, predb_id)');
        } elseif ($mutation === 'text owner') {
            DB::unprepared('DROP TABLE predb');
            DB::unprepared("CREATE TABLE predb (id TEXT NOT NULL PRIMARY KEY, title VARCHAR NOT NULL, filename VARCHAR NOT NULL DEFAULT '')");
            foreach ((new PredbIdentitySchema)->definitions('sqlite') as $definition) {
                DB::unprepared($definition);
            }
        } elseif ($mutation === 'oversized table SQL') {
            DB::unprepared('DROP TABLE predb');
            DB::unprepared('CREATE TABLE predb (id INTEGER NOT NULL PRIMARY KEY, /*'.str_repeat('a', 70000)."*/ title varchar NOT NULL, filename varchar NOT NULL DEFAULT '')");
            foreach ((new PredbIdentitySchema)->definitions('sqlite') as $definition) {
                DB::unprepared($definition);
            }
        } elseif ($mutation === 'missing column') {
            DB::unprepared('ALTER TABLE predb_identity_keys DROP COLUMN source_revision');
        } elseif ($mutation === 'wrong default') {
            $state = (array) DB::table('predb_identity_state')->sole();
            Schema::drop('predb_identity_state');
            Schema::create('predb_identity_state', static function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->char('generation', 32);
                $table->unsignedBigInteger('epoch')->default(1);
                $table->unsignedBigInteger('built_epoch')->nullable();
                $table->char('policy_fingerprint', 64)->nullable();
                $table->boolean('ready')->default(false);
                $table->unsignedBigInteger('rebuild_epoch')->nullable();
                $table->unsignedInteger('rebuild_cursor')->default(0);
                $table->unsignedInteger('rebuild_until_id')->nullable();
            });
            DB::table('predb_identity_state')->insert($state);
        } elseif ($mutation === 'wrong trigger target') {
            DB::unprepared('CREATE TABLE scratch_source (id INTEGER PRIMARY KEY)');
            DB::unprepared('DROP TRIGGER predb_identity_insert');
            $body = (new PredbIdentitySchema)->specifications('sqlite')['predb_identity_insert']['action_statement'];
            DB::unprepared('CREATE TRIGGER predb_identity_insert AFTER INSERT ON scratch_source '.$body);
        } else {
            DB::unprepared('DROP TABLE predb_identity_keys');
        }
        $before = DB::table('predb_identity_state')->get()->all();
        $schemaBefore = $this->schemaSnapshot();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $failure = null;
        try {
            (new PredbIdentitySchemaAttestation)->assertCurrent(DB::connection());
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'Unsupported metadata was treated as a current schema.');
        self::assertEquals($schemaBefore, $this->schemaSnapshot());
        self::assertEquals($before, DB::table('predb_identity_state')->get()->all());
        foreach ($queries as $query) {
            self::assertStringStartsWith('select ', strtolower(ltrim($query)));
        }
    }

    /** @return list<list<object>> */
    private function schemaSnapshot(): array
    {
        return [
            DB::table('main.sqlite_schema')->orderBy('type')->orderBy('name')->get()->all(),
            DB::table('temp.sqlite_schema')->orderBy('type')->orderBy('name')->get()->all(),
        ];
    }

    public function test_oversized_index_name_is_bounded_before_transport_and_rejected(): void
    {
        $name = 'idx_'.str_repeat('x', 4100);
        DB::unprepared('CREATE INDEX "'.$name.'" ON predb (title)');
        $query = null;
        DB::listen(static function (QueryExecuted $event) use (&$query): void {
            if (str_contains($event->sql, 'pragma_index_list')) {
                $query = [$event->sql, $event->bindings];
            }
        });
        try {
            (new PredbIdentitySchemaAttestation)->assertCurrent(DB::connection());
            self::fail('Oversized index metadata was accepted.');
        } catch (RuntimeException) {
            self::assertNotNull($query);
        }
        $rows = DB::connection()->select($query[0], $query[1]);
        self::assertLessThanOrEqual(256, strlen($rows[0]->name));
    }

    public function test_custom_index_collation_is_bounded_before_transport_and_rejected(): void
    {
        $collation = 'BINARY_'.str_repeat('x', 4100);
        DB::connection()->getPdo()->sqliteCreateCollation($collation, strcmp(...));
        DB::unprepared('DROP INDEX predb_identity_key_owner');
        DB::unprepared('CREATE INDEX predb_identity_key_owner ON predb_identity_keys (generation COLLATE "'.$collation.'", predb_id)');
        $query = null;
        DB::listen(static function (QueryExecuted $event) use (&$query): void {
            if (str_contains($event->sql, 'pragma_index_xinfo')) {
                $query = [$event->sql, $event->bindings];
            }
        });
        try {
            (new PredbIdentitySchemaAttestation)->assertCurrent(DB::connection());
            self::fail('Unexpected custom index collation was accepted.');
        } catch (RuntimeException) {
            self::assertNotNull($query);
        }
        $rows = DB::connection()->select($query[0], $query[1]);
        self::assertLessThanOrEqual(7, strlen($rows[0]->coll));
    }

    /** @return array<string, array{string, bool}> */
    public static function mariaSessionVersions(): array
    {
        return [
            'base 11.4.8 version' => ['11.4.8-MariaDB-ubu2404', true],
            'binary log 11.4.8 version' => ['11.4.8-MariaDB-ubu2404-log', true],
            'unqualified debug suffix' => ['11.4.8-MariaDB-ubu2404-debug', false],
            'unqualified major version' => ['10.11.6-MariaDB-ubu2404', false],
        ];
    }

    #[DataProvider('mariaSessionVersions')]
    public function test_mariadb_session_version_accepts_log_suffix_and_rejects_unqualified_versions(string $version, bool $accepted): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $connection = new class($pdo, 'nntmux', '', ['driver' => 'mariadb']) extends Connection
            {
                /** @param array<string, mixed> $config */
                public function __construct(\PDO $pdo, string $database, string $tablePrefix, array $config)
                {
                    parent::__construct($pdo, $database, $tablePrefix, $config);
                    $this->transactions = 1;
                }

                public string $testVersion = '11.4.8-MariaDB-ubu2404';

                public function getDriverName(): string
                {
                    return 'mariadb';
                }

                /**
                 * @param  array<array-key, mixed>  $bindings
                 * @param  array<array-key, mixed>  $fetchUsing
                 * @return list<object>
                 */
                public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
                {
                    if (str_contains($query, 'predb_identity_state') && str_contains($query, 'for update')) {
                        return [(object) ['id' => '1']];
                    }
                    if (str_contains($query, 'limit 1')) {
                        return [];
                    }
                    if (str_contains($query, 'FROM information_schema.TABLES')) {
                        throw new RuntimeException('Reached table attestation past session check.');
                    }

                    return [];
                }

                /** @param array<array-key, mixed> $bindings */
                public function selectOne($query, $bindings = [], $useReadPdo = true): mixed
                {
                    return (object) [
                        'version' => $this->testVersion,
                        'schema_name' => 'nntmux',
                        'isolation_level' => 'READ-COMMITTED',
                        'connection_collation' => 'utf8mb4_unicode_ci',
                        'client_charset' => 'utf8mb4',
                        'lower_case_table_names' => 0,
                        'mode_bytes' => 22,
                        'mode_raw' => 'NO_ENGINE_SUBSTITUTION',
                    ];
                }
            };
            $connection->testVersion = $version;

            try {
                (new PredbIdentitySchemaAttestation)->assertCurrent($connection);
                self::fail('Expected exception.');
            } catch (RuntimeException $e) {
                if ($accepted) {
                    self::assertSame('Reached table attestation past session check.', $e->getMessage());
                } else {
                    self::assertSame('MariaDB PreDB session contract is unqualified.', $e->getMessage());
                }
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
