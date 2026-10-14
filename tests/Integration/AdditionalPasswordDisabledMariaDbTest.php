<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\ConfigurationSeeder;
use Database\Seeders\RootCategoriesTableSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class AdditionalPasswordDisabledMariaDbTest extends TestCase
{
    private ?PDO $administrator = null;

    private ?PDO $competitor = null;

    private string $database = '';

    private bool $databaseCreated = false;

    private bool $connectionConfigured = false;

    private ?bool $originalSupportsClaims = null;

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    /** @var list<QueryExecuted> */
    private array $candidateQueries = [];

    /** @var list<QueryExecuted> */
    private array $lockingQueries = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['database.default', 'database.connections.additional_qualification', 'cache.default', 'nntmux_settings.check_passworded_rars'] as $key) {
            $this->originalConfig[$key] = config($key);
        }
        $property = new ReflectionProperty(AdditionalCandidateQuery::class, 'supportsClaims');
        $this->originalSupportsClaims = $property->getValue();
        $property->setValue(null, null);
        $host = (string) getenv('ADDITIONAL_ADMISSION_TEST_HOST');
        if ($host === '') {
            $this->markTestSkipped('Set ADDITIONAL_ADMISSION_TEST_HOST to an isolated MariaDB test server.');
        }
        try {
            $port = (int) (getenv('ADDITIONAL_ADMISSION_TEST_PORT') ?: 3306);
            $username = (string) (getenv('ADDITIONAL_ADMISSION_TEST_USERNAME') ?: 'root');
            $password = (string) getenv('ADDITIONAL_ADMISSION_TEST_PASSWORD');
            $this->administrator = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->database = 'nntmux_additional_test_'.bin2hex(random_bytes(8));
            $this->administrator->exec('CREATE DATABASE `'.$this->database.'`');
            $this->databaseCreated = true;
            $this->administrator->exec('USE `'.$this->database.'`');
            $this->administrator->exec($this->app->make('files')->get(database_path('schema/mariadb-schema.sql')));
            config([
                'database.default' => 'additional_qualification',
                'database.connections.additional_qualification' => [
                    'driver' => 'mysql', 'host' => $host, 'port' => $port,
                    'database' => $this->database, 'username' => $username, 'password' => $password,
                    'url' => null, 'unix_socket' => '', 'prefix' => '', 'strict' => true,
                    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                ],
                'cache.default' => 'array',
                'nntmux_settings.check_passworded_rars' => false,
            ]);
            DB::purge('additional_qualification');
            $this->connectionConfigured = true;
            DB::statement('SET SESSION max_statement_time = 60');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
            if (Artisan::call('migrate', ['--database' => 'additional_qualification', '--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Could not migrate owned additional fixture: '.Artisan::output());
            }
            (new ConfigurationSeeder)->run();
            DB::table('root_categories')->insertOrIgnore(['id' => 2000, 'title' => 'Movies']);
            DB::table('categories')->insertOrIgnore([
                ['id' => 2040, 'title' => 'HD', 'root_categories_id' => 2000, 'disablepreview' => 0],
                ['id' => 2041, 'title' => 'Disabled', 'root_categories_id' => 2000, 'disablepreview' => 1],
            ]);
            $this->competitor = new PDO("mysql:host={$host};port={$port};dbname={$this->database};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->competitor->exec('SET SESSION innodb_lock_wait_timeout = 2');
            $this->freezeTime();
            DB::listen(function (QueryExecuted $query): void {
                if ($this->isCandidateQuery($query)) {
                    $this->candidateQueries[] = $query;
                }
                if (str_contains($query->sql, 'for update skip locked')) {
                    $this->lockingQueries[] = $query;
                }
            });
        } catch (\Throwable $exception) {
            $this->cleanupDatabase();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupDatabase();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupDatabase(): void
    {
        try {
            if ($this->competitor?->inTransaction()) {
                $this->competitor->rollBack();
            }
            $this->competitor = null;
            if ($this->databaseCreated && $this->administrator !== null && preg_match('/^nntmux_additional_test_[a-f0-9]{16}$/D', $this->database) === 1) {
                if ($this->connectionConfigured) {
                    $connection = DB::connection('additional_qualification');
                    while ($connection->transactionLevel() > 0) {
                        $connection->rollBack();
                    }
                }
                DB::purge('additional_qualification');
                $this->administrator->exec('DROP DATABASE `'.$this->database.'`');
                $this->databaseCreated = false;
            }
        } finally {
            config($this->originalConfig);
            (new ReflectionProperty(AdditionalCandidateQuery::class, 'supportsClaims'))->setValue(null, $this->originalSupportsClaims);
        }
    }

    /** @return iterable<string, array{string, bool|null}> */
    public static function settingsAndIsolation(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach (['enabled' => true, 'disabled' => false, 'unset' => null] as $setting => $value) {
                yield $isolation.':'.$setting => [$isolation, $value];
            }
        }
    }

    #[DataProvider('settingsAndIsolation')]
    public function test_actual_claims_preserve_status_policy_order_exclusions_and_ownership(string $isolation, ?bool $passwordChecks): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        config(['nntmux_settings.check_passworded_rars' => $passwordChecks]);
        $rows = [];
        for ($id = 1; $id <= 30; $id++) {
            $rows[] = $this->releaseRow($id, ['size' => 500, 'passwordstatus' => $id % 2 === 0 ? -1 : 0,
                'postdate' => now()->subSeconds(intdiv($id - 1, 2))->format('Y-m-d H:i:s')]);
        }
        $invalid = [
            ['passwordstatus' => 1], ['passwordstatus' => 2], ['passwordstatus' => -2],
            ['haspreview' => 0], ['nzbstatus' => 0], ['categories_id' => 2041],
            ['archive_retry_at' => now()->addHour()], ['additional_pp_claimed_at' => now(), 'additional_pp_claim_token' => 'other'],
            ['size' => 100], ['size' => 1000], ['leftguid' => 'b'], ['groups_id' => 2],
        ];
        foreach ($invalid as $index => $overrides) {
            $rows[] = $this->releaseRow(100 + $index, array_replace(['size' => 500], $overrides));
        }
        $rows[] = $this->releaseRow(200, ['size' => 500, 'additional_pp_claimed_at' => now()->subDay(), 'additional_pp_claim_token' => 'stale', 'archive_retry_at' => now()->subHour()]);
        DB::table('releases')->insert($rows);
        $claimed = AdditionalCandidateQuery::claimBatch('a', 3, 'first', 1, 100, 1000, ['id'], [1, 2]);
        $expected = $passwordChecks === true ? [200, 4, 6] : [200, 3, 4];
        $this->assertSame($expected, $claimed->pluck('id')->all());
        $this->assertSame(3, DB::table('releases')->where('additional_pp_claim_token', 'first')->count());
        $next = AdditionalCandidateQuery::claimBatch('a', 3, 'second', 1, 100, 1000, ['id'], [1, 2]);
        $this->assertSame($passwordChecks === true ? [8, 10, 12] : [5, 6, 7], $next->pluck('id')->all());
        $this->assertSame([], array_intersect($expected, $next->pluck('id')->all()));
        AdditionalCandidateQuery::clearClaim(200, 'second');
        $this->assertSame('first', DB::table('releases')->where('id', 200)->value('additional_pp_claim_token'));
        $this->assertSame('other', DB::table('releases')->where('id', 107)->value('additional_pp_claim_token'));
        $this->assertCandidateBound($this->candidateQueries[0], 12, $passwordChecks !== true);
        foreach ($this->lockingQueries as $query) {
            $this->assertStringContainsString('force index (PRIMARY)', $query->sql);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function isolationModes(): iterable
    {
        yield 'READ COMMITTED' => ['READ COMMITTED'];
        yield 'REPEATABLE READ' => ['REPEATABLE READ'];
    }

    #[DataProvider('isolationModes')]
    public function test_competing_row_lock_is_skipped_without_losing_claim_order(string $isolation): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        DB::table('releases')->insert(array_map(fn (int $id): array => $this->releaseRow($id, [
            'passwordstatus' => $id % 2 === 0 ? -1 : 0, 'postdate' => now()->subSeconds($id),
        ]), range(1, 15)));
        $this->competitor->beginTransaction();
        try {
            $this->competitor->query('SELECT id FROM releases WHERE id IN (1, 2) FOR UPDATE')->fetchAll();
            $claimed = AdditionalCandidateQuery::claimBatch('a', 3, 'free-worker', columns: ['id']);
            $this->assertSame([3, 4, 5], $claimed->pluck('id')->all());
            $this->assertSame(0, DB::table('releases')->whereIn('id', [1, 2])->whereNotNull('additional_pp_claim_token')->count());
            $this->assertSame(3, DB::table('releases')->where('additional_pp_claim_token', 'free-worker')->count());
        } finally {
            $this->competitor->rollBack();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function committedTransitions(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach (['passwordstatus = 2', 'haspreview = 0', 'nzbstatus = 0', 'archive_retry_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)', "additional_pp_claimed_at = NOW(), additional_pp_claim_token = 'other'"] as $transition) {
                yield $isolation.':'.$transition => [$isolation, $transition];
            }
        }
    }

    #[DataProvider('committedTransitions')]
    public function test_locking_read_rechecks_committed_state_after_candidate_selection(string $isolation, string $transition): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        DB::table('releases')->insert(array_map(fn (int $id): array => $this->releaseRow($id, [
            'passwordstatus' => 0, 'postdate' => now()->subSeconds($id),
        ]), range(1, 8)));
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $transition): void {
            if (! $changed && $this->isCandidateQuery($query)) {
                $changed = true;
                $this->competitor->beginTransaction();
                $this->competitor->exec('UPDATE releases SET '.$transition.' WHERE id = 1');
                $this->competitor->commit();
            }
        });
        try {
            $claimed = AdditionalCandidateQuery::claimBatch('a', 2, 'new-worker', columns: ['id']);
            $this->assertTrue($changed);
            $this->assertSame([2, 3], $claimed->pluck('id')->all());
            $this->assertNotSame('new-worker', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        } finally {
            if ($this->competitor->inTransaction()) {
                $this->competitor->rollBack();
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function planDistributions(): iterable
    {
        foreach (['dense', 'sparse', 'mostly-ineligible'] as $distribution) {
            yield $distribution.':two-categories' => [$distribution, false];
            yield $distribution.':seeded-categories' => [$distribution, true];
        }
    }

    #[DataProvider('planDistributions')]
    public function test_generated_query_sorts_only_bounded_status_prefixes(string $distribution, bool $seedCategories): void
    {
        if ($seedCategories) {
            DB::table('categories')->delete();
            (new RootCategoriesTableSeeder)->run();
            (new CategoriesTableSeeder)->run();
        }
        for ($start = 1; $start <= 40000; $start += 500) {
            $rows = [];
            for ($id = $start; $id < $start + 500; $id++) {
                $eligibleStatus = $distribution === 'sparse' ? $id % 97 === 0 : $id <= 20000;
                $overrides = ['passwordstatus' => $eligibleStatus ? ($id % 2 === 0 ? -1 : 0) : 2,
                    'postdate' => now()->subSeconds($id)];
                if ($distribution === 'mostly-ineligible' && $id % 97 !== 0) {
                    $overrides['archive_retry_at'] = now()->addDay();
                }
                $rows[] = $this->releaseRow($id, $overrides);
            }
            DB::table('releases')->insert($rows);
        }
        DB::statement('ANALYZE TABLE releases');
        DB::beginTransaction();
        try {
            $claimed = AdditionalCandidateQuery::claimBatch('a', 25, 'plan-worker', columns: ['id']);
            $this->assertCount(25, $claimed);
        } finally {
            DB::rollBack();
        }
        $query = $this->candidateQueries[0];
        $this->assertCandidateBound($query, 100, true);
        $plan = DB::select('EXPLAIN '.$query->sql, $query->bindings);
        $branches = array_values(array_filter($plan, static fn (object $row): bool => $row->table === 'r'));
        $this->assertCount(2, $branches);
        foreach ($branches as $branch) {
            $this->assertSame('ix_releases_add_pp_claim_queue', $branch->key, json_encode($plan, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('filesort', strtolower($branch->Extra ?? ''));
            $this->assertStringNotContainsString('temporary', strtolower($branch->Extra ?? ''));
        }
        $analysis = (array) DB::selectOne('ANALYZE FORMAT=JSON '.$query->sql, $query->bindings);
        $json = (string) reset($analysis);
        $nodes = $this->tableNodes(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        $derived = array_values(array_filter($nodes, static fn (array $node): bool => str_starts_with($node['table_name'], '<derived')));
        $this->assertNotEmpty($derived, $json);
        foreach ($derived as $node) {
            $this->assertLessThanOrEqual(200, (float) $node['r_rows'], $json);
        }
    }

    private function isCandidateQuery(QueryExecuted $query): bool
    {
        return str_starts_with($query->sql, 'select') && str_contains($query->sql, 'order by')
            && (str_contains($query->sql, '`additional_candidates`') || str_contains($query->sql, 'select `r`.`id`, `r`.`postdate`'));
    }

    private function assertCandidateBound(QueryExecuted $query, int $bound, bool $combined): void
    {
        $this->assertSame($combined ? 3 : 1, preg_match_all('/limit '.$bound.'\b/', $query->sql));
        $this->assertSame($combined, str_contains($query->sql, 'union all'));
        $this->assertLessThanOrEqual($bound, count(DB::select($query->sql, $query->bindings)));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function releaseRow(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id, 'guid' => sprintf('a%035d', $id), 'leftguid' => 'a', 'groups_id' => 1,
            'passwordstatus' => -1, 'haspreview' => -1, 'nzbstatus' => 1, 'categories_id' => 2040,
            'size' => 2 * 1048576, 'postdate' => now(), 'archive_retry_at' => null,
            'additional_pp_claimed_at' => null, 'additional_pp_claim_token' => null,
        ], $overrides);
    }

    /** @param array<string|int, mixed> $node
     * @return list<array<string, mixed>>
     */
    private function tableNodes(array $node): array
    {
        $tables = isset($node['table_name']) ? [$node] : [];
        foreach ($node as $value) {
            if (is_array($value)) {
                array_push($tables, ...$this->tableNodes($value));
            }
        }

        return $tables;
    }
}
