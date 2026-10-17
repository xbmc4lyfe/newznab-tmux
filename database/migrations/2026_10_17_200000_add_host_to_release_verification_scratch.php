<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scratch directories live on the worker host that created them, so each host inventories,
 * budgets and cleans only its own. Existing rows belong to the primary host ('').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_verification_scratch', function (Blueprint $table): void {
            $table->string('host', 64)->default('');
        });
        Schema::table('release_verification_scratch', function (Blueprint $table): void {
            $table->dropUnique('verification_scratch_candidate_version');
            $table->unique(['host', 'candidate_id', 'version'], 'verification_scratch_host_candidate_version');
            $table->index(['host', 'id'], 'verification_scratch_host_id');
        });
        Schema::table('release_verification_scratch_budget', function (Blueprint $table): void {
            $table->string('host', 64)->default('');
        });
        Schema::table('release_verification_scratch_budget', function (Blueprint $table): void {
            $table->unique('host', 'verification_scratch_budget_host');
        });
    }

    public function down(): void
    {
        // Without host ownership only the primary's records remain meaningful; other hosts'
        // rows could duplicate a candidate version under the restored unique index.
        DB::table('release_verification_scratch')->where('host', '<>', '')->delete();
        DB::table('release_verification_scratch_budget')->where('host', '<>', '')->delete();
        Schema::table('release_verification_scratch_budget', function (Blueprint $table): void {
            $table->dropUnique('verification_scratch_budget_host');
        });
        Schema::table('release_verification_scratch_budget', function (Blueprint $table): void {
            $table->dropColumn('host');
        });
        Schema::table('release_verification_scratch', function (Blueprint $table): void {
            $table->dropIndex('verification_scratch_host_id');
            $table->dropUnique('verification_scratch_host_candidate_version');
            $table->unique(['candidate_id', 'version'], 'verification_scratch_candidate_version');
        });
        Schema::table('release_verification_scratch', function (Blueprint $table): void {
            $table->dropColumn('host');
        });
    }
};
