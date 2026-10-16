<?php

use App\Services\Predb\PredbIdentitySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $definitions = (new PredbIdentitySchema)->definitions($driver);
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $engine = DB::table('information_schema.tables')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'predb')->value('ENGINE');
            if (strtoupper((string) $engine) !== 'INNODB') {
                throw new RuntimeException('PreDB identity tracking requires an InnoDB source table');
            }
        }
        if (! Schema::hasTable('predb_identity_state')) {
            Schema::create('predb_identity_state', static function (Blueprint $table): void {
                $table->engine('InnoDB');
                $table->unsignedTinyInteger('id')->primary();
                $table->char('generation', 32);
                $table->unsignedBigInteger('epoch')->default(0);
                $table->unsignedBigInteger('built_epoch')->nullable();
                $table->char('policy_fingerprint', 64)->nullable();
                $table->boolean('ready')->default(false);
                $table->unsignedBigInteger('rebuild_epoch')->nullable();
                $table->unsignedInteger('rebuild_cursor')->default(0);
                $table->unsignedInteger('rebuild_until_id')->nullable();
            });
        }
        if (! Schema::hasTable('predb_identity_revisions')) {
            Schema::create('predb_identity_revisions', static function (Blueprint $table): void {
                $table->engine('InnoDB');
                $table->unsignedInteger('predb_id')->primary();
                $table->unsignedBigInteger('revision');
                $table->boolean('deleted');
                $table->unsignedBigInteger('projected_revision')->nullable();
                $table->char('projected_generation', 32)->nullable();
                $table->index(['revision', 'predb_id'], 'predb_identity_revision_range');
            });
        }
        if (! Schema::hasTable('predb_identity_keys')) {
            Schema::create('predb_identity_keys', static function (Blueprint $table): void {
                $table->engine('InnoDB');
                $table->char('generation', 32);
                $table->unsignedTinyInteger('kind');
                $table->char('key_digest', 64);
                $table->unsignedInteger('predb_id');
                $table->unsignedBigInteger('source_revision');
                $table->primary(['generation', 'kind', 'key_digest', 'predb_id'], 'predb_identity_key_lookup');
                $table->index(['generation', 'predb_id'], 'predb_identity_key_owner');
            });
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (['predb', 'predb_identity_state', 'predb_identity_revisions', 'predb_identity_keys'] as $name) {
                $engine = DB::table('information_schema.tables')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                    ->where('TABLE_NAME', $name)->value('ENGINE');
                if (strtoupper((string) $engine) !== 'INNODB') {
                    throw new RuntimeException('PreDB identity tracking requires an InnoDB table: '.$name);
                }
            }
        }
        // DDL may commit independently. Never preserve readiness across schema installation.
        DB::table('predb_identity_state')->updateOrInsert(['id' => 1], [
            'generation' => bin2hex(random_bytes(16)), 'ready' => false, 'built_epoch' => null,
            'policy_fingerprint' => null, 'rebuild_epoch' => null, 'rebuild_cursor' => 0, 'rebuild_until_id' => null,
        ]);
        foreach ($definitions as $name => $statement) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('predb_identity_state')) {
            DB::table('predb_identity_state')->where('id', 1)->update([
                'ready' => false, 'built_epoch' => null, 'generation' => bin2hex(random_bytes(16)),
            ]);
        }
        foreach (PredbIdentitySchema::TRIGGERS as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        Schema::dropIfExists('predb_identity_keys');
        Schema::dropIfExists('predb_identity_revisions');
        Schema::dropIfExists('predb_identity_state');
    }
};
