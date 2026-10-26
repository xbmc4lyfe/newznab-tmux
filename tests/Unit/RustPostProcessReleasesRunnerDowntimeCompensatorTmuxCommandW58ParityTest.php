<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\PostProcessRunner;
use App\Services\Runners\ReleasesRunner;
use App\Services\Tmux\CollectionDowntimeCompensator;
use App\Services\Tmux\Tmux;
use App\Services\Tmux\TmuxCommand;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

final class RustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the words per item. */
    private const TARGETS = [
        PostProcessRunner::class => ['w58PostProcessWorkBatch', 'nntmux_w58_postprocess_runner_work_batch', 'W58_POSTPROCESS_RUNNER_FFI_CDEF', 25, 1],
        ReleasesRunner::class => ['w58ReleaseWorkBatch', 'nntmux_w58_releases_runner_work_batch', 'W58_RELEASES_RUNNER_FFI_CDEF', 11, 1],
        CollectionDowntimeCompensator::class => ['w58DowntimePlanBatch', 'nntmux_w58_downtime_compensator_plan_batch', 'W58_DOWNTIME_COMPENSATOR_FFI_CDEF', 8, 2],
        Tmux::class => ['w58PaneStatusBatch', 'nntmux_w58_tmux_pane_status_batch', 'W58_TMUX_FFI_CDEF', 9, 1],
        TmuxCommand::class => ['w58CommandLineBatch', 'nntmux_w58_tmux_command_line_batch', 'W58_TMUX_COMMAND_FFI_CDEF', 8, 1],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        PostProcessRunner::class => true,
        ReleasesRunner::class => true,
        CollectionDowntimeCompensator::class => true,
        Tmux::class => true,
        TmuxCommand::class => true,
    ];

    /** Edge cases shared by every helper: separators, whitespace, NUL and control bytes, non-ASCII, boundary and malformed items. */
    private const EDGE = ['', ' ', '  ', "\t", "\0", "\n", "\x7f", "\xff", "caf\xc3\xa9", ':', '::', ':::', ',', ',,', ',,,', '.', '-', '--', '-t', "'", "''",
        "it's", 'a b', 'a:b', 'a:b:c', 'a:b:c:d', "a\0b", "x\0:y", 'additional', 'ADDITIONAL:', 'nfo:', 'nfo:0', 'nfo:g', 'nfo:G:1',
        'movie:abc:007', 'movie:abc:100', 'amazon:x:-1',
        'Amazon:9:99', '1:releases:1', '0:releases:1', '1:RELEASES:0', '1:fixnames:', '123456789:nzbs:999999999', '1234567890:nzbs:1', '01:backfill:01',
        '1:groups:65536', '1:collections:1000', '1:collections:99', '+1:groups:1', ' 1:groups:1', '1:groups:1 ', '0,0,0', '0,299,0', '0,300,0', '0,301,1',
        '300,0,1', '1,6000001,1', '1,6000002,169', '9999999999,0,9999999999', '0,9999999999,9999999999', '10000000000,1,1', '1,2,', ',1,2', '1,2,3,4',
        '1 ,2,3', '1,2,3 ', 's:0.0', 's:0.0 0', 's:0.0 1 bash', 's:0.0 2 bash', 's:0.0 0 /', 's:0.0 0 /bin/', 's:0.0 0 BASH', 's:0.0 0 python3', 's:0.0 0 python4',
        's:0.0 0 sleepy', ':0.0 0 sh', 's:.0 0 sh', 's:0. 0 sh', 's:1000.0 0 sh', 's:0.1000 0 sh', 's:0:0.0 0 sh', 's.x:0.0 0 sh', 's:0.0.0 0 sh',
        'a:b:0.0 0 php', 'nntmux:0.1  php', 'nntmux:0.1 0  php', 'new-session', 'send-keys', 'SEND-KEYS', 'kill-server', 'nntmux:0', 'nntmux:0.1', 'nntmux:0.',
        'nntmux:', ':1', '-a:1', 'a_b-c:12.345', 'a:1234', 'php artisan', '--session=nntmux', '/usr/bin/php', 'a=b', 'C:\\tmp', '$HOME', '"x"'];

    /**
     * EDGE plus long runs and boundary lengths.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, 'tv:'.str_repeat('a', 40), 'tv:'.str_repeat('a', 39).':', 'tv:'.str_repeat('F', 40).':00', 'tv:'.str_repeat('f', 41).':7',
            str_repeat('a', 64).':0.0 0 sh', str_repeat('a', 65).':0.0 0 sh', str_repeat('a', 64).':1.2', str_repeat('a', 65).':1',
            str_repeat("'", 300), str_repeat('x', 3000), str_repeat(':', 500), str_repeat(',', 500), 'nfo:'.str_repeat('0', 2000).':1',
            'additional:'.str_repeat('e', 40).':'.str_repeat('9', 30), '5:releases:'.str_repeat('9', 30), str_repeat('1', 30).',1,1',
            's:0.0 0 '.str_repeat('/x', 300), str_repeat('python', 2)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w58_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW58RustFfi', 'withW58RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W58_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->isFinal() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W58_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            $state = new ReflectionMethod($class, 'w58RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if (! $state->isPrivate() || ! $state->isStatic() || $state->getDeclaringClass()->getName() !== $class
                || $params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w58RustFfiState';
            }
            $uses = new ReflectionMethod($class, 'usesW58RustFfi');
            $with = new ReflectionMethod($class, 'withW58RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' W58 toggle signatures';
            }
            // Toggle state lives in function-local statics only, never in static properties.
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() && $property->getDeclaringClass()->getName() === $class && str_contains(strtolower($property->getName()), 'w58')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
        }
        // Each class declares its own FFI declaration constant name, so the runners never shadow BaseRunner's.
        $cdefs = array_column(array_values(self::TARGETS), 2);
        $this->assertSame($cdefs, array_values(array_unique($cdefs)));
        $this->assertSame([], $missing);
    }

    public function test_w58_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW58RustFfi(), $class);
            $this->assertTrue($class::withW58RustFfi(true, static fn (): bool => $class::usesW58RustFfi()));
            $this->assertFalse($class::withW58RustFfi(false, static fn (): bool => $class::usesW58RustFfi()));
            $this->assertTrue($class::withW58RustFfi(false, static fn (): bool => $class::withW58RustFfi(true, static fn (): bool => $class::usesW58RustFfi())));
            $this->assertFalse($class::withW58RustFfi(true, static fn (): bool => $class::withW58RustFfi(false, static fn (): bool => $class::usesW58RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW58RustFfi(), $class);
            try {
                $class::withW58RustFfi(! $class::usesW58RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW58RustFfi(), $class.' restores after an exception');
        }
        // Toggles are independent between classes.
        foreach (array_keys(self::TARGETS) as $class) {
            $flipped = ! $class::usesW58RustFfi();
            $class::withW58RustFfi($flipped, function () use ($class, $flipped): void {
                foreach (array_keys(self::TARGETS) as $other) {
                    $this->assertSame($other === $class ? $flipped : self::DEFAULTS[$other], $other::usesW58RustFfi(), $class.' vs '.$other);
                }
            });
        }
        // Both dispatch paths return the PHP reference words.
        foreach (self::TARGETS as $class => [$entry]) {
            $items = ['nntmux:0.1 0 /bin/bash', '42:releases:500', '1000,2000,3', 'send-keys', ''];
            $php = $class::{$entry.'Php'}($items);
            $this->assertSame($php, $class::withW58RustFfi(true, static fn (): array => $class::$entry($items)), $class);
            $this->assertSame($php, $class::withW58RustFfi(false, static fn (): array => $class::$entry($items)), $class);
        }
    }

    public function test_w58_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer, $per]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('categorize_w58', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[64]');
            $items = ['nntmux:0.1 0 bash', 'additional:ab:1'];
            $blob = implode("\0", $items);
            $words = $trailer + 2 * $per;
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, 64), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 's:0.0', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 5, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3 * $per, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 64), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3 * $per) * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 64), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 64), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, null, 64), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 64), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 64), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 64), $class);
        }
    }

    public function test_w58_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer, $per]) {
            $items = ['nntmux:0.1 0 /bin/bash', 'additional:'.str_repeat('a', 40).':2', '42:releases:500', '1000,2000,3', 'send-keys', "caf\xc3\xa9", ''];
            $invoke = new ReflectionMethod($class, 'w58Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + $per * \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + $per * \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4 * $per), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + $per), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            $this->assertCount($trailer, $class::{$entry.'Native'}([]), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $items, array $expected): void
    {
        $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Php'}($items));
        $this->assertParity($class, $items, 'known case');
    }

    /**
     * @return array<string, array{class-string, list<string>, list<int>}>
     */
    public static function knownCases(): array
    {
        $guid = 'additional:0123456789abcdef0123456789ABCDEF01234567:3';
        $line = "send-keys -t nntmux:0.1 'it'\\''s' ''";

        return [
            'postprocess: valid guid, unknown bucket, bare mode and capped retries' => [PostProcessRunner::class,
                [$guid, 'TV:zz:x', 'nfo', 'amazon:f:123'],
                [1 | 1 << 8 | 1 << 9 | 3 << 10 | 50 << 17 | \strlen($guid) << 25, 4 | 16 << 3 | 50 << 17 | 7 << 25, 2 | 16 << 3 | 30 << 17 | 3 << 25,
                    5 | 15 << 3 | 1 << 9 | 99 << 10 | 12 << 25, 0, 1, 1, 0, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 2, 1, 50]],
            'releases: qualified, zero limit, zero group and malformed' => [ReleasesRunner::class,
                ['42:Releases:500', '42:nzbs:0', '0:groups:5', 'x:bogus:'],
                [1 | 7 << 3 | 95 << 6 | 500 << 13 | 42 << 29, 5 | 7 << 3 | 60 << 6 | 42 << 29, 3 | 3 << 3 | 20 << 6 | 5 << 13, 0, 1, 1, 0, 1, 0, 1, 0, 2, 1, 500, 175]],
            'downtime: short, skewed, capped, malformed and boundary gaps' => [CollectionDowntimeCompensator::class,
                ['1000,1100,2', '1000,900,1', '0,6000301,500', '1,2', '1000,1300,0'],
                [2 | 2 << 20, 0, 1 | 1 << 20, 0, 4 | 100_000 << 3 | 168 << 20, 168 * 3600 + 6_000_301, 0, 0, 3 | 5 << 3, 300,
                    1, 1, 1, 1, 1, 168 * 3600 + 6_000_601, 6_000_301, 100_005]],
            'panes: idle shell, dead php, invalid target and sleeping' => [Tmux::class,
                ['nntmux:0.1 0 /bin/bash', 'nntmux:2.3 1 php artisan', 'bad 0 sleep', 'other:10.0 0 /usr/bin/SLEEP 30'],
                [2 | 1 << 3 | 1 << 6 | 1 << 19 | 6 << 29 | 244 << 36, 1 | 2 << 3 | 1 << 6 | 1 << 7 | 2 << 9 | 3 << 19 | 6 << 29 | 160 << 36, 3 << 3 | 196 << 36,
                    3 | 3 << 3 | 1 << 6 | 10 << 9 | 5 << 29 | 33 << 36, 1, 1, 1, 1, 0, 0, 2, 10, 3]],
            'command line: subcommand, option, pane target, quoting and empty' => [TmuxCommand::class,
                ['send-keys', '-t', 'nntmux:0.1', "it's", ''],
                [3 | 9 << 14 | 9 << 38, 1 | 2 << 14 | 2 << 38, 2 | 2 << 4 | 10 << 14 | 10 << 38, 5 | 1 << 3 | 1 << 6 | 9 << 14 | 4 << 38, 1 << 3 | 2 << 14,
                    1, 1, 1, 1, 0, 1, \strlen($line), (int) hexdec(hash('fnv1a32', $line))]],
            'command line empty batch' => [TmuxCommand::class, [], [0, 0, 0, 0, 0, 0, 0, 0x811C9DC5]],
            'downtime empty batch' => [CollectionDowntimeCompensator::class, [], [0, 0, 0, 0, 0, 0, 0, 0]],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, ['nntmux:0.1 0 bash', ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, ['1:releases:5', $item], 'edge item vs work item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$item], 'single edge item '.bin2hex(substr($item, 0, 64)));
        }
        // Keys are ignored and the per-item words keep the input order.
        $keyed = ['b' => 'nntmux:0.1 0 bash', 7 => '1000,2000,3', 'a' => '', 'c' => 'tv:abc:1'];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][3] + 4 * self::TARGETS[$class][4], $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        // 3 seeds x 2 workloads x 320 items, plus 400 byte-soup items and 300 random slices per class.
        foreach ([581, 582, 583] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        $random = new Randomizer(new Mt19937(5583));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 40);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("aAfFzZ0123456789:,. -_/'\"tvnfoTVNFOsleepbashphp\0\t\n\x01\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Every class's generator through every helper.
        $mixed = [];
        foreach (array_keys(self::TARGETS) as $other) {
            array_push($mixed, ...\array_slice($this->benchItems($other, new Randomizer(new Mt19937(5584)), true), 0, 80));
        }
        $this->assertParity($class, $mixed, 'cross-class items');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function targetClasses(): array
    {
        $cases = [];
        foreach (array_keys(self::TARGETS) as $class) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class];
        }

        return $cases;
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including joining,
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, NNTMUX_W583_BENCH_ROUNDS
        // interleaved paired rounds per helper and workload (default 301, minimum 60), 5 iterations per round).
        // Common = one batch of 320 well-formed items of the class's format; uncommon = 320 items mixing edge
        // cases, malformed, long, NUL, quote and non-ASCII items.
        // Speedup = PHP latency / Rust latency, minimum over 3 runs at 301 rounds (PHP 8.5.12 NTS, rustc 1.99.1
        // release dylib, Apple Silicon, shared host at load average ~8.8-9.5), common mean / p99, uncommon mean / p99:
        // - PostProcessRunner:             3.37x / 3.26x, 3.02x / 2.78x
        // - ReleasesRunner:                4.09x / 3.83x, 4.00x / 3.32x
        // - CollectionDowntimeCompensator: 4.72x / 4.25x, 2.53x / 2.85x
        // - Tmux:                          6.51x / 5.67x, 3.51x / 2.83x
        // - TmuxCommand:                   5.17x / 4.45x, 2.07x / 1.88x
        // Every helper clears every gate in all three runs and defaults to Rust. Shorter runs on the same loaded
        // host estimate p99 from the 2nd-3rd slowest sample and are outlier-sensitive: at 101 rounds TmuxCommand's
        // uncommon p99 measured 1.36x and 1.38x in two of three runs (the serial FNV-1a hash over long quoted
        // arguments costs about as much in Rust as in PHP's hash()), and single runs dipped below 1.0x for one
        // p99 (CollectionDowntimeCompensator uncommon at 101 rounds, ReleasesRunner uncommon at 201 rounds) while
        // the Rust mean of that run rose 2-3x, which points to host interference.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W583_BENCH=1 php artisan test --compact tests/Unit/RustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W583_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W583_BENCH=1 to run the Wave 58.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5836));
        $warmup = 5;
        $rounds = max(60, (int) (getenv('NNTMUX_W583_BENCH_ROUNDS') ?: 301));
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(5837)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(5838)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertGreaterThanOrEqual(300, \count($items));
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW58RustFfi($useRust, static fn (): array => $class::$entry($items)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $rounds; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry($items));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW58RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][3] + self::TARGETS[$class][4] * \count($items), $php, $class.' '.$message);
        foreach ($php as $word) {
            $this->assertIsInt($word, $class.' '.$message);
        }
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW58RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW58RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 well-formed items in each class's format; uncommon batches mix in edge cases, malformed and long items,
     * case changes, NUL, quote and non-ASCII bytes.
     *
     * @return list<string>
     */
    private function benchItems(string $class, Randomizer $random, bool $uncommon): array
    {
        $rows = [];
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::edgeCases());

                continue;
            }
            $row = match ($class) {
                PostProcessRunner::class => $this->postProcessItem($random, $uncommon),
                ReleasesRunner::class => $this->releaseItem($random, $uncommon),
                CollectionDowntimeCompensator::class => $this->downtimeItem($random, $uncommon),
                Tmux::class => $this->paneItem($random, $uncommon),
                default => $this->commandItem($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), ' '.$row, $row."\t", $row."\0x", "caf\xc3\xa9".$row, $row.':x', $row.',1', $row."'",
                    str_repeat($row, 4)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function postProcessItem(Randomizer $random, bool $noisy): string
    {
        $mode = $this->pick($random, ['additional', 'nfo', 'movie', 'tv', 'amazon']);
        $guid = bin2hex($random->getBytes(20));
        if ($noisy) {
            $mode = $this->pick($random, [$mode, strtoupper($mode), 'anime', 'books', '']);
            $guid = $this->pick($random, [$guid, substr($guid, 0, $random->getInt(0, 39)), strtoupper($guid), 'zz'.$guid, $guid.'0']);
        }
        $retries = $noisy ? $this->pick($random, ['', 'x', '-1', '007', '100', (string) $random->getInt(0, 99)]) : (string) $random->getInt(0, 5);

        return $mode.':'.$guid.':'.$retries;
    }

    private function releaseItem(Randomizer $random, bool $noisy): string
    {
        $group = (string) $random->getInt(1, 5000);
        $stage = $this->pick($random, ['releases', 'collections', 'groups', 'fixnames', 'nzbs', 'backfill']);
        $limit = (string) $random->getInt(100, 5000);
        if ($noisy) {
            $group = $this->pick($random, [$group, '0', '-1', '1234567890', 'alt.binaries', '']);
            $stage = $this->pick($random, [$stage, strtoupper($stage), 'update', '']);
            $limit = $this->pick($random, [$limit, '0', '99999999', '1e3', '']);
        }

        return $group.':'.$stage.':'.$limit;
    }

    private function downtimeItem(Randomizer $random, bool $noisy): string
    {
        $last = $random->getInt(1_700_000_000, 1_800_000_000);
        $now = $last + $this->pick($random, [$random->getInt(0, 299), $random->getInt(300, 3600), $random->getInt(3600, 259_200)]);
        $delay = (string) $random->getInt(1, 48);
        if ($noisy) {
            $now = $this->pick($random, [$now, $last - $random->getInt(1, 600), $last + $random->getInt(6_000_000, 99_000_000), 9_999_999_999]);
            $delay = $this->pick($random, [$delay, '0', '500', '99999999999', '', '-1']);
        }

        return $last.','.$now.','.$delay;
    }

    private function paneItem(Randomizer $random, bool $noisy): string
    {
        $session = $this->pick($random, ['nntmux', 'NNTPProxy', 'nntmux_2', 'main-1']);
        $target = $session.':'.$random->getInt(0, 9).'.'.$random->getInt(0, 6);
        $dead = $random->getInt(0, 9) === 0 ? '1' : '0';
        $command = $this->pick($random, ['/bin/bash', 'bash', 'zsh', 'php artisan tmux:monitor --session=nntmux', '/usr/bin/php -d memory_limit=-1 artisan',
            'sleep 30', '/usr/bin/sleep 600', 'python3 /app/bin/scheduler.py', 'tmux', 'htop', 'mysql -u nntmux']);
        if ($noisy) {
            $target = $this->pick($random, [$target, $session, $session.':1', $session.':1000.1', 'bad name:1.1', $session.':x.y', str_repeat('s', 70).':1.1']);
            $dead = $this->pick($random, [$dead, '', '2', 'yes']);
            $command = $this->pick($random, [$command, '', '/', strtoupper($command), '/opt/very/long/path/to/'.str_repeat('x', 40)]);
        }

        return $target.' '.$dead.' '.$command;
    }

    private function commandItem(Randomizer $random, bool $noisy): string
    {
        $arg = $this->pick($random, ['new-session', 'send-keys', 'select-pane', 'split-window', '-t', '-d', '-s', '-n', 'nntmux', 'nntmux:0', 'nntmux:1.2',
            'php artisan tmux:monitor --session=nntmux', 'C-m', '/usr/bin/php', '--session=nntmux', 'Enter', 'clear', '100']);
        if ($noisy) {
            $arg = $this->pick($random, [$arg, '', "it's", "a 'quoted' arg", 'echo "$HOME" && ls', str_repeat("x'", $random->getInt(1, 40)), "tab\there",
                "caf\xc3\xa9", strtoupper($arg), str_repeat('a', 70).':1']);
        }

        return $arg;
    }

    /**
     * A callback that throws a RuntimeException with $message.
     *
     * @return callable(): mixed
     */
    private function throwing(string $message): callable
    {
        return static function () use ($message): mixed {
            throw new RuntimeException($message);
        };
    }

    /**
     * Calls a Wave 58.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w58RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W58_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w58', TmuxCommand::W58_TMUX_COMMAND_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }
}
