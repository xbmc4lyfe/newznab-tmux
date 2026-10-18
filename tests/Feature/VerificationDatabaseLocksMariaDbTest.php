<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\DatabaseVerificationLockStore;
use App\Services\Releases\Verification\VerificationLockStore;
use App\Services\Releases\Verification\VerificationResetService;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Each extra connection stands for a worker process on another host: MariaDB user-level locks
 * belong to the session, exactly as they do across hosts sharing the database.
 */
class VerificationDatabaseLocksMariaDbTest extends TestCase
{
    /** @var list<string> */
    private array $databases = [];

    private ?string $storage = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('VERIFICATION_MARIADB_TEST') !== '1') {
            $this->markTestSkipped('Requires the isolated verification MariaDB test database.');
        }
        $this->storage = storage_path('framework/testing/db-locks-'.bin2hex(random_bytes(6)));
        config(['release_verification.storage_path' => $this->storage,
            'release_verification.candidate_workers' => 2, 'release_verification.locks' => 'auto']);
        $this->fixture('mysql');
        foreach (['verification_lock_other', 'verification_lock_third'] as $name) {
            config(['database.connections.'.$name => config('database.connections.mysql')]);
            DB::purge($name);
        }
        $this->assertInstanceOf(DatabaseVerificationLockStore::class, VerificationLockStore::make());
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_keys(DB::getConnections()) as $name) {
                DB::purge($name);
            }
            foreach ($this->databases as $database) {
                DB::connection('verification_lock_admin')->statement('DROP DATABASE IF EXISTS `'.$database.'`');
            }
            DB::purge('verification_lock_admin');
            // Only the directory this test allocated; a skipped test allocated none.
            if ($this->storage !== null) {
                File::deleteDirectory($this->storage);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_exclusive_names_exclude_other_sessions_and_a_second_holder_in_this_process(): void
    {
        $here = new DatabaseVerificationLockStore;
        $there = new DatabaseVerificationLockStore('verification_lock_other');

        $held = $here->exclusive('candidate-7');
        $this->assertNotNull($held);
        $this->assertNull($there->exclusive('candidate-7'));
        $this->assertNull($here->exclusive('candidate-7'));
        $this->assertNotNull($elsewhere = $there->exclusive('candidate-8'));
        $this->assertTrue($here->holds($held));

        $here->release($held);
        $this->assertFalse($here->holds($held));
        $this->assertNotNull($taken = $there->exclusive('candidate-7'));
        $there->release($taken);
        $there->release($elsewhere);
    }

    public function test_shared_holders_coexist_and_exclude_an_exclusive_holder_until_all_leave(): void
    {
        $first = new DatabaseVerificationLockStore;
        $second = new DatabaseVerificationLockStore('verification_lock_other');
        $exclusive = new DatabaseVerificationLockStore('verification_lock_third');

        $a = $first->shared('worker');
        $b = $second->shared('worker');
        $c = $first->shared('worker');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotNull($c);
        $this->assertNull($exclusive->exclusiveGroup('worker'));
        $first->release($a);
        $first->release($c);
        $this->assertNull($exclusive->exclusiveGroup('worker'));
        $second->release($b);

        $sole = $exclusive->exclusiveGroup('worker');
        $this->assertNotNull($sole);
        $this->assertNull($first->shared('worker'));
        $this->assertNull($second->exclusiveGroup('worker'));
        $this->assertNotNull($reset = $second->exclusiveGroup('reset'));
        $exclusive->release($sole);
        $second->release($reset);
        $this->assertNotNull($again = $first->shared('worker'));
        $first->release($again);
    }

    public function test_an_ended_session_releases_its_locks_and_its_holder_learns_it_lost_them(): void
    {
        $here = new DatabaseVerificationLockStore;
        $there = new DatabaseVerificationLockStore('verification_lock_other');
        $slot = $there->exclusive('worker-3');
        $member = $there->shared('worker');
        $this->assertNotNull($slot);
        $this->assertNotNull($member);
        $this->assertNull($here->exclusive('worker-3'));

        DB::connection('verification_lock_other')->disconnect();

        $this->assertNotNull($taken = $here->exclusive('worker-3'));
        $this->assertNotNull($sole = $here->exclusiveGroup('worker'));
        $this->assertFalse($there->holds($slot));
        $this->assertFalse($there->holds($member));
        $there->release($slot);
        $there->release($member);
        $here->release($taken);
        $here->release($sole);
    }

    public function test_worker_slots_candidates_and_maintenance_are_fenced_across_hosts(): void
    {
        $here = new VerificationWorkerLocks(new DatabaseVerificationLockStore);
        $there = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other'));
        $audit = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_third'));
        try {
            $this->assertTrue($here->start(1));
            $this->assertFalse($there->start(1));
            $this->assertTrue($there->start());
            $this->assertTrue($here->held());
            $this->assertTrue($here->tryCandidate(5));
            $this->assertFalse($there->tryCandidate(5));
            $this->assertTrue($here->protects(5));
            $this->assertFalse($there->protects(5));
            $this->assertTrue($here->tryGc());
            $this->assertFalse($there->tryGc());
            $this->assertFalse($here->preferExpiry());
            $this->assertTrue($here->preferExpiry());
            // Another host cleans its own scratch at the same time, but expiry runs on one host at once.
            config(['release_verification.host' => 'm4']);
            $this->assertTrue($there->tryGc());
            config(['release_verification.host' => '']);
            $this->assertTrue($here->tryExpiry());
            $this->assertFalse($there->tryExpiry());
            $here->releaseGc();
            $this->assertTrue($there->tryExpiry());
            $there->releaseGc();
            $this->assertFalse($audit->tryExclusive());

            $here->close();
            $there->close();
            $this->assertTrue($audit->tryExclusive());
            $this->assertTrue($audit->protects(5));
            $this->assertFalse($here->start(1));
        } finally {
            $here->close();
            $there->close();
            $audit->close();
        }
    }

    public function test_reset_and_admission_exclude_each_other_across_hosts(): void
    {
        $reset = app(VerificationResetService::class);
        $remoteWorker = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other'));
        $called = false;
        try {
            $this->assertTrue($remoteWorker->start());
            try {
                $reset->run(function () use (&$called): void {
                    $called = true;
                });
                $this->fail('A worker on another host must exclude reset.');
            } catch (RuntimeException) {
                $this->assertFalse($called);
            }
        } finally {
            $remoteWorker->close();
        }

        $remote = new DatabaseVerificationLockStore('verification_lock_other');
        $remoteReset = $remote->exclusiveGroup('reset');
        $this->assertNotNull($remoteReset);
        try {
            $reset->withAdmission(static fn () => null);
            $this->fail('A reset on another host must refuse admission.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('retry reset later', $error->getMessage());
        } finally {
            $remote->release($remoteReset);
        }

        DB::beginTransaction();
        try {
            $reset->withAdmission(static fn () => null);
            $this->assertNull($remote->exclusiveGroup('reset'));
        } finally {
            DB::commit();
        }
        $this->assertNotNull($afterCommit = $remote->exclusiveGroup('reset'));
        $remote->release($afterCommit);
    }

    public function test_a_reset_whose_lock_session_ends_stops_instead_of_reconnecting(): void
    {
        (require database_path('migrations/2026_10_17_210000_create_release_verification_pauses_table.php'))->up();
        $continued = false;
        try {
            app(VerificationResetService::class)->run(function () use (&$continued): void {
                $this->killSession(DB::connection());
                DB::selectOne('SELECT 1 AS v');
                $continued = true;
            });
            $this->fail('Reset must not continue on a session without its locks.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Verification lock session was lost', $error->getMessage());
        }
        $this->assertFalse($continued);
        // Its durable pause outlives the session and keeps every host's workers out.
        $this->assertTrue(VerificationWorkerLocks::paused('verification_lock_other'));
        $this->assertFalse((new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other')))->start());

        // The killed session released everything, and the connection reconnects once no lock is held.
        $other = new DatabaseVerificationLockStore('verification_lock_other');
        $this->assertNotNull($reset = $other->exclusiveGroup('reset'));
        $this->assertNotNull($worker = $other->exclusiveGroup('worker'));
        $other->release($reset);
        $other->release($worker);
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS v')->v);
        $this->artisan('releases:verification-barrier', ['--release' => true])->assertSuccessful();
        $this->assertTrue(VerificationWorkerLocks::paused());
        $this->artisan('releases:verification-barrier', ['--release' => true, '--all' => true])->assertSuccessful();
        $this->assertFalse(VerificationWorkerLocks::paused());
    }

    public function test_a_worker_whose_lock_session_ends_learns_it_and_cannot_reconnect_unprotected(): void
    {
        $worker = new VerificationWorkerLocks(new DatabaseVerificationLockStore);
        try {
            $this->assertTrue($worker->start(1));
            $this->assertTrue($worker->tryCandidate(9));
            $this->killSession(DB::connection());

            $this->assertFalse($worker->held());
            $this->assertFalse($worker->protects(9));
            try {
                DB::table('migrations')->count();
                $this->fail('A worker must not reconnect while it believes it holds locks.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Verification lock session was lost', $error->getMessage());
            }
        } finally {
            $worker->close();
        }
        $this->assertNotNull($slot = (new DatabaseVerificationLockStore('verification_lock_other'))->exclusive('worker-1'));
        (new DatabaseVerificationLockStore('verification_lock_other'))->release($slot);
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS v')->v);
    }

    private function killSession(Connection $connection): void
    {
        $session = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        DB::connection('verification_lock_admin')->statement('KILL CONNECTION '.$session);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (DB::connection('verification_lock_admin')->selectOne('SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE ID = ?', [$session])->c == 0) {
                return;
            }
            usleep(20000);
        }
        $this->fail('The killed session did not end.');
    }

    public function test_a_barrier_stops_running_workers_on_other_hosts_and_keeps_new_ones_out(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl to run a worker in another process.');
        }
        config(['database.connections.verification_lock_child' => config('database.connections.mysql')]);
        [$parentEnd, $childEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            // A worker on another host: its own session, never the parent's sockets.
            fclose($parentEnd);
            $worker = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_child'));
            $report = 'unavailable';
            if ($worker->start()) {
                fwrite($childEnd, "started\n");
                $deadline = microtime(true) + 10;
                while ($worker->admitting() && microtime(true) < $deadline) {
                    usleep(50000);
                }
                $report = $worker->admitting() ? 'never-saw-drain' : 'saw-drain';
                $worker->close();
            }
            fwrite($childEnd, $report."\n");
            // Skip every shutdown handler; the killed process ends its own database session.
            posix_kill(getmypid(), SIGKILL);
        }
        fclose($childEnd);
        $barrier = new VerificationWorkerLocks(new DatabaseVerificationLockStore);
        $late = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other'));
        try {
            $this->assertSame("started\n", fgets($parentEnd));
            $this->assertTrue($barrier->drain(10));
            $this->assertSame("saw-drain\n", fgets($parentEnd));
            $this->assertFalse($late->start());
            $this->assertFalse($late->admitting());
            $this->assertTrue($barrier->protects(0));
        } finally {
            $barrier->close();
            pcntl_waitpid($pid, $status);
            fclose($parentEnd);
        }
        $this->assertTrue($late->start());
        $this->assertTrue($late->admitting());
        $late->close();
    }

    public function test_publication_forwarders_are_fenced_by_number_and_hold_the_worker_group_across_hosts(): void
    {
        config(['database.connections.verification_lock_child' => config('database.connections.mysql')]);
        $here = new VerificationWorkerLocks(new DatabaseVerificationLockStore);
        $same = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other'));
        $barrier = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_third'));
        $other = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_child'));
        try {
            $this->assertTrue($here->startForwarder(1));
            $this->assertTrue($here->held());
            $this->assertTrue($here->admitting());
            // The same number on the same host is one process; another number joins.
            $this->assertFalse($same->startForwarder(1));
            $this->assertTrue($same->startForwarder(2));
            // Another host's forwarder with the same number does not collide.
            config(['release_verification.host' => 'm4']);
            $this->assertTrue($other->startForwarder(1));
            config(['release_verification.host' => '']);
            $other->close();
            // A barrier or reset waits for forwarders as for workers, and does not abandon them.
            $this->assertFalse($barrier->tryExclusive());
            $this->assertFalse($barrier->drain(0.5));
            $this->assertTrue($here->admitting());
            $this->assertTrue($here->held());
            $here->close();
            $same->close();
            $this->assertTrue($barrier->tryExclusive());
            $this->assertFalse($here->startForwarder(1));
        } finally {
            $here->close();
            $same->close();
            $other->close();
            $barrier->close();
        }
    }

    public function test_a_barrier_gives_up_and_reopens_admission_while_a_worker_stays_active(): void
    {
        $worker = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other'));
        $barrier = new VerificationWorkerLocks(new DatabaseVerificationLockStore);
        try {
            $this->assertTrue($worker->start());
            $this->assertFalse($barrier->drain(0.5));
            $this->assertTrue($worker->admitting());
            $this->assertTrue($worker->held());
        } finally {
            $worker->close();
            $barrier->close();
        }
    }

    public function test_a_durable_pause_keeps_workers_out_after_the_barrier_session_ends(): void
    {
        (require database_path('migrations/2026_10_17_210000_create_release_verification_pauses_table.php'))->up();
        $worker = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other'));
        $this->assertTrue($worker->start());
        VerificationWorkerLocks::pause('deploy-attempt', 'deployment');
        $this->assertFalse($worker->admitting());
        $worker->close();

        // The barrier's own session is gone, but the pause is durable.
        DB::connection('verification_lock_third')->table('release_verification_pauses')->count();
        DB::purge('verification_lock_third');
        $this->assertFalse($worker->start());
        $this->assertTrue(VerificationWorkerLocks::paused());

        VerificationWorkerLocks::unpause(null, 'deployment');
        $this->assertTrue($worker->start());
        $this->assertTrue($worker->admitting());
        $worker->close();
    }

    public function test_the_barrier_command_pauses_holds_and_releases_every_host_on_sigterm(): void
    {
        // Before the pause migration, as on the first upgrade: the barrier creates the table itself.
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('release_verification_pauses'));
        $connection = config('database.connections.mysql');
        $output = $this->storage.'/barrier.out';
        File::ensureDirectoryExists($this->storage);
        $process = proc_open([PHP_BINARY, base_path('artisan'), 'releases:verification-barrier', '--timeout=10', '--no-interaction'],
            [['file', '/dev/null', 'r'], ['file', $output, 'w'], ['file', $output, 'a']], $pipes, base_path(), [
                'PATH' => (string) getenv('PATH'), 'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->storage.'/unused-config.php',
                'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
                'DB_PASSWORD' => (string) $connection['password'], 'DB_URL' => '', 'DB_SOCKET' => '', 'CACHE_STORE' => 'array',
                'RELEASE_VERIFICATION_LOCKS' => 'database']);
        $this->assertIsResource($process);
        $worker = new VerificationWorkerLocks(new DatabaseVerificationLockStore('verification_lock_other'));
        try {
            $deadline = microtime(true) + 20;
            while (! str_contains((string) @file_get_contents($output), 'barrier held') && microtime(true) < $deadline) {
                $this->assertTrue(proc_get_status($process)['running'], (string) @file_get_contents($output));
                usleep(100000);
            }
            $this->assertStringContainsString('barrier held', (string) file_get_contents($output));
            $this->assertTrue(DB::getSchemaBuilder()->hasTable('release_verification_pauses'));
            $this->assertTrue(VerificationWorkerLocks::paused());
            $this->assertFalse($worker->start());

            proc_terminate($process, SIGTERM);
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(100000);
            }
            $this->assertStringContainsString('barrier released', (string) file_get_contents($output));
            $this->assertFalse(VerificationWorkerLocks::paused());
            $this->assertTrue($worker->start());
        } finally {
            $worker->close();
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }
            proc_close($process);
        }
    }

    public function test_admission_in_an_older_snapshot_still_sees_a_committed_reset_pause(): void
    {
        (require database_path('migrations/2026_10_17_210000_create_release_verification_pauses_table.php'))->up();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame(0, DB::table('release_verification_pauses')->count());
            DB::connection('verification_lock_other')->table('release_verification_pauses')
                ->insert(['token' => 'interrupted-reset', 'reason' => 'reset']);
            $this->assertSame(0, DB::table('release_verification_pauses')->count(), 'The snapshot predates the pause.');
            try {
                app(VerificationResetService::class)->withAdmission(static fn () => null);
                $this->fail('Admission must see the committed reset pause.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('retry reset later', $error->getMessage());
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_lock_names_are_scoped_to_their_database(): void
    {
        $this->fixture('verification_lock_elsewhere');
        $here = new DatabaseVerificationLockStore('mysql');
        $elsewhere = new DatabaseVerificationLockStore('verification_lock_elsewhere');

        $this->assertNotNull($a = $here->exclusive('gc'));
        $this->assertNotNull($b = $elsewhere->exclusive('gc'));
        $here->release($a);
        $elsewhere->release($b);
    }

    private function fixture(string $connection): void
    {
        $endpoint = [
            'driver' => 'mysql', 'url' => null, 'host' => getenv('VERIFICATION_MARIADB_HOST') ?: 'nntmux-verify-db',
            'port' => (int) (getenv('VERIFICATION_MARIADB_PORT') ?: 3306), 'database' => 'mysql',
            'username' => getenv('VERIFICATION_MARIADB_USERNAME') ?: 'root', 'password' => (string) getenv('VERIFICATION_MARIADB_PASSWORD'),
            'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'strict' => false, 'engine' => 'InnoDB', 'options' => [],
        ];
        config(['database.connections.verification_lock_admin' => $endpoint]);
        $database = 'verification_locks_'.bin2hex(random_bytes(10));
        try {
            DB::connection('verification_lock_admin')->statement('CREATE DATABASE `'.$database.'`');
        } catch (Throwable $error) {
            DB::purge('verification_lock_admin');

            throw $error;
        }
        $this->databases[] = $database;
        config(['database.connections.'.$connection => ['database' => $database] + $endpoint]);
        if ($connection === 'mysql') {
            config(['database.default' => 'mysql']);
        }
        DB::purge($connection);
        $this->assertSame($database, DB::connection($connection)->selectOne('SELECT DATABASE() AS name')->name);
    }
}
