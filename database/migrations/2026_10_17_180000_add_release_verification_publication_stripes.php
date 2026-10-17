<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One publication lock row per release-size stripe, so publications of different sizes no
     * longer wait for each other while exact duplicates still serialize on a shared stripe.
     */
    public function up(): void
    {
        DB::table('release_verification_locks')->insertOrIgnore(array_map(
            static fn (int $stripe): array => ['name' => 'publication:'.$stripe], range(0, 63)));
    }

    public function down(): void
    {
        DB::table('release_verification_locks')->where('name', 'like', 'publication:%')->delete();
    }
};
