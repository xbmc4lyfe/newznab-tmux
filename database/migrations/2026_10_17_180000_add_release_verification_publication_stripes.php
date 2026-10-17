<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Publication lock stripes keyed by duplicate-search keys (fingerprint, predb id, names, size),
     * so unrelated publications no longer wait for each other while possible duplicates still do.
     */
    public function up(): void
    {
        DB::table('release_verification_locks')->insertOrIgnore(array_map(
            static fn (int $stripe): array => ['name' => 'publication:'.$stripe], range(0, 1023)));
    }

    public function down(): void
    {
        DB::table('release_verification_locks')->where('name', 'like', 'publication:%')->delete();
    }
};
