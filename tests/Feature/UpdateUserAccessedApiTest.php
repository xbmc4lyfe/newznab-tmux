<?php

namespace Tests\Feature;

use App\Events\UserAccessedApi;
use App\Listeners\UpdateUserAccessedApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\TestCase;

final class UpdateUserAccessedApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
    }

    public function test_event_has_ip_property(): void
    {
        // Use a simple stdClass as the event just stores the user reference
        $user = new stdClass;
        $user->id = 1;
        $event = new UserAccessedApi($user, '192.168.1.100');
        $this->assertEquals('192.168.1.100', $event->ip);
        $this->assertSame($user, $event->user);
    }

    public function test_event_ip_is_null_when_not_provided(): void
    {
        $user = new stdClass;
        $user->id = 1;
        $event = new UserAccessedApi($user);
        $this->assertNull($event->ip);
    }

    public function test_listener_is_properly_structured(): void
    {
        // Test that the listener class exists and is properly structured
        $listener = new UpdateUserAccessedApi;
        $this->assertInstanceOf(UpdateUserAccessedApi::class, $listener);
        $this->assertTrue(method_exists($listener, 'handle'));
    }

    public function test_event_stores_user_and_ip_correctly(): void
    {
        $user = new stdClass;
        $user->id = 999;
        $event = new UserAccessedApi($user, '10.0.0.1');
        $this->assertEquals(999, $event->user->id);
        $this->assertEquals('10.0.0.1', $event->ip);
    }

    public function test_listener_updates_api_access_and_host(): void
    {
        $this->createUsersTable();

        DB::table('users')->insert([
            'id' => 1,
            'host' => null,
            'apiaccess' => null,
        ]);

        $user = new stdClass;
        $user->id = 1;

        (new UpdateUserAccessedApi)->handle(new UserAccessedApi($user, '192.168.1.100'));

        $row = DB::table('users')->where('id', 1)->first();

        $this->assertSame('192.168.1.100', $row->host);
        $this->assertNotNull($row->apiaccess);
    }

    public function test_listener_updates_with_a_single_query_without_loading_user_model(): void
    {
        $this->createUsersTable();

        DB::table('users')->insert([
            'id' => 1,
            'host' => null,
            'apiaccess' => null,
        ]);

        $user = new stdClass;
        $user->id = 1;

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new UpdateUserAccessedApi)->handle(new UserAccessedApi($user, '10.0.0.1'));

        $queries = DB::getQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('update ', strtolower((string) $queries[0]['query']));
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('host')->nullable();
            $table->timestamp('apiaccess')->nullable();
        });
    }
}
