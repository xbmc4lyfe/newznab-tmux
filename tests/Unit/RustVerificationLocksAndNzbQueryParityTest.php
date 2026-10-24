<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\Nzb\NzbCreationCandidateQuery as Query;
use App\Services\Releases\Verification\DatabaseVerificationLockStore as DatabaseLocks;
use App\Services\Releases\Verification\OversizedCollectionDeferrals as Deferrals;
use App\Services\Releases\Verification\PublicationOutbox as Outbox;
use App\Services\Releases\Verification\VerificationLock;
use App\Services\Releases\Verification\VerificationLockStore;
use App\Services\Releases\Verification\VerificationWorkerLocks as Workers;
use App\Support\Configuration\PostProcessingConfigurationData;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Opt-in admission experiment: WAVE15_BENCHMARK=1 php artisan test --compact
 * tests/Unit/RustVerificationLocksAndNzbQueryParityTest.php --filter=benchmark
 * Two independent repetitions, 2048 randomized single-call pairs per cell, 100
 * warmups per path. Includes configuration/DB lookup, filesystem reads, packing,
 * FFI, allocation and decoding. Raw samples and source/tool/input identities go
 * to a temporary JSON artifact. Each of four metrics must pass in BOTH repeats.
 * Defaults remain PHP until that experiment qualifies an individual method.
 */
final class RustVerificationLocksAndNzbQueryParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [Outbox::class, DatabaseLocks::class, Workers::class, Deferrals::class, Query::class];

    private Container $previousContainer;

    private mixed $previousFacadeApp;

    private Container $container;

    private Manager $database;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApp = Facade::getFacadeApplication();
        $application = new Application;
        $this->container = $application;
        Container::setInstance($this->container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
        $this->container->instance('config', new Repository(['database' => ['default' => 'testing'],
            'release_verification' => ['lease_seconds' => 300, 'candidate_workers' => 2, 'host' => 'worker-a']]));
        $this->container->instance('files', new Filesystem);
        $this->container->instance('cache', new CacheRepository(new ArrayStore));
        $this->database = new Manager($this->container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'testing');
        $this->container->instance('db', $this->database->getDatabaseManager());
        $this->database->getDatabaseManager()->setDefaultConnection('testing');
        $this->container->bind('db.schema', fn () => $this->database->getConnection('testing')->getSchemaBuilder());
        $this->database->getConnection('testing')->statement('CREATE TABLE post_processing_configurations (id INTEGER PRIMARY KEY)');
        $this->timeout(120);
        $this->root = sys_get_temp_dir().'/nntmux-wave15-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            self::assertNotNull(RustPipelineLibrary::resolveDomain('nfo', self::cdef($class)), 'Build the release library; parity cannot silently use fallback.');
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
        }
        (new Filesystem)->deleteDirectory($this->root);
        $this->database->getDatabaseManager()->disconnect('testing');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApp);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    /** @param class-string $class */
    private static function cdef(string $class): string
    {
        return (new ReflectionClass($class))->getConstant('FFI_CDEF');
    }

    private function timeout(int $timeout): void
    {
        $values = PostProcessingConfigurationData::defaults()->toArray();
        $values['releaseTimeoutSeconds'] = $timeout;
        $this->container->make('cache')->put('configuration:post-processing', new PostProcessingConfigurationData(...$values));
    }

    /** @return array<string, mixed> */
    private static function document(float $enqueued = 1000.0): array
    {
        return ['claim' => ['id' => 7, 'version' => 2, 'manifest_hash' => 'hash', 'lease_token' => 'token'],
            'reason' => 'verified', 'evidence' => ['articles' => 2, 'bytes' => 1024, 'sampled_files' => 1,
                'verified_file_names' => ['a.bin'], 'identity_hints' => ['title']], 'enqueued_at' => $enqueued,
            'not_before' => 0.0, 'attempts' => 0, 'deferrals' => 0];
    }

    public function test_key_hashes_member_errors_slots_and_host_names_preserve_boundaries(): void
    {
        $locks = new DatabaseLocks('testing');
        $random = new Randomizer(new Mt19937(1501));
        foreach (['', 'worker', "a\0b", str_repeat('x', 47), str_repeat('x', 48), 'é'] as $name) {
            self::assertSame(self::outcome(fn () => $locks->keyPhp($name)), self::outcome(fn () => $locks->keyNative($name)));
        }
        foreach (['worker', 'reset', 'unknown', '', "worker\0"] as $group) {
            self::assertSame(self::outcome(fn () => $locks->membersPhp($group)), self::outcome(fn () => $locks->membersNative($group)));
        }
        foreach (['', 'host', "host\0binary", str_repeat('long-host-', 100)] as $host) {
            config(['release_verification.host' => $host]);
            foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $number) {
                self::assertSame(Workers::forwarderLockNamePhp($number), Workers::forwarderLockNameNative($number));
            }
        }
        for ($i = 0; $i < 300; $i++) {
            config(['release_verification.candidate_workers' => $random->getInt(-1000, 1000),
                'release_verification.slot_offset' => $random->getInt(-1000, 1000),
                'release_verification.metadata_bytes' => $random->getInt(-1000, PHP_INT_MAX)]);
            self::assertSame(Workers::slotsPhp(), Workers::slotsNative());
            self::assertSame(Workers::firstSlotPhp(), Workers::firstSlotNative());
            self::assertSame(Workers::lastSlotPhp(), Workers::lastSlotNative());
            self::assertSame(Deferrals::budgetPhp(), Deferrals::budgetNative());
            Workers::setUseRustFfi(true);
            self::assertSame(Workers::lastSlotPhp(), Workers::lastSlot());
            Workers::setUseRustFfi(false);
            $hash = bin2hex($random->getBytes(40));
            self::assertSame($locks->keyPhp(substr($hash, 0, 47)), $locks->keyNative(substr($hash, 0, 47)));
        }
    }

    public function test_protection_keeps_short_circuit_and_checks_the_selected_live_lock(): void
    {
        foreach ([false, true] as $exclusive) {
            foreach ([false, true] as $global) {
                foreach ([false, true] as $candidate) {
                    foreach ([false, true] as $held) {
                        foreach (['Php', 'Native'] as $suffix) {
                            $store = $this->createMock(VerificationLockStore::class);
                            $lock = new VerificationLock('candidate', ['key']);
                            $selected = $exclusive ? $global : $candidate;
                            $store->expects($selected ? self::once() : self::never())->method('holds')->with($lock)->willReturn($held);
                            $worker = new Workers($store);
                            $reflection = new ReflectionClass($worker);
                            $reflection->getProperty('exclusive')->setValue($worker, $exclusive);
                            $reflection->getProperty('global')->setValue($worker, $global ? $lock : null);
                            $reflection->getProperty('candidates')->setValue($worker, $candidate ? [7 => $lock] : []);
                            self::assertSame($selected && $held, $worker->{'protects'.$suffix}(7));
                            $worker->close();
                        }
                    }
                }
            }
        }
        foreach (['Php', 'Native'] as $suffix) {
            $store = $this->createMock(VerificationLockStore::class);
            $store->method('holds')->willThrowException(new RuntimeException('lost session'));
            $worker = new Workers($store);
            (new ReflectionClass($worker))->getProperty('candidates')->setValue($worker, [7 => new VerificationLock('candidate', ['key'])]);
            self::assertSame(['error' => RuntimeException::class, 'message' => 'lost session'], self::outcome(fn () => $worker->{'protects'.$suffix}(7)));
        }
    }

    public function test_claim_ttl_columns_and_both_sql_dialects_match(): void
    {
        foreach ([PHP_INT_MIN, -100, 0, 1, 149, 150, 151, 1800, intdiv(PHP_INT_MAX, 2), PHP_INT_MAX] as $timeout) {
            $this->timeout($timeout);
            self::assertSame($timeout, app(ConfigurationProvider::class)->postProcessing()->releaseTimeoutSeconds);
            $php = self::outcome(fn () => Query::claimTtlSecondsPhp());
            $native = self::outcome(fn () => Query::claimTtlSecondsNative());
            if (isset($php['error'])) {
                self::assertSame(\TypeError::class, $php['error']);
                self::assertSame($php['error'], $native['error']);
                self::assertStringContainsString('Return value must be of type int', $native['message']);
            } else {
                self::assertSame($php, $native);
                self::assertSame(max(300, ($timeout ?: 120) * 2), $php['value']);
            }
        }
        $random = new Randomizer(new Mt19937(1502));
        foreach ([false, true] as $supports) {
            foreach ([[], ['*'], ['id', 'nzb_creation_claimed_at', 'nzb_creation_claim_token', 'name'],
                ["\0\xff", '', 'nzb_creation_claimed_at', 'id', 'id'], [3 => 'id']] as $columns) {
                self::assertSame(Query::selectableColumnsPhp($columns, $supports), Query::selectableColumnsNative($columns, $supports));
            }
        }
        foreach (['sqlite', 'mysql'] as $driver) {
            $connection = $this->database->getConnection('testing');
            (new ReflectionClass($connection))->getProperty('config')->setValue($connection, ['driver' => $driver]);
            foreach ([[], [1], [PHP_INT_MIN, PHP_INT_MAX, 0, -7, 1, 1], [3 => 8], ['9', -1]] as $ids) {
                self::assertSame(Query::idOrderExpressionPhp($ids), Query::idOrderExpressionNative($ids));
            }
            for ($i = 0; $i < 100; $i++) {
                $ids = array_map(fn () => $random->getInt(-1000000, 1000000), range(0, $i));
                self::assertSame(Query::idOrderExpressionPhp($ids), Query::idOrderExpressionNative($ids));
            }
        }
    }

    public function test_deferral_hash_comparisons_are_strict_and_budget_changes_only_unlock_larger_limits(): void
    {
        foreach ([null, (object) ['collectionhash' => 1, 'budget_bytes' => 0],
            (object) ['collectionhash' => "a\0b", 'budget_bytes' => '100'],
            (object) ['collectionhash' => 'a', 'budget_bytes' => PHP_INT_MAX]] as $row) {
            foreach (['a', '1', "a\0b", ''] as $hash) {
                foreach ([PHP_INT_MIN, 1, 100, 101, PHP_INT_MAX] as $budget) {
                    self::assertSame(Deferrals::raisedBudgetMatchesPhp($row, $hash, $budget), Deferrals::raisedBudgetMatchesNative($row, $hash, $budget));
                }
            }
        }
    }

    public function test_entry_validation_normalization_and_random_json_preserve_php_types(): void
    {
        $cases = ['null', '[]', '{broken', 'false', '{"claim":1}', json_encode(self::document(), JSON_THROW_ON_ERROR)];
        $random = new Randomizer(new Mt19937(1503));
        for ($i = 0; $i < 200; $i++) {
            $row = self::document($random->getInt(-1000000, 2000000) / 3);
            $row['attempts'] = $random->getInt(-1000, 1000);
            $row['deferrals'] = (string) $random->getInt(-1000, 1000);
            $row['not_before'] = (string) ($random->getInt(0, 2000000) / 7);
            $row['evidence']['identity_hints'] = ['n' => 7, 'bool' => false, 'null' => null, 'unicode' => 'é'];
            if ($i % 3 === 0) {
                $row['claim']['id'] = '7';
            }
            $cases[] = json_encode($row, JSON_THROW_ON_ERROR);
        }
        foreach (['enqueued_at', 'not_before', 'heartbeat_at', 'attempts', 'deferrals'] as $field) {
            foreach ([null, false, '12.75', -7, [], ['value']] as $value) {
                $row = self::document();
                $row[$field] = $value;
                $cases[] = json_encode($row, JSON_THROW_ON_ERROR);
            }
        }
        foreach ($cases as $json) {
            $php = Outbox::decodeEntryPhp('entry.json', '/path', $json, 123);
            $native = Outbox::decodeEntryNative('entry.json', '/path', $json, 123);
            self::assertEquals($php, $native, $json);
            self::assertSame($php?->toArray(), $native?->toArray());
        }
    }

    private function fixture(string $name, int $count, bool $uncommon = false): Outbox
    {
        $box = new Outbox($this->root.'/'.$name);
        $box->prepare(1);
        $box->prepare(2);
        mkdir($box->directory().'/inflight/not-a-slot');
        mkdir($box->directory().'/inflight/01');
        mkdir($box->directory().'/inflight/9999999999999999999999999');
        for ($i = 0; $i < $count; $i++) {
            $row = self::document(1000.0 + $i);
            $row['not_before'] = $uncommon ? 5000 : 0;
            file_put_contents($box->directory().'/pending/'.$i.'.json', json_encode($row, JSON_THROW_ON_ERROR));
        }
        file_put_contents($box->directory().'/inflight/2/entry.json', json_encode(self::document(), JSON_THROW_ON_ERROR));

        return $box;
    }

    public function test_outbox_reads_preserve_order_early_returns_symlink_fences_and_invalid_bookkeeping(): void
    {
        $box = $this->fixture('outbox', 12, true);
        $path = $box->directory();
        symlink($path.'/pending', $path.'/inflight/3');
        file_put_contents($path.'/pending/broken.json', '{');
        symlink($path.'/inflight/2/entry.json', $path.'/pending/link.json');
        foreach ([false, true] as $toggle) {
            Outbox::setUseRustFfi($toggle);
            self::assertSame($box->slotDirectoriesPhp(), $box->slotDirectoriesNative());
            self::assertNotContains($path.'/inflight/3', $box->slotDirectoriesNative());
            foreach ([1, 2, PHP_INT_MAX, 0] as $slot) {
                self::assertSame($box->foreignSlotsPhp($slot), $box->foreignSlotsNative($slot));
                foreach ([1000.0, 1240.0, 1240.001, 5000.0] as $now) {
                    self::assertSame($box->hasDuePhp($slot, $now), $box->hasDueNative($slot, $now));
                    self::assertSame($box->hasDuePhp($slot, $now), $box->hasDue($slot, $now));
                    foreach ([0, 1, 4, 100] as $cap) {
                        self::assertSame($box->surveyPhp($now, $slot, $cap, 5), $box->surveyNative($now, $slot, $cap, 5));
                        self::assertSame($box->surveyPhp($now, $slot, $cap, 5), $box->survey($now, $slot, $cap, 5));
                    }
                }
            }
        }
        self::assertSame(['removed' => 2, 'parked' => 0], $box->purgeInvalid());
        self::assertFileExists($path.'/inflight/2/entry.json');
        $row = self::document();
        $row['attempts'] = 1;
        file_put_contents($path.'/pending/0.json', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertTrue($box->surveyNative(1100, 1, 64)['retried']);
        symlink($path, $this->root.'/linked');
        $linked = new Outbox($this->root.'/linked');
        self::assertSame([], $linked->slotDirectoriesNative());
        self::assertFalse($linked->hasDueNative(1, 1100));
    }

    /** @return array<string, mixed> */
    private static function outcome(callable $work): array
    {
        try {
            return ['value' => $work()];
        } catch (Throwable $error) {
            return ['error' => $error::class, 'message' => $error->getMessage()];
        }
    }

    public function test_abi_rejects_null_oversize_overlap_and_truncated_frames_and_retries_buffers(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', implode("\n", array_map(self::cdef(...), self::CLASSES)));
        self::assertNotNull($ffi);
        self::assertSame(-1, self::abi($ffi, 'nntmux_wave15_outbox_folder', [null, 0, 0, 0]));
        self::assertSame(-1, self::abi($ffi, 'nntmux_wave15_outbox_folder', ['a', PHP_INT_MAX, 0, 0]));
        self::assertSame(-1, self::abi($ffi, 'nntmux_nzb_selectable_columns', ["\0\0\0\x09a", 5, 0, null, 0]));
        self::assertSame(-1, self::abi($ffi, 'nntmux_nzb_id_order', ['x', 1, 0, null, 0]));
        $buffer = $ffi->new('char[128]');
        FFI::memcpy($buffer, 'database', 8);
        self::assertSame(-1, self::abi($ffi, 'nntmux_verification_lock_key', [$buffer, 8, 'x', 1, $buffer, 128]));
        $needed = self::abi($ffi, 'nntmux_verification_lock_key', ['db/', 3, 'worker', 6, null, 0]);
        self::assertSame(-23, $needed);
        $written = self::abi($ffi, 'nntmux_verification_lock_key', ['db/', 3, 'worker', 6, $buffer, 128]);
        self::assertSame('nnv:'.substr(sha1('db/'), 0, 12).':worker', FFI::string($buffer, $written));
        self::assertSame(300, self::abi($ffi, 'nntmux_nzb_claim_ttl', [PHP_INT_MIN]));
        self::assertSame(512, self::abi($ffi, 'nntmux_verification_members', ['worker', 6]));
        self::assertSame(1, self::abi($ffi, 'nntmux_wave15_outbox_folder', ['2', 1, 1, 1]));
        self::assertSame(1, self::abi($ffi, 'nntmux_wave15_raised_budget', ['hash', 4, 'hash', 4, 100, 101]));
        $packed = pack('q*', 7, -2);
        $written = self::abi($ffi, 'nntmux_nzb_id_order', [$packed, strlen($packed), 0, $buffer, 128]);
        self::assertSame('FIELD(id, 7,-2)', FFI::string($buffer, $written));
        $packed = pack('N', 2).'id'.pack('N', 100).'nzb_creation_claimed_at';
        // A malformed declared column length must decline rather than read past input.
        self::assertSame(-1, self::abi($ffi, 'nntmux_nzb_selectable_columns', [$packed, strlen($packed), 0, $buffer, 128]));
        $packed = pack('N', 2).'id'.pack('N', strlen('nzb_creation_claimed_at')).'nzb_creation_claimed_at';
        $written = self::abi($ffi, 'nntmux_nzb_selectable_columns', [$packed, strlen($packed), 0, $buffer, 128]);
        self::assertSame(pack('N', 2).'id', FFI::string($buffer, $written));
        $packed = serialize(self::document());
        $needed = self::abi($ffi, 'nntmux_wave15_outbox_normalize', [$packed, strlen($packed), null, 0]);
        self::assertLessThan(-1, $needed);
        $larger = $ffi->new('char['.(-$needed).']');
        $written = self::abi($ffi, 'nntmux_wave15_outbox_normalize', [$packed, strlen($packed), $larger, -$needed]);
        self::assertSame(-$needed, $written);
        self::assertCount(8, unserialize(FFI::string($larger, $written), ['allowed_classes' => false]));
    }

    /** @param list<mixed> $arguments */
    private static function abi(FFI $ffi, string $symbol, array $arguments): mixed
    {
        return $ffi->{$symbol}(...$arguments);
    }

    public function test_missing_library_keeps_php_fallbacks_and_production_defaults(): void
    {
        $property = (new ReflectionClass(RustPipelineLibrary::class))->getProperty('domainLibraries');
        $saved = $property->getValue();
        $blocked = $saved;
        foreach (self::CLASSES as $class) {
            $blocked['nfo|default|'.hash('xxh3', self::cdef($class))] = false;
            self::assertFalse((new ReflectionClass($class))->getProperty('useRustFfi')->getValue());
        }
        $property->setValue(null, $blocked);
        try {
            self::assertSame(Workers::lastSlotPhp(), Workers::lastSlotNative());
            self::assertSame(Workers::forwarderLockNamePhp(7), Workers::forwarderLockNameNative(7));
            self::assertSame(Deferrals::budgetPhp(), Deferrals::budgetNative());
            self::assertSame(Query::selectableColumnsPhp(['id'], false), Query::selectableColumnsNative(['id'], false));
            self::assertSame(Query::idOrderExpressionPhp([1, 2]), Query::idOrderExpressionNative([1, 2]));
            $locks = new DatabaseLocks('testing');
            self::assertSame($locks->keyPhp('worker'), $locks->keyNative('worker'));
            self::assertSame($locks->membersPhp('worker'), $locks->membersNative('worker'));
            $box = $this->fixture('fallback', 2);
            self::assertSame($box->surveyPhp(1100, 1, 32), $box->surveyNative(1100, 1, 32));
            $json = json_encode(self::document(), JSON_THROW_ON_ERROR);
            self::assertEquals(Outbox::decodeEntryPhp('a', 'b', $json, 1), Outbox::decodeEntryNative('a', 'b', $json, 1));
        } finally {
            $property->setValue(null, $saved);
        }
    }

    public function test_benchmark_randomized_paired_callers(): void
    {
        if (getenv('WAVE15_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt-in paired admission experiment.');
        }
        $random = new Randomizer(new Mt19937(15102026));
        $raw = [];
        $summary = [];
        foreach ([false, true] as $uncommon) {
            $workload = $uncommon ? 'uncommon' : 'common';
            config(['release_verification.candidate_workers' => $uncommon ? 1000 : 2,
                'release_verification.slot_offset' => $uncommon ? PHP_INT_MAX : 0,
                'release_verification.host' => $uncommon ? str_repeat('worker-long-', 100) : 'worker-a',
                'release_verification.metadata_bytes' => $uncommon ? PHP_INT_MIN : 268435456]);
            $this->timeout($uncommon ? 18000 : 120);
            $box = $this->fixture('bench-'.$workload, $uncommon ? 40 : 4, $uncommon);
            $locks = new DatabaseLocks('testing');
            $store = $this->createStub(VerificationLockStore::class);
            $store->method('holds')->willReturn(true);
            $worker = new Workers($store);
            (new ReflectionClass($worker))->getProperty('candidates')->setValue($worker, [7 => new VerificationLock('candidate', ['key'])]);
            $json = json_encode(self::document(), JSON_THROW_ON_ERROR);
            if ($uncommon) {
                $row = self::document();
                $row['evidence']['identity_hints'] = array_fill(0, 200, str_repeat('title', 40));
                $json = json_encode($row, JSON_THROW_ON_ERROR);
            }
            $ids = $uncommon ? range(-1000, 1000) : [7, 2, 9];
            $columns = $uncommon ? array_fill(0, 128, 'nzb_creation_claimed_at') : ['id', 'name', 'nzb_creation_claim_token'];
            $row = (object) ['collectionhash' => $uncommon ? str_repeat('hash', 1000) : 'hash', 'budget_bytes' => 100];
            $cases = [
                'key' => [fn () => $locks->keyPhp($uncommon ? str_repeat('x', 47) : 'worker'), fn () => $locks->keyNative($uncommon ? str_repeat('x', 47) : 'worker')],
                'members' => [fn () => $locks->membersPhp($uncommon ? 'reset' : 'worker'), fn () => $locks->membersNative($uncommon ? 'reset' : 'worker')],
                'forwarderLockName' => [fn () => Workers::forwarderLockNamePhp($uncommon ? PHP_INT_MAX : 1), fn () => Workers::forwarderLockNameNative($uncommon ? PHP_INT_MAX : 1)],
                'slots' => [Workers::slotsPhp(...), Workers::slotsNative(...)],
                'firstSlot' => [Workers::firstSlotPhp(...), Workers::firstSlotNative(...)],
                'lastSlot' => [Workers::lastSlotPhp(...), Workers::lastSlotNative(...)],
                'protects' => [fn () => $worker->protectsPhp(7), fn () => $worker->protectsNative(7)],
                'claimTtlSeconds' => [Query::claimTtlSecondsPhp(...), Query::claimTtlSecondsNative(...)],
                'selectableColumns' => [fn () => Query::selectableColumnsPhp($columns, false), fn () => Query::selectableColumnsNative($columns, false)],
                'idOrderExpression' => [fn () => Query::idOrderExpressionPhp($ids), fn () => Query::idOrderExpressionNative($ids)],
                'budget' => [Deferrals::budgetPhp(...), Deferrals::budgetNative(...)],
                'raisedBudgetMatches' => [fn () => Deferrals::raisedBudgetMatchesPhp($row, $row->collectionhash, 101), fn () => Deferrals::raisedBudgetMatchesNative($row, $row->collectionhash, 101)],
                'slotDirectories' => [$box->slotDirectoriesPhp(...), $box->slotDirectoriesNative(...)],
                'hasDue' => [fn () => $box->hasDuePhp(1, 1100), fn () => $box->hasDueNative(1, 1100)],
                'survey' => [fn () => $box->surveyPhp(1100, 1, 64, 5), fn () => $box->surveyNative(1100, 1, 64, 5)],
                'foreignSlots' => [fn () => $box->foreignSlotsPhp(1), fn () => $box->foreignSlotsNative(1)],
                'decodeEntry' => [fn () => Outbox::decodeEntryPhp('a', 'b', $json, 1), fn () => Outbox::decodeEntryNative('a', 'b', $json, 1)],
            ];
            foreach ($cases as $name => [$php, $rust]) {
                self::assertEquals($php(), $rust(), $name);
                for ($i = 0; $i < 100; $i++) {
                    $php();
                    $rust();
                }
                for ($repetition = 0; $repetition < 2; $repetition++) {
                    $samples = [[], []];
                    for ($i = 0; $i < 2048; $i++) {
                        $order = $random->getInt(0, 1) === 0 ? [0, 1] : [1, 0];
                        $values = [];
                        foreach ($order as $index) {
                            $start = hrtime(true);
                            $values[$index] = $index === 0 ? $php() : $rust();
                            $samples[$index][] = hrtime(true) - $start;
                        }
                        self::assertEquals($values[0], $values[1], $name);
                    }
                    $raw[$name][$workload][$repetition] = $samples;
                    [$baseline, $native] = $samples;
                    sort($baseline);
                    sort($native);
                    $summary[$name][$workload][$repetition] = ['mean' => array_sum($baseline) / array_sum($native),
                        'p99' => $baseline[(int) ceil(count($baseline) * .99) - 1] / $native[(int) ceil(count($native) * .99) - 1]];
                }
            }
        }
        $decisions = [];
        foreach ($summary as $name => $workloads) {
            $ratios = [];
            foreach ($workloads as $repetitions) {
                foreach ($repetitions as $metrics) {
                    array_push($ratios, ...array_values($metrics));
                }
            }
            $decisions[$name] = min($ratios) >= 1.5;
        }
        $sources = [];
        foreach (self::CLASSES as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $sources[$class] = hash_file('sha256', $file);
        }
        $artifact = sys_get_temp_dir().'/wave15-locks-nzb-benchmark-'.getmypid().'.json';
        file_put_contents($artifact, json_encode(['protocol' => ['seed' => 15102026, 'warmup' => 100, 'pairs' => 2048, 'repetitions' => 2,
            'clock' => 'hrtime(true)', 'build' => 'cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'php' => PHP_VERSION, 'os' => php_uname(),
            'rustc' => trim((string) shell_exec('rustc --version')), 'cargo' => trim((string) shell_exec('cargo --version')),
            'hardware' => PHP_OS_FAMILY === 'Darwin' ? trim((string) shell_exec('sysctl -n hw.model hw.memsize machdep.cpu.brand_string')) : (string) @file_get_contents('/proc/cpuinfo'),
            'resource_limits' => ['php_memory_limit' => ini_get('memory_limit'), 'executor' => 'one benchmark process, no imposed CPU quota; no production services'],
            'harness_sha256' => hash_file('sha256', __FILE__),
            'rust_library_sha256' => hash_file('sha256', __DIR__.'/../../rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')), 'opcache_cli' => ini_get('opcache.enable_cli'),
            'baseline' => '950ac7d530c222147d2dc2ef71c89b98c892ccfe', 'sources' => $sources,
            'rust_source_sha256' => hash_file('sha256', __DIR__.'/../../rust/pipeline-ffi/src/nfo.rs'),
            'composer_lock_sha256' => hash_file('sha256', __DIR__.'/../../composer.lock'),
            'failures_or_timeouts' => [],
            'prior_invalid_screen' => ['artifact' => 'wave15-locks-nzb-benchmark-78196.json', 'reason' => 'Outbox nested decoders followed the global toggle; explicit path propagation now measures the complete native caller.'], 'fixture' => 'document(); fixture() common=4 due/uncommon=40 backed-off; warm filesystem cache; SQLite private :memory:; no production DB'],
            'summary' => $summary, 'admit' => $decisions, 'raw_ns' => $raw], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nWave15 benchmark: ".$artifact."\n".json_encode(['summary' => $summary, 'admit' => $decisions], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertFileExists($artifact);
    }
}
