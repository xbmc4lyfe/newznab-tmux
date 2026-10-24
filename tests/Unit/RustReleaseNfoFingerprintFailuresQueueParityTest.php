<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ReleaseFingerprintMetadata;
use App\Models\ReleaseNfo;
use App\Models\ReleaseNzbCreationFailure;
use App\Services\Nzb\ReleaseArticleFingerprintStore;
use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Wave 21 Slice 21.2. Production keeps PHP unless every helper in a class passes
 * all common/uncommon mean/p99 gates in all three repetitions (>= 1.5x).
 *
 * Reproduce: cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
 * NNTMUX_W21_NFO_QUEUE_BENCH=1 php artisan test --compact tests/Unit/RustReleaseNfoFingerprintFailuresQueueParityTest.php
 * Raw timings, inputs, source/library hashes and tool/host identity go to a unique
 * JSON artifact in the system temporary directory. No production DB is involved.
 *
 * Apple M5 / PHP 8.5.12 / optimized Rust 1.99.1 screen, 2026-10-24:
 * worst-repetition PHP/Rust speedups (common mean/p99; uncommon mean/p99):
 * queryProjection 0.0443/0.0421; 0.0484/0.0554
 * metadata castPayload 0.0406/0.0401; 0.0398/0.0390
 * failure castPayload 0.0464/0.0451; 0.0458/0.0467
 * updatePayload 0.0498/0.0492; 0.0161/0.0085
 * shouldSkip 0.0547/0.0563; 0.4098/0.9977
 * All fail the gate; PHP remains the default for all five classes. The two
 * cast maps have no input-dependent uncommon branch; the same payload is used.
 */
final class RustReleaseNfoFingerprintFailuresQueueParityTest extends TestCase
{
    private const array CLASSES = [ReleaseNfo::class, ReleaseFingerprintMetadata::class, ReleaseNzbCreationFailure::class, ReleaseArticleFingerprintStore::class, DatabaseFingerprintQueue::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
        parent::tearDown();
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse((new \ReflectionClass($class))->getDefaultProperties()['useRustFfi'], $class.' declared production default');
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi());
            $class::enableRustFfi();
            self::assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            self::assertFalse($class::usesRustFfi());
            $class::enableRustFfi();
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi());
        }
    }

    /** @return list<array{class-string, string, list<mixed>, mixed}> */
    private static function cases(): array
    {
        $cases = [
            [ReleaseNfo::class, 'queryProjection', [false], ['columns' => ['releases_id'], 'raw' => null]],
            [ReleaseNfo::class, 'queryProjection', [true], ['columns' => ['releases_id'], 'raw' => 'UNCOMPRESS(nfo) AS nfo']],
            [ReleaseFingerprintMetadata::class, 'castPayload', [], ['metadata' => 'array', 'collected_at' => 'datetime']],
            [ReleaseNzbCreationFailure::class, 'castPayload', [], ['attempts' => 'integer']],
        ];
        foreach ([null, '', '0', str_repeat('A', 64), " \0\xffhash\r\n ", str_repeat("a\0\xff", 8192)] as $fingerprint) {
            $cases[] = [ReleaseArticleFingerprintStore::class, 'updatePayload', [$fingerprint], $fingerprint === null ? [] : ['article_fingerprint' => $fingerprint]];
        }
        foreach ([false, true] as $force) {
            $cases[] = [DatabaseFingerprintQueue::class, 'shouldSkip', [null, 'digest', $force], false];
            foreach (['', 'pending', 'processing', 'complete', 'failed', 'PENDING', "pending\0", "\xff"] as $status) {
                foreach (['', 'a', str_repeat('A', 64), "a\0\xff", str_repeat('a', 8192)] as $digest) {
                    foreach ([$digest, $digest.'x', strtolower($digest)] as $stored) {
                        $cases[] = [DatabaseFingerprintQueue::class, 'shouldSkip', [(object) ['source_digest' => $stored, 'status' => $status], $digest, $force], $stored === $digest && (! $force || in_array($status, ['pending', 'processing'], true))];
                    }
                }
            }
        }

        return $cases;
    }

    public function test_php_native_and_dispatch_match_without_hidden_fallback(): void
    {
        self::assertNotNull(RustPipelineLibrary::resolveDomain('wave21-nfo-fingerprint-failures-queue', ReleaseNfo::FFI_CDEF), 'Build the release FFI library first');
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
            }
            foreach (self::cases() as [$class, $method, $arguments, $expected]) {
                self::assertSame($expected, $class::{$method.'Php'}(...$arguments), $class.'::'.$method.' PHP');
                self::assertSame($expected, $class::{$method.'Native'}(...$arguments), $class.'::'.$method.' direct native');
                self::assertSame($expected, $class::$method(...$arguments), $class.'::'.$method.' dispatch');
            }
        }
        $random = new Randomizer(new Mt19937(2102));
        for ($i = 0; $i < 256; $i++) {
            $digest = $random->getBytes($random->getInt(1, 1024));
            self::assertSame(['article_fingerprint' => $digest], ReleaseArticleFingerprintStore::updatePayloadNative($digest));
            $job = (object) ['source_digest' => $i % 2 === 0 ? $digest : $digest.'x', 'status' => $i % 3 === 0 ? 'pending' : 'complete'];
            self::assertSame(DatabaseFingerprintQueue::shouldSkipPhp($job, $digest, true), DatabaseFingerprintQueue::shouldSkipNative($job, $digest, true));
        }
    }

    public function test_declined_inputs_preserve_php_short_circuit_and_errors(): void
    {
        DatabaseFingerprintQueue::enableRustFfi();
        $reads = [];
        $job = new class($reads)
        {
            /** @param list<string> $reads */
            public function __construct(public array &$reads) {}

            public function __get(string $name): string
            {
                $this->reads[] = $name;

                return $name === 'source_digest' ? 'a' : 'pending';
            }
        };
        self::assertNull(DatabaseFingerprintQueue::shouldSkipNative($job, 'a', false));
        self::assertSame([], $reads, 'Native eligibility must not invoke magic getters');
        self::assertTrue(DatabaseFingerprintQueue::shouldSkip($job, 'a', false));
        self::assertSame(['source_digest'], $reads, 'Unforced replay must not read status');
        foreach ([null, 1, false, [], (object) ['value' => 'pending']] as $status) {
            $row = (object) ['source_digest' => 'a', 'status' => $status];
            self::assertNull(DatabaseFingerprintQueue::shouldSkipNative($row, 'a', true));
            self::assertFalse(DatabaseFingerprintQueue::shouldSkip($row, 'a', true));
            self::assertTrue(DatabaseFingerprintQueue::shouldSkip($row, 'a', false));
        }
        $invalid = (object) ['source_digest' => 7, 'status' => 'pending'];
        self::assertNull(DatabaseFingerprintQueue::shouldSkipNative($invalid, 'a', false));
        $value = 'x';
        $referenced = [&$value];
        self::assertNull(ReleaseNfo::computeNative(3, $referenced));
        self::assertNull((new \ReflectionMethod(ReleaseNfo::class, 'computeNative'))->invoke(null, 3, [$job]));
        self::assertNull(ReleaseNfo::computeNative(3, [str_repeat('x', 16 * 1024 * 1024 + 1)]));
    }

    public function test_invalid_stored_digest_raises_the_original_php_error(): void
    {
        self::expectException(\TypeError::class);
        self::expectExceptionMessage('hash_equals');
        self::assertFalse(DatabaseFingerprintQueue::shouldSkipPhp((object) ['source_digest' => 7, 'status' => 'pending'], 'a', false));
    }

    public function test_invalid_stored_digest_raises_the_same_error_after_native_declines(): void
    {
        DatabaseFingerprintQueue::enableRustFfi();
        self::expectException(\TypeError::class);
        self::expectExceptionMessage('hash_equals');
        self::assertFalse(DatabaseFingerprintQueue::shouldSkip((object) ['source_digest' => 7, 'status' => 'pending'], 'a', false));
    }

    public function test_missing_library_falls_back_for_all_five_callers(): void
    {
        $property = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $previous = $property->getValue();
        $key = 'wave21-nfo-fingerprint-failures-queue|default|'.hash('xxh3', ReleaseNfo::FFI_CDEF);
        $property->setValue(null, [$key => false]);
        try {
            foreach (self::CLASSES as $class) {
                $class::enableRustFfi();
            }
            foreach (array_slice(self::cases(), 0, 12) as [$class, $method, $arguments, $expected]) {
                self::assertNull($class::{$method.'Native'}(...$arguments));
                self::assertSame($expected, $class::$method(...$arguments));
            }
        } finally {
            $property->setValue(null, $previous);
        }
    }

    public function test_abi_rejects_invalid_inputs_and_never_writes_partial_outputs(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('wave21-nfo-fingerprint-failures-queue', ReleaseNfo::FFI_CDEF);
        self::assertNotNull($ffi);
        $out = $ffi->new('char[256]');
        FFI::memset($out, 90, 256);
        foreach ([
            [999, 'a:0:{}'], [0, ''], [0, 'a:1:{i:0;i:1;}'], [0, 'a:1:{i:00;b:1;}'],
            [0, 'a:1:{s:1:"0";b:1;}'], [0, 'a:2:{i:0;b:1;i:0;b:0;}'],
            [0, 'a:1:{i:0;b:1;}x'], [0, 'a:1:{i:0;R:1;}'], [1, serialize([null])],
            [3, serialize([7])], [4, serialize(['a', 'pending', 'a', 1])],
        ] as [$operation, $wire]) {
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            self::assertSame(-1, $ffi->nntmux_w21_nfo_fingerprint_failures_queue($operation, $wire, strlen($wire), $out, 256));
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        }
        $wire = serialize([true]);
        foreach ([[null, 0, $out, 256], [$wire, PHP_INT_MAX, $out, 256], [$wire, strlen($wire), null, 0], [$wire, strlen($wire), $out, PHP_INT_MAX], [$out, 6, $out, 256]] as [$input, $length, $output, $capacity]) {
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            self::assertSame(-1, $ffi->nntmux_w21_nfo_fingerprint_failures_queue(0, $input, $length, $output, $capacity));
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        }
        $expected = serialize(ReleaseNfo::queryProjectionPhp(true));
        foreach ([0, 1, strlen($expected) - 1] as $capacity) {
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            self::assertSame(-strlen($expected), $ffi->nntmux_w21_nfo_fingerprint_failures_queue(0, $wire, strlen($wire), $out, $capacity));
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        }
        // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
        self::assertSame(strlen($expected), $ffi->nntmux_w21_nfo_fingerprint_failures_queue(0, $wire, strlen($wire), $out, strlen($expected)));
        self::assertSame($expected, FFI::string($out, strlen($expected)));
        self::assertSame(str_repeat('Z', 256 - strlen($expected)), FFI::string(FFI::addr($out[strlen($expected)]), 256 - strlen($expected)));
    }

    public function test_real_model_queries_casts_store_and_queue_transactions(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $previousResolver = Model::getConnectionResolver();
        $container = new Application(dirname(__DIR__, 2));
        $container->instance('config', new Repository);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $database = new Manager($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->bootEloquent();
        $connection = $database->getConnection();
        $container->instance('db', $database->getDatabaseManager());
        $schema = $connection->getSchemaBuilder();
        $container->instance('db.schema', $schema);
        try {
            $schema->create('release_nfos', static function (Blueprint $table): void {
                $table->integer('releases_id')->primary();
                $table->binary('nfo')->nullable();
            });
            $schema->create('releases', static function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('guid');
                $table->string('article_fingerprint')->nullable();
                $table->timestamps();
            });
            $schema->create('release_nzb_contents', static function (Blueprint $table): void {
                $table->string('guid')->primary();
                $table->string('source_digest');
            });
            $schema->create('release_fingerprint_metadata', static function (Blueprint $table): void {
                $table->integer('releases_id')->primary();
                $table->string('status');
                $table->string('run_token')->nullable();
                $table->timestamps();
            });
            $schema->create('release_fingerprint_jobs', static function (Blueprint $table): void {
                $table->integer('release_id')->primary();
                foreach (['source_digest', 'status'] as $column) {
                    $table->string($column);
                }
                $table->integer('attempts');
                foreach (['available_at', 'lease_token', 'lease_until', 'worker_id', 'last_error'] as $column) {
                    $table->string($column)->nullable();
                }
                $table->timestamps();
            });
            foreach ([false, true] as $enabled) {
                foreach (self::CLASSES as $class) {
                    $enabled ? $class::enableRustFfi() : $class::disableRustFfi();
                }
                foreach (['release_nfos', 'releases', 'release_nzb_contents', 'release_fingerprint_metadata', 'release_fingerprint_jobs'] as $table) {
                    $connection->table($table)->delete();
                }
                $connection->table('release_nfos')->insert([['releases_id' => 7, 'nfo' => "nfo\0\xff"], ['releases_id' => 8, 'nfo' => null]]);
                $nfo = ReleaseNfo::getReleaseNfo(7, false);
                self::assertInstanceOf(ReleaseNfo::class, $nfo);
                self::assertSame(['releases_id' => 7], $nfo->getAttributes());
                self::assertNull(ReleaseNfo::getReleaseNfo(8, false));
                // SQLite cannot execute MySQL's UNCOMPRESS; verify the real query it builds.
                $queries = $connection->pretend(static fn () => ReleaseNfo::getReleaseNfo(7, true));
                self::assertStringContainsString('UNCOMPRESS(nfo) AS nfo', $queries[0]['query']);
                $metadata = new ReleaseFingerprintMetadata;
                $metadata->setRawAttributes(['metadata' => '{"a":1,"b":null}', 'collected_at' => '2026-10-24 01:02:03']);
                self::assertSame(['a' => 1, 'b' => null], $metadata->metadata);
                self::assertSame('2026-10-24 01:02:03', $metadata->collected_at?->format('Y-m-d H:i:s'));
                $failure = new ReleaseNzbCreationFailure;
                $failure->setRawAttributes(['attempts' => '007']);
                self::assertSame(7, $failure->attempts);
                $failure->setRawAttributes(['attempts' => null]);
                self::assertNull($failure->attempts);
                $connection->table('releases')->insert([['id' => 7, 'guid' => 'g7'], ['id' => 2, 'guid' => 'g2']]);
                ReleaseArticleFingerprintStore::store(7, null);
                self::assertNull($connection->table('releases')->where('id', 7)->value('article_fingerprint'));
                ReleaseArticleFingerprintStore::store(7, 'UPPER mixed');
                ReleaseArticleFingerprintStore::store(2, 'UPPER mixed');
                self::assertSame([2, 7], ReleaseArticleFingerprintStore::candidates('UPPER mixed', ['id'])->pluck('id')->all());
                ReleaseArticleFingerprintStore::store(7, '');
                self::assertSame('', $connection->table('releases')->where('id', 7)->value('article_fingerprint'));
                $queue = new DatabaseFingerprintQueue;
                try {
                    $queue->enqueueLocked((object) ['id' => 7], 'a');
                    self::fail('Must require the source transaction');
                } catch (RuntimeException $error) {
                    self::assertSame('Fingerprint enqueue requires the source transaction.', $error->getMessage());
                }
                self::assertFalse($queue->enqueue(999));
                self::assertFalse($queue->enqueue(7));
                $connection->table('release_nzb_contents')->insert(['guid' => 'g7', 'source_digest' => 'a']);
                $connection->table('release_fingerprint_metadata')->insert(['releases_id' => 7, 'status' => 'complete', 'run_token' => 'old']);
                self::assertTrue($queue->enqueue(7));
                $row = (array) $connection->table('release_fingerprint_jobs')->first();
                self::assertSame('pending', $row['status']);
                self::assertSame(0, $row['attempts']);
                self::assertSame('stale', $connection->table('release_fingerprint_metadata')->value('status'));
                self::assertNull($connection->table('release_fingerprint_metadata')->value('run_token'));
                foreach (['pending', 'processing', 'complete', 'failed'] as $status) {
                    foreach ([false, true] as $force) {
                        $connection->table('release_fingerprint_jobs')->update(['status' => $status, 'attempts' => 3, 'lease_token' => 'claim', 'worker_id' => 'worker', 'lease_until' => 'later', 'last_error' => 'error']);
                        $connection->table('release_fingerprint_metadata')->update(['status' => 'complete', 'run_token' => 'old']);
                        $before = (array) $connection->table('release_fingerprint_jobs')->first();
                        self::assertTrue($queue->enqueue(7, $force));
                        $after = (array) $connection->table('release_fingerprint_jobs')->first();
                        if (! $force || in_array($status, ['pending', 'processing'], true)) {
                            self::assertSame($before, $after, 'Replay preserves claim, attempts and timestamps');
                            self::assertSame('complete', $connection->table('release_fingerprint_metadata')->value('status'));
                            self::assertSame('old', $connection->table('release_fingerprint_metadata')->value('run_token'));
                        } else {
                            self::assertSame('pending', $after['status']);
                            self::assertSame(0, $after['attempts']);
                            self::assertSame($before['created_at'], $after['created_at']);
                            foreach (['lease_token', 'lease_until', 'worker_id', 'last_error'] as $field) {
                                self::assertNull($after[$field]);
                            }
                        }
                    }
                }
                $connection->table('release_fingerprint_jobs')->update(['status' => 'processing', 'lease_token' => 'in-flight']);
                $connection->table('release_nzb_contents')->update(['source_digest' => 'b']);
                self::assertTrue($queue->enqueue(7));
                self::assertSame('b', $connection->table('release_fingerprint_jobs')->value('source_digest'));
                self::assertNull($connection->table('release_fingerprint_jobs')->value('lease_token'));
                $before = (array) $connection->table('release_fingerprint_jobs')->first();
                $metadataBefore = (array) $connection->table('release_fingerprint_metadata')->first();
                try {
                    $connection->transaction(static function () use ($queue): void {
                        $queue->enqueueLocked((object) ['id' => 7], 'rollback');
                        throw new RuntimeException('rollback fixture');
                    });
                } catch (RuntimeException $error) {
                    self::assertSame('rollback fixture', $error->getMessage());
                }
                self::assertSame($before, (array) $connection->table('release_fingerprint_jobs')->first());
                self::assertSame($metadataBefore, (array) $connection->table('release_fingerprint_metadata')->first());
            }
        } finally {
            $connection->disconnect();
            if ($previousResolver !== null) {
                Model::setConnectionResolver($previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
            Container::setInstance($previousContainer);
        }
    }

    /** @return array<string, array{class-string, string, array<string, list<list<mixed>>>}> */
    private static function benchmarkGroups(): array
    {
        $long = str_repeat("a\0\xff", 8192);

        return [
            'nfo.queryProjection' => [ReleaseNfo::class, 'queryProjection', ['common' => [[true]], 'uncommon' => [[false]]]],
            // These two fixed projections have no input-dependent uncommon path.
            'metadata.castPayload' => [ReleaseFingerprintMetadata::class, 'castPayload', ['common' => [[]], 'uncommon' => [[]]]],
            'failure.castPayload' => [ReleaseNzbCreationFailure::class, 'castPayload', ['common' => [[]], 'uncommon' => [[]]]],
            'store.updatePayload' => [ReleaseArticleFingerprintStore::class, 'updatePayload', ['common' => [[str_repeat('a', 64)]], 'uncommon' => [[null], [''], [$long]]]],
            'queue.shouldSkip' => [DatabaseFingerprintQueue::class, 'shouldSkip', ['common' => [[(object) ['source_digest' => str_repeat('a', 64), 'status' => 'pending'], str_repeat('a', 64), false]], 'uncommon' => [[null, '', true], [(object) ['source_digest' => $long, 'status' => 'processing'], $long, true], [(object) ['source_digest' => $long, 'status' => 'failed'], $long.'x', true]]]],
        ];
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_W21_NFO_QUEUE_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_W21_NFO_QUEUE_BENCH=1');
        }
        $destination = sys_get_temp_dir().'/nntmux-wave21-nfo-queue-'.bin2hex(random_bytes(6)).'.json';
        $random = new Randomizer(new Mt19937(2102));
        $groups = self::benchmarkGroups();
        $root = dirname(__DIR__, 2);
        $paths = ['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php', 'tests/Unit/RustReleaseNfoFingerprintFailuresQueueParityTest.php'];
        foreach (self::CLASSES as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertNotFalse($path);
            $paths[] = substr($path, strlen($root) + 1);
        }
        $hashes = array_combine($paths, array_map(static fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), $paths));
        $library = $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $metadata = [
            'utc' => gmdate(DATE_ATOM), 'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'baseline' => 'Extracted original PHP expressions; dispatched through the same public helper with Rust disabled',
            'build_command' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml',
            'repetitions' => 3, 'paired_samples' => 41, 'inner_loops' => 200, 'warmup_pairs' => 16, 'seed' => 2102,
            'protocol' => 'Identical fixture per pair, seeded random fixture and PHP/Rust order; 16 warmup pairs per repetition; 200 public dispatch calls per observation including bridge validation, serialization, allocation, FFI and decode. Native eligibility/parity and toggling outside timing. Mean and nearest-rank p99 of the 41 per-call batch averages. With 41 samples p99 is the maximum; tail estimates are coarse. Gate uses worst repetition for each metric, >=1.5 on both workloads. Fixed cast payloads have identical common/uncommon inputs. Pure CPU helper screen, no production throughput claim.',
            'php' => PHP_VERSION, 'os' => php_uname(), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')),
            'rustc' => trim((string) shell_exec('rustc --version')), 'cargo' => trim((string) shell_exec('cargo --version')),
            'limits' => (string) shell_exec('ulimit -a'), 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'source_sha256' => $hashes, 'library_sha256' => hash_file('sha256', $library),
            'inputs_base64_php_serialized' => base64_encode(serialize($groups)),
        ];
        $results = [];
        $failure = null;
        try {
            foreach ($groups as $name => [$class, $method, $workloads]) {
                foreach ($workloads as $workload => $inputs) {
                    foreach ($inputs as $arguments) {
                        self::assertSame($class::{$method.'Php'}(...$arguments), $class::{$method.'Native'}(...$arguments), 'Native eligibility before measurement');
                    }
                    for ($repetition = 0; $repetition < 3; $repetition++) {
                        $raw = [];
                        for ($sample = -16; $sample < 41; $sample++) {
                            $fixture = $random->getInt(0, count($inputs) - 1);
                            $arguments = $inputs[$fixture];
                            $expected = $class::{$method.'Php'}(...$arguments);
                            $order = $random->getInt(0, 1);
                            $times = [];
                            foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                                $which === 1 ? $class::enableRustFfi() : $class::disableRustFfi();
                                $start = hrtime(true);
                                for ($loop = 0; $loop < 200; $loop++) {
                                    $observed = $class::$method(...$arguments);
                                }
                                $times[$which] = (hrtime(true) - $start) / 200;
                                self::assertSame($expected, $observed);
                            }
                            if ($sample >= 0) {
                                $raw[] = [$fixture, $order, $times[0], $times[1]];
                            }
                        }
                        $php = array_column($raw, 2);
                        $native = array_column($raw, 3);
                        sort($php);
                        sort($native);
                        $mean = array_sum($php) / array_sum($native);
                        $p99 = $php[40] / $native[40];
                        $results[$name][$workload][] = [
                            'raw_fixture_order_php_native_ns_per_call' => $raw,
                            'php_mean_ns' => array_sum($php) / 41, 'native_mean_ns' => array_sum($native) / 41,
                            'php_p99_ns' => $php[40], 'native_p99_ns' => $native[40],
                            'mean_speedup' => $mean, 'p99_speedup' => $p99,
                            'gate_passed' => $mean >= 1.5 && $p99 >= 1.5,
                        ];
                        fwrite(STDOUT, sprintf("%s %s repetition %d mean %.4fx p99 %.4fx\n", $name, $workload, $repetition + 1, $mean, $p99));
                    }
                }
            }
            self::assertSame($hashes, array_combine($paths, array_map(static fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), $paths)), 'Frozen sources');
            self::assertSame($metadata['library_sha256'], hash_file('sha256', $library), 'Frozen library');
        } catch (Throwable $error) {
            $failure = ['class' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            file_put_contents($destination, json_encode($metadata + ['results' => $results, 'failure' => $failure], JSON_THROW_ON_ERROR));
            fwrite(STDOUT, 'Benchmark evidence: '.$destination."\n");
        }
    }
}
