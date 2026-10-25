<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ImportDatabaseNzbs;
use App\Console\Commands\ImportNzbs;
use App\Console\Commands\YencDoctorCommand;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\Releases\Fingerprint\FingerprintDispatch;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNntpServiceFingerprintDispatchCommandsParityTest extends TestCase
{
    /** class => [toggle setter, toggle getter, toggle reset, helper method] */
    private const array HELPERS = [
        NNTPService::class => ['useRustFfi', 'usesRustFfi', 'resetRustFfi', 'planVerificationMessageId'],
        FingerprintDispatch::class => ['useRustFfi', 'usesRustFfi', 'resetRustFfi', 'planEnqueue'],
        ImportDatabaseNzbs::class => ['useWave382RustFfi', 'usesWave382RustFfi', 'resetWave382RustFfi', 'summarizeImportBatch'],
        ImportNzbs::class => ['useWave382RustFfi', 'usesWave382RustFfi', 'resetWave382RustFfi', 'summarizeImportFile'],
        YencDoctorCommand::class => ['useWave382RustFfi', 'usesWave382RustFfi', 'resetWave382RustFfi', 'summarizeDecoderReadiness'],
    ];

    private const int SEED = 38022026;

    private const int RANDOMIZED = 1200;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as $class => [, , $reset]) {
            $class::$reset();
        }
        ImportDatabaseNzbs::setUseRustFfi(null);
        ImportNzbs::setUseRustFfi(null);
        YencDoctorCommand::setUseRustFfi(null);
        parent::tearDown();
    }

    public function test_defaults_and_toggles_are_independent(): void
    {
        foreach (self::HELPERS as $class => [$set, $get, $reset]) {
            $this->assertFalse(\constant($class.'::RUST_FFI_DEFAULT'), $class);
            $this->assertFalse($class::$get(), $class);
        }
        foreach (self::HELPERS as $selected => [$set, $get, $reset]) {
            $selected::$set(true);
            foreach (self::HELPERS as $class => [, $classGet]) {
                $this->assertSame($selected === $class, $class::$classGet(), $selected.' → '.$class);
            }
            $selected::$set(false);
            $this->assertFalse($selected::$get());
            $selected::$set(true);
            $selected::$reset();
            $this->assertFalse($selected::$get());
        }
        // The Wave 38.2 toggles leave the earlier command toggles alone, and vice versa.
        ImportNzbs::useWave382RustFfi(true);
        $this->assertFalse(ImportNzbs::usesRustFfi());
        ImportNzbs::setUseRustFfi(true);
        ImportNzbs::resetWave382RustFfi();
        $this->assertTrue(ImportNzbs::usesRustFfi());
        $this->assertFalse(ImportNzbs::usesWave382RustFfi());
    }

    public function test_php_helpers_answer_known_vectors(): void
    {
        $this->assertSame(['message_id' => 'abc@x', 'bracketed' => '<abc@x>', 'length' => 5, 'stripped_brackets' => 4, 'first_invalid' => -1, 'reason' => 'ok',
            'within_budget' => true], NNTPService::planVerificationMessageId('<<abc@x>>', 512));
        $this->assertFalse(NNTPService::planVerificationMessageId('abc@x', 6)['within_budget']);
        $this->assertTrue(NNTPService::planVerificationMessageId('abc@x', 7)['within_budget']);
        $this->assertSame(['message_id' => null, 'bracketed' => null, 'length' => 3, 'stripped_brackets' => 0, 'first_invalid' => 1, 'reason' => 'invalid_budget',
            'within_budget' => false], NNTPService::planVerificationMessageId('a b', 0));
        $this->assertSame('empty', NNTPService::planVerificationMessageId('<>><', 9)['reason']);
        $this->assertSame(['invalid_byte', 1], self::pick(NNTPService::planVerificationMessageId("<a<b\x7f>", 9), ['reason', 'first_invalid']));
        $this->assertSame('ok', NNTPService::planVerificationMessageId("\xff\xc3\xa9", 9)['reason']);
        // Every id the plan accepts is accepted by verificationMessageId(), and every rejected id throws there.
        foreach (['<a@b>', 'x', "a\tb", '<>', "a\x7f", 'a<b', "\xff", '<<x@y.z>>', ' a', "a\x0b"] as $id) {
            $plan = NNTPService::planVerificationMessageId($id, 100);
            try {
                $this->assertSame($plan['message_id'], NNTPService::verificationMessageId($id, 100), bin2hex($id));
            } catch (RuntimeException) {
                $this->assertNull($plan['message_id'], bin2hex($id));
            }
        }

        $this->assertSame(['route' => 'database', 'reason' => 'queued', 'release_id' => 5, 'generation' => 'g1', 'force_applies' => false, 'generation_applies' => false],
            FingerprintDispatch::planEnqueue(5, " g1\n"));
        $this->assertSame(['database', 'forced', true], self::pick(FingerprintDispatch::planEnqueue(5, '', true, null), ['route', 'reason', 'force_applies']));
        $this->assertSame(['job', 'generation', false, true], self::pick(FingerprintDispatch::planEnqueue(5, 'g2', true, 'php'),
            ['route', 'reason', 'force_applies', 'generation_applies']));
        $this->assertSame(['job', 'no_generation'], self::pick(FingerprintDispatch::planEnqueue(5, ' ', false, 'Rust'), ['route', 'reason']));
        $this->assertSame(['rejected', 'invalid_release_id'], self::pick(FingerprintDispatch::planEnqueue(0, 'g', true), ['route', 'reason']));

        $this->assertSame(['processed' => 3, 'accepted' => 3, 'below_cursor' => 0, 'out_of_order' => 0, 'truncated' => false, 'next_cursor' => 20, 'span' => 10],
            ImportDatabaseNzbs::summarizeImportBatch(10, 1000, [11, 12, 20]));
        $this->assertSame(['processed' => 5, 'accepted' => 2, 'below_cursor' => 2, 'out_of_order' => 1, 'truncated' => true, 'next_cursor' => 15, 'span' => 5],
            ImportDatabaseNzbs::summarizeImportBatch(10, 2, [9, 11, 11, 10, 15, 16]));
        $this->assertSame([1, 1, true], self::pick(ImportDatabaseNzbs::summarizeImportBatch(-5, 0, [1, 2]), ['accepted', 'next_cursor', 'truncated']));
        $this->assertSame([0, 0, 0], self::pick(ImportDatabaseNzbs::summarizeImportBatch(PHP_INT_MIN, PHP_INT_MAX, []), ['processed', 'next_cursor', 'span']));
        $this->assertSame([PHP_INT_MAX, PHP_INT_MAX, 1], self::pick(ImportDatabaseNzbs::summarizeImportBatch(0, 5, [PHP_INT_MAX, PHP_INT_MIN]),
            ['next_cursor', 'span', 'below_cursor']));

        $this->assertSame(['kind' => 'nzb_gz', 'importable' => true, 'compressed' => true, 'stem' => 'a', 'size_class' => 'normal'],
            ImportNzbs::summarizeImportFile('a.NZB.gz', 4096));
        $this->assertSame(['kind' => 'manifest', 'importable' => false, 'compressed' => false, 'stem' => NzbUploadManifestService::FILENAME, 'size_class' => 'empty'],
            ImportNzbs::summarizeImportFile(NzbUploadManifestService::FILENAME, 0));
        $this->assertSame(['nzb', '', 'small'], self::pick(ImportNzbs::summarizeImportFile('.nzb', 1023), ['kind', 'stem', 'size_class']));
        $this->assertSame(['other', 'oversized'], self::pick(ImportNzbs::summarizeImportFile('nzb', DatabaseNzbStore::MAX_COMPRESSED_BYTES + 1), ['kind', 'size_class']));
        $this->assertSame('normal', ImportNzbs::summarizeImportFile('x.nzb', DatabaseNzbStore::MAX_COMPRESSED_BYTES)['size_class']);
        foreach (['a.nzb', 'B.NZB.GZ', 'c.nzb.gz.part', NzbUploadManifestService::FILENAME, 'd.Nzb'] as $name) {
            $this->assertSame(ImportNzbs::isImportableNzbFilenamePhp($name), ImportNzbs::summarizeImportFile($name, 1)['importable'], $name);
        }

        $this->assertSame(['mode_valid' => true, 'active_decoder' => 'RapidYenc', 'error' => 'none', 'exit_code' => 0, 'warning' => 'none', 'library_state' => 'loaded'],
            YencDoctorCommand::summarizeDecoderReadiness('auto', '/lib/rapidyenc.so', true, true, true, true));
        $this->assertSame('stale_library', YencDoctorCommand::summarizeDecoderReadiness('auto', '/lib/x', true, true, true, false)['warning']);
        $this->assertSame(['PHP', 'none', 'php_fallback', 'unset'], self::pick(YencDoctorCommand::summarizeDecoderReadiness('auto', '', false, false, false, false),
            ['active_decoder', 'error', 'warning', 'library_state']));
        $this->assertSame(['unavailable', 'native_unusable', 1, 'none', 'missing'], self::pick(YencDoctorCommand::summarizeDecoderReadiness('native', '/x', false, false, false, false),
            ['active_decoder', 'error', 'exit_code', 'warning', 'library_state']));
        $this->assertSame(['PHP', 'unusable'], self::pick(YencDoctorCommand::summarizeDecoderReadiness('php', '/x', false, true, false, false), ['active_decoder', 'library_state']));
        $this->assertSame([false, 'unavailable', 'configuration', 1], self::pick(YencDoctorCommand::summarizeDecoderReadiness('Auto', '/x', true, true, true, true),
            ['mode_valid', 'active_decoder', 'error', 'exit_code']));
    }

    public function test_native_matches_php_on_edge_cases_and_randomized_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $class => [$set, , , $method]) {
            $cases = [...self::edgeCases($class), ...array_map(static fn (): array => self::randomArgs($class, $random), range(1, self::RANDOMIZED))];
            $this->assertGreaterThanOrEqual(1000, \count($cases));
            foreach ($cases as $args) {
                $expected = self::invoke($class, $method.'Php', $args);
                $native = self::invoke($class, $method.'Native', $args);
                $this->assertNotNull($native, $class.'::'.$method.' declined '.self::describe($args));
                $this->assertSame($expected, $native, $class.'::'.$method.' '.self::describe($args));
                $class::$set(true);
                $viaRust = $class::$method(...$args);
                $class::$set(false);
                $viaPhp = $class::$method(...$args);
                $this->assertSame([$expected, $expected], [$viaRust, $viaPhp], $class.'::'.$method.' dispatch');
            }
        }
    }

    public function test_out_of_contract_inputs_fall_back_to_php(): void
    {
        $this->nativeFfi();
        foreach ([[5, 0, pack('q', 0)], [-1, 0, pack('q', 0)], [0, 1, pack('q', 9).'a'], [0, -1, pack('q', 9)], [0, 0, ''], [0, 0, 'short'],
            [1, 4, pack('q3', 1, 0, 0)], [1, 0, pack('q3', 1, 0, 1).'x'], [1, 2, pack('q3', 1, -1, 2).'x'], [2, 0, pack('q2', 0, 1).'x'],
            [2, 1, pack('q2', 0, 1)], [3, 0, pack('q3', 1, 1, 1).'x'], [3, 1, pack('q3', 1, 0, 0)], [4, 32, pack('q', 0)], [4, 0, pack('q', 1)],
            [0, 0, str_repeat('x', 16777217)]] as [$op, $flags, $input]) {
            $this->assertNull(NNTPService::evaluateWave382Native($op, $flags, $input, 6), $op.'/'.$flags.' '.bin2hex(substr($input, 0, 40)));
        }
        $this->assertNull(NNTPService::evaluateWave382Native(2, 0, pack('q2', 0, 1), 0));
        // A wrong expected slot count is a contract violation, not a partial answer.
        $this->assertNull(NNTPService::evaluateWave382Native(2, 0, pack('q2', 0, 1), 6));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], NNTPService::evaluateWave382Native(2, 0, pack('q2', 0, 1), 7));

        // Inputs past the native byte limit keep the PHP answer through every public entry point.
        $huge = str_repeat('a', 16777216);
        foreach (self::HELPERS as $class => [$set]) {
            $class::$set(true);
        }
        $this->assertSame(self::invoke(NNTPService::class, 'planVerificationMessageIdPhp', ["<{$huge}>", 9]), NNTPService::planVerificationMessageId("<{$huge}>", 9));
        $this->assertSame(self::invoke(FingerprintDispatch::class, 'planEnqueuePhp', [1, $huge, false, 'php']), FingerprintDispatch::planEnqueue(1, $huge, false, 'php'));
        $ids = range(1, 2097151);
        $this->assertNull(self::invoke(ImportDatabaseNzbs::class, 'summarizeImportBatchNative', [0, 10, $ids]));
        $this->assertSame(self::invoke(ImportDatabaseNzbs::class, 'summarizeImportBatchPhp', [0, 10, $ids]), ImportDatabaseNzbs::summarizeImportBatch(0, 10, $ids));
        $this->assertSame(self::invoke(ImportNzbs::class, 'summarizeImportFilePhp', [$huge.'.nzb', 1, 'm']), ImportNzbs::summarizeImportFile($huge.'.nzb', 1, 'm'));
        $this->assertSame(self::invoke(YencDoctorCommand::class, 'summarizeDecoderReadinessPhp', [$huge, '', true, true, true, true]),
            YencDoctorCommand::summarizeDecoderReadiness($huge, '', true, true, true, true));
    }

    public function test_direct_ffi_export_preserves_sentinels_and_capacity_contract(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, 0, pack('q', 512).'<<abc@x>>', [2, 5, 0, -1, 4, 1]], [1, 3, pack('q3', 5, 2, 3).'g2php', [1, 2, 0, 2, 0, 1]],
            [2, 0, pack('q6', 10, 1, 9, 11, 15, 16), [2, 1, 1, 0, 1, 11, 1]], [3, 0, pack('q3', 4096, 8, 4).'a.NZB.gzm.js', [1, 1, 1, 1, 2]],
            [4, 23, pack('q', 4).'auto', [1, 1, 0, 0, 2, 2]]];
        foreach ($vectors as [$op, $flags, $input, $expected]) {
            $length = \count($expected) * 8;
            $wire = pack('q*', ...$expected);
            $this->assertSame($expected, NNTPService::evaluateWave382Native($op, $flags, $input, \count($expected)));
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w38_nntp_service_fingerprint_dispatch_commands($op, $flags, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2), $op.' capacity '.$capacity);
                if ($written < 0) {
                    // Retry with exactly the reported capacity.
                    $retry = $ffi->new('char['.(-$written).']');
                    // @phpstan-ignore method.notFound
                    $this->assertSame(-$written, $ffi->nntmux_w38_nntp_service_fingerprint_dispatch_commands($op, $flags, $input, \strlen($input), $retry, -$written));
                    $this->assertSame($wire, FFI::string($retry, -$written));
                }
            }
            // Unknown flag bits are rejected for every operation.
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w38_nntp_service_fingerprint_dispatch_commands($op, $flags | 64, $input, \strlen($input), $small, $length + 2));
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q2', 0, 1);
        foreach ([[null, 0, $buffer, 1024], [null, 16, $buffer, 1024], [$input, 16, null, 0], [$input, 16, null, 64], [$input, 16777217, $buffer, 1024],
            [$buffer, 16, $buffer, 1024], [FFI::addr($buffer[8]), 16, $buffer, 1024], [$buffer, 16, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 16);
            FFI::memset(FFI::addr($buffer[16]), 0x5A, 1008);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w38_nntp_service_fingerprint_dispatch_commands(2, 0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 16).str_repeat('Z', 1008), FFI::string($buffer, 1024));
        }
        foreach ([5, 99, 4294967295] as $op) {
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w38_nntp_service_fingerprint_dispatch_commands($op, 0, $input, 16, $buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $helpers = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Services\NNTP\NNTPService::evaluateWave382Native(2, 0, pack('q2', 0, 1), 7) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($helpers as [$class, $set, $method, $cases]) {
                foreach ($cases as $args) {
                    $class::$set(false);
                    $expected = serialize($class::$method(...$args));
                    $class::$set(true);
                    if ($expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $helpers = [];
        foreach (self::HELPERS as $class => [$set, , , $method]) {
            $helpers[] = [$class, $set, $method, self::edgeCases($class)];
        }
        $missing = '/tmp/w38-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize($helpers))], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave38-2-nntp-service-fingerprint-dispatch-commands-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w38-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w38-2/release/libnntmux_pipeline.dylib php vendor/bin/phpunit tests/Unit/RustNntpServiceFingerprintDispatchCommandsParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/NNTP/NNTPService.php',
            'app/Services/Releases/Fingerprint/FingerprintDispatch.php', 'app/Console/Commands/ImportDatabaseNzbs.php', 'app/Console/Commands/ImportNzbs.php',
            'app/Console/Commands/YencDoctorCommand.php', 'tests/Unit/RustNntpServiceFingerprintDispatchCommandsParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        try {
            foreach (self::workloads() as [$class, $common, $uncommon]) {
                [$set, , $reset, $method] = self::HELPERS[$class];
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
                            $class::$set($enabled);
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
                                $class::$set($side === 'native');
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
                $class::$reset();
                if (! $qualifies) {
                    $this->assertFalse(\constant($class.'::RUST_FFI_DEFAULT'), $name.' must keep PHP as the default below 1.5x');
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
            fwrite(STDERR, "\nWave 38.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(NNTPService::W382_FFI_DOMAIN, NNTPService::W382_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
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
        $long = str_repeat('é<> .', 4000);
        $manifest = NzbUploadManifestService::FILENAME;

        return match ($class) {
            NNTPService::class => [['<<abc@x>>', 512], ['abc@x', 6], ['abc@x', 7], ['a b', 0], ['<>><', 9], ['', 9], ['', PHP_INT_MIN], ["<a<b\x7f>", 9],
                ["\xff\xc3\xa9", 9], [' a', 9], ['a', PHP_INT_MAX], ['a', PHP_INT_MIN], ["\0", 1], ["a\x0b", 5], ["<{$long}>", PHP_INT_MAX], [str_repeat('x', 70000), 70001]],
            FingerprintDispatch::class => [[5, " g1\n", false, 'rust'], [5, '', true, null], [5, 'g2', true, 'rust'], [5, 'g2', true, 'php'], [5, ' ', false, 'Rust'],
                [0, 'g', true, 'rust'], [PHP_INT_MIN, '', false, ''], [PHP_INT_MAX, "\0\t\n\r\x0b ", true, ' rust'], [1, $long, false, $long], [1, "\xff", false, '']],
            ImportDatabaseNzbs::class => [[10, 1000, [11, 12, 20]], [10, 2, [9, 11, 11, 10, 15, 16]], [-5, 0, [1, 2]], [PHP_INT_MIN, PHP_INT_MAX, []],
                [0, 5, [PHP_INT_MAX, PHP_INT_MIN]], [PHP_INT_MAX, 10, [PHP_INT_MAX, 1]], [0, 10001, range(1, 10050)], [0, 1, [0]], [3, 3, [3, 3, 3, 4, 4, 5, 6]]],
            ImportNzbs::class => [['a.NZB.gz', 4096, $manifest], [$manifest, 0, $manifest], ['.nzb', 1023, $manifest], ['nzb', 33554433, $manifest], ['x.nzb', 33554432, $manifest],
                ['', 1, ''], ['', 1, $manifest], ['b.nzb.GZ', PHP_INT_MIN, 'm'], ['c.nzb.gz.part', PHP_INT_MAX, 'm'], ["\xff.Nzb", 1024, "\xff.Nzb"], [$long.'.nzb', 5, $long]],
            YencDoctorCommand::class => [['auto', '/lib/x.so', true, true, true, true], ['auto', '/lib/x', true, true, true, false], ['auto', '', false, false, false, false],
                ['native', '/x', false, false, false, false], ['php', '/x', false, true, false, false], ['Auto', '/x', true, true, true, true], ['', '', true, true, true, true],
                ['native', '', true, false, true, false], [' php', ' ', false, true, true, true], [$long, $long, true, false, true, false]],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<mixed> */
    private static function randomArgs(string $class, Randomizer $random): array
    {
        return match ($class) {
            NNTPService::class => [self::text($random, ['<', '>', '<<', '>>', 'a', 'abc', '@', '.', 'news.example', ' ', "\t", "\0", "\x7f", "\x1f", "\xff", 'é', "\x0b", '-', '$']),
                self::int($random)],
            FingerprintDispatch::class => [self::int($random), self::text($random, [' ', "\t", "\n", "\0", "\x0b", 'g', 'gen-1', 'é', "\xff", 'rust']), $random->getInt(0, 1) === 1,
                [null, 'rust', 'php', 'Rust', ' rust', '', 'job', self::text($random, ['rust', 'r', 'ust', ' '])][$random->getInt(0, 7)]],
            ImportDatabaseNzbs::class => self::batchArgs($random),
            ImportNzbs::class => self::fileArgs($random),
            YencDoctorCommand::class => [['auto', 'php', 'native', 'Auto', 'PHP', ' auto', '', 'native ', 'rapid', self::text($random, ['a', 'auto', 'php', 'native', ' ', "\0"])][$random->getInt(0, 9)],
                ['', '/usr/lib/librapidyenc.so', ' ', "\0"][$random->getInt(0, 3)], $random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1,
                $random->getInt(0, 1) === 1],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return array{int, int, list<int>} */
    private static function batchArgs(Randomizer $random): array
    {
        $cursor = $random->getInt(0, 3) === 0 ? self::int($random) : $random->getInt(-5, 1000);
        $limit = [$random->getInt(-5, 20), 10000, 10001, PHP_INT_MIN, PHP_INT_MAX, self::int($random)][$random->getInt(0, 5)];
        $ids = [];
        $base = max(0, $cursor);
        $next = $base > PHP_INT_MAX - 3 ? $base : $base + $random->getInt(-3, 3);
        for ($i = $random->getInt(0, 40); $i > 0; $i--) {
            $ids[] = match ($random->getInt(0, 5)) {
                0 => self::int($random),
                1 => $next,
                2 => $next - $random->getInt(0, 5),
                default => $next = $next < PHP_INT_MAX - 10 ? $next + $random->getInt(1, 10) : PHP_INT_MAX,
            };
        }

        return [$cursor, $limit, $ids];
    }

    /** @return array{string, int, string} */
    private static function fileArgs(Randomizer $random): array
    {
        $name = self::text($random, ['a', 'Movie', '.nzb', '.NZB', '.gz', '.Gz', 'nzb', '.nzb.gz', '.NzB.gZ', ' ', "\0", 'é', '/', NzbUploadManifestService::FILENAME]);
        $size = [0, 1, 1023, 1024, 33554432, 33554433, -1, self::int($random)][$random->getInt(0, 7)];
        $manifest = [NzbUploadManifestService::FILENAME, '', $name, self::text($random, ['a', '.nzb', 'json'])][$random->getInt(0, 3)];

        return [$name, $size, $manifest];
    }

    /** @param list<string> $pieces */
    private static function text(Randomizer $random, array $pieces): string
    {
        $out = '';
        for ($i = $random->getInt(0, 8); $i > 0; $i--) {
            $out .= $pieces[$random->getInt(0, \count($pieces) - 1)];
        }

        return $out;
    }

    private static function int(Randomizer $random): int
    {
        return match ($random->getInt(0, 5)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-3, 12),
            2 => $random->getInt(PHP_INT_MAX - 5, PHP_INT_MAX),
            3 => $random->getInt(PHP_INT_MIN, PHP_INT_MIN + 5),
            4 => $random->getInt(1020, 1030),
            default => $random->getInt(0, 1000000),
        };
    }

    /** @return list<array{class-string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $many = static fn (string $class, int $count): array => array_map(static fn (): array => self::randomArgs($class, $random), range(1, $count));
        $long = str_repeat('news.example-', 400);
        $manifest = NzbUploadManifestService::FILENAME;

        return [
            [NNTPService::class, [['<part1of10.abc@news.example>', 768000], ['part2of10.abc@news.example', 768000], ['<x@y>', 768000], ['<7f3a9c21$1@news.host>', 2000000]],
                [["<{$long}>", 768000], ['<>', 768000], ["<a b\0>", 1], ['<<<x>>>', PHP_INT_MAX], ...$many(NNTPService::class, 12)]],
            [FingerprintDispatch::class, [[123456, '', false, 'rust'], [123457, 'g-20261025', false, 'php'], [123458, '', true, 'rust'], [123459, '', false, null]],
                [[0, '', false, 'rust'], [PHP_INT_MAX, " \t{$long}\n", true, 'php'], [5, ' ', false, ''], ...$many(FingerprintDispatch::class, 12)]],
            [ImportDatabaseNzbs::class, [[1000, 1000, range(1001, 1100)], [5000, 1000, range(5001, 5050)], [0, 1000, [1, 2, 3, 5, 8, 13]], [99, 1000, []]],
                [[0, 100, range(1, 1000)], [500, 1000, array_merge(range(1, 400), range(501, 900))], [PHP_INT_MAX - 10, 10, range(PHP_INT_MAX - 20, PHP_INT_MAX)],
                    ...$many(ImportDatabaseNzbs::class, 12)]],
            [ImportNzbs::class, [['Some.Release.Name-GRP.nzb', 182000, $manifest], ['Other.Release.1080p.nzb.gz', 64000, $manifest], [$manifest, 512, $manifest],
                ['readme.txt', 1200, $manifest]],
                [[$long.'.NZB.GZ', 33554433, $manifest], ['', 0, ''], ["\xff.nzb", -1, 'x'], ...$many(ImportNzbs::class, 12)]],
            [YencDoctorCommand::class, [['auto', '/usr/lib/librapidyenc.so', true, true, true, true], ['auto', '', true, false, false, false], ['php', '', true, false, false, false],
                ['native', '/usr/lib/librapidyenc.so', true, true, true, true]],
                [['native', '/missing', false, false, false, false], ['Auto', $long, true, true, true, false], ['', '', false, false, false, false],
                    ...$many(YencDoctorCommand::class, 12)]],
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
