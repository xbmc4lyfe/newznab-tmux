<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Earlier PR snapshots created evidence tables without completeness markers.
        if (Schema::hasTable('par_file_hash_inventories')) {
            return;
        }
        Schema::create('par_file_hash_inventories', static function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('release_guid', 40);
            $table->unsignedBigInteger('evidence_last_id');
            $table->unsignedBigInteger('evidence_count');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_file_hash_inventories');
    }
};
