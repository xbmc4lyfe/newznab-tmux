<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a PRE row was first stored, so PREs without a known predate (srrDB, undated RSS) get the
     * same one-day delay before full-text name matching as dated ones.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('predb', 'imported_at')) {
            Schema::table('predb', function (Blueprint $table) {
                $table->dateTime('imported_at')->nullable()->useCurrent();
            });
        }

        // Supports the undated branch of the fix-names eligibility query, next to the existing
        // (searched, predate, id) index used by the dated branch.
        if (! Schema::hasIndex('predb', 'ix_predb_searched_imported_at')) {
            Schema::table('predb', function (Blueprint $table) {
                $table->index(['searched', 'imported_at', 'id'], 'ix_predb_searched_imported_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('predb', 'imported_at')) {
            return;
        }

        if (Schema::hasIndex('predb', 'ix_predb_searched_imported_at')) {
            Schema::table('predb', function (Blueprint $table) {
                $table->dropIndex('ix_predb_searched_imported_at');
            });
        }

        Schema::table('predb', function (Blueprint $table) {
            $table->dropColumn('imported_at');
        });
    }
};
