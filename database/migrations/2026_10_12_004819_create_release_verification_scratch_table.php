<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_verification_scratch', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedInteger('version');
            $table->unique(['candidate_id', 'version'], 'verification_scratch_candidate_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_verification_scratch');
    }
};
