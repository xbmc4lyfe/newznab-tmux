<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string RELEASES_NZB_CLAIM_INDEX = 'ix_releases_nzb_creation_queue';

    public function up(): void
    {
        if (! Schema::hasTable('releases')) {
            return;
        }

        Schema::table('releases', function (Blueprint $table): void {
            if (! Schema::hasColumn('releases', 'nzb_creation_claimed_at')) {
                $table->timestamp('nzb_creation_claimed_at')->nullable()->after('nzbstatus');
            }

            if (! Schema::hasColumn('releases', 'nzb_creation_claim_token')) {
                $table->string('nzb_creation_claim_token', 64)->nullable()->after('nzb_creation_claimed_at');
            }

            if (! Schema::hasColumn('releases', 'nzb_creation_attempts')) {
                $table->unsignedSmallInteger('nzb_creation_attempts')->default(0)->after('nzb_creation_claim_token');
            }

            if (! Schema::hasColumn('releases', 'nzb_creation_last_error')) {
                $table->text('nzb_creation_last_error')->nullable()->after('nzb_creation_attempts');
            }
        });

        if (! $this->indexExists('releases', self::RELEASES_NZB_CLAIM_INDEX)) {
            $this->createNzbCreationClaimIndex();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('releases')) {
            return;
        }

        if ($this->indexExists('releases', self::RELEASES_NZB_CLAIM_INDEX)) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->dropIndex(self::RELEASES_NZB_CLAIM_INDEX);
            });
        }

        Schema::table('releases', function (Blueprint $table): void {
            if (Schema::hasColumn('releases', 'nzb_creation_last_error')) {
                $table->dropColumn('nzb_creation_last_error');
            }

            if (Schema::hasColumn('releases', 'nzb_creation_attempts')) {
                $table->dropColumn('nzb_creation_attempts');
            }

            if (Schema::hasColumn('releases', 'nzb_creation_claim_token')) {
                $table->dropColumn('nzb_creation_claim_token');
            }

            if (Schema::hasColumn('releases', 'nzb_creation_claimed_at')) {
                $table->dropColumn('nzb_creation_claimed_at');
            }
        });
    }

    private function createNzbCreationClaimIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('releases', function (Blueprint $table): void {
                $table->index([
                    'nzbstatus',
                    'groups_id',
                    'leftguid',
                    'nzb_creation_claimed_at',
                    'postdate',
                ], self::RELEASES_NZB_CLAIM_INDEX);
            });

            return;
        }

        DB::statement(
            'CREATE INDEX `'.self::RELEASES_NZB_CLAIM_INDEX.'` ON `releases` '.
            '(`nzbstatus`, `groups_id`, `leftguid`, `nzb_creation_claimed_at`, `postdate` DESC)'
        );
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
};
