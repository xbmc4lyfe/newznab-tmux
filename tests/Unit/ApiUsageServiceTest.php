<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\RecordApiUsage;
use App\Models\User;
use App\Services\Api\ApiUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ApiUsageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge();
        DB::reconnect();
        Cache::flush();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('apiaccess')->nullable();
            $table->string('host')->nullable();
        });
        Schema::create('user_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('users_id');
            $table->text('request');
            $table->dateTime('timestamp');
        });
        DB::table('users')->insert(['id' => 1]);
    }

    public function test_async_record_dispatches_id_only_audit_job(): void
    {
        Queue::fake();
        config(['nntmux.api.async_audit' => true]);
        $user = new User;
        $user->id = 1;
        $request = Request::create('/api/v2/search?api_token=secret&id=test', 'GET');

        (new ApiUsageService)->record($user, $request);

        Queue::assertPushed(RecordApiUsage::class, static fn (RecordApiUsage $job): bool => $job->userId === 1 && $job->requestUri === '/api/v2/search?api_token=secret&id=test'
        );
        $this->assertSame(0, DB::table('user_requests')->count());
    }

    public function test_audit_job_persists_each_request_and_coalesces_user_updates(): void
    {
        config(['nntmux.api.access_update_interval' => 60]);

        (new RecordApiUsage(1, '/api/v2/search?id=one', '192.0.2.1', '2026-07-14 10:00:00'))->handle();
        (new RecordApiUsage(1, '/api/v2/search?id=two', '192.0.2.2', '2026-07-14 10:00:01'))->handle();

        $this->assertSame(2, DB::table('user_requests')->count());
        $this->assertSame('192.0.2.1', DB::table('users')->where('id', 1)->value('host'));
    }
}
