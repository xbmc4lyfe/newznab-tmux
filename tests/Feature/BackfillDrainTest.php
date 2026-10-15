<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\MissedPartHandler;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class BackfillDrainTest extends TestCase
{
    public function test_real_backfill_child_commits_parts_before_clean_drain_and_resumes_without_replay(): void
    {
        $database = $this->createProcessDatabase();
        try {
            $first = $this->runBackfillChild('success', '201-300');

            $this->assertSame(0, $first['exit_code'], $first['error']);
            $this->assertSame(['201-300'], $first['ranges']);
            $this->assertSame(201, (int) DB::table('usenet_groups')->value('first_record'));
            $this->assertSame([201, 300], DB::table('parts')->orderBy('number')->pluck('number')->all());
            $this->assertSame(200, (int) DB::table('parts')->sum('size'));
            $this->assertSame(2, (int) DB::table('binaries')->sum('currentparts'));
            $this->assertSame(0, DB::table('missed_parts')->count());

            app(ProcessingRuntimeStateRepository::class)->requestStop(false);
            $second = $this->runBackfillChild('success', '101-200');

            $this->assertSame(0, $second['exit_code'], $second['error']);
            $this->assertSame(['101-200'], $second['ranges']);
            $this->assertSame(101, (int) DB::table('usenet_groups')->value('first_record'));
            $this->assertSame([101, 200, 201, 300], DB::table('parts')->orderBy('number')->pluck('number')->all());
            $this->assertSame(400, (int) DB::table('parts')->sum('size'));
            $this->assertSame(4, (int) DB::table('binaries')->sum('currentparts'));
            $this->assertSame(0, DB::table('missed_parts')->count());
        } finally {
            DB::disconnect('sqlite');
            unlink($database);
        }
    }

    #[DataProvider('failedChildRanges')]
    public function test_real_backfill_child_does_not_commit_a_cursor_after_a_failed_range(string $mode, string $error): void
    {
        $database = $this->createProcessDatabase();
        try {
            if ($mode === 'storage-failure') {
                DB::statement("CREATE TRIGGER reject_parts BEFORE INSERT ON parts BEGIN SELECT RAISE(ABORT, 'fixture storage failure'); END");
            }

            $result = $this->runBackfillChild($mode, '201-300');

            $this->assertSame(2, $result['exit_code']);
            $this->assertStringContainsString($error, $result['error']);
            $this->assertSame(['201-300'], $result['ranges']);
            $this->assertSame(301, (int) DB::table('usenet_groups')->value('first_record'));
            $this->assertSame(0, DB::table('parts')->count());
            $this->assertSame(0, DB::table('binaries')->count());
            $this->assertSame(0, DB::table('collections')->count());
            $this->assertSame(0, DB::table('missed_parts')->count());
        } finally {
            DB::disconnect('sqlite');
            unlink($database);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function failedChildRanges(): array
    {
        return [
            'overview failed' => ['overview-failure', 'Backfill overview failed'],
            'storage rolled back without repair' => ['storage-failure', 'Header storage did not complete'],
        ];
    }

    public function test_real_backfill_child_preserves_failed_storage_obligations_when_repair_allows_cursor_progress(): void
    {
        $database = $this->createProcessDatabase();
        try {
            DB::statement("CREATE TRIGGER reject_parts BEFORE INSERT ON parts BEGIN SELECT RAISE(ABORT, 'fixture storage failure'); END");

            $result = $this->runBackfillChild('storage-repair-failure', '201-300');

            $this->assertSame(0, $result['exit_code'], $result['error']);
            $this->assertSame(['201-300'], $result['ranges']);
            $this->assertSame(201, (int) DB::table('usenet_groups')->value('first_record'));
            $this->assertSame(0, DB::table('parts')->count());
            $this->assertSame(0, DB::table('binaries')->count());
            $this->assertSame(0, DB::table('collections')->count());
            $this->assertSame([201 => 1, 300 => 1], DB::table('missed_parts')->orderBy('numberid')->pluck('attempts', 'numberid')->all());
            $repairable = (new MissedPartHandler)->getMissingParts(1);
            $this->assertSame([201, 300], array_map(static fn (\stdClass $part): int => (int) $part->numberid, $repairable));
        } finally {
            DB::disconnect('sqlite');
            unlink($database);
        }
    }

    public function test_backfill_commits_the_active_range_before_yielding_and_resumes_from_that_cursor(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, first_record INTEGER, first_record_postdate TEXT, last_updated TEXT)');
        DB::table('usenet_groups')->insert(['id' => 1, 'first_record' => 301]);
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn (int $time): string => date('Y-m-d H:i:s', $time));
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $ranges = [];
        $binaries = $this->mock(BinariesService::class);
        $binaries->shouldReceive('getMessageBuffer')->andReturn(100);
        $binaries->shouldReceive('scan')->andReturnUsing(static function (array $group, int $first, int $last) use (&$ranges): array {
            $ranges[] = [$first, $last];
            ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);

            return ['firstArticleDate' => '2026-10-01'];
        });
        $service = new BackfillService(new BackfillConfig, $binaries, $this->mock(NNTPService::class));
        $method = new ReflectionMethod($service, 'processBackfillChunks');
        $group = ['id' => 1, 'name' => 'alt.test', 'first_record' => 301];
        $method->invoke($service, $group, 1, 0, 'test');
        $this->assertSame([[201, 300]], $ranges);
        $this->assertSame(201, (int) DB::table('usenet_groups')->value('first_record'));

        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        $group['first_record'] = 201;
        $method->invoke($service, $group, 1, 0, 'test');
        $this->assertSame([[201, 300], [101, 200]], $ranges);
        $this->assertSame(101, (int) DB::table('usenet_groups')->value('first_record'));
    }

    private function createProcessDatabase(): string
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX child processes are unavailable.');
        }
        $database = tempnam(sys_get_temp_dir(), 'nntmux-backfill-drain-');
        $this->assertNotFalse($database);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
            'database.connections.sqlite.url' => null, 'release_verification.required' => true,
            'release_verification.pending_bytes' => 1048576]);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT, first_record INT, first_record_postdate TEXT, last_updated TEXT)');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test', 'first_record' => 301,
            'first_record_postdate' => '2026-10-01 00:00:00']);
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, subject TEXT, fromname TEXT, date TEXT,
            xref TEXT, groups_id INT, totalfiles INT, collectionhash BLOB UNIQUE, collection_regexes_id INT,
            dateadded TEXT, last_seen_at TEXT, filecheck INT DEFAULT 0, filesize INT DEFAULT 0, noise TEXT)');
        DB::statement('CREATE TABLE collection_groups (collections_id INT, group_name TEXT, UNIQUE(collections_id, group_name))');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, binaryhash BLOB, name TEXT, collections_id INT,
            totalparts INT, currentparts INT, filenumber INT, partsize INT, partcheck INT DEFAULT 0, UNIQUE(binaryhash, collections_id))');
        DB::statement('CREATE TABLE parts (binaries_id INT, number INT, messageid TEXT, partnumber INT, size INT, UNIQUE(binaries_id, partnumber))');
        DB::statement('CREATE TABLE missed_parts (id INTEGER PRIMARY KEY, numberid INT, groups_id INT, attempts INT DEFAULT 0, UNIQUE(numberid, groups_id))');
        DB::statement('CREATE TABLE release_verification_state (id INTEGER PRIMARY KEY, pending_bytes INT)');
        DB::table('release_verification_state')->insert(['id' => 1, 'pending_bytes' => 0]);

        return $database;
    }

    /** @return array{exit_code: int, ranges: list<string>, error: string} */
    private function runBackfillChild(string $mode, string $expectedRange): array
    {
        $channels = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($channels);
        DB::disconnect('sqlite');
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($channels[0]);
            $this->executeBackfillChild($channels[1], $mode);
        }
        fclose($channels[1]);
        stream_set_timeout($channels[0], 12);
        try {
            $ready = json_decode((string) fgets($channels[0]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['ready' => $expectedRange], $ready);
            app(ProcessingRuntimeStateRepository::class)->requestStop();
            fwrite($channels[0], "continue\n");
            $report = json_decode((string) fgets($channels[0]), true, flags: JSON_THROW_ON_ERROR);
            $deadline = microtime(true) + 12;
            do {
                $finished = pcntl_waitpid($pid, $status, WNOHANG);
                if ($finished === $pid) {
                    $pid = 0;
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
            $this->assertSame(0, $pid, 'The child must exit cleanly after reaching its committed chunk boundary.');
            $this->assertTrue(pcntl_wifexited($status), 'The child must exit normally rather than by a signal.');

            return ['exit_code' => pcntl_wexitstatus($status), 'ranges' => $report['ranges'], 'error' => $report['error']];
        } finally {
            fclose($channels[0]);
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    /** @param resource $channel */
    private function executeBackfillChild(mixed $channel, string $mode): never
    {
        DB::purge('sqlite');
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn (int $time): string => date('Y-m-d H:i:s', $time));
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $nntp = new class($channel, $mode) extends NNTPService
        {
            /** @var list<string> */
            public array $ranges = [];

            /** @param resource $channel */
            public function __construct(private mixed $channel, private string $mode) {}

            public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
            {
                return ['first' => 1, 'last' => 1000];
            }

            public function reconnect(bool $compression = true): mixed
            {
                return false;
            }

            /** @return list<array<string, int|string>>|false */
            public function getXOVER(string $range): array|false
            {
                $this->ranges[] = $range;
                if (count($this->ranges) === 1) {
                    fwrite($this->channel, json_encode(['ready' => $range], JSON_THROW_ON_ERROR)."\n");
                    stream_set_timeout($this->channel, 12);
                    if (fgets($this->channel) !== "continue\n") {
                        throw new \RuntimeException('The test did not release the admitted overview response.');
                    }
                }
                if ($this->mode === 'overview-failure') {
                    return false;
                }
                [$first, $last] = array_map('intval', explode('-', $range));
                $headers = [];
                foreach ([$first, $last] as $offset => $number) {
                    $headers[] = ['Number' => $number, 'Subject' => 'Drain.Release'.$first.' ('.($offset + 1).'/2)',
                        'From' => 'poster@example.test', 'Date' => '2026-10-01 12:00:00', 'Bytes' => 100,
                        'Message-ID' => '<drain-'.$number.'@example.test>', 'Xref' => 'news.example.test alt.test:'.$number];
                }

                return $headers;
            }
        };
        $config = new BinariesConfig(messageBuffer: 100, compressedHeaders: false, partRepair: $mode === 'storage-repair-failure', headerChunkSize: 100);
        $parser = new HeaderParser(new class extends BlacklistService
        {
            public function isBlackListed(array $msg, string $groupName): bool
            {
                return false;
            }
        });
        $collections = new CollectionHandler(new class extends CollectionsCleaningService
        {
            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => $subject];
            }
        });
        $storage = new HeaderStorageService($collections, config: $config);
        $service = new BackfillService(new BackfillConfig(compressedHeaders: false), new BinariesService($config, $parser, $storage, nntp: $nntp), $nntp);
        $exitCode = 0;
        $error = '';
        try {
            $group = (array) DB::table('usenet_groups')->where('id', 1)->first();
            $service->backfillGroup($group, 0, 200);
        } catch (\Throwable $exception) {
            $exitCode = 2;
            $error = $exception->getMessage();
        }
        fwrite($channel, json_encode(['ranges' => $nntp->ranges, 'error' => $error], JSON_THROW_ON_ERROR)."\n");
        fclose($channel);
        DB::disconnect('sqlite');
        exit($exitCode);
    }
}
