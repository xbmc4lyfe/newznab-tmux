<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named rows that verification writers lock to serialize one kind of work without holding the
     * shared capacity row, such as publications that must see each other's releases.
     */
    public function up(): void
    {
        Schema::create('release_verification_locks', function (Blueprint $table): void {
            $table->string('name', 32)->primary();
        });
        DB::table('release_verification_locks')->insert(['name' => 'publication']);
    }

    public function down(): void
    {
        Schema::dropIfExists('release_verification_locks');
    }
};
