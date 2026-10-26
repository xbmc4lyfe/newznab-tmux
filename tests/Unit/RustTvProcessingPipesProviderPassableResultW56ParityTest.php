<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\Pipes\AbstractApiProviderPipe;
use App\Services\TvProcessing\Pipes\AbstractTvProviderPipe;
use App\Services\TvProcessing\Providers\AbstractTvProvider;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
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

final class RustTvProcessingPipesProviderPassableResultW56ParityTest extends TestCase
{
    /**
     * Per class: the batch entry, its Rust export, its FFI declaration constant, the trailer word count and the
     * per-record word layout (1 per item, 2 per item, or 1 per (ours, theirs) pair).
     */
    private const TARGETS = [
        AbstractTvProviderPipe::class => ['w56PipeGateBatch', 'nntmux_w563_tv_pipe_gate_batch', 'W56_TV_PIPE_FFI_CDEF', 10, 'double'],
        AbstractApiProviderPipe::class => ['w56SearchEligibilityBatch', 'nntmux_w563_tv_api_search_eligibility_batch', 'W56_API_PIPE_FFI_CDEF', 7, 'single'],
        AbstractTvProvider::class => ['w56TitleMatchBatch', 'nntmux_w563_tv_provider_title_match_batch', 'W56_TV_PROVIDER_FFI_CDEF', 4, 'pair'],
        TvProcessingPassable::class => ['w56ContextFingerprintBatch', 'nntmux_w563_tv_passable_context_fingerprint_batch', 'W56_FFI_CDEF', 6, 'single'],
        TvProcessingResult::class => ['w56OutcomeSummaryBatch', 'nntmux_w563_tv_result_outcome_summary_batch', 'W56_FFI_CDEF', 8, 'single'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        AbstractTvProviderPipe::class => true,
        AbstractApiProviderPipe::class => true,
        AbstractTvProvider::class => true,
        TvProcessingPassable::class => true,
        TvProcessingResult::class => true,
    ];

    /** Edge cases shared by every helper: whitespace, NUL and control bytes, non-ASCII, boundary and malformed rows. */
    private const EDGE = ['', ' ', "  \t\n", "\0", "\x0b", "\x0c", "\r\n", '_', '.', '..', '-', '--', ':', '::', ':::', 'x', 'X', "a\0b", "S01\0E02",
        "caf\xc3\xa9", "\xff\xfe", "gr\xc3\xbcn-GRP", 'S01E02', 's1e1', 'S1E1234', 'S123E01', 'S01E', 'SE01', 'S01', 'S0', 'S100', '1x01', '01x001',
        '100x1', '1x', 'x1', '2023', '1899', '2100', '2023.12.25', '2023.13.25', '2023.12.32', '2023.00.01', '2023.1.25', '1999 01 01', '2023-12',
        'Show.S01E02.720p-GRP', 'Show 3x105', 'Show.S02.Complete', 'The.Show.2023.01.02.HDTV', 'Doctor Who 2005', 'Doctor Who (2005)', 'The Office US',
        'The Office UK 2005', 'Show NZ', 'US', 'US 2019', '2019 2020', '12345', 'a', 'ab', '-GRP', 'x-GRP', 'x-'.'abcdefghijklmnopqrstu', 'x-abc_DEF',
        "x-GRP\n", ' x-GRP ', '[tag]', '][', '[', 'x[a]y', 'matched', 'MATCHED:1:2', 'matched:1:2', 'matched:0:2', 'matched:1:0', 'season_pack:5',
        'season_pack:5:1', 'episode_missing:7:', 'not_found::', 'not_found:1', 'parse_failed', 'transient:3:4', 'skipped', ' pending ', 'pending:a',
        'matched:1234567890123456789:1', 'matched:123456789012345678:1', 'matched:+1:2', 'matched: 1:2', 'matched:1:2:3', 'unknown:1:0',
        'matched:1099511627776:1', ":\0:"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w56_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW56RustFfi', 'withW56RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W56_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->isFinal() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W56_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            $state = new ReflectionMethod($class, 'w56RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if (! $state->isPrivate() || ! $state->isStatic() || $state->getDeclaringClass()->getName() !== $class
                || $params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w56RustFfiState';
            }
            $uses = new ReflectionMethod($class, 'usesW56RustFfi');
            $with = new ReflectionMethod($class, 'withW56RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' W56 toggle signatures';
            }
        }
        // The provider base class keeps unique toggle names so provider subclasses can declare their own W56 toggles.
        foreach (['usesAbstractTvProviderW56RustFfi', 'withAbstractTvProviderW56RustFfi'] as $method) {
            if (! method_exists(AbstractTvProvider::class, $method) || ! (new ReflectionMethod(AbstractTvProvider::class, $method))->isStatic()) {
                $missing[] = AbstractTvProvider::class.'::'.$method;
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w56_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW56RustFfi(), $class);
            $this->assertTrue($class::withW56RustFfi(true, static fn (): bool => $class::usesW56RustFfi()));
            $this->assertFalse($class::withW56RustFfi(false, static fn (): bool => $class::usesW56RustFfi()));
            $this->assertTrue($class::withW56RustFfi(false, static fn (): bool => $class::withW56RustFfi(true, static fn (): bool => $class::usesW56RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW56RustFfi(), $class);
            try {
                $class::withW56RustFfi(! $class::usesW56RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW56RustFfi(), $class.' restores after an exception');
        }
        // The unique base class names drive the same state as usesW56RustFfi() / withW56RustFfi().
        $flipped = ! AbstractTvProvider::usesAbstractTvProviderW56RustFfi();
        $this->assertSame($flipped, AbstractTvProvider::withAbstractTvProviderW56RustFfi($flipped, static fn (): bool => AbstractTvProvider::usesW56RustFfi()));
        $this->assertSame($flipped, AbstractTvProvider::withW56RustFfi($flipped, static fn (): bool => AbstractTvProvider::usesAbstractTvProviderW56RustFfi()));
        // The pipe base class and its API subclass keep independent toggles, separate from the earlier waves.
        AbstractApiProviderPipe::withW56RustFfi(! AbstractApiProviderPipe::usesW56RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[AbstractTvProviderPipe::class], AbstractTvProviderPipe::usesW56RustFfi());
            $this->assertSame(AbstractApiProviderPipe::W48_API_PROVIDER_RUST_FFI_DEFAULT, AbstractApiProviderPipe::usesW48ApiProviderRustFfi());
        });
        AbstractTvProviderPipe::withW56RustFfi(! AbstractTvProviderPipe::usesW56RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[AbstractApiProviderPipe::class], AbstractApiProviderPipe::usesW56RustFfi());
            $this->assertSame(AbstractTvProviderPipe::W48_TV_PROVIDER_RUST_FFI_DEFAULT, AbstractTvProviderPipe::usesW48TvProviderRustFfi());
        });
        TvProcessingPassable::withW56RustFfi(! TvProcessingPassable::usesW56RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[TvProcessingResult::class], TvProcessingResult::usesW56RustFfi());
            $this->assertSame(self::DEFAULTS[AbstractTvProvider::class], AbstractTvProvider::usesW56RustFfi());
            $this->assertSame(TvProcessingPassable::W47_PASSABLE_RUST_FFI_DEFAULT, TvProcessingPassable::usesW47PassableRustFfi());
            $this->assertSame(TvProcessingResult::W47_RESULT_RUST_FFI_DEFAULT, TvProcessingResult::usesW47ResultRustFfi());
        });
    }

    public function test_w56_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('tv_w56', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[64]');
            $items = ['The.Show.S01E02', 'matched:1:2'];
            $blob = implode("\0", $items);
            $words = $this->wordsFor($class, 2);
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, 64), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'S01E02', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 6, 2), 24);
            $joined = implode('', $nul);
            $nulWords = $this->wordsFor($class, 3);
            $this->assertSame($nulWords, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 64), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, $nulWords * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 64), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 64), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, null, 64), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 64), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 64), $class);
            $this->assertSame(self::TARGETS[$class][3], $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 64), $class);
        }
    }

    public function test_w56_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry]) {
            $items = ['The.Show.S01E02.720p-GRP', 'Doctor Who 2005', 'matched:12:34', '[x] caf\xc3\xa9', '2023.12.25', ''];
            $invoke = new ReflectionMethod($class, 'w56Invoke');
            $raw = $invoke->invoke(null, $items, $this->wordsFor($class, \count($items)));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $this->wordsFor($class, \count($items)) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $this->wordsFor($class, 4)), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $this->wordsFor($class, 1)), $class.' single NUL item');
            $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], self::TARGETS[$class][3]), $class.' empty batch');
            $this->assertCount(self::TARGETS[$class][3], $class::{$entry.'Native'}([]), $class);
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
        $fnv = static fn (string $value): int => (int) hexdec(hash('fnv1a32', $value));
        $offset = $fnv('');

        return [
            'pipe gates: episode, cross, airdate, season pack and empty references' => [AbstractTvProviderPipe::class,
                ['The.Show.S01E02.720p', 'Show 3x105', 'Daily.Show.2023.12.25.HDTV', 'Show.S02.Complete', '', 'S01E01', 'Show.2023.13.01'],
                [2 | 32 | 1 << 8 | 2 << 16, $fnv('the show') | 8 << 32, 4 | 32 | 3 << 8 | 105 << 16, $fnv('show') | 4 << 32,
                    8 | 32 | 20231225 << 28, $fnv('daily show') | 10 << 32, 16 | 32 | 2 << 8, $fnv('show') | 4 << 32, 1, $offset,
                    2 | 32 | 64 | 1 << 8 | 1 << 16, $offset, 0, $fnv('show 2023 13 01') | 15 << 32, 1, 2, 1, 1, 1, 5, 1, 3, 105, 4]],
            'search eligibility: year, country and numeric titles' => [AbstractApiProviderPipe::class,
                ['Doctor Who 2005', 'The Office US', '2019', '', 'Show UK 2010', 'a'],
                [4 | 16 | 105 << 8 | 10 << 16 | $fnv('doctor who') << 31, 8 | 16 | 1 << 5 | 10 << 16 | $fnv('the office') << 31, 2 | 4 << 16 | $fnv('2019') << 31,
                    1 | $offset << 31, 4 | 8 | 16 | 2 << 5 | 110 << 8 | 4 << 16 | $fnv('show') << 31, 1 << 16 | $fnv('a') << 31, 1, 1, 2, 2, 3, 3, 10]],
            'title matches: equal, dissimilar and unpaired titles' => [AbstractTvProvider::class,
                ['Doctor Who 2005', 'doctor.who.2005', 'Hello', 'World', 'Lonely'],
                [10 | 20 << 20 | (1 | 2 | 8) << 40, 1 | 10 << 20, 6 << 20 | 4 << 40, 1, 1, 1, 0]],
            'title matches: best similar pair and year gap' => [AbstractTvProvider::class,
                ['The Office US', 'The Office UK', 'Doctor Who 1963', 'Doctor Who 2005', 'Show', 'Show'],
                [10 | 20 << 20 | 3 << 40, 10 | 20 << 20 | (1 | 2 | 16) << 40, 4 | 8 << 20 | (1 | 2) << 40, 3, 3, 0, 0]],
            'context fingerprints: groups, tags and non-ASCII names' => [TvProcessingPassable::class,
                [' The.Show.S01E02-GRP ', '-GRP', "[x] caf\xc3\xa9", ''],
                [$fnv('the.show.s01e02.grp') | 4 << 32 | 3 << 48 | 2 << 53, $fnv('grp') | 1 << 32, $fnv('x.caf') | 2 << 32 | (4 | 8) << 53, $offset | 1 << 53, 1, 1, 1, 1, 4, 7]],
            'outcome summaries: classes and id validation' => [TvProcessingResult::class,
                ['matched:12:34', ' Season_Pack :12', 'not_found', 'matched:x:1', 'odd:0:0:0'],
                [1 << 2 | (1 | 2 | 8) << 6 | 12 << 10, 2 << 2 | (1 | 8) << 6 | 12 << 10, 2 | 4 << 2 | 8 << 6, 1 << 2 | (2 | 4) << 6, 3 | 4 << 6, 3, 0, 1, 1, 2, 2, 1, 34]],
            'pipe gates empty batch' => [AbstractTvProviderPipe::class, [], [0, 0, 0, 0, 0, 0, 0, 0, 0, 0]],
            'outcome summaries empty batch' => [TvProcessingResult::class, [], [0, 0, 0, 0, 0, 0, 0, 0]],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, ['The.Show.S01E02', ...self::EDGE], 'edge batch');
        foreach (self::EDGE as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex($item));
            $this->assertParity($class, ['The.Show.S01E02', $item], 'edge item vs name '.bin2hex($item));
            $this->assertParity($class, [$item], 'single edge item '.bin2hex($item));
        }
        // Keys are ignored and the per-record words keep the input order.
        $keyed = ['b' => 'Show.S01E02', 7 => 'matched:1:2', 'a' => '', 'c' => 'Doctor Who 2005'];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount($this->wordsFor($class, 4), $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        foreach ([561, 562, 563] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        // Random byte soup, including NUL, control and high bytes.
        $random = new Randomizer(new Mt19937(5563));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 40);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("Ss0123456789EexX.-_ :[]UuKkabmatched_pk \0\t\n\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
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
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, 201 interleaved paired rounds per
        // helper and workload, 5 iterations per round). Common = one batch of 320 well-formed release names, clean
        // titles, title pairs, search names or outcome snapshots; uncommon = 320 long, noisy and malformed rows
        // (edge cases, NUL and non-ASCII bytes, boundary values, empty and whitespace-only rows).
        // Speedup = PHP latency / Rust latency, ranges over 3 runs (PHP 8.5 NTS, release dylib, Apple Silicon,
        // shared host at load average ~9.0-9.1):
        // - AbstractTvProviderPipe:  common mean 9.43-9.82x / p99 8.57-9.24x, uncommon mean 8.05-8.97x / p99 6.94-7.51x
        // - AbstractApiProviderPipe: common mean 7.61-7.74x / p99 5.86-6.98x, uncommon mean 5.12-5.29x / p99 3.52-4.66x
        // - AbstractTvProvider:      common mean 2.68-2.84x / p99 2.36-2.81x, uncommon mean 1.95-2.05x / p99 1.87-2.03x
        // - TvProcessingPassable:    common mean 3.69-3.87x / p99 3.26-3.87x, uncommon mean 3.02-3.19x / p99 2.59-2.80x
        // - TvProcessingResult:      common mean 5.23-5.51x / p99 4.19-4.78x, uncommon mean 2.19-3.92x / p99 2.48-5.76x
        // Every helper clears every gate in every run and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W563_BENCH=1 php artisan test --compact tests/Unit/RustTvProcessingPipesProviderPassableResultW56ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W563_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W563_BENCH=1 to run the Wave 56.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5636));
        $warmup = 5;
        $rounds = 201;
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(5637)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(5638)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW56RustFfi($useRust, static fn (): array => $class::$entry($items)));
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
        fwrite(STDERR, "\nRustTvProcessingPipesProviderPassableResultW56 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW56RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount($this->wordsFor($class, \count($items)), $php, $class.' '.$message);
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW56RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW56RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    private function wordsFor(string $class, int $count): int
    {
        [, , , $trailer, $layout] = self::TARGETS[$class];

        return $trailer + match ($layout) {
            'double' => 2 * $count,
            'pair' => intdiv($count + 1, 2),
            default => $count,
        };
    }

    /**
     * 320 realistic rows for each helper; uncommon batches mix in edge cases, long noisy rows, case changes,
     * NUL and non-ASCII bytes.
     *
     * @return list<string>
     */
    private function benchItems(string $class, Randomizer $random, bool $uncommon): array
    {
        $rows = [];
        for ($i = 0; $i < 320; $i++) {
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $rows[] = $this->pick($random, self::EDGE);

                continue;
            }
            $row = match ($class) {
                AbstractTvProviderPipe::class, TvProcessingPassable::class => $this->releaseName($random, $uncommon),
                AbstractApiProviderPipe::class, AbstractTvProvider::class => $this->showTitle($random, $uncommon),
                default => $this->outcome($random, $uncommon),
            };
            if ($uncommon && $random->getInt(0, 4) === 0) {
                $row = $this->pick($random, [strtoupper($row), ' '.$row."\t", $row."\0x", "caf\xc3\xa9 ".$row, $row.' [x]']);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function showTitle(Randomizer $random, bool $noisy): string
    {
        $words = [$this->pick($random, ['The', 'Doctor', 'Law', 'Grey', 'Star', 'Big', 'Mr', 'Better', 'Breaking', 'House'])];
        for ($t = $random->getInt(1, $noisy ? 7 : 3); $t > 0; $t--) {
            $words[] = $this->pick($random, ['Who', 'Office', 'Order', 'Anatomy', 'Trek', 'Bang', 'Theory', 'Robot', 'Call', 'Saul', 'Bad', 'of', 'and', 'Dragon']);
        }
        if ($random->getInt(0, 2) === 0) {
            $words[] = $this->pick($random, ['US', 'UK', 'AU', 'CA', 'NZ', 'DE']);
        }
        if ($random->getInt(0, 2) === 0) {
            $words[] = (string) $random->getInt($noisy ? 1890 : 1960, 2030);
        }

        return implode($this->pick($random, [' ', ' ', '.', '_']), $words);
    }

    private function releaseName(Randomizer $random, bool $noisy): string
    {
        $title = $this->showTitle($random, $noisy);
        $season = $random->getInt(1, $noisy ? 120 : 30);
        $episode = $random->getInt(1, $noisy ? 1200 : 24);
        $reference = match ($random->getInt(0, 4)) {
            0, 1 => \sprintf('S%02dE%02d', $season, $episode),
            2 => $season.'x'.str_pad((string) $episode, 2, '0', STR_PAD_LEFT),
            3 => $random->getInt($noisy ? 1890 : 2000, 2030).'.'.str_pad((string) $random->getInt(1, $noisy ? 14 : 12), 2, '0', STR_PAD_LEFT)
                .'.'.str_pad((string) $random->getInt(1, $noisy ? 33 : 28), 2, '0', STR_PAD_LEFT),
            default => \sprintf('S%02d', $season),
        };
        $tags = [];
        for ($t = $random->getInt(1, $noisy ? 8 : 4); $t > 0; $t--) {
            $tags[] = $this->pick($random, ['720p', '1080p', '2160p', 'WEB-DL', 'HDTV', 'BluRay', 'x264', 'x265', 'HEVC', 'DDP5', '1', 'AAC', 'REPACK', 'PROPER', 'MULTi', 'German']);
        }

        return str_replace(' ', '.', $title).'.'.$reference.'.'.implode('.', $tags).$this->pick($random, ['-GRP', '-NTb', '-FLUX', '', ' [x]', '-'.str_repeat('A', $noisy ? 24 : 4)]);
    }

    private function outcome(Randomizer $random, bool $noisy): string
    {
        $status = $this->pick($random, ['matched', 'matched', 'season_pack', 'episode_missing', 'not_found', 'not_found', 'parse_failed', 'transient', 'skipped', 'pending']);
        $video = match ($random->getInt(0, $noisy ? 5 : 3)) {
            0 => '',
            1, 2, 3 => (string) $random->getInt(1, 400000),
            4 => (string) $random->getInt(0, PHP_INT_MAX),
            default => $this->pick($random, ['x', '-1', ' 1', '1e3', '0']),
        };
        $episode = $status === 'matched' || $random->getInt(0, 3) === 0 ? (string) $random->getInt(0, 9000000) : '';
        if ($noisy && $random->getInt(0, 3) === 0) {
            $status = $this->pick($random, [strtoupper($status), ' '.$status.' ', $status.'x', '']);
        }

        return $status.':'.$video.($episode === '' && $random->getInt(0, 1) === 0 ? '' : ':'.$episode);
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
     * Calls a Wave 56.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w56RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W56_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('tv_w56', TvProcessingResult::W56_FFI_CDEF) === null) {
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
