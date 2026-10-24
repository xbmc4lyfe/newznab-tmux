<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Par2PacketAccumulator;
use App\Services\Releases\Verification\FileVerificationLockStore;
use App\Services\Releases\Verification\LeaseHeartbeat;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\VerificationLockStore;
use App\Services\Yenc\DecoderFactory;
use App\Services\Yenc\PhpPayloadDecoder;
use App\Support\Par2DescriptorEvidence;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

final class RustVerificationLeaseLockScratchYencPar2ParityTest extends TestCase
{
    private const CLASSES = [LeaseHeartbeat::class, VerificationLockStore::class, ScratchBudget::class, DecoderFactory::class, Par2DescriptorEvidence::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(false);
            }
        }
        parent::tearDown();
    }

    public function test_required_helpers_exist(): void
    {
        foreach (self::cases() as [$class, $method]) {
            self::assertTrue(method_exists($class, $method.'Native'), $class.'::'.$method.'Native');
        }
    }

    /** @return array<string, string> */
    private static function descriptor(string $name = "file.mkv\0\xff"): array
    {
        return ['packet_checksum' => str_repeat('a', 32), 'recovery_set_id' => str_repeat('b', 32), 'file_id' => str_repeat('c', 32), 'hash' => str_repeat('d', 32), 'hash_16K' => str_repeat('e', 32), 'size_decimal' => '18446744073709551615', 'filename_bytes' => $name];
    }

    /** @return array<string, int|string> */
    private static function row(string $name = "file.mkv\0\xff"): array
    {
        return ['releases_id' => 7, 'release_guid' => "g\0\xff", 'packet_checksum' => str_repeat('a', 32), 'recovery_set_id' => str_repeat('b', 32), 'file_id' => str_repeat('c', 32), 'full_md5' => str_repeat('d', 32), 'hash16k' => str_repeat('e', 32), 'file_size' => '18446744073709551615', 'raw_filename_base64' => base64_encode($name), 'descriptor_proof_version' => 1, 'classification' => 'unknown', 'classification_version' => 0, 'classification_provenance' => 'unclassified'];
    }

    /** @return list<array{class-string, string, list<mixed>, mixed}> */
    private static function cases(): array
    {
        $cases = [];
        foreach ([[null, 0.0, 30.0, true], [0.0, 29.999, 30.0, false], [0.0, 30.0, 30.0, true], [10.0, 9.0, 1.0, false], [0.0, 0.0, 0.0, true], [1.0, 1.0, -1.0, true], [-1e200, 1e200, 1e200, true]] as [$last, $now, $interval, $expected]) {
            $cases[] = [LeaseHeartbeat::class, 'renewalDue', [$last, $now, $interval], $expected];
        }
        foreach ([['auto', 'mysql', 'database'], ['auto', 'mariadb', 'database'], ['auto', 'sqlite', 'file'], ['auto', 'MYSQL', 'file'], ['database', '', 'database'], ['file', 'mysql', 'file'], ['', 'mysql', null], ["file\0", 'mysql', null]] as [$driver, $db, $expected]) {
            $cases[] = [VerificationLockStore::class, 'resolveDriver', [$driver, $db], $expected];
        }
        foreach (['', '/root/', "a\0\xff"] as $root) {
            foreach ([false, true] as $locks) {
                $cases[] = [VerificationLockStore::class, 'lockPath', [$root, $locks], $locks ? $root.'/locks' : $root];
            }
            $cases[] = [VerificationLockStore::class, 'stateFilePath', [$root, "name\0\xff"], $root."/name\0\xff.state"];
        }
        foreach ([[100, 1000.9, 50, 100], [1000, 10.9, 5, 5], [100, -0.1, 5, -6], [0, 0.0, 0, 0], [PHP_INT_MAX, 1024.0, 2048, -1024]] as [$scratch, $free, $reserve, $expected]) {
            $cases[] = [ScratchBudget::class, 'reservationCap', [$scratch, $free, $reserve], $expected];
        }
        foreach ([[0, 0], [10, 5], [0, PHP_INT_MAX], [PHP_INT_MIN, 1], [PHP_INT_MAX, -1]] as [$bytes, $reserved]) {
            $cases[] = [ScratchBudget::class, 'reservationDelta', [$bytes, $reserved], $bytes - $reserved];
        }
        foreach ([[0, 0], [10, -20], [10, -10], [PHP_INT_MAX, 1], [PHP_INT_MIN, -1], [10, 5]] as [$reserved, $delta]) {
            $cases[] = [ScratchBudget::class, 'adjustedBudget', [$reserved, $delta], max(0, $reserved + $delta)];
        }
        foreach ([[0, 10, 10, false], [0, 11, 10, true], [1, 0, 10, true], [-1, 0, 10, true], [0, PHP_INT_MIN, PHP_INT_MAX, false]] as [$delta, $reserved, $limit, $expected]) {
            $cases[] = [ScratchBudget::class, 'rejectUnchanged', [$delta, $reserved, $limit], $expected];
        }
        foreach (['auto', 'php', 'native', '', 'PHP', "auto\0"] as $mode) {
            $cases[] = [DecoderFactory::class, 'validMode', [$mode], in_array($mode, ['auto', 'php', 'native'], true)];
            foreach (['', "lib:\0\xff"] as $library) {
                foreach ([['cli', 'Linux'], ['fpm-fcgi', 'Linux'], ['cli', 'Darwin']] as [$sapi, $os]) {
                    $cases[] = [DecoderFactory::class, 'selectsPhp', [$mode, $library, $sapi, $os], $mode === 'php' || ($mode === 'auto' && ($library === '' || $sapi !== 'cli' || $os !== 'Linux'))];
                }
                $cases[] = [DecoderFactory::class, 'cacheKey', [PHP_INT_MAX, $mode, $library], PHP_INT_MAX.':'.$mode.':'.$library];
            }
        }
        $cases[] = [DecoderFactory::class, 'failureKey', [-1, "\0\xff:"], "-1:\0\xff:"];
        foreach (['', "file.mkv\0\xff", str_repeat("\0\xff", 4096)] as $name) {
            $row = self::row($name);
            $cases[] = [Par2DescriptorEvidence::class, 'descriptorRow', [7, "g\0\xff", self::descriptor($name)], $row];
            $stored = $row + ['ignored' => 'extra'];
            foreach (['releases_id', 'descriptor_proof_version', 'classification_version'] as $column) {
                $stored[$column] = (string) $stored[$column];
            }
            $cases[] = [Par2DescriptorEvidence::class, 'evidenceRowsEqual', [$stored, $row], true];
            foreach ($row as $column => $value) {
                $changed = $stored;
                $changed[$column] = is_int($value) ? $value + 1 : $value.'x';
                $cases[] = [Par2DescriptorEvidence::class, 'evidenceRowsEqual', [$changed, $row], false];
            }
        }

        return $cases;
    }

    public function test_php_native_and_dispatch_match_edge_cases_without_hidden_fallback(): void
    {
        self::assertNotNull(RustPipelineLibrary::resolveDomain('nfo', LeaseHeartbeat::FFI_CDEF));
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            foreach (self::cases() as [$class, $method, $args, $expected]) {
                self::assertSame($expected, $class::{$method.'Php'}(...$args), $method.' PHP');
                self::assertSame($expected, $class::{$method.'Native'}(...$args), $method.' direct native');
                self::assertSame($expected, $class::$method(...$args), $method.' dispatch');
            }
        }
    }

    public function test_real_heartbeat_retry_and_decoder_cache_behaviour(): void
    {
        foreach ([false, true] as $enabled) {
            LeaseHeartbeat::setUseRustFfi($enabled);
            DecoderFactory::setUseRustFfi($enabled);
            $now = 0.0;
            $attempts = 0;
            $beat = new LeaseHeartbeat(static function () use (&$attempts): void {
                if (++$attempts === 1) {
                    throw new RuntimeException('lost lease');
                }
            }, 30.0, static function () use (&$now): float {
                return $now;
            });
            try {
                $beat();
                self::fail('Renewal failures must propagate');
            } catch (RuntimeException $error) {
                self::assertSame('lost lease', $error->getMessage());
            }
            $beat();
            $now = 29.9;
            $beat();
            self::assertSame(2, $attempts);
            $now = 30.0;
            $beat();
            self::assertSame(3, $attempts);
            $factory = new DecoderFactory;
            $php = $factory->make('php', '');
            self::assertInstanceOf(PhpPayloadDecoder::class, $php);
            self::assertSame($php, $factory->make('php', ''));
            self::assertInstanceOf(PhpPayloadDecoder::class, $factory->make('auto', ''));
            try {
                $factory->make('invalid');
                self::fail('Invalid mode must throw');
            } catch (RuntimeException $error) {
                self::assertSame('YENC_DECODER must be auto, php or native.', $error->getMessage());
            }
        }
        foreach (self::CLASSES as $class) {
            self::assertFalse((new \ReflectionClass($class))->getDefaultProperties()['useRustFfi']);
        }
    }

    public function test_bridge_rejects_unsupported_values_and_invalid_abi_ranges(): void
    {
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \LogicException('must not serialize');
            }
        };
        self::assertNull(LeaseHeartbeat::computeNative(13, [[$object], []]));
        $cycle = [];
        $cycle['self'] = &$cycle;
        self::assertNull(LeaseHeartbeat::computeNative(13, [$cycle, []]));
        $ffi = RustPipelineLibrary::resolveDomain('nfo', LeaseHeartbeat::FFI_CDEF);
        self::assertNotNull($ffi);
        $out = $ffi->new('char[32]');
        FFI::memset($out, 90, 32);
        $wire = serialize(['auto']);
        foreach ([[999, $wire, strlen($wire), $out, 32], [8, 'junk', 4, $out, 32], [8, $wire.'x', strlen($wire) + 1, $out, 32], [8, null, 0, $out, 32], [8, $wire, PHP_INT_MAX, $out, 32], [8, $wire, strlen($wire), null, 0], [8, $wire, strlen($wire), $out, PHP_INT_MAX]] as $args) {
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            self::assertSame(-1, $ffi->nntmux_w20_verification_helpers(...$args));
            self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
        }
        // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
        self::assertSame(-4, $ffi->nntmux_w20_verification_helpers(8, $wire, strlen($wire), $out, 1));
        self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
    }

    public function test_nonfinite_gates_and_declined_numeric_casts_preserve_php_fallback(): void
    {
        foreach ([[null, NAN, INF], [NAN, INF, 1.0], [INF, INF, 1.0], [0.0, INF, 30.0], [0.0, 1.0, NAN]] as [$last, $now, $interval]) {
            $expected = $last === null || ($now - $last) >= $interval;
            self::assertSame($expected, LeaseHeartbeat::renewalDueNative($last, $now, $interval));
            LeaseHeartbeat::setUseRustFfi(true);
            self::assertSame($expected, LeaseHeartbeat::renewalDue($last, $now, $interval));
        }
        ScratchBudget::setUseRustFfi(true);
        foreach ([[INF, 0], [-INF, 0], [NAN, 0], [(float) PHP_INT_MAX, 0], [(float) PHP_INT_MIN, 1]] as [$free, $reserve]) {
            self::assertNull(ScratchBudget::reservationCapNative(100, $free, $reserve), 'Unsafe casts must decline');
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = [$severity, $message];

                return true;
            });
            try {
                $expected = min(100, (int) floor($free) - $reserve);
                $baselineWarnings = $warnings;
                $warnings = [];
                self::assertSame($expected, ScratchBudget::reservationCap(100, $free, $reserve));
                self::assertSame($baselineWarnings, $warnings, 'PHP cast diagnostics must be preserved');
            } finally {
                restore_error_handler();
            }
        }
        self::assertNull(ScratchBudget::rejectUnchangedNative(0.0, 1, 1));
        self::assertTrue(ScratchBudget::rejectUnchanged(0.0, 1, 1), 'Strict float/int zero inequality');
        Par2DescriptorEvidence::setUseRustFfi(true);
        foreach (['7foo', '7.0', '7e0', '9223372036854775808'] as $cast) {
            self::assertNull(Par2DescriptorEvidence::evidenceRowsEqualNative(['releases_id' => $cast], ['releases_id' => 7]));
            self::assertSame((int) $cast === 7, Par2DescriptorEvidence::evidenceRowsEqual(['releases_id' => $cast], ['releases_id' => 7]));
        }
        self::assertSame(['value' => null], LeaseHeartbeat::computeNative(1, ['invalid', 'mysql']), 'A valid null result differs from a declined ABI call');
    }

    public function test_real_scratch_transactions_and_descriptor_replays(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 2));
        $config = new Repository(['release_verification' => ['storage_path' => sys_get_temp_dir(), 'scratch_bytes' => 100, 'free_bytes' => 0]]);
        $container->instance('config', $config);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $database = new Manager($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $database->getConnection();
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        $container->instance('db', $database->getDatabaseManager());
        $schema = $connection->getSchemaBuilder();
        $container->instance('db.schema', $schema);
        try {
            $schema->create('release_verification_scratch', static function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('host');
                $table->integer('reserved_bytes');
            });
            $schema->create('release_verification_scratch_budget', static function (Blueprint $table): void {
                $table->string('host')->primary();
                $table->boolean('initialized');
                $table->integer('reserved_bytes');
            });
            $schema->create('releases', static function (Blueprint $table): void {
                $table->integer('id')->primary();
                foreach (['guid', 'name', 'searchname'] as $column) {
                    $table->string($column)->nullable();
                }
                $table->integer('predb_id');
                $table->integer('isrenamed');
            });
            (require dirname(__DIR__, 2).'/database/migrations/2026_10_15_190000_create_par2_descriptor_evidence_table.php')->up();
            $bytes = file_get_contents(__DIR__.'/../Fixtures/par2/rename-index.par2');
            self::assertIsString($bytes);
            $inventory = new Par2PacketAccumulator;
            $inventory->append($bytes);
            self::assertCount(10, $inventory->descriptors());
            foreach ([false, true] as $enabled) {
                ScratchBudget::setUseRustFfi($enabled);
                Par2DescriptorEvidence::setUseRustFfi($enabled);
                VerificationLockStore::setUseRustFfi($enabled);
                foreach (['release_verification_scratch', 'release_verification_scratch_budget', 'par2_descriptor_evidence', 'releases'] as $table) {
                    $connection->table($table)->delete();
                }
                $connection->table('release_verification_scratch')->insert(['id' => 1, 'host' => 'test', 'reserved_bytes' => 0]);
                $connection->table('release_verification_scratch_budget')->insert(['host' => 'test', 'initialized' => true, 'reserved_bytes' => 0]);
                $budget = new ScratchBudget;
                $budget->reserve(1, 40);
                $budget->reserve(1, 40);
                self::assertSame(40, (int) $connection->table('release_verification_scratch_budget')->value('reserved_bytes'));
                try {
                    $budget->reserve(1, 101);
                    self::fail('Capacity rejection must propagate');
                } catch (RuntimeException $error) {
                    self::assertSame('Verification is waiting for shared scratch capacity.', $error->getMessage());
                }
                self::assertSame(40, (int) $connection->table('release_verification_scratch')->value('reserved_bytes'));
                self::assertSame(40, (int) $connection->table('release_verification_scratch_budget')->value('reserved_bytes'));
                $budget->reserve(1, 5);
                self::assertSame(5, (int) $connection->table('release_verification_scratch_budget')->value('reserved_bytes'));
                $budget->forget(1);
                self::assertSame(0, (int) $connection->table('release_verification_scratch_budget')->value('reserved_bytes'));
                self::assertSame(0, $connection->table('release_verification_scratch')->count());
                $config->set('release_verification.locks', 'auto');
                self::assertInstanceOf(FileVerificationLockStore::class, VerificationLockStore::make());
                $config->set('release_verification.locks', 'invalid');
                try {
                    VerificationLockStore::make();
                    self::fail('Unknown lock driver must throw');
                } catch (RuntimeException $error) {
                    self::assertSame('Unknown verification lock driver.', $error->getMessage());
                }
                $snapshot = ['id' => 7, 'guid' => 'guid', 'name' => 'release', 'searchname' => null, 'predb_id' => 0, 'isrenamed' => 0];
                $connection->table('releases')->insert($snapshot);
                self::assertTrue(Par2DescriptorEvidence::remember($inventory, $snapshot));
                self::assertTrue(Par2DescriptorEvidence::remember($inventory, $snapshot));
                self::assertSame(10, $connection->table('par2_descriptor_evidence')->count());
                self::assertFalse(Par2DescriptorEvidence::remember($inventory, $snapshot, static fn (): bool => false));
                $descriptor = $inventory->descriptors()[0];
                $connection->table('par2_descriptor_evidence')->where('packet_checksum', $descriptor['packet_checksum'])->update(['hash16k' => str_repeat('f', 32)]);
                try {
                    Par2DescriptorEvidence::remember($inventory, $snapshot);
                    self::fail('Conflicting descriptor replay must throw');
                } catch (RuntimeException $error) {
                    self::assertSame('PAR2 descriptor checksum identity contains different evidence.', $error->getMessage());
                }
                self::assertSame(10, $connection->table('par2_descriptor_evidence')->count());
                $snapshot['guid'] = 'new-guid';
                $connection->table('releases')->where('id', 7)->update(['guid' => $snapshot['guid']]);
                self::assertTrue(Par2DescriptorEvidence::remember($inventory, $snapshot));
                self::assertSame(10, $connection->table('par2_descriptor_evidence')->where('release_guid', 'new-guid')->count());
                self::assertSame(0, $connection->table('par2_descriptor_evidence')->where('release_guid', 'guid')->count());
            }
        } finally {
            $connection->disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
            Container::setInstance($previousContainer);
        }
    }

    /** @return array<string, list<array{class-string, string, list<mixed>, mixed}>> */
    private static function benchmarkGroups(): array
    {
        $groups = [];
        foreach (self::cases() as $case) {
            $groups[$case[1]][] = $case;
        }
        $long = str_repeat("x\0\xff", 8192);
        foreach ([
            [VerificationLockStore::class, 'lockPath', [$long, true], $long.'/locks'],
            [VerificationLockStore::class, 'stateFilePath', [$long, $long], $long.'/'.$long.'.state'],
            [DecoderFactory::class, 'cacheKey', [1, 'auto', $long], '1:auto:'.$long],
            [DecoderFactory::class, 'failureKey', [1, $long], '1:'.$long],
            [Par2DescriptorEvidence::class, 'descriptorRow', [7, "g\0\xff", self::descriptor($long)], self::row($long)],
            [Par2DescriptorEvidence::class, 'evidenceRowsEqual', [self::row($long), self::row($long)], true],
        ] as $case) {
            $groups[$case[1]][] = $case;
        }
        $common = [
            [LeaseHeartbeat::class, 'renewalDue', [100.0, 101.0, 30.0], false],
            [VerificationLockStore::class, 'lockPath', ['/storage/verification', true], '/storage/verification/locks'],
            [VerificationLockStore::class, 'stateFilePath', ['/storage/verification/locks', 'publish'], '/storage/verification/locks/publish.state'],
            [DecoderFactory::class, 'failureKey', [1234, '/usr/lib/librapidyenc.so'], '1234:/usr/lib/librapidyenc.so'],
            [Par2DescriptorEvidence::class, 'descriptorRow', [7, "g\0\xff", self::descriptor('movie.mkv')], self::row('movie.mkv')],
            [Par2DescriptorEvidence::class, 'evidenceRowsEqual', [self::row('movie.mkv'), self::row('movie.mkv')], true],
        ];
        foreach ($common as $case) {
            array_unshift($groups[$case[1]], $case);
        }
        foreach ($groups as &$fixtures) {
            if (count($fixtures) === 1) {
                $fixtures[] = $fixtures[0];
            }
        }
        unset($fixtures);

        return $groups;
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_W20_LEASE_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_W20_LEASE_BENCH=1');
        }
        $random = new Randomizer(new Mt19937(2002));
        $destination = sys_get_temp_dir().'/nntmux-wave20-lease-'.bin2hex(random_bytes(6)).'.json';
        $root = dirname(__DIR__, 2);
        $paths = ['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.lock', 'rust/pipeline-ffi/Cargo.toml', 'composer.lock', 'vendor/composer/installed.php', 'tests/Unit/RustVerificationLeaseLockScratchYencPar2ParityTest.php', 'app/Support/RustPipelineLibrary.php'];
        foreach (self::CLASSES as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertNotFalse($path);
            $paths[] = substr($path, strlen($root) + 1);
        }
        $library = $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $hashes = array_combine($paths, array_map(static fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), $paths));
        $metadata = ['utc' => gmdate(DATE_ATOM), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'build_command' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml', 'seed' => 2002, 'samples' => 3000, 'warmup_pairs' => 64, 'php' => PHP_VERSION, 'os' => php_uname(), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'limits' => (string) shell_exec('ulimit -a'), 'rustc' => trim((string) shell_exec('rustc --version')), 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'source_sha256' => $hashes, 'library_sha256' => hash_file('sha256', $library), 'inputs_base64_php_serialized' => base64_encode(serialize(self::benchmarkGroups())), 'protocol' => 'Same-input single-call public dispatch pairs; randomized order and fixture; 64 warmups, 3000 pairs per helper/workload; serialization, FFI and allocation included; parity and toggle outside timing; nearest-rank p99, approximate 95 percent rank interval. Pure helper comparison only; SQL remains database-side, no production throughput claim.'];
        $results = [];
        $failure = null;
        try {
            foreach (self::benchmarkGroups() as $method => $fixtures) {
                foreach (['common' => [$fixtures[0]], 'uncommon' => array_slice($fixtures, 1)] as $workload => $inputs) {
                    foreach ($inputs as [$class, , $args, $expected]) {
                        self::assertSame($expected, $class::{$method.'Native'}(...$args), 'Native eligibility before timing');
                    }
                    $raw = [];
                    for ($i = -64; $i < 3000; $i++) {
                        $fixture = $random->getInt(0, count($inputs) - 1);
                        [$class, , $args, $expected] = $inputs[$fixture];
                        $order = $random->getInt(0, 1);
                        $times = [];
                        foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                            $class::setUseRustFfi($which === 1);
                            $start = hrtime(true);
                            $observed = $class::$method(...$args);
                            $times[$which] = hrtime(true) - $start;
                            self::assertSame($expected, $observed);
                        }
                        if ($i >= 0) {
                            $raw[] = [$fixture, $order, $times[0], $times[1]];
                        }
                    }
                    $php = array_column($raw, 2);
                    $native = array_column($raw, 3);
                    sort($php);
                    sort($native);
                    $mean = array_sum($php) / array_sum($native);
                    $p99 = $php[2969] / $native[2969];
                    $results[$method][$workload] = ['raw_fixture_order_php_native_ns' => $raw, 'php_mean_ns' => array_sum($php) / 3000, 'native_mean_ns' => array_sum($native) / 3000, 'php_p99_ns' => $php[2969], 'native_p99_ns' => $native[2969], 'mean_speedup' => $mean, 'p99_speedup' => $p99, 'p99_speedup_approx_95_rank_interval' => [$php[2958] / $native[2980], $php[2980] / $native[2958]], 'gate_passed' => $mean >= 1.5 && $p99 >= 1.5];
                    fwrite(STDOUT, sprintf("%s %s mean %.3fx p99 %.3fx\n", $method, $workload, $mean, $p99));
                }
            }
            self::assertSame($hashes, array_combine($paths, array_map(static fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), $paths)), 'Frozen sources');
            self::assertSame($metadata['library_sha256'], hash_file('sha256', $library), 'Frozen library');
        } catch (\Throwable $error) {
            $failure = ['class' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            file_put_contents($destination, json_encode($metadata + ['results' => $results, 'failure' => $failure], JSON_THROW_ON_ERROR));
            fwrite(STDOUT, 'Benchmark evidence: '.$destination."\n");
        }
    }
}
