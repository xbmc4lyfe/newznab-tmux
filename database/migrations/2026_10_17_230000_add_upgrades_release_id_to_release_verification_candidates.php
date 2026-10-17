<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partial release a pending generation would upgrade in place, so a generation orphaned by that
 * release's deletion is recognized without reading its manifest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('release_verification_candidates', 'upgrades_release_id')) {
            Schema::table('release_verification_candidates', function (Blueprint $table): void {
                $table->unsignedBigInteger('upgrades_release_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('release_verification_candidates', function (Blueprint $table): void {
            $table->dropColumn('upgrades_release_id');
        });
    }
};
