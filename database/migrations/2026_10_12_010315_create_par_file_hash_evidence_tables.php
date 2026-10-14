<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('par_file_hashes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->string('release_guid', 40);
            $table->char('hash16k', 32);
            $table->char('full_md5', 32);
            $table->string('file_size', 20);
            $table->unique(['releases_id', 'release_guid', 'hash16k', 'full_md5', 'file_size'], 'par_file_hashes_identity');
            $table->index(['full_md5', 'file_size', 'releases_id'], 'par_file_hashes_content');
            $table->index(['releases_id', 'id'], 'par_file_hashes_cursor');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        Schema::create('par2_hash_retry', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('release_guid', 40);
            $table->timestamp('retry_at');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        Schema::create('par_file_hash_inventories', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('release_guid', 40);
            $table->unsignedBigInteger('evidence_last_id');
            $table->unsignedBigInteger('evidence_count');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('par2_hash_retry');
        Schema::dropIfExists('par_file_hash_inventories');
        Schema::dropIfExists('par_file_hashes');
    }
};
