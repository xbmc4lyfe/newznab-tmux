<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\CandidateRepository;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Database\Events\QueryExecuted;
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
