<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mcp\Prompts\DiagnoseNntmuxProblemPrompt;
use App\Mcp\Resources\ProjectOverviewResource;
use App\Mcp\Servers\NewznabTmuxServer;
use App\Mcp\Tools\InspectChangeImpactTool;
use App\Mcp\Tools\InspectCodeContextTool;
use App\Mcp\Tools\PlanVerificationTool;
use App\Services\Mcp\ProjectPathGuard;
use App\Services\Mcp\RepositoryContextCollector;
use App\Services\Mcp\VerificationPlanner;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\Fluent\AssertableJson;
use InvalidArgumentException;
use Laravel\Mcp\Enums\Role;
use Laravel\Mcp\Request;
use Mockery;
use Psr\Log\LoggerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Wave 24.1 helper experiment. Timed public Native calls include serialization,
 * allocation, FFI, Rust computation and deserialization. Excludes Laravel boot,
 * repository collection, logging and production throughput. Dispatch stays PHP
 * unless every helper's common/uncommon mean AND p99 speedups reach 1.5x.
 * Frozen protocol: seed 24012026; 3 rounds x 41 paired samples x 200 individually
 * timed calls per side, 10 warmup calls per side per round; nearest-rank p99.
 * Order and identical input indices are randomized per pair. Raw observations,
 * failures, environment and hashes are preserved in /tmp/wave24-1-*.json.
 * Reproduce: MCP_INSPECT_PROMPT_BENCHMARK=1 php artisan test --compact
 * tests/Unit/RustMcpInspectPromptParityTest.php --filter=test_paired_benchmark
 *
 * Measured 2026-10-24, Apple M5 / Darwin arm64, PHP 8.5.12 CLI opcache/JIT
 * disabled, rustc 1.99.1 release, base dddbb7a7e944247901f3acbc5488a9cc5240274d.
 * Each cell has 24,600 observations per side, PHP latency / Native latency:
 * helper                       common mean/p99  uncommon mean/p99
 * formatChangeImpactSummary    0.062/0.086      0.064/0.079
 * buildChangeImpactPayload     0.025/0.033      0.002/0.002
 * normalizeInspectInput        0.041/0.044      0.476/0.794
 * formatCodeContextSummary     0.066/0.094      0.065/0.083
 * formatPlanSummary            0.059/0.063      0.063/0.056
 * computeDurationMs            0.042/0.053      0.038/0.049
 * formatDiagnosePromptMessages 0.050/0.057      0.051/0.043
 * buildProjectOverviewPayload  0.020/0.023      0.019/0.028
 * All eight candidates miss the 1.5x gate; all five production toggles remain
 * false. The raw report contains absolute ns, per-round ranges, paired order,
 * source/library hashes and host limits (no invalid observations or failures):
 * /tmp/wave24-1-mcp-inspect-prompt-20261024-190726-90559.json
 * This measurement predates only this evidence comment; measured source hashes
 * identify the harness before annotation. No other validation ran concurrently
 * with this final benchmark. This is local helper evidence, not host throughput.
 */
final class RustMcpInspectPromptParityTest extends TestCase
{
    private const int MAX_BYTES = 67_108_864;

    private const array CLASSES = [InspectChangeImpactTool::class, InspectCodeContextTool::class,
        PlanVerificationTool::class, DiagnoseNntmuxProblemPrompt::class, ProjectOverviewResource::class];

    /** @var array<class-string, bool> */
    private array $previousToggles = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CLASSES as $class) {
            $this->previousToggles[$class] = (new ReflectionProperty($class, 'useRustFfi'))->getValue();
            $class::setUseRustFfi(false);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousToggles as $class => $toggle) {
            $class::setUseRustFfi($toggle);
        }
        parent::tearDown();
    }

    public function test_defaults_and_independent_original_contracts(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi']);
            $this->assertFalse($class::usesRustFfi());
        }
        $this->assertSame('Assessed change impact for 0 path(s).', InspectChangeImpactTool::formatChangeImpactSummary(0));
        $impact = ['paths' => ['app/Test.php'], 'warnings' => ['bounded'], 'truncated' => true, 'other' => ['keep' => null]];
        $this->assertSame(['schema_version' => 7, 'summary' => 'Assessed change impact for 1 path(s).',
            'evidence' => $impact, 'warnings' => ['bounded'], 'truncated' => true], InspectChangeImpactTool::buildChangeImpactPayload($impact, 7));
        $this->assertSame(['query' => 'foo', 'kind' => 'auto', 'max_results' => 3], InspectCodeContextTool::normalizeInspectInput("\0 \tfoo\r\n\v", null, null, 3));
        $this->assertSame(['query' => "\f x\f", 'kind' => '', 'max_results' => -2], InspectCodeContextTool::normalizeInspectInput("\f x\f", '', -2, -1));
        $this->assertSame('Found 2 relevant source location(s).', InspectCodeContextTool::formatCodeContextSummary(2));
        $this->assertSame('Planned 3 verification check(s).', PlanVerificationTool::formatPlanSummary(3));
        $this->assertSame(-1, PlanVerificationTool::computeDurationMs(2_000_000, 1));
        $this->assertSame(0, PlanVerificationTool::computeDurationMs(999_999, 1));
        $messages = DiagnoseNntmuxProblemPrompt::formatDiagnosePromptMessages('failed', null);
        $this->assertSame('Act as an evidence-first NNTmux debugger. Inspect the producer, runtime path, schema/configuration, history, and related tests before proposing a change. Identify the narrow root cause, preserve public behavior, and finish with focused failure-mode coverage plus the verification plan. Use runtime probes only when useful, and request NNTP explicitly if it is genuinely required.', $messages[0]);
        $this->assertSame("Problem:\nfailed\n\nScope:\nInfer the narrowest safe scope from evidence.", $messages[1]);
        $this->assertSame("Problem:\n\n\nScope:\n", DiagnoseNntmuxProblemPrompt::formatDiagnosePromptMessages('', '')[1]);
        $overview = ProjectOverviewResource::buildProjectOverviewPayload(9);
        $this->assertSame(9, $overview['schema_version']);
        $this->assertSame(['schema_version', 'summary', 'architecture', 'testing', 'command_policy', 'companion', 'warnings', 'truncated'], array_keys($overview));
        $this->assertSame('NNTP -> NNTPService -> BinariesRunner -> ReleaseCreationService -> ReleaseProcessingService -> SearchService -> API/Web', $overview['architecture']['processing_flow']);
        $this->assertSame('Run PHP, Artisan, Composer, npm, Pint, PHPStan, and tests on the WSL host.', $overview['command_policy']['default']);
        $this->assertSame('PHPUnit 12', $overview['testing']['framework']);
        $this->assertCount(5, $overview['architecture']['important_directories']);
        $this->assertFalse($overview['truncated']);
    }

    public function test_all_native_helpers_and_both_dispatch_modes_preserve_bytes_types_and_order(): void
    {
        $this->nativeFfi();
        foreach (self::vectors() as [$class, $method, $args]) {
            $php = $method.'Php';
            $native = $method.'Native';
            $expected = $class::$php(...$args);
            $actual = $class::$native(...$args);
            $this->assertNotNull($actual, $method.' Native declined');
            $this->assertSame(serialize($expected), serialize($actual), $method);
            foreach ([false, true] as $enabled) {
                $class::setUseRustFfi($enabled);
                $this->assertSame(serialize($expected), serialize($class::$method(...$args)), $method);
            }
        }
    }

    public function test_unavailable_ffi_declines_and_dispatches_to_php_in_a_fresh_process(): void
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common]) {
            $vectors[] = [$class, $method, $common[0]];
        }
        $encoded = base64_encode(serialize($vectors));
        $script = 'require "tests/bootstrap.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class, $method, $args]) {'
            .'$class::setUseRustFfi(true); $native = $method."Native"; $php = $method."Php";'
            .'if ($class::usesRustFfi() || $class::$native(...$args) !== null || serialize($class::$method(...$args)) !== serialize($class::$php(...$args))) {'
            .'throw new RuntimeException($method." did not fall back"); }} echo "fallback verified";';
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $script], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('fallback verified', $process->getOutput());
    }

    public function test_native_declines_keep_original_php_errors_and_large_payload_retries(): void
    {
        $this->nativeFfi();
        $impact = ['paths' => 'invalid count input', 'warnings' => [], 'truncated' => false];
        $this->assertNull(InspectChangeImpactTool::buildChangeImpactPayloadNative($impact, 1));
        foreach ([false, true] as $enabled) {
            InspectChangeImpactTool::setUseRustFfi($enabled);
            try {
                InspectChangeImpactTool::buildChangeImpactPayload($impact, 1);
                $this->fail('count() must retain its original TypeError.');
            } catch (\TypeError) {
                $this->addToAssertionCount(1);
            }
        }
        $impact = ['paths' => [], 'warnings' => [str_repeat('x', 300_000)], 'truncated' => true];
        $this->assertSame(InspectChangeImpactTool::buildChangeImpactPayloadPhp($impact, 1), InspectChangeImpactTool::buildChangeImpactPayloadNative($impact, 1));
    }

    public function test_seeded_randomized_native_parity(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(24012027));
        for ($i = 0; $i < 200; $i++) {
            $length = $random->getInt(0, 256);
            $bytes = $length ? $random->getBytes($length) : '';
            $number = $i % 3 === 0 ? PHP_INT_MIN : ($i % 3 === 1 ? PHP_INT_MAX : $random->getInt(-999999999, 999999999));
            $impact = ['paths' => [4 => $bytes, 9 => 'é🙂'], 'warnings' => [$bytes], 'truncated' => $i % 2 === 0,
                'metadata' => ['binary' => $bytes, 'value' => $number, 'float' => [0.1, -0.0, INF, -INF, NAN], '01' => false, -7 => null]];
            $vectors = [
                [InspectChangeImpactTool::class, 'formatChangeImpactSummary', [$number]],
                [InspectChangeImpactTool::class, 'buildChangeImpactPayload', [$impact, $number]],
                [InspectCodeContextTool::class, 'normalizeInspectInput', [" \0".$bytes."\v\n", $i % 2 ? null : $bytes, $i % 2 ? null : $number, $number]],
                [InspectCodeContextTool::class, 'formatCodeContextSummary', [$number]],
                [PlanVerificationTool::class, 'formatPlanSummary', [$number]],
                [PlanVerificationTool::class, 'computeDurationMs', [$number, $random->getInt(-999999999, 999999999)]],
                [PlanVerificationTool::class, 'computeDurationMs', [0, $random->getInt(-9223372036854, 9223372036854) * 1_000_000]],
                [DiagnoseNntmuxProblemPrompt::class, 'formatDiagnosePromptMessages', [$bytes, $i % 2 ? null : $bytes]],
                [ProjectOverviewResource::class, 'buildProjectOverviewPayload', [$number]],
            ];
            foreach ($vectors as [$class, $method, $args]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $actual = $class::$native(...$args);
                $this->assertNotNull($actual, $method.' case '.$i);
                $this->assertSame(serialize($class::$php(...$args)), serialize($actual), $method.' case '.$i);
            }
        }
    }

    public function test_declines_preserve_object_resource_reference_identity_without_serialization_hooks(): void
    {
        $this->nativeFfi();
        $object = new class
        {
            public int $calls = 0;

            public function __serialize(): array
            {
                $this->calls++;

                return [];
            }
        };
        $resource = fopen('php://memory', 'w+');
        $this->assertIsResource($resource);
        $value = ['marker' => 'before'];
        $references = [&$value, &$value];
        $cycle = [];
        $cycle['self'] = &$cycle;
        $deep = ['end'];
        for ($i = 0; $i < 70; $i++) {
            $deep = [$deep];
        }
        try {
            foreach ([$object, $resource, $references, $cycle, $deep, array_fill(0, 33000, false)] as $metadata) {
                $impact = ['paths' => [], 'warnings' => [], 'truncated' => false, 'metadata' => $metadata];
                $this->assertNull(InspectChangeImpactTool::buildChangeImpactPayloadNative($impact, 1));
                InspectChangeImpactTool::setUseRustFfi(true);
                $actual = InspectChangeImpactTool::buildChangeImpactPayload($impact, 1);
                $this->assertSame($metadata, $actual['evidence']['metadata']);
                $this->assertSame(0, $object->calls);
            }
            $impact['metadata'] = $references;
            $actual = InspectChangeImpactTool::buildChangeImpactPayload($impact, 1);
            $value['marker'] = 'after';
            $this->assertSame('after', $actual['evidence']['metadata'][0]['marker']);
        } finally {
            fclose($resource);
        }
        $oversized = str_repeat('x', self::MAX_BYTES);
        $this->assertNull(InspectCodeContextTool::normalizeInspectInputNative($oversized, null, null, 20));
        InspectCodeContextTool::setUseRustFfi(true);
        $this->assertSame($oversized, InspectCodeContextTool::normalizeInspectInput($oversized, null, null, 20)['query']);
    }

    public function test_c_abi_declines_malformed_unknown_null_overflow_and_overlapping_ranges_atomically(): void
    {
        $ffi = $this->nativeFfi();
        $out = $ffi->new('char[256]');
        FFI::memset($out, 0x5A, 256);
        foreach (['', 'N;', 'a:0:{}junk', 'a:1:{i:0;i:9223372036854775808;}', 'a:1:{i:0;i:-9223372036854775809;}',
            'a:999999999999999999999:{}', 'a:1:{i:00;i:1;}', serialize([new \stdClass]),
            'a:1:{s:1:"0";i:1;}', 'a:2:{i:0;i:1;i:0;i:2;}', 'a:2:{i:0;i:1;i:1;R:2;}', serialize(['count' => 1]), serialize([true]), serialize([1, 2])] as $input) {
            $this->assertSame(-1, self::abi($ffi, 0, $input, strlen($input), $out, 256), bin2hex($input));
            $this->assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        }
        $input = serialize([1]);
        foreach ([8, 4294967295] as $operation) {
            $this->assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), $out, 256));
        }
        foreach (range(0, 7) as $operation) {
            $empty = serialize([]);
            $this->assertSame(-1, self::abi($ffi, $operation, $empty, strlen($empty), $out, 256));
            $wrong = serialize([null, null, null, null]);
            $this->assertSame(-1, self::abi($ffi, $operation, $wrong, strlen($wrong), $out, 256));
        }
        foreach ([0, 1, PHP_INT_MAX, -1] as $length) {
            $this->assertSame(-1, self::abi($ffi, 0, null, $length, $out, 256));
        }
        $this->assertSame(-1, self::abi($ffi, 0, $input, strlen($input), null, 0));
        foreach ([self::MAX_BYTES + 1, PHP_INT_MAX, -1] as $length) {
            $this->assertSame(-1, self::abi($ffi, 0, $input, $length, $out, 256));
            $this->assertSame(-1, self::abi($ffi, 0, $input, strlen($input), $out, $length));
        }
        $wrap = $ffi->cast('char *', -8);
        $this->assertSame(-1, self::abi($ffi, 0, $wrap, 32, $out, 256));
        $this->assertSame(-1, self::abi($ffi, 0, $input, strlen($input), $wrap, 32));
        $this->assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        FFI::memcpy($out, $input, strlen($input));
        $before = FFI::string($out, 256);
        foreach ([0, 1, strlen($input) - 1] as $offset) {
            $this->assertSame(-1, self::abi($ffi, 0, $out, strlen($input), FFI::addr($out[$offset]), 128));
            $this->assertSame($before, FFI::string($out, 256));
        }
    }

    public function test_all_c_abi_operations_have_exact_atomic_capacities_and_unaligned_byte_buffers(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::workloads() as $operation => [$class, $method, $common]) {
            $args = $common[0];
            $php = $method.'Php';
            $expected = serialize($class::$php(...$args));
            $input = serialize($args);
            $needed = strlen($expected);
            $out = $ffi->new('char['.($needed + 2).']');
            FFI::memset($out, 0x5A, $needed + 2);
            foreach ([0, 1, $needed - 1] as $capacity) {
                $this->assertSame(-$needed, self::abi($ffi, $operation, $input, strlen($input), $out, $capacity));
                $this->assertSame(str_repeat('Z', $needed + 2), FFI::string($out, $needed + 2));
            }
            // Byte-oriented ABI has no typed output pointers: offsets of one are legal.
            $backing = $ffi->new('char['.(strlen($input) + 2).']');
            $unaligned = FFI::addr($backing[1]);
            FFI::memcpy($unaligned, $input, strlen($input));
            $target = FFI::addr($out[1]);
            $this->assertSame($needed, self::abi($ffi, $operation, $unaligned, strlen($input), $target, $needed));
            $this->assertSame($expected, FFI::string($target, $needed));
            $this->assertSame('Z', $out[0]);
            $this->assertSame('Z', $out[$needed + 1]);
        }
    }

    public function test_real_handlers_use_normalized_inputs_preserve_payloads_roles_and_validation(): void
    {
        config()->set('nntmux-mcp.schema_version', 7);
        config()->set('nntmux-mcp.limits.max_results', 3);
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            $impact = ['paths' => ['app/Test.php'], 'warnings' => ['bounded'], 'truncated' => true];
            $evidence = ['matches' => [['path' => 'app/Test.php']], 'warnings' => [], 'truncated' => false];
            $collector = Mockery::mock(RepositoryContextCollector::class);
            $collector->shouldReceive('changeImpact')->once()->with(['app/Test.php'])->andReturn($impact);
            $collector->shouldReceive('inspect')->once()->with('Query', 'auto', 3)->andReturn($evidence);
            $collector->shouldReceive('inspect')->once()->with('bad', 'path', 1)->andThrow(new InvalidArgumentException('blocked'));
            $this->app->instance(RepositoryContextCollector::class, $collector);
            NewznabTmuxServer::tool(InspectChangeImpactTool::class, ['paths' => ['app/Test.php']])->assertOk()
                ->assertStructuredContent(fn (AssertableJson $json) => $json->where('schema_version', 7)->where('summary', 'Assessed change impact for 1 path(s).')->where('evidence', $impact)->where('warnings', ['bounded'])->where('truncated', true));
            NewznabTmuxServer::tool(InspectCodeContextTool::class, ['query' => " Query\n"])->assertOk()
                ->assertStructuredContent(fn (AssertableJson $json) => $json->where('schema_version', 7)->where('summary', 'Found 1 relevant source location(s).')->where('evidence', $evidence)->where('warnings', [])->where('truncated', false));
            NewznabTmuxServer::tool(InspectCodeContextTool::class, ['query' => 'bad', 'kind' => 'path', 'max_results' => 1])->assertHasErrors(['blocked']);
            foreach ([[], ['query' => 'x', 'kind' => 'unknown'], ['query' => 'x', 'max_results' => 4], ['query' => str_repeat('x', 8193)]] as $bad) {
                NewznabTmuxServer::tool(InspectCodeContextTool::class, $bad)->assertHasErrors();
            }
            NewznabTmuxServer::tool(InspectChangeImpactTool::class, ['paths' => []])->assertHasErrors();
            $guard = $this->createMock(ProjectPathGuard::class);
            $guard->expects($this->once())->method('normalizeMany')->with(['app/Test.php'])->willReturn(['app/Test.php']);
            $planner = $this->createMock(VerificationPlanner::class);
            $plan = ['checks' => [['id' => 'php-lint']]];
            $planner->expects($this->once())->method('plan')->with(['app/Test.php'])->willReturn($plan);
            $logger = Mockery::mock(LoggerInterface::class);
            $logger->shouldReceive('info')->once()->with('MCP tool completed', Mockery::on(fn (array $context): bool => $context['tool'] === 'plan-verification' && is_int($context['duration_ms']) && $context['duration_ms'] >= 0));
            Log::shouldReceive('channel')->once()->with('nntmux_mcp')->andReturn($logger);
            $response = (new PlanVerificationTool)->handle(new Request(['paths' => ['app/Test.php']]), $guard, $planner);
            $this->assertSame(['schema_version' => 7, 'summary' => 'Planned 1 verification check(s).', 'paths' => ['app/Test.php'], 'results' => $plan, 'warnings' => [], 'truncated' => false], $response->getStructuredContent());
            $messages = (new DiagnoseNntmuxProblemPrompt)->handle(new Request(['problem' => 'failed', 'scope' => null]));
            $this->assertSame(Role::Assistant, $messages[0]->role());
            $this->assertSame(Role::User, $messages[1]->role());
            $expected = DiagnoseNntmuxProblemPrompt::formatDiagnosePromptMessagesPhp('failed', null);
            $this->assertSame($expected[0], (string) $messages[0]->content());
            $this->assertSame($expected[1], (string) $messages[1]->content());
            $resource = (new ProjectOverviewResource)->handle(new Request([]));
            $this->assertSame(ProjectOverviewResource::buildProjectOverviewPayloadPhp(7), json_decode((string) $resource->content(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function test_paired_benchmark(): void
    {
        if (getenv('MCP_INSPECT_PROMPT_BENCHMARK') !== '1') {
            $this->markTestSkipped('Opt in with MCP_INSPECT_PROMPT_BENCHMARK=1.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave24-1-mcp-inspect-prompt-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        self::benchmark($path);
        $this->assertFileExists($path);
        fwrite(STDERR, "\nMCP inspect/prompt raw evidence: {$path}\n");
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('mcp-inspect-prompt-test', InspectChangeImpactTool::FFI_CDEF);
        $this->assertNotNull($ffi, 'Build/copy the release library; native coverage must not silently skip.');

        return $ffi;
    }

    private static function abi(FFI $ffi, int $operation, string|CData|null $input, int $length, ?CData $output, int $capacity): int
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w24_mcp_inspect_prompt($operation, $input, $length, $output, $capacity);
    }

    /** @return list<array{class-string, string, array<mixed>}> */
    private static function vectors(): array
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            foreach ([...$common, ...$uncommon] as $args) {
                $vectors[] = [$class, $method, $args];
            }
        }
        foreach ([PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1] as $integer) {
            foreach ([[InspectChangeImpactTool::class, 'formatChangeImpactSummary'], [InspectCodeContextTool::class, 'formatCodeContextSummary'], [PlanVerificationTool::class, 'formatPlanSummary'], [ProjectOverviewResource::class, 'buildProjectOverviewPayload']] as [$class, $method]) {
                $vectors[] = [$class, $method, [$integer]];
            }
        }
        foreach ([[0, 0], [0, 999999], [0, 1000000], [999999, 0], [1000000, 0], [0, 7952064785736000000], [7952064785736000000, 0], [PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MIN], [PHP_INT_MAX - 999999, PHP_INT_MAX]] as $args) {
            $vectors[] = [PlanVerificationTool::class, 'computeDurationMs', $args];
        }
        foreach (["\0 \tfoo\n\r\v", "\f foo\f", "\xc2\xa0foo\xc2\xa0", "\0\xff\0", '', '  '] as $query) {
            $vectors[] = [InspectCodeContextTool::class, 'normalizeInspectInput', [$query, '', 0, -1]];
            $vectors[] = [DiagnoseNntmuxProblemPrompt::class, 'formatDiagnosePromptMessages', [$query, '']];
        }

        return $vectors;
    }

    /** @return list<array{class-string, string, list<array<mixed>>, list<array<mixed>>}> */
    private static function workloads(): array
    {
        $impact = ['paths' => ['app/Mcp/Tools/InspectCodeContextTool.php'], 'warnings' => [], 'truncated' => false, 'checks' => ['pint', 'phpstan', 'phpunit']];
        $large = ['paths' => array_fill(0, 20, 'app/Services/Example.php'), 'warnings' => [str_repeat('bounded evidence ', 512)], 'truncated' => true,
            'matches' => array_fill(0, 50, ['path' => 'app/Test.php', 'line' => 123, 'snippet' => str_repeat('é🙂 context ', 32)])];

        return [
            [InspectChangeImpactTool::class, 'formatChangeImpactSummary', [[1], [5], [20]], [[0], [PHP_INT_MIN], [PHP_INT_MAX]]],
            [InspectChangeImpactTool::class, 'buildChangeImpactPayload', [[$impact, 1]], [[$large, PHP_INT_MAX]]],
            [InspectCodeContextTool::class, 'normalizeInspectInput', [[' app/Example.php ', null, null, 50], ['Example', 'symbol', 10, 50]], [[str_repeat('é🙂x', 1024), 'error', 50, 50], [str_repeat(' ', 8190).'x ', null, null, 1]]],
            [InspectCodeContextTool::class, 'formatCodeContextSummary', [[0], [5], [50]], [[PHP_INT_MIN], [PHP_INT_MAX]]],
            [PlanVerificationTool::class, 'formatPlanSummary', [[1], [6]], [[0], [PHP_INT_MIN], [PHP_INT_MAX]]],
            [PlanVerificationTool::class, 'computeDurationMs', [[1000000000, 1003000000], [10, 999999]], [[PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MIN], [0, 7952064785736000000], [7952064785736000000, 0], [1000000, 0]]],
            [DiagnoseNntmuxProblemPrompt::class, 'formatDiagnosePromptMessages', [['Failed to index releases', null], ['Missing result', 'app/Services']], [[str_repeat('failure é🙂 ', 800), str_repeat('scope ', 300)], ["\0\xff", '']]],
            [ProjectOverviewResource::class, 'buildProjectOverviewPayload', [[1], [2]], [[PHP_INT_MIN], [PHP_INT_MAX]]],
        ];
    }

    /**
     * This entry point also runs without booting Laravel:
     * php -r 'require "tests/bootstrap.php"; require "tests/Unit/RustMcpInspectPromptParityTest.php";
     * Tests\Unit\RustMcpInspectPromptParityTest::benchmark("/tmp/wave24-1-manual.json");'
     */
    public static function benchmark(string $outputPath): void
    {
        $root = dirname(__DIR__, 2);
        $seed = 24012026;
        $report = [
            'protocol' => ['rounds' => 3, 'paired_samples_per_round' => 41, 'calls_per_side_per_sample' => 200,
                'warmup_calls_per_side_per_round' => 10, 'seed' => $seed, 'clock' => 'hrtime(true), individual call ns',
                'p99' => 'nearest-rank over individual calls', 'order' => 'independently randomized PHP/Native side order per pair; identical randomized input indices',
                'scope' => 'Public Php versus Native helper calls, including all Native serialization/FFI/allocation/deserialization; no dispatcher fallback; excludes process startup, probes, I/O, boot and production throughput.',
                'uncertainty' => 'Observed min/max across three rounds; descriptive range, not a confidence interval. 8200 individual calls/side/round; p99 has 82 upper-tail observations/round.',
                'gate' => 'Every helper common and uncommon mean AND p99 speedups must each be >=1.5; no averaging across helpers.',
                'reproduce' => 'MCP_INSPECT_PROMPT_BENCHMARK=1 php artisan test --compact tests/Unit/RustMcpInspectPromptParityTest.php --filter=test_paired_benchmark'],
            'base_head' => 'dddbb7a7e944247901f3acbc5488a9cc5240274d',
            'head' => self::command('git rev-parse HEAD'), 'git_status' => self::command('git status --porcelain'),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'mb_encoding' => mb_internal_encoding()],
            'rustc' => self::command('rustc --version'), 'cargo' => self::command('cargo --version'),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.model hw.ncpu hw.memsize' : 'lscpu'),
            'limits' => self::command('ulimit -a'), 'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml; locked repository release profile',
            'source_sha256' => [], 'libraries' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php', 'app/Mcp/Tools/InspectChangeImpactTool.php', 'app/Mcp/Tools/InspectCodeContextTool.php', 'app/Mcp/Tools/PlanVerificationTool.php', 'app/Mcp/Prompts/DiagnoseNntmuxProblemPrompt.php', 'app/Mcp/Resources/ProjectOverviewResource.php', 'tests/Unit/RustMcpInspectPromptParityTest.php'] as $file) {
            $report['source_sha256'][$file] = is_file($root.'/'.$file) ? hash_file('sha256', $root.'/'.$file) : null;
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix, '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix] as $file) {
            $report['libraries'][$file] = is_file($file) ? ['realpath' => realpath($file), 'sha256' => hash_file('sha256', $file)] : null;
        }
        if (PHP_OS_FAMILY === 'Linux') {
            foreach (['/sys/fs/cgroup/cpu.max', '/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/cpu.stat', '/proc/self/status'] as $file) {
                $report['linux_limits'][$file] = is_readable($file) ? file_get_contents($file) : null;
            }
        }
        $report['protocol_sha256'] = hash('sha256', serialize($report['protocol']));
        $random = new Randomizer(new Mt19937($seed));
        $encoding = mb_internal_encoding();
        mb_internal_encoding('UTF-8');
        try {
            $loadStart = hrtime(true);
            if (RustPipelineLibrary::resolveDomain('mcp-inspect-prompt', InspectChangeImpactTool::FFI_CDEF) === null) {
                throw new RuntimeException('Benchmark invalid: native MCP export unavailable.');
            }
            $report['library_load_ns'] = hrtime(true) - $loadStart;
            foreach ($report['libraries'] as $path => $identity) {
                if ($identity !== null && RustPipelineLibrary::resolveDomain('mcp-inspect-benchmark-identity', InspectChangeImpactTool::FFI_CDEF, $path) !== null) {
                    $report['selected_library'] = ['path' => $path, ...$identity];
                    break;
                }
            }
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $method;
                $phpMethod = $method.'Php';
                $nativeMethod = $method.'Native';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    foreach ($inputs as $args) {
                        $expected = $class::$phpMethod(...$args);
                        $actual = $class::$nativeMethod(...$args);
                        if ($actual === null || serialize($expected) !== serialize($actual)) {
                            throw new RuntimeException($name.'/'.$kind.' invalid: direct Native decline or mismatch.');
                        }
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
                    $aggregate = ['php' => [], 'native' => []];
                    for ($round = 0; $round < 3; $round++) {
                        foreach ([$phpMethod, $nativeMethod] as $warmMethod) {
                            for ($warm = 0; $warm < 10; $warm++) {
                                $class::$warmMethod(...$inputs[$warm % count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        $pairs = [];
                        for ($sample = 0; $sample < 41; $sample++) {
                            $indices = [];
                            for ($iteration = 0; $iteration < 200; $iteration++) {
                                $indices[] = $random->getInt(0, count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            $pairs[] = ['order' => $order, 'input_indices' => $indices];
                            foreach ($order as $side) {
                                $timedMethod = $side === 'php' ? $phpMethod : $nativeMethod;
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$timedMethod(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if ($side === 'native' && $result === null) {
                                        throw new RuntimeException($name.'/'.$kind.' Native declined during timing.');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['round' => $round + 1, 'pairs' => $pairs, 'raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / $nativeStats['mean_ns'], 'p99_speedup' => $phpStats['p99_ns'] / $nativeStats['p99_ns']];
                        foreach (['php', 'native'] as $side) {
                            array_push($aggregate[$side], ...$raw[$side]);
                        }
                    }
                    $cell['aggregate'] = ['php' => self::statistics($aggregate['php']), 'native' => self::statistics($aggregate['native'])];
                    $cell['aggregate']['mean_speedup'] = $cell['aggregate']['php']['mean_ns'] / $cell['aggregate']['native']['mean_ns'];
                    $cell['aggregate']['p99_speedup'] = $cell['aggregate']['php']['p99_ns'] / $cell['aggregate']['native']['p99_ns'];
                    $cell['round_range'] = [];
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $report['results'][$name]['qualifies'] = $report['results'][$name]['common']['aggregate']['mean_speedup'] >= 1.5
                    && $report['results'][$name]['common']['aggregate']['p99_speedup'] >= 1.5
                    && $report['results'][$name]['uncommon']['aggregate']['mean_speedup'] >= 1.5
                    && $report['results'][$name]['uncommon']['aggregate']['p99_speedup'] >= 1.5;
            }
        } catch (Throwable $exception) {
            $report['failures'][] = ['type' => $exception::class, 'message' => $exception->getMessage()];
            if (isset($name, $kind, $cell)) {
                $report['partial_observation'] = ['helper' => $name, 'kind' => $kind, 'completed_rounds' => $cell,
                    'round' => $round ?? null, 'sample' => $sample ?? null, 'pairs' => $pairs ?? [], 'raw_ns' => $raw ?? []];
            }
            throw $exception;
        } finally {
            mb_internal_encoding($encoding);
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (file_put_contents($outputPath, $json) === false) {
                throw new RuntimeException('Unable to preserve benchmark evidence at '.$outputPath);
            }
        }
    }

    /** @param list<int> $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count,
            'p99_ns' => $values[(int) ceil($count * 0.99) - 1], 'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    private static function command(string $command): string
    {
        return trim((string) shell_exec($command.' 2>&1'));
    }
}
