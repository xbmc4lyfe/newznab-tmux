<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PredbImportedAtMigrationTest extends TestCase
{
    #[Test]
    public function new_predb_rows_record_when_they_were_imported(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->unique();
            $table->tinyInteger('searched')->default(0);
        });

        $migration = require database_path('migrations/2026_10_10_000000_add_imported_at_to_predb_table.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('predb', 'ix_predb_searched_imported_at'));

        DB::table('predb')->insert(['title' => 'Fresh-GRP']);
        $this->assertNotNull(DB::table('predb')->where('title', 'Fresh-GRP')->value('imported_at'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('predb', 'imported_at'));
    }
}
