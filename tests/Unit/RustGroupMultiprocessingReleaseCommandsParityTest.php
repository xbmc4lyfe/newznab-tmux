<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ProcessBackfill;
use App\Console\Commands\ProcessPostProcess;
use App\Console\Commands\ProcessReleasesCommand;
use App\Console\Commands\ProcessSafe;
use App\Console\Commands\UpdatePerGroup;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustGroupMultiprocessingReleaseCommandsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array COMMANDS = [UpdatePerGroup::class, ProcessReleasesCommand::class, ProcessPostProcess::class, ProcessSafe::class, ProcessBackfill::class];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
        parent::tearDown();
    }

    public function test_toggles_default_to_php_and_are_independent(): void
    {
        foreach (self::COMMANDS as $class) {
            $this->assertFalse($class::usesRustFfi(), $class.' must keep the PHP production default.');
        }
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            foreach (self::COMMANDS as $sibling) {
                if ($sibling !== $class) {
                    $this->assertFalse($sibling::usesRustFfi(), $sibling.' must not follow '.$class);
                }
            }
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
            $class::useRustFfi();
            $this->assertTrue($class::usesRustFfi());
            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_php_helpers_match_the_previous_inline_rules(): void
    {
        $this->assertSame([
            'binaries' => 'Updating binaries for group: a.b',
            'backfill' => 'Backfilling group: a.b',
            'releases' => 'Processing releases for group: a.b',
            'additional' => 'Post-processing additional for group: a.b',
            'nfo' => 'Processing NFO files for group: a.b',
            'completed' => 'Completed all processing for group: a.b',
        ], UpdatePerGroup::formatStageProgressMessagesPhp('a.b'));
        $this->assertSame(
            ['error' => 'Type must be either: binaries or backfill', 'lines' => ['', 'binaries => Do Safe Binaries update', 'backfill => Do Safe Backfill update']],
            ProcessSafe::buildHelpLinesPhp(),
        );
        $postProcessHelp = ProcessPostProcess::buildHelpLinesPhp();
        $this->assertSame('Type must be one of: ama, add, ani, mov, nfo, tv, boo, mus, con, gam', $postProcessHelp['error']);
        $this->assertCount(11, $postProcessHelp['lines']);
        $this->assertSame(['', 'tv  => Do TV processing'], [$postProcessHelp['lines'][0], $postProcessHelp['lines'][10]]);

        $scalars = [null, true, false, 0, 1, -1, PHP_INT_MAX, PHP_INT_MIN, 0.0, 1.9, -0.5, NAN, -INF, '', ' ', '0', '5', ' 5', '5 ', '1.', '.5', '.', '1e3', '1e', '0x1A',
            '-1', '+1', "\f1\v", "1\0", 'true', 'TRUE', 'tv', 'mov', 'binaries', 'backfill', '99999999999999999999', '1e1000', '1e-400', [], ['true']];
        foreach ($scalars as $value) {
            $label = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
            $this->assertSame(is_numeric($value), UpdatePerGroup::isNumericGroupIdPhp($value), $label);
            $this->assertSame($value === 'true' || $value === true, ProcessPostProcess::normalizeRenamedOnlyPhp($value), $label);
            // The previous ProcessBackfill::handle() branch.
            $options = isset($value) && is_numeric($value) && $value > 0 ? [0 => (int) $value] : [0 => false];
            $this->assertSame($options, ProcessBackfill::normalizeBackfillOptionsPhp($value), $label);
            if (\is_string($value)) {
                $this->assertSame(\in_array($value, ['ama', 'add', 'ani', 'mov', 'nfo', 'tv', 'boo', 'mus', 'con', 'gam'], true), ProcessPostProcess::isValidTypePhp($value), $label);
                $this->assertSame(\in_array($value, ['backfill', 'binaries'], true), ProcessSafe::isValidSafeTypePhp($value), $label);
            }
        }

        foreach ([null, '', '0', '000', '7', '007', '-1', '+1', ' 1', '1 ', '1.0', '1e3', "1\0", '99999999999999999999', '9223372036854775807', 'é'] as $groupId) {
            foreach ([false, true] as $readyOnly) {
                foreach ([false, true] as $maintenanceOnly) {
                    $this->assertSame(self::previousPlan($groupId, $readyOnly, $maintenanceOnly),
                        ProcessReleasesCommand::resolveExecutionPlanPhp($groupId, $readyOnly, $maintenanceOnly), json_encode([$groupId, $readyOnly, $maintenanceOnly]));
                }
            }
        }
    }

    public function test_native_and_dispatch_parity_including_binary_and_uncommon_inputs(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = self::outcome(static fn (): mixed => $class::$php(...$args));
                $label = $class.'::'.$method.' '.json_encode($args, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $this->assertSame(serialize($expected), serialize(self::outcome(static fn (): mixed => $class::$native(...$args))), $label);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame(serialize($expected), serialize(self::outcome(static fn (): mixed => $class::$method(...$args))), $label);
                }
            }
            $class::setUseRustFfi(false);
        }
    }

    public function test_randomized_numeric_plan_and_option_inputs_match_php(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(31032026));
        $alphabet = "0123456789+-.eE xX\t\n\r\x0b\x0c\0a9é";
        for ($i = 0; $i < 6000; $i++) {
            $length = $random->getInt(0, 24);
            $text = $length === 0 ? '' : $random->getBytesFromString($alphabet, $length);
            $label = json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE);
            $this->assertSame(is_numeric($text), UpdatePerGroup::isNumericGroupIdNative($text), $label);
            $this->assertSame(ProcessBackfill::normalizeBackfillOptionsPhp($text), ProcessBackfill::normalizeBackfillOptionsNative($text), $label);
            $flags = $random->getInt(0, 3);
            $this->assertSame(ProcessReleasesCommand::resolveExecutionPlanPhp($text, ($flags & 1) === 1, ($flags & 2) === 2),
                ProcessReleasesCommand::resolveExecutionPlanNative($text, ($flags & 1) === 1, ($flags & 2) === 2), $label);
        }
        $digits = '0123456789';
        for ($i = 0; $i < 3000; $i++) {
            $number = ($random->getInt(0, 3) === 0 ? ' ' : '').(['', '-', '+'][$random->getInt(0, 2)])
                .($random->getInt(0, 4) === 0 ? '' : $random->getBytesFromString($digits, $random->getInt(1, 24)))
                .($random->getInt(0, 1) === 1 ? '.'.($random->getInt(0, 3) === 0 ? '' : $random->getBytesFromString($digits, $random->getInt(1, 20))) : '')
                .($random->getInt(0, 2) === 0 ? 'e'.$random->getInt(-400, 400) : '')
                .($random->getInt(0, 3) === 0 ? "\n" : '');
            $this->assertSame(is_numeric($number), UpdatePerGroup::isNumericGroupIdNative($number), $number);
            $this->assertSame(ProcessBackfill::normalizeBackfillOptionsPhp($number), ProcessBackfill::normalizeBackfillOptionsNative($number), $number);
            $groupId = ltrim($number, " -+\n");
            $this->assertSame(ProcessReleasesCommand::resolveExecutionPlanPhp($groupId, false, false), ProcessReleasesCommand::resolveExecutionPlanNative($groupId, false, false), $groupId);
        }
        for ($i = 0; $i < 1000; $i++) {
            $length = $random->getInt(0, 9);
            $type = $length === 0 ? '' : $random->getBytesFromString("abcdefgilmnorstuvy \0", $length);
            $this->assertSame(ProcessPostProcess::isValidTypePhp($type), ProcessPostProcess::isValidTypeNative($type), json_encode($type));
            $this->assertSame(ProcessSafe::isValidSafeTypePhp($type), ProcessSafe::isValidSafeTypeNative($type), json_encode($type));
            $this->assertSame(ProcessPostProcess::normalizeRenamedOnlyPhp($type), ProcessPostProcess::normalizeRenamedOnlyNative($type), json_encode($type));
        }
    }

    public function test_commands_report_validation_failures_identically(): void
    {
        $this->nativeFfi();
        foreach ([false, true] as $enabled) {
            foreach (self::COMMANDS as $class) {
                $class::setUseRustFfi($enabled);
            }

            // The exact text of the previous inline error()/line() calls.
            $this->assertSame(1, Artisan::call('multiprocessing:postprocess', ['type' => 'xyz']));
            $this->assertSame("Type must be one of: ama, add, ani, mov, nfo, tv, boo, mus, con, gam\n\n"
                ."ama => Do amazon (books+music+console+games) processing in parallel\nboo => Do books processing\nmus => Do music processing\n"
                ."con => Do console processing\ngam => Do games processing\nadd => Do additional (rar|zip) processing\nani => Do anime processing\n"
                ."mov => Do movie processing\nnfo => Do NFO processing\ntv  => Do TV processing\n", Artisan::output());

            $this->assertSame(1, Artisan::call('multiprocessing:safe', ['type' => 'BINARIES']));
            $this->assertSame("Type must be either: binaries or backfill\n\nbinaries => Do Safe Binaries update\nbackfill => Do Safe Backfill update\n", Artisan::output());

            $this->artisan('group:update-all', ['groupId' => 'abc'])->expectsOutput('Group ID must be numeric.')->assertFailed();

            $this->artisan('releases:process', ['groupId' => '0'])->expectsOutput(ProcessReleasesCommand::ERROR_GROUP_ID)->assertFailed();
            $this->artisan('releases:process', ['groupId' => '5', '--maintenance-only' => true])->expectsOutput(ProcessReleasesCommand::ERROR_MAINTENANCE_WITH_GROUP)->assertFailed();
            $this->artisan('releases:process', ['--ready-only' => true])->expectsOutput(ProcessReleasesCommand::ERROR_READY_ONLY)->assertFailed();
            $this->artisan('releases:process', ['--ready-only' => true, '--maintenance-only' => true])->expectsOutput(ProcessReleasesCommand::ERROR_READY_ONLY)->assertFailed();
        }
    }

    public function test_native_exports_answer_without_php_fallback(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::COMMANDS as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionMethod($class, 'ffi'))->invoke(null), $class.' must load its own Wave 31.3 declarations.');
        }
        $call = static fn (string $symbol, mixed ...$args): mixed => self::callExport($ffi, 'nntmux_cat_gmr_cmd_'.$symbol, ...$args);
        $this->assertSame([1, 0, 1, 0, 1, -1, -1], [$call('is_numeric', 4, " 1e3\n", 5), $call('is_numeric', 4, '1e', 2), $call('is_numeric', 3, '', 0),
            $call('is_numeric', 1, '', 0), $call('is_numeric', 2, '', 0), $call('is_numeric', 5, '', 0), $call('is_numeric', 4, null, 0)]);
        $this->assertSame([1, 0, 1, 0, -1], [$call('is_valid_type', 0, 'gam', 3), $call('is_valid_type', 0, 'backfill', 8), $call('is_valid_type', 1, 'backfill', 8),
            $call('is_valid_type', 1, 'gam', 3), $call('is_valid_type', 2, 'gam', 3)]);
        $this->assertSame([1, 0, 1, 0, -1], [$call('renamed_only', 4, 0, 'true', 4), $call('renamed_only', 4, 0, 'true ', 5), $call('renamed_only', 1, 1, '', 0),
            $call('renamed_only', 2, 1, '', 0), $call('renamed_only', 1, 2, '', 0)]);

        $value = $ffi->new('int64_t[1]');
        $value[0] = 99;
        $this->assertSame(0, $call('execution_plan', 0, '', 0, 0, 0, $value));
        $this->assertSame(99, $value[0], 'Plans without a group id leave the output untouched.');
        $this->assertSame(1, $call('execution_plan', 1, '0042', 4, 0, 0, $value));
        $this->assertSame(42, $value[0]);
        $this->assertSame(3, $call('execution_plan', 1, '99999999999999999999', 20, 1, 0, $value));
        $this->assertSame(PHP_INT_MAX, $value[0]);
        $this->assertSame([4, 5, 6, 2, -1, -1], [$call('execution_plan', 1, '0', 1, 1, 1, $value), $call('execution_plan', 1, '1', 1, 1, 1, $value),
            $call('execution_plan', 0, '', 0, 1, 1, $value), $call('execution_plan', 0, '', 0, 0, 1, $value), $call('execution_plan', 2, '', 0, 0, 0, $value),
            $call('execution_plan', 0, '', 0, 0, 0, null)]);

        $this->assertSame(1, $call('backfill_options', 4, 0, 0.0, ' 1e3 ', 5, $value));
        $this->assertSame(1000, $value[0]);
        $this->assertSame(1, $call('backfill_options', 3, 0, 1.9, '', 0, $value));
        $this->assertSame(1, $value[0]);
        $this->assertSame([0, 0, 0, -1, -1, -1], [$call('backfill_options', 4, 0, 0.0, '1e-400', 6, $value), $call('backfill_options', 3, 0, NAN, '', 0, $value),
            $call('backfill_options', 1, 1, 0.0, '', 0, $value), $call('backfill_options', 3, 0, INF, '', 0, $value), $call('backfill_options', 9, 0, 0.0, '', 0, $value),
            $call('backfill_options', 2, 5, 0.0, '', 0, null)]);
    }

    public function test_direct_abi_short_buffers_do_not_write_and_exact_buffers_have_no_terminator(): void
    {
        $ffi = $this->nativeFfi();
        $long = str_repeat("é\0\xff", 300);
        $lengths = $ffi->new('size_t[6]');
        $help = static fn (array $help): string => implode("\n", [$help['error'], ...$help['lines']]);
        $vectors = [
            ['nntmux_cat_gmr_cmd_stage_messages', [$long, strlen($long), $lengths], implode('', UpdatePerGroup::formatStageProgressMessagesPhp($long))],
            ['nntmux_cat_gmr_cmd_stage_messages', ['', 0, $lengths], implode('', UpdatePerGroup::formatStageProgressMessagesPhp(''))],
            ['nntmux_cat_gmr_cmd_help_lines', [0], $help(ProcessPostProcess::buildHelpLinesPhp())],
            ['nntmux_cat_gmr_cmd_help_lines', [1], $help(ProcessSafe::buildHelpLinesPhp())],
        ];
        foreach ($vectors as [$symbol, $args, $expected]) {
            $length = strlen($expected);
            $buffer = $ffi->new('char['.($length + 2).']');
            foreach ([0, 1, $length - 1, $length, $length + 1] as $capacity) {
                FFI::memset($buffer, 0x5A, $length + 2);
                FFI::memset($lengths, 0, FFI::sizeof($lengths));
                $written = self::callExport($ffi, $symbol, ...[...$args, $buffer, $capacity]);
                if ($capacity < $length) {
                    $this->assertSame(-$length, $written, $symbol);
                    $this->assertSame(str_repeat('Z', $length + 2), FFI::string($buffer, $length + 2), $symbol.' must not write a short buffer');
                    $this->assertSame(0, $lengths[0], $symbol.' must not report lengths for a short buffer');
                } else {
                    $this->assertSame($length, $written, $symbol);
                    $this->assertSame($expected.'ZZ', FFI::string($buffer, $length + 2), $symbol);
                }
            }
            $this->assertSame(-1, self::callExport($ffi, $symbol, ...[...$args, null, 64]), $symbol.' must reject a null output buffer');
        }

        $buffer = $ffi->new('char[64]');
        FFI::memset($buffer, 0x5A, 64);
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_gmr_cmd_help_lines', 2, $buffer, 64));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_gmr_cmd_stage_messages', 'x', 1, null, $buffer, 64));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_gmr_cmd_stage_messages', null, 1, $lengths, $buffer, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($buffer, 64));
    }

    public function test_missing_library_and_disabled_extension_fall_back_in_fresh_processes(): void
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $vectors[] = [$class, $method, $common[0]];
            $vectors[] = [$class, $method, $uncommon[0]];
        }
        $encoded = base64_encode(serialize($vectors));
        $script = 'require "vendor/autoload.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class,$method,$args]) {'
            .'$class::setUseRustFfi(true); $php=$method."Php"; $native=$method."Native";'
            .'if ($class::$php(...$args)!==$class::$native(...$args) || $class::$php(...$args)!==$class::$method(...$args)) throw new RuntimeException($method);'
            .'} echo "fallback verified";';
        foreach ([['-d', 'ffi.enable=0'], []] as $options) {
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => '/tmp/w31-3-missing-library.dylib']);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_cached_load_failure_and_call_time_errors_fall_back_for_every_helper(): void
    {
        $this->nativeFfi();
        $missingSymbols = FFI::cdef('uint32_t nntmux_pipeline_abi_version(void);', self::libraryPath());
        foreach ([false, $missingSymbols] as $handle) {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $class::setFfiForTesting($handle);
                $class::setUseRustFfi(true);
                $php = $method.'Php';
                $native = $method.'Native';
                foreach ([$common[0], $uncommon[0]] as $args) {
                    $expected = serialize(self::outcome(static fn (): mixed => $class::$php(...$args)));
                    $this->assertSame($expected, serialize(self::outcome(static fn (): mixed => $class::$native(...$args))), $class.'::'.$method);
                    $this->assertSame($expected, serialize(self::outcome(static fn (): mixed => $class::$method(...$args))), $class.'::'.$method);
                }
                $class::setFfiForTesting(null);
                $class::setUseRustFfi(false);
            }
        }
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave31-3-group-multiprocessing-release-commands-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 31032026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including type checks, FFI marshalling, scratch allocation and result reconstruction. Excludes app boot, console rendering, NNTP, forking and database I/O.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w31-3/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustGroupMultiprocessingReleaseCommandsParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'profile' => 'release: opt-level=3, lto=true, codegen-units=1',
            'library' => ['path' => realpath(self::libraryPath()), 'sha256' => hash_file('sha256', self::libraryPath())],
            'results' => [], 'failures' => [],
        ];
        $random = new Randomizer(new Mt19937(31032026));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $inputs = array_values(array_filter($inputs, static fn (array $args): bool => self::outcome(static fn (): mixed => $class::$php(...$args))[0] === 'value'));
                    $expected = array_map(static fn (array $args): string => serialize($class::$php(...$args)), $inputs);
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
                            foreach ($random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'] as $side) {
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $raw[$side][] = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
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
                // The production default stays PHP for every helper below the gate.
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
            $summary = ["\nWave 31.3 raw benchmark evidence: {$path}"];
            foreach ($report['results'] as $helper => $cells) {
                foreach ($cells as $kind => $cell) {
                    $summary[] = sprintf('%s/%s mean %.2f-%.2fx p99 %.2f-%.2fx %s', $helper, $kind,
                        $cell['round_range']['mean_speedup']['min'], $cell['round_range']['mean_speedup']['max'],
                        $cell['round_range']['p99_speedup']['min'], $cell['round_range']['p99_speedup']['max'],
                        $cell['qualifies'] ? 'QUALIFIES' : 'below gate');
                }
            }
            fwrite(STDERR, implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    /**
     * The validation and dispatch order of the previous `ProcessReleasesCommand::handle()`.
     *
     * @return array{valid: bool, mode: string, group_id: ?int, error: ?string}
     */
    private static function previousPlan(?string $groupId, bool $readyOnly, bool $maintenanceOnly): array
    {
        $invalid = static fn (string $error): array => ['valid' => false, 'mode' => 'invalid', 'group_id' => null, 'error' => $error];
        if ($groupId !== null && (! ctype_digit((string) $groupId) || (int) $groupId <= 0)) {
            return $invalid('Group ID must be a positive integer.');
        }
        if ($maintenanceOnly && $groupId !== null) {
            return $invalid('The --maintenance-only option cannot be combined with a group ID.');
        }
        if ($readyOnly && ($groupId === null || $maintenanceOnly)) {
            return $invalid('The --ready-only option requires a group ID and cannot be combined with --maintenance-only.');
        }
        if ($readyOnly) {
            return ['valid' => true, 'mode' => 'ready_only', 'group_id' => (int) $groupId, 'error' => null];
        }
        if ($maintenanceOnly) {
            return ['valid' => true, 'mode' => 'maintenance_only', 'group_id' => null, 'error' => null];
        }

        return $groupId !== null
            ? ['valid' => true, 'mode' => 'group', 'group_id' => (int) $groupId, 'error' => null]
            : ['valid' => true, 'mode' => 'all', 'group_id' => null, 'error' => null];
    }

    private function nativeFfi(): FFI
    {
        $cdef = implode("\n", [UpdatePerGroup::FFI_CDEF, ProcessReleasesCommand::FFI_CDEF, ProcessPostProcess::FFI_CDEF, ProcessSafe::FFI_CDEF, ProcessBackfill::FFI_CDEF]);
        $cdef = implode("\n", array_unique(array_map('trim', explode("\n", $cdef))));
        $ffi = RustPipelineLibrary::resolveDomain('w31-3-group-multiprocessing-release-commands-test', $cdef, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library and Wave 31.3 exports must load; native coverage must not silently skip.');

        return $ffi;
    }

    private static function libraryPath(): string
    {
        $explicit = getenv('NNTMUX_PIPELINE_FFI_LIB');

        return $explicit !== false && $explicit !== ''
            ? $explicit
            : base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
    }

    /**
     * The returned value, or the exception class and message, so throwing inputs compare too.
     *
     * @return array{string, mixed}
     */
    private static function outcome(callable $call): array
    {
        try {
            return ['value', $call()];
        } catch (Throwable $error) {
            return ['error', $error::class.': '.$error->getMessage()];
        }
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 512);

        return [
            [UpdatePerGroup::class, 'isNumericGroupId', [['1'], ['42'], ['12345']],
                [['abc'], [''], [' 7'], ['7 '], ['1e3'], ['1.'], ['.5'], ['.'], ['0x1A'], ["1\0"], ["\f1\v"], ['+-1'], ['99999999999999999999'],
                    [null], [true], [false], [7], [1.5], [NAN], [INF], [[]], [$long]]],
            [UpdatePerGroup::class, 'formatStageProgressMessages', [['alt.binaries.teevee'], ['alt.binaries.hdtv.x264'], ['a.b']],
                [[''], ["\0é\xff"], ["a\nb"], [$long]]],
            [ProcessReleasesCommand::class, 'resolveExecutionPlan', [[null, false, false], ['12', false, false], ['12', true, false], [null, false, true]],
                [['0', false, false], ['000', true, false], ['', false, false], ['-1', false, false], [' 1', false, false], ['1.0', false, false], ['007', false, false],
                    ['99999999999999999999', true, false], ['5', false, true], ['5', true, true], [null, true, false], [null, true, true], ["1\0", false, false], [$long, false, false]]],
            [ProcessPostProcess::class, 'isValidType', [['add'], ['mov'], ['tv'], ['nfo']],
                [[''], ['TV'], ['tv '], ["tv\0"], ['xyz'], ['ama,add'], [$long]]],
            [ProcessPostProcess::class, 'normalizeRenamedOnly', [['false'], ['true']],
                [['TRUE'], ['1'], [''], ['true '], [true], [false], [null], [1], [1.0], [[]], [['true']], [$long]]],
            [ProcessPostProcess::class, 'buildHelpLines', [[]], [[]]],
            [ProcessSafe::class, 'isValidSafeType', [['binaries'], ['backfill']],
                [[''], ['Binaries'], ['backfill '], ["binaries\0"], ['tv'], [$long]]],
            [ProcessSafe::class, 'buildHelpLines', [[]], [[]]],
            [ProcessBackfill::class, 'normalizeBackfillOptions', [[null], ['20000'], ['500']],
                [['0'], ['-5'], ['0.5'], ['1.9'], [' 7 '], ['1e3'], ['1e100'], ['1e1000'], ['1e-400'], ['3e-324'], ['99999999999999999999'], ['abc'], ['0x10'], [''],
                    ["5\0"], [true], [false], [7], [0], [PHP_INT_MIN], [1.9], [-1.5], [NAN], [-INF], [INF], [1e20], [[]], [$long]]],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1]];
    }

    private static function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
