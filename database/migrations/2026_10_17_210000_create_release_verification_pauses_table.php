<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable admission pause for verification workers on every host. Unlike the barrier's session
 * lock, a pause row survives a lost barrier session and a failed deployment until it is released.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_verification_pauses', function (Blueprint $table): void {
            $table->string('token', 64)->primary();
            $table->string('reason', 64);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_verification_pauses');
    }
};
