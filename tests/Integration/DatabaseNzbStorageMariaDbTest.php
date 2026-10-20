<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Nzb\DatabaseNzbStore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Run only against a disposable database initialized by the storage migration. */
final class DatabaseNzbStorageMariaDbTest extends TestCase
{
    public function test_real_database_preserves_large_binary_sources_and_atomic_source_changes(): void
    {
        $url = getenv('FINGERPRINT_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('FINGERPRINT_TEST_DATABASE_URL must identify the disposable integration database.');
        }
        config(['database.default' => 'fingerprint_test', 'database.connections.fingerprint_test' => [
            'driver' => 'mariadb', 'url' => preg_replace('/^mysql:/', 'mariadb:', $url), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
        ]]);
        DB::purge('fingerprint_test');
        $id = random_int(50000000, 60000000);
        $release = (object) ['id' => $id, 'guid' => 'integration-'.bin2hex(random_bytes(8))];
        DB::table('releases')->insert(['id' => $id, 'guid' => $release->guid]);
        $xml = '<nzb><!--'.base64_encode(random_bytes(131072)).'--></nzb>';
        $store = app(DatabaseNzbStore::class);
        try {
            DB::transaction(function () use ($store, $release, $xml): void {
                $store->put($release, $xml);
                DB::table('release_fingerprint_jobs')->where('release_id', $release->id)->update(['available_at' => '2099-01-01']);
            });
            $this->assertSame($xml, $store->read($release->guid));
            $body = DB::table('release_nzb_contents')->where('guid', $release->guid)->value('body');
            $this->assertGreaterThan(65535, strlen($body));
            $this->assertSame($xml, gzdecode($body));
            $original = DB::table('release_fingerprint_jobs')->where('release_id', $id)->value('source_digest');
            try {
                DB::transaction(function () use ($store, $release): void {
                    $store->put($release, '<nzb>replacement</nzb>');
                    throw new \RuntimeException('rolled-back replacement');
                });
                $this->fail('Expected rollback.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('rolled-back replacement', $exception->getMessage());
            }
            $this->assertSame($xml, $store->read($release->guid));
            $this->assertSame($original, DB::table('release_fingerprint_jobs')->where('release_id', $id)->value('source_digest'));
            DB::table('releases')->where('id', $id)->delete();
            $this->assertFalse(DB::table('release_nzb_contents')->where('guid', $release->guid)->exists());
            $this->assertFalse(DB::table('release_fingerprint_jobs')->where('release_id', $id)->exists());
        } finally {
            DB::table('releases')->where('id', $id)->delete();
        }
    }
}
