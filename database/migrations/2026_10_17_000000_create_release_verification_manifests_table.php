<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content-addressed verification manifests (gzipped JSON named by the SHA-256 of the JSON),
     * stored in the shared database so any worker host can claim any candidate and so a manifest
     * commits or rolls back with the candidate row that references it.
     */
    public function up(): void
    {
        Schema::create('release_verification_manifests', function (Blueprint $table): void {
            $table->string('name', 80)->primary();
            $table->binary('body');
            $table->unsignedInteger('bytes');
            $table->timestamp('created_at')->useCurrent();
        });
        // Laravel's binary() is a 64 KiB BLOB on MySQL/MariaDB; large releases' manifests exceed it.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE release_verification_manifests MODIFY body LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('release_verification_manifests');
    }
};
