<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a version admitted as unknown with header defects that no worker or preparer has checked
 * yet, so the header-rejection backfill leaves its first check to the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_verification_candidates', function (Blueprint $table): void {
            $table->boolean('header_check_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('release_verification_candidates', function (Blueprint $table): void {
            $table->dropColumn('header_check_pending');
        });
    }
};
