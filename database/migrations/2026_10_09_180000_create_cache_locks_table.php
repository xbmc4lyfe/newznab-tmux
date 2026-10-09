<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lock table for the database cache store (Cache::lock with CACHE_STORE=database).
     */
    public function up(): void
    {
        if (Schema::hasTable('cache_locks')) {
            return;
        }

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    /**
     * The lock table is shared cache infrastructure (also used by Cache::lock elsewhere) and may have
     * existed before this migration, so it is intentionally left in place on rollback.
     */
    public function down(): void {}
};
