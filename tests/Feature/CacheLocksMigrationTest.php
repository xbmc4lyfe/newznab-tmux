<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CacheLocksMigrationTest extends TestCase
{
    #[Test]
    public function the_database_cache_store_can_acquire_locks_after_migrating(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
        ]);
        DB::purge();
        DB::reconnect();

        (require database_path('migrations/2026_10_09_180000_create_cache_locks_table.php'))->up();

        $this->assertTrue(Schema::hasTable('cache_locks'));
        $store = Cache::store('database')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock('nzb-import-article:abc', 60);
        $this->assertTrue($lock->get());
        $this->assertFalse($store->lock('nzb-import-article:abc', 60)->get());
        $lock->release();
    }
}
