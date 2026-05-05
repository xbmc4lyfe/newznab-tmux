<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the indexes the collections/binaries/parts (CBP) hot paths rely on.
 *
 * Targets:
 *  - parts.number for BinariesService::postdate() which currently full-scans
 *    parts because the table only has PRIMARY KEY (binaries_id, number).
 *  - binaries(collections_id, binaryhash(16)) for the
 *    `binaryhash IN (...) AND collections_id = ?` lookup pattern in
 *    BinaryHandler::selectBinaryRows().
 *  - collections(filecheck, filesize, groups_id) covering the
 *    ReleaseCreationService and runCollectionFileCheckStage* WHERE clauses.
 *  - drops the over-wide ix_binaries_binaryhash(3072) prefix index which is
 *    superseded by the new composite index above.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // parts.number — single-column index so postdate() can do an index
        // lookup on parts.number rather than scanning the full table.
        if (Schema::hasTable('parts') && ! $this->indexExists('parts', 'ix_parts_number')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->index('number', 'ix_parts_number');
            });
        }

        // binaries(collections_id, binaryhash). MySQL needs an explicit prefix
        // because binaryhash is BLOB; the values are UNHEX'd MD5 (16 bytes) so
        // a (16) prefix indexes the full payload. SQLite has no prefix indexes
        // and stores the column as a regular BLOB, so we add the composite
        // without a prefix there.
        if (Schema::hasTable('binaries') && ! $this->indexExists('binaries', 'ix_binaries_collection_hash')) {
            if ($driver === 'sqlite') {
                Schema::table('binaries', function (Blueprint $table) {
                    $table->index(['collections_id', 'binaryhash'], 'ix_binaries_collection_hash');
                });
            } else {
                DB::statement(
                    'CREATE INDEX `ix_binaries_collection_hash` ON `binaries` (`collections_id`, `binaryhash`(16))'
                );
            }
        }

        // The legacy ix_binaries_binaryhash(3072) prefix index is now redundant —
        // every consumer pairs binaryhash with collections_id, which the new
        // composite index serves directly with a much smaller key.
        if (Schema::hasTable('binaries') && $this->indexExists('binaries', 'ix_binaries_binaryhash')) {
            Schema::table('binaries', function (Blueprint $table) {
                $table->dropIndex('ix_binaries_binaryhash');
            });
        }

        // collections(filecheck, filesize, groups_id) — covering index for the
        // hot ReleaseCreationService::createReleases() filter and the
        // ReleaseProcessingService stage queries that filter on filecheck.
        if (Schema::hasTable('collections') && ! $this->indexExists('collections', 'ix_collections_filecheck_filesize_groups')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->index(['filecheck', 'filesize', 'groups_id'], 'ix_collections_filecheck_filesize_groups');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (Schema::hasTable('collections') && $this->indexExists('collections', 'ix_collections_filecheck_filesize_groups')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->dropIndex('ix_collections_filecheck_filesize_groups');
            });
        }

        // Recreate the original ix_binaries_binaryhash if we dropped it. On
        // MySQL it has to be a prefix index because binaryhash is a BLOB.
        if (Schema::hasTable('binaries') && ! $this->indexExists('binaries', 'ix_binaries_binaryhash')) {
            if ($driver === 'sqlite') {
                Schema::table('binaries', function (Blueprint $table) {
                    $table->index('binaryhash', 'ix_binaries_binaryhash');
                });
            } else {
                DB::statement('CREATE INDEX `ix_binaries_binaryhash` ON `binaries` (`binaryhash`(3072))');
            }
        }

        if (Schema::hasTable('binaries') && $this->indexExists('binaries', 'ix_binaries_collection_hash')) {
            Schema::table('binaries', function (Blueprint $table) {
                $table->dropIndex('ix_binaries_collection_hash');
            });
        }

        if (Schema::hasTable('parts') && $this->indexExists('parts', 'ix_parts_number')) {
            Schema::table('parts', function (Blueprint $table) {
                $table->dropIndex('ix_parts_number');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName]
            );

            return $rows !== [];
        }

        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return $rows !== [];
    }
};
