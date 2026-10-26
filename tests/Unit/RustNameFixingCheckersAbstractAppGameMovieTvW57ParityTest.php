<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Checkers\AbstractNameChecker;
use App\Services\NameFixing\Checkers\AppNameChecker;
use App\Services\NameFixing\Checkers\GameNameChecker;
use App\Services\NameFixing\Checkers\MovieNameChecker;
use App\Services\NameFixing\Checkers\TvNameChecker;
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

final class RustNameFixingCheckersAbstractAppGameMovieTvW57ParityTest extends TestCase
{
    /** Per class: the batch entry, its Rust export, its FFI declaration constant and the trailer word count. */
    private const TARGETS = [
        AbstractNameChecker::class => ['w57CandidateEligibilityBatch', 'nntmux_w57_name_checker_candidate_eligibility_batch', 'W57_ABSTRACT_CHECKER_FFI_CDEF', 8],
        AppNameChecker::class => ['w57AppPatternBatch', 'nntmux_w57_app_name_checker_pattern_batch', 'W57_APP_CHECKER_FFI_CDEF', 10],
        GameNameChecker::class => ['w57GamePatternBatch', 'nntmux_w57_game_name_checker_pattern_batch', 'W57_GAME_CHECKER_FFI_CDEF', 9],
        MovieNameChecker::class => ['w57MoviePatternBatch', 'nntmux_w57_movie_name_checker_pattern_batch', 'W57_MOVIE_CHECKER_FFI_CDEF', 8],
        TvNameChecker::class => ['w57TvPatternBatch', 'nntmux_w57_tv_name_checker_pattern_batch', 'W57_TV_CHECKER_FFI_CDEF', 8],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        AbstractNameChecker::class => true,
        AppNameChecker::class => true,
        GameNameChecker::class => true,
        MovieNameChecker::class => true,
        TvNameChecker::class => true,
    ];

    /** Edge cases shared by every helper: whitespace, NUL and control bytes, non-ASCII, boundary and malformed names. */
    private const EDGE = ['', ' ', "  \t\n", "\0", "\x0b", "\x0c", "\r\n", "\x7f", '_', '.', '..', '-', '--', '-ab', 'a-b', 'a-bc', 'x-abcdefghijklmno',
        'x-abcdefghijklmnop', 'x', 'X', "a\0b", "S01\0E02", "caf\xc3\xa9", "\xff\xfe", "gr\xc3\xbcn-GRP", 'abcdefg', 'abcdefgh', 'a.b', 'ab.cd.ef',
        'S01E02', 's1e1', 'S1E1234', 'S1234E01', 'S01E', 'SE01', 'S01', 'S0', 'S1000', '1x01', '01x001', '100x01', '1x1', '1x', 'x1', '2023', '1899',
        '2100', '2023.12.25', '2023.13.25', '2023.12.32', '2023.00.01', '2023.1.25', '1999 01 01', '2023-12', 'v1', 'v1.', 'v1.2', 'V1.2.3.4.5.6.7.8.9.10.11.12.13.14.15.16',
        'xv1.2', '.v1.2', 'v12345.6', 'v00001.2', 'v9999.1', 'Incl', 'Incl.Keygen', 'keygen.incl',
        'incl..crack', 'Win64', 'x64 x86', 'MULTILINGUAL', 'macOS', 'Linux', 'Adobe', 'Game-CODEX', 'Game-codex ', 'Game-CODEX-', 'Game - CODEX', '-CODEX',
        'Game-CODEX.x', 'PS5', 'ps5 nsw', 'Game.NSW-VENOM', 'DLC', 'Update.v12', 'REPACK-FitGirl', 'Game.PAL.Wii', 'Movie.2019.1080p', 'WEB-DL', 'WEB.RIP',
        'WEB', 'web.', 'H.264', 'H.265', 'H', 'h.x', 'x264', 'HEVC', '2160p.HDR', '4K UHD', '3D.SBS', 'Movie (1999)', 'Show.S01E02.720p-GRP', 'Show 3x05',
        'Show.S02.Complete', 'Daily.2023.12.25.HDTV', 'Show.AMZN.WEB', 'NF', 'MAX'];

    /**
     * EDGE plus long version, token and separator runs.
     *
     * @return list<string>
     */
    private static function edgeCases(): array
    {
        return [...self::EDGE, 'v1'.str_repeat('0', 30).'.1', 'v'.str_repeat('9', 20), 'v'.str_repeat('9', 20).'.1', str_repeat('a', 2000),
            str_repeat('a.', 700), str_repeat('-x', 300), str_repeat('S01E02.', 300), str_repeat('x', 17).'-CODEX', 'x-'.str_repeat('c', 17)];
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w57_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry, , $cdef]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW57RustFfi', 'withW57RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()
                    || $reflection->getMethod($method)->getDeclaringClass()->getName() !== $class) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W57_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPublic() || $default->isFinal() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W57_RUST_FFI_DEFAULT';
            }
            $declaration = $reflection->getReflectionConstant($cdef);
            if ($declaration === false || $declaration->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class.'::'.$cdef;
            }
            $state = new ReflectionMethod($class, 'w57RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if (! $state->isPrivate() || ! $state->isStatic() || $state->getDeclaringClass()->getName() !== $class
                || $params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w57RustFfiState';
            }
            $uses = new ReflectionMethod($class, 'usesW57RustFfi');
            $with = new ReflectionMethod($class, 'withW57RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' W57 toggle signatures';
            }
            // Toggle state lives in function-local statics only, never in static properties.
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() && $property->getDeclaringClass()->getName() === $class && str_contains(strtolower($property->getName()), 'w57')) {
                    $missing[] = $class.'::$'.$property->getName();
                }
            }
        }
        // The base class keeps unique toggle names that its subclasses' own W57 toggles never shadow.
        foreach (['usesAbstractNameCheckerW57RustFfi', 'withAbstractNameCheckerW57RustFfi'] as $method) {
            if (! method_exists(AbstractNameChecker::class, $method) || ! (new ReflectionMethod(AbstractNameChecker::class, $method))->isStatic()) {
                $missing[] = AbstractNameChecker::class.'::'.$method;
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w57_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi(), $class);
            $this->assertTrue($class::withW57RustFfi(true, static fn (): bool => $class::usesW57RustFfi()));
            $this->assertFalse($class::withW57RustFfi(false, static fn (): bool => $class::usesW57RustFfi()));
            $this->assertTrue($class::withW57RustFfi(false, static fn (): bool => $class::withW57RustFfi(true, static fn (): bool => $class::usesW57RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi(), $class);
            try {
                $class::withW57RustFfi(! $class::usesW57RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi(), $class.' restores after an exception');
        }
        // The unique base class names drive the same state as usesW57RustFfi() / withW57RustFfi().
        $flipped = ! AbstractNameChecker::usesAbstractNameCheckerW57RustFfi();
        $this->assertSame($flipped, AbstractNameChecker::withAbstractNameCheckerW57RustFfi($flipped, static fn (): bool => AbstractNameChecker::usesW57RustFfi()));
        $this->assertSame($flipped, AbstractNameChecker::withW57RustFfi($flipped, static fn (): bool => AbstractNameChecker::usesAbstractNameCheckerW57RustFfi()));
        // Subclasses reach the base toggle only through its unique names.
        $this->assertSame($flipped, AbstractNameChecker::withW57RustFfi($flipped, static fn (): bool => TvNameChecker::usesAbstractNameCheckerW57RustFfi()));

        // Parent and child toggles are independent in both directions.
        $subclasses = [AppNameChecker::class, GameNameChecker::class, MovieNameChecker::class, TvNameChecker::class];
        AbstractNameChecker::withW57RustFfi(! AbstractNameChecker::usesW57RustFfi(), function () use ($subclasses): void {
            foreach ($subclasses as $class) {
                $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi(), $class.' ignores the base toggle');
            }
        });
        foreach ($subclasses as $class) {
            $class::withW57RustFfi(! $class::usesW57RustFfi(), function () use ($class, $subclasses): void {
                $this->assertSame(self::DEFAULTS[AbstractNameChecker::class], AbstractNameChecker::usesW57RustFfi(), $class.' leaves the base toggle');
                $this->assertSame(self::DEFAULTS[AbstractNameChecker::class], AbstractNameChecker::usesAbstractNameCheckerW57RustFfi());
                foreach ($subclasses as $other) {
                    if ($other !== $class) {
                        $this->assertSame(self::DEFAULTS[$other], $other::usesW57RustFfi(), $class.' leaves '.$other);
                    }
                }
            });
        }
        // The base batch dispatches on the base toggle even when called through a subclass.
        $names = ['The.Show.S01E02-GRP', ''];
        $this->assertSame(AbstractNameChecker::w57CandidateEligibilityBatchPhp($names), TvNameChecker::w57CandidateEligibilityBatch($names));
    }

    public function test_w57_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $cdef, $trailer]) {
            $declaration = \constant($class.'::'.$cdef);
            $this->assertStringContainsString($symbol, $declaration);
            $ffi = RustPipelineLibrary::resolveDomain('name_checkers_w57', $declaration);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[64]');
            $items = ['The.Show.S01E02', 'Adobe.v1.2.x64-CODEX'];
            $blob = implode("\0", $items);
            $words = $trailer + 2;
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, 64), $class);
            $this->assertSame($class::{$entry.'Php'}($items), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", 'S01E02', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 6, 2), 24);
            $joined = implode('', $nul);
            $this->assertSame($trailer + 3, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 64), $class);
            $this->assertSame($class::{$entry.'Php'}($nul), array_values((array) unpack('P*', FFI::string($out, ($trailer + 3) * 8))), $class);
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

    public function test_w57_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , , $trailer]) {
            $items = ['The.Show.S01E02.720p-GRP', 'Adobe.Photoshop.v24.1.x64.Incl.Keygen', 'Game.PS5-CODEX', "[x] caf\xc3\xa9", 'Movie.2019.1080p.WEB-DL.x264', ''];
            $invoke = new ReflectionMethod($class, 'w57Invoke');
            $raw = $invoke->invoke(null, $items, $trailer + \count($items));
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}($items), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $trailer + \count($items) + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}($nul), $invoke->invoke(null, $nul, $trailer + 4), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(["\0"]), $invoke->invoke(null, ["\0"], $trailer + 1), $class.' single NUL item');
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
        $fnv = static fn (string $value): int => (int) hexdec(hash('fnv1a32', $value));

        return [
            'candidate eligibility: trim, group suffix, short and control names' => [AbstractNameChecker::class,
                [" The.Show.S01E02-GRP\n", '', 'ab', "caf\xc3\xa9 x\x01y long"],
                [$fnv('the.show.s01e02.grp') | 4 << 32 | (1 | 8 | 64) << 38 | 47 << 45 | 19 << 53, $fnv(''), $fnv('ab') | 1 << 32 | 4 << 45 | 2 << 53,
                    $fnv('caf.x.y.long') | 4 << 32 | (4 | 16 | 32) << 38 | 17 << 45 | 14 << 53, 1, 1, 1, 1, 1, 47, 9, 4]],
            'app patterns: keygen, Windows, macOS version and empty names' => [AppNameChecker::class,
                ['Adobe.Photoshop.v24.1.2.x64.Incl.Keygen', 'Tool.v1.Win32', 'App v12345.6 macOS', ''],
                [(1 | 2 | 16 | 32 | 512) | 1 << 12 | 3 << 16 | 24 << 20 | 8 << 34, (4 | 64) | 3 << 12 | 3 << 34, (1 | 128) | 4 << 12 | 2 << 16 | 9999 << 20 | 4 << 34, 0,
                    1, 1, 0, 1, 1, 0, 0, 2, 2, 9999]],
            'game patterns: consoles, scene groups, updates and repacks' => [GameNameChecker::class,
                ['Game.PS5-DUPLEX', 'Game.Switch.NSW-VENOM', 'Game.Update.v12-CODEX', 'Game.REPACK', 'x-codex'],
                [1 | 2 << 12 | 3 << 24 | 3 << 32, (1 | 32) | 1 << 12 | 19 << 16 | 9 << 24 | 4 << 32, (8 | 32 | 256) | 3 << 12 | 1 << 16 | 4 << 32,
                    16 | 5 << 12 | 2 << 32, 32 | 3 << 12 | 1 << 16 | 2 << 32, 0, 1, 1, 2, 0, 1, 0, 1, 2]],
            'movie patterns: WEB-DL H.264, UHD BluRay and plain names' => [MovieNameChecker::class,
                ['Movie.2019.1080p.WEB-DL.H.264.DDP5.1', 'Film (1999) 2160p HDR BluRay x265', 'nothing'],
                [2019 | 4 << 12 | 2 << 16 | 2 << 20 | 1 << 24 | 4 << 30 | 1 << 33 | 9 << 34, 1999 | 6 << 12 | 1 << 16 | 3 << 20 | 2 << 24 | 4 << 30 | 1 << 33 | 6 << 34,
                    1 << 34, 2, 2, 1999, 2019, 0, 1, 1, 1]],
            'tv patterns: episode, cross, airdate, season pack and invalid dates' => [TvNameChecker::class,
                ['Show.S01E02.720p.HDTV', 'Show 3x05 AMZN', 'Daily.2023.12.25.WEB', 'Show.S02.Complete', 'Show.2023.13.25'],
                [1 | 1 << 3 | 2 << 13 | (1 | 8) << 23 | 4 << 55, 2 | 3 << 3 | 5 << 13 | 64 << 23 | 3 << 55, 3 | 2 << 23 | 20231225 << 30 | 5 << 55,
                    4 | 2 << 3 | 32 << 23 | 3 << 55, 4 << 55, 1, 1, 1, 1, 1, 3, 5, 1]],
            'candidate eligibility empty batch' => [AbstractNameChecker::class, [], [0, 0, 0, 0, 0, 0, 0, 0]],
            'app patterns empty batch' => [AppNameChecker::class, [], [0, 0, 0, 0, 0, 0, 0, 0, 0, 0]],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, ['The.Show.S01E02', ...self::edgeCases()], 'edge batch');
        foreach (self::edgeCases() as $item) {
            $this->assertParity($class, [$item, $item], 'edge item '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, ['The.Show.S01E02', $item], 'edge item vs name '.bin2hex(substr($item, 0, 64)));
            $this->assertParity($class, [$item], 'single edge item '.bin2hex(substr($item, 0, 64)));
        }
        // Keys are ignored and the per-name words keep the input order.
        $keyed = ['b' => 'Show.S01E02', 7 => 'Adobe.v1.2', 'a' => '', 'c' => 'Game-CODEX'];
        $this->assertParity($class, $keyed, 'keyed batch');
        $this->assertCount(self::TARGETS[$class][3] + 4, $class::{self::TARGETS[$class][0].'Php'}($keyed));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        foreach ([571, 572, 573] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchItems($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        // Random byte soup, including NUL, control and high bytes.
        $random = new Randomizer(new Mt19937(5573));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 48);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("SsEe0123456789xXvV.-_ ()[]HhDdWwBbPpKkIiNnCcLl \0\t\n\x01\x7f\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $soup, 'byte soup');
        $this->assertParity($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0"))), 'NUL-free soup');
        for ($i = 0; $i < 300; $i++) {
            $this->assertParity($class, \array_slice($soup, $i, $random->getInt(0, 5)), 'soup slice '.$i);
        }
        // Keyword soup: every keyword table, in random case, separators and order.
        $words = ['v1.2', 'V10.0.1', 'x64', 'x86', 'Win64', 'Incl', 'Keygen', 'Multi', 'macOS', 'Linux', 'Adobe', 'PS5', 'NSW', 'Switch', 'DLC', 'Update',
            'v12', 'REPACK', 'CODEX', 'FitGirl', 'PAL', 'build', 'Proper', '2019', '1999', '1080p', '2160p', '4K', 'WEB', 'DL', 'Rip', 'H', '264', '265',
            'x265', 'HEVC', 'BluRay', 'HDTV', 'DDP5', 'HDR', 'REMUX', '3D', 'S01E02', 'S1', '3x05', '2023', '12', '25', '13', 'AMZN', 'NF', 'Complete'];
        $keywords = [];
        for ($i = 0; $i < 400; $i++) {
            $parts = [];
            for ($t = $random->getInt(0, 9); $t > 0; $t--) {
                $word = $this->pick($random, $words);
                $parts[] = $random->getInt(0, 3) === 0 ? strtolower($word) : $word;
            }
            $keywords[] = implode($this->pick($random, ['.', ' ', '_', '-', '.-', ' - ']), $parts);
        }
        $this->assertParity($class, $keywords, 'keyword soup');
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
        // helper and workload, 5 iterations per round). Common = one batch of 320 well-formed release names of the
        // checker's category; uncommon = 320 long, noisy and malformed names (edge cases, NUL and non-ASCII bytes,
        // case changes, empty and whitespace-only names).
        // Speedup = PHP latency / Rust latency, ranges over 3 runs (PHP 8.5 NTS, release dylib, Apple Silicon,
        // shared host at load average ~7.6-9.7):
        // - AbstractNameChecker: common mean 4.15-4.38x / p99 3.30-3.81x, uncommon mean 3.10-3.19x / p99 2.52-2.76x
        // - AppNameChecker:      common mean 4.73-4.85x / p99 3.16-4.10x, uncommon mean 3.41-3.48x / p99 2.61-3.19x
        // - GameNameChecker:     common mean 3.86-3.97x / p99 3.51-3.68x, uncommon mean 3.88-4.11x / p99 3.60-4.72x
        // - MovieNameChecker:    common mean 5.26-5.35x / p99 4.23-4.42x, uncommon mean 4.84-5.23x / p99 3.97-4.47x
        // - TvNameChecker:       common mean 7.84-8.46x / p99 6.09-19.51x, uncommon mean 7.47-7.93x / p99 6.25-6.93x
        // Every helper clears every gate in every run and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // NNTMUX_W573_BENCH=1 php artisan test --compact tests/Unit/RustNameFixingCheckersAbstractAppGameMovieTvW57ParityTest.php --filter benchmark
        if (getenv('NNTMUX_W573_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W573_BENCH=1 to run the Wave 57.3 benchmark.');
        }
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5736));
        $warmup = 5;
        $rounds = 201;
        $iterations = 5;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => $this->benchItems($class, new Randomizer(new Mt19937(5737)), false),
                'uncommon' => $this->benchItems($class, new Randomizer(new Mt19937(5738)), true),
            ];
            foreach ($workloads as $name => $items) {
                $this->assertSame($class::{$entry.'Php'}($items), $class::{$entry.'Native'}($items), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW57RustFfi($useRust, static fn (): array => $class::$entry($items)));
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
        fwrite(STDERR, "\nRustNameFixingCheckersAbstractAppGameMovieTvW57 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW57RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}($items);
        $this->assertCount(self::TARGETS[$class][3] + \count($items), $php, $class.' '.$message);
        $this->assertSame($php, $class::{$entry.'Native'}($items), $class.' '.$message);
        $this->assertSame($php, $class::withW57RustFfi(true, static fn (): array => $class::$entry($items)), $class.' '.$message);
        $this->assertSame($php, $class::withW57RustFfi(false, static fn (): array => $class::$entry($items)), $class.' '.$message);
    }

    /**
     * 320 realistic names for each checker; uncommon batches mix in edge cases, long noisy names, case changes,
     * NUL and non-ASCII bytes.
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
                AppNameChecker::class => $this->appName($random, $uncommon),
                GameNameChecker::class => $this->gameName($random, $uncommon),
                MovieNameChecker::class => $this->movieName($random, $uncommon),
                TvNameChecker::class => $this->tvName($random, $uncommon),
                default => match ($random->getInt(0, 3)) {
                    0 => $this->appName($random, $uncommon),
                    1 => $this->gameName($random, $uncommon),
                    2 => $this->movieName($random, $uncommon),
                    default => $this->tvName($random, $uncommon),
                },
            };
            if ($uncommon && $random->getInt(0, 3) === 0) {
                $row = $this->pick($random, [strtoupper($row), strtolower($row), ' '.$row."\t", $row."\0x", "caf\xc3\xa9 ".$row, $row.' [x]',
                    str_replace('.', ' ', $row), str_repeat($row.'.', 3)]);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function title(Randomizer $random, bool $noisy): string
    {
        $words = [$this->pick($random, ['The', 'Doctor', 'Star', 'Big', 'Mr', 'Better', 'Breaking', 'House', 'Super', 'Dark'])];
        for ($t = $random->getInt(1, $noisy ? 7 : 3); $t > 0; $t--) {
            $words[] = $this->pick($random, ['Who', 'Office', 'Order', 'Trek', 'Bang', 'Theory', 'Robot', 'Call', 'Saul', 'Bad', 'of', 'and', 'Dragon', 'Souls', 'Mario']);
        }

        return implode($this->pick($random, ['.', '.', ' ', '_']), $words);
    }

    private function group(Randomizer $random, bool $noisy): string
    {
        return $this->pick($random, ['-GRP', '-NTb', '-FLUX', '-CODEX', '-SKIDROW', '', ' [x]', '-'.str_repeat('A', $noisy ? 24 : 4)]);
    }

    private function appName(Randomizer $random, bool $noisy): string
    {
        $parts = [$this->pick($random, ['Adobe', 'Microsoft', 'Autodesk', 'JetBrains', 'Corel', 'Nero', 'WinRAR', 'VLC', 'Acme']), $this->pick($random, ['Photoshop', 'Office', 'AutoCAD', 'IntelliJ', 'Studio', 'Burner', 'Suite'])];
        if ($random->getInt(0, 3) > 0) {
            $parts[] = 'v'.$random->getInt(1, $noisy ? 99999 : 30).'.'.$random->getInt(0, 20).($random->getInt(0, 1) === 1 ? '.'.$random->getInt(0, 999) : '');
        }
        for ($t = $random->getInt(0, $noisy ? 6 : 3); $t > 0; $t--) {
            $parts[] = $this->pick($random, ['x64', 'x86', 'Win64', 'Win32', 'Multilingual', 'Incl.Keygen', 'Incl.Crack', 'Patch', 'macOS', 'Linux', 'Portable', 'Retail', 'Final']);
        }

        return implode($this->pick($random, ['.', '.', ' ', '_']), $parts).$this->group($random, $noisy);
    }

    private function gameName(Randomizer $random, bool $noisy): string
    {
        $parts = [$this->title($random, $noisy)];
        for ($t = $random->getInt(0, $noisy ? 5 : 2); $t > 0; $t--) {
            $parts[] = $this->pick($random, ['PS5', 'PS4', 'NSW', 'XBOX360', 'Wii', 'PAL', 'NTSC', 'USA', 'DLC', 'Update', 'v'.$random->getInt(1, 99), 'Build', 'REPACK', 'PROPER', 'GOG', 'MULTi10']);
        }

        return str_replace(' ', '.', implode('.', $parts)).$this->pick($random, ['-CODEX', '-PLAZA', '-FitGirl', '-VENOM', '-BigBlue', '-DUPLEX', '-OUTLAWS', '-ALiAS', '', '-'.str_repeat('B', $noisy ? 20 : 4)]);
    }

    private function movieName(Randomizer $random, bool $noisy): string
    {
        $parts = [$this->title($random, $noisy)];
        if ($random->getInt(0, 4) > 0) {
            $parts[] = (string) $random->getInt($noisy ? 1890 : 1960, 2030);
        }
        $parts[] = $this->pick($random, ['720p', '1080p', '2160p', '1080i', '4K', 'UHD', '480p']);
        $parts[] = $this->pick($random, ['BluRay', 'WEB-DL', 'WEBRip', 'WEB', 'HDTV', 'DVDRip', 'BDRemux']);
        for ($t = $random->getInt(1, $noisy ? 6 : 3); $t > 0; $t--) {
            $parts[] = $this->pick($random, ['x264', 'x265', 'H.264', 'H.265', 'HEVC', 'AVC', 'XviD', 'DDP5.1', 'DTS', 'AAC', 'Atmos', 'HDR', 'DV', 'REMUX', 'PROPER', 'MULTi', '3D']);
        }

        return implode($this->pick($random, ['.', '.', ' ']), $parts).$this->group($random, $noisy);
    }

    private function tvName(Randomizer $random, bool $noisy): string
    {
        $season = $random->getInt(1, $noisy ? 1200 : 30);
        $episode = $random->getInt(1, $noisy ? 1200 : 24);
        $reference = match ($random->getInt(0, 4)) {
            0, 1 => \sprintf('S%02dE%02d', $season, $episode),
            2 => $random->getInt(1, $noisy ? 120 : 30).'x'.str_pad((string) $episode, 2, '0', STR_PAD_LEFT),
            3 => $random->getInt($noisy ? 1890 : 2000, 2030).'.'.str_pad((string) $random->getInt(0, $noisy ? 14 : 12), 2, '0', STR_PAD_LEFT)
                .'.'.str_pad((string) $random->getInt(1, $noisy ? 33 : 28), 2, '0', STR_PAD_LEFT),
            default => \sprintf('S%02d', $season),
        };
        $tags = [];
        for ($t = $random->getInt(1, $noisy ? 8 : 4); $t > 0; $t--) {
            $tags[] = $this->pick($random, ['720p', '1080p', '2160p', 'WEB-DL', 'HDTV', 'BluRay', 'x264', 'HEVC', 'AMZN', 'NF', 'DSNP', 'REPACK', 'PROPER', 'Complete', 'German']);
        }

        return str_replace(' ', '.', $this->title($random, $noisy)).'.'.$reference.'.'.implode('.', $tags).$this->group($random, $noisy);
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
     * Calls a Wave 57.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w57RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W57_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('name_checkers_w57', TvNameChecker::W57_TV_CHECKER_FFI_CDEF) === null) {
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
