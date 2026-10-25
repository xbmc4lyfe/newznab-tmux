<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NzbImportStatus;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbArticleCoverage;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbImportService;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNzbSyncNfoImportCoverageCandidateW41ParityTest extends TestCase
{
    /** class => helper method; every class exposes the same Wave 41.2 toggle trio. */
    private const array HELPERS = [
        DirectorySyncBridge::class => 'summarizeDirectorySyncAttempts',
        NfoImportService::class => 'summarizeNfoImportPayloads',
        NzbArticleCoverage::class => 'summarizeCoverageCandidates',
        NzbCreationCandidateQuery::class => 'summarizeClaimWindow',
        NzbImportService::class => 'summarizeImportStatusBatch',
    ];

    private const array NFOS = ['', 'x', "a\0b", "\0", 'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn', "\xdb\xb0\xb1\xb2\r\n", 'ä NFO', "trailing\0", ' '];

    private const array STATUSES = ['inserted', 'queued', 'metadata_ignored', 'metadata_deferred', 'duplicate', 'blacklisted', 'nogroup', 'failed',
        'Inserted', '', 'unknown', "inserted\0", 'inserted ', 'no_group'];

    private const int SEED = 41022026;

    private const int RANDOMIZED = 1300;

    protected function tearDown(): void
    {
        foreach (array_keys(self::HELPERS) as $class) {
            $class::resetW412RustFfi();
        }
        parent::tearDown();
    }

    public function test_defaults_and_toggles_are_independent(): void
    {
        $earlier = self::earlierToggles();
        foreach (array_keys(self::HELPERS) as $class) {
            $this->assertFalse(\constant($class.'::W412_RUST_FFI_DEFAULT'), $class);
            $this->assertFalse($class::usesW412RustFfi(), $class);
        }
        foreach (array_keys(self::HELPERS) as $selected) {
            $selected::useW412RustFfi();
            foreach (array_keys(self::HELPERS) as $class) {
                $this->assertSame($selected === $class, $class::usesW412RustFfi(), $selected.' → '.$class);
            }
            $this->assertSame($earlier, self::earlierToggles(), $selected.' leaves the earlier toggles alone');
            $selected::useW412RustFfi(false);
            $this->assertFalse($selected::usesW412RustFfi());
            $selected::useW412RustFfi(true);
            $this->assertTrue($selected::usesW412RustFfi());
            $selected::resetW412RustFfi();
            $this->assertFalse($selected::usesW412RustFfi());
        }
        // The earlier toggles leave the Wave 41.2 toggles alone.
        DirectorySyncBridge::setUseRustFfi(true);
        NfoImportService::setUseRustFfi(true);
        NzbCreationCandidateQuery::setUseRustFfi(true);
        NzbImportService::setUseRustFfi(! NzbImportService::usesRustFfi());
        try {
            foreach (array_keys(self::HELPERS) as $class) {
                $this->assertFalse($class::usesW412RustFfi(), $class);
            }
        } finally {
            foreach ($earlier as $class => $value) {
                (new ReflectionClass($class))->getProperty('useRustFfi')->setValue(null, $value);
            }
        }
        $this->assertSame($earlier, self::earlierToggles());
        $this->assertSame(array_column(NzbImportStatus::cases(), 'value'), NzbImportService::W412_IMPORT_STATUSES);
    }

    public function test_php_helpers_answer_known_vectors(): void
    {
        $this->assertSame(['attempts' => 6, 'ok' => 1, 'failed' => 2, 'unsupported' => 3, 'slow' => 1, 'total_elapsed_ms' => 565, 'max_elapsed_ms' => 300],
            DirectorySyncBridge::summarizeDirectorySyncAttempts([
                ['function' => 'fsync', 'extension' => true, 'synced' => true, 'elapsed_ms' => 10],
                ['function' => 'fsync', 'extension' => true, 'synced' => false, 'elapsed_ms' => 300],
                ['function' => 'fsync', 'extension' => true, 'elapsed_ms' => 250], ['function' => '', 'extension' => true, 'synced' => true, 'elapsed_ms' => 5],
                ['function' => 'fsync', 'extension' => 1, 'synced' => true, 'elapsed_ms' => -7], 'junk',
            ]));
        $this->assertSame([2, PHP_INT_MAX], self::pick(DirectorySyncBridge::summarizeDirectorySyncAttempts([['elapsed_ms' => PHP_INT_MAX], ['elapsed_ms' => 1]], -1),
            ['slow', 'total_elapsed_ms']));

        $this->assertSame(['entries' => 8, 'accepted' => 2, 'missing_guid' => 2, 'empty' => 2, 'oversized' => 1, 'binary' => 1, 'accepted_bytes' => 6],
            NfoImportService::summarizeNfoImportPayloads([['guid' => 'a', 'nfo' => 'abc'], ['guid' => 'b', 'nfo' => "\0x"], ['guid' => 'c', 'nfo' => ''],
                ['guid' => 'd'], ['guid' => 'e', 'nfo' => 'abcde'], ['guid' => '', 'nfo' => 'xyz'], ['guid' => 'f', 'nfo' => "\xdb\xb0\n"], 5], 4));
        $this->assertSame([0, 1], self::pick(NfoImportService::summarizeNfoImportPayloads([['guid' => 'a', 'nfo' => 'a']], -1), ['accepted', 'oversized']));

        $this->assertSame(['candidates' => 8, 'full' => 2, 'partial' => 1, 'none' => 2, 'invalid' => 3, 'acceptable' => 3, 'total_required' => 44,
            'total_missing' => 21, 'max_missing' => 10], NzbArticleCoverage::summarizeCoverageCandidates([['required' => 10, 'covered' => 10],
                ['required' => 10, 'covered' => 9], ['required' => 10, 'covered' => 0], ['required' => 10, 'covered' => -3], ['required' => 0, 'covered' => 5],
                ['required' => -2, 'covered' => 1], ['required' => 4, 'covered' => 99], ['required' => '4', 'covered' => 4]], 1));

        $this->assertSame(['candidates' => 10, 'invalid_ids' => 3, 'duplicate_ids' => 2, 'unclaimed' => 3, 'fresh' => 1, 'stale' => 3, 'future' => 1,
            'malformed_claims' => 2, 'admissible' => 2], NzbCreationCandidateQuery::summarizeClaimWindow([['id' => 1], ['id' => 2, 'claimed_at' => 950],
                ['id' => 3, 'claimed_at' => 900], ['id' => 4, 'claimed_at' => 1001], ['id' => 5, 'claimed_at' => '900'], ['id' => 1, 'claimed_at' => 500],
                ['id' => 0], ['id' => -4, 'claimed_at' => 1], ['id' => 3, 'claimed_at' => null], ['id' => '6', 'claimed_at' => 1.5]], 1000, 100));
        $this->assertSame([2, 0], self::pick(NzbCreationCandidateQuery::summarizeClaimWindow([['id' => 1, 'claimed_at' => PHP_INT_MIN],
            ['id' => 2, 'claimed_at' => PHP_INT_MAX]], PHP_INT_MAX, -5), ['stale', 'fresh']));
        $this->assertSame([1, 0], self::pick(NzbCreationCandidateQuery::summarizeClaimWindow([['id' => 1, 'claimed_at' => PHP_INT_MIN]], PHP_INT_MIN, PHP_INT_MAX),
            ['fresh', 'admissible']));

        $this->assertSame(['results' => 12, 'inserted' => 2, 'queued' => 1, 'metadata_ignored' => 1, 'metadata_deferred' => 1, 'duplicate' => 1, 'blacklisted' => 1,
            'nogroup' => 1, 'failed' => 1, 'unknown' => 3, 'inserted_bytes' => 100], NzbImportService::summarizeImportStatusBatch([
                ['status' => NzbImportStatus::Inserted, 'bytes' => 100], ['status' => 'queued'], ['status' => NzbImportStatus::MetadataIgnored],
                ['status' => 'metadata_deferred'], ['status' => NzbImportStatus::Duplicate, 'bytes' => 9], ['status' => 'blacklisted'], ['status' => 'nogroup'],
                ['status' => NzbImportStatus::Failed], ['status' => 'Inserted'], ['status' => 'inserted', 'bytes' => -5], 'inserted', ['status' => 7]]));
        $this->assertSame(PHP_INT_MAX, NzbImportService::summarizeImportStatusBatch([['status' => 'inserted', 'bytes' => PHP_INT_MAX],
            ['status' => NzbImportStatus::Inserted, 'bytes' => 1]])['inserted_bytes']);
    }

    public function test_native_matches_php_on_edge_cases_and_randomized_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $class => $method) {
            $cases = [...self::edgeCases($class), ...array_map(static fn (): array => self::randomArgs($class, $random), range(1, self::RANDOMIZED))];
            $this->assertGreaterThanOrEqual(1300, \count($cases));
            foreach ($cases as $args) {
                $expected = $class::{$method.'Php'}(...$args);
                $native = $class::{$method.'Native'}(...$args);
                $this->assertNotNull($native, $class.'::'.$method.' declined '.self::describe($args));
                $this->assertSame($expected, $native, $class.'::'.$method.' '.self::describe($args));
                $class::useW412RustFfi(true);
                $viaRust = $class::$method(...$args);
                $class::useW412RustFfi(false);
                $viaPhp = $class::$method(...$args);
                $this->assertSame([$expected, $expected], [$viaRust, $viaPhp], $class.'::'.$method.' dispatch');
            }
        }
    }

    public function test_out_of_contract_inputs_fall_back_to_php(): void
    {
        $this->nativeFfi();
        foreach ([[5, pack('q', 0)], [-1, pack('q3', 1, 10, 10)], [4294967296, pack('q3', 1, 10, 10)], [0, ''], [0, 'short'], [0, pack('q2', 250, 1)],
            [0, pack('q5', 250, 2, 1, 1, 0)], [0, pack('q5', 250, 1, 1, 2, 0)], [0, pack('q', 250).'x'],
            [1, pack('q', 4)], [1, pack('q4', 4, 1, 1, 2).'a'], [1, pack('q4', 4, 1, 2, 1).'a'], [1, pack('q4', 4, 1, -2, 1)], [1, pack('q2', 4, 0).'x'], [1, pack('q2', 4, -1)],
            [2, pack('q2', 0, 1)], [2, pack('q', 0).'x'], [3, pack('q', 0)], [3, pack('q5', 0, 0, 1, 3, 0)], [3, pack('q4', 0, 0, 1, 0)],
            [4, pack('q2', 1, 0)], [4, pack('q4', 0, 1, 9, 0).'inserted'], [4, pack('q3', 0, 1, 8).'inserted'], [4, pack('q2', 0, 0).'x'],
            [2, str_repeat("\0", 16777224)]] as [$op, $input]) {
            $this->assertNull(DirectorySyncBridge::evaluateW412Native($op, $input, 9), $op.' '.bin2hex(substr($input, 0, 48)));
        }
        $this->assertNull(DirectorySyncBridge::evaluateW412Native(2, pack('q3', 1, 10, 10), 0));
        // A wrong expected slot count is a contract violation, not a partial answer.
        $this->assertNull(DirectorySyncBridge::evaluateW412Native(2, pack('q3', 1, 10, 10), 8));
        $this->assertNull(DirectorySyncBridge::evaluateW412Native(2, pack('q3', 1, 10, 10), 10));
        $this->assertSame([1, 1, 0, 0, 0, 1, 10, 0, 0], DirectorySyncBridge::evaluateW412Native(2, pack('q3', 1, 10, 10), 9));

        // Inputs past the native byte limit keep the PHP answer through every public entry point.
        foreach (array_keys(self::HELPERS) as $class) {
            $class::useW412RustFfi(true);
        }
        $oversized = [
            DirectorySyncBridge::class => [array_fill(0, 524288, ['function' => 'fsync', 'extension' => true, 'synced' => true, 'elapsed_ms' => 1]), 250],
            NfoImportService::class => [[['guid' => 'a', 'nfo' => str_repeat('n', 16777200)]], PHP_INT_MAX],
            NzbArticleCoverage::class => [array_fill(0, 1048576, ['required' => 10, 'covered' => 5]), 0],
            NzbCreationCandidateQuery::class => [array_fill(0, 699051, ['id' => 1]), 1000, 100],
            NzbImportService::class => [[['status' => str_repeat('i', 16777200)]]],
        ];
        foreach ($oversized as $class => $args) {
            $method = self::HELPERS[$class];
            $this->assertNull($class::{$method.'Native'}(...$args), $class);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
        }
    }

    public function test_direct_ffi_export_preserves_sentinels_and_capacity_contract(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, pack('q5', 250, 1, 1, 1, 300), [1, 1, 0, 0, 1, 300, 300]],
            [1, pack('q4', 4, 1, 3, 1).'abc', [1, 1, 0, 0, 0, 0, 3]],
            [2, pack('q3', 1, 10, 10), [1, 1, 0, 0, 0, 1, 10, 0, 0]],
            [3, pack('q5', 1000, 100, 7, 1, 1), [1, 0, 0, 0, 0, 1, 0, 0, 1]],
            [4, pack('q4', 0, 1, 8, 5).'inserted', [1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 5]]];
        foreach ($vectors as [$op, $input, $expected]) {
            $length = \count($expected) * 8;
            $wire = pack('q*', ...$expected);
            $this->assertSame($expected, DirectorySyncBridge::evaluateW412Native($op, $input, \count($expected)));
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w412_nzb_sync_nfo_import_coverage_candidate($op, 0, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2), $op.' capacity '.$capacity);
                if ($written < 0) {
                    // Retry with exactly the reported capacity.
                    $retry = $ffi->new('char['.(-$written).']');
                    // @phpstan-ignore method.notFound
                    $this->assertSame(-$written, $ffi->nntmux_w412_nzb_sync_nfo_import_coverage_candidate($op, 0, $input, \strlen($input), $retry, -$written));
                    $this->assertSame($wire, FFI::string($retry, -$written));
                }
            }
            // Every flag bit is rejected for every operation.
            foreach ([1, 64, 4294967295] as $flags) {
                // @phpstan-ignore method.notFound
                $this->assertSame(-1, $ffi->nntmux_w412_nzb_sync_nfo_import_coverage_candidate($op, $flags, $input, \strlen($input), $small, $length + 2));
            }
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q3', 1, 10, 10);
        foreach ([[null, 0, $buffer, 1024], [null, 24, $buffer, 1024], [$input, 24, null, 0], [$input, 24, null, 72], [$input, 16777217, $buffer, 1024],
            [$buffer, 24, $buffer, 1024], [FFI::addr($buffer[8]), 24, $buffer, 1024], [$buffer, 24, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 24);
            FFI::memset(FFI::addr($buffer[24]), 0x5A, 1000);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w412_nzb_sync_nfo_import_coverage_candidate(2, 0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 24).str_repeat('Z', 1000), FFI::string($buffer, 1024));
        }
        foreach ([5, 99, 4294967295] as $op) {
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w412_nzb_sync_nfo_import_coverage_candidate($op, 0, $input, 24, $buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $helpers = unserialize(base64_decode($argv[2]), ['allowed_classes' => [App\Enums\NzbImportStatus::class]]);
            if (App\Services\Nzb\DirectorySyncBridge::evaluateW412Native(2, pack('q3', 1, 10, 10), 9) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($helpers as [$class, $method, $cases]) {
                foreach ($cases as $args) {
                    if ($class::{$method.'Native'}(...$args) !== null) {
                        throw new RuntimeException($class.'::'.$method.' answered without a library');
                    }
                    $class::useW412RustFfi(false);
                    $expected = serialize($class::$method(...$args));
                    $class::useW412RustFfi(true);
                    if ($expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $helpers = [];
        foreach (self::HELPERS as $class => $method) {
            $helpers[] = [$class, $method, self::edgeCases($class)];
        }
        $missing = '/tmp/w41-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize($helpers))], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    /**
     * Per-run minimum speedups over 3 rounds, two runs (PHP 8.5.12 NTS, opcache CLI off, Apple M5; PHP latency / Rust
     * latency), common mean/p99 then uncommon mean/p99: directory sync 0.78-0.83x/0.73-0.90x, 0.92-0.94x/1.01x; NFO
     * payloads 0.39-0.41x/0.45-0.53x, 0.12-0.14x/0.10-0.13x; coverage 0.97x/1.03-1.04x, 1.09-1.12x/1.13-1.34x; claim
     * window 0.70-0.78x/0.44-0.69x, 0.82-0.87x/0.86-0.91x; import statuses 0.71x/0.76-0.83x, 0.76-0.79x/0.86-0.88x. No
     * helper clears 1.5x on every measure, so every W412_RUST_FFI_DEFAULT stays false.
     */
    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave41-2-nzb-sync-nfo-import-coverage-candidate-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including normalization, packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && php artisan test --compact tests/Unit/RustNzbSyncNfoImportCoverageCandidateW41ParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/Nzb/DirectorySyncBridge.php', 'app/Services/Nzb/NfoImportService.php',
            'app/Services/Nzb/NzbArticleCoverage.php', 'app/Services/Nzb/NzbCreationCandidateQuery.php', 'app/Services/Nzb/NzbImportService.php',
            'tests/Unit/RustNzbSyncNfoImportCoverageCandidateW41ParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        try {
            foreach (self::workloads() as [$class, $common, $uncommon]) {
                $method = self::HELPERS[$class];
                $name = $class.'::'.$method;
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::{$method.'Php'}(...$args));
                        $this->assertSame(end($expected), serialize($class::{$method.'Native'}(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'inputs' => \count($inputs), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::useW412RustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % \count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < $pairs; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < $calls; $i++) {
                                $indices[] = $random->getInt(0, \count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            foreach ($order as $side) {
                                $class::useW412RustFfi($side === 'native');
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
                            'mean_speedup' => $phpStats['mean_ns'] / max(1.0, $nativeStats['mean_ns']), 'p99_speedup' => $phpStats['p99_ns'] / max(1, $nativeStats['p99_ns'])];
                    }
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $qualifies = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $report['results'][$name]['qualifies'] = $qualifies;
                $class::resetW412RustFfi();
                if (! $qualifies) {
                    $this->assertFalse(\constant($class.'::W412_RUST_FFI_DEFAULT'), $name.' must keep PHP as the default below 1.5x');
                }
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
            fwrite(STDERR, "\nWave 41.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(DirectorySyncBridge::W412_FFI_DOMAIN, DirectorySyncBridge::W412_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return array<class-string, mixed> the earlier-wave `useRustFfi` toggle of every helper class that has one */
    private static function earlierToggles(): array
    {
        $toggles = [];
        foreach (array_keys(self::HELPERS) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->hasProperty('useRustFfi')) {
                $toggles[$class] = $reflection->getProperty('useRustFfi')->getValue();
            }
        }

        return $toggles;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private static function pick(array $result, array $keys): array
    {
        return array_map(static fn (string $key): mixed => $result[$key], $keys);
    }

    /** @param list<mixed> $args */
    private static function describe(array $args): string
    {
        return substr(bin2hex(serialize($args)), 0, 400);
    }

    /** @return list<list<mixed>> */
    private static function edgeCases(string $class): array
    {
        $sync = static fn (mixed $function, mixed $extension, mixed $synced, mixed $elapsed): array => ['function' => $function, 'extension' => $extension,
            'synced' => $synced, 'elapsed_ms' => $elapsed];

        return match ($class) {
            DirectorySyncBridge::class => [[[], 250], [[], PHP_INT_MIN], [['x', null, 5, []], 0],
                [[$sync('fsync', true, true, 10), $sync('fsync', true, false, 300), $sync('fsync', true, null, 250), $sync('', true, true, 5),
                    $sync('fsync', 1, true, -7), $sync(null, true, true, 1), $sync('fsync', false, true, 1), $sync('0', true, 'yes', '9')], 250],
                [[$sync('f', true, true, PHP_INT_MAX), $sync('f', true, true, PHP_INT_MAX), $sync('f', true, true, PHP_INT_MIN)], PHP_INT_MAX],
                [[$sync(['f'], true, 1, 1.5), $sync("\0", true, true, 0)], -1]],
            NfoImportService::class => [[[], 65535], [[], PHP_INT_MIN], [['x', null, 5, []], 0],
                [[['guid' => 'a', 'nfo' => 'abc'], ['guid' => 'b', 'nfo' => "\0x"], ['guid' => 'c', 'nfo' => ''], ['guid' => 'd'], ['guid' => 'e', 'nfo' => 'abcde'],
                    ['guid' => '', 'nfo' => 'xyz'], ['guid' => 'f', 'nfo' => "\xdb\xb0\n"], ['guid' => 7, 'nfo' => 'x'], ['guid' => '0', 'nfo' => 5]], 4],
                [array_map(static fn (string $nfo): array => ['guid' => 'g', 'nfo' => $nfo], self::NFOS), 64],
                [array_map(static fn (string $nfo): array => ['guid' => 'g', 'nfo' => $nfo], self::NFOS), 0],
                [['a' => ['guid' => 'x', 'nfo' => 'y'], 'b' => ['guid' => 'x', 'nfo' => 'z']], PHP_INT_MAX]],
            NzbArticleCoverage::class => [[[], 0], [[], PHP_INT_MIN], [['x', null, 5, []], 0],
                [[['required' => 10, 'covered' => 10], ['required' => 10, 'covered' => 9], ['required' => 10, 'covered' => 0], ['required' => 10, 'covered' => -3],
                    ['required' => 0, 'covered' => 5], ['required' => -2, 'covered' => 1], ['required' => 4, 'covered' => 99], ['required' => '4', 'covered' => 4],
                    ['required' => 4, 'covered' => '4'], ['required' => 4.0], ['covered' => 3]], 1],
                [[['required' => PHP_INT_MAX, 'covered' => 0], ['required' => PHP_INT_MAX, 'covered' => 1], ['required' => PHP_INT_MAX, 'covered' => PHP_INT_MAX],
                    ['required' => PHP_INT_MIN, 'covered' => PHP_INT_MIN]], PHP_INT_MAX],
                [[['required' => 3, 'covered' => PHP_INT_MIN], ['required' => 1, 'covered' => PHP_INT_MAX]], -9]],
            NzbCreationCandidateQuery::class => [[[], 1000, 100], [[], PHP_INT_MIN, PHP_INT_MIN], [['x', null, 5, []], 0, 0],
                [[['id' => 1], ['id' => 2, 'claimed_at' => 950], ['id' => 3, 'claimed_at' => 900], ['id' => 4, 'claimed_at' => 1001], ['id' => 5, 'claimed_at' => '900'],
                    ['id' => 1, 'claimed_at' => 500], ['id' => 0], ['id' => -4, 'claimed_at' => 1], ['id' => 3, 'claimed_at' => null], ['id' => '6', 'claimed_at' => 1.5]], 1000, 100],
                [[['id' => 1, 'claimed_at' => PHP_INT_MIN], ['id' => 2, 'claimed_at' => PHP_INT_MAX], ['id' => PHP_INT_MAX, 'claimed_at' => 0]], PHP_INT_MAX, -5],
                [[['id' => 1, 'claimed_at' => PHP_INT_MIN], ['id' => 1], ['id' => PHP_INT_MIN]], PHP_INT_MIN, PHP_INT_MAX],
                [[['id' => 1, 'claimed_at' => -10], ['id' => 2, 'claimed_at' => PHP_INT_MIN + 1], ['id' => 3, 'claimed_at' => -1]], 5, PHP_INT_MAX],
                [[['id' => 1, 'claimed_at' => PHP_INT_MIN], ['id' => 2, 'claimed_at' => -1]], PHP_INT_MAX, PHP_INT_MAX],
                [[['id' => 9, 'claimed_at' => 0], ['id' => 9], ['id' => 8], ['id' => 8, 'claimed_at' => 0]], 0, 0]],
            NzbImportService::class => [[[]], [['x', null, 5, []]],
                [array_map(static fn (string $status): array => ['status' => $status, 'bytes' => 3], self::STATUSES)],
                [array_map(static fn (NzbImportStatus $status): array => ['status' => $status, 'bytes' => PHP_INT_MAX], NzbImportStatus::cases())],
                [[['status' => 'inserted', 'bytes' => PHP_INT_MIN], ['status' => 'inserted', 'bytes' => '9'], ['status' => NzbImportStatus::Inserted, 'bytes' => 1.5],
                    ['status' => 7, 'bytes' => 9], ['bytes' => 9]]],
                [['a' => ['status' => 'queued'], 'b' => ['status' => NzbImportStatus::Queued]]]],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<mixed> */
    private static function randomArgs(string $class, Randomizer $random): array
    {
        $rows = static function (callable $row, int $max = 12) use ($random): array {
            $out = [];
            for ($i = $random->getInt(0, $max); $i > 0; $i--) {
                $out[] = $random->getInt(0, 9) === 0 ? [null, 'row', 7, 1.5, true][$random->getInt(0, 4)] : $row();
            }

            return $out;
        };
        $odd = static fn (): mixed => ['5', 5.0, null, true, [], 'x'][$random->getInt(0, 5)];
        $value = static fn (): mixed => $random->getInt(0, 7) === 0 ? $odd() : self::int($random);
        $fields = static function (array $keys) use ($random): array {
            $out = [];
            foreach ($keys as $key => $make) {
                if ($random->getInt(0, 9) !== 0) {
                    $out[$key] = $make();
                }
            }

            return $out;
        };
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, \count($values) - 1)];

        return match ($class) {
            DirectorySyncBridge::class => [$rows(static fn (): array => $fields([
                'function' => static fn (): mixed => $pick(['fsync', 'fdatasync', '', '0', null, 7, ['f']]),
                'extension' => static fn (): mixed => $pick([true, true, true, false, 1, null]),
                'synced' => static fn (): mixed => $pick([true, true, false, null, 1, 'true']),
                'elapsed_ms' => static fn (): mixed => $random->getInt(0, 3) === 0 ? $value() : $random->getInt(-5, 600)])),
                $pick([250, self::int($random), $random->getInt(-5, 600)])],
            NfoImportService::class => [$rows(static fn (): array => $fields([
                'guid' => static fn (): mixed => $pick([sha1((string) $random->getInt(0, 99)), '', '0', 12, null, ['x']]),
                'nfo' => static fn (): mixed => $random->getInt(0, 9) === 0 ? $odd()
                    : ($random->getInt(0, 3) === 0 ? $pick(self::NFOS) : $random->getBytes($random->getInt(1, 96)))])),
                $pick([65535, self::int($random), $random->getInt(-2, 100)])],
            NzbArticleCoverage::class => [$rows(static fn (): array => $fields([
                'required' => static fn (): mixed => $random->getInt(0, 5) === 0 ? $value() : $random->getInt(-2, 400),
                'covered' => static fn (): mixed => $random->getInt(0, 5) === 0 ? $value() : $random->getInt(-3, 403)])),
                $random->getInt(0, 2) === 0 ? self::int($random) : $random->getInt(-2, 20)],
            NzbCreationCandidateQuery::class => (static function () use ($random, $rows, $fields, $value, $odd, $pick): array {
                $now = $random->getInt(0, 3) === 0 ? self::int($random) : 1761000000 + $random->getInt(0, 100000);
                $ttl = $pick([3600, 900, self::int($random), $random->getInt(-5, 5000)]);

                return [$rows(static fn (): array => $fields([
                    'id' => static fn (): mixed => $random->getInt(0, 7) === 0 ? $value() : $random->getInt(-1, 15),
                    'claimed_at' => static fn (): mixed => match ($random->getInt(0, 5)) {
                        0 => null, 1 => $odd(), 2 => self::int($random),
                        default => $now - $random->getInt(-50, 7200),
                    }]), 16), $now, $ttl];
            })(),
            NzbImportService::class => [$rows(static fn (): array => $fields([
                'status' => static fn (): mixed => match ($random->getInt(0, 9)) {
                    0 => $odd(), 1, 2, 3 => $pick(NzbImportStatus::cases()), default => $pick(self::STATUSES),
                }, 'bytes' => $value]), 16)],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function int(Randomizer $random): int
    {
        return match ($random->getInt(0, 5)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-3, 12),
            2 => $random->getInt(PHP_INT_MAX - 5, PHP_INT_MAX),
            3 => $random->getInt(PHP_INT_MIN, PHP_INT_MIN + 5),
            4 => $random->getInt(990, 1010),
            default => $random->getInt(0, 1000000),
        };
    }

    /** @return list<array{class-string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $many = static fn (string $class, int $count): array => array_map(static fn (): array => self::randomArgs($class, $random), range(1, $count));
        $syncs = static fn (int $n): array => array_map(static fn (int $i): array => ['function' => $i % 17 === 0 ? null : 'fsync', 'extension' => true,
            'synced' => $i % 11 !== 0, 'elapsed_ms' => $i % 29 === 0 ? 900 : 2 + $i % 40], range(1, $n));
        $nfos = static fn (int $n, int $size): array => array_map(static fn (int $i): array => ['guid' => $i % 23 === 0 ? '' : sha1('release-'.$i),
            'nfo' => $i % 13 === 0 ? '' : str_repeat(\chr(0xB0 + $i % 3).'NFO line '.$i."\r\n", intdiv($size, 16))], range(1, $n));
        $coverage = static fn (int $n): array => array_map(static fn (int $i): array => ['required' => 40 + $i % 300, 'covered' => $i % 5 === 0 ? 20 + $i % 200 : 40 + $i % 300],
            range(1, $n));
        $claims = static fn (int $n): array => array_map(static fn (int $i): array => ['id' => 100000 + ($i % 97 === 0 ? 1 : $i),
            'claimed_at' => $i % 3 === 0 ? null : 1761000000 - ($i * 37) % 7200], range(1, $n));
        $statuses = static fn (int $n): array => array_map(static fn (int $i): array => ['status' => NzbImportStatus::cases()[$i % 4 === 0 ? $i % 8 : 0],
            'bytes' => 20000 + $i * 11], range(1, $n));

        return [
            [DirectorySyncBridge::class, [[$syncs(50), 250], [$syncs(10), 250], [$syncs(200), 250], [$syncs(1), 250]],
                [[$syncs(3000), 250], [array_fill(0, 300, ['function' => ['x'], 'extension' => 1, 'synced' => 'y', 'elapsed_ms' => '5']), PHP_INT_MIN],
                    ...$many(DirectorySyncBridge::class, 12)]],
            [NfoImportService::class, [[$nfos(20, 4096), 65535], [$nfos(5, 8192), 65535], [$nfos(80, 2048), 65535], [$nfos(1, 16384), 65535]],
                [[$nfos(200, 32768), 65535], [array_fill(0, 300, ['guid' => 'g', 'nfo' => str_repeat("\0", 200)]), 100], ...$many(NfoImportService::class, 12)]],
            [NzbArticleCoverage::class, [[$coverage(50), 0], [$coverage(10), 0], [$coverage(200), 5], [$coverage(1), 0]],
                [[$coverage(3000), 10], [array_fill(0, 300, ['required' => '5', 'covered' => 1.5]), PHP_INT_MIN], ...$many(NzbArticleCoverage::class, 12)]],
            [NzbCreationCandidateQuery::class, [[$claims(50), 1761000000, 3600], [$claims(10), 1761000000, 3600], [$claims(200), 1761000000, 3600],
                [$claims(1), 1761000000, 3600]],
                [[$claims(3000), 1761000000, 900], [array_fill(0, 300, ['id' => 7, 'claimed_at' => PHP_INT_MIN]), PHP_INT_MAX, PHP_INT_MAX],
                    ...$many(NzbCreationCandidateQuery::class, 12)]],
            [NzbImportService::class, [[$statuses(50)], [$statuses(10)], [$statuses(200)], [$statuses(1)]],
                [[$statuses(3000)], [array_fill(0, 300, ['status' => str_repeat('inserted', 40), 'bytes' => PHP_INT_MAX])], ...$many(NzbImportService::class, 12)]],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = \count($values);

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
