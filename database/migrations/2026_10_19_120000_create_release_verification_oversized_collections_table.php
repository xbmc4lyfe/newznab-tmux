<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collections whose headers exceeded the verification metadata budget when archived. Release creation
 * skips them until retry_after, or at once when the budget is raised above the one they failed, instead
 * of reading every part again on each pass. The hash guards against a reused collection id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('release_verification_oversized_collections')) {
            return;
        }
        Schema::create('release_verification_oversized_collections', static function (Blueprint $table): void {
            $table->unsignedBigInteger('collection_id')->primary();
            $table->binary('collectionhash', 20, true);
            $table->unsignedBigInteger('budget_bytes');
            $table->unsignedInteger('attempts')->default(1);
            $table->dateTime('failed_at');
            $table->dateTime('retry_after');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_verification_oversized_collections');
    }
};
