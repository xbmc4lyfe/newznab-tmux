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
        Schema::create('nzb_storage_imports', static function (Blueprint $table): void {
            $table->string('name', 120)->primary();
            $table->unsignedInteger('last_release_id')->default(0);
            $table->timestamp('updated_at', 6);
        });
        Schema::create('release_nzb_contents', static function (Blueprint $table): void {
            $table->string('guid', 40)->primary();
            $table->unsignedInteger('releases_id')->unique();
            $table->binary('body', length: null);
            $table->char('source_digest', 64);
            $table->char('generation', 32);
            $table->unsignedBigInteger('decoded_bytes');
            $table->timestamps(6);
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        // The framework's generic binary column is BLOB on MySQL; NZBs need LONGBLOB.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE release_nzb_contents MODIFY body LONGBLOB NOT NULL');
        }
        Schema::create('release_fingerprint_jobs', static function (Blueprint $table): void {
            $table->unsignedInteger('release_id')->primary();
            $table->char('source_digest', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at', 6);
            $table->char('lease_token', 32)->nullable();
            $table->dateTime('lease_until', 6)->nullable();
            $table->string('worker_id', 120)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps(6);
            $table->index(['status', 'available_at', 'release_id'], 'fingerprint_jobs_ready');
            $table->index(['status', 'lease_until', 'release_id'], 'fingerprint_jobs_expired');
            $table->index(['status', 'updated_at'], 'fingerprint_jobs_completed');
            $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
        });
        Schema::create('release_fingerprint_connections', static function (Blueprint $table): void {
            $table->string('provider', 64);
            $table->unsignedInteger('slot');
            $table->char('lease_token', 32)->nullable();
            $table->dateTime('lease_until', 6)->nullable();
            $table->string('worker_id', 120)->nullable();
            $table->primary(['provider', 'slot']);
        });
        Schema::create('release_fingerprint_provider_state', static function (Blueprint $table): void {
            $table->string('provider', 64)->primary();
            $table->char('generation', 32)->nullable();
            $table->dateTime('blocked_until', 6)->nullable();
            $table->char('probe_token', 32)->nullable();
            $table->dateTime('probe_until', 6)->nullable();
        });
        DB::table('release_fingerprint_provider_state')->insert(['provider' => 'alternate']);
        // One shared account budget across every Rust host. Other NNTP consumers need the remaining slots.
        foreach (['primary' => 32, 'alternate' => 8] as $provider => $count) {
            for ($slot = 1; $slot <= $count; $slot++) {
                DB::table('release_fingerprint_connections')->insert(['provider' => $provider, 'slot' => $slot]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('release_fingerprint_provider_state');
        Schema::dropIfExists('release_fingerprint_connections');
        Schema::dropIfExists('release_fingerprint_jobs');
        Schema::dropIfExists('release_nzb_contents');
        Schema::dropIfExists('nzb_storage_imports');
    }
};
