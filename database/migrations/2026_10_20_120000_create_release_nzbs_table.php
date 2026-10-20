<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Release NZBs (the gzipped document a legacy .nzb.gz file held, byte for byte), stored in the
     * shared database so every host can serve any release and an NZB commits or rolls back with the
     * release transaction that installs it. Deleting a release deletes its NZB.
     */
    public function up(): void
    {
        if (Schema::hasTable('release_nzbs')) {
            return;
        }
        Schema::create('release_nzbs', static function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->binary('body');
            $table->unsignedInteger('bytes');
            // NzbSourceGuard::digest() of the decoded document, as naming evidence records it.
            $table->char('source_sha256', 64);
            $table->timestamp('updated_at')->useCurrent();
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        // Laravel's binary() is a 64 KiB BLOB on MySQL/MariaDB; large releases' NZBs exceed it.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE release_nzbs MODIFY body LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        // With database storage these rows are the only copy of each NZB.
        if (Schema::hasTable('release_nzbs') && DB::table('release_nzbs')->exists()) {
            throw new RuntimeException('release_nzbs holds release NZBs; export or delete them before rolling back this migration.');
        }
        Schema::dropIfExists('release_nzbs');
    }
};
