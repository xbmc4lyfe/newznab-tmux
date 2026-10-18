<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\CandidateRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * Reading which candidates hold a partial's inventory runs inside every archive and hot-collection
 * pass. It must lock only the releases those candidates belong to, never a range of the pointer
 * index that pointer moves and new partials insert into.
 */
class PartialHolderLocksMariaDbTest extends TestCase
{
    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('VERIFICATION_MARIADB_TEST') !== '1') {
            $this->markTestSkipped('Requires the isolated verification MariaDB test database.');
        }
        $endpoint = [
            'driver' => 'mysql', 'url' => null, 'host' => getenv('VERIFICATION_MARIADB_HOST') ?: 'nntmux-verify-db',
            'port' => (int) (getenv('VERIFICATION_MARIADB_PORT') ?: 3306), 'database' => 'mysql',
            'username' => getenv('VERIFICATION_MARIADB_USERNAME') ?: 'root', 'password' => (string) getenv('VERIFICATION_MARIADB_PASSWORD'),
            'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'strict' => false, 'engine' => 'InnoDB', 'options' => [],
        ];
        config(['database.connections.partial_holder_admin' => $endpoint]);
        $this->database = 'partial_holders_'.bin2hex(random_bytes(10));
        DB::connection('partial_holder_admin')->statement('CREATE DATABASE `'.$this->database.'`');
        foreach (['mysql', 'partial_holder_other'] as $name) {
            config(['database.connections.'.$name => ['database' => $this->database] + $endpoint]);
            DB::purge($name);
        }
        config(['database.default' => 'mysql']);
        Schema::create('releases', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('late_article_candidate_id')->nullable()->index();
        });
        Schema::create('release_verification_candidates', function ($table): void {
            $table->id();
            $table->string('source_identity', 40)->index();
            $table->string('manifest_path')->default('');
            $table->unsignedBigInteger('release_id')->nullable();
            $table->unsignedBigInteger('upgrades_release_id')->nullable();
        });
        // Upload A: its published partial (release 2, held by candidate 60) and a newer pending post.
        // Upload B: its published partial (release 1, held by candidate 50) and a pending post.
        DB::table('releases')->insert([['id' => 1, 'late_article_candidate_id' => 50], ['id' => 2, 'late_article_candidate_id' => 60]]);
        DB::table('release_verification_candidates')->insert([
            ['id' => 50, 'source_identity' => str_repeat('b', 40), 'manifest_path' => 'b', 'release_id' => 1, 'upgrades_release_id' => null],
            ['id' => 60, 'source_identity' => str_repeat('a', 40), 'manifest_path' => 'a', 'release_id' => 2, 'upgrades_release_id' => null],
            ['id' => 120, 'source_identity' => str_repeat('a', 40), 'manifest_path' => 'a2', 'release_id' => null, 'upgrades_release_id' => null],
            ['id' => 125, 'source_identity' => str_repeat('b', 40), 'manifest_path' => 'b2', 'release_id' => null, 'upgrades_release_id' => null],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_keys(DB::getConnections()) as $name) {
                if ($name !== 'partial_holder_admin') {
                    DB::purge($name);
                }
            }
            if ($this->database !== null) {
                DB::connection('partial_holder_admin')->statement('DROP DATABASE IF EXISTS `'.$this->database.'`');
            }
            DB::purge('partial_holder_admin');
        } finally {
            parent::tearDown();
        }
    }

    public function test_reading_holders_neither_blocks_pointer_moves_nor_new_partials_elsewhere(): void
    {
        $other = DB::connection('partial_holder_other');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::beginTransaction();
        $other->beginTransaction();
        try {
            $holders = $this->lockPartialHolders(str_repeat('a', 40));
            $this->assertSame([60], array_map(static fn (object $held): int => (int) $held->id, $holders));

            // Upload B's late articles move its pointer to a new generation, and another partial publishes:
            // both write the top of the pointer index while upload A's holders stay locked.
            $failure = null;
            try {
                $this->assertSame(1, $other->table('releases')->where('id', 1)->where('late_article_candidate_id', 50)
                    ->update(['late_article_candidate_id' => 130]));
                $other->table('releases')->insert(['id' => 3, 'late_article_candidate_id' => 131]);
            } catch (QueryException $error) {
                $failure = $error->getMessage();
            }
            $this->assertNull($failure, 'A pointer write waited behind the holder read: '.$failure);

            // Upload A's own holder stays locked against a concurrent clear.
            $blocked = false;
            try {
                $other->table('releases')->where('id', 2)->update(['late_article_candidate_id' => null]);
            } catch (QueryException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, 'The holder read did not lock the partial it found.');
        } finally {
            $this->rollBack($other);
            DB::rollBack();
        }
    }

    public function test_a_generation_holding_the_inventory_is_found_through_the_release_it_upgrades(): void
    {
        DB::table('release_verification_candidates')->insert(['id' => 140, 'source_identity' => str_repeat('a', 40),
            'manifest_path' => 'a3', 'release_id' => null, 'upgrades_release_id' => 2]);
        DB::table('release_verification_candidates')->where('id', 60)->update(['manifest_path' => '']);
        DB::table('releases')->where('id', 2)->update(['late_article_candidate_id' => 140]);

        DB::transaction(function (): void {
            $this->assertSame([140], array_map(static fn (object $held): int => (int) $held->id, $this->lockPartialHolders(str_repeat('a', 40))));
        });
    }

    /** @return list<object> */
    private function lockPartialHolders(string $sourceIdentity): array
    {
        // As archive() reads them in its own transaction.
        return (fn (string $identity): array => $this->lockPartialHolders($identity, false))->call(app(CandidateRepository::class), $sourceIdentity);
    }

    private function rollBack(Connection $connection): void
    {
        try {
            $connection->rollBack();
        } catch (Throwable) {
            // A lock-wait timeout may already have ended the transaction.
        }
    }
}
