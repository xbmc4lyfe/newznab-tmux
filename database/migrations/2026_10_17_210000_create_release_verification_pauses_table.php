<?php

declare(strict_types=1);

use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A durable admission pause for verification workers on every host. Unlike the barrier's session
 * lock, a pause row survives a lost barrier session and a failed deployment until it is released.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A deployment barrier that ran before this migration has already created it.
        VerificationWorkerLocks::ensurePauseTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('release_verification_pauses');
    }
};
