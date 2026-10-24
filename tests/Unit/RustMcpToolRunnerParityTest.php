<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Mcp\Resources\ProjectInstructionsResource;
use App\Mcp\Servers\NewznabTmuxServer;
use App\Mcp\Tools\ProbeRuntimeTool;
use App\Mcp\Tools\RunVerificationTool;
use App\Services\Mcp\ProcessRunner;
use App\Services\Mcp\RuntimeProbeRunner;
use App\Services\Mcp\SensitiveValueRedactor;
use App\Services\Mcp\VerificationRunner;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Services\StatusProbes\DatabaseProbe;
use App\Services\StatusProbes\DiskProbe;
use App\Services\StatusProbes\ProbeResult;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Mockery;
use Psr\Log\LoggerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use TypeError;

/**
 * Helper-only experiment; process startup, probes, filesystem reads, Laravel boot,
 * and production throughput are outside the timed scope. Public Native calls
 * include PHP serialization, allocation, FFI, Rust work, and PHP deserialization.
 * No adoption claim is made by this harness; all candidates default to PHP.
 *
 * Reproduce after building the release library:
 * MCP_TOOL_RUNNER_BENCHMARK=1 php artisan test --compact
 * tests/Unit/RustMcpToolRunnerParityTest.php --filter=test_paired_benchmark
 *
 * Frozen protocol: seed 23022026, exactly 3 rounds x 41 randomized paired samples
 * x 200 individually timed calls per side, 10 warmup calls per side per round.
 * p99 uses the nearest rank of individual call latencies, never batch averages.
 * Raw observations, input/order indices, source/library hashes and host limits
 * are written outside tracked files to /tmp/wave23-2-mcp-tool-runner-*.json.
 *
 * Measured 2026-10-24, Apple M5 / Darwin arm64, PHP 8.5.12 (CLI opcache/JIT
 * disabled), rustc 1.99.1 release, base 775ba6cf8bcfe6b571fb5a850dfc7187c1adaa00.
 * Each cell has 24,600 observations per side. PHP latency / Native latency,
 * mean/p99 speedup; absolute ns, all rounds and observed ranges are in the raw
 * report /tmp/wave23-2-mcp-tool-runner-20261024-183433-81618.json (no failures).
 * Candidate                    common          uncommon
 * limitOutput                  0.037/0.039     0.015/0.023
 * buildProcessResult           0.022/0.016     0.005/0.006
 * deduplicateIdentifiers       0.041/0.026     0.021/0.022
 * resolveProbeBatchStatus      0.059/0.051     0.045/0.066
 * formatProbeEntry             0.027/0.029     0.002/0.002
 * normalizeInstructionPaths    0.062/0.041     0.336/0.303
 * assembleInstructionDocument  0.071/0.061     0.030/0.057
 * buildProbeToolSummary        0.051/0.026     0.054/0.035
 * formatVerificationSummary    0.058/0.023     0.059/0.039
 * isAllowedCheck               0.059/0.023     0.053/0.022
 * All candidates miss the 1.5x mean and p99 gates; all five production toggles
 * remain false. Native methods stay directly callable for parity/experiments.
 * This measurement predates only this evidence comment; the report preserves
 * the measured source hashes, including the test harness before annotation.
 */
final class RustMcpToolRunnerParityTest extends TestCase
{
    private const int MAX_BYTES = 67_108_864;

    private const array CLASSES = [
        ProcessRunner::class,
        RuntimeProbeRunner::class,
        ProjectInstructionsResource::class,
        ProbeRuntimeTool::class,
        RunVerificationTool::class,
    ];

    private string $previousEncoding;

    /** @var array<class-string, bool> */
    private array $previousToggles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEncoding = mb_internal_encoding();
        mb_internal_encoding('UTF-8');
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
        if (isset($this->previousEncoding)) {
            mb_internal_encoding($this->previousEncoding);
        }
        parent::tearDown();
    }

    public function test_default_switches_and_original_php_contracts(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi']);
            $this->assertFalse($class::usesRustFfi());
        }

        $this->assertSame(['x'.'\n[output truncated]', true], ProcessRunner::limitOutput('xyz', 0));
        $this->assertSame(['é'.'\n[output truncated]', true], ProcessRunner::limitOutput('éé', 3));
        $this->assertSame(["a\0\xff", false], ProcessRunner::limitOutput("a\0\xff", 3));
        $this->assertSame([
            'command' => ['php', '-l'], 'exit_code' => null, 'duration_ms' => -1,
            'status' => 'timed_out', 'output' => "a\0\xff", 'truncated' => true,
        ], ProcessRunner::buildProcessResult(['php', '-l'], null, -1, 'timed_out', "a\0\xff", true));
        $this->assertSame(['disk', 'database'], RuntimeProbeRunner::deduplicateIdentifiers([4 => 'disk', 8 => 'database', 9 => 'disk']));
        $this->assertSame('passed', RuntimeProbeRunner::resolveProbeBatchStatus([]));
        $this->assertSame('failed', RuntimeProbeRunner::resolveProbeBatchStatus([['ok' => true], ['ok' => false]]));
        $this->assertSame([
            'id' => 'disk', 'ok' => false, 'latency_ms' => 7, 'impact' => 'minor',
            'reason' => 'full', 'metadata' => ['free' => 0],
        ], RuntimeProbeRunner::formatProbeEntry('disk', false, 7, 'minor', 'full', ['free' => 0]));
        $this->assertSame(['.ai/rules/index.md', 'AGENTS.md'], ProjectInstructionsResource::normalizeInstructionPaths(['AGENTS.md', '.ai/rules/index.md', 'AGENTS.md']));
        $this->assertSame("<!-- schema_version: 4 -->\n<!-- truncated: false -->\nfirst\n\nsecond", ProjectInstructionsResource::assembleInstructionDocument(['first', 'second'], 13, 4));
        $this->assertSame("<!-- schema_version: 4 -->\n<!-- truncated: true -->\na\n\n[project instructions truncated]", ProjectInstructionsResource::assembleInstructionDocument(['abc'], 0, 4));
        $this->assertSame([
            'schema_version' => 3, 'summary' => 'Completed 2 probe(s); 1 reported unhealthy.',
            'warnings' => ['One or more selected runtime services reported unhealthy.'], 'truncated' => false,
        ], ProbeRuntimeTool::buildProbeToolSummary(2, 1, 3));
        $this->assertSame('Verification failed with 2 result(s).', RunVerificationTool::formatVerificationSummary('failed', 2));
        foreach (['targeted', 'phpunit', 'phpstan', 'pint', 'php-lint', 'diff', 'composer', 'frontend'] as $check) {
            $this->assertTrue(RunVerificationTool::isAllowedCheck($check));
        }
        foreach (['', 'PHPUNIT', ' phpunit', 'phpunit ', "phpunit\0", 'shell', 'phpunit;id'] as $check) {
            $this->assertFalse(RunVerificationTool::isAllowedCheck($check));
        }
    }

    public function test_native_and_both_dispatch_modes_match_all_helper_vectors(): void
    {
        $this->nativeFfi();
        foreach (self::parityVectors() as [$class, $method, $args]) {
            $php = $method.'Php';
            $native = $method.'Native';
            $expected = $class::$php(...$args);
            // The direct Native method must work while production dispatch is off.
            $class::setUseRustFfi(false);
            $actual = $class::$native(...$args);
            $this->assertNotNull($actual, $class.'::'.$native.' must not silently fall back.');
            $this->assertSame(serialize($expected), serialize($actual), $class.'::'.$method);
            foreach ([false, true] as $enabled) {
                $class::setUseRustFfi($enabled);
                $this->assertSame(serialize($expected), serialize($class::$method(...$args)), $class.'::'.$method);
            }
        }
    }

    public function test_native_declines_preserve_php_fallback_and_error_semantics(): void
    {
        $this->nativeFfi();
        $object = (object) ['keep' => 'object identity'];
        $reference = ['name' => 'same'];
        $references = [&$reference, &$reference];
        $deep = ['leaf'];
        for ($i = 0; $i < 70; $i++) {
            $deep = [$deep];
        }
        $cases = [
            [RuntimeProbeRunner::class, 'formatProbeEntry', ['disk', true, 1, null, 'ok', ['object' => $object]]],
            [RuntimeProbeRunner::class, 'formatProbeEntry', ['disk', true, 1, null, 'ok', $references]],
            [RuntimeProbeRunner::class, 'formatProbeEntry', ['disk', true, 1, null, 'ok', $deep]],
            [RuntimeProbeRunner::class, 'formatProbeEntry', ['disk', true, 1, null, 'ok', array_fill(0, 33_000, true)]],
            [ProjectInstructionsResource::class, 'normalizeInstructionPaths', [['10', '2', '01', '1e2', '10']]],
        ];
        foreach ($cases as [$class, $method, $args]) {
            $native = $method.'Native';
            $php = $method.'Php';
            $this->assertNull($class::$native(...$args), $class.'::'.$native);
            $class::setUseRustFfi(true);
            $this->assertSame($class::$php(...$args), $class::$method(...$args));
        }
        $this->assertSame($object, RuntimeProbeRunner::formatProbeEntry('disk', true, 1, null, 'ok', ['object' => $object])['metadata']['object']);

        foreach ([[['ok' => 1]], [['ok' => '0']], [['ok' => null]], ['invalid row']] as $invalidResults) {
            $this->assertNull(RuntimeProbeRunner::resolveProbeBatchStatusNative($invalidResults));
            foreach ([false, true] as $enabled) {
                RuntimeProbeRunner::setUseRustFfi($enabled);
                try {
                    RuntimeProbeRunner::resolveProbeBatchStatus($invalidResults);
                    $this->fail('Malformed probe results must preserve the original PHP TypeError.');
                } catch (TypeError) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $oversize = str_repeat('x', self::MAX_BYTES);
        $this->assertNull(RunVerificationTool::isAllowedCheckNative($oversize));
        RunVerificationTool::setUseRustFfi(true);
        $this->assertFalse(RunVerificationTool::isAllowedCheck($oversize));
    }

    public function test_unsupported_metadata_declines_before_object_hooks_and_preserves_resources_and_references(): void
    {
        $this->nativeFfi();
        $object = new class
        {
            public int $calls = 0;

            public function __serialize(): array
            {
                $this->calls++;

                return ['value' => 'should never serialize'];
            }
        };
        $resource = fopen('php://memory', 'w+');
        $this->assertIsResource($resource);
        try {
            foreach ([['object' => $object], ['resource' => $resource]] as $metadata) {
                $this->assertNull(RuntimeProbeRunner::formatProbeEntryNative('disk', true, 1, null, 'ok', $metadata));
                $this->assertSame(0, $object->calls);
                RuntimeProbeRunner::setUseRustFfi(true);
                $actual = RuntimeProbeRunner::formatProbeEntry('disk', true, 1, null, 'ok', $metadata);
                $this->assertSame($metadata, $actual['metadata']);
                $this->assertSame(0, $object->calls);
            }
            $value = ['marker' => 'before'];
            $metadata = [&$value, &$value];
            $this->assertNull(RuntimeProbeRunner::formatProbeEntryNative('disk', true, 1, null, 'ok', $metadata));
            $actual = RuntimeProbeRunner::formatProbeEntry('disk', true, 1, null, 'ok', $metadata);
            $value['marker'] = 'after';
            $this->assertSame('after', $actual['metadata'][0]['marker']);
            $this->assertSame('after', $actual['metadata'][1]['marker']);
            $cycle = ['marker' => 'before'];
            $cycle['self'] = &$cycle;
            $this->assertNull(RuntimeProbeRunner::formatProbeEntryNative('disk', true, 1, null, 'ok', $cycle));
            $actual = RuntimeProbeRunner::formatProbeEntry('disk', true, 1, null, 'ok', $cycle);
            $cycle['marker'] = 'after';
            $this->assertSame('after', $actual['metadata']['self']['marker']);
        } finally {
            fclose($resource);
        }
    }

    public function test_deterministic_randomized_native_parity_across_all_helpers(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(23022027));
        for ($case = 0; $case < 200; $case++) {
            $length = $random->getInt(0, 128);
            $binary = $length > 0 ? $random->getBytes($length) : '';
            $utf8 = str_repeat('é🙂log ', $random->getInt(0, 30));
            $integer = $case % 3 === 0 ? PHP_INT_MAX : ($case % 3 === 1 ? PHP_INT_MIN : $random->getInt(-10000, 10000));
            $flag = $random->getInt(0, 1) === 1;
            $metadata = ['bytes' => $binary, 'number' => $integer, 'float' => $random->getInt(-1000, 1000) / 7.0,
                'nested' => ['flag' => $flag, 'null' => null, 'list' => [$binary, $integer, false]], '01' => $binary];
            $rows = array_fill(0, $random->getInt(0, 12), ['ok' => true]);
            if ($flag) {
                $rows[] = ['ok' => false, 'metadata' => $metadata];
            }
            $vectors = [
                [ProcessRunner::class, 'limitOutput', [$flag ? $binary : $utf8, $flag ? strlen($binary) + 1 : $random->getInt(-1, 128)]],
                [ProcessRunner::class, 'buildProcessResult', [[$binary, 'php'], $flag ? null : $integer, $integer, $binary, $binary, $flag]],
                [RuntimeProbeRunner::class, 'deduplicateIdentifiers', [[$binary, $utf8, $binary, 'probe-'.$case]]],
                [RuntimeProbeRunner::class, 'resolveProbeBatchStatus', [$rows]],
                [RuntimeProbeRunner::class, 'formatProbeEntry', [$binary, $flag, $integer, $flag ? null : $binary, $binary, $metadata]],
                [ProjectInstructionsResource::class, 'normalizeInstructionPaths', [['path-'.$binary, '.ai/rules/'.$case.'.md', 'path-'.$binary]]],
                [ProjectInstructionsResource::class, 'assembleInstructionDocument', [[$flag ? $binary : $utf8], $flag ? strlen($binary) + 1 : $random->getInt(-1, 128), $integer]],
                [ProbeRuntimeTool::class, 'buildProbeToolSummary', [$integer, $flag ? 0 : $integer, $integer]],
                [RunVerificationTool::class, 'formatVerificationSummary', [$binary, $integer]],
                [RunVerificationTool::class, 'isAllowedCheck', [$flag ? 'phpunit' : $binary]],
            ];
            foreach ($vectors as [$class, $method, $args]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $expected = $class::$php(...$args);
                $actual = $class::$native(...$args);
                $this->assertNotNull($actual, $class.'::'.$native.' randomized case '.$case);
                $this->assertSame(serialize($expected), serialize($actual), $class.'::'.$method.' randomized case '.$case);
            }
        }
    }

    public function test_truncation_encoding_guards_are_conditional_and_byte_preserving(): void
    {
        $this->nativeFfi();
        foreach (['UTF-8', 'ISO-8859-1', 'UTF-16LE'] as $encoding) {
            mb_internal_encoding($encoding);
            foreach (["a\0\xffb", 'é🙂last'] as $value) {
                $this->assertSame([$value, false], ProcessRunner::limitOutputNative($value, strlen($value)));
                $this->assertSame(ProjectInstructionsResource::assembleInstructionDocumentPhp([$value], strlen($value), 2), ProjectInstructionsResource::assembleInstructionDocumentNative([$value], strlen($value), 2));
                if ($encoding !== 'UTF-8' || ! mb_check_encoding($value, 'UTF-8')) {
                    $this->assertNull(ProcessRunner::limitOutputNative($value, 1));
                    $this->assertNull(ProjectInstructionsResource::assembleInstructionDocumentNative([$value], 1, 2));
                }
                ProcessRunner::setUseRustFfi(true);
                ProjectInstructionsResource::setUseRustFfi(true);
                $this->assertSame(ProcessRunner::limitOutputPhp($value, 1), ProcessRunner::limitOutput($value, 1));
                $this->assertSame(ProjectInstructionsResource::assembleInstructionDocumentPhp([$value], 1, 2), ProjectInstructionsResource::assembleInstructionDocument([$value], 1, 2));
            }
        }
    }

    public function test_c_abi_rejects_malformed_shapes_lengths_and_unsupported_serialization_without_writing(): void
    {
        $ffi = $this->nativeFfi();
        $out = $ffi->new('char[128]');
        $malformed = ['', 'N;', 'a:1:{', 'a:0:{}trailing', 'a:99999999999999999999:{}',
            'a:1:{i:0;s:99999999999999999999:"x";}', 'a:1:{i:0;i:9223372036854775808;}',
            'a:1:{i:0;i:-9223372036854775809;}', serialize([new \stdClass]),
            'a:2:{i:0;s:1:"a";i:1;R:2;}', serialize(['check' => 'phpunit']),
            serialize([1]), serialize(['phpunit', 'extra']),
            'a:1:{s:1:"0";s:7:"phpunit";}', 'a:1:{i:1;s:7:"phpunit";}',
            'a:2:{i:0;s:7:"phpunit";i:0;s:7:"phpunit";}'];
        foreach ($malformed as $input) {
            FFI::memset($out, 0x5A, 128);
            $this->assertSame(-1, self::abi($ffi, 9, $input, strlen($input), $out, 128), bin2hex($input));
            $this->assertSame(str_repeat('Z', 128), FFI::string($out, 128));
        }
        $valid = serialize(['phpunit']);
        foreach ([10, 4_294_967_295] as $operation) {
            $this->assertSame(-1, self::abi($ffi, $operation, $valid, strlen($valid), $out, 128));
        }
        foreach ([0, 1, self::MAX_BYTES + 1, PHP_INT_MAX] as $length) {
            $this->assertSame(-1, self::abi($ffi, 9, null, $length, $out, 128));
        }
        foreach ([self::MAX_BYTES + 1, PHP_INT_MAX, -1] as $length) {
            // The advertised extent is rejected before dereferencing a tiny input.
            $this->assertSame(-1, self::abi($ffi, 9, $valid, $length, $out, 128));
            $this->assertSame(-1, self::abi($ffi, 9, $valid, strlen($valid), $out, $length));
        }
        $this->assertSame(-1, self::abi($ffi, 9, $valid, strlen($valid), null, 1));
        // Wrapping pointer extents must fail checked_add before forming a slice.
        $wrapping = $ffi->cast('char *', -8);
        $this->assertSame(-1, self::abi($ffi, 9, $wrapping, 32, $out, 128));
        $this->assertSame(-1, self::abi($ffi, 9, $valid, strlen($valid), $wrapping, 128));
        $this->assertSame(str_repeat('Z', 128), FFI::string($out, 128));

        // Every operation validates its own argument count and types.
        foreach (range(0, 9) as $operation) {
            $input = serialize([]);
            $this->assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), $out, 128));
        }
        foreach ([0 => [1, 2], 1 => ['command', 0, 1, 'passed', '', false], 2 => [[1]],
            3 => [[['ok' => 1]]], 4 => ['disk', 1, 1, null, '', []], 5 => [[1]],
            6 => [['section'], '1', 1], 7 => [1, false, 1], 8 => ['passed', '1'], 9 => [false]] as $operation => $arguments) {
            $input = serialize($arguments);
            $this->assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), $out, 128));
            $this->assertSame(str_repeat('Z', 128), FFI::string($out, 128));
        }
    }

    public function test_c_abi_short_zero_exact_misaligned_and_overlapping_byte_buffers(): void
    {
        $ffi = $this->nativeFfi();
        $input = serialize(['failed', 3]);
        $expected = serialize('Verification failed with 3 result(s).');
        $needed = strlen($expected);
        $out = $ffi->new('char['.($needed + 2).']');
        FFI::memset($out, 0x5A, $needed + 2);
        $this->assertSame(-1, self::abi($ffi, 8, $input, strlen($input), null, 0));
        $this->assertSame(-$needed, self::abi($ffi, 8, $input, strlen($input), $out, 0));
        $this->assertSame(-$needed, self::abi($ffi, 8, $input, strlen($input), $out, $needed - 1));
        $this->assertSame(str_repeat('Z', $needed + 2), FFI::string($out, $needed + 2));
        // char pointers have alignment 1: odd addresses are valid, unlike typed outputs.
        $unalignedOutput = FFI::addr($out[1]);
        $inputBacking = $ffi->new('char['.(strlen($input) + 2).']');
        $unalignedInput = FFI::addr($inputBacking[1]);
        FFI::memcpy($unalignedInput, $input, strlen($input));
        $this->assertSame($needed, self::abi($ffi, 8, $unalignedInput, strlen($input), $unalignedOutput, $needed));
        $this->assertSame($expected, FFI::string($unalignedOutput, $needed));
        $this->assertSame('Z', $out[0]);
        $this->assertSame('Z', $out[$needed + 1]);

        $backing = $ffi->new('char[512]');
        FFI::memset($backing, 0x5A, 512);
        $source = FFI::addr($backing[128]);
        FFI::memcpy($source, $input, strlen($input));
        $before = FFI::string($backing, 512);
        foreach ([128, 129, 127] as $offset) {
            $this->assertSame(-1, self::abi($ffi, 8, $source, strlen($input), FFI::addr($backing[$offset]), 128));
            $this->assertSame($before, FFI::string($backing, 512), 'Overlapping buffers must never be partly written.');
        }
    }

    public function test_real_process_runner_redacts_before_configured_limit_and_preserves_status(): void
    {
        config()->set('nntmux-mcp.limits.max_process_output_bytes', 32);
        foreach ([false, true] as $enabled) {
            ProcessRunner::setUseRustFfi($enabled);
            $runner = new ProcessRunner(new SensitiveValueRedactor);
            $command = [PHP_BINARY, '-r', 'echo "API_TOKEN=".getenv("MCP_TEST_SECRET")." ".str_repeat("x", 80);'];
            $result = $runner->run($command, 5, ['MCP_TEST_SECRET' => 'private-token-do-not-log']);
            $this->assertSame($command, $result['command']);
            $this->assertSame('passed', $result['status']);
            $this->assertSame(0, $result['exit_code']);
            $this->assertIsInt($result['duration_ms']);
            $this->assertGreaterThanOrEqual(0, $result['duration_ms']);
            $this->assertSame('API_TOKEN=[REDACTED] '.str_repeat('x', 11).'\n[output truncated]', $result['output']);
            $this->assertTrue($result['truncated']);
            $failure = $runner->run([PHP_BINARY, '-r', 'fwrite(STDERR, "failed"); exit(3);'], 5);
            $this->assertSame('failed', $failure['status']);
            $this->assertSame(3, $failure['exit_code']);
            $this->assertSame('failed', $failure['output']);
            $timeout = $runner->run([PHP_BINARY, '-r', 'usleep(2000000);'], 1);
            $this->assertSame('timed_out', $timeout['status']);
            $this->assertNull($timeout['exit_code']);
        }
    }

    public function test_real_probe_runner_deduplicates_and_redacts_before_formatting_and_logging(): void
    {
        foreach ([false, true] as $enabled) {
            RuntimeProbeRunner::setUseRustFfi($enabled);
            $container = new Container;
            $database = Mockery::mock(ServiceProbeInterface::class);
            $disk = Mockery::mock(ServiceProbeInterface::class);
            $database->shouldReceive('probe')->once()->andReturn(new ProbeResult(true, 2, null, 'API_TOKEN=private-db-token', ['api_token' => 'private-metadata', 'nested' => ['dsn' => 'redis://user:pass@redis']]));
            $disk->shouldReceive('probe')->once()->andReturn(new ProbeResult(false, 9, IncidentImpactEnum::Minor, 'disk unavailable', ['free' => 0]));
            $container->instance(DatabaseProbe::class, $database);
            $container->instance(DiskProbe::class, $disk);
            $logger = Mockery::mock(LoggerInterface::class);
            $logger->shouldReceive('info')->once()->with('MCP tool completed', Mockery::on(fn (array $context): bool => $context['status'] === 'failed' && $context['safe_paths'] === [] && $context['truncated'] === false));
            Log::shouldReceive('channel')->once()->with('nntmux_mcp')->andReturn($logger);
            $results = (new RuntimeProbeRunner($container, new SensitiveValueRedactor))->run(['database', 'disk', 'database']);
            $this->assertSame(['database', 'disk'], array_column($results, 'id'));
            $this->assertSame('API_TOKEN=[REDACTED]', $results[0]['reason']);
            $this->assertSame('[REDACTED]', $results[0]['metadata']['api_token']);
            $this->assertSame('redis://[REDACTED]@redis', $results[0]['metadata']['nested']['dsn']);
            $this->assertSame('minor', $results[1]['impact']);
        }
    }

    public function test_real_mcp_tool_dispatch_preserves_versioned_payloads_and_validation(): void
    {
        config()->set('nntmux-mcp.schema_version', 7);
        foreach ([false, true] as $enabled) {
            ProbeRuntimeTool::setUseRustFfi($enabled);
            RunVerificationTool::setUseRustFfi($enabled);
            $probes = Mockery::mock(RuntimeProbeRunner::class);
            $probes->shouldReceive('run')->once()->with(['disk'])->andReturn([
                ['id' => 'disk', 'ok' => false, 'latency_ms' => 1, 'impact' => 'minor', 'reason' => 'full', 'metadata' => []],
            ]);
            $this->app->instance(RuntimeProbeRunner::class, $probes);
            NewznabTmuxServer::tool(ProbeRuntimeTool::class, ['probes' => ['disk']])->assertOk()->assertStructuredContent(function (AssertableJson $json): void {
                $json->where('schema_version', 7)->where('summary', 'Completed 1 probe(s); 1 reported unhealthy.')
                    ->where('warnings', ['One or more selected runtime services reported unhealthy.'])
                    ->where('truncated', false)->where('results.0.id', 'disk')->etc();
            });
            $verification = Mockery::mock(VerificationRunner::class);
            $verification->shouldReceive('run')->once()->with('diff', ['composer.json'], null, null)->andReturn([
                'status' => 'passed', 'results' => [['status' => 'passed']], 'warnings' => [], 'truncated' => false,
            ]);
            $this->app->instance(VerificationRunner::class, $verification);
            NewznabTmuxServer::tool(RunVerificationTool::class, ['check' => 'diff', 'paths' => ['composer.json']])->assertOk()->assertStructuredContent(function (AssertableJson $json): void {
                $json->where('schema_version', 7)->where('summary', 'Verification passed with 1 result(s).')
                    ->where('results.status', 'passed')->where('warnings', [])->where('truncated', false)->etc();
            });
            // The mocks have no spare calls: validation cannot invoke a runtime probe or command.
            foreach ([[], ['probes' => []], ['probes' => ['disk', 'disk']], ['probes' => ['unknown']], ['probes' => [1]]] as $input) {
                NewznabTmuxServer::tool(ProbeRuntimeTool::class, $input)->assertHasErrors();
            }
            foreach ([['check' => 'shell', 'paths' => ['composer.json']], ['check' => 'diff', 'paths' => []], ['check' => 'diff', 'paths' => ['composer.json', 'composer.json']], ['check' => 'diff', 'paths' => ['composer.json'], 'filter' => str_repeat('x', 201)]] as $input) {
                NewznabTmuxServer::tool(RunVerificationTool::class, $input)->assertHasErrors();
            }
        }
    }

    public function test_real_instruction_resource_uses_configured_schema_and_byte_bound(): void
    {
        config()->set('nntmux-mcp.schema_version', 9);
        config()->set('nntmux-mcp.limits.max_resource_bytes', 17);
        foreach ([false, true] as $enabled) {
            ProjectInstructionsResource::setUseRustFfi($enabled);
            NewznabTmuxServer::resource(ProjectInstructionsResource::class)->assertOk()
                ->assertSee(['<!-- schema_version: 9 -->', '<!-- truncated: true -->', '[project instructions truncated]']);
        }
    }

    public function test_verification_validation_errors_match_the_original_allowlist_rule(): void
    {
        $runner = $this->createMock(VerificationRunner::class);
        $runner->expects($this->never())->method('run');
        $original = ['required', 'string', 'in:targeted,phpunit,phpstan,pint,php-lint,diff,composer,frontend'];
        $knownStringable = new class
        {
            public function __toString(): string
            {
                return 'diff';
            }
        };
        $unknownStringable = new class
        {
            public function __toString(): string
            {
                return 'shell';
            }
        };
        foreach ([false, true] as $enabled) {
            RunVerificationTool::setUseRustFfi($enabled);
            foreach (['shell', ['diff'], 123, null, '', true, 'PHPUNIT', ' phpunit', $knownStringable, $unknownStringable] as $invalid) {
                $input = ['check' => $invalid, 'paths' => ['composer.json']];
                $expected = Validator::make($input, ['check' => $original])->errors()->toArray();
                $this->assertNotEmpty($expected);
                try {
                    (new RunVerificationTool)->handle(new Request($input), $runner);
                    $this->fail('An invalid check must not reach VerificationRunner.');
                } catch (ValidationException $exception) {
                    $this->assertSame($expected, $exception->errors());
                }
            }
        }
    }

    public function test_paired_benchmark(): void
    {
        if (getenv('MCP_TOOL_RUNNER_BENCHMARK') !== '1') {
            $this->markTestSkipped('Opt in with MCP_TOOL_RUNNER_BENCHMARK=1.');
        }
        $this->nativeFfi();
        $path = '/tmp/wave23-2-mcp-tool-runner-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        self::benchmark($path);
        $this->assertFileExists($path);
        fwrite(STDERR, "\nMCP helper benchmark raw evidence: {$path}\n");
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('mcp-tool-runner-test', ProcessRunner::FFI_CDEF);
        if ($ffi === null) {
            if (getenv('MCP_TOOL_RUNNER_BENCHMARK') === '1') {
                $this->fail('Benchmark invalid: the MCP tool runner native export is unavailable.');
            }
            $this->markTestSkipped('Build the release library with the MCP tool runner export for native parity.');
        }

        return $ffi;
    }

    private static function abi(FFI $ffi, int $operation, string|CData|null $input, int $length, ?CData $output, int $capacity): int
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w23_mcp_tool_runner($operation, $input, $length, $output, $capacity);
    }

    /** @return list<array{class-string, string, array<mixed>}> */
    private static function parityVectors(): array
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            foreach ([...$common, ...$uncommon] as $args) {
                $vectors[] = [$class, $method, $args];
            }
        }
        foreach ([['', 0], ['é🙂tail', 1], ['é🙂tail', 2], ['é🙂tail', 5], ['é🙂tail', 6], ['é🙂tail', PHP_INT_MAX], ["a\0\xff", 3]] as $args) {
            $vectors[] = [ProcessRunner::class, 'limitOutput', $args];
        }
        $vectors[] = [ProcessRunner::class, 'buildProcessResult', [[], null, PHP_INT_MIN, "\0\xff", "\xff\0", false]];
        $vectors[] = [RuntimeProbeRunner::class, 'deduplicateIdentifiers', [[8 => "\0a", 19 => "\xff", 30 => "\0a"]]];
        $vectors[] = [RuntimeProbeRunner::class, 'resolveProbeBatchStatus', [[]]];
        $vectors[] = [RuntimeProbeRunner::class, 'resolveProbeBatchStatus', [[7 => ['ok' => true, 'ignored' => null]]]];
        $vectors[] = [RuntimeProbeRunner::class, 'formatProbeEntry', ["\xff\0", false, PHP_INT_MAX, '', "\0\xff", ['float' => [0.1, -0.0, INF, -INF, NAN, 1e-12, 1e100], -3 => null, '' => false, '01' => '01']]];
        $vectors[] = [ProjectInstructionsResource::class, 'normalizeInstructionPaths', [['Z', '', "a\0b", "\xff", 'Z', '.']]];
        foreach ([[[], 1, 0], [['1', '2'], 4, -1], [['é🙂'], 3, PHP_INT_MAX], [["\0\xff"], 2, 1]] as $args) {
            $vectors[] = [ProjectInstructionsResource::class, 'assembleInstructionDocument', $args];
        }
        $vectors[] = [ProbeRuntimeTool::class, 'buildProbeToolSummary', [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN]];
        $vectors[] = [RunVerificationTool::class, 'formatVerificationSummary', ["\0\xff", PHP_INT_MIN]];

        return $vectors;
    }

    /** @return array<string, array{class-string, string, list<array<mixed>>, list<array<mixed>>}> */
    private static function workloads(): array
    {
        return [
            'limitOutput' => [ProcessRunner::class, 'limitOutput', [['phpunit passed', 32768], [str_repeat('log ', 128), 32768]], [[str_repeat('é🙂 log ', 4096), 32768], [str_repeat('x', 65536), 1]]],
            'buildProcessResult' => [ProcessRunner::class, 'buildProcessResult', [[['php', '-l', 'app/Test.php'], 0, 14, 'passed', 'No syntax errors detected.', false]], [[['vendor/bin/phpunit', '--filter=Example'], null, 600000, 'timed_out', str_repeat('trace line\n', 4096), true]]],
            'deduplicateIdentifiers' => [RuntimeProbeRunner::class, 'deduplicateIdentifiers', [[['database', 'disk', 'database']], [['nntp', 'redis', 'queue', 'search']]], [[array_map(static fn (int $i): string => 'probe-'.($i % 700), range(0, 2047))]]],
            'resolveProbeBatchStatus' => [RuntimeProbeRunner::class, 'resolveProbeBatchStatus', [[[['ok' => true], ['ok' => true]]]], [[array_merge(array_fill(0, 1023, ['ok' => true]), [['ok' => false]])]]],
            'formatProbeEntry' => [RuntimeProbeRunner::class, 'formatProbeEntry', [['database', true, 2, null, 'Connected', ['latency' => 1.5]]], [['disk', false, 1800, 'critical', str_repeat('unhealthy ', 128), ['nested' => ['paths' => array_fill(0, 128, '/safe/path')], 'bytes' => PHP_INT_MAX, 'null' => null, 'binary' => "\0\xff"]]]],
            'normalizeInstructionPaths' => [ProjectInstructionsResource::class, 'normalizeInstructionPaths', [[['AGENTS.md', '.ai/rules/index.md', '.ai/rules/command-execution.md', 'AGENTS.md']]], [[array_map(static fn (int $i): string => '.ai/rules/rule-'.($i % 200).'.md', range(0, 1023))]]],
            'assembleInstructionDocument' => [ProjectInstructionsResource::class, 'assembleInstructionDocument', [[['# AGENTS.md\n\nGuide', '# .ai/rules/index.md\n\nRules'], 65536, 1]], [[['# AGENTS.md'.str_repeat('é🙂 guidance ', 8192)], 65536, 1]]],
            'buildProbeToolSummary' => [ProbeRuntimeTool::class, 'buildProbeToolSummary', [[2, 0, 1], [6, 1, 1]], [[PHP_INT_MAX, PHP_INT_MAX, -1], [0, 0, 999]]],
            'formatVerificationSummary' => [RunVerificationTool::class, 'formatVerificationSummary', [['passed', 3], ['failed', 1]], [[str_repeat('status ', 128), PHP_INT_MAX], ['timed_out', 0]]],
            'isAllowedCheck' => [RunVerificationTool::class, 'isAllowedCheck', [['phpunit'], ['diff'], ['frontend']], [[str_repeat('unknown', 128)], ["phpunit\0"], ['PHPUNIT']]],
        ];
    }

    /**
     * This entry point also runs without booting Laravel:
     * php -r 'require "tests/bootstrap.php"; require "tests/Unit/RustMcpToolRunnerParityTest.php";
     * Tests\Unit\RustMcpToolRunnerParityTest::benchmark("/tmp/wave23-2-manual.json");'
     */
    public static function benchmark(string $outputPath): void
    {
        $root = dirname(__DIR__, 2);
        $seed = 23022026;
        $report = [
            'protocol' => ['rounds' => 3, 'paired_samples_per_round' => 41, 'calls_per_side_per_sample' => 200,
                'warmup_calls_per_side_per_round' => 10, 'seed' => $seed, 'clock' => 'hrtime(true), individual call ns',
                'p99' => 'nearest-rank over individual calls', 'order' => 'independently randomized PHP/Native side order per pair; identical randomized input indices',
                'scope' => 'Public Php versus Native helper calls, including all Native serialization/FFI/allocation/deserialization; no dispatcher fallback; excludes process startup, probes, I/O, boot and production throughput.',
                'uncertainty' => 'Observed min/max across three rounds; descriptive range, not a confidence interval. 8200 individual calls/side/round; p99 has 82 upper-tail observations/round.',
                'gate' => 'Every helper common and uncommon mean AND p99 speedups must each be >=1.5; no averaging across helpers.',
                'reproduce' => 'MCP_TOOL_RUNNER_BENCHMARK=1 php artisan test --compact tests/Unit/RustMcpToolRunnerParityTest.php --filter=test_paired_benchmark'],
            'base_head' => '775ba6cf8bcfe6b571fb5a850dfc7187c1adaa00',
            'head' => self::command('git rev-parse HEAD'), 'git_status' => self::command('git status --porcelain'),
            'php' => ['version' => PHP_VERSION, 'binary' => PHP_BINARY, 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'mb_encoding' => mb_internal_encoding()],
            'rustc' => self::command('rustc --version'), 'cargo' => self::command('cargo --version'),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.model hw.ncpu hw.memsize' : 'lscpu'),
            'limits' => self::command('ulimit -a'), 'build' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml; locked repository release profile',
            'source_sha256' => [], 'libraries' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.toml', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'app/Support/RustPipelineLibrary.php', 'app/Services/Mcp/ProcessRunner.php', 'app/Services/Mcp/RuntimeProbeRunner.php', 'app/Mcp/Resources/ProjectInstructionsResource.php', 'app/Mcp/Tools/ProbeRuntimeTool.php', 'app/Mcp/Tools/RunVerificationTool.php', 'tests/Unit/RustMcpToolRunnerParityTest.php'] as $file) {
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
            if (RustPipelineLibrary::resolveDomain('mcp-tool-runner', ProcessRunner::FFI_CDEF) === null) {
                throw new RuntimeException('Benchmark invalid: native MCP export unavailable.');
            }
            $report['library_load_ns'] = hrtime(true) - $loadStart;
            foreach ($report['libraries'] as $path => $identity) {
                if ($identity !== null && RustPipelineLibrary::resolveDomain('mcp-tool-benchmark-identity', ProcessRunner::FFI_CDEF, $path) !== null) {
                    $report['selected_library'] = ['path' => $path, ...$identity];
                    break;
                }
            }
            foreach (self::workloads() as $name => [$class, $method, $common, $uncommon]) {
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
