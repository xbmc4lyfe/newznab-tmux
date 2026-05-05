<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2 / OPT-IN: shrink binaries.binaryhash from BLOB to BINARY(16).
 *
 * binaryhash holds an UNHEX'd MD5 (16 bytes) but is stored as BLOB with a
 * 3072-byte prefix index. Switching to BINARY(16) lets us index the full
 * column with a much smaller key and avoids the TOAST-style blob overhead.
 *
 * THIS IS A COLUMN-TYPE CHANGE on a hot, very large table. It is gated
 * behind the env flag `RUN_PHASE2_BINARIES_HASH_SHRINK` so a normal `php
 * artisan migrate` run on production does NOT touch the table. Recommended
 * production procedure:
 *
 *   1. Confirm the 2026_05_05_*_add_cbp_query_indexes migration has been
 *      applied so ix_binaries_collection_hash already serves the hot lookup.
 *   2. Use pt-online-schema-change (Percona) or gh-ost to rewrite the table:
 *
 *        pt-online-schema-change \
 *          --alter "MODIFY binaryhash BINARY(16) NOT NULL DEFAULT '\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0', \
 *                   DROP INDEX ix_binaries_collection_hash, \
 *                   ADD INDEX ix_binaries_collection_hash (collections_id, binaryhash)" \
 *          --execute D=nntmux,t=binaries
 *
 *   3. Once the online tool has finished and traffic looks healthy, set
 *      RUN_PHASE2_BINARIES_HASH_SHRINK=1 in .env and run `php artisan
 *      migrate` so this migration records the change in the migrations
 *      table without re-issuing the ALTER (the up() body is a no-op when
 *      the column type already matches).
 *
 * On smaller deployments (dev, staging, fresh installs) you can simply set
 * RUN_PHASE2_BINARIES_HASH_SHRINK=1 before the first `php artisan migrate`
 * and let this migration issue the ALTER inline — that path is fine when the
 * table is small.
 *
 * SQLite: skipped entirely (column type changes are awkward on SQLite and
 * the test schema already declares the column as BLOB without a prefix
 * index, so there is nothing to shrink).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! self::isEnabled()) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('binaries')) {
            return;
        }

        if ($this->columnIsBinary16('binaries', 'binaryhash')) {
            // Already converted out-of-band (e.g. via pt-online-schema-change).
            // Nothing to do; we just need to record the migration as run.
            return;
        }

        // In-place ALTER. Acceptable for small DBs only — production should
        // have used pt-online-schema-change before flipping the env flag.
        DB::statement(
            "ALTER TABLE `binaries` MODIFY `binaryhash` BINARY(16) NOT NULL DEFAULT '\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0'"
        );

        // Recreate the composite index now that the column can be indexed in full.
        if ($this->indexExists('binaries', 'ix_binaries_collection_hash')) {
            DB::statement('ALTER TABLE `binaries` DROP INDEX `ix_binaries_collection_hash`');
        }
        DB::statement('CREATE INDEX `ix_binaries_collection_hash` ON `binaries` (`collections_id`, `binaryhash`)');
    }

    public function down(): void
    {
        if (! self::isEnabled()) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('binaries')) {
            return;
        }

        // Revert to the original BLOB column type. This too should be done
        // via pt-online-schema-change on production-sized tables.
        DB::statement(
            "ALTER TABLE `binaries` MODIFY `binaryhash` BLOB NOT NULL DEFAULT '\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0'"
        );

        if ($this->indexExists('binaries', 'ix_binaries_collection_hash')) {
            DB::statement('ALTER TABLE `binaries` DROP INDEX `ix_binaries_collection_hash`');
        }
        // BLOB requires a prefix length on MySQL.
        DB::statement('CREATE INDEX `ix_binaries_collection_hash` ON `binaries` (`collections_id`, `binaryhash`(16))');
    }

    private static function isEnabled(): bool
    {
        // Read directly from the process environment rather than env(): this
        // migration runs out-of-band from a normal request lifecycle and the
        // user is meant to flip the flag immediately before invoking
        // `php artisan migrate`. config() helpers would require a dedicated
        // config entry just for one optional flag.
        $value = getenv('RUN_PHASE2_BINARIES_HASH_SHRINK');

        return $value === '1' || strtolower((string) $value) === 'true';
    }

    private function columnIsBinary16(string $table, string $column): bool
    {
        $rows = DB::select(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if ($rows === []) {
            return false;
        }

        $row = (array) $rows[0];

        return strtolower((string) ($row['DATA_TYPE'] ?? $row['data_type'] ?? '')) === 'binary'
            && (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? $row['character_maximum_length'] ?? 0) === 16;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return $rows !== [];
    }
};
