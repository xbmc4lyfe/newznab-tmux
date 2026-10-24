<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ImportDatabaseNzbs;
use App\Console\Commands\ImportNfos;
use App\Console\Commands\ImportNzbs;
use App\Console\Commands\ImportVerificationManifests;
use App\Console\Commands\YencDoctorCommand;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNfoNzbManifestImportYencCommandsParityTest extends TestCase
{
    private const array CLASSES = [ImportNfos::class, ImportNzbs::class, ImportDatabaseNzbs::class, ImportVerificationManifests::class, YencDoctorCommand::class];

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
        $this->assertSame('/srv/import', ImportNfos::normalizeFolderPathPhp('/srv/import/\\//'));
        $this->assertSame('', ImportNzbs::normalizeFolderPathPhp('/'));
        $this->assertSame(
            ['imported' => 1, 'failed' => 1, 'skipped' => 2, 'warnings' => ['b: bad']],
            ImportNfos::tallyManifestOutcomesPhp([
                ['dirname' => 'a', 'status' => 'imported', 'message' => ''],
                ['dirname' => 'b', 'status' => 'failed', 'message' => 'bad'],
                ['dirname' => 'c', 'status' => 'skipped', 'message' => 'later'],
                ['dirname' => 'd', 'status' => 'unknown', 'message' => ''],
            ]),
        );
        $this->assertSame('Processed 3 NFOs, skipped 2, failed 1.', ImportNfos::formatImportSummaryPhp(3, 2, 1));
        $this->assertTrue(ImportNzbs::isImportableNzbFilenamePhp('Release.NZB.GZ'));
        $this->assertFalse(ImportNzbs::isImportableNzbFilenamePhp('nntmux-upload.json'));
        $this->assertFalse(ImportNzbs::isImportableNzbFilenamePhp('a.nzb', 'a.nzb'));
        $this->assertFalse(ImportNzbs::isImportableNzbFilenamePhp('a.nfo'));
        $this->assertSame(1, ImportNzbs::normalizeSourceIdPhp(null));
        $this->assertSame(1, ImportNzbs::normalizeSourceIdPhp('0'));
        $this->assertSame(7, ImportNzbs::normalizeSourceIdPhp('7'));
        $this->assertSame('Completed: 1 imported, 2 failed, 3 skipped.', ImportNzbs::formatCompletionSummaryPhp(1, 2, 3));
        $this->assertFalse(ImportDatabaseNzbs::isValidCheckpointNamePhp(''));
        $this->assertFalse(ImportDatabaseNzbs::isValidCheckpointNamePhp(str_repeat('x', 121)));
        $this->assertSame(1, ImportDatabaseNzbs::clampLimitPhp(0));
        $this->assertSame(10000, ImportDatabaseNzbs::clampLimitPhp(PHP_INT_MAX));
        $this->assertFalse(ImportDatabaseNzbs::isValidReleaseGuidPhp("abc\n"));
        $this->assertSame('Dry run: Imported 2; already stored 1; resume after release 9.', ImportDatabaseNzbs::formatSummaryLinePhp(true, 3, 2, 1, 0, 0, 9));
        $this->assertSame(['batch' => 1, 'limit' => 0, 'cursor' => 0], ImportVerificationManifests::normalizeBatchOptionsPhp(0, -1, -5));
        $this->assertSame(3, ImportVerificationManifests::computeChunkLimitPhp(10, 5, 2));
        $this->assertSame(10, ImportVerificationManifests::computeChunkLimitPhp(10, 0, 2));
        $this->assertSame(PHP_INT_MAX, ImportVerificationManifests::computeChunkLimitPhp(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN));
        $this->assertSame(
            'imported=1 already=0 missing=0 invalid=0 conflict=0 unreferenced=0 last_candidate_id=4',
            ImportVerificationManifests::formatSummaryLinePhp(['imported' => 1], 4),
        );
        $this->assertSame('unavailable', YencDoctorCommand::resolveActiveDecoderPhp('typo', true, true));
        $this->assertSame('PHP', YencDoctorCommand::resolveActiveDecoderPhp('php', true, true));
        $this->assertSame('RapidYenc', YencDoctorCommand::resolveActiveDecoderPhp('auto', true, true));
        $this->assertSame('PHP', YencDoctorCommand::resolveActiveDecoderPhp('auto', false, true));
        $this->assertSame('unavailable', YencDoctorCommand::resolveActiveDecoderPhp('native', true, false));
        $this->assertSame(['Native self-check', 'failed: '], YencDoctorCommand::buildDiagnosticTableRowsPhp(self::report())[6]);
    }

    public function test_native_helpers_and_public_dispatch_have_exact_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = $class::$php(...$args);
                // Check the raw bridge first: fallback must not conceal a failed native call.
                $this->assertSame($expected, ImportNfos::transformNative($op, self::rawArgs($op, $args)), $class.'::'.$method.' raw native '.json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE));
                $this->assertSame($expected, $class::$native(...$args), $class.'::'.$method.' native');
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $class.'::'.$method.' public dispatch');
                }
            }
        }
    }

    public function test_declined_inputs_fall_back_to_identical_php_results(): void
    {
        $this->nativeFfi();
        $declined = [
            [ImportNfos::class, 'normalizeFolderPath', ["/bad\xff/"], 0],
            [ImportNfos::class, 'tallyManifestOutcomes', [[['dirname' => "\xfe", 'status' => 'failed', 'message' => 'x']]], 1],
            [ImportNzbs::class, 'isImportableNzbFilename', ["\xc3.nzb", 'nntmux-upload.json'], 3],
            [ImportNzbs::class, 'normalizeSourceId', ['12abc'], 4],
            [ImportNzbs::class, 'normalizeSourceId', ['1e3'], 4],
            [ImportNzbs::class, 'normalizeSourceId', [' 0x1A'], 4],
            [ImportNzbs::class, 'normalizeSourceId', ['99999999999999999999'], 4],
            [ImportNzbs::class, 'normalizeSourceId', ["\xff7"], 4],
            [ImportDatabaseNzbs::class, 'isValidCheckpointName', ["\x80"], 6],
            [ImportDatabaseNzbs::class, 'isValidReleaseGuid', ["ab\xff"], 8],
            [YencDoctorCommand::class, 'isValidConfiguredMode', ["auto\xff"], 13],
            [YencDoctorCommand::class, 'buildDiagnosticTableRows', [[...self::report(), 'native_error' => "bad \xff"]], 15],
        ];
        foreach ($declined as [$class, $method, $args, $op]) {
            $this->assertNull(ImportNfos::transformNative($op, $args), $method.' must decline');
            $php = $method.'Php';
            $native = $method.'Native';
            $this->assertSame($class::$php(...$args), $class::$native(...$args), $method.' fallback');
        }
        foreach ([1.5, -0.0, 2.0, [], [3], ['a' => 0]] as $source) {
            $this->assertSame(ImportNzbs::normalizeSourceIdPhp($source), ImportNzbs::normalizeSourceIdNative($source));
        }
        $this->assertNull(ImportNfos::transformNative(4, [1.5]));
        $this->assertNull(ImportNfos::transformNative(0, [new \stdClass]));
        $this->assertNull(ImportNfos::transformNative(999, []));
        $this->assertNull(ImportNfos::transformNative(0, ['a', 'b']));
        $this->assertNull(ImportNfos::transformNative(0, ['x' => 'a']));
        $this->assertNull(ImportNfos::transformNative(1, [[['dirname' => 'a', 'status' => 1, 'message' => '']]]));
        $this->assertNull(ImportNfos::transformNative(15, [[...self::report(), 'thread_safe' => 0]]));
        $partial = self::report();
        unset($partial['rapidyenc_version']);
        $this->assertNull(ImportNfos::transformNative(15, [$partial]));
    }

    public function test_seeded_byte_strings_match_php_cast_trim_and_regex(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(30022026));
        $alphabet = "09-+ \t\n\r\v\f.eExaZ_/\\\0é";
        for ($sample = 0; $sample < 2000; $sample++) {
            $value = '';
            for ($i = $random->getInt(0, 24); $i > 0; $i--) {
                $value .= mb_substr($alphabet, $random->getInt(0, mb_strlen($alphabet) - 1), 1);
            }
            $label = bin2hex($value);
            $this->assertSame(ImportNzbs::normalizeSourceIdPhp($value), ImportNzbs::normalizeSourceIdNative($value), 'source '.$label);
            $this->assertSame(ImportNfos::normalizeFolderPathPhp($value), ImportNfos::transformNative(0, [$value]), 'folder '.$label);
            $this->assertSame(ImportDatabaseNzbs::isValidReleaseGuidPhp($value), ImportNfos::transformNative(8, [$value]), 'guid '.$label);
            $this->assertSame(ImportNzbs::isImportableNzbFilenamePhp($value.'.Nzb'), ImportNfos::transformNative(3, [$value.'.Nzb', 'nntmux-upload.json']), 'nzb '.$label);
            $raw = ImportNfos::transformNative(4, [$value]);
            if ($raw !== null) {
                $this->assertSame(ImportNzbs::normalizeSourceIdPhp($value), $raw, 'raw source '.$label);
            }
        }
    }

    public function test_abi_capacity_malformed_inputs_and_bad_ranges_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon, $op]) {
            $php = $method.'Php';
            foreach ([...$common, ...$uncommon] as $args) {
                $input = json_encode(self::rawArgs($op, $args), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $expected = $class::$php(...$args);
                $buffer = $ffi->new('char[65536]');
                FFI::memset($buffer, 0x5A, 65536);
                // @phpstan-ignore method.notFound
                $length = $ffi->nntmux_w30_nfo_nzb_manifest_import_yenc_commands($op, $input, strlen($input), $buffer, 65536);
                $this->assertGreaterThan(0, $length);
                $wire = FFI::string($buffer, $length);
                $this->assertSame($expected, json_decode($wire, true, 64, JSON_THROW_ON_ERROR));
                $small = $ffi->new('char['.($length + 2).']');
                foreach ([0, 1, $length - 1, $length, $length + 1] as $capacity) {
                    FFI::memset($small, 0x5A, $length + 2);
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_w30_nfo_nzb_manifest_import_yenc_commands($op, $input, strlen($input), $small, $capacity);
                    $this->assertSame($capacity < $length ? -$length : $length, $written, $method.' capacity');
                    $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2));
                }
            }
        }
        $buffer = $ffi->new('char[1024]');
        foreach (['["a"] x', '["a"', '["\ud800"]', '["\x"]', "[\"a\x01\"]", '{"0":"a"}', '[1]', '["a",1]', "[\"\xff\"]", '[01]', str_repeat('[', 40).str_repeat(']', 40)] as $bad) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w30_nfo_nzb_manifest_import_yenc_commands(0, $bad, strlen($bad), $buffer, 1024), bin2hex($bad));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
        $input = '["a/"]';
        foreach ([[null, 0, $buffer, 1024], [null, 1, $buffer, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 1], [$input, 16777217, $buffer, 1024], [$input, strlen($input), $buffer, 16777217], [$buffer, 20, $buffer, 1024], [FFI::addr($buffer[1]), 20, $buffer, 1024], [$buffer, 20, FFI::addr($buffer[1]), 1023]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w30_nfo_nzb_manifest_import_yenc_commands(0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat('Z', 1024), FFI::string($buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Console\Commands\ImportNfos::transformNative(0, ['a/']) !== null) {
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
        $missing = '/tmp/w30-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_real_commands_produce_identical_output_with_rust_forced(): void
    {
        $this->nativeFfi();
        $root = sys_get_temp_dir().'/w30-2-commands-'.getmypid();
        File::ensureDirectoryExists($root);
        try {
            $outputs = [];
            foreach ([false, true] as $enabled) {
                foreach (self::CLASSES as $class) {
                    $class::setUseRustFfi($enabled);
                }
                $run = static function (string $command, array $options): array {
                    $code = Artisan::call($command, $options);

                    return [$code, Artisan::output()];
                };
                $outputs[] = [
                    $run('nntmux:import-nfos', ['--folder' => $root.'/missing///']),
                    $run('nntmux:import-nzbs', ['--folder' => $root.'/missing\\']),
                    $run('nzbs:import-database', ['--root' => [$root], '--name' => str_repeat('n', 121)]),
                    $run('nzbs:import-database', ['--root' => [$root], '--name' => '']),
                    (function () use ($run): array {
                        config(['yenc.decoder' => 'typo', 'yenc.native_library' => '/nonexistent/librapidyenc.so']);

                        return $run('nntmux:yenc-doctor', []);
                    })(),
                    (function () use ($run): array {
                        config(['yenc.decoder' => 'auto', 'yenc.native_library' => '/nonexistent/librapidyenc.so']);

                        return $run('nntmux:yenc-doctor', []);
                    })(),
                ];
            }
            $this->assertSame($outputs[0], $outputs[1]);
            $this->assertSame([1, 'Folder path does not exist: '.$root."/missing\n"], $outputs[0][0]);
            $this->assertStringContainsString('Folder path does not exist: '.$root.'/missing', $outputs[0][1][1]);
            $this->assertSame(2, $outputs[0][2][0]);
            $this->assertSame(2, $outputs[0][3][0]);
            $this->assertSame(1, $outputs[0][4][0]);
            $this->assertStringContainsString('| Active decoder    | unavailable', $outputs[0][4][1]);
            $this->assertStringContainsString('| Active decoder    | PHP', $outputs[0][5][1]);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_real_nfo_import_reports_failed_manifests_identically(): void
    {
        $this->nativeFfi();
        $root = sys_get_temp_dir().'/w30-2-nfos-'.getmypid();
        try {
            $outputs = [];
            foreach ([false, true] as $enabled) {
                File::deleteDirectory($root);
                foreach (['alpha', 'beta'] as $release) {
                    File::ensureDirectoryExists($root.'/'.$release);
                    File::put($root.'/'.$release.'/nntmux-upload.json', '{not json');
                }
                ImportNfos::setUseRustFfi($enabled);
                $code = Artisan::call('nntmux:import-nfos', ['--folder' => $root.'//']);
                $outputs[] = [$code, Artisan::output()];
            }
            $this->assertSame($outputs[0], $outputs[1]);
            $this->assertSame(1, $outputs[0][0]);
            $this->assertMatchesRegularExpression('/^(alpha|beta): .+\n(alpha|beta): .+\nProcessed 0 NFOs, skipped 0, failed 2\.\n$/', $outputs[0][1]);
        } finally {
            File::deleteDirectory($root);
        }
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave30-2-nfo-nzb-manifest-import-yenc-commands-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 30022026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including JSON encoding, FFI, allocation and decoding. Excludes app boot, filesystem and database I/O.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w30-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustNfoNzbManifestImportYencCommandsParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w30-2 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Console/Commands/ImportNfos.php', 'app/Console/Commands/ImportNzbs.php', 'app/Console/Commands/ImportDatabaseNzbs.php', 'app/Console/Commands/ImportVerificationManifests.php', 'app/Console/Commands/YencDoctorCommand.php', 'tests/Unit/RustNfoNzbManifestImportYencCommandsParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(30022026));
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
            fwrite(STDERR, "\nWave 30.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('w30-nfo-nzb-manifest-import-yenc-commands-test', ImportNfos::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicitly built optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /**
     * @param  list<mixed>  $args
     * @return list<mixed>
     */
    private static function rawArgs(int $op, array $args): array
    {
        return match ($op) {
            3 => count($args) === 1 ? [...$args, 'nntmux-upload.json'] : $args,
            12 => [...array_map(static fn (string $key): int => (int) ($args[0][$key] ?? 0), ['imported', 'already', 'missing', 'invalid', 'conflict', 'unreferenced']), $args[1]],
            default => $args,
        };
    }

    /** @return array<string, mixed> */
    private static function report(): array
    {
        return ['php' => '8.5.0', 'os' => 'Darwin', 'sapi' => 'cli', 'thread_safe' => false, 'ffi_extension' => true, 'ffi_enable' => '',
            'decoder_mode' => 'auto', 'configuration_error' => null, 'library_path' => '', 'library_present' => false, 'native_available' => false,
            'native_error' => null, 'rapidyenc_version' => null, 'hardware_crc32' => false, 'active_decoder' => 'PHP'];
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>, int}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0/", 512);
        $ready = [...self::report(), 'thread_safe' => true, 'ffi_enable' => 'preload', 'decoder_mode' => 'native', 'library_path' => '/usr/lib/librapidyenc.so',
            'library_present' => true, 'native_available' => true, 'rapidyenc_version' => '1.1.0', 'hardware_crc32' => true, 'active_decoder' => 'RapidYenc'];
        $outcomes = [];
        for ($i = 0; $i < 40; $i++) {
            $outcomes[] = ['dirname' => 'release-'.$i, 'status' => ['imported', 'failed', 'skipped'][$i % 3], 'message' => $i % 3 === 1 ? "bad\0nfo é" : ''];
        }

        return [
            [ImportNfos::class, 'normalizeFolderPath', [['/srv/import/'], ['/srv/import']], [['\\\\server\\share\\//'], ['/'], [''], ["é/\0/"], [$long]], 0],
            [ImportNfos::class, 'tallyManifestOutcomes', [[[['dirname' => 'a', 'status' => 'imported', 'message' => '']]], [[]]], [[$outcomes], [['k' => ['dirname' => '', 'status' => 'failed', 'message' => '"\\']]], [[['dirname' => 'x', 'status' => 'IMPORTED', 'message' => 'm', 'extra' => 1]]]], 1],
            [ImportNfos::class, 'formatImportSummary', [[3, 1, 0], [0, 0, 0]], [[PHP_INT_MAX, PHP_INT_MIN, -1]], 2],
            [ImportNzbs::class, 'normalizeFolderPath', [['/incoming/']], [['\\'], ["a\\b\\\0\\"]], 0],
            [ImportNzbs::class, 'isImportableNzbFilename', [['Show.S01E01.nzb'], ['Show.nzb.gz'], ['notes.txt']], [['nntmux-upload.json'], ['A.NZB'], ['.nzb'], [''], ["é\0.nzb.GZ"], ['x.nzb', 'x.nzb'], ['manifest.json', 'manifest.json'], ['x.nzb.gz.bak']], 3],
            [ImportNzbs::class, 'normalizeSourceId', [[null], ['2'], [false]], [[''], ['0'], ['00'], [' 12 '], ["\t-3\n"], ['+4'], ['abc'], ['é'], ["\0"], [true], [0], [-9], [PHP_INT_MIN], [(string) PHP_INT_MAX], ['-9223372036854775808']], 4],
            [ImportNzbs::class, 'formatCompletionSummary', [[10, 1, 2]], [[PHP_INT_MIN, 0, PHP_INT_MAX]], 5],
            [ImportDatabaseNzbs::class, 'isValidCheckpointName', [['published-nzbs']], [[''], [str_repeat('x', 120)], [str_repeat('x', 121)], [str_repeat('é', 60)], [str_repeat('é', 61)], ["\0"]], 6],
            [ImportDatabaseNzbs::class, 'clampLimit', [[1000], [1]], [[0], [-1], [10000], [10001], [PHP_INT_MAX], [PHP_INT_MIN]], 7],
            [ImportDatabaseNzbs::class, 'isValidReleaseGuid', [['0123456789abcdef0123456789abcdef01234567'], ['abc-DEF_123']], [[''], [str_repeat('a', 41)], ["abc\n"], ['ab c'], ["ab\0"], ['é'], ['a.b']], 8],
            [ImportDatabaseNzbs::class, 'formatSummaryLine', [[false, 10, 7, 3, 0, 0, 12345], [true, 0, 0, 0, 0, 0, 0]], [[false, 5, 1, 1, 2, 1, -1], [true, PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX]], 9],
            [ImportVerificationManifests::class, 'normalizeBatchOptions', [[1000, 0, 0], [500, 100, 42]], [[0, -1, -1], [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX]], 10],
            [ImportVerificationManifests::class, 'computeChunkLimit', [[1000, 0, 0], [1000, 2500, 2000], [1000, 2500, 1000]], [[1000, 5, 5], [1000, 5, 9], [-5, 3, 0], [1000, -1, 4], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN], [5, PHP_INT_MAX, -1], [PHP_INT_MAX, 1, PHP_INT_MIN]], 11],
            [ImportVerificationManifests::class, 'formatSummaryLine', [[['imported' => 5, 'already' => 2, 'missing' => 1, 'invalid' => 0, 'conflict' => 0, 'unreferenced' => 0], 99]], [[[], 0], [['imported' => PHP_INT_MIN, 'unreferenced' => PHP_INT_MAX], -1]], 12],
            [YencDoctorCommand::class, 'isValidConfiguredMode', [['auto'], ['php'], ['native']], [[''], ['AUTO'], ['auto '], ["php\0"], ['é']], 13],
            [YencDoctorCommand::class, 'resolveActiveDecoder', [['auto', true, true], ['auto', true, false], ['php', true, true]], [['native', false, false], ['native', true, true], ['native', false, true], ['', true, true], ['Auto', true, true], ['php', false, false], ['auto', false, true]], 14],
            [YencDoctorCommand::class, 'buildDiagnosticTableRows', [[self::report()], [$ready]], [[[...self::report(), 'native_error' => "Library \"x\" missing\0é", 'ffi_enable' => '0', 'library_path' => $long]], [[...$ready, 'rapidyenc_version' => '']]], 15],
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
