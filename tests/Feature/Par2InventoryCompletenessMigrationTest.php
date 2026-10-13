<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Par2InventoryCompletenessMigrationTest extends TestCase
{
    public function test_fresh_chain_gives_the_additive_migration_sole_marker_table_ownership(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
        });
        $evidenceMigration = require database_path('migrations/2026_10_12_010315_create_par_file_hash_evidence_tables.php');
        $evidenceMigration->up();
        self::assertFalse(Schema::hasTable('par_file_hash_inventories'));

        $inventoryMigration = require database_path('migrations/2026_10_12_220000_create_par_file_hash_inventories_table.php');
        $inventoryMigration->up();
        self::assertTrue(Schema::hasTable('par_file_hash_inventories'));
        $inventoryMigration->down();
        self::assertFalse(Schema::hasTable('par_file_hash_inventories'));
        self::assertTrue(Schema::hasTable('par_file_hashes'));
        $inventoryMigration->up();
        self::assertTrue(Schema::hasTable('par_file_hash_inventories'));
        $inventoryMigration->down();
        $evidenceMigration->down();
        self::assertFalse(Schema::hasTable('par_file_hashes'));
    }

    public function test_upgrade_adds_inventory_markers_without_recreating_existing_evidence(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
        });
        (require database_path('migrations/2026_10_12_010315_create_par_file_hash_evidence_tables.php'))->up();
        Schema::dropIfExists('par_file_hash_inventories');
        DB::table('releases')->insert(['id' => 42]);
        DB::table('par_file_hashes')->insert([
            'releases_id' => 42, 'release_guid' => 'target', 'hash16k' => md5('prefix'),
            'full_md5' => md5('payload'), 'file_size' => '123',
        ]);

        $paths = glob(database_path('migrations/*_create_par_file_hash_inventories_table.php'));
        self::assertCount(1, $paths);
        $migration = require $paths[0];
        $migration->up();
        $migration->up();
        self::assertTrue(Schema::hasTable('par_file_hash_inventories'));
        self::assertSame(0, DB::table('par_file_hash_inventories')->count());
        self::assertSame(1, DB::table('par_file_hashes')->count());
        $migration->down();
        self::assertFalse(Schema::hasTable('par_file_hash_inventories'));
        self::assertSame(1, DB::table('par_file_hashes')->count());
    }
}
