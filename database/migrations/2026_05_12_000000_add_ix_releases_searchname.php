<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('ix_releases_searchname')) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->index('searchname', 'ix_releases_searchname');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('ix_releases_searchname')) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            $table->dropIndex('ix_releases_searchname');
        });
    }

    private function indexExists(string $keyName): bool
    {
        $rows = DB::select('SHOW INDEX FROM `releases` WHERE Key_name = ?', [$keyName]);

        return $rows !== [];
    }
};
