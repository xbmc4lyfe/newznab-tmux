<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\BackfillReleaseFingerprintMetadata;
use App\Console\Commands\GetArticleRange;
use App\Console\Commands\PrepareReleaseCandidates;
use App\Console\Commands\ReleaseVerificationBarrier;
use App\Console\Commands\TransferFingerprintQueue;
use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustArticleCandidateBarrierFingerprintCommandsParityTest extends TestCase
{
    private const array CLASSES = [GetArticleRange::class, PrepareReleaseCandidates::class, ReleaseVerificationBarrier::class, TransferFingerprintQueue::class, BackfillReleaseFingerprintMetadata::class];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'nntmux.fingerprint_format' => 'compact']);
        DB::purge();
        DB::reconnect();
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_defaults_nullable_toggles_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi());
        }
        foreach (self::CLASSES as $selected) {
            $selected::setUseRustFfi(true);
            foreach (self::CLASSES as $class) {
                $this->assertSame($selected === $class, $class::usesRustFfi());
            }
            $selected::setUseRustFfi(false);
            $this->assertFalse($selected::usesRustFfi());
            $selected::setUseRustFfi(null);
            $this->assertFalse($selected::usesRustFfi());
        }
    }

    public function test_php_helpers_preserve_existing_command_contracts(): void
    {
        $this->assertTrue(GetArticleRange::isValidModePhp('binaries'));
        $this->assertTrue(GetArticleRange::isValidModePhp('backfill'));
        $this->assertFalse(GetArticleRange::isValidModePhp('Binaries'));
        $this->assertTrue(GetArticleRange::isValidArticleRangePhp(1, 1));
        $this->assertFalse(GetArticleRange::isValidArticleRangePhp(0, 1));
        $this->assertFalse(GetArticleRange::isValidArticleRangePhp(5, 4));
        $this->assertTrue(GetArticleRange::isContiguousBinariesRangePhp('binaries', 1000, 1001));
        $this->assertFalse(GetArticleRange::isContiguousBinariesRangePhp('binaries', 1000, 1002));
        $this->assertFalse(GetArticleRange::isContiguousBinariesRangePhp('binaries', null, 1));
        $this->assertFalse(GetArticleRange::isContiguousBinariesRangePhp('backfill', 1000, 1));
        $this->assertTrue(GetArticleRange::isContiguousBinariesRangePhp('binaries', PHP_INT_MAX, PHP_INT_MAX));
        $this->assertSame(['limit' => 5000, 'max_seconds' => 30, 'dirty_limit' => 1, 'dirty_seconds' => 12],
            PrepareReleaseCandidates::normalizePositiveOptionsPhp('5000', '30', '-4', '12abc'));
        $this->assertSame('Classified 3 candidate header inventories.', PrepareReleaseCandidates::formatClassifiedMessagePhp(3));
        $this->assertSame('Settled 1 of 4 dirty candidates (2 late collections kept for a later pass).', PrepareReleaseCandidates::formatSettledDirtyMessagePhp(1, 4, 2));
        $this->assertNull(ReleaseVerificationBarrier::resolveReleaseScopeReasonPhp(true));
        $this->assertSame('deployment', ReleaseVerificationBarrier::resolveReleaseScopeReasonPhp(false));
        $this->assertSame(1, ReleaseVerificationBarrier::clampTimeoutSecondsPhp('0'));
        $this->assertSame(900, ReleaseVerificationBarrier::clampTimeoutSecondsPhp('900'));
        $this->assertTrue(ReleaseVerificationBarrier::shouldCheckLivenessPhp(1_700_000_005, 1_700_000_000));
        $this->assertFalse(ReleaseVerificationBarrier::shouldCheckLivenessPhp(1_700_000_004, 1_700_000_000));
        $this->assertTrue(ReleaseVerificationBarrier::shouldCheckLivenessPhp(1_700_000_000, 0));
        $this->assertSame(10000, TransferFingerprintQueue::clampBatchLimitPhp('99999'));
        $this->assertSame(1, TransferFingerprintQueue::clampBatchLimitPhp(null));
        $this->assertSame(42, TransferFingerprintQueue::extractReleaseIdFromQueuePayloadPhp(self::legacyPayload(42)));
        $this->assertNull(TransferFingerprintQueue::extractReleaseIdFromQueuePayloadPhp('{'));
        $this->assertSame(PHP_INT_MAX, TransferFingerprintQueue::extractReleaseIdFromQueuePayloadPhp(self::payload('releaseId";i:99999999999999999999;')));
        $this->assertSame('Validated 2 fingerprint jobs.', TransferFingerprintQueue::formatCompletionSummaryPhp(true, 2));
        $this->assertSame('Transferred 2 fingerprint jobs.', TransferFingerprintQueue::formatCompletionSummaryPhp(false, 2));
        $this->assertSame(['limit' => 10000, 'after_id' => 0, 'delay_ms' => 60000], BackfillReleaseFingerprintMetadata::normalizeBackfillOptionsPhp('20000', '-1', '70000'));
        $this->assertTrue(BackfillReleaseFingerprintMetadata::markersAreCurrentPhp('1', 'integer', 'compact-v1', 'text', 1, 'compact-v1'));
        $this->assertTrue(BackfillReleaseFingerprintMetadata::markersAreCurrentPhp('40', 'INTEGER', null, null, 40, null));
        $this->assertFalse(BackfillReleaseFingerprintMetadata::markersAreCurrentPhp('1', 'text', 'compact-v1', 'text', 1, 'compact-v1'));
        $this->assertFalse(BackfillReleaseFingerprintMetadata::markersAreCurrentPhp('1', 'integer', '[]', 'array', 1, 'compact-v1'));
        $this->assertSame('Dry run: 3 matching releases; nothing was queued.', BackfillReleaseFingerprintMetadata::formatSummaryLinePhp(true, 3));
        $this->assertSame('Queued 3 releases. Resume with --after-id=9.', BackfillReleaseFingerprintMetadata::formatSummaryLinePhp(false, 3, 9));
    }

    public function test_markers_match_the_job_currency_rule(): void
    {
        $types = [null, '', 'integer', 'INTEGER', 'text', 'string', 'null', 'NULL', 'real', 'array', 'object', 'true'];
        $values = [null, '', '1', '01', '-0', '40', '1.0', ' 1', 'compact-v1', 'Compact-v1', "compact-v1\0"];
        foreach ([[CompactFingerprintCollector::SCHEMA_VERSION, CompactFingerprintCollector::FORMAT], [ReleaseFingerprintCollector::SCHEMA_VERSION, null]] as [$version, $format]) {
            foreach ($types as $versionType) {
                foreach ($values as $versionValue) {
                    foreach ($types as $formatType) {
                        foreach ($values as $formatValue) {
                            $metadata = [];
                            $type = strtolower((string) $versionType);
                            if ($type === 'integer' && preg_match('/\A-?[0-9]+\z/', (string) $versionValue) === 1) {
                                $metadata['schema_version'] = (int) $versionValue;
                            } elseif ($type !== '') {
                                $metadata['schema_version'] = (string) $versionValue;
                            }
                            $type = strtolower((string) $formatType);
                            if (in_array($type, ['string', 'text'], true)) {
                                $metadata['format'] = (string) $formatValue;
                            } elseif ($type !== '' && $type !== 'null') {
                                $metadata['format'] = [$type];
                            }
                            $expected = ($metadata['format'] ?? null) === $format && ($metadata['schema_version'] ?? null) === $version;
                            $this->assertSame($expected, BackfillReleaseFingerprintMetadata::markersAreCurrentPhp($versionValue, $versionType, $formatValue, $formatType, $version, $format));
                            $this->assertSame($expected, BackfillReleaseFingerprintMetadata::markersAreCurrentNative($versionValue, $versionType, $formatValue, $formatType, $version, $format));
                        }
                    }
                }
            }
        }
        foreach ([true, false] as $compact) {
            config(['nntmux.fingerprint_worker' => 'legacy', 'nntmux.fingerprint_format' => $compact ? 'compact' : 'full']);
            $metadata = ['format' => CollectReleaseFingerprintMetadata::currentFormat(), 'schema_version' => CollectReleaseFingerprintMetadata::currentSchemaVersion()];
            $this->assertTrue(CollectReleaseFingerprintMetadata::isCurrent(array_filter($metadata, static fn (mixed $value): bool => $value !== null)));
            $this->assertTrue(BackfillReleaseFingerprintMetadata::markersAreCurrentPhp((string) $metadata['schema_version'], 'integer',
                $metadata['format'], $metadata['format'] === null ? null : 'text', $metadata['schema_version'], $metadata['format']));
        }
    }

    public function test_native_helpers_and_public_dispatch_have_exact_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $label = $class.'::'.$method.' '.json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE);
                $expected = $class::$php(...$args);
                // Check the raw bridge first: fallback must not conceal a failed native call.
                $this->assertTrue(GetArticleRange::transformNative($op, $args, $raw), $label.' raw native declined');
                $this->assertSame($expected, $raw, $label.' raw native');
                $this->assertSame($expected, $class::$native(...$args), $label.' native');
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $label.' public dispatch');
                }
            }
        }
    }

    public function test_declined_inputs_fall_back_to_identical_php_results(): void
    {
        $this->nativeFfi();
        $deep = json_encode(['displayName' => CollectReleaseFingerprintMetadata::class,
            'data' => ['command' => 'releaseId";i:5;', 'nested' => json_decode(str_repeat('[', 40).str_repeat(']', 40))]], JSON_THROW_ON_ERROR);
        $declined = [
            [PrepareReleaseCandidates::class, 'normalizePositiveOptions', ['12abc', 1, 1, 1], 3],
            [PrepareReleaseCandidates::class, 'normalizePositiveOptions', [1.9, 1, 1, 1], 3],
            [PrepareReleaseCandidates::class, 'normalizePositiveOptions', [1, [3], 1, 1], 3],
            [ReleaseVerificationBarrier::class, 'clampTimeoutSeconds', ['1e3'], 7],
            [ReleaseVerificationBarrier::class, 'clampTimeoutSeconds', [' 0x1A'], 7],
            [ReleaseVerificationBarrier::class, 'clampTimeoutSeconds', ['99999999999999999999'], 7],
            [ReleaseVerificationBarrier::class, 'clampTimeoutSeconds', ["\xff7"], 7],
            [ReleaseVerificationBarrier::class, 'clampTimeoutSeconds', [-0.0], 7],
            [TransferFingerprintQueue::class, 'clampBatchLimit', ['1.5'], 9],
            [TransferFingerprintQueue::class, 'clampBatchLimit', [['a' => 1]], 9],
            [TransferFingerprintQueue::class, 'extractReleaseIdFromQueuePayload', ['{'], 10],
            [TransferFingerprintQueue::class, 'extractReleaseIdFromQueuePayload', [''], 10],
            [TransferFingerprintQueue::class, 'extractReleaseIdFromQueuePayload', ['{"displayName":"x","displayName":"'.addslashes(CollectReleaseFingerprintMetadata::class).'","data":{"command":"releaseId\";i:7;"}}'], 10],
            [TransferFingerprintQueue::class, 'extractReleaseIdFromQueuePayload', [$deep], 10],
            [TransferFingerprintQueue::class, 'extractReleaseIdFromQueuePayload', [self::payload('releaseId";i:9223372036854775808;')], 10],
            [TransferFingerprintQueue::class, 'extractReleaseIdFromQueuePayload', ["{\"a\":\"\xff\"}"], 10],
            [BackfillReleaseFingerprintMetadata::class, 'normalizeBackfillOptions', ['100', '7 days', '0'], 12],
            [BackfillReleaseFingerprintMetadata::class, 'markersAreCurrent', ['99999999999999999999', 'integer', null, null, PHP_INT_MAX, null], 13],
            [BackfillReleaseFingerprintMetadata::class, 'markersAreCurrent', ['1', 'integer', "compact-v1\xff", 'text', 1, 'compact-v1'], 13],
            [GetArticleRange::class, 'isValidMode', ["binaries\xff"], 0],
        ];
        foreach ($declined as [$class, $method, $args, $op]) {
            $this->assertFalse(GetArticleRange::transformNative($op, $args), $method.' must decline '.bin2hex(serialize($args)));
            $php = $method.'Php';
            $native = $method.'Native';
            $this->assertSame($class::$php(...$args), $class::$native(...$args), $method.' fallback');
            $class::setUseRustFfi(true);
            $this->assertSame($class::$php(...$args), $class::$method(...$args), $method.' dispatch fallback');
        }
        $this->assertSame(7, TransferFingerprintQueue::extractReleaseIdFromQueuePayloadNative($declined[12][2][0]));
        $this->assertSame(5, TransferFingerprintQueue::extractReleaseIdFromQueuePayloadNative($deep));
        $this->assertFalse(GetArticleRange::transformNative(999, []));
        $this->assertFalse(GetArticleRange::transformNative(0, ['a', 'b']));
        $this->assertFalse(GetArticleRange::transformNative(0, ['x' => 'a']));
        $this->assertFalse(GetArticleRange::transformNative(0, [new \stdClass]));
        $this->assertFalse(GetArticleRange::transformNative(1, [1, '2']));
        $this->assertFalse(GetArticleRange::transformNative(6, [1]));
    }

    public function test_seeded_byte_strings_match_php_int_cast_and_regex(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(31022026));
        $alphabet = "09-+ \t\n\r\v\f.eExaZ_\0é";
        $commandAlphabet = ['releaseId";i:', '0', '1', '9', ';', '"', 'i', ':', 'x', "\0", 'é', 's:9:'];
        for ($sample = 0; $sample < 2000; $sample++) {
            $value = '';
            for ($i = $random->getInt(0, 24); $i > 0; $i--) {
                $value .= mb_substr($alphabet, $random->getInt(0, mb_strlen($alphabet) - 1), 1);
            }
            $label = bin2hex($value);
            $this->assertSame(ReleaseVerificationBarrier::clampTimeoutSecondsPhp($value), ReleaseVerificationBarrier::clampTimeoutSecondsNative($value), 'timeout '.$label);
            $this->assertSame(TransferFingerprintQueue::clampBatchLimitPhp($value), TransferFingerprintQueue::clampBatchLimitNative($value), 'batch '.$label);
            if (GetArticleRange::transformNative(7, [$value], $raw)) {
                $this->assertSame(ReleaseVerificationBarrier::clampTimeoutSecondsPhp($value), $raw, 'raw timeout '.$label);
            }
            $this->assertSame(BackfillReleaseFingerprintMetadata::markersAreCurrentPhp($value, 'integer', null, null, 1, null),
                BackfillReleaseFingerprintMetadata::markersAreCurrentNative($value, 'integer', null, null, 1, null), 'marker '.$label);
            $command = '';
            for ($i = $random->getInt(0, 12); $i > 0; $i--) {
                $command .= $commandAlphabet[$random->getInt(0, count($commandAlphabet) - 1)];
            }
            $payload = self::payload($command);
            $this->assertSame(TransferFingerprintQueue::extractReleaseIdFromQueuePayloadPhp($payload), TransferFingerprintQueue::extractReleaseIdFromQueuePayloadNative($payload), 'payload '.bin2hex($command));
            if (GetArticleRange::transformNative(10, [$payload], $raw)) {
                $this->assertSame(TransferFingerprintQueue::extractReleaseIdFromQueuePayloadPhp($payload), $raw, 'raw payload '.bin2hex($command));
            }
        }
    }

    public function test_abi_capacity_malformed_inputs_and_bad_ranges_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            foreach ([...$common, ...$uncommon] as $args) {
                $input = json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $expected = $class::$php(...$args);
                $buffer = $ffi->new('char[65536]');
                FFI::memset($buffer, 0x5A, 65536);
                // @phpstan-ignore method.notFound
                $length = $ffi->nntmux_w31_article_candidate_barrier_fingerprint_commands($op, $input, strlen($input), $buffer, 65536);
                $this->assertGreaterThan(0, $length);
                $wire = FFI::string($buffer, $length);
                $this->assertSame([$expected], json_decode($wire, true, 64, JSON_THROW_ON_ERROR));
                $small = $ffi->new('char['.($length + 2).']');
                foreach ([0, 1, $length - 1, $length, $length + 1] as $capacity) {
                    FFI::memset($small, 0x5A, $length + 2);
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_w31_article_candidate_barrier_fingerprint_commands($op, $input, strlen($input), $small, $capacity);
                    $this->assertSame($capacity < $length ? -$length : $length, $written, $method.' capacity');
                    $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2));
                }
            }
        }
        $buffer = $ffi->new('char[1024]');
        foreach (['["a"] x', '["a"', '["\ud800"]', '["\x"]', "[\"a\x01\"]", '{"0":"a"}', '[1]', '["a",1]', "[\"\xff\"]", '[01]', str_repeat('[', 40).str_repeat(']', 40)] as $bad) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w31_article_candidate_barrier_fingerprint_commands(0, $bad, strlen($bad), $buffer, 1024), bin2hex($bad));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
        $input = '["binaries"]';
        foreach ([[null, 0, $buffer, 1024], [null, 1, $buffer, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 1], [$input, 16777217, $buffer, 1024], [$input, strlen($input), $buffer, 16777217], [$buffer, 20, $buffer, 1024], [FFI::addr($buffer[1]), 20, $buffer, 1024], [$buffer, 20, FFI::addr($buffer[1]), 1023]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w31_article_candidate_barrier_fingerprint_commands(0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Console\Commands\GetArticleRange::transformNative(0, ['binaries'])) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($vectors as [$class, $method, $common, $uncommon]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $class::setUseRustFfi(true);
                foreach ([...$common, ...$uncommon] as $args) {
                    $expected = serialize($class::$php(...$args));
                    if ($expected !== serialize($class::$native(...$args)) || $expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $missing = '/tmp/w31-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_real_commands_produce_identical_output_with_rust_forced(): void
    {
        $this->nativeFfi();
        Schema::create('usenet_groups', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('last_record');
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test', 'last_record' => 1000]);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->unsignedTinyInteger('nzbstatus')->default(1);
        });
        (require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php'))->up();
        $compact = ['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => CompactFingerprintCollector::SCHEMA_VERSION];
        foreach ([1 => null, 2 => ['complete', $compact], 3 => ['partial', $compact], 4 => ['complete', ['schema_version' => '1'] + $compact],
            5 => ['complete', ['format' => []] + $compact], 6 => ['complete', $compact], 7 => ['complete', ['schema_version' => 2] + $compact]] as $id => $record) {
            DB::table('releases')->insert(['id' => $id, 'guid' => 'g'.$id, 'nzbstatus' => 1]);
            if ($record !== null) {
                DB::table('release_fingerprint_metadata')->insert(['releases_id' => $id, 'status' => $record[0], 'metadata' => json_encode($record[1], JSON_THROW_ON_ERROR)]);
            }
        }
        $run = static function (string $command, array $options): array {
            $code = Artisan::call($command, $options);

            return [$code, Artisan::output()];
        };
        $transfer = function (array $ready, array $delayed, array $options) use ($run): array {
            $redis = Mockery::mock(PhpRedisConnection::class);
            $redis->shouldReceive('zcard')->andReturn(0);
            $redis->shouldReceive('lindex')->andReturnUsing(static fn (string $key, int $index): ?string => $ready[$index] ?? null);
            $redis->shouldReceive('zrange')->andReturnUsing(static fn (string $key, int $start): array => isset($delayed[$start]) ? [$delayed[$start]] : []);
            $manager = Mockery::mock(RedisManager::class);
            $manager->shouldReceive('connection')->with('default')->andReturn($redis);
            $this->app->instance(RedisManager::class, $manager);

            return $run('releases:fingerprint-transfer', ['--dry-run' => true] + $options);
        };
        $outputs = [];
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            Queue::fake();
            $outputs[] = [
                $run('articles:get-range', ['mode' => 'header', 'group' => 'alt.test', 'first' => '1', 'last' => '2']),
                $run('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => '9', 'last' => '2']),
                $run('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => '0', 'last' => '2']),
                $run('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => '1002', 'last' => '2000', '--contiguous' => true]),
                $run('articles:get-range', ['mode' => 'backfill', 'group' => 'alt.test', 'first' => '1', 'last' => '2', '--contiguous' => true]),
                $run('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.missing', 'first' => '1', 'last' => '2', '--contiguous' => true]),
                $run('releases:verification-barrier', ['--release' => true]),
                $run('releases:verification-barrier', ['--release' => true, '--all' => true]),
                $transfer([self::legacyPayload(50), self::legacyPayload(51)], [self::legacyPayload(52)], ['--limit' => '0']),
                $transfer([self::legacyPayload(50), self::legacyPayload(51)], [self::legacyPayload(52)], ['--limit' => '2']),
                $transfer([self::legacyPayload(50), '{"displayName":"Other"}'], [], []),
                $transfer(['{'], [], []),
                $transfer([json_encode(['displayName' => CollectReleaseFingerprintMetadata::class, 'data' => ['command' => 5]], JSON_THROW_ON_ERROR)], [], []),
                $transfer([self::payload('releaseId";i:0;')], [], []),
                $run('releases:fingerprint-backfill', ['--dry-run' => true, '--limit' => '100']),
                $run('releases:fingerprint-backfill', ['--dry-run' => true, '--limit' => '2', '--after-id' => '1']),
                $run('releases:fingerprint-backfill', ['--limit' => '3', '--after-id' => '-5', '--delay-ms' => '-1']),
                $run('releases:fingerprint-backfill', ['--limit' => '10', '--after-id' => '99']),
            ];
        }
        $this->assertSame($outputs[0], $outputs[1]);
        [$mode, $reversed, $zero, $ahead, $backfill, $missing, $release, $releaseAll, $validated, $limited, $unexpected, $syntax, $typeError, $zeroId, $dry, $page, $queued, $empty] = $outputs[0];
        $this->assertSame([1, "Mode must be either \"binaries\" or \"backfill\".\n"], $mode);
        $this->assertSame([1, "Article range must contain positive integers with first no greater than last.\n"], $reversed);
        $this->assertSame($reversed, $zero);
        foreach ([$ahead, $backfill, $missing] as $refused) {
            $this->assertSame([1, "A preceding header range is unfinished; leaving this range for the next pass.\n"], $refused);
        }
        $this->assertSame([0, "barrier released\n"], $release);
        $this->assertSame($release, $releaseAll);
        $this->assertSame([0, "Validated 1 fingerprint jobs.\n"], $validated);
        $this->assertSame([0, "Validated 2 fingerprint jobs.\n"], $limited);
        $this->assertSame([1, "Unexpected legacy payload; nothing was removed.\nTransferred 1; run again to resume.\n"], $unexpected);
        $this->assertSame([1, "Syntax error\nTransferred 0; run again to resume.\n"], $syntax);
        $this->assertSame(1, $typeError[0]);
        $this->assertStringContainsString('preg_match(): Argument #2 ($subject) must be of type string, int given', $typeError[1]);
        $this->assertSame([1, "Unexpected legacy payload; nothing was removed.\nTransferred 0; run again to resume.\n"], $zeroId);
        $this->assertSame(0, $dry[0]);
        $this->assertMatchesRegularExpression('/\| 1 +\|\n\| 3 +\|\n\| 4 +\|\n\| 5 +\|\n\| 7 +\|.*Dry run: 5 matching releases; nothing was queued\.\n$/s', $dry[1]);
        $this->assertStringEndsWith("Dry run: 2 matching releases; nothing was queued.\n", $page[1]);
        $this->assertSame([0, "Queued 3 releases. Resume with --after-id=4.\n"], $queued);
        $this->assertSame([0, "Queued 0 releases. Resume with --after-id=99.\n"], $empty);
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave31-2-article-candidate-barrier-fingerprint-commands-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 31022026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including JSON encoding, FFI, allocation and decoding. Excludes app boot, Redis and database I/O.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w31-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustArticleCandidateBarrierFingerprintCommandsParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w31-2 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Console/Commands/GetArticleRange.php', 'app/Console/Commands/PrepareReleaseCandidates.php', 'app/Console/Commands/ReleaseVerificationBarrier.php', 'app/Console/Commands/TransferFingerprintQueue.php', 'app/Console/Commands/BackfillReleaseFingerprintMetadata.php', 'tests/Unit/RustArticleCandidateBarrierFingerprintCommandsParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(31022026));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $class::setUseRustFfi(true);
                        $this->assertSame(end($expected), serialize($class::$method(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
                    for ($round = 0; $round < 3; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < 41; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < 200; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            foreach ($order as $side) {
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                    }
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $report['results'][$name]['qualifies'] = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $class::setUseRustFfi(null);
                $this->assertFalse($class::usesRustFfi());
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            $summary = [];
            foreach ($report['results'] as $helper => $cells) {
                $summary[] = sprintf('%s common mean %.2fx p99 %.2fx; uncommon mean %.2fx p99 %.2fx', $helper,
                    $cells['common']['round_range']['mean_speedup']['min'], $cells['common']['round_range']['p99_speedup']['min'],
                    $cells['uncommon']['round_range']['mean_speedup']['min'], $cells['uncommon']['round_range']['p99_speedup']['min']);
            }
            fwrite(STDERR, "\nWave 31.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w31-article-candidate-barrier-fingerprint-commands-test', GetArticleRange::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicitly built optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    private static function payload(string $command): string
    {
        return json_encode(['displayName' => CollectReleaseFingerprintMetadata::class, 'data' => ['command' => $command]], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function legacyPayload(int $releaseId): string
    {
        return self::payload(serialize(new CollectReleaseFingerprintMetadata($releaseId)));
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>, int}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0releaseId\";i:", 64);

        return [
            [GetArticleRange::class, 'isValidMode', [['binaries'], ['backfill']], [[''], ['Binaries'], ['binaries '], ["binaries\0"], ['é'], [$long]], 0],
            [GetArticleRange::class, 'isValidArticleRange', [[1000, 1999], [1, 1]], [[0, 0], [-1, 5], [5, 4], [PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MAX], [1, PHP_INT_MIN]], 1],
            [GetArticleRange::class, 'isContiguousBinariesRange', [['binaries', 1000, 1001], ['binaries', 1000, 500]], [['binaries', 1000, 1002], ['binaries', null, 1], ['backfill', 1000, 1], ['', 0, 0],
                ['binaries', PHP_INT_MAX, PHP_INT_MAX], ['binaries', PHP_INT_MIN, PHP_INT_MIN], ['binaries', -1, 0], ['BINARIES', 1, 1]], 2],
            [PrepareReleaseCandidates::class, 'normalizePositiveOptions', [['5000', '30', '200', '20'], [5000, 30, 200, 20]], [[null, false, true, ''], ['0', '-1', ' 7 ', "\v9\f"], ['+3', 'abc', 'é', "\0"],
                [PHP_INT_MIN, PHP_INT_MAX, (string) PHP_INT_MAX, '-9223372036854775808']], 3],
            [PrepareReleaseCandidates::class, 'formatClassifiedMessage', [[0], [1234]], [[-1], [PHP_INT_MAX], [PHP_INT_MIN]], 4],
            [PrepareReleaseCandidates::class, 'formatSettledDirtyMessage', [[3, 10, 2], [0, 0, 0]], [[PHP_INT_MAX, PHP_INT_MIN, -1]], 5],
            [ReleaseVerificationBarrier::class, 'resolveReleaseScopeReason', [[false], [true]], [[false]], 6],
            [ReleaseVerificationBarrier::class, 'clampTimeoutSeconds', [['900'], [900]], [[null], [''], ['0'], ['-5'], [' 30'], ['30 '], [true], [false], [PHP_INT_MIN], [(string) PHP_INT_MAX], ['abc'], ["\0"]], 7],
            [ReleaseVerificationBarrier::class, 'shouldCheckLiveness', [[1_700_000_005, 1_700_000_000], [1_700_000_001, 1_700_000_000], [1_700_000_000, 0]], [[0, 0], [4, -1], [PHP_INT_MIN, 1], [PHP_INT_MAX, -1], [PHP_INT_MAX, PHP_INT_MIN], [PHP_INT_MIN, PHP_INT_MAX]], 8],
            [TransferFingerprintQueue::class, 'clampBatchLimit', [['1000'], [1000]], [[null], ['0'], ['10000'], ['10001'], ['-9'], [PHP_INT_MAX], [PHP_INT_MIN], [true], ['x']], 9],
            [TransferFingerprintQueue::class, 'extractReleaseIdFromQueuePayload', [[self::legacyPayload(42)], [self::legacyPayload(123456789)]], [
                [self::payload('releaseId";i:0;releaseId";i:7;')], [self::payload('releaseId";i:012;')], [self::payload('releaseId";i:12')], [self::payload('releaseId";i:-3;')],
                [self::payload('releaseId";i:9223372036854775807;')], [self::payload('')], [self::payload($long.'releaseId";i:5;')], [self::payload("é\0releaseId\";i:8;")],
                ['{"displayName":"Other","data":{"command":"releaseId\";i:4;"}}'], [json_encode(['displayName' => CollectReleaseFingerprintMetadata::class, 'data' => ['command' => 4]], JSON_THROW_ON_ERROR)],
                [json_encode(['displayName' => CollectReleaseFingerprintMetadata::class, 'data' => 'releaseId";i:4;'], JSON_THROW_ON_ERROR)], [json_encode(['displayName' => CollectReleaseFingerprintMetadata::class], JSON_THROW_ON_ERROR)],
                [json_encode([CollectReleaseFingerprintMetadata::class], JSON_THROW_ON_ERROR)], ['5'], ['null'], ['"x"'],
                [json_encode(['displayName' => strtolower(CollectReleaseFingerprintMetadata::class), 'data' => ['command' => 'releaseId";i:4;']], JSON_THROW_ON_ERROR)],
            ], 10],
            [TransferFingerprintQueue::class, 'formatCompletionSummary', [[false, 1000], [true, 12]], [[true, 0], [false, PHP_INT_MIN], [true, PHP_INT_MAX]], 11],
            [BackfillReleaseFingerprintMetadata::class, 'normalizeBackfillOptions', [['100', '0', '0'], [100, 4242, 25]], [[null, null, null], ['0', '-1', '-1'], ['10001', (string) PHP_INT_MAX, '60001'],
                [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX], [true, false, true], [' 5', "7\n", 'x']], 12],
            [BackfillReleaseFingerprintMetadata::class, 'markersAreCurrent', [['1', 'integer', 'compact-v1', 'text', 1, 'compact-v1'], ['1', 'integer', 'compact-v1', 'string', 1, 'compact-v1'], ['40', 'integer', null, null, 40, null]], [
                [null, null, null, null, 1, 'compact-v1'], ['1', 'text', 'compact-v1', 'text', 1, 'compact-v1'], ['01', 'INTEGER', 'compact-v1', 'TEXT', 1, 'compact-v1'], ['-0', 'integer', null, 'NULL', 0, null],
                ['1.0', 'integer', 'compact-v1', 'text', 1, 'compact-v1'], ['1', 'integer', '[]', 'array', 1, 'compact-v1'], ['1', 'integer', 'compact-v1', 'text', 1, null], ['40', 'integer', 'x', 'true', 40, null],
                ['1', 'integer', "compact-v1\0", 'text', 1, 'compact-v1'], ['1', 'integer', 'é', 'text', 1, 'é'], ['1', 'real', 'compact-v1', 'text', 1, 'compact-v1'], ['', '', '', '', 0, ''],
                [(string) PHP_INT_MIN, 'integer', null, null, PHP_INT_MIN, null],
            ], 13],
            [BackfillReleaseFingerprintMetadata::class, 'formatSummaryLine', [[false, 100, 12345], [true, 5, 0]], [[false, 0, 0], [true, PHP_INT_MAX, PHP_INT_MIN], [false, PHP_INT_MIN, PHP_INT_MAX]], 14],
        ];
    }

    /** @param list<int> $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1],
            'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
