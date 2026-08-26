<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use DateTimeInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeleteUnverifiedUsersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge();
        DB::reconnect();

        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name')->default('web');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('email');
            $table->string('password');
            $table->unsignedInteger('roles_id');
            $table->boolean('verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    #[Test]
    public function it_deletes_only_old_unverified_user_and_freeuser_accounts(): void
    {
        $userRole = $this->createRole('User');
        $freeUserRole = $this->createRole('FreeUser');

        $userId = $this->createUser($userRole, 'old-user');
        $freeUserId = $this->createUser($freeUserRole, 'old-freeuser');

        foreach (['Admin', 'Moderator', 'Disabled', 'Friend', 'Supporter'] as $roleName) {
            $roleId = $this->createRole($roleName);
            $this->createUser($roleId, 'old-'.strtolower($roleName));
        }

        $this->artisan('nntmux:delete-unverified-users')->assertSuccessful();

        $this->assertSoftDeleted('users', ['id' => $userId]);
        $this->assertSoftDeleted('users', ['id' => $freeUserId]);

        foreach (['old-admin', 'old-moderator', 'old-disabled', 'old-friend', 'old-supporter'] as $username) {
            $this->assertDatabaseHas('users', [
                'username' => $username,
                'deleted_at' => null,
            ]);
        }
    }

    #[Test]
    public function it_preserves_ineligible_user_and_freeuser_accounts(): void
    {
        $userRole = $this->createRole('User');
        $freeUserRole = $this->createRole('FreeUser');

        $recentUserId = $this->createUser($userRole, 'recent-user', now()->subDays(2));
        $legacyVerifiedUserId = $this->createUser($userRole, 'legacy-verified-user', verified: true);
        $emailVerifiedFreeUserId = $this->createUser(
            $freeUserRole,
            'email-verified-freeuser',
            emailVerifiedAt: now()->subDays(4),
        );

        $this->artisan('nntmux:delete-unverified-users')->assertSuccessful();

        foreach ([$recentUserId, $legacyVerifiedUserId, $emailVerifiedFreeUserId] as $userId) {
            $this->assertDatabaseHas('users', [
                'id' => $userId,
                'deleted_at' => null,
            ]);
        }
    }

    private function createRole(string $name): int
    {
        return (int) DB::table('roles')->insertGetId([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    private function createUser(
        int $roleId,
        string $username,
        ?DateTimeInterface $createdAt = null,
        bool $verified = false,
        ?DateTimeInterface $emailVerifiedAt = null,
    ): int {
        return (int) DB::table('users')->insertGetId([
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => 'password',
            'roles_id' => $roleId,
            'verified' => $verified,
            'email_verified_at' => $emailVerifiedAt,
            'created_at' => $createdAt ?? now()->subDays(4),
            'updated_at' => now(),
        ]);
    }
}
