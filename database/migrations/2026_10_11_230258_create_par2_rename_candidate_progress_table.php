<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par2_rename_candidate_progress', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->char('candidate_signature', 64);
            $table->unsignedInteger('next_segment');
            $table->json('retry_segments');
            $table->json('accumulator_state')->nullable();
            $table->unsignedBigInteger('decoded_offset')->default(0);
            $table->json('article_identity')->nullable();
            $table->unsignedInteger('stored_bytes');
            $table->string('blocked_reason', 64)->nullable();
            $table->timestamp('updated_at');
            $table->primary(['releases_id', 'candidate_signature'], 'par2_candidate_progress_primary');
            $table->foreign('releases_id')->references('releases_id')->on('par2_rename_progress')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par2_rename_candidate_progress');
    }
};
