<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNzbServiceParserManifestWriteContentsW40ParityTest extends TestCase
{
    /** class => helper method; every class exposes the same Wave 40.2 toggle trio. */
    private const array HELPERS = [
        NzbService::class => 'summarizeNzbStorageInventory',
        NzbParserService::class => 'summarizeParsedFileSegments',
        NzbContentsService::class => 'summarizeArchiveMediaBreakdown',
        NzbUploadManifestService::class => 'summarizeManifestHeaderBudget',
        DatabaseNzbStore::class => 'summarizeWriteBatchOutcomes',
    ];

    private const array NAMES = ['a.part01.RAR', 'a.r00', 'b.R100', 'c.7z', 'c.7Z.001', '.7z.002', 'd.001', 'e.r0', 'e.r0000', 'f.vol00+01.PAR2', 'g.nfo', 'g.SFV',
        'h.mkv', 'h.Mp4', 'h.ts', 'h.webm', 'h.ts.', 'noext', '', '.', 'x.par', "x.rar\0", 'x.rar ', "x.mkv\n", "\xff.flac", 'ä.Mkv', 'x.7z.01'];

    private const array STATUSES = ['written', 'skipped_existing', 'failed', 'Written', '', 'skipped', "written\0", 'written '];

    private const int SEED = 40022026;

    private const int RANDOMIZED = 1200;

    protected function tearDown(): void
    {
        foreach (array_keys(self::HELPERS) as $class) {
            $class::resetW402RustFfi();
        }
        parent::tearDown();
    }

    public function test_defaults_and_toggles_are_independent(): void
    {
        $earlier = [NzbService::usesRustFfi(), self::contentsToggle()];
        foreach (array_keys(self::HELPERS) as $class) {
            $this->assertFalse(\constant($class.'::W402_RUST_FFI_DEFAULT'), $class);
            $this->assertFalse($class::usesW402RustFfi(), $class);
        }
        foreach (array_keys(self::HELPERS) as $selected) {
            $selected::useW402RustFfi(true);
            foreach (array_keys(self::HELPERS) as $class) {
                $this->assertSame($selected === $class, $class::usesW402RustFfi(), $selected.' → '.$class);
            }
            $this->assertSame($earlier, [NzbService::usesRustFfi(), self::contentsToggle()], $selected.' leaves the earlier toggles alone');
            $selected::useW402RustFfi(false);
            $this->assertFalse($selected::usesW402RustFfi());
            $selected::useW402RustFfi(true);
            $selected::resetW402RustFfi();
            $this->assertFalse($selected::usesW402RustFfi());
        }
        // The earlier toggles leave the Wave 40.2 toggles alone.
        NzbService::setUseRustFfi(! $earlier[0]);
        NzbContentsService::setUseRustFfi(! $earlier[1]);
        try {
            $this->assertFalse(NzbService::usesW402RustFfi());
            $this->assertFalse(NzbContentsService::usesW402RustFfi());
        } finally {
            NzbService::setUseRustFfi($earlier[0]);
            NzbContentsService::setUseRustFfi($earlier[1]);
        }
    }

    public function test_php_helpers_answer_known_vectors(): void
    {
        $this->assertSame(['entries' => 6, 'valid_gzip' => 2, 'undersized' => 4, 'missing_guid' => 3, 'total_compressed_bytes' => 227],
            NzbService::summarizeNzbStorageInventory([['guid' => 'a', 'bytes' => 100], ['bytes' => 64], ['guid' => 'b', 'bytes' => 63],
                ['guid' => 'c', 'bytes' => -5], 'junk', ['guid' => '', 'bytes' => '70']]));
        $this->assertSame([1, 0], self::pick(NzbService::summarizeNzbStorageInventory([['guid' => 'a', 'bytes' => 0]], -1), ['valid_gzip', 'undersized']));
        $this->assertSame(PHP_INT_MAX, NzbService::summarizeNzbStorageInventory([['guid' => 'a', 'bytes' => PHP_INT_MAX], ['guid' => 'a', 'bytes' => 1]])['total_compressed_bytes']);

        $this->assertSame(['files' => 7, 'complete' => 2, 'gapped' => 4, 'oversized' => 1, 'total_segment_bytes' => 54],
            NzbParserService::summarizeParsedFileSegments([
                ['segments' => [['number' => 2, 'bytes' => 10], ['number' => 1, 'bytes' => 20]]], ['segments' => [['number' => 1, 'bytes' => 5], ['number' => 3, 'bytes' => 7]]],
                ['segments' => []], ['segments' => [['number' => 1, 'bytes' => 1], ['number' => 2, 'bytes' => 1], ['number' => 3, 'bytes' => 1]]],
                ['segments' => [['number' => 1, 'bytes' => -4]]], 'x', ['segments' => [['number' => '1', 'bytes' => 9]]],
            ], 2));
        $this->assertSame([0, 1, 1], self::pick(NzbParserService::summarizeParsedFileSegments([['segments' => [['number' => 1]]], ['segments' => []]], -3),
            ['complete', 'gapped', 'oversized']));
        $this->assertSame([0, 1], self::pick(NzbParserService::summarizeParsedFileSegments([['segments' => [['number' => 1], ['number' => 1]]]]), ['complete', 'gapped']));

        $this->assertSame(['members' => 12, 'archive' => 3, 'par2' => 1, 'text' => 2, 'media' => 2, 'other' => 4],
            NzbContentsService::summarizeArchiveMediaBreakdown(['a.part01.RAR', 'a.r00', 'a.7z.001', 'a.001', 'a.vol00+01.par2', 'a.NFO', 'a.sfv', 'a.mkv', 'a.Mp4',
                'noext', null, 'a.ts.']));

        $this->assertSame(['declared_files' => 3, 'actual_files' => 3, 'file_count_delta' => 0, 'counts_match' => true, 'within_byte_budget' => true,
            'empty_manifest' => false, 'status' => NzbUploadManifestService::STATUS_OK], NzbUploadManifestService::summarizeManifestHeaderBudget(3, 3, 100, 1000));
        $this->assertSame([-2, false, NzbUploadManifestService::STATUS_COUNT_MISMATCH], self::pick(NzbUploadManifestService::summarizeManifestHeaderBudget(5, 3, 100, 1000),
            ['file_count_delta', 'counts_match', 'status']));
        $this->assertSame([true, NzbUploadManifestService::STATUS_EMPTY], self::pick(NzbUploadManifestService::summarizeManifestHeaderBudget(0, 0, 0, 0), ['empty_manifest', 'status']));
        $this->assertSame([false, NzbUploadManifestService::STATUS_OVER_BUDGET], self::pick(NzbUploadManifestService::summarizeManifestHeaderBudget(2, 3, 107374182401),
            ['within_byte_budget', 'status']));

        $this->assertSame(['results' => 7, 'written' => 3, 'skipped_existing' => 1, 'failed' => 3, 'slow_writes' => 2, 'bytes_written' => 150],
            DatabaseNzbStore::summarizeWriteBatchOutcomes([['status' => 'written', 'bytes' => 100, 'latency_ms' => 5], ['status' => 'written', 'bytes' => 50, 'latency_ms' => 1001],
                ['status' => 'skipped_existing', 'bytes' => 999, 'latency_ms' => 2000], ['status' => 'failed'], null, ['status' => 'Written', 'bytes' => -9, 'latency_ms' => 1000],
                ['status' => 'written', 'bytes' => '7', 'latency_ms' => '5000']]));
        $this->assertSame([1, PHP_INT_MAX], self::pick(DatabaseNzbStore::summarizeWriteBatchOutcomes([['status' => 'written', 'bytes' => PHP_INT_MAX, 'latency_ms' => 1],
            ['status' => 'written', 'bytes' => 1]], -5), ['slow_writes', 'bytes_written']));
    }

    public function test_native_matches_php_on_edge_cases_and_randomized_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $class => $method) {
            $cases = [...self::edgeCases($class), ...array_map(static fn (): array => self::randomArgs($class, $random), range(1, self::RANDOMIZED))];
            $this->assertGreaterThanOrEqual(1000, \count($cases));
            foreach ($cases as $args) {
                $expected = self::invoke($class, $method.'Php', $args);
                $native = self::invoke($class, $method.'Native', $args);
                $this->assertNotNull($native, $class.'::'.$method.' declined '.self::describe($args));
                $this->assertSame($expected, $native, $class.'::'.$method.' '.self::describe($args));
                $class::useW402RustFfi(true);
                $viaRust = $class::$method(...$args);
                $class::useW402RustFfi(false);
                $viaPhp = $class::$method(...$args);
                $this->assertSame([$expected, $expected], [$viaRust, $viaPhp], $class.'::'.$method.' dispatch');
            }
        }
    }

    public function test_out_of_contract_inputs_fall_back_to_php(): void
    {
        $this->nativeFfi();
        foreach ([[5, pack('q', 0)], [-1, pack('q4', 3, 3, 100, 1000)], [0, ''], [0, 'short'], [0, pack('q2', 64, 1)], [0, pack('q3', 64, 2, 1)], [0, pack('q', 64).'x'],
            [1, pack('q', 5)], [1, pack('q2', 5, 1)], [1, pack('q3', 5, 1, -1)], [1, pack('q3', 5, 0, 0)], [1, pack('q2', 5, -1)], [1, pack('q4', 5, 1, 1, 1)],
            [2, pack('q2', 1, 0)], [2, pack('q3', 0, 1, 3).'ab'], [2, pack('q3', 0, 1, -2)], [2, pack('q2', 0, 0).'x'], [2, pack('q', 0)],
            [3, pack('q3', 1, 2, 3)], [3, pack('q5', 1, 2, 3, 4, 5)], [4, pack('q4', 1, 1, 7, 0).'written'], [4, pack('q5', 1, 1, 8, 0, 0).'written'],
            [4, pack('q2', 1, 0).'x'], [3, str_repeat("\0", 16777224)]] as [$op, $input]) {
            $this->assertNull(NzbService::evaluateW402Native($op, $input, 7), $op.' '.bin2hex(substr($input, 0, 48)));
        }
        $this->assertNull(NzbService::evaluateW402Native(3, pack('q4', 3, 3, 100, 1000), 0));
        // A wrong expected slot count is a contract violation, not a partial answer.
        $this->assertNull(NzbService::evaluateW402Native(3, pack('q4', 3, 3, 100, 1000), 6));
        $this->assertSame([3, 3, 0, 1, 1, 0, 0], NzbService::evaluateW402Native(3, pack('q4', 3, 3, 100, 1000), 7));

        // Inputs past the native byte limit keep the PHP answer through every public entry point.
        foreach (array_keys(self::HELPERS) as $class) {
            $class::useW402RustFfi(true);
        }
        $entries = array_fill(0, 1048576, ['guid' => 'a', 'bytes' => 100]);
        $this->assertNull(self::invoke(NzbService::class, 'summarizeNzbStorageInventoryNative', [$entries, 64]));
        $this->assertSame(self::invoke(NzbService::class, 'summarizeNzbStorageInventoryPhp', [$entries, 64]), NzbService::summarizeNzbStorageInventory($entries, 64));
        $files = [['segments' => array_fill(0, 1048575, ['number' => 1, 'bytes' => 1])]];
        $this->assertNull(self::invoke(NzbParserService::class, 'summarizeParsedFileSegmentsNative', [$files, PHP_INT_MAX]));
        $this->assertSame(self::invoke(NzbParserService::class, 'summarizeParsedFileSegmentsPhp', [$files, PHP_INT_MAX]),
            NzbParserService::summarizeParsedFileSegments($files, PHP_INT_MAX));
        $names = [str_repeat('a', 16777200).'.rar'];
        $this->assertNull(self::invoke(NzbContentsService::class, 'summarizeArchiveMediaBreakdownNative', [$names]));
        $this->assertSame(self::invoke(NzbContentsService::class, 'summarizeArchiveMediaBreakdownPhp', [$names]), NzbContentsService::summarizeArchiveMediaBreakdown($names));
        $results = [['status' => str_repeat('w', 16777200)]];
        $this->assertNull(self::invoke(DatabaseNzbStore::class, 'summarizeWriteBatchOutcomesNative', [$results, 1000]));
        $this->assertSame(self::invoke(DatabaseNzbStore::class, 'summarizeWriteBatchOutcomesPhp', [$results, 1000]),
            DatabaseNzbStore::summarizeWriteBatchOutcomes($results, 1000));
    }

    public function test_direct_ffi_export_preserves_sentinels_and_capacity_contract(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, pack('q5', 64, 1, 100, 0, 10), [2, 1, 1, 1, 110]],
            [1, pack('q7', 5, 1, 2, 2, 10, 1, 20), [1, 1, 0, 0, 30]],
            [2, pack('q4', 0, 2, 5, -1).'a.rar', [2, 1, 0, 0, 0, 1]],
            [3, pack('q4', 3, 3, 100, 1000), [3, 3, 0, 1, 1, 0, 0]],
            [4, pack('q5', 1000, 1, 7, 100, 5).'written', [1, 1, 0, 0, 0, 100]]];
        foreach ($vectors as [$op, $input, $expected]) {
            $length = \count($expected) * 8;
            $wire = pack('q*', ...$expected);
            $this->assertSame($expected, NzbService::evaluateW402Native($op, $input, \count($expected)));
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w40_nzb_service_parser_manifest_write_contents($op, 0, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2), $op.' capacity '.$capacity);
                if ($written < 0) {
                    // Retry with exactly the reported capacity.
                    $retry = $ffi->new('char['.(-$written).']');
                    // @phpstan-ignore method.notFound
                    $this->assertSame(-$written, $ffi->nntmux_w40_nzb_service_parser_manifest_write_contents($op, 0, $input, \strlen($input), $retry, -$written));
                    $this->assertSame($wire, FFI::string($retry, -$written));
                }
            }
            // Every flag bit is rejected for every operation.
            foreach ([1, 64, 4294967295] as $flags) {
                // @phpstan-ignore method.notFound
                $this->assertSame(-1, $ffi->nntmux_w40_nzb_service_parser_manifest_write_contents($op, $flags, $input, \strlen($input), $small, $length + 2));
            }
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q4', 3, 3, 100, 1000);
        foreach ([[null, 0, $buffer, 1024], [null, 32, $buffer, 1024], [$input, 32, null, 0], [$input, 32, null, 64], [$input, 16777217, $buffer, 1024],
            [$buffer, 32, $buffer, 1024], [FFI::addr($buffer[8]), 32, $buffer, 1024], [$buffer, 32, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 32);
            FFI::memset(FFI::addr($buffer[32]), 0x5A, 992);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w40_nzb_service_parser_manifest_write_contents(3, 0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 32).str_repeat('Z', 992), FFI::string($buffer, 1024));
        }
        foreach ([5, 99, 4294967295] as $op) {
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w40_nzb_service_parser_manifest_write_contents($op, 0, $input, 32, $buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $helpers = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Services\Nzb\NzbService::evaluateW402Native(3, pack('q4', 3, 3, 100, 1000), 7) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($helpers as [$class, $method, $cases]) {
                foreach ($cases as $args) {
                    $class::useW402RustFfi(false);
                    $expected = serialize($class::$method(...$args));
                    $class::useW402RustFfi(true);
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
        $missing = '/tmp/w40-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize($helpers))], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    /**
     * Per-run minimum speedups over 3 rounds, two runs (PHP 8.5.12 NTS, Apple M5; PHP latency / Rust latency), common
     * mean/p99 then uncommon mean/p99: storage inventory 0.75-0.80x/0.70-0.90x, 0.93-0.94x/0.93-0.94x; parsed segments
     * 1.03-1.04x/0.77-1.10x, 0.93-1.07x/0.93-1.14x; archive/media 1.28-1.30x/1.69-1.72x, 1.77-1.82x/2.04-2.08x; manifest
     * header 0.17x/0.18-0.19x, 0.17x/0.11-0.17x; write batch 0.45-0.47x/0.29-0.48x, 0.47-0.49x/0.48-0.50x. No helper
     * clears 1.5x on every measure (archive/media misses on common mean), so every W402_RUST_FFI_DEFAULT stays false.
     */
    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave40-2-nzb-service-parser-manifest-write-contents-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including normalization, packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w40-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w40-2/release/libnntmux_pipeline.dylib php vendor/bin/phpunit tests/Unit/RustNzbServiceParserManifestWriteContentsW40ParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/Nzb/NzbService.php', 'app/Services/Nzb/NzbParserService.php',
            'app/Services/Nzb/NzbContentsService.php', 'app/Services/Nzb/NzbUploadManifestService.php', 'app/Services/Nzb/DatabaseNzbStore.php',
            'tests/Unit/RustNzbServiceParserManifestWriteContentsW40ParityTest.php'] as $file) {
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
                        $expected[] = serialize(self::invoke($class, $method.'Php', $args));
                        $this->assertSame(end($expected), serialize(self::invoke($class, $method.'Native', $args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'inputs' => \count($inputs), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::useW402RustFfi($enabled);
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
                                $class::useW402RustFfi($side === 'native');
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
                $class::resetW402RustFfi();
                if (! $qualifies) {
                    $this->assertFalse(\constant($class.'::W402_RUST_FFI_DEFAULT'), $name.' must keep PHP as the default below 1.5x');
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
            fwrite(STDERR, "\nWave 40.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(NzbService::W402_FFI_DOMAIN, NzbService::W402_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    private static function contentsToggle(): bool
    {
        return (bool) (new ReflectionProperty(NzbContentsService::class, 'useRustFfi'))->getValue();
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function invoke(string $class, string $method, array $args): mixed
    {
        return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
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
        $segments = static fn (array $numbers, int $bytes = 1): array => ['segments' => array_map(static fn (mixed $n): array => ['number' => $n, 'bytes' => $bytes], $numbers)];

        return match ($class) {
            NzbService::class => [[[], 64], [[], PHP_INT_MIN], [['x', null, 5], 0], [[[]], 1],
                [[['guid' => 'a', 'bytes' => 100], ['bytes' => 64], ['guid' => 'b', 'bytes' => 63], ['guid' => 'c', 'bytes' => -5], 'junk', ['guid' => '', 'bytes' => '70']], 64],
                [[['guid' => 'a', 'bytes' => PHP_INT_MAX], ['guid' => 'a', 'bytes' => PHP_INT_MAX]], PHP_INT_MAX],
                [[['guid' => 'a', 'bytes' => PHP_INT_MIN], ['guid' => 0, 'bytes' => 0], ['guid' => ['a'], 'bytes' => 1.5]], PHP_INT_MIN],
                [[['guid' => '0', 'bytes' => 64], ['guid' => "\0", 'bytes' => 65]], 65]],
            NzbParserService::class => [[[], 10000], [[], PHP_INT_MIN], [['x', null, 5, []], 0], [[['segments' => 'x'], ['segments' => [null, 'x']]], 10],
                [[$segments([2, 1]), $segments([1, 3]), $segments([]), $segments([1, 2, 3]), $segments([1], -4), 'x', $segments(['1'], 9)], 2],
                [[$segments([1, 1]), $segments([0]), $segments([-1, 2]), $segments([PHP_INT_MAX]), $segments([PHP_INT_MIN, 1])], 10],
                [[$segments([1], PHP_INT_MAX), $segments([1], PHP_INT_MAX), $segments([1], PHP_INT_MIN)], PHP_INT_MAX],
                [[$segments(range(1, 50)), $segments(array_reverse(range(1, 50))), $segments(range(2, 51))], 49],
                [[['segments' => ['a' => ['number' => 2, 'bytes' => 3], 'b' => ['number' => 1, 'bytes' => 4]]]], 2]],
            NzbContentsService::class => [[[]], [[null, 1, 2.5, true, []]], [self::NAMES], [array_map('strtoupper', self::NAMES)], [['a' => 'x.rar', 'b' => 'y.mkv']]],
            NzbUploadManifestService::class => [[3, 3, 100, 1000], [5, 3, 100, 1000], [0, 0, 0, 0], [3, 3, 1001, 1000], [2, 3, 107374182401, 107374182400],
                [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN], [PHP_INT_MAX, 1, PHP_INT_MAX, -1], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                [-1, -1, -1, -1], [1, 0, 5, 1]],
            DatabaseNzbStore::class => [[[], 1000], [[], PHP_INT_MIN], [[null, 1, 'written', []], 0],
                [[['status' => 'written', 'bytes' => 100, 'latency_ms' => 5], ['status' => 'written', 'bytes' => 50, 'latency_ms' => 1001],
                    ['status' => 'skipped_existing', 'bytes' => 999, 'latency_ms' => 2000], ['status' => 'failed'], null, ['status' => 'Written', 'bytes' => -9, 'latency_ms' => 1000],
                    ['status' => 'written', 'bytes' => '7', 'latency_ms' => '5000']], 1000],
                [[['status' => 'written', 'bytes' => PHP_INT_MAX, 'latency_ms' => PHP_INT_MAX], ['status' => 'written', 'bytes' => PHP_INT_MAX, 'latency_ms' => PHP_INT_MIN]], PHP_INT_MAX],
                [array_map(static fn (string $status): array => ['status' => $status, 'bytes' => 1, 'latency_ms' => 1], self::STATUSES), 0],
                [[['status' => 'written', 'bytes' => PHP_INT_MIN, 'latency_ms' => 0]], -1]],
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
        $value = static fn (): mixed => $random->getInt(0, 7) === 0 ? ['5', 5.0, null, true, []][$random->getInt(0, 4)] : self::int($random);
        $fields = static function (array $keys) use ($random, $value): array {
            $out = [];
            foreach ($keys as $key => $make) {
                if ($random->getInt(0, 9) !== 0) {
                    $out[$key] = $make === null ? $value() : $make();
                }
            }

            return $out;
        };
        $file = static function () use ($random, $rows, $fields): array {
            $count = $random->getInt(0, 8);
            $numbers = range(1, max(1, $count));
            $random->shuffleArray($numbers);
            $segments = [];
            foreach (\array_slice($numbers, 0, $count) as $number) {
                $segments[] = $random->getInt(0, 7) === 0 ? $fields(['number' => null, 'bytes' => null]) : ['number' => $number, 'bytes' => $random->getInt(-5, 800000)];
            }

            return $random->getInt(0, 9) === 0 ? ['segments' => $rows(static fn (): array => $fields(['number' => null, 'bytes' => null]), 4)] : ['segments' => $segments];
        };

        return match ($class) {
            NzbService::class => [$rows(static fn (): array => $fields([
                'guid' => static fn (): mixed => [sha1((string) $random->getInt(0, 99)), '', '0', 12, null, ['x']][$random->getInt(0, 5)], 'bytes' => null])),
                [64, self::int($random), $random->getInt(-5, 2000)][$random->getInt(0, 2)]],
            NzbParserService::class => [$rows($file, 8), [10000, self::int($random), $random->getInt(-2, 9)][$random->getInt(0, 2)]],
            NzbContentsService::class => [$rows(static fn (): mixed => $random->getInt(0, 9) === 0 ? [7, null, 1.5][$random->getInt(0, 2)]
                : self::NAMES[$random->getInt(0, \count(self::NAMES) - 1)], 16)],
            NzbUploadManifestService::class => [self::int($random), self::int($random), self::int($random), [107374182400, self::int($random)][$random->getInt(0, 1)]],
            DatabaseNzbStore::class => [$rows(static fn (): array => $fields([
                'status' => static fn (): mixed => $random->getInt(0, 9) === 0 ? [7, null, []][$random->getInt(0, 2)] : self::STATUSES[$random->getInt(0, \count(self::STATUSES) - 1)],
                'bytes' => null, 'latency_ms' => static fn (): mixed => $random->getInt(0, 4) === 0 ? $value() : $random->getInt(-5, 3000)])),
                [1000, self::int($random), $random->getInt(-2, 3000)][$random->getInt(0, 2)]],
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
        $inventory = static fn (int $n): array => array_map(static fn (int $i): array => ['guid' => $i % 23 === 0 ? '' : sha1('release-'.$i), 'bytes' => $i % 19 === 0 ? 20 : 4000 + $i * 37],
            range(1, $n));
        $files = static function (int $n, int $segments): array {
            $out = [];
            for ($f = 1; $f <= $n; $f++) {
                $numbers = range(1, $segments);
                if ($f % 7 === 0) {
                    $numbers[$segments - 1] = $segments + 1;
                }
                $out[] = ['segments' => array_map(static fn (int $number): array => ['number' => $number, 'bytes' => 739000 + $number % 13], $numbers)];
            }

            return $out;
        };
        $names = static fn (int $n): array => array_map(static fn (int $i): string => match ($i % 10) {
            0 => sprintf('release.part%02d.rar', $i), 1, 2, 3 => sprintf('release.r%02d', $i % 100), 4 => sprintf('release.vol%02d+%02d.par2', $i, $i + 1),
            5 => 'release.nfo', 6 => 'release.sfv', 7 => 'release.mkv', 8 => sprintf('sample-%d.mp4', $i), default => sprintf('release.7z.%03d', $i % 1000),
        }, range(1, $n));
        $writes = static fn (int $n): array => array_map(static fn (int $i): array => ['status' => ['written', 'written', 'written', 'skipped_existing', 'failed'][$i % 5],
            'bytes' => 20000 + $i * 11, 'latency_ms' => $i % 31 === 0 ? 1500 : 40 + $i % 200], range(1, $n));

        return [
            [NzbService::class, [[$inventory(50), 64], [$inventory(10), 64], [$inventory(200), 64], [$inventory(1), 64]],
                [[$inventory(3000), 64], [array_fill(0, 300, ['guid' => ['x'], 'bytes' => '5']), PHP_INT_MIN], ...$many(NzbService::class, 12)]],
            [NzbParserService::class, [[$files(10, 30), 10000], [$files(3, 100), 10000], [$files(25, 10), 10000], [$files(1, 50), 10000]],
                [[$files(40, 400), 10000], [$files(5, 60), 50], ...$many(NzbParserService::class, 12)]],
            [NzbContentsService::class, [[$names(40)], [$names(10)], [$names(120)], [$names(3)]],
                [[$names(3000)], [array_fill(0, 300, str_repeat('x', 200).'.R123')], ...$many(NzbContentsService::class, 12)]],
            [NzbUploadManifestService::class, [[12, 12, 4000000000, 107374182400], [40, 38, 900000000, 107374182400], [1, 1, 50000, 107374182400], [0, 0, 0, 107374182400]],
                [[PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN], [5, 5, 107374182401, 107374182400], ...$many(NzbUploadManifestService::class, 12)]],
            [DatabaseNzbStore::class, [[$writes(50), 1000], [$writes(10), 1000], [$writes(200), 1000], [$writes(1), 1000]],
                [[$writes(3000), 0], [array_fill(0, 300, ['status' => str_repeat('written', 40), 'bytes' => PHP_INT_MAX, 'latency_ms' => PHP_INT_MAX]), PHP_INT_MIN],
                    ...$many(DatabaseNzbStore::class, 12)]],
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
