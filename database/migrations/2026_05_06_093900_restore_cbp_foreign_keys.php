<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('collections') || ! Schema::hasTable('binaries') || ! Schema::hasTable('parts')) {
            return;
        }

        // Remove orphans so FK creation cannot fail on existing bad rows.
        DB::statement(
            'DELETE p FROM parts p LEFT JOIN binaries b ON b.id = p.binaries_id WHERE b.id IS NULL'
        );
        DB::statement(
            'DELETE b FROM binaries b LEFT JOIN collections c ON c.id = b.collections_id WHERE c.id IS NULL'
        );

        $this->dropForeignKeyIfExists('parts', 'FK_binaries');
        $this->dropForeignKeyIfExists('binaries', 'FK_Collections');

        DB::statement(
            'ALTER TABLE `binaries`
                ADD CONSTRAINT `FK_Collections`
                FOREIGN KEY (`collections_id`)
                REFERENCES `collections` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE'
        );

        DB::statement(
            'ALTER TABLE `parts`
                ADD CONSTRAINT `FK_binaries`
                FOREIGN KEY (`binaries_id`)
                REFERENCES `binaries` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('binaries') || ! Schema::hasTable('parts')) {
            return;
        }

        $this->dropForeignKeyIfExists('parts', 'FK_binaries');
        $this->dropForeignKeyIfExists('binaries', 'FK_Collections');
    }

    private function dropForeignKeyIfExists(string $table, string $constraintName): void
    {
        $exists = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_TYPE = "FOREIGN KEY"
               AND TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?',
            [$table, $constraintName]
        );

        if ($exists !== []) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraintName}`");
        }
    }
};
