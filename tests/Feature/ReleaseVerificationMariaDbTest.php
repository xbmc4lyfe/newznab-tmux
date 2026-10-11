<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CollectionCleanupService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseVerificationMariaDbTest extends ReleaseVerificationCandidatesTest
{
    protected function configureVerificationDatabase(): void
    {
        if (getenv('VERIFICATION_MARIADB_TEST') !== '1') {
            $this->markTestSkipped('Requires the isolated verification MariaDB test database.');
        }
        config(['database.default' => 'mysql', 'database.connections.mysql.host' => 'nntmux-verify-db',
            'database.connections.mysql.database' => 'verification', 'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => '']);
        DB::purge();
        $this->assertSame('verification', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->dropVerificationTables();
    }

    protected function tearDown(): void
    {
        if (getenv('VERIFICATION_MARIADB_TEST') === '1') {
            $this->dropVerificationTables();
        }
        parent::tearDown();
    }

    private function dropVerificationTables(): void
    {
        foreach (['parts', 'binaries', 'collections', 'usenet_groups', 'releases', 'release_verification_audit_retries', 'release_verification_candidates', 'release_verification_state'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function test_second_connection_cannot_claim_or_publish_while_lease_is_active(): void
    {
        $store = app(ManifestStore::class);
        $manifest = ['identity' => sha1('lease'), 'groups_id' => 1, 'files' => [], 'expected_files' => 0];
        app(CandidateRepository::class)->enqueue($manifest);
        $claim = app(CandidateRepository::class)->claim();
        config(['database.connections.verification_other' => config('database.connections.mysql')]);
        DB::purge('verification_other');
        $row = DB::connection('verification_other')->table('release_verification_candidates')->first();
        $this->assertSame($claim->lease_token, $row->lease_token);
        $this->assertNull(app(CandidateRepository::class)->claim());
        app(CandidateRepository::class)->heartbeat($claim->id, $claim->lease_token);
        $this->assertSame('verifying', DB::connection('verification_other')->table('release_verification_candidates')->value('status'));
    }

    public function test_cleanup_uses_current_reads_for_new_parts_inside_an_older_repeatable_read_snapshot(): void
    {
        DB::table('collections')->insert(['id' => 1, 'collectionhash' => sha1('cleanup-race', true), 'subject' => 'Movie', 'fromname' => 'poster',
            'totalfiles' => 1, 'groups_id' => 1, 'date' => now(), 'filecheck' => 3]);
        DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1, 'binaryhash' => md5('file', true), 'name' => 'movie.mkv', 'totalparts' => 1]);
        DB::table('parts')->insert(['binaries_id' => 1, 'partnumber' => 1, 'messageid' => 'new@example.test', 'size' => 0]);
        config(['database.connections.verification_other' => config('database.connections.mysql')]);
        DB::purge('verification_other');
        $other = DB::connection('verification_other');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $cleanup = app(CollectionCleanupService::class);
            $this->assertSame([], $cleanup->verificationEligibleCollections()->where('id', 1)->pluck('id')->all());
            $other->transaction(static function () use ($other): void {
                $other->table('collections')->where('id', 1)->lockForUpdate()->first();
                $other->table('parts')->where('binaries_id', 1)->update(['size' => 5]);
            });
            $this->assertSame(0, (int) DB::table('parts')->value('size'), 'The consistent read still has the pre-arrival snapshot.');
            $this->assertSame(0, $cleanup->deleteCollectionsAndDescendants([1]));
            $this->assertSame(5, (int) $other->table('parts')->value('size'));
            $this->assertTrue($other->table('collections')->where('id', 1)->exists());
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $other->transaction(static fn () => $other->table('collections')->where('id', 1)->lockForUpdate()->first());
                $this->fail('Header insertion must wait for the deletion guard parent lock.');
            } catch (QueryException $error) {
                $this->assertSame(1205, (int) $error->errorInfo[1]);
            }
        } finally {
            DB::rollBack();
            DB::purge('verification_other');
        }
    }
}
