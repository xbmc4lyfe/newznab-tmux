<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\RecategorizeOnlyFans;
use App\Console\Commands\TestCategorization;
use App\Models\Category;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\GroupNamePipe;
use App\Services\Categorization\Pipes\PcPipe;
use App\Services\Categorization\Pipes\XxxPipe;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustCategorizationPipesCommandsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array PIPES = [GroupNamePipe::class, XxxPipe::class, PcPipe::class];

    /** @var list<class-string> */
    private const array COMMANDS = [TestCategorization::class, RecategorizeOnlyFans::class];

    protected function tearDown(): void
    {
        foreach (self::PIPES as $class) {
            $class::setUseRustFfi(false);
            $class::setPipeFfiForTesting(null);
        }
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
        AbstractCategorizationPipe::setUseRustFfi(false);
        Mockery::close();
        parent::tearDown();
    }

    public function test_php_helpers_preserve_the_original_rules(): void
    {
        $this->assertSame(['GroupName', 5], [GroupNamePipe::pipeNamePhp(), GroupNamePipe::pipePriorityPhp()]);
        $this->assertSame(['XXX', 10], [XxxPipe::pipeNamePhp(), XxxPipe::pipePriorityPhp()]);
        $this->assertSame(['PC', 30], [PcPipe::pipeNamePhp(), PcPipe::pipePriorityPhp()]);
        foreach ([new GroupNamePipe, new XxxPipe, new PcPipe] as $pipe) {
            $this->assertSame($pipe::pipeNamePhp(), $pipe->getName());
            $this->assertSame($pipe::pipePriorityPhp(), $pipe->getPriority());
        }

        $this->assertSame(GroupNamePipe::RESULT_SUCCESSFUL | GroupNamePipe::RESULT_IN_DOMAIN, GroupNamePipe::evaluateResultPhp(Category::TV_ANIME, 0.8, 'group_name_anime'));
        $this->assertSame(0, GroupNamePipe::evaluateResultPhp(Category::OTHER_MISC, 0.0, 'no_match'));
        $this->assertSame('GroupName -> 5070 (group_name_anime)', GroupNamePipe::resultSummaryPhp(5070, 0.8, 'group_name_anime'));
        $this->assertSame('GroupName -> no match', GroupNamePipe::resultSummaryPhp(Category::OTHER_MISC, 0.9, 'no_match'));

        $this->assertSame(15, XxxPipe::evaluateResultPhp(Category::XXX_ONLYFANS, 0.95, 'onlyfans'));
        $this->assertSame(XxxPipe::RESULT_SUCCESSFUL, XxxPipe::evaluateResultPhp(Category::MOVIE_HD, 0.5, 'x'));

        $this->assertSame(PcPipe::DECISION_PASS_THROUGH, PcPipe::decisionStatePhp(true, true));
        $this->assertSame(PcPipe::DECISION_SKIP, PcPipe::decisionStatePhp(false, true));
        $this->assertSame(PcPipe::DECISION_CATEGORIZE, PcPipe::decisionStatePhp(false, false));
        $this->assertSame(PcPipe::RESULT_SUCCESSFUL | PcPipe::RESULT_IN_DOMAIN, PcPipe::evaluateResultPhp(Category::PC_GAMES, 0.9, 'pc_games'));

        $this->assertSame(['PC', 30, PcPipe::class], TestCategorization::categorizerRowPhp(['name' => 'PC', 'priority' => 30, 'class' => PcPipe::class]));
        $sample = 'The.Mandalorian.S03E08.2160p.WEB-DL.DDP5.1.Atmos.H.265-FLUX';
        $this->assertSame(['The.Mandalorian.S03E08.2160p.WEB-DL.DDP5.1.Atmos.H...', 'TV', 'TV', '✓'], TestCategorization::sampleRowPhp($sample, 'TV', 'TV', true));
        $this->assertSame([str_repeat('a', 50), 'A', 'B', '✗'], TestCategorization::sampleRowPhp(str_repeat('a', 50), 'A', 'B', false));
        $this->assertSame(['<fg=green>MATCH</>', '<fg=red>MISMATCH</>'], [TestCategorization::comparisonStatusPhp(true), TestCategorization::comparisonStatusPhp(false)]);

        $this->assertTrue(RecategorizeOnlyFans::isEligiblePhp(Category::XXX_ONLYFANS, 'Of.Creator'));
        $this->assertFalse(RecategorizeOnlyFans::isEligiblePhp(Category::XXX_ONLYFANS, 'OnlyFans.Creator'));
        $this->assertFalse(RecategorizeOnlyFans::isEligiblePhp(Category::XXX_OTHER, 'OF.Creator'));
        $this->assertSame('#7 OF.x: XXX > HD Clips (6041) -> XXX > OnlyFans (6047)', RecategorizeOnlyFans::previewLinePhp(7, 'OF.x', 'HD Clips', 6041, 'OnlyFans', 6047));
        $this->assertSame('#7 OF.x: XXX >  (6041) -> XXX >  (6047)', RecategorizeOnlyFans::previewLinePhp(7, 'OF.x', null, 6041, null, 6047));
        $this->assertSame(
            ['Inspected: 4; Eligible: 3; Skipped: 1; Failed: 0.', 'Would update: 3. No releases were changed.'],
            RecategorizeOnlyFans::summaryLinesPhp(false, 4, 3, 0, 0, 0, 1, 0)
        );
        $this->assertSame(
            ['Inspected: 4; Eligible: 3; Skipped: 1; Failed: 2.', 'Database categories updated: 3; Search refreshes confirmed: 1; Unconfirmed: 1.'],
            RecategorizeOnlyFans::summaryLinesPhp(true, 4, 3, 3, 1, 1, 1, 2)
        );
    }

    public function test_toggles_default_to_php_and_are_independent(): void
    {
        $all = [...self::PIPES, ...self::COMMANDS];
        foreach ($all as $class) {
            $this->assertFalse($class::usesRustFfi(), $class.' must keep the PHP production default.');
        }
        foreach ($all as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $this->assertFalse(AbstractCategorizationPipe::$useRustFfi, 'The parent pipe toggle is separate storage.');
            $this->assertFalse(BookPipe::usesRustFfi());
            foreach ($all as $sibling) {
                if ($sibling !== $class) {
                    $this->assertFalse($sibling::usesRustFfi(), $sibling.' must not follow '.$class);
                }
            }
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
        }
        AbstractCategorizationPipe::setUseRustFfi(true);
        foreach (self::PIPES as $class) {
            $this->assertFalse($class::usesRustFfi());
        }
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_native_and_dispatch_parity_including_binary_and_uncommon_inputs(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = $class::$php(...$args);
                $this->assertSame($expected, $class::$native(...$args), $class.'::'.$method);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $class.'::'.$method);
                }
            }
            $class::setUseRustFfi(false);
        }
    }

    public function test_native_exports_answer_without_php_fallback(): void
    {
        $ffi = $this->nativeFfi();
        $call = static fn (string $symbol, mixed ...$args): mixed => self::callExport($ffi, 'nntmux_cat_pipe_cmd_'.$symbol, ...$args);
        $this->assertSame([5, 10, 30, -1], [$call('pipe_priority', 0), $call('pipe_priority', 1), $call('pipe_priority', 2), $call('pipe_priority', 3)]);
        $this->assertSame(15, $call('evaluate_result', 1, 6047, 1.0, 'x', 1));
        $this->assertSame(-1, $call('evaluate_result', 7, 6047, 1.0, 'x', 1));
        $this->assertSame(-1, $call('evaluate_result', 1, 6047, 1.0, null, 0));
        $this->assertSame([0, 1, 2, -1], [$call('decision_state', 1, 0), $call('decision_state', 0, 1), $call('decision_state', 0, 0), $call('decision_state', 0, 2)]);
        $this->assertSame([1, 0, -1], [$call('onlyfans_eligible', 6047, 'oF.', 3), $call('onlyfans_eligible', 6047, "o\0.", 3), $call('onlyfans_eligible', 6047, null, 0)]);
    }

    public function test_direct_abi_short_buffers_do_not_write_and_exact_buffers_have_no_terminator(): void
    {
        $ffi = $this->nativeFfi();
        $long = str_repeat("é\0\xff", 300);
        $vectors = [
            ['nntmux_cat_pipe_cmd_pipe_name', [0], 'GroupName'],
            ['nntmux_cat_pipe_cmd_pipe_name', [2], 'PC'],
            ['nntmux_cat_pipe_cmd_group_name_summary', [5070, 0.8, $long, strlen($long)], 'GroupName -> 5070 ('.$long.')'],
            ['nntmux_cat_pipe_cmd_stats_row', ['n', 1, $long, strlen($long)], 'n'.$long],
            ['nntmux_cat_pipe_cmd_stats_row', ['', 0, 'c', 1], 'c'],
            ['nntmux_cat_pipe_cmd_sample_row', [$long, strlen($long), 1], substr($long, 0, 50).'...✓'],
            ['nntmux_cat_pipe_cmd_comparison_status', [0], '<fg=red>MISMATCH</>'],
            ['nntmux_cat_pipe_cmd_onlyfans_preview', [PHP_INT_MIN, $long, strlen($long), '', 0, -1, 'OnlyFans', 8, 6047], '#'.PHP_INT_MIN.' '.$long.': XXX >  (-1) -> XXX > OnlyFans (6047)'],
            ['nntmux_cat_pipe_cmd_onlyfans_summary', [0, 1, 2, 3, 4, 5, 6, 7], "Inspected: 1; Eligible: 2; Skipped: 6; Failed: 7.\nWould update: 2. No releases were changed."],
        ];
        foreach ($vectors as [$symbol, $args, $expected]) {
            $length = strlen($expected);
            $buffer = $ffi->new('char['.($length + 2).']');
            foreach ([0, 1, $length - 1, $length, $length + 1] as $capacity) {
                FFI::memset($buffer, 0x5A, $length + 2);
                $written = self::callExport($ffi, $symbol, ...[...$args, $buffer, $capacity]);
                if ($capacity < $length) {
                    $this->assertSame($length === 1 ? -2 : -$length, $written, $symbol);
                    $this->assertSame(str_repeat('Z', $length + 2), FFI::string($buffer, $length + 2), $symbol.' must not write a short buffer');
                } else {
                    $this->assertSame($length, $written, $symbol);
                    $this->assertSame($expected.'ZZ', FFI::string($buffer, $length + 2), $symbol);
                }
            }
            $this->assertSame(-1, self::callExport($ffi, $symbol, ...[...$args, null, 64]), $symbol.' must reject a null output buffer');
        }

        $buffer = $ffi->new('char[64]');
        FFI::memset($buffer, 0x5A, 64);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_cat_pipe_cmd_pipe_name(3, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_cat_pipe_cmd_sample_row('x', 1, 2, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_cat_pipe_cmd_stats_row(null, 1, 'c', 1, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_cat_pipe_cmd_onlyfans_preview(1, 'x', 1, null, 0, 1, 'y', 1, 1, $buffer, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_cat_pipe_cmd_onlyfans_summary(2, 0, 0, 0, 0, 0, 0, 0, $buffer, 64));
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
        $script = 'require "tests/bootstrap.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class,$method,$args]) {'
            .'$class::setUseRustFfi(true); $php=$method."Php"; $native=$method."Native";'
            .'if ($class::$php(...$args)!==$class::$native(...$args) || $class::$php(...$args)!==$class::$method(...$args)) throw new RuntimeException($method);'
            .'} echo "fallback verified";';
        foreach ([['-d', 'ffi.enable=0'], []] as $options) {
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => '/tmp/w29-3-missing-library.dylib']);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_cached_load_failure_and_call_time_errors_fall_back_for_every_helper(): void
    {
        $this->nativeFfi();
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library);
        $missingSymbols = FFI::cdef('uint32_t nntmux_pipeline_abi_version(void);', $library);
        foreach ([false, $missingSymbols] as $handle) {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                self::setHandle($class, $handle);
                $class::setUseRustFfi(true);
                $php = $method.'Php';
                $native = $method.'Native';
                foreach ([$common[0], $uncommon[0]] as $args) {
                    $this->assertSame($class::$php(...$args), $class::$native(...$args), $class.'::'.$method);
                    $this->assertSame($class::$php(...$args), $class::$method(...$args), $class.'::'.$method);
                }
                self::setHandle($class, null);
                $class::setUseRustFfi(false);
            }
        }
    }

    public function test_real_pipes_keep_names_and_priorities_in_the_default_pipeline(): void
    {
        $this->nativeFfi();
        foreach ([false, true] as $enabled) {
            foreach (self::PIPES as $class) {
                $class::setUseRustFfi($enabled);
            }
            $stats = collect((new CategorizationService)->getCategorizerStats())->keyBy('class');
            $this->assertSame(['name' => 'GroupName', 'priority' => 5, 'class' => GroupNamePipe::class], $stats[GroupNamePipe::class]);
            $this->assertSame(['name' => 'XXX', 'priority' => 10, 'class' => XxxPipe::class], $stats[XxxPipe::class]);
            $this->assertSame(['name' => 'PC', 'priority' => 30, 'class' => PcPipe::class], $stats[PcPipe::class]);
            foreach (self::PIPES as $class) {
                $this->assertSame($class::pipePriorityNative(), (new $class)->getPriority());
            }
        }
    }

    public function test_real_test_categorization_command_renders_rows_and_status(): void
    {
        $this->nativeFfi();
        $outputs = [];
        foreach ([false, true] as $enabled) {
            TestCategorization::setUseRustFfi($enabled);
            $service = Mockery::mock(CategorizationService::class);
            $service->shouldReceive('getCategorizerStats')->andReturn([
                ['name' => 'GroupName', 'priority' => 5, 'class' => GroupNamePipe::class],
                ['name' => 'PC', 'priority' => 30, 'class' => PcPipe::class],
            ]);
            $service->shouldReceive('compare')->andReturnUsing(static fn (int|string $group, string $name): array => [
                'pipeline' => ['category_id' => 5040, 'category_name' => 'TV > HD'],
                'legacy' => ['category_id' => strlen($name) % 2 === 0 ? 5040 : 5030, 'category_name' => strlen($name) % 2 === 0 ? 'TV > HD' : 'TV > SD'],
                'match' => strlen($name) % 2 === 0,
            ]);
            $command = new TestCategorization;
            $command->setLaravel($this->app);
            $buffer = new BufferedOutput;
            $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
            foreach ([['listCategorizers', [$service]], ['compareResult', [$service, 'Even.Release']], ['compareResult', [$service, 'Odd.Release']], ['testSampleReleases', [$service]]] as [$method, $args]) {
                (new ReflectionMethod($command, $method))->invoke($command, ...$args);
            }
            $outputs[] = $buffer->fetch();
        }
        $this->assertSame($outputs[0], $outputs[1]);
        $this->assertStringContainsString('| GroupName | 5        | '.GroupNamePipe::class, $outputs[0]);
        $this->assertStringContainsString('Result: MATCH', $outputs[0]);
        $this->assertStringContainsString('Result: MISMATCH', $outputs[0]);
        $this->assertStringContainsString('| The.Mandalorian.S03E08.2160p.WEB-DL.DDP5.1.Atmos.H... |', $outputs[0]);
        $this->assertStringContainsString('| Inception.2010.1080p.BluRay.x264-SPARKS ', $outputs[0]);
        $this->assertStringContainsString('| ✓     |', $outputs[0]);
        $this->assertStringContainsString('| ✗     |', $outputs[0]);
    }

    public function test_real_onlyfans_preview_lists_and_summarizes_identically(): void
    {
        $this->nativeFfi();
        Schema::create('categories', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('root_categories_id');
            $table->string('title');
        });
        DB::table('categories')->insert([
            ['id' => Category::XXX_CLIPHD, 'root_categories_id' => Category::XXX_ROOT, 'title' => 'HD Clips'],
            ['id' => Category::XXX_OTHER, 'root_categories_id' => Category::XXX_ROOT, 'title' => 'Other'],
            ['id' => Category::XXX_ONLYFANS, 'root_categories_id' => Category::XXX_ROOT, 'title' => 'OnlyFans'],
        ]);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('searchname');
            $table->integer('categories_id');
            $table->integer('groups_id')->default(0);
            $table->string('fromname')->default('poster@example.com');
        });
        $ids = [
            (int) DB::table('releases')->insertGetId(['searchname' => 'OF.Sadie.Summers.NEW.TRADWIFE.BG.1080p', 'categories_id' => Category::XXX_CLIPHD]),
            (int) DB::table('releases')->insertGetId(['searchname' => 'of.Creator_Pack_mp4', 'categories_id' => Category::XXX_OTHER]),
            (int) DB::table('releases')->insertGetId(['searchname' => 'OF.Generic.Title', 'categories_id' => Category::XXX_OTHER]),
        ];
        $service = Mockery::mock(CategorizationService::class);
        $service->shouldReceive('determineCategory')->andReturnUsing(static fn (int|string $group, string $name): array => [
            'categories_id' => str_contains($name, 'Generic') ? Category::XXX_OTHER : Category::XXX_ONLYFANS,
        ]);
        $this->app->instance(CategorizationService::class, $service);
        foreach ([false, true] as $enabled) {
            RecategorizeOnlyFans::setUseRustFfi($enabled);
            $this->artisan('releases:recategorize-onlyfans')
                ->expectsOutput(sprintf('#%d OF.Sadie.Summers.NEW.TRADWIFE.BG.1080p: XXX > HD Clips (%d) -> XXX > OnlyFans (6047)', $ids[0], Category::XXX_CLIPHD))
                ->expectsOutput(sprintf('#%d of.Creator_Pack_mp4: XXX > Other (%d) -> XXX > OnlyFans (6047)', $ids[1], Category::XXX_OTHER))
                ->expectsOutput('Inspected: 3; Eligible: 2; Skipped: 1; Failed: 0.')
                ->expectsOutput('Would update: 2. No releases were changed.')
                ->assertSuccessful();
            $this->assertSame([Category::XXX_CLIPHD, Category::XXX_OTHER, Category::XXX_OTHER], DB::table('releases')->orderBy('id')->pluck('categories_id')->map(static fn ($id): int => (int) $id)->all());
        }
    }

    #[Group('benchmark')]
    public function test_paired_randomized_benchmarks(): void
    {
        if (! in_array('--group=benchmark', $_SERVER['argv'] ?? [], true)) {
            $this->markTestSkipped('Opt in with --group=benchmark.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave29-3-categorization-pipes-commands-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => 29032026, 'rounds' => 3, 'pairs_per_round' => 41, 'calls_per_side_per_pair' => 200,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'uncertainty' => 'Three-round min/max; descriptive, not a confidence interval. 8200 calls/side/round, 24600/cell.',
                'scope' => 'Public helper dispatch including type checks, FFI marshalling, scratch allocation and result reconstruction. Excludes app boot, console rendering and database I/O. No production throughput claim.',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w29-3/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustCategorizationPipesCommandsParityTest.php --group=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'resource_limits' => self::command(['/bin/sh', '-c', 'ulimit -a']),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'rustc' => self::command(['rustc', '--version']), 'cargo' => self::command(['cargo', '--version']),
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w29-3 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
            'profile' => 'opt-level=3, lto=true, codegen-units=1',
            'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/categorize.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'app/Support/RustPipelineLibrary.php',
            'app/Services/Categorization/Pipes/GroupNamePipe.php', 'app/Services/Categorization/Pipes/XxxPipe.php', 'app/Services/Categorization/Pipes/PcPipe.php',
            'app/Console/Commands/TestCategorization.php', 'app/Console/Commands/RecategorizeOnlyFans.php', 'tests/Unit/RustCategorizationPipesCommandsParityTest.php'] as $file) {
            $report['source_sha256'][$file] = is_file(base_path($file)) ? hash_file('sha256', base_path($file)) : null;
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $this->assertNotFalse($library, 'Benchmark requires explicit library identity.');
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937(29032026));
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
                    $aggregate = ['php' => [], 'native' => []];
                    for ($round = 0; $round < 3; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        $pairs = [];
                        for ($pair = 0; $pair < 41; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < 200; $i++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            $pairs[] = ['order' => $order, 'input_indices' => $indices];
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
                        $cell['rounds'][] = ['pairs' => $pairs, 'raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                        foreach (['php', 'native'] as $side) {
                            array_push($aggregate[$side], ...$raw[$side]);
                        }
                    }
                    $phpStats = self::statistics($aggregate['php']);
                    $nativeStats = self::statistics($aggregate['native']);
                    $cell['aggregate'] = ['php' => $phpStats, 'native' => $nativeStats,
                        'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $report['results'][$name]['qualifies'] = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                // The production default stays PHP for every helper below the gate.
                $class::setUseRustFfi(false);
                $this->assertFalse($class::usesRustFfi());
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            $report['partial'] = ['helper' => $name ?? null, 'kind' => $kind ?? null, 'round' => $round ?? null, 'pair' => $pair ?? null];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            fwrite(STDERR, "\nWave 29.3 raw benchmark evidence: {$path}\n");
            $summary = [];
            foreach ($report['results'] as $helper => $cells) {
                foreach (['common', 'uncommon'] as $kind) {
                    if (isset($cells[$kind])) {
                        $summary[] = sprintf('%s/%s mean %.2f-%.2fx p99 %.2f-%.2fx %s', $helper, $kind,
                            $cells[$kind]['round_range']['mean_speedup']['min'], $cells[$kind]['round_range']['mean_speedup']['max'],
                            $cells[$kind]['round_range']['p99_speedup']['min'], $cells[$kind]['round_range']['p99_speedup']['max'],
                            $cells[$kind]['qualifies'] ? 'QUALIFIES' : 'below gate');
                    }
                }
            }
            fwrite(STDERR, implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $cdef = implode("\n", [GroupNamePipe::PIPE_FFI_CDEF, PcPipe::PIPE_FFI_CDEF, TestCategorization::FFI_CDEF, RecategorizeOnlyFans::FFI_CDEF]);
        $cdef = implode("\n", array_unique(array_map('trim', explode("\n", $cdef))));
        $ffi = RustPipelineLibrary::resolveDomain('w29-categorization-pipes-commands-test', $cdef, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The explicit optimized library and Wave 29.3 exports must load; native coverage must not silently skip.');

        return $ffi;
    }

    private static function setHandle(string $class, FFI|false|null $handle): void
    {
        if (in_array($class, self::PIPES, true)) {
            $class::setPipeFfiForTesting($handle);
        } else {
            $class::setFfiForTesting($handle);
        }
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $long = str_repeat("é\0\xff", 2048);
        $results = [[Category::TV_ANIME, 0.8, 'group_name_anime'], [Category::XXX_ONLYFANS, 0.95, 'onlyfans'], [Category::PC_GAMES, 0.9, 'pc_games'], [Category::OTHER_MISC, 0.0, 'no_match']];
        $oddResults = [[Category::OTHER_MISC, 1.0, 'no_match'], [6999, NAN, ''], [4999, -0.0, 'group_name_'], [PHP_INT_MAX, INF, "group_name_\0"], [PHP_INT_MIN, -INF, $long],
            [6000, 0.9499999, 'Group_name_x'], [5999, 0.95, "no_match\0"], [7000, 1e-300, 'x'], [4000, PHP_FLOAT_EPSILON, 'x'], [3999, 0.5, 'x']];
        $samples = ['Game.of.Thrones.S08E06.720p.BluRay.x264-DEMAND', 'The.Mandalorian.S03E08.2160p.WEB-DL.DDP5.1.Atmos.H.265-FLUX', 'Inception.2010.1080p.BluRay.x264-SPARKS',
            '[SubsPlease] Jujutsu Kaisen - 47 (1080p) [ABC12345].mkv', 'Various.Artists.Now.Thats.What.I.Call.Music.100.2018.MP3.320kbps'];

        return [
            [GroupNamePipe::class, 'pipeName', [[]], [[]]],
            [GroupNamePipe::class, 'pipePriority', [[]], [[]]],
            [GroupNamePipe::class, 'evaluateResult', $results, $oddResults],
            [GroupNamePipe::class, 'resultSummary', $results, $oddResults],
            [XxxPipe::class, 'pipeName', [[]], [[]]],
            [XxxPipe::class, 'pipePriority', [[]], [[]]],
            [XxxPipe::class, 'evaluateResult', $results, $oddResults],
            [PcPipe::class, 'pipeName', [[]], [[]]],
            [PcPipe::class, 'pipePriority', [[]], [[]]],
            [PcPipe::class, 'decisionState', [[false, false], [false, true]], [[true, false], [true, true]]],
            [PcPipe::class, 'evaluateResult', $results, $oddResults],
            [TestCategorization::class, 'categorizerRow',
                [[['name' => 'GroupName', 'priority' => 5, 'class' => GroupNamePipe::class]], [['name' => 'PC', 'priority' => 30, 'class' => PcPipe::class]]],
                [[['name' => '', 'priority' => -1, 'class' => '']], [['name' => null, 'priority' => 1, 'class' => 'c']], [['name' => $long, 'priority' => PHP_INT_MAX, 'class' => $long]],
                    [['name' => 'x', 'priority' => '5', 'class' => 'y']], [['class' => 'c', 'priority' => PHP_INT_MIN, 'name' => 'n', 'extra' => 1]], [['name' => 1.5, 'priority' => 2, 'class' => true]]]],
            [TestCategorization::class, 'sampleRow', array_map(static fn (string $sample, int $i): array => [$sample, 'TV > HD', 'TV > SD', $i % 2 === 0], $samples, array_keys($samples)),
                [['', '', '', true], [str_repeat('a', 50), 'x', 'y', false], [str_repeat('a', 51), 'x', 'y', true], [str_repeat('é', 30), "\0", "\xff", false], [$long, $long, '', true]]],
            [TestCategorization::class, 'comparisonStatus', [[true], [false]], [[false], [true]]],
            [RecategorizeOnlyFans::class, 'isEligible', [[Category::XXX_ONLYFANS, 'OF.Sadie.Summers.NEW.TRADWIFE.BG.1080p'], [Category::XXX_ONLYFANS, 'of.Creator_Pack_mp4'], [Category::XXX_OTHER, 'OF.Generic.Title']],
                [[Category::XXX_ONLYFANS, 'OF'], [Category::XXX_ONLYFANS, ''], [Category::XXX_ONLYFANS, 'Öf.x'], [Category::XXX_ONLYFANS, "oF.\0"], [Category::XXX_ONLYFANS, 'O F.'],
                    [Category::XXX_ONLYFANS, $long], [Category::XXX_ONLYFANS, 'of.'.$long], [PHP_INT_MIN, 'of.'], [PHP_INT_MAX, 'OF.x']]],
            [RecategorizeOnlyFans::class, 'previewLine', [[12, 'OF.Sadie.Summers.NEW.TRADWIFE.BG.1080p', 'HD Clips', 6041, 'OnlyFans', 6047], [987654, 'of.Creator_Pack_mp4', 'Other', 6999, 'OnlyFans', 6047]],
                [[PHP_INT_MIN, $long, null, PHP_INT_MAX, null, 0], [0, '', '', 0, "é\0", -1], [1, "OF.\xff", $long, 6041, 'OnlyFans', 6047]]],
            [RecategorizeOnlyFans::class, 'summaryLines', [[false, 5, 3, 0, 0, 0, 2, 0], [true, 250, 120, 118, 117, 1, 130, 3]],
                [[true, PHP_INT_MAX, PHP_INT_MIN, -1, 0, PHP_INT_MAX, PHP_INT_MIN, -1], [false, 0, 0, 0, 0, 0, 0, 0], [false, -5, PHP_INT_MIN, 1, 1, 1, 1, 1]]],
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
