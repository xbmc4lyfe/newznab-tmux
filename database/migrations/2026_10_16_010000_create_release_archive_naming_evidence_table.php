<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('release_archive_naming_evidence')) {
            return;
        }

        Schema::create('release_archive_naming_evidence', static function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('release_guid', 40);
            $table->char('source_sha256', 64);
            $table->unsignedTinyInteger('proof_version');
            $table->string('scope', 24);
            $table->longText('episode_files');
            $table->timestamp('updated_at');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_archive_naming_evidence');
    }
};
