<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('forum_categories', 'depth')) {
            return;
        }

        Schema::table('forum_categories', function (Blueprint $table) {
            // The forum package is removed; its category tree needs only the column (smallint NOT NULL DEFAULT 0).
            $table->smallInteger('depth')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('forum_categories', function (Blueprint $table) {
            $table->dropColumn('depth');
        });
    }
};
