<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('par_file_hash_inventories')) {
            if (! Schema::hasColumn('par_file_hash_inventories', 'proof_version')) {
                Schema::table('par_file_hash_inventories', static function (Blueprint $table): void {
                    // Earlier markers lack complete Main/current-inventory proof.
                    $table->unsignedTinyInteger('proof_version')->default(0);
                });
            }

            return;
        }
        Schema::create('par_file_hash_inventories', static function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('release_guid', 40);
            $table->unsignedBigInteger('evidence_last_id');
            $table->unsignedBigInteger('evidence_count');
            $table->unsignedTinyInteger('proof_version')->default(0);
            // Column presence records ownership across separate migration instances.
            $table->boolean('created_by_inventory_upgrade')->default(true);
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('par_file_hash_inventories')) {
            return;
        }
        if (Schema::hasColumn('par_file_hash_inventories', 'created_by_inventory_upgrade')) {
            Schema::dropIfExists('par_file_hash_inventories');

            return;
        }
        if (Schema::hasColumn('par_file_hash_inventories', 'proof_version')) {
            Schema::table('par_file_hash_inventories', static function (Blueprint $table): void {
                $table->dropColumn('proof_version');
            });
        }
    }
};
