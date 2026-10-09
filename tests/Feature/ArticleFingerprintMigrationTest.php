<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArticleFingerprintMigrationTest extends TestCase
{
    #[Test]
    public function rerunning_the_migration_adds_a_missing_index_to_an_existing_column(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->char('article_fingerprint', 40)->nullable();
        });

        $migration = require database_path('migrations/2026_10_09_170000_add_article_fingerprint_to_releases_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex('releases', 'ix_releases_article_fingerprint'));

        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('releases', 'article_fingerprint'));
    }
}
