<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the unused `nzb_guid` columns.
 *
 * `releases.nzb_guid` was only ever written (md5 of the first NZB segment
 * Message-ID) by NzbService and NzbImportService and never read by any code
 * path. `release_comments.nzb_guid` was fully dead — never written, never
 * read. Dropping both columns and the dedicated `ix_releases_nzb_guid` index
 * removes write overhead with no consumer-facing impact.
 *
 * Rollback caveat: the original `releases.nzb_guid` was `BLOB NOT NULL` with
 * no default. Recreating it with a zero-byte default keeps existing rows from
 * being blocked on `migrate:rollback`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('releases') && $this->indexExists('releases', 'ix_releases_nzb_guid')) {
            Schema::table('releases', function (Blueprint $table) {
                $table->dropIndex('ix_releases_nzb_guid');
            });
        }

        if (Schema::hasTable('releases') && Schema::hasColumn('releases', 'nzb_guid')) {
            Schema::table('releases', function (Blueprint $table) {
                $table->dropColumn('nzb_guid');
            });
        }

        if (Schema::hasTable('release_comments') && Schema::hasColumn('release_comments', 'nzb_guid')) {
            Schema::table('release_comments', function (Blueprint $table) {
                $table->dropColumn('nzb_guid');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (Schema::hasTable('releases') && ! Schema::hasColumn('releases', 'nzb_guid')) {
            if ($driver === 'sqlite') {
                Schema::table('releases', function (Blueprint $table) {
                    $table->binary('nzb_guid')->nullable();
                });
            } else {
                DB::statement(
                    "ALTER TABLE `releases` ADD COLUMN `nzb_guid` BLOB NOT NULL DEFAULT ''"
                );
            }
        }

        if (Schema::hasTable('releases') && ! $this->indexExists('releases', 'ix_releases_nzb_guid')) {
            if ($driver === 'sqlite') {
                Schema::table('releases', function (Blueprint $table) {
                    $table->index('nzb_guid', 'ix_releases_nzb_guid');
                });
            } else {
                DB::statement('CREATE INDEX `ix_releases_nzb_guid` ON `releases` (`nzb_guid`(3072))');
            }
        }

        if (Schema::hasTable('release_comments') && ! Schema::hasColumn('release_comments', 'nzb_guid')) {
            if ($driver === 'sqlite') {
                Schema::table('release_comments', function (Blueprint $table) {
                    $table->binary('nzb_guid')->nullable();
                });
            } else {
                DB::statement(
                    "ALTER TABLE `release_comments` ADD COLUMN `nzb_guid` BINARY(16) NOT NULL DEFAULT '\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0'"
                );
            }
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
