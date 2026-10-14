<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Manticoresearch\Client;
use Manticoresearch\Request;
use Manticoresearch\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class NntmuxPredbSearchReconcileCommandTest extends SearchConsoleCommandTestCase
{
    private array $indexed = [3 => 3];

    private array $written = [];

    private array $probed = [];

    private ?Closure $probeOverride = null;

    private ?array $writeResult = null;

    private bool $persistWrites = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['search.default' => 'manticore']);
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->dateTime('adddate');
        });
        Schema::create('predb', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('title');
            $table->string('filename');
            $table->string('source');
            $table->integer('searched')->default(1);
        });
        $this->seedIds([3, 17, 91]);
        $client = new class(fn (string $sql): mixed => $this->probe($sql)) extends Client
        {
            public function __construct(private Closure $probe) {}

            public function request(Request $request, array $params = [], string $retryReason = ''): Response
            {
                parse_str($request->getBody(), $body);
                $responseClass = $params['responseClass'] ?? Response::class;

                return new $responseClass(($this->probe)($body['query']));
            }
        };
        $driver = Mockery::mock(ManticoreSearchDriver::class)->makePartial();
        $driver->manticoreSearch = $client;
        $driver->shouldReceive('isAvailable')->andReturnTrue();
        $driver->shouldReceive('getPredbIndex')->andReturn('predb_rt');
        $driver->shouldReceive('bulkInsertPredb')->andReturnUsing(function (array $rows): array {
            foreach ($rows as $row) {
                $this->written[] = $row;
                if ($this->persistWrites) {
                    $this->indexed[$row['id']] = $row['id'];
                }
            }

            return $this->writeResult ?? ['success' => count($rows), 'errors' => 0];
        });
        $this->app->instance(ManticoreSearchDriver::class, $driver);
    }

    public function test_repairs_only_missing_sparse_ids_and_leaves_sql_state_unchanged(): void
    {
        $before = DB::table('predb')->orderBy('id')->get()->toArray();
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true])
            ->expectsOutputToContain('Reindexed 2 PreDB row(s).')->assertSuccessful();
        self::assertSame([3 => 3, 17 => 17, 91 => 91], $this->indexed);
        self::assertSame([17, 91], array_column($this->written, 'id'));
        self::assertSame(['id', 'title', 'filename', 'source'], array_keys($this->written[0]));
        self::assertEquals($before, DB::table('predb')->orderBy('id')->get()->toArray());
        $this->written = [];
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true])->assertSuccessful();
        self::assertSame([], $this->written);
    }

    public function test_report_and_dry_run_never_write(): void
    {
        foreach ([[], ['--reindex' => true, '--dry-run' => true]] as $options) {
            $this->artisan('nntmux:search-reconcile', $options + ['--predb' => true, '--all' => true])
                ->expectsOutputToContain('missing in Manticore index: 2')->assertSuccessful();
        }
        self::assertSame([], $this->written);
        self::assertSame([1, 1, 1], DB::table('predb')->pluck('searched')->all());
    }

    public function test_concurrent_producer_document_survives_repair_and_rerun_repairs_remaining_ids(): void
    {
        $documents = [3 => ['title' => 'Release.3', 'filename' => 'file.3', 'source' => 'test']];
        $fresh = ['title' => 'Fresh.Release.17', 'filename' => 'fresh.file.17', 'source' => 'irc'];
        $operations = [];
        $race = true;
        $driver = null;
        $respond = function (Request $request) use (&$documents, &$operations, &$race, &$driver, $fresh): array {
            if ($request->getPath() === '/sql') {
                parse_str($request->getBody(), $body);
                preg_match('/IN \(([\d,]+)\)/', $body['query'], $matches);
                $ids = array_map('intval', explode(',', $matches[1]));
                $present = array_values(array_intersect($ids, array_keys($documents)));

                return [['data' => array_map(static fn (int $id): array => ['id' => $id], $present),
                    'total' => count($present), 'error' => '', 'warning' => '']];
            }

            self::assertSame('/bulk', $request->getPath());
            self::assertSame('POST', $request->getMethod());
            self::assertSame('application/x-ndjson', $request->getContentType());
            if ($race) {
                $race = false;
                DB::table('predb')->where('id', 17)->update($fresh);
                self::assertSame(['success' => 1, 'errors' => 0], $driver->bulkInsertPredb([['id' => 17] + $fresh]));
            }
            $writes = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                explode("\n", trim($request->getBody())));
            $operations[] = array_map(static fn (array $write): string => array_key_first($write), $writes);
            foreach ($writes as $write) {
                if (isset($write['insert']) && isset($documents[$write['insert']['id']])) {
                    return ['items' => [['insert' => ['table' => 'predb_rt', 'error' => 'secret-title: duplicate ID']]],
                        'errors' => true, 'error' => 'secret-title: duplicate ID', 'current_line' => 1, 'skipped_lines' => 1];
                }
            }
            foreach ($writes as $write) {
                $operation = reset($write);
                self::assertSame('predb_rt', $operation['table']);
                $documents[$operation['id']] = $operation['doc'];
            }

            return ['items' => [['bulk' => ['table' => 'predb_rt', '_id' => 0, 'created' => count($writes),
                'deleted' => 0, 'updated' => 0, 'result' => 'created', 'status' => 201]]],
                'errors' => false, 'error' => '', 'current_line' => count($writes) + 1, 'skipped_lines' => 0];
        };
        $client = new class($respond) extends Client
        {
            public function __construct(private Closure $respond) {}

            public function request(Request $request, array $params = [], string $retryReason = ''): Response
            {
                $responseClass = $params['responseClass'] ?? Response::class;

                return new $responseClass(($this->respond)($request));
            }
        };
        $driver = Mockery::mock(ManticoreSearchDriver::class, [['indexes' => ['predb' => 'predb_rt']]])->makePartial();
        $driver->shouldReceive('isAvailable')->andReturnTrue();
        $driver->manticoreSearch = $client;
        $this->app->instance(ManticoreSearchDriver::class, $driver);

        $status = Artisan::call('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true]);
        self::assertSame($fresh, $documents[17]);
        self::assertSame(1, $status);
        self::assertStringNotContainsString('secret-title', Artisan::output());
        self::assertArrayNotHasKey(91, $documents);
        self::assertSame([['replace'], ['insert', 'insert']], $operations);
        $afterProducer = DB::table('predb')->orderBy('id')->get()->toArray();

        self::assertSame(0, Artisan::call('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true]));
        self::assertSame($fresh, $documents[17]);
        self::assertSame(['title' => 'Release.91', 'filename' => 'file.91', 'source' => 'test'], $documents[91]);
        self::assertSame([['replace'], ['insert', 'insert'], ['insert']], $operations);
        self::assertEquals($afterProducer, DB::table('predb')->orderBy('id')->get()->toArray());
    }

    public function test_keyset_chunks_and_subbatches_exclude_new_higher_ids(): void
    {
        DB::table('predb')->delete();
        $this->seedIds(range(10, 1500, 10));
        $this->probeOverride = function (): array {
            $this->probeOverride = null;
            $this->seedIds([2000]);

            return ['data' => [], 'total' => 0, 'error' => '', 'warning' => ''];
        };
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--chunk' => 70, '--in-batch' => 50])
            ->expectsOutputToContain('Scanned 150 PreDB row(s)')
            ->expectsOutputToContain('Last completed ID: 1500; frozen max ID: 1500')->assertSuccessful();
        self::assertSame([50, 20, 50, 20, 10], array_map('count', $this->probed));
        self::assertSame(range(10, 1500, 10), array_merge(...$this->probed));
        self::assertSame([], $this->written);
    }

    #[DataProvider('invalidFlags')]
    public function test_rejects_unsafe_scope_flags(array $flags): void
    {
        $this->artisan('nntmux:search-reconcile', $flags)->assertFailed();
        self::assertSame([], $this->probed);
        self::assertSame([], $this->written);
    }

    public static function invalidFlags(): array
    {
        return [[['--predb' => true]], [['--predb' => true, '--all' => true, '--orphans' => true]]];
    }

    #[DataProvider('badResponses')]
    public function test_probe_errors_fail_closed_without_writes(mixed $response): void
    {
        $this->probeOverride = static function () use ($response): mixed {
            if ($response instanceof RuntimeException) {
                throw $response;
            }

            return $response;
        };
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true])
            ->expectsOutputToContain('Last completed ID: 0; frozen max ID: 91')
            ->doesntExpectOutputToContain('secret-title')->assertFailed();
        self::assertSame([], $this->written);
    }

    public static function badResponses(): array
    {
        return [
            [new RuntimeException('secret-title')], [false], [[]], [['error' => 'secret-title']], [['unexpected' => []]], [['data' => [['no_id' => 1]]]],
            [['data' => []]],
            [['data' => [], 'total' => '0', 'error' => '', 'warning' => '']],
            [['data' => [], 'total' => -1, 'error' => '', 'warning' => '']],
            [['data' => [], 'total' => 1, 'error' => '', 'warning' => '']],
            [['data' => [], 'total' => 0, 'error' => false, 'warning' => '']],
            [['data' => [], 'total' => 0, 'error' => '', 'warning' => null]],
            [['data' => [], 'total' => 0, 'error' => '0', 'warning' => '']],
            [['data' => [], 'total' => 0, 'error' => '', 'warning' => '0']],
        ];
    }

    public function test_bulk_error_stops_without_claiming_repair(): void
    {
        $this->writeResult = ['success' => 1, 'errors' => 1];
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true])
            ->expectsOutputToContain('Last completed ID: 0; frozen max ID: 91')
            ->doesntExpectOutputToContain('Reindexed 2')->assertFailed();
    }

    public function test_post_write_missing_ids_fail_instead_of_counting_optimistic_success(): void
    {
        $this->persistWrites = false;
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true])
            ->expectsOutputToContain('Last completed ID: 0; frozen max ID: 91')
            ->doesntExpectOutputToContain('Reindexed 2')->assertFailed();
        self::assertCount(2, $this->probed);
    }

    public function test_failure_in_later_chunk_stops_at_last_completed_chunk(): void
    {
        DB::table('predb')->delete();
        $this->seedIds(range(10, 1500, 10));
        $this->probeOverride = function (): array {
            if (count($this->probed) === 2) {
                throw new RuntimeException('secret-title');
            }

            return ['data' => [], 'total' => 0, 'error' => '', 'warning' => ''];
        };
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--chunk' => 50])
            ->expectsOutputToContain('Last completed ID: 500; frozen max ID: 1500')
            ->assertFailed();
        self::assertCount(2, $this->probed);
    }

    public function test_sdk_partial_result_warning_fails_closed_before_writing(): void
    {
        $client = new class extends Client
        {
            public function __construct() {}

            public function request(Request $request, array $params = [], string $retryReason = ''): Response
            {
                $responseClass = $params['responseClass'] ?? Response::class;

                return new $responseClass([
                    ['columns' => [['id' => ['type' => 'long long']]], 'data' => [['id' => 3]], 'total' => 1,
                        'error' => '', 'warning' => 'secret-title: query timed out'],
                ]);
            }
        };
        $this->app->make(ManticoreSearchDriver::class)->manticoreSearch = $client;
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true, '--reindex' => true])
            ->expectsOutputToContain('Last completed ID: 0; frozen max ID: 91')
            ->doesntExpectOutputToContain('secret-title')->assertFailed();
        self::assertSame([], $this->written);
    }

    public function test_legacy_empty_and_id_responses_are_valid_successful_reads(): void
    {
        $this->probeOverride = static fn (): array => ['data' => [['id' => 3]], 'total' => 1, 'error' => '', 'warning' => ''];
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true])
            ->expectsOutputToContain('missing in Manticore index: 2')->assertSuccessful();
        $this->probeOverride = static fn (): array => ['data' => [], 'total' => 0, 'error' => '', 'warning' => ''];
        $this->artisan('nntmux:search-reconcile', ['--predb' => true, '--all' => true])
            ->expectsOutputToContain('missing in Manticore index: 3')->assertSuccessful();
    }

    private function seedIds(array $ids): void
    {
        DB::table('predb')->insert(array_map(static fn (int $id): array => [
            'id' => $id, 'title' => 'Release.'.$id, 'filename' => 'file.'.$id, 'source' => 'test', 'searched' => 1,
        ], $ids));
    }

    private function probe(string $sql): mixed
    {
        self::assertStringContainsString('FROM `predb_rt`', $sql);
        preg_match('/IN \(([\d,]+)\) LIMIT (\d+) OPTION max_matches=(\d+)/', $sql, $matches);
        self::assertNotEmpty($matches);
        $ids = array_map('intval', explode(',', $matches[1]));
        self::assertSame(count($ids), (int) $matches[2]);
        self::assertSame(count($ids), (int) $matches[3]);
        $this->probed[] = $ids;

        if ($this->probeOverride !== null) {
            return ($this->probeOverride)();
        }
        $matches = array_intersect_key($this->indexed, array_flip($ids));

        return [['columns' => [['id' => ['type' => 'long long']]],
            'data' => array_values(array_map(static fn (int $id): array => ['id' => $id], $matches)),
            'total' => count($matches), 'error' => '', 'warning' => '']];
    }
}
