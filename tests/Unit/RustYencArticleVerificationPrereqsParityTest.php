<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NNTP\BoundedArticleResult;
use App\Services\Releases\Verification\AuditReleaseDeletion;
use App\Services\Releases\Verification\DirtyCandidateSettler;
use App\Services\Releases\Verification\Prerequisites;
use App\Services\Yenc\YencPart;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class RustYencArticleVerificationPrereqsParityTest extends TestCase
{
    private const CLASSES = [YencPart::class, BoundedArticleResult::class, Prerequisites::class, DirtyCandidateSettler::class, AuditReleaseDeletion::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(false);
            }
        }
        parent::tearDown();
    }

    public function test_yenc_helpers_preserve_byte_ranges_crc_and_completeness(): void
    {
        foreach ([false, true] as $enabled) {
            YencPart::setUseRustFfi($enabled);
            foreach ([[null, null, 0, null], [1, 1, 0, 1], [3, 7, 2, 5], [0, -1, -1, 0], [9, 3, 8, -5]] as [$begin, $end, $offset, $length]) {
                $part = new YencPart("a\0\xff", 10, 2, $begin, $end);
                self::assertSame($offset, $part->offset());
                self::assertSame($offset, $part->offsetNative());
                self::assertSame($length, $part->length());
                self::assertSame($length, $part->lengthNative());
                self::assertSame(['name' => "a\0\xff", 'size' => 10, 'part' => 2, 'begin' => $begin, 'end' => $end], $part->metadata());
                self::assertSame($part->metadataPhp(), $part->metadataNative());
            }
            foreach ([[null, null, 10, false], [1, 10, 10, true], [0, 10, 10, false], [2, 1, 10, false], [1, 11, 10, false], [1, 1, PHP_INT_MAX, true], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, true]] as [$begin, $end, $size, $valid]) {
                self::assertSame($valid, YencPart::validSpan($begin, $end, $size));
                self::assertSame($valid, YencPart::validSpanNative($begin, $end, $size));
            }
            foreach (['' => null, 'a' => '0000000a', 'DEADBEEF' => 'deadbeef', '000000000' => null, ' ff ' => null, "\0" => null, 'xyz' => null] as $crc => $expected) {
                self::assertSame($expected, YencPart::normalizeCrc32($crc));
                self::assertSame($expected, YencPart::normalizeCrc32Native($crc));
            }
            foreach ([['', 0], ["a\0\xff", 3], ['é', 2]] as [$bytes, $length]) {
                self::assertSame($length, YencPart::byteLength($bytes));
                self::assertSame($length, YencPart::byteLengthNative($bytes));
            }
            foreach ([[new YencPart('a', 3), 'abc', true], [new YencPart('a', 3), 'ab', false], [new YencPart('a', 10, 2, 3, 5), 'abc', true], [new YencPart('a', 10, 2, 0, 2), 'abc', false], [new YencPart('a', 0), '', true], [new YencPart('a', -1), '', false]] as [$part, $bytes, $complete]) {
                self::assertSame($complete, $part->isComplete($bytes));
                self::assertSame($complete, $part->isCompleteNative($bytes));
            }
        }
    }

    /** @param list<mixed> $arguments */
    private static function invoke(object|string $target, string $method, array $arguments): mixed
    {
        return is_object($target) ? $target->$method(...$arguments) : $target::$method(...$arguments);
    }

    /**
     * Each row includes the exact operation arguments, including instance state.
     *
     * @return list<array<mixed>>
     */
    private static function cases(): array
    {
        $part = new YencPart("a\0\xff", 10, 2, 3, 5);
        $empty = new YencPart('', 0);
        $success = BoundedArticleResult::success('', $part);
        $failure = BoundedArticleResult::failure("bad\0", true);
        $paths = array_fill(0, 9, '/artifact');
        $receipt = ['guid' => 'g', 'path' => '/artifact'];
        $inventory = ['version' => 1, 'nzb_receipt' => $receipt, 'paths' => $paths];
        $rows = [
            [$part, 'offset', [], 2, 0, [3]],
            [$empty, 'offset', [], 0, 0, [null]],
            [$part, 'length', [], 3, 1, [3, 5]],
            [$empty, 'length', [], null, 1, [null, null]],
            [YencPart::class, 'byteLength', ["a\0\xff"], 3, 2, ["a\0\xff"]],
            [YencPart::class, 'byteLength', [''], 0, 2, ['']],
            [YencPart::class, 'validSpan', [1, PHP_INT_MAX, PHP_INT_MAX], true, 3, [1, PHP_INT_MAX, PHP_INT_MAX]],
            [YencPart::class, 'validSpan', [null, 0, -1], false, 3, [null, 0, -1]],
            [YencPart::class, 'normalizeCrc32', ['AbCd'], '0000abcd', 4, ['AbCd']],
            [YencPart::class, 'normalizeCrc32', [null], null, 4, [null]],
            [$part, 'isComplete', ['abc'], true, 5, [3, 3, 5, 10]],
            [$empty, 'isComplete', ['x'], false, 5, [1, null, null, 0]],
            [$part, 'metadata', [], ['name' => "a\0\xff", 'size' => 10, 'part' => 2, 'begin' => 3, 'end' => 5], 6, ["a\0\xff", 10, 2, 3, 5]],
            [$empty, 'metadata', [], ['name' => '', 'size' => 0, 'part' => null, 'begin' => null, 'end' => null], 6, ['', 0, null, null, null]],
            [$success, 'outcome', [], 'success', 10, [true, null, false]],
            [$failure, 'outcome', [], 'oversized', 10, [false, "bad\0", true]],
            [BoundedArticleResult::class, 'exceedsByteLimit', ['', -1], true, 11, [0, -1]],
            [BoundedArticleResult::class, 'exceedsByteLimit', ["a\0", 2], false, 11, [2, 2]],
            [$success, 'isSuccess', [], true, 12, [true, null]],
            [$failure, 'isSuccess', [], false, 12, [false, "bad\0"]],
            [$success, 'hasMetadata', [], true, 13, [true]],
            [$failure, 'hasMetadata', [], false, 13, [false]],
            [Prerequisites::class, 'isExplicitPath', ['par2'], false, 20, ['par2']],
            [Prerequisites::class, 'isExplicitPath', ["a\\b\0"], true, 20, ["a\\b\0"]],
            [DirtyCandidateSettler::class, 'settleWindow', [0, -2.0, 10.0], ['limit' => 1, 'deadline' => 10.0], 30, [0, -2.0, 10.0]],
            [DirtyCandidateSettler::class, 'settleWindow', [PHP_INT_MAX, 0.25, 1024.0], ['limit' => PHP_INT_MAX, 'deadline' => 1024.25], 30, [PHP_INT_MAX, 0.25, 1024.0]],
            [DirtyCandidateSettler::class, 'cursorPosition', [['at' => "\0", 'id' => ' +2.5e1 '], 'at', 'id'], ['at' => "\0", 'id' => 25], 31, [['at' => "\0", 'id' => ' +2.5e1 '], 'at', 'id']],
            [DirtyCandidateSettler::class, 'cursorPosition', [['at' => '', 'id' => 'NaN'], 'at', 'id'], null, 31, [['at' => '', 'id' => 'NaN'], 'at', 'id']],
            [DirtyCandidateSettler::class, 'sourceHash', [str_repeat('Aa', 20)], str_repeat("\xaa", 20), 32, [str_repeat('Aa', 20)]],
            [DirtyCandidateSettler::class, 'sourceHash', ['g'.str_repeat('0', 39)], null, 32, ['g'.str_repeat('0', 39)]],
            [AuditReleaseDeletion::class, 'inventoryPayload', [$receipt, $paths], $inventory, 40, [$receipt, $paths]],
            [AuditReleaseDeletion::class, 'inventoryPayload', [[], [3 => "\0\xff"]], ['version' => 1, 'nzb_receipt' => [], 'paths' => [3 => "\0\xff"]], 40, [[], [3 => "\0\xff"]]],
            [AuditReleaseDeletion::class, 'inventoryReason', [$inventory, 'g'], null, 41, [$inventory, 'g']],
            [AuditReleaseDeletion::class, 'inventoryReason', [[], 'g'], 'Legacy audit artifact ownership is unqualified; retaining it for review.', 41, [[], 'g']],
            [AuditReleaseDeletion::class, 'isTerminalEligible', ['0', 0, 'unrepairable'], false, 42, ['0', 0, 'unrepairable']],
            [AuditReleaseDeletion::class, 'isTerminalEligible', [[false], '', 'unrepairable'], true, 42, [[false], '', 'unrepairable']],
        ];
        foreach ([null, false, 1, ['version' => '1'], ['version' => 1, 'nzb_receipt' => [], 'paths' => []]] as $value) {
            $rows[] = [AuditReleaseDeletion::class, 'inventoryReason', [$value, 'g'], 'Audit artifact inventory is unavailable.', 41, [$value, 'g']];
        }
        foreach ([['guid' => 'other', 'path' => '/artifact'], ['guid' => 'g', 'path' => 'different']] as $bad) {
            $value = ['version' => 1, 'nzb_receipt' => $bad, 'paths' => $paths];
            $rows[] = [AuditReleaseDeletion::class, 'inventoryReason', [$value, 'g'], 'Audit NZB receipt does not match its retained inventory.', 41, [$value, 'g']];
        }
        foreach (['', null, 0] as $path) {
            $value = ['version' => 1, 'nzb_receipt' => ['guid' => 'g', 'path' => $path], 'paths' => [$path, ...array_fill(0, 8, 'x')]];
            $rows[] = [AuditReleaseDeletion::class, 'inventoryReason', [$value, 'g'], 'Audit NZB artifact path is invalid.', 41, [$value, 'g']];
        }

        return $rows;
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', YencPart::FFI_CDEF);
        self::assertNotNull($ffi, 'Release library must be available; fallback is not parity proof');

        return $ffi;
    }

    /** @param list<mixed> $arguments */
    private static function nativeWire(int $operation, array $arguments): mixed
    {
        $ffi = self::ffi();
        $input = serialize($arguments);
        $output = $ffi->new('char[262144]');
        // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
        $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, 262144);
        if ($written < -1) {
            $capacity = -$written;
            $output = $ffi->new('char['.$capacity.']');
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
        }
        self::assertGreaterThanOrEqual(2, $written, 'Native must accept valid null-producing inputs too');

        return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
    }

    public function test_every_helper_matches_literal_contract_with_both_dispatch_settings(): void
    {
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            foreach (self::cases() as [$target, $method, $args, $expected, $op, $wire]) {
                self::assertSame($expected, self::invoke($target, $method.'Php', $args), $method.' PHP');
                self::assertSame($expected, self::invoke($target, $method.'Native', $args), $method.' direct native (independent toggle)');
                self::assertSame($expected, self::nativeWire($op, $wire), $method.' ABI');
                self::assertSame($expected, self::invoke($target, $method, $args), $method.' public');
            }
        }
    }

    public function test_real_readonly_factories_and_production_defaults(): void
    {
        foreach (self::CLASSES as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->hasProperty('useRustFfi')) {
                self::assertFalse($reflection->getDefaultProperties()['useRustFfi']);
            } else {
                self::assertFalse($reflection->getMethod('useRustFfi')->getStaticVariables()['useRustFfi']);
            }
        }
        foreach ([false, true] as $enabled) {
            BoundedArticleResult::setUseRustFfi($enabled);
            YencPart::setUseRustFfi($enabled);
            $part = new YencPart('file', 3);
            $result = BoundedArticleResult::success("a\0b", $part);
            self::assertTrue((new \ReflectionClass($result))->isReadOnly());
            self::assertTrue((new \ReflectionClass($part))->isReadOnly());
            self::assertSame("a\0b", $result->body);
            self::assertSame($part, $result->part);
            self::assertNull($result->error);
            self::assertFalse($result->oversized);
            self::assertTrue($part->isComplete($result->body));
            foreach ([false, true] as $oversized) {
                $failed = BoundedArticleResult::failure('', $oversized);
                self::assertNull($failed->body);
                self::assertNull($failed->part);
                self::assertSame('', $failed->error);
                self::assertFalse($failed->isSuccess());
                self::assertFalse($failed->hasMetadata());
                self::assertSame($oversized ? 'oversized' : 'failure', $failed->outcome());
            }
        }
    }

    public function test_abi_declines_invalid_ranges_wire_and_short_capacity_without_writes(): void
    {
        $ffi = self::ffi();
        $out = $ffi->new('char[32]');
        FFI::memset($out, 90, 32);
        $wire = serialize([null]);
        foreach ([[999, $wire, strlen($wire), $out, 32], [0, 'not-wire', 8, $out, 32], [0, $wire.'x', strlen($wire) + 1, $out, 32], [0, serialize(['bad']), 18, $out, 32], [0, null, 1, $out, 32], [0, $wire, PHP_INT_MAX, $out, 32], [0, $wire, strlen($wire), null, 1], [0, $wire, strlen($wire), $out, PHP_INT_MAX]] as $args) {
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            self::assertSame(-1, $ffi->nntmux_w19_verification_prereqs(...$args));
            self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
        }
        $needed = strlen(serialize(0));
        // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
        self::assertSame(-$needed, $ffi->nntmux_w19_verification_prereqs(0, $wire, strlen($wire), $out, 1));
        self::assertSame(str_repeat('Z', 32), FFI::string($out, 32));
        // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
        self::assertSame(-$needed, $ffi->nntmux_w19_verification_prereqs(0, $wire, strlen($wire), null, 0));
    }

    public function test_unsupported_values_decline_and_public_dispatch_falls_back(): void
    {
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \LogicException('Serialization must never be invoked');
            }
        };
        $deep = 'leaf';
        for ($i = 0; $i < 30; $i++) {
            $deep = [$deep];
        }
        $cycle = [];
        $cycle['self'] = &$cycle;
        foreach ([$object, $deep, $cycle] as $unsupported) {
            AuditReleaseDeletion::setUseRustFfi(true);
            DirtyCandidateSettler::setUseRustFfi(true);
            self::assertNull(AuditReleaseDeletion::isTerminalEligibleNative($unsupported, 1, 'unrepairable'));
            self::assertTrue(AuditReleaseDeletion::isTerminalEligible($unsupported, 1, 'unrepairable'));
            self::assertNull(DirtyCandidateSettler::sourceHashNative($unsupported));
            self::assertNull(DirtyCandidateSettler::sourceHash($unsupported));
            self::assertNull(AuditReleaseDeletion::inventoryPayloadNative(['unsupported' => $unsupported], []));
            $fallback = AuditReleaseDeletion::inventoryPayload(['unsupported' => $unsupported], []);
            self::assertSame(1, $fallback['version']);
            self::assertSame([], $fallback['paths']);
        }
        $fallback = AuditReleaseDeletion::inventoryPayload(['object' => $object], []);
        self::assertSame($object, $fallback['nzb_receipt']['object']);
        foreach ([[1, INF, 0.0], [1, 1.0, -INF], [1, PHP_FLOAT_MAX, PHP_FLOAT_MAX]] as $args) {
            self::assertNull(DirtyCandidateSettler::settleWindowNative(...$args));
            self::assertSame(DirtyCandidateSettler::settleWindowPhp(...$args), DirtyCandidateSettler::settleWindow(...$args));
        }
        self::assertNull(DirtyCandidateSettler::settleWindowNative(1, 0.0, NAN));
        self::assertNan(DirtyCandidateSettler::settleWindow(1, 0.0, NAN)['deadline']);
        // PHP integer arithmetic can overflow to float and violate these declared returns.
        foreach ([['offset', new YencPart('', 1, null, PHP_INT_MIN)], ['length', new YencPart('', 1, null, PHP_INT_MIN, PHP_INT_MAX)]] as [$method, $part]) {
            self::assertNull(self::invoke($part, $method.'Native', []));
            YencPart::setUseRustFfi(true);
            try {
                self::invoke($part, $method, []);
                self::fail('Overflow must preserve PHP TypeError');
            } catch (\TypeError $error) {
                self::assertStringContainsString('int', $error->getMessage());
            }
        }
    }

    /**
     * Supported uncommon inputs keep large byte payload and serialization costs in scope.
     *
     * @return array<string, list<array<mixed>>>
     */
    private static function benchmarkGroups(): array
    {
        $groups = [];
        foreach (self::cases() as $case) {
            $groups[$case[1]][] = $case;
        }
        $large = str_repeat("x\0\xff", 8192);
        $part = new YencPart($large, strlen($large), PHP_INT_MAX, 1, strlen($large));
        $paths = array_fill(0, 9, '/'.$large);
        $receipt = ['guid' => 'g', 'path' => $paths[0], 'extra' => array_fill(0, 16, $large)];
        $inventory = ['version' => 1, 'nzb_receipt' => $receipt, 'paths' => $paths];
        foreach ([
            [$part, 'metadata', [], 6, [$large, strlen($large), PHP_INT_MAX, 1, strlen($large)]],
            [$part, 'isComplete', [$large], 5, [strlen($large), 1, strlen($large), strlen($large)]],
            [YencPart::class, 'byteLength', [$large], 2, [$large]],
            [BoundedArticleResult::class, 'exceedsByteLimit', [$large, strlen($large)], 11, [strlen($large), strlen($large)]],
            [Prerequisites::class, 'isExplicitPath', [$large.'/par2'], 20, [$large.'/par2']],
            [DirtyCandidateSettler::class, 'cursorPosition', [['at' => $large, 'id' => '-9223372036854775808'], 'at', 'id'], 31, [['at' => $large, 'id' => '-9223372036854775808'], 'at', 'id']],
            [AuditReleaseDeletion::class, 'inventoryPayload', [$receipt, $paths], 40, [$receipt, $paths]],
            [AuditReleaseDeletion::class, 'inventoryReason', [$inventory, 'g'], 41, [$inventory, 'g']],
        ] as [$target, $method, $args, $operation, $wire]) {
            $groups[$method][] = [$target, $method, $args, self::invoke($target, $method.'Php', $args), $operation, $wire];
        }

        return $groups;
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_YENC_ARTICLE_PREREQS_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_YENC_ARTICLE_PREREQS_BENCH=1');
        }
        self::ffi();
        $random = new Randomizer(new Mt19937(1902));
        $destination = tempnam('/tmp', 'nntmux-wave19-yenc-article-prereqs-');
        self::assertNotFalse($destination);
        unlink($destination);
        $destination .= '.json';
        $root = dirname(__DIR__, 2);
        $paths = ['rust/pipeline-ffi/src/nfo.rs', 'rust/pipeline-ffi/Cargo.lock', 'rust/pipeline-ffi/Cargo.toml', 'composer.lock', 'vendor/composer/installed.php', 'tests/Unit/RustYencArticleVerificationPrereqsParityTest.php'];
        foreach (self::CLASSES as $class) {
            $paths[] = substr((new \ReflectionClass($class))->getFileName(), strlen($root) + 1);
        }
        $paths[] = 'app/Support/RustPipelineLibrary.php';
        $library = $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $hashes = array_combine($paths, array_map(static fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), $paths));
        $metadata = ['utc' => gmdate(DATE_ATOM), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'build_command' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml', 'profile' => 'release; see frozen Cargo.toml', 'seed' => 1902, 'samples' => 3000, 'warmup_pairs' => 64, 'php' => PHP_VERSION, 'os' => php_uname(), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'limits' => (string) shell_exec('ulimit -a'), 'rustc' => trim((string) shell_exec('rustc --version')), 'memory_limit' => ini_get('memory_limit'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'source_sha256' => $hashes, 'library_sha256' => hash_file('sha256', $library), 'inputs_base64_php_serialized' => base64_encode(serialize(self::benchmarkGroups())), 'protocol' => 'Same-input individually timed public dispatcher pairs; randomized order; toggle and parity outside timing; full FFI serialization/scratch overhead; nearest-rank p99 with approximate 95% rank uncertainty; no production I/O.'];
        $results = [];
        $failure = null;
        try {
            foreach (self::benchmarkGroups() as $method => $fixtures) {
                foreach (['common' => [$fixtures[0]], 'uncommon' => array_slice($fixtures, 1)] as $workload => $inputs) {
                    foreach ($inputs as [$target, , $args, $expected, $op, $wire]) {
                        self::assertSame($expected, self::nativeWire($op, $wire));
                        self::assertSame($expected, self::invoke($target, $method.'Native', $args));
                    }
                    $raw = [];
                    for ($i = -64; $i < 3000; $i++) {
                        $fixture = $random->getInt(0, count($inputs) - 1);
                        [$target, , $args, $expected] = $inputs[$fixture];
                        $class = is_object($target) ? $target::class : $target;
                        $order = $random->getInt(0, 1);
                        $times = [];
                        foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                            $class::setUseRustFfi($which === 1);
                            $start = hrtime(true);
                            $observed = self::invoke($target, $method, $args);
                            $times[$which] = hrtime(true) - $start;
                            self::assertSame($expected, $observed);
                        }
                        if ($i >= 0) {
                            $raw[] = [$fixture, $order, $times[0], $times[1]];
                        }
                    }
                    $php = array_column($raw, 2);
                    $native = array_column($raw, 3);
                    sort($php);
                    sort($native);
                    $mean = array_sum($php) / array_sum($native);
                    $p99 = $php[2969] / $native[2969];
                    $results[$method][$workload] = ['raw_fixture_order_php_native_ns' => $raw, 'php_mean_ns' => array_sum($php) / 3000, 'native_mean_ns' => array_sum($native) / 3000, 'php_p99_ns' => $php[2969], 'native_p99_ns' => $native[2969], 'mean_speedup' => $mean, 'p99_speedup' => $p99, 'p99_speedup_approx_95_rank_interval' => [$php[2958] / $native[2980], $php[2980] / $native[2958]], 'gate_passed' => $mean >= 1.5 && $p99 >= 1.5];
                    fwrite(STDOUT, sprintf("%s %s mean %.3fx p99 %.3fx\n", $method, $workload, $mean, $p99));
                }
            }
            self::assertSame($hashes, array_combine($paths, array_map(static fn (string $path): string|false => hash_file('sha256', $root.'/'.$path), $paths)), 'Sources must remain frozen during measurement');
            self::assertSame($metadata['library_sha256'], hash_file('sha256', $library), 'Library must remain frozen');
        } catch (\Throwable $error) {
            $failure = ['class' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            file_put_contents($destination, json_encode($metadata + ['results' => $results, 'failure' => $failure], JSON_THROW_ON_ERROR));
            fwrite(STDOUT, 'Benchmark evidence: '.$destination."\n");
        }
    }
}
