<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private array $indexes = [
        'releases' => [
            'ix_releases_categories_postdate_admin',
            'ix_releases_postdate_admin',
        ],
        'usenet_groups' => [
            'ix_usenet_groups_active_name_admin',
        ],
        'release_reports' => [
            'ix_release_reports_status_created_admin',
        ],
    ];

    public function up(): void
    {
        if (Schema::hasTable('releases') && ! $this->indexExists('releases', 'ix_releases_categories_postdate_admin')) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->index(['categories_id', 'postdate'], 'ix_releases_categories_postdate_admin');
            });
        }

        if (Schema::hasTable('releases') && ! $this->indexExists('releases', 'ix_releases_postdate_admin')) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->index('postdate', 'ix_releases_postdate_admin');
            });
        }

        if (Schema::hasTable('usenet_groups') && ! $this->indexExists('usenet_groups', 'ix_usenet_groups_active_name_admin')) {
            Schema::table('usenet_groups', function (Blueprint $table): void {
                $table->index(['active', 'name'], 'ix_usenet_groups_active_name_admin');
            });
        }

        if (Schema::hasTable('release_reports') && ! $this->indexExists('release_reports', 'ix_release_reports_status_created_admin')) {
            Schema::table('release_reports', function (Blueprint $table): void {
                $table->index(['status', 'created_at'], 'ix_release_reports_status_created_admin');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexNames) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexNames as $indexName) {
                if ($this->indexExists($table, $indexName)) {
                    Schema::table($table, function (Blueprint $table) use ($indexName): void {
                        $table->dropIndex($indexName);
                    });
                }
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            return Schema::hasIndex($table, $indexName);
        } catch (Throwable) {
            if (DB::getDriverName() === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list('{$table}')");

                foreach ($indexes as $index) {
                    if (($index->name ?? null) === $indexName) {
                        return true;
                    }
                }

                return false;
            }
        }

        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($indexes) > 0;
    }
};
