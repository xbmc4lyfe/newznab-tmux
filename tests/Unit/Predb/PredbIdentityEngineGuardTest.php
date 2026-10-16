<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PredbIdentityEngineGuardTest extends TestCase
{
    public static function unsupportedTables(): array
    {
        $cases = [];
        foreach (['predb_identity_state', 'predb_identity_revisions', 'predb_identity_keys'] as $table) {
            foreach (['MyISAM', null] as $engine) {
                $cases[$table.' '.($engine ?? 'missing')] = [$table, $engine];
            }
        }

        return $cases;
    }

    #[DataProvider('unsupportedTables')]
    public function test_reused_metadata_tables_require_transactional_engines(string $table, ?string $engine): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDriverName')->willReturn('mysql');
        $connection->method('getDatabaseName')->willReturn('identity_fixture');
        DB::shouldReceive('connection')->andReturn($connection);
        Schema::shouldReceive('hasTable')->andReturnTrue();
        $target = null;
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->andReturnUsing(static function (string $column, mixed $value) use (&$target, $query): Builder {
            if ($column === 'TABLE_NAME') {
                $target = $value;
            }

            return $query;
        });
        $query->shouldReceive('value')->with('ENGINE')->andReturnUsing(static function () use (&$target, $table, $engine): ?string {
            return $target === $table ? $engine : 'InnoDB';
        });
        DB::shouldReceive('table')->with('information_schema.tables')->andReturn($query);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PreDB identity tracking requires an InnoDB table: '.$table);
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
    }
}
