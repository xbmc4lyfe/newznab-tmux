<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string RELEASES_CLAIM_INDEX = 'ix_releases_add_pp_claim_queue';

    public function up(): void
    {
        if (Schema::hasTable('releases')) {
            Schema::table('releases', function (Blueprint $table): void {
                if (! Schema::hasColumn('releases', 'additional_pp_claimed_at')) {
                    $table->timestamp('additional_pp_claimed_at')->nullable()->after('pp_timeout_count');
                }

                if (! Schema::hasColumn('releases', 'additional_pp_claim_token')) {
                    $table->string('additional_pp_claim_token', 64)->nullable()->after('additional_pp_claimed_at');
                }
            });

            if (! $this->indexExists('releases', self::RELEASES_CLAIM_INDEX)) {
                $this->createAdditionalClaimIndex();
            }
        }

        if (Schema::hasTable('release_files')) {
            $this->ensureReleaseFilesPrimaryKey();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('releases')) {
            if ($this->indexExists('releases', self::RELEASES_CLAIM_INDEX)) {
                Schema::table('releases', function (Blueprint $table): void {
                    $table->dropIndex(self::RELEASES_CLAIM_INDEX);
                });
            }

            Schema::table('releases', function (Blueprint $table): void {
                if (Schema::hasColumn('releases', 'additional_pp_claim_token')) {
                    $table->dropColumn('additional_pp_claim_token');
                }

                if (Schema::hasColumn('releases', 'additional_pp_claimed_at')) {
                    $table->dropColumn('additional_pp_claimed_at');
                }
            });
        }
    }

    private function createAdditionalClaimIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('releases', function (Blueprint $table): void {
                $table->index([
                    'passwordstatus',
                    'haspreview',
                    'nzbstatus',
                    'leftguid',
                    'additional_pp_claimed_at',
                    'postdate',
                ], self::RELEASES_CLAIM_INDEX);
            });

            return;
        }

        DB::statement(
            'CREATE INDEX `'.self::RELEASES_CLAIM_INDEX.'` ON `releases` '.
            '(`passwordstatus`, `haspreview`, `nzbstatus`, `leftguid`, `additional_pp_claimed_at`, `postdate` DESC)'
        );
    }

    private function ensureReleaseFilesPrimaryKey(): void
    {
        if ($this->indexWithColumnsExists('release_files', ['releases_id', 'name'])) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `release_files` ADD PRIMARY KEY (`releases_id`, `name`)');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]) !== [];
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexWithColumnsExists(string $table, array $columns): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
                $indexName = (string) ($index->name ?? '');
                if ($indexName === '') {
                    continue;
                }

                $indexColumns = array_map(
                    static fn (object $row): string => (string) $row->name,
                    DB::select("PRAGMA index_info('{$indexName}')")
                );

                if ($indexColumns === $columns) {
                    return true;
                }
            }

            return false;
        }

        $rows = DB::select("SHOW INDEX FROM `{$table}`");
        $indexes = [];

        foreach ($rows as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = (string) $row->Column_name;
        }

        foreach ($indexes as $indexColumns) {
            ksort($indexColumns);
            if (array_values($indexColumns) === $columns) {
                return true;
            }
        }

        return false;
    }
};
