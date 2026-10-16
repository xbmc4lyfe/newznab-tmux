<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\Predb\PredbIdentityKeys;
use App\Services\Predb\PredbIdentityProjector;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PredbIdentityProjectorSqlTest extends TestCase
{
    public static function driverProfiles(): array
    {
        $cases = [];
        foreach (['sqlite', 'mysql', 'mariadb'] as $driver) {
            foreach (['', 'fixture_', 'odd`"_'] as $prefix) {
                foreach ([false, true] as $deleted) {
                    $cases[] = [$driver, $prefix, $deleted];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('driverProfiles')]
    public function test_parameterized_projector_preserves_framework_lock_routing_and_binding_contract(string $driver, string $prefix, bool $deleted): void
    {
        $resolved = 0;
        $factory = static function () use (&$resolved): never {
            $resolved++;
            throw new RuntimeException('This compile-only test must not resolve a database connection.');
        };
        $connection = match ($driver) {
            'sqlite' => new class($factory, 'fixture', $prefix, ['driver' => $driver]) extends SQLiteConnection
            {
                use RecordsPredbProjectionSql;
            },
            'mysql' => new class($factory, 'fixture', $prefix, ['driver' => $driver]) extends MySqlConnection
            {
                use RecordsPredbProjectionSql;
            },
            'mariadb' => new class($factory, 'fixture', $prefix, ['driver' => $driver]) extends MariaDbConnection
            {
                use RecordsPredbProjectionSql;
            },
        };
        $connection->fixtureDeleted = $deleted;
        $generation = str_repeat('a', 32);
        $expected = $connection->pretend(static function () use ($connection, $generation, $deleted): void {
            $connection->table('predb_identity_state')->where('id', 1)->lockForUpdate()->first();
            $connection->table('predb')->where('id', 42)->lockForUpdate()->first(['id', 'title', 'filename']);
            $connection->table('predb_identity_revisions')->where('predb_id', 42)->lockForUpdate()->first();
            $keys = $deleted ? [] : (new PredbIdentityKeys)->forRow($connection->fixtureSource());
            self::assertNotNull($keys);
            $rows = array_map(static fn (array $key): array => ['generation' => $generation, 'kind' => $key['kind'], 'key_digest' => $key['key_digest'], 'predb_id' => 42, 'source_revision' => 7], $keys);
            $connection->table('predb_identity_keys')->where('generation', $generation)->where('predb_id', 42)->delete();
            if ($rows !== []) {
                $connection->table('predb_identity_keys')->insert($rows);
            }
            $connection->table('predb_identity_revisions')->where('predb_id', 42)->where('revision', 7)->where('deleted', $deleted)->update(['projected_revision' => 7, 'projected_generation' => $generation]);
            $connection->table('predb_identity_revisions')->where('predb_id', 42)->first();
        });
        $actual = $connection->pretend(static fn () => (new PredbIdentityProjector)->replaceOwner($connection, $generation, 42, 7));
        self::assertSame($expected, $actual);
        self::assertCount($deleted ? 6 : 7, $actual);
        self::assertSame(0, $resolved);
    }
}

/** Actual framework SQL compilation and binding preparation; only results/logging are synthetic. */
trait RecordsPredbProjectionSql
{
    private ?bool $fixtureReadPdo = null;

    public bool $fixtureDeleted = false;

    public function transactionLevel(): int
    {
        return 1;
    }

    public function getPdo(): PDO
    {
        return new class extends PDO
        {
            public function __construct() {}

            public function inTransaction(): bool
            {
                return true;
            }
        };
    }

    public function fixtureSource(): object
    {
        return (object) ['id' => 42, 'title' => 'Synthetic.Asset-GRP', 'filename' => 'Synthetic.Asset-GRP.rar.par2'];
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->fixtureReadPdo = $useReadPdo;
        try {
            parent::select($query, $bindings, $useReadPdo, $fetchUsing);
        } finally {
            $this->fixtureReadPdo = null;
        }
        if (str_contains($query, 'predb_identity_state')) {
            return [(object) ['generation' => str_repeat('a', 32)]];
        }
        if (str_contains($query, 'predb_identity_revisions')) {
            return [(object) ['revision' => 7, 'deleted' => $this->fixtureDeleted, 'projected_revision' => 7, 'projected_generation' => str_repeat('a', 32)]];
        }

        return $this->fixtureDeleted ? [] : [$this->fixtureSource()];
    }

    public function logQuery($query, $bindings, $time = null)
    {
        // Ordinary pretend logging interpolates SQL by resolving PDO; retain parameterized evidence.
        if ($this->loggingQueries) {
            $this->queryLog[] = ['sql' => $query, 'bindings' => $bindings, 'prepared_bindings' => $this->prepareBindings($bindings), 'useReadPdo' => $this->fixtureReadPdo];
        }
    }
}
