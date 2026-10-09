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
        if (Schema::hasColumn('predb', 'imported_at')) {
            return;
        }

        Schema::table('predb', function (Blueprint $table) {
            $table->dateTime('imported_at')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('predb', 'imported_at')) {
            return;
        }

        Schema::table('predb', function (Blueprint $table) {
            $table->dropColumn('imported_at');
        });
    }
};
