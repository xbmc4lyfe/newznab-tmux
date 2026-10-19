<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class VerificationReadyQueueMariaDbTest extends TestCase
{
    private ?PDO $administrator = null;

    private string $database = '';

    private bool $databaseCreated = false;

    private string $directory = '';

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['database.default', 'database.connections.ready_qualification', 'cache.default'] as $key) {
            $this->originalConfig[$key] = config($key);
        }
        $host = (string) getenv('PAR2_DONOR_TEST_HOST');
        if ($host === '') {
            $this->markTestSkipped('Set PAR2_DONOR_TEST_HOST to an isolated MariaDB test server.');
        }
        try {
            $port = (int) (getenv('PAR2_DONOR_TEST_PORT') ?: 3306);
            $username = (string) (getenv('PAR2_DONOR_TEST_USERNAME') ?: 'root');
            $password = (string) getenv('PAR2_DONOR_TEST_PASSWORD');
            $this->administrator = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password);
            $this->database = 'nntmux_ready_test_'.bin2hex(random_bytes(8));
            $this->administrator->exec('CREATE DATABASE `'.$this->database.'`');
            $this->databaseCreated = true;
            $this->administrator->exec('USE `'.$this->database.'`');
            $this->administrator->exec($this->app->make('files')->get(database_path('schema/mariadb-schema.sql')));
            config([
                'database.default' => 'ready_qualification',
                'database.connections.ready_qualification' => [
                    'driver' => 'mysql', 'host' => $host, 'port' => $port,
                    'database' => $this->database, 'username' => $username, 'password' => $password,
                    'url' => null, 'unix_socket' => '', 'prefix' => '', 'strict' => true,
                    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                ],
                'cache.default' => 'array',
            ]);
            DB::purge('ready_qualification');
            DB::statement('SET SESSION max_statement_time = 60');
            if (Artisan::call('migrate', ['--database' => 'ready_qualification', '--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Could not migrate owned queue fixture: '.Artisan::output());
            }
            DB::table('root_categories')->insertOrIgnore(['id' => 2000, 'title' => 'Movies']);
            DB::table('categories')->insertOrIgnore(['id' => 2040, 'title' => 'HD', 'root_categories_id' => 2000]);
            DB::table('usenet_groups')->insertOrIgnore(['id' => 1, 'name' => 'alt.binaries.donor.test']);
            (new ConfigurationSeeder)->run();
            $this->directory = storage_path('framework/testing/ready-mariadb-'.bin2hex(random_bytes(8)));
            config(['release_verification.storage_path' => $this->directory]);
        } catch (\Throwable $exception) {
            $this->cleanupDatabase();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupDatabase();
            if ($this->directory !== '') {
                app('files')->deleteDirectory($this->directory);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupDatabase(): void
    {
        try {
            if ($this->databaseCreated && $this->administrator !== null && preg_match('/^nntmux_ready_test_[a-f0-9]{16}$/D', $this->database) === 1) {
                DB::purge('ready_qualification');
                $this->administrator->exec('DROP DATABASE `'.$this->database.'`');
                $this->databaseCreated = false;
            }
        } finally {
            config($this->originalConfig);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function transitions(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach (['claim', 'replay', 'expire', 'publish', 'backoff', 'path'] as $transition) {
                yield $isolation.':'.$transition => [$isolation, $transition];
            }
        }
    }

    #[DataProvider('transitions')]
    public function test_preparation_compare_and_swap_preserves_committed_independent_transitions(string $isolation, string $transition): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        app(CandidateRepository::class)->enqueue(['identity' => 'race', 'groups_id' => 1, 'expected_files' => 1,
            'files' => [['key' => 'movie', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'race@article', 'bytes' => 5]]]]]);
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $other = new PDO('mysql:host='.getenv('PAR2_DONOR_TEST_HOST').';port='.(getenv('PAR2_DONOR_TEST_PORT') ?: 3306).';dbname='.$this->database.';charset=utf8mb4',
            (string) (getenv('PAR2_DONOR_TEST_USERNAME') ?: 'root'), (string) getenv('PAR2_DONOR_TEST_PASSWORD'));
        $updates = [
            'claim' => "status = 'verifying', lease_token = 'other', lease_until = DATE_ADD(NOW(), INTERVAL 1 HOUR)",
            'replay' => 'version = version + 1',
            'expire' => "status = 'expired', manifest_path = ''",
            'publish' => 'release_id = 123',
            'backoff' => 'next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)',
            'path' => "manifest_path = CONCAT(REPEAT('a', 64), '.json.gz')",
        ];
        $once = false;
        DB::listen(static function (QueryExecuted $query) use (&$once, $other, $updates, $transition): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'manifest_path') && str_contains($query->sql, 'order by')) {
                $once = true;
                $other->beginTransaction();
                $other->exec('UPDATE release_verification_candidates SET '.$updates[$transition]);
                $other->commit();
            }
        });
        try {
            $this->assertSame(0, app(CandidatePreparationService::class)->prepare(1, 30));
            $this->assertTrue($once);
            $this->assertNotSame('ready', DB::table('release_verification_candidates')->value('status'));
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function waitingTransitions(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach (['claim', 'replay', 'expire', 'publish', 'backoff', 'path', 'token', 'lease', 'lease_boundary', 'status'] as $transition) {
                yield $isolation.':'.$transition => [$isolation, $transition];
            }
        }
    }

    #[DataProvider('waitingTransitions')]
    public function test_waiting_batch_preserves_independent_transition_and_counts_only_matching_rows(string $isolation, string $transition): void
    {
        $this->freezeTime();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        $this->insertWaitingCandidates([1, 2, 3]);
        DB::table('release_verification_candidates')->where('id', 2)->update(['lease_until' => now()->subSecond()]);
        $other = $this->independentConnection();
        $updates = [
            'claim' => "status = 'verifying', lease_token = 'other', lease_until = DATE_ADD(NOW(), INTERVAL 1 HOUR)",
            'replay' => 'version = version + 1',
            'expire' => "status = 'expired', manifest_path = ''",
            'publish' => 'release_id = 123',
            'backoff' => 'next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)',
            'path' => "manifest_path = CONCAT(REPEAT('a', 64), '.json.gz')",
            'token' => "lease_token = 'other'",
            'lease' => 'lease_until = DATE_ADD(NOW(), INTERVAL 1 HOUR)',
            'lease_boundary' => "lease_until = '".now()->format('Y-m-d H:i:s')."'",
            'status' => "status = 'unknown'",
        ];
        $once = false;
        $committed = null;
        $batchUpdates = [];
        DB::listen(static function (QueryExecuted $query) use (&$once, &$committed, &$batchUpdates, $other, $updates, $transition): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'manifest_path') && str_contains($query->sql, 'order by')) {
                $once = true;
                $other->beginTransaction();
                $other->exec('UPDATE release_verification_candidates SET '.$updates[$transition].' WHERE id = 1');
                $other->commit();
                $committed = $other->query('SELECT * FROM release_verification_candidates WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            }
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'release_verification_candidates')) {
                $batchUpdates[] = $query;
            }
        });
        $report = null;
        try {
            $changed = app(CandidatePreparationService::class)->prepare(3, 30, observer: static function (array $diagnostics) use (&$report): void {
                $report = $diagnostics;
            });
            $this->assertTrue($once);
            $this->assertSame(2, $changed);
            $this->assertCount(1, $batchUpdates);
            $this->assertStringContainsString('`id` in (', $batchUpdates[0]->sql);
            $this->assertSame($committed, $other->query('SELECT * FROM release_verification_candidates WHERE id = 1')->fetch(PDO::FETCH_ASSOC));
            $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'waiting_upload')->count());
            $this->assertSame(3, $report['assessments']['waiting_upload']);
            $this->assertSame(2, $report['updated']['waiting_upload']);
            $this->assertSame(1, $report['zero_row_updates']);
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function isolationLevels(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            yield $isolation => [$isolation];
        }
    }

    #[DataProvider('isolationLevels')]
    public function test_waiting_batch_reports_every_compare_and_swap_miss_when_no_rows_match(string $isolation): void
    {
        $this->freezeTime();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        $this->insertWaitingCandidates([1, 2, 3]);
        $other = $this->independentConnection();
        $once = false;
        $updates = 0;
        DB::listen(static function (QueryExecuted $query) use (&$once, &$updates, $other): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'manifest_path') && str_contains($query->sql, 'order by')) {
                $once = true;
                $other->beginTransaction();
                $other->exec('UPDATE release_verification_candidates SET version = version + 1');
                $other->commit();
            }
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'release_verification_candidates')) {
                $updates++;
            }
        });
        $report = null;
        try {
            $this->assertSame(0, app(CandidatePreparationService::class)->prepare(3, 30, observer: static function (array $diagnostics) use (&$report): void {
                $report = $diagnostics;
            }));
            $this->assertTrue($once);
            $this->assertSame(0, $updates);
            $this->assertSame(3, DB::table('release_verification_candidates')->where('status', 'pending')->where('version', 2)->count());
            $this->assertSame(0, $report['updated']['waiting_upload']);
            $this->assertSame(3, $report['zero_row_updates']);
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    }

    #[DataProvider('isolationLevels')]
    public function test_waiting_batches_follow_caller_transaction_rollback(string $isolation): void
    {
        $this->freezeTime();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        $this->insertWaitingCandidates(range(1, 26));
        $other = $this->independentConnection();
        DB::beginTransaction();
        try {
            $this->assertSame(26, app(CandidatePreparationService::class)->prepare(26, 30));
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame(26, DB::table('release_verification_candidates')->where('status', 'waiting_upload')->count());
            $this->assertSame(26, (int) $other->query("SELECT COUNT(*) FROM release_verification_candidates WHERE status = 'pending'")->fetchColumn());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(26, DB::table('release_verification_candidates')->where('status', 'pending')->count());
    }

    #[DataProvider('isolationLevels')]
    public function test_waiting_batch_skips_real_invalidation_lock_and_recovers_on_next_pass(string $isolation): void
    {
        $this->freezeTime();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        $this->insertWaitingCandidates([1, 2]);
        foreach ([10 => 2, 20 => 1] as $collectionId => $candidateId) {
            $identity = sha1('invalidation:'.$collectionId);
            DB::table('collections')->insert(['id' => $collectionId, 'collectionhash' => hex2bin($identity),
                'subject' => 'Movie', 'fromname' => 'poster', 'totalfiles' => 2, 'groups_id' => 1, 'date' => now(), 'filecheck' => 3]);
            DB::table('release_verification_candidates')->where('id', $candidateId)->update(['source_identity' => $identity]);
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
        $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $payload = ['base_path' => base_path(), 'directory' => $this->directory,
            'connection' => config('database.connections.ready_qualification'), 'parent_id' => $parentId, 'isolation' => $isolation];
        $payloadFile = $this->directory.'/invalidation-config.json';
        file_put_contents($payloadFile, json_encode($payload, JSON_THROW_ON_ERROR));
        chmod($payloadFile, 0600);
        file_put_contents($this->directory.'/child.env', '');
        $script = $this->directory.'/invalidation-child.php';
        file_put_contents($script, <<<'PHP'
<?php
$config = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->useEnvironmentPath($config['directory']);
$app->loadEnvironmentFrom('child.env');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => $config['connection'],
    'cache.default' => 'array', 'release_verification.storage_path' => $config['directory']]);
Illuminate\Support\Facades\DB::purge('mysql');
Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$config['isolation']);
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
$childId = (int) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$connection = $config['connection'];
$monitor = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'].';charset=utf8mb4',
    $connection['username'], $connection['password']);
$first = true;
$outcome = ['status' => 'running', 'connection_id' => $childId, 'lock_queries' => [], 'wait_graph' => []];
Illuminate\Support\Facades\DB::listen(static function (Illuminate\Database\Events\QueryExecuted $query) use (&$first, &$outcome, $config, $monitor, $childId): void {
    if (! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'release_verification_candidates') || ! str_contains($query->sql, 'for update')) {
        return;
    }
    $outcome['lock_queries'][] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    if (! $first) {
        return;
    }
    $first = false;
    file_put_contents($config['directory'].'/invalidation-ready', (string) $childId);
    $deadline = microtime(true) + 15;
    do {
        $statement = $monitor->prepare('SELECT requested.lock_table AS requesting_table, requested.lock_index AS requesting_index,
            requested.lock_data AS requesting_record, held.lock_table AS blocking_table, held.lock_index AS blocking_index,
            held.lock_data AS blocking_record, requester.trx_mysql_thread_id AS requesting_thread,
            blocker.trx_mysql_thread_id AS blocking_thread, requester.trx_query AS requesting_query
            FROM information_schema.INNODB_LOCK_WAITS AS waits
            JOIN information_schema.INNODB_LOCKS AS requested ON requested.lock_id = waits.requested_lock_id
            JOIN information_schema.INNODB_LOCKS AS held ON held.lock_id = waits.blocking_lock_id
            JOIN information_schema.INNODB_TRX AS requester ON requester.trx_id = waits.requesting_trx_id
            JOIN information_schema.INNODB_TRX AS blocker ON blocker.trx_id = waits.blocking_trx_id
            WHERE requester.trx_mysql_thread_id = ? AND blocker.trx_mysql_thread_id = ?');
        $statement->execute([$config['parent_id'], $childId]);
        $outcome['wait_graph'] = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($outcome['wait_graph'] !== [] || is_file($config['directory'].'/preparation-returned')) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Preparation neither returned nor produced the owned candidate lock wait.');
});
try {
    Illuminate\Support\Facades\DB::transaction(static function () use ($app): void {
        $app->make(App\Services\Releases\Verification\CandidateRepository::class)->invalidateHotCollections([10, 20]);
    });
    $outcome['status'] = 'committed';
} catch (Throwable $failure) {
    $outcome['status'] = 'failed';
    $outcome['error'] = $failure instanceof Illuminate\Database\QueryException ? $failure->errorInfo : $failure->getMessage();
} finally {
    file_put_contents($config['directory'].'/invalidation-result.json', json_encode($outcome, JSON_THROW_ON_ERROR));
}
PHP);
        $connection = $payload['connection'];
        $environment = ['PATH' => (string) getenv('PATH'), 'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $this->directory.'/unused-config.php', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'DB_SOCKET' => '', 'CACHE_STORE' => 'array'];
        $stdout = $this->directory.'/child-stdout';
        $stderr = $this->directory.'/child-stderr';
        $process = proc_open([PHP_BINARY, $script, $payloadFile], [['file', '/dev/null', 'r'], ['file', $stdout, 'w'], ['file', $stderr, 'w']],
            $pipes, base_path(), $environment);
        $this->assertIsResource($process);
        $report = null;
        $changed = null;
        $parentError = null;
        try {
            $this->waitForChildFile($this->directory.'/invalidation-ready', $process, $stderr);
            try {
                $changed = app(CandidatePreparationService::class)->prepare(2, 30, observer: static function (array $diagnostics) use (&$report): void {
                    $report = $diagnostics;
                });
            } catch (QueryException $failure) {
                $parentError = $failure->errorInfo;
            } finally {
                file_put_contents($this->directory.'/preparation-returned', 'returned');
            }
            $this->waitForChildFile($this->directory.'/invalidation-result.json', $process, $stderr);
            $child = json_decode(file_get_contents($this->directory.'/invalidation-result.json'), true, flags: JSON_THROW_ON_ERROR);
            $evidence = json_encode(['parent_error' => $parentError, 'parent_changed' => $changed, 'child' => $child], JSON_THROW_ON_ERROR);
            $this->assertNull($parentError, $evidence);
            $this->assertSame('committed', $child['status'], $evidence);
            // Per collection: its candidates, then the partial holders' pointer read (both locking, same rows first).
            $this->assertCount(4, $child['lock_queries'], $evidence);
            $this->assertSame([], $child['wait_graph'], $evidence);
            $this->assertSame(1, $changed, $evidence);
            $this->assertSame(1, $report['updated']['waiting_upload']);
            $this->assertSame(1, $report['zero_row_updates']);
            $this->assertSame('waiting_upload', DB::table('release_verification_candidates')->where('id', 1)->value('status'));
            $this->assertSame('pending', DB::table('release_verification_candidates')->where('id', 2)->value('status'));
            $this->assertSame(1, app(CandidatePreparationService::class)->prepare(2, 30));
            $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'waiting_upload')->count());
        } finally {
            file_put_contents($this->directory.'/preparation-returned', 'returned');
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    /** @param resource $process */
    private function waitForChildFile(string $path, $process, string $stderr): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path)) {
            if (! proc_get_status($process)['running']) {
                throw new RuntimeException('Owned invalidation child exited before its barrier: '.file_get_contents($stderr));
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Owned invalidation child timed out before its barrier: '.file_get_contents($stderr));
            }
            usleep(20000);
        }
    }

    public function test_waiting_batch_skips_locked_rows_without_losing_committed_prefix_and_recovers(): void
    {
        $this->freezeTime();
        $this->insertWaitingCandidates(range(1, 50));
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $other = $this->independentConnection();
        $other->beginTransaction();
        $other->query('SELECT id FROM release_verification_candidates WHERE id = 26 FOR UPDATE')->fetchColumn();
        $report = null;
        try {
            $this->assertSame(49, app(CandidatePreparationService::class)->prepare(50, 30, observer: static function (array $diagnostics) use (&$report): void {
                $report = $diagnostics;
            }));
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(49, (int) $other->query("SELECT COUNT(*) FROM release_verification_candidates WHERE status = 'waiting_upload'")->fetchColumn());
            $this->assertSame(1, DB::table('release_verification_candidates')->where('status', 'pending')->count());
            $this->assertSame(50, $report['assessments']['waiting_upload']);
            $this->assertSame(49, $report['updated']['waiting_upload']);
            $this->assertSame(1, $report['zero_row_updates']);
        } finally {
            $other->rollBack();
        }
        $this->assertSame(1, app(CandidatePreparationService::class)->prepare(50, 30));
        $this->assertSame(50, DB::table('release_verification_candidates')->where('status', 'waiting_upload')->count());
    }

    public function test_failed_later_batch_leaves_previous_batch_committed_and_failed_rows_eligible(): void
    {
        $this->freezeTime();
        $this->insertWaitingCandidates(range(1, 50));
        DB::statement("CREATE TRIGGER waiting_batch_statement_failure BEFORE UPDATE ON release_verification_candidates
            FOR EACH ROW BEGIN IF NEW.id = 28 AND NEW.status = 'waiting_upload' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Owned fixture update failure'; END IF; END");
        $other = $this->independentConnection();
        $report = null;
        try {
            try {
                app(CandidatePreparationService::class)->prepare(50, 30, observer: static function (array $diagnostics) use (&$report): void {
                    $report = $diagnostics;
                });
                $this->fail('The second batch must propagate its statement failure.');
            } catch (QueryException $exception) {
                $this->assertSame('45000', $exception->errorInfo[0]);
                $this->assertSame(1644, $exception->errorInfo[1]);
            }
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(25, (int) $other->query("SELECT COUNT(*) FROM release_verification_candidates WHERE status = 'waiting_upload'")->fetchColumn());
            $this->assertSame(25, DB::table('release_verification_candidates')->where('status', 'pending')->count());
            $this->assertSame(50, $report['assessments']['waiting_upload']);
            $this->assertSame(25, $report['updated']['waiting_upload']);
            $this->assertSame('error', $report['stop_reason']);
        } finally {
            DB::statement('DROP TRIGGER waiting_batch_statement_failure');
        }
        $this->assertSame(25, app(CandidatePreparationService::class)->prepare(50, 30));
        $this->assertSame(50, DB::table('release_verification_candidates')->where('status', 'waiting_upload')->count());
    }

    public function test_generated_waiting_batch_updates_use_bounded_primary_ranges_and_do_not_wait_for_unrelated_rows(): void
    {
        $this->freezeTime();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $captured = collect();
        $lockSelections = collect();
        DB::listen(static function (QueryExecuted $query) use (&$captured, &$lockSelections): void {
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'release_verification_candidates')) {
                $captured->push($query);
            }
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'release_verification_candidates') && str_contains(strtolower($query->sql), 'for update skip locked')) {
                $lockSelections->push($query);
            }
        });
        foreach (['dense', 'sparse'] as $layout) {
            foreach ([1, 3, 25] as $size) {
                DB::table('release_verification_candidates')->delete();
                $rows = [];
                for ($id = 1; $id <= 2000; $id++) {
                    $rows[] = ['id' => $id, 'identity' => sha1('plan:'.$id), 'source_identity' => sha1('plan:'.$id), 'groups_id' => 1,
                        'manifest_path' => '', 'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => 'waiting_upload',
                        'last_unique_at' => now(), 'next_attempt_at' => now()->subHour()];
                }
                DB::table('release_verification_candidates')->insert($rows);
                $ids = $layout === 'dense' ? range(1000, 999 + $size) : array_map(static fn (int $i): int => 100 + $i * 70, range(1, $size));
                $path = $this->waitingManifest();
                DB::table('release_verification_candidates')->whereIn('id', $ids)->update(['status' => 'pending', 'manifest_path' => $path]);
                DB::statement('ANALYZE TABLE release_verification_candidates');
                $captured = collect();
                $lockSelections = collect();
                $other = $this->independentConnection();
                $other->beginTransaction();
                $unrelatedId = $ids[0] + ($layout === 'dense' ? $size : 1);
                $other->query('SELECT id FROM release_verification_candidates WHERE id = '.$unrelatedId.' FOR UPDATE')->fetchColumn();
                try {
                    $this->assertSame($size, app(CandidatePreparationService::class)->prepare($size, 30), $layout.':'.$size);
                    $this->assertCount(1, $captured, $layout.':'.$size);
                    $query = $captured->first();
                    $this->assertInstanceOf(QueryExecuted::class, $query);
                    $this->assertStringContainsString('`id` in (', $query->sql);
                    $this->assertCount($size === 1 ? 0 : 1, $lockSelections);
                    foreach ($lockSelections as $selection) {
                        $selectionPlan = DB::selectOne('EXPLAIN '.$selection->sql, $selection->bindings);
                        $this->assertSame('PRIMARY', $selectionPlan->key);
                        $this->assertSame('range', $selectionPlan->type);
                        $this->assertLessThanOrEqual($size, (int) $selectionPlan->rows);
                    }
                    $plan = DB::selectOne('EXPLAIN '.$query->sql, $query->bindings);
                    $this->assertSame('PRIMARY', $plan->key, $layout.':'.$size);
                    $this->assertSame('range', $plan->type, $layout.':'.$size);
                    $this->assertLessThanOrEqual($size, (int) $plan->rows, $layout.':'.$size);
                    $this->assertSame($size, DB::table('release_verification_candidates')->whereIn('id', $ids)->where('status', 'waiting_upload')->count());
                    DB::beginTransaction();
                    try {
                        DB::table('release_verification_candidates')->whereIn('id', $ids)->update(['status' => 'pending']);
                        foreach ($lockSelections as $selection) {
                            $this->assertBoundedExecutedCandidateQuery($selection, $size);
                        }
                        $this->assertBoundedExecutedCandidateQuery($query, $size);
                        $this->assertSame($size, DB::table('release_verification_candidates')->whereIn('id', $ids)->where('status', 'waiting_upload')->count());
                    } finally {
                        DB::rollBack();
                    }
                    $this->assertSame('waiting_upload', $other->query('SELECT status FROM release_verification_candidates WHERE id = '.$unrelatedId)->fetchColumn());
                } finally {
                    $other->rollBack();
                }
            }
        }
    }

    private function assertBoundedExecutedCandidateQuery(QueryExecuted $query, int $size): void
    {
        $analysis = (array) DB::selectOne('ANALYZE FORMAT=JSON '.$query->sql, $query->bindings);
        $json = (string) reset($analysis);
        $runtime = $this->candidateTableNode(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $this->assertNotNull($runtime, $json);
        $this->assertSame('PRIMARY', $runtime['key'], $json);
        $this->assertSame('range', $runtime['access_type'], $json);
        $this->assertArrayHasKey('r_rows', $runtime, $json);
        $this->assertLessThanOrEqual($size, (float) $runtime['r_rows'], $json);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function candidateTableNode(array $node): ?array
    {
        if (($node['table_name'] ?? null) === 'release_verification_candidates') {
            return $node;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $table = $this->candidateTableNode($value);
                if ($table !== null) {
                    return $table;
                }
            }
        }

        return null;
    }

    private function independentConnection(): PDO
    {
        return new PDO('mysql:host='.getenv('PAR2_DONOR_TEST_HOST').';port='.(getenv('PAR2_DONOR_TEST_PORT') ?: 3306).';dbname='.$this->database.';charset=utf8mb4',
            (string) (getenv('PAR2_DONOR_TEST_USERNAME') ?: 'root'), (string) getenv('PAR2_DONOR_TEST_PASSWORD'));
    }

    private function waitingManifest(): string
    {
        return app(ManifestStore::class)->write(['identity' => 'waiting-batch', 'groups_id' => 1, 'expected_files' => 2,
            'fromname' => 'poster', 'groups' => ['alt.binaries.donor.test'],
            'files' => [['key' => 'movie', 'subject' => 'movie.mkv', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'waiting@article', 'bytes' => 5]]]]]);
    }

    /** @param list<int> $ids */
    private function insertWaitingCandidates(array $ids): void
    {
        $path = $this->waitingManifest();
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['id' => $id, 'identity' => sha1('waiting:'.$id), 'source_identity' => sha1('waiting:'.$id), 'groups_id' => 1,
                'manifest_path' => $path, 'manifest_bytes' => filesize(app(ManifestStore::class)->path($path)),
                'manifest_hash' => substr($path, 0, 64), 'version' => 1, 'status' => 'pending',
                'last_unique_at' => now(), 'next_attempt_at' => now()->subHour()];
        }
        DB::table('release_verification_candidates')->insert($rows);
    }

    public function test_status_due_index_serves_first_and_keyset_pages_with_ineligible_rows(): void
    {
        $this->freezeTime();
        $rows = [];
        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = ['identity' => sha1('plan:'.$i), 'source_identity' => sha1('plan:'.$i), 'groups_id' => 1,
                'manifest_path' => '', 'manifest_bytes' => 0, 'manifest_hash' => str_repeat('0', 64), 'status' => 'pending',
                'last_unique_at' => now(), 'next_attempt_at' => now()->subHour(),
                'lease_token' => $i <= 1000 ? 'leased' : null, 'lease_until' => $i <= 1000 ? now()->addHour() : null];
        }
        DB::table('release_verification_candidates')->insert($rows);
        DB::statement('ANALYZE TABLE release_verification_candidates');
        foreach ([null, 1100] as $cursor) {
            $query = DB::table('release_verification_candidates')->where('status', 'pending')->where('next_attempt_at', '<=', now())
                ->whereNull('release_id')->whereNull('lease_token')
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()));
            if ($cursor !== null) {
                $time = now()->subHour()->format('Y-m-d H:i:s');
                $query->where(fn ($q) => $q->where('next_attempt_at', '>', $time)
                    ->orWhere(fn ($q) => $q->where('next_attempt_at', $time)->where('id', '>', $cursor)));
            }
            $query->orderBy('next_attempt_at')->orderBy('id')->limit(100);
            $plan = DB::selectOne('EXPLAIN '.$query->toSql(), $query->getBindings());
            $this->assertSame('verification_due', $plan->key);
            $this->assertStringNotContainsString('filesort', strtolower($plan->Extra ?? ''));
            $this->assertCount(100, $query->get());
        }
    }
}
