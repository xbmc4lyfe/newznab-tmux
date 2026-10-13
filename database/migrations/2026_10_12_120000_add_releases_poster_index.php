<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX_NAME = 'ix_releases_fromname';

    public function up(): void
    {
        if (! Schema::hasTable('releases') || Schema::hasIndex('releases', self::INDEX_NAME)) {
            return;
        }

        $this->alter(static function (): void {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table = str_replace('`', '``', DB::getTablePrefix().'releases');
                DB::statement('ALTER TABLE `'.$table.'` ADD INDEX '.self::INDEX_NAME.' (`fromname`), ALGORITHM=INPLACE, LOCK=NONE');

                return;
            }

            Schema::table('releases', static function (Blueprint $table): void {
                $table->index('fromname', self::INDEX_NAME);
            });
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('releases') || ! Schema::hasIndex('releases', self::INDEX_NAME)) {
            return;
        }

        $this->alter(static function (): void {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $table = str_replace('`', '``', DB::getTablePrefix().'releases');
                DB::statement('ALTER TABLE `'.$table.'` DROP INDEX '.self::INDEX_NAME.', ALGORITHM=INPLACE, LOCK=NONE');

                return;
            }

            Schema::table('releases', static function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        });
    }

    private function alter(callable $operation): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $operation();

            return;
        }

        $previous = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
        DB::statement('SET SESSION lock_wait_timeout = 30');
        try {
            $operation();
        } finally {
            DB::statement('SET SESSION lock_wait_timeout = '.$previous);
        }
    }
};
