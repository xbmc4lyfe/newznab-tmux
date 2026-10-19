<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partial release a pending generation would upgrade in place, so a generation orphaned by that
 * release's deletion is recognized without reading its manifest; and the release a published candidate
 * retains its inventory for, so expiry finds the few retaining candidates by index even once that release
 * was deleted.
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
        if (! Schema::hasColumn('release_verification_candidates', 'retained_for_release_id')) {
            Schema::table('release_verification_candidates', function (Blueprint $table): void {
                $table->unsignedBigInteger('retained_for_release_id')->nullable();
            });
        }
        // last_unique_at, set only while a candidate retains inventory, so its index holds just those rows
        // in expiry order.
        if (! Schema::hasColumn('release_verification_candidates', 'retained_last_unique_at')) {
            Schema::table('release_verification_candidates', function (Blueprint $table): void {
                $table->dateTime('retained_last_unique_at')->nullable();
            });
        }
        // A separate statement keeps the column additions instant.
        if (! Schema::hasIndex('release_verification_candidates', 'verification_retained_expiry')) {
            Schema::table('release_verification_candidates', function (Blueprint $table): void {
                $table->index('retained_last_unique_at', 'verification_retained_expiry');
            });
        }
    }

    public function down(): void
    {
        Schema::table('release_verification_candidates', function (Blueprint $table): void {
            $table->dropIndex('verification_retained_expiry');
        });
        Schema::table('release_verification_candidates', function (Blueprint $table): void {
            $table->dropColumn('retained_last_unique_at');
        });
        Schema::table('release_verification_candidates', function (Blueprint $table): void {
            $table->dropColumn(['upgrades_release_id', 'retained_for_release_id']);
        });
    }
};
