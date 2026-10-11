<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
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
        foreach (['parts', 'binaries', 'collections', 'usenet_groups', 'releases', 'release_verification_candidates', 'release_verification_state'] as $table) {
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
}
