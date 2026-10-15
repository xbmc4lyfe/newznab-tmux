<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Private research proposal only: no deployment or runtime migration authorized.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfo_cleanup_commits', static function (Blueprint $table): void {
            $table->string('binding', 64)->primary();
            $table->unsignedBigInteger('release_id');
            $table->string('release_guid');
            $table->string('candidate_identity', 40);
            $table->string('stored_digest', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfo_cleanup_commits');
    }
};
