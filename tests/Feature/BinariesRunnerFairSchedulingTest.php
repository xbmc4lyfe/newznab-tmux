<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConfigurationDomain;
use App\Services\Runners\BinariesRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class BinariesRunnerFairSchedulingTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nntmux-fair-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->directory.'/db.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->directory.'/db.sqlite',
            'nntmux.echocli' => false, 'nntmux.concurrency_timeout' => 10]);
        DB::purge();
        DB::reconnect();
        DB::statement('PRAGMA journal_mode=WAL');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->integer('active')->default(1);
            $table->unsignedBigInteger('last_record')->default(1000);
        });
        Schema::create('short_groups', function (Blueprint $table): void {
            $table->string('name');
            $table->unsignedBigInteger('last_record');
        });
        Schema::create('received_headers', function (Blueprint $table): void {
            $table->integer('groups_id');
            $table->unsignedBigInteger('number');
            $table->primary(['groups_id', 'number']);
        });
        foreach ([1 => 900000, 2 => 800000, 3 => 700000] as $id => $serverLast) {
            DB::table('usenet_groups')->insert(['id' => $id, 'name' => 'alt.test.'.$id]);
            DB::table('short_groups')->insert(['name' => 'alt.test.'.$id, 'last_record' => $serverLast]);
        }
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['binary_threads' => 1, 'max_messages' => 3, 'max_headers_per_iteration' => 8]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function outputModes(): array
    {
        return ['buffered' => [false], 'streaming' => [true]];
    }

    #[DataProvider('outputModes')]
    public function test_ranges_rotate_between_groups_and_respect_exact_remainder(bool $stream): void
    {
        config(['nntmux.stream_fork_output' => $stream]);
        (new FairSchedulingBinariesRunner($this->directory))->safeBinaries();
        $this->assertSame([1, 2, 3, 1, 2, 3, 1, 2, 3], array_column($this->rangeStarts(), 'group'));
        foreach ([1, 2, 3] as $group) {
            $this->assertSame(1008, (int) DB::table('usenet_groups')->where('id', $group)->value('last_record'));
            $this->assertSame(range(1001, 1008), DB::table('received_headers')->where('groups_id', $group)->orderBy('number')->pluck('number')->all());
        }
    }

    #[DataProvider('outputModes')]
    public function test_interrupted_range_is_replayed_without_losing_headers_and_rotation_survives_restart(bool $stream): void
    {
        if (! function_exists('posix_kill') || ! function_exists('pcntl_signal')) {
            $this->markTestSkipped('POSIX signals required.');
        }
        config(['nntmux.stream_fork_output' => $stream]);
        try {
            (new FairSchedulingBinariesRunner($this->directory, interruptGroup: 2))->safeBinaries();
            $this->fail('Expected forced interruption.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cancellation', $exception->getMessage());
        }
        $this->assertSame(1003, (int) DB::table('usenet_groups')->where('id', 1)->value('last_record'));
        $this->assertSame(1000, (int) DB::table('usenet_groups')->where('id', 2)->value('last_record'));
        $this->assertSame([1001], DB::table('received_headers')->where('groups_id', 2)->pluck('number')->all());
        $before = count($this->rangeStarts());
        (new FairSchedulingBinariesRunner($this->directory))->safeBinaries();
        $resumed = array_slice($this->rangeStarts(), $before);
        $this->assertSame([3, 1, 2], array_column(array_slice($resumed, 0, 3), 'group'));
        $this->assertSame(1004, $resumed[1]['first'], 'Completed group work must not restart from the beginning.');
        $this->assertSame(1001, $resumed[2]['first'], 'Interrupted range must remain eligible.');
        foreach ([1, 2, 3] as $group) {
            $cursor = (int) DB::table('usenet_groups')->where('id', $group)->value('last_record');
            $this->assertGreaterThan(1000, $cursor);
            $this->assertSame(range(1001, $cursor), DB::table('received_headers')->where('groups_id', $group)->orderBy('number')->pluck('number')->all());
        }
    }

    public function test_rotation_handles_removed_groups_and_ignores_server_number_priority(): void
    {
        Cache::forever('binaries:rotation:last-group', 2);
        DB::table('usenet_groups')->where('id', 2)->update(['active' => 0]);
        (new FairSchedulingBinariesRunner($this->directory))->safeBinaries();
        $this->assertSame([3, 1], array_column(array_slice($this->rangeStarts(), 0, 2), 'group'));
    }

    public function test_concurrent_workers_never_overlap_ranges_for_the_same_group(): void
    {
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['binary_threads' => 4]);
        (new FairSchedulingBinariesRunner($this->directory))->safeBinaries();
        $this->assertCount(9, $this->rangeStarts());
        foreach ([1, 2, 3] as $group) {
            $ranges = array_values(array_filter($this->rangeStarts(), static fn (array $range): bool => $range['group'] === $group));
            $this->assertSame([1001, 1004, 1007], array_column($ranges, 'first'));
            $this->assertSame(range(1001, 1008), DB::table('received_headers')->where('groups_id', $group)->orderBy('number')->pluck('number')->all());
        }
    }

    /** @return list<array{group: int, first: int, last: int}> */
    private function rangeStarts(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->directory.'/events', FILE_IGNORE_NEW_LINES) ?: []);
    }
}

class FairSchedulingBinariesRunner extends BinariesRunner
{
    public function __construct(private readonly string $directory, private readonly int $interruptGroup = 0) {}

    protected function executeCommand(string|array $command): string
    {
        return '';
    }

    protected function buildDnrCommand(string $args): array
    {
        $parts = explode('  ', $args);
        if ($parts[0] !== 'get_range') {
            return [PHP_BINARY, '-r', ''];
        }
        $group = (int) substr($parts[2], strlen('alt.test.'));
        $script = <<<'PHP'
            $db = new PDO('sqlite:'.$argv[1].'/db.sqlite');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA busy_timeout=5000');
            [$group, $first, $last, $interrupt] = array_map('intval', array_slice($argv, 2, 4));
            $lock = fopen($argv[1].'/group-'.$group.'.lock', 'c');
            if (!flock($lock, LOCK_EX|LOCK_NB)) {
                fwrite(STDERR, 'Concurrent ranges for the same group.');
                exit(23);
            }
            file_put_contents($argv[1].'/events', json_encode(['group'=>$group,'first'=>$first,'last'=>$last])."\n", FILE_APPEND|LOCK_EX);
            usleep(100000);
            $insert = $db->prepare('INSERT OR IGNORE INTO received_headers(groups_id,number) VALUES (?,?)');
            for ($number=$first; $number<=$last; $number++) {
                $insert->execute([$group,$number]);
                if ($group === $interrupt && $number === $first) {
                    posix_kill(posix_getppid(), SIGTERM);
                    sleep(10);
                }
            }
            $db->prepare('UPDATE usenet_groups SET last_record=? WHERE id=?')->execute([$last,$group]);
            PHP;

        return [PHP_BINARY, '-r', $script, $this->directory, (string) $group, $parts[3], $parts[4], (string) $this->interruptGroup];
    }
}
