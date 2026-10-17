<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The collection run that currently owns a fingerprint record. Overlapping runs (one requested by an
 * in-place partial upgrade) share the processing status, so only the owning run may save its result.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('release_fingerprint_metadata') && ! Schema::hasColumn('release_fingerprint_metadata', 'run_token')) {
            Schema::table('release_fingerprint_metadata', function (Blueprint $table): void {
                $table->char('run_token', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('release_fingerprint_metadata', function (Blueprint $table): void {
            $table->dropColumn('run_token');
        });
    }
};
