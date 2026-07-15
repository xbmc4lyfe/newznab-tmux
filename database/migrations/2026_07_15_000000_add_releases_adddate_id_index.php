<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX = 'ix_releases_adddate_id';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->index(['adddate', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('releases')"))
                ->contains(static fn (object $index): bool => $index->name === self::INDEX);
        }

        return DB::select('SHOW INDEX FROM `releases` WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
