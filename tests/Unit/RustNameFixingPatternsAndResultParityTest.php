<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\BlockAbusiveServices;
use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Patterns\AppPatterns;
use App\Services\NameFixing\Patterns\GamePatterns;
use App\Services\NameFixing\Patterns\MoviePatterns;
use App\Services\NameFixing\Patterns\TvPatterns;
use App\Support\Passkeys\RelyingPartyIdResolver;
use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use FFI;
use Illuminate\Http\Request;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Wave 19.1: exact catalog bytes/order and additive NameFixResult helpers.
 * Baseline db5ebbf223d3ba95d86c899eeffc898fff65d13c; PHP 8.5.12,
 * rustc 1.99.1 / cargo 1.99.1, Darwin arm64 Mac17,3 (10 CPUs, 32 GiB).
 * Release profile: opt-level=3, LTO, one codegen unit; no added dependencies.
 * Local host process with no imposed CPU/memory cap; concurrent host work can
 * affect tail latency, so these observations cannot authorize adoption alone.
 *
 * Benchmarks: NNTMUX_NAME_FIXING_PATTERNS_RESULT_BENCH=1 php artisan test --compact
 * tests/Unit/RustNameFixingPatternsAndResultParityTest.php --filter=benchmark
 * Protocol: seed 20261024, 256 warmup pairs, 3 x 4096 randomized single-call
 * pairs per operation/workload; nearest-rank p99, PHP/Rust latency ratios.
 * The real PHP adapter, framing, allocations and DTO construction are timed.
 * Framework serialization remains inside both toArray paths. Library startup
 * is recorded separately; this is a warmed long-lived caller comparison.
 * Raw paired nanoseconds, order, source/tool/library/input hashes and failures
 * are preserved in the temporary JSON artifact printed by the benchmark.
 * Adoption requires all four common/uncommon mean/p99 ratios >= 1.5 in every
 * repetition; otherwise every production toggle remains false.
 *
 * Measured 2026-10-24: 638,976 pairs, no invalid observations. Every operation
 * failed the adoption gate; all five production toggles remain false. Ratios
 * below are min-max across three repetitions, common mean | common p99 |
 * uncommon mean | uncommon p99 (PHP ns / native ns). Raw artifact from this
 * run: wave19-name-fixing-44466.json in the host temporary directory.
 * AppPatterns.getAllPatterns: 0.034-0.034 | 0.035-0.036 | 0.034-0.035 | 0.034-0.036
 * AppPatterns.getPriorityOrder: 0.084-0.087 | 0.086-0.091 | 0.086-0.086 | 0.091-0.091
 * AppPatterns.getPatternCount: 0.165-0.174 | 0.155-0.231 | 0.167-0.175 | 0.155-0.231
 * AppPatterns.getPattern: 0.089-0.091 | 0.142-0.142 | 0.089-0.093 | 0.144-0.155
 * AppPatterns.getPatternKey: 0.171-0.174 | 0.183-0.214 | 0.170-0.172 | 0.214-0.250
 * GamePatterns.getAllPatterns: 0.028-0.028 | 0.028-0.041 | 0.028-0.028 | 0.028-0.029
 * GamePatterns.getPriorityOrder: 0.076-0.079 | 0.079-0.100 | 0.078-0.079 | 0.077-0.079
 * GamePatterns.getPatternCount: 0.170-0.175 | 0.231-0.231 | 0.161-0.170 | 0.155-0.214
 * GamePatterns.getPattern: 0.086-0.089 | 0.077-0.153 | 0.089-0.091 | 0.142-0.153
 * GamePatterns.getPatternKey: 0.184-0.185 | 0.214-0.231 | 0.184-0.188 | 0.214-0.250
 * MoviePatterns.getAllPatterns: 0.024-0.024 | 0.023-0.024 | 0.023-0.024 | 0.024-0.034
 * MoviePatterns.getPriorityOrder: 0.068-0.071 | 0.067-0.087 | 0.069-0.071 | 0.087-0.102
 * MoviePatterns.getPatternCount: 0.174-0.176 | 0.168-0.231 | 0.171-0.174 | 0.155-0.231
 * MoviePatterns.getPattern: 0.089-0.092 | 0.142-0.153 | 0.089-0.090 | 0.084-0.153
 * MoviePatterns.getPatternKey: 0.195-0.200 | 0.214-0.231 | 0.189-0.196 | 0.214-0.231
 * TvPatterns.getAllPatterns: 0.022-0.023 | 0.022-0.023 | 0.022-0.022 | 0.022-0.023
 * TvPatterns.getPriorityOrder: 0.068-0.068 | 0.067-0.077 | 0.070-0.072 | 0.067-0.089
 * TvPatterns.getPatternCount: 0.159-0.166 | 0.155-0.214 | 0.163-0.169 | 0.168-0.168
 * TvPatterns.getPattern: 0.088-0.088 | 0.077-0.153 | 0.089-0.091 | 0.142-0.142
 * TvPatterns.getPatternKey: 0.195-0.201 | 0.214-0.250 | 0.199-0.203 | 0.200-0.214
 * Result.matched: 0.110-0.113 | 0.128-0.143 | 0.045-0.047 | 0.051-0.052
 * Result.unmatched: 0.108-0.112 | 0.115-0.139 | 0.112-0.112 | 0.100-0.118
 * Result.fromCheckResult: 0.032-0.033 | 0.052-0.055 | 0.114-0.116 | 0.121-0.131
 * Result.isSuccessful: 0.147-0.151 | 0.144-0.214 | 0.150-0.155 | 0.155-0.155
 * Result.toArray: 0.773-0.779 | 0.686-0.850 | 0.642-0.648 | 0.633-0.683
 * Result.getSummary: 0.139-0.145 | 0.143-0.167 | 0.183-0.197 | 0.196-0.212
 */
final class RustNameFixingPatternsAndResultParityTest extends TestCase
{
    private const array CLASSES = [AppPatterns::class, GamePatterns::class, MoviePatterns::class, TvPatterns::class];

    private const array PRIORITIES = [
        ['WITH_KEYGEN', 'WINDOWS', 'MACOS', 'LINUX', 'ADOBE', 'MICROSOFT', 'WITH_VERSION'],
        ['MODERN_CONSOLE', 'REGION_CONSOLE', 'CONSOLE_SCENE_GROUP', 'PC_SCENE_GROUP', 'DLC_UPDATE', 'OUTLAWS', 'ALIAS', 'GOG', 'REPACK'],
        ['UHD_HDR', 'UHD_REMUX', 'STREAMING_4K', 'STREAMING_HD', 'YEAR_RES_VCODEC', 'YEAR_SOURCE_VCODEC_RES', 'YEAR_SOURCE_VCODEC_ACODEC', 'RES_SOURCE_ACODEC_VCODEC', 'RES_ACODEC_SOURCE_YEAR', 'MULTI_LANGUAGE', 'GENERIC'],
        ['STREAMING_4K_HDR', 'STREAMING_HD', 'TV_RES_SOURCE_VCODEC', 'TV_AUDIO_SOURCE_RES_VCODEC', 'TV_SOURCE_VCODEC', 'TV_SOURCE_GROUP', 'TV_YEAR_SEASON', 'TV_WITH_YEAR', 'DAILY_SHOW', 'SPORTS', 'COMPLETE_SEASON', 'ANIME'],
    ];

    private function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', AppPatterns::FFI_CDEF."\n".NameFixResult::FFI_CDEF);
        self::assertNotNull($ffi, 'Build and install the release Rust library before parity tests.');

        return $ffi;
    }

    protected function tearDown(): void
    {
        foreach ([...self::CLASSES, NameFixResult::class] as $class) {
            $class::setUseRustFfi(false);
        }
        parent::tearDown();
    }

    /** @param list<string> $fields */
    private function frames(array $fields): string
    {
        return implode('', array_map(static fn (string $field): string => pack('V', strlen($field)).$field, $fields));
    }

    public function test_catalogs_preserve_every_pattern_byte_priority_and_lookup(): void
    {
        $this->ffi();
        foreach (self::CLASSES as $kind => $class) {
            $expected = [];
            foreach (self::PRIORITIES[$kind] as $key) {
                $expected[$key] = constant($class.'::'.$key);
            }
            self::assertSame($expected, $class::ALL_PATTERNS);
            self::assertSame($expected, $class::getAllPatternsPhp());
            self::assertSame($expected, $class::getAllPatternsNative());
            self::assertSame(self::PRIORITIES[$kind], $class::getPriorityOrderNative());
            self::assertSame(count($expected), $class::getPatternCountNative());
            foreach ([false, true] as $enabled) {
                $class::setUseRustFfi($enabled);
                self::assertSame($expected, $class::getAllPatterns());
                self::assertSame(array_keys($expected), $class::getPriorityOrder());
                self::assertSame(count($expected), $class::getPatternCount());
                foreach (array_keys($expected) as $index => $key) {
                    self::assertSame($expected[$key], $class::getPattern($key));
                    self::assertSame($expected[$key], $class::getPatternNative($key));
                    self::assertSame($key, $class::getPatternKey($index));
                    self::assertSame($key, $class::getPatternKeyNative($index));
                    self::assertNotFalse(preg_match($expected[$key], 'Example.Title.2026.1080p.WEB-DL.x264-GROUP'));
                }
                foreach (['', 'UNKNOWN', 'ALL_PATTERNS', 'QUALIFIERS', 'PLATFORMS', 'STREAMING_HD ', 'streaming_hd', "WINDOWS\0", "\xff", '番組', str_repeat('x', 65536)] as $unknown) {
                    self::assertNull($class::getPatternPhp($unknown));
                    self::assertNull($class::getPatternNative($unknown));
                    self::assertNull($class::getPattern($unknown));
                }
                foreach ([-1, count($expected), PHP_INT_MAX] as $index) {
                    self::assertNull($class::getPatternKey($index));
                    self::assertNull($class::getPatternKeyNative($index));
                }
            }
        }
    }

    public function test_existing_subcatalogs_and_constants_keep_their_contract(): void
    {
        self::assertSame(array_intersect_key(AppPatterns::ALL_PATTERNS, array_flip(['WINDOWS', 'MACOS', 'LINUX'])), AppPatterns::getPlatformPatterns());
        self::assertSame(array_intersect_key(AppPatterns::ALL_PATTERNS, array_flip(['ADOBE', 'MICROSOFT'])), AppPatterns::getVendorPatterns());
        self::assertSame(array_slice(GamePatterns::ALL_PATTERNS, 0, 3, true), GamePatterns::getConsolePatterns());
        self::assertSame(array_slice(GamePatterns::ALL_PATTERNS, 3, null, true), GamePatterns::getPCPatterns());
        self::assertSame(array_slice(MoviePatterns::ALL_PATTERNS, 0, 3, true), MoviePatterns::get4KPatterns());
        self::assertSame(array_slice(TvPatterns::ALL_PATTERNS, 0, 2, true), TvPatterns::getStreamingPatterns());
        self::assertSame(array_slice(TvPatterns::ALL_PATTERNS, 2, 6, true), TvPatterns::getStandardPatterns());
        self::assertContains('arm64', AppPatterns::ARCHITECTURES);
        self::assertContains('Pre-Activated', AppPatterns::QUALIFIERS);
        self::assertContains('TENOKE', GamePatterns::SCENE_GROUPS);
        self::assertSame('Nintendo Switch', GamePatterns::PLATFORMS['NSW']);
    }

    public function test_result_factories_status_and_summary_preserve_binary_and_numeric_edges(): void
    {
        $this->ffi();
        $names = ['', '0', ' ', 'Title.2026-GRP', "A\0B\n\xff", '番組.Été', str_repeat('long.', 2000)];
        foreach ($names as $index => $name) {
            $method = $index % 2 ? "method\0\xff" : '';
            $checker = $index % 2 ? 'Checker: Été' : '';
            $id = [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX][$index % 5];
            $confidence = [-INF, -0.0, 0.5, 1.0, INF][$index % 5];
            $expected = new NameFixResult($name, $method, $checker, $id, $confidence);
            self::assertSame(serialize($expected), serialize(NameFixResult::matchedPhp($name, $method, $checker, $id, $confidence)));
            self::assertSame(serialize($expected), serialize(NameFixResult::matchedNative($name, $method, $checker, $id, $confidence)));
            $expected->metadata = ['nested' => ['bytes' => "\0\xff", 'null' => null], 'confidence' => NAN];
            self::assertSame($name !== '', $expected->isSuccessfulNative());
            self::assertSame($expected->getSummaryPhp(), $expected->getSummaryNative());
            self::assertSame($name === '' ? $checker.': no match' : $checker.': '.$method.' => '.$name, $expected->getSummaryNative());
            self::assertSame($expected, NameFixResult::fromCheckResultNative($expected));
            foreach ([false, true] as $enabled) {
                NameFixResult::setUseRustFfi($enabled);
                self::assertSame($name !== '', $expected->isSuccessful());
                self::assertSame($expected->getSummaryPhp(), $expected->getSummary());
                self::assertSame($expected, NameFixResult::fromCheckResult($expected, 'ignored'));
                self::assertSame(serialize(new NameFixResult($name, $method, $checker, $id, $confidence)), serialize(NameFixResult::matched($name, $method, $checker, $id, $confidence)));
                $unmatched = NameFixResult::unmatched($checker);
                self::assertSame(['', '', $checker, 0, 0.0, []], [$unmatched->newName, $unmatched->method, $unmatched->checkerName, $unmatched->preDbId, $unmatched->confidence, $unmatched->metadata]);
                self::assertSame(serialize($unmatched), serialize(NameFixResult::unmatchedNative($checker)));
                self::assertSame(serialize($unmatched), serialize(NameFixResult::fromCheckResult(null, $checker)));
                self::assertSame(serialize($unmatched), serialize(NameFixResult::fromCheckResultNative(null, $checker)));
                $oldFactory = NameFixResult::fromMatch($name, $method, $checker, $id, $confidence);
                self::assertSame($id > 0, $oldFactory->hasPreDbId());
                self::assertSame($checker.': '.$method, $oldFactory->getFormattedMethod());
                $predb = NameFixResult::fromPreDb($name, $id, $method, $checker);
                self::assertSame('PreDB: '.$method, $predb->method);
                self::assertSame(['source' => $method], $predb->metadata);
            }
        }
    }

    public function test_result_array_preserves_framework_partials_metadata_and_mutations(): void
    {
        $this->ffi();
        $result = new NameFixResult("0\0\xff", "m\n", '番組', PHP_INT_MAX, NAN, ['nested' => new NameFixResult('nested', 'method', 'checker'), 'nan' => NAN, 'raw' => "\xff\0", 'null' => null]);
        foreach ([
            static fn (): NameFixResult => clone $result,
            static fn (): NameFixResult => (clone $result)->only('newName', 'metadata'),
            static fn (): NameFixResult => (clone $result)->except('newName', 'checkerName'),
            static fn (): NameFixResult => (clone $result)->additional(['extra' => 'value', 'newName' => 'override']),
        ] as $makeVariant) {
            // Spatie consumes temporary partials. Each observation needs the
            // same initial context, not a second call on the consumed object.
            $expected = $makeVariant()->transform();
            self::assertSame(serialize($expected), serialize($makeVariant()->toArrayPhp()));
            self::assertSame(serialize($expected), serialize($makeVariant()->toArrayNative()));
            foreach ([false, true] as $enabled) {
                NameFixResult::setUseRustFfi($enabled);
                self::assertSame(serialize($expected), serialize($makeVariant()->toArray()));
            }
        }
        $result->newName = '';
        $result->method = "changed\0";
        self::assertFalse($result->isSuccessfulNative());
        self::assertSame(serialize($result->transform()), serialize($result->toArrayNative()));
    }

    public function test_native_declines_and_dispatchers_fall_back_when_library_is_unavailable(): void
    {
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $original = $cache->getValue();
        try {
            $blocked = $original;
            foreach ([AppPatterns::FFI_CDEF, NameFixResult::FFI_CDEF] as $cdef) {
                $blocked['cleaning|default|'.hash('xxh3', $cdef)] = false;
            }
            $cache->setValue(null, $blocked);
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
                self::assertNull($class::getAllPatternsNative());
                self::assertNull($class::getPriorityOrderNative());
                self::assertNull($class::getPatternCountNative());
                self::assertNull($class::getPatternNative(array_key_first($class::ALL_PATTERNS)));
                self::assertSame($class::ALL_PATTERNS, $class::getAllPatterns());
                self::assertSame(array_keys($class::ALL_PATTERNS), $class::getPriorityOrder());
                self::assertSame(count($class::ALL_PATTERNS), $class::getPatternCount());
            }
            NameFixResult::setUseRustFfi(true);
            $result = new NameFixResult('Title', 'Method', 'Checker');
            self::assertNull(NameFixResult::matchedNative('Title', 'Method', 'Checker'));
            self::assertNull(NameFixResult::unmatchedNative('Checker'));
            self::assertNull(NameFixResult::fromCheckResultNative($result));
            self::assertNull($result->isSuccessfulNative());
            self::assertNull($result->toArrayNative());
            self::assertNull($result->getSummaryNative());
            self::assertTrue($result->isSuccessful());
            self::assertSame($result->getSummaryPhp(), $result->getSummary());
            self::assertSame($result->toArrayPhp(), $result->toArray());
            self::assertSame(['newName' => 'Title'], (clone $result)->only('newName')->toArray());
            self::assertSame(['method' => 'Method'], (clone $result)->only('method')->toArray());
            self::assertSame('Title', NameFixResult::matched('Title', 'Method', 'Checker')->newName);
            self::assertSame('', NameFixResult::unmatched('Checker')->newName);
            self::assertSame($result, NameFixResult::fromCheckResult($result));
        } finally {
            $cache->setValue(null, $original);
        }
    }

    public function test_abi_rejects_invalid_regions_modes_framing_and_small_buffers_atomically(): void
    {
        $ffi = $this->ffi();
        $out = $ffi->new('char[32]');
        FFI::memset($out, 0x5A, 32);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fixing_patterns(0, 0, null, 0, 0, $out, 32));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fixing_patterns(0, 0, '', 0, 0, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fixing_patterns(0, 0, '', -1, 0, $out, 32));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fixing_patterns(4, 0, '', 0, 0, $out, 32));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fixing_patterns(0, 99, '', 0, 0, $out, 32));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_name_fixing_patterns(0, 0, '', 0, 0, $out, 32));
        foreach (['', "\x01", pack('V', 100).'short', $this->frames(['one'])] as $bad) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_name_fix_result(0, $bad, strlen($bad), 0, 1, $out, 32));
        }
        $valid = $this->frames(['Title', 'method', 'checker']);
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_name_fix_result(5, $valid, strlen($valid), 0, 1, $out, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fix_result(3, null, 0, 0, 1, $out, 32));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fix_result(3, '', 0, 0, 1, $out, -1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fix_result(99, $valid, strlen($valid), 0, 1, $out, 32));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_name_fix_result(0, $valid, strlen($valid), 0, 2, $out, 32));
        self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
    }

    public function test_legacy_null_optional_outputs_decline_and_public_callers_preserve_php_behavior(): void
    {
        $this->ffi();
        $sqlToggle = (new ReflectionProperty(SqlError::class, 'useRustFfi'))->getValue();
        $rpToggle = (new ReflectionProperty(RelyingPartyIdResolver::class, 'useRustFfi'))->getValue();
        $blockToggle = BlockAbusiveServices::usesRustFfi();
        $rpConfig = config('passkeys.relying_party.id');
        try {
            SqlError::setUseRustFfi(true);
            RelyingPartyIdResolver::setUseRustFfi(true);
            BlockAbusiveServices::setUseRustFfi(true);
            foreach (['Deadlock found when trying to get lock', 'database is locked', 'unrelated failure', str_repeat('é', 501).' (Connection: mariadb, SQL: private statement)'] as $message) {
                $exception = new RuntimeException($message);
                self::assertNull(SqlError::isTransientLockNative($exception));
                self::assertNull(SqlError::describeNative($exception));
                self::assertSame(SqlError::isTransientLockPhp($exception), SqlError::isTransientLock($exception));
                self::assertSame(SqlError::describePhp($exception), SqlError::describe($exception));
            }
            foreach (['', 'localhost', 'example.com', '127.0.0.1', 'bad host'] as $host) {
                self::assertNull(RelyingPartyIdResolver::isValidRelyingPartyIdNative($host));
                self::assertSame(RelyingPartyIdResolver::isValidRelyingPartyIdPhp($host), RelyingPartyIdResolver::isValidRelyingPartyId($host));
            }
            config()->set('passkeys.relying_party.id', 'example.com');
            foreach (['', 'auth.example.com:8443, proxy.local', '127.0.0.1'] as $forwarded) {
                $request = Request::create('https://portal.example.net/passkeys', 'GET', [], [], [], ['HTTP_X_FORWARDED_HOST' => $forwarded]);
                self::assertSame(RelyingPartyIdResolver::resolvePhp($request), RelyingPartyIdResolver::resolve($request));
            }
            $middleware = (new ReflectionClass(BlockAbusiveServices::class))->newInstanceWithoutConstructor();
            foreach (['', 'Mozilla/5.0', 'AIOStreams/1.0', 'UsenetStreamer/2.0'] as $agent) {
                self::assertNull($middleware->isBlockedUserAgentNative($agent));
                self::assertSame($middleware->isBlockedUserAgentPhp($agent), $middleware->isBlockedUserAgent($agent));
            }
        } finally {
            SqlError::setUseRustFfi($sqlToggle);
            RelyingPartyIdResolver::setUseRustFfi($rpToggle);
            BlockAbusiveServices::setUseRustFfi($blockToggle);
            config()->set('passkeys.relying_party.id', $rpConfig);
        }
    }

    public function test_production_defaults_remain_php_until_every_speed_gate_passes(): void
    {
        foreach ([...self::CLASSES, NameFixResult::class] as $class) {
            self::assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi'], $class);
        }
    }

    /**
     * Reproducible paired caller benchmark. Timing results are evidence, not CI
     * assertions: noisy machines cannot enable native production dispatch.
     */
    public function test_benchmark_paired_common_and_uncommon_workloads(): void
    {
        if (getenv('NNTMUX_NAME_FIXING_PATTERNS_RESULT_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_NAME_FIXING_PATTERNS_RESULT_BENCH=1 for paired measurements.');
        }
        $startup = hrtime(true);
        $this->ffi();
        $startup = hrtime(true) - $startup;
        $workloads = [];
        foreach (self::CLASSES as $class) {
            $label = (new ReflectionClass($class))->getShortName();
            foreach (['common', 'uncommon'] as $kind) {
                $keys = array_keys($class::ALL_PATTERNS);
                $key = $kind === 'common' ? $keys[0] : $keys[count($keys) - 1];
                $index = $kind === 'common' ? 0 : count($keys) - 1;
                // Catalog/count have no variable input; uncommon exercises the
                // same fixed operation rather than inventing a bulk speedup.
                foreach (['getAllPatterns', 'getPriorityOrder', 'getPatternCount'] as $method) {
                    $workloads[$label.'.'.$method.'/'.$kind] = [static fn () => $class::{$method.'Php'}(), static fn () => $class::{$method.'Native'}()];
                }
                $workloads[$label.'.getPattern/'.$kind] = [static fn () => $class::getPatternPhp($key), static fn () => $class::getPatternNative($key)];
                $workloads[$label.'.getPatternKey/'.$kind] = [static fn () => $class::getPatternKeyPhp($index), static fn () => $class::getPatternKeyNative($index)];
            }
        }
        foreach (['common', 'uncommon'] as $kind) {
            $long = $kind === 'uncommon';
            $name = $long ? str_repeat("番組\0\xff.Long.", 1024) : 'Show.S01E01-GRP';
            $method = $long ? str_repeat('Method.', 64) : 'TV';
            $checker = $long ? "Checker\0\xff" : 'TV';
            $id = $long ? PHP_INT_MAX : 42;
            $confidence = $long ? 0.001 : 1.0;
            $result = new NameFixResult($name, $method, $checker, $id, $confidence, $long ? ['nested' => array_fill(0, 64, ['bytes' => "\0\xff", 'ids' => [1, 2, 3]])] : []);
            $checkResult = $long ? null : $result;
            $workloads['Result.matched/'.$kind] = [static fn () => NameFixResult::matchedPhp($name, $method, $checker, $id, $confidence), static fn () => NameFixResult::matchedNative($name, $method, $checker, $id, $confidence)];
            $workloads['Result.unmatched/'.$kind] = [static fn () => NameFixResult::unmatchedPhp($checker), static fn () => NameFixResult::unmatchedNative($checker)];
            $workloads['Result.fromCheckResult/'.$kind] = [static fn () => NameFixResult::fromCheckResultPhp($checkResult, $checker), static fn () => NameFixResult::fromCheckResultNative($checkResult, $checker)];
            foreach (['isSuccessful', 'toArray', 'getSummary'] as $methodName) {
                $workloads['Result.'.$methodName.'/'.$kind] = [static fn () => $result->{$methodName.'Php'}(), static fn () => $result->{$methodName.'Native'}()];
            }
        }
        $sourcePaths = ['tests/Unit/RustNameFixingPatternsAndResultParityTest.php', 'rust/pipeline-ffi/src/cleaning.rs', 'app/Services/NameFixing/Data/NameFixResult.php', ...array_map(static fn (string $class): string => 'app/Services/NameFixing/Patterns/'.(new ReflectionClass($class))->getShortName().'.php', self::CLASSES)];
        $sourceHashes = [];
        foreach ($sourcePaths as $path) {
            $sourceHashes[$path] = hash_file('sha256', base_path($path));
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $artifact = ['protocol' => ['seed' => 20261024, 'warmup_pairs' => 256, 'repetitions' => 3, 'pairs_per_repetition' => 4096, 'percentile' => 'nearest-rank p99', 'threshold' => 1.5, 'startup_ns' => $startup], 'environment' => ['php' => PHP_VERSION, 'os' => php_uname(), 'opcache' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'source_sha256' => $sourceHashes, 'composer_lock_sha256' => hash_file('sha256', base_path('composer.lock')), 'vendor_lock_sha256' => hash_file('sha256', base_path('vendor/composer/installed.json')), 'rust_lock_sha256' => hash_file('sha256', base_path('rust/pipeline-ffi/Cargo.lock')), 'release_library_sha256' => hash_file('sha256', storage_path('app/nntmux-native/libnntmux_pipeline.'.$suffix))], 'workloads' => [], 'failures' => []];
        $path = sys_get_temp_dir().'/wave19-name-fixing-'.getmypid().'.json';
        $random = new Randomizer(new Mt19937(20261024));
        try {
            foreach ($workloads as $label => [$phpCall, $nativeCall]) {
                $expected = $phpCall();
                $native = $nativeCall();
                self::assertNotNull($native, $label.' must measure the real native path.');
                self::assertSame(serialize($expected), serialize($native), $label);
                $inputHash = hash('sha256', serialize($expected));
                for ($warm = 0; $warm < 256; $warm++) {
                    $phpCall();
                    $nativeCall();
                }
                $repetitions = [];
                for ($rep = 0; $rep < 3; $rep++) {
                    $samples = [[], []];
                    $order = '';
                    for ($pair = 0; $pair < 4096; $pair++) {
                        $first = $random->getInt(0, 1);
                        $order .= (string) $first;
                        foreach ([$first, 1 - $first] as $side) {
                            $call = $side === 0 ? $phpCall : $nativeCall;
                            $start = hrtime(true);
                            $call();
                            $samples[$side][] = hrtime(true) - $start;
                        }
                    }
                    $means = [array_sum($samples[0]) / 4096, array_sum($samples[1]) / 4096];
                    $p99 = [];
                    foreach ($samples as $values) {
                        sort($values);
                        $p99[] = $values[(int) ceil(count($values) * 0.99) - 1];
                    }
                    $repetitions[] = ['php_ns' => $samples[0], 'native_ns' => $samples[1], 'order' => $order, 'mean_speedup' => $means[0] / $means[1], 'p99_speedup' => $p99[0] / $p99[1]];
                }
                $artifact['workloads'][$label] = ['output_sha256' => $inputHash, 'repetitions' => $repetitions];
                $ratios = array_map(static fn (array $rep): string => sprintf('%.3fx/%.3fx', $rep['mean_speedup'], $rep['p99_speedup']), $repetitions);
                fwrite(STDOUT, $label.' '.implode(' ', $ratios)."\n");
            }
        } catch (\Throwable $exception) {
            $artifact['failures'][] = ['class' => get_class($exception), 'message' => $exception->getMessage()];
            throw $exception;
        } finally {
            file_put_contents($path, json_encode($artifact, JSON_THROW_ON_ERROR));
            fwrite(STDOUT, 'Wave19 raw benchmark artifact: '.$path."\n");
        }
    }
}
