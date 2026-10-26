<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseGroupResolver;
use App\Services\Releases\ReleaseManagementService;
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

final class RustReleaseImageBrowseDuplicateGroupManagementW55ParityTest extends TestCase
{
    /**
     * Per class: the batch entry, its Rust export, the trailer word count after the per-record words and the
     * leading export items that write no per-record word (ReleaseDuplicateFinder sends its search name first).
     */
    private const TARGETS = [
        ReleaseImageService::class => ['w55ImageReferenceFlagBatch', 'nntmux_w553_release_image_reference_flag_batch', 7, 0],
        ReleaseBrowseService::class => ['w55BrowseOrderPlanBatch', 'nntmux_w553_release_browse_order_plan_batch', 11, 0],
        ReleaseDuplicateFinder::class => ['w55CandidateNameMatchBatch', 'nntmux_w553_release_duplicate_name_match_batch', 5, 1],
        ReleaseGroupResolver::class => ['w55GroupNamePlanBatch', 'nntmux_w553_release_group_name_plan_batch', 5, 0],
        ReleaseManagementService::class => ['w55ExportDateKeyBatch', 'nntmux_w553_release_export_date_key_batch', 8, 0],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        ReleaseImageService::class => true,
        ReleaseBrowseService::class => true,
        ReleaseDuplicateFinder::class => true,
        ReleaseGroupResolver::class => true,
        ReleaseManagementService::class => true,
    ];

    /** Edge cases shared by every helper: whitespace, NUL and control bytes, non-ASCII, boundary and malformed rows. */
    private const EDGE = ['', ' ', "  \t\n", "\0", "\x0b", "\x0c", "\r\n", "\n", '_', '__', '.', '..', '/', '//', '///', '---', 'x', 'X', "a\0b",
        "caf\xc3\xa9", "\xff\xfe.jpg", "gr\xc3\xbcn.group", 'abc-123_x', '_hidden', '-dash', 'a', '0', '1.5', 'cover.JPG', 'cover.jpeg?x=1',
        'cover.jpgx', 'cover.webp#frag', 'x.bmp.txt', '.png', 'HTTP://Example.com/a.gif', 'https://user:pw@cdn.example.com/c.jpg?w=1',
        'http://a/b@c.gif', 'http:/x.com', 'https//x.com', 'httpx://x.com', 'ftp://x.com/y.png', 'http://', 'https://?', 'httpS://h#x',
        ' http://padded.example.com/p.png ', '', 'posted_desc', 'name_asc', 'name_ASC', 'cat_desc', "cat_desc\n", "comments_desc\n\n", 'Cat_asc',
        'size', 'stats_up', 'added_asc_extra', 'files_', '_asc', 'posted__asc', 'comments_Desc', 'a.b.teevee', 'A.B.teevee', 'alt.binaries.teevee',
        'alt..x', 'single', "alt.bin\n", "alt.bin\n\n", 'alt.b!n', 'alt.binaries.x-y_z.0', '.alt.x', 'alt.x.', 'a.b.', 'a.b.c', '25/12/2023',
        '1/2/1999', '2023-12-25', '29/02/2023', '29/02/2024', '29/02/1900', '29/02/2000', '31/04/2024', '0/1/2024', '1/0/2024', '1/13/2024',
        '1/1/99', '01/01/0000', '1/1/32767', '001/1/2024', '1/1/ 2024', '+1/1/2024', 'a/b/c', '1/2/3/4', 'Some.Movie.2019.1080p',
        'some movie 2019 1080p', 'Some.Movie.2019', 'Some.Movie.2019.1080p.REPACK', 'Other.Movie.2019.2019', '2019', 'SOME-MOVIE'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w55_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW55RustFfi', 'withW55RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()) {
                    $missing[] = $class.'::'.$method;
                }
            }
            $default = $reflection->getReflectionConstant('W55_RUST_FFI_DEFAULT');
            if ($default === false || ! $default->isPrivate() || $default->getDeclaringClass()->getName() !== $class || ! \is_bool($default->getValue())) {
                $missing[] = $class.'::W55_RUST_FFI_DEFAULT';
            }
            if (! $reflection->hasConstant('W55_FFI_CDEF')) {
                $missing[] = $class.'::W55_FFI_CDEF';
            }
            $state = new ReflectionMethod($class, 'w55RustFfiState');
            $params = array_map(static fn ($p): string => $p->getName().':'.$p->getType().'='.var_export($p->getDefaultValue(), true), $state->getParameters());
            if (! $state->isPrivate() || ! $state->isStatic() || $state->getDeclaringClass()->getName() !== $class
                || $params !== ['op:?string=NULL', 'val:mixed=NULL'] || (string) $state->getReturnType() !== 'mixed') {
                $missing[] = $class.'::w55RustFfiState';
            }
            $uses = new ReflectionMethod($class, 'usesW55RustFfi');
            $with = new ReflectionMethod($class, 'withW55RustFfi');
            $withParams = array_map(static fn ($p): string => $p->getName().':'.$p->getType(), $with->getParameters());
            if (! $uses->getReturnType() instanceof ReflectionNamedType || $uses->getReturnType()->getName() !== 'bool' || $uses->getNumberOfParameters() !== 0
                || $withParams !== ['enabled:bool', 'callback:callable'] || (string) $with->getReturnType() !== 'mixed') {
                $missing[] = $class.' W55 toggle signatures';
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w55_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW55RustFfi(), $class);
            $this->assertTrue($class::withW55RustFfi(true, static fn (): bool => $class::usesW55RustFfi()));
            $this->assertFalse($class::withW55RustFfi(false, static fn (): bool => $class::usesW55RustFfi()));
            $this->assertTrue($class::withW55RustFfi(false, static fn (): bool => $class::withW55RustFfi(true, static fn (): bool => $class::usesW55RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW55RustFfi(), $class);
            try {
                $class::withW55RustFfi(! $class::usesW55RustFfi(), $this->throwing('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW55RustFfi(), $class.' restores after an exception');
        }
        // Each class keeps an independent toggle, separate from the earlier waves.
        ReleaseImageService::withW55RustFfi(! ReleaseImageService::usesW55RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[ReleaseBrowseService::class], ReleaseBrowseService::usesW55RustFfi());
            $this->assertSame(ReleaseImageService::W431_RUST_FFI_DEFAULT, ReleaseImageService::usesW431RustFfi());
        });
        ReleaseDuplicateFinder::withW55RustFfi(! ReleaseDuplicateFinder::usesW55RustFfi(), function (): void {
            $this->assertSame(self::DEFAULTS[ReleaseGroupResolver::class], ReleaseGroupResolver::usesW55RustFfi());
            $this->assertSame(self::DEFAULTS[ReleaseManagementService::class], ReleaseManagementService::usesW55RustFfi());
            $this->assertSame(ReleaseDuplicateFinder::W43_RELEASE_DUPLICATE_RUST_FFI_DEFAULT, ReleaseDuplicateFinder::usesW43RustFfi());
            $this->assertSame(ReleaseGroupResolver::W43_RELEASE_GROUP_RUST_FFI_DEFAULT, ReleaseGroupResolver::usesW43RustFfi());
        });
        ReleaseManagementService::withW55RustFfi(! ReleaseManagementService::usesW55RustFfi(), function (): void {
            $this->assertSame(ReleaseManagementService::W43_RELEASE_MANAGEMENT_RUST_FFI_DEFAULT, ReleaseManagementService::usesW43RustFfi());
            $this->assertSame(ReleaseBrowseService::W43_RELEASE_BROWSE_RUST_FFI_DEFAULT, ReleaseBrowseService::usesW43RustFfi());
        });
    }

    public function test_w55_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, $symbol, $trailer, $lead]) {
            $cdef = \constant($class.'::W55_FFI_CDEF');
            $this->assertStringContainsString($symbol, $cdef);
            $ffi = RustPipelineLibrary::resolveDomain('release_w55', $cdef);
            $this->assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[32]');
            $items = ['a.b.x', '1/2/2024'];
            $blob = implode("\0", $items);
            $words = $trailer + 2 - $lead;
            $this->assertSame($words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, 32), $class);
            $this->assertSame($class::{$entry.'Php'}(...$this->argsFor($class, $items)), array_values((array) unpack('P*', FFI::string($out, $words * 8))), $class);
            // A length column splits unseparated items, so NUL bytes stay inside an item.
            $nul = ["a\0b", '1/2/2024', "x\0"];
            $lens = $ffi->new('int64_t[3]');
            FFI::memcpy($lens, pack('P*', 3, 8, 2), 24);
            $joined = implode('', $nul);
            $nulWords = $trailer + 3 - $lead;
            $this->assertSame($nulWords, $this->callExport($ffi, $symbol, $joined, \strlen($joined), FFI::addr($lens[0]), 3, $out, 32), $class);
            $this->assertSame($class::{$entry.'Php'}(...$this->argsFor($class, $nul)), array_values((array) unpack('P*', FFI::string($out, $nulWords * 8))), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $joined, \strlen($joined) - 1, FFI::addr($lens[0]), 3, $out, 32), $class.' lengths must tile the blob');
            // A NUL inside an item, a short buffer, a null output, a null blob and a count mismatch all decline.
            $this->assertSame(-1, $this->callExport($ffi, $symbol, "a\0b", 3, null, 1, $out, 32), $class);
            $this->assertSame(-$words, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, $out, $words - 1), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, $blob, \strlen($blob), null, 2, null, 32), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, null, 8, null, 1, $out, 32), $class);
            $this->assertSame(-1, $this->callExport($ffi, $symbol, 'a', 1, null, 2, $out, 32), $class);
            $this->assertSame($trailer, $this->callExport($ffi, $symbol, null, 0, null, 0, $out, 32), $class);
        }
    }

    public function test_w55_native_path_runs_for_every_class(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [$entry, , $trailer, $lead]) {
            $items = ['Some.Movie.2019', 'a.b.teevee', 'name_asc', 'cover.jpg', '25/12/2023', ''];
            $words = \count($items) + $trailer - $lead;
            $invoke = new ReflectionMethod($class, 'w55Invoke');
            $raw = $invoke->invoke(null, $items, $words);
            $this->assertIsArray($raw, $class.' stays on Rust');
            $this->assertSame($class::{$entry.'Php'}(...$this->argsFor($class, $items)), $raw, $class);
            $this->assertNull($invoke->invoke(null, $items, $words + 1), $class.' rejects a word count mismatch');
            $nul = ["a\0b", 'ok', "x\0", "\0"];
            $this->assertSame($class::{$entry.'Php'}(...$this->argsFor($class, $nul)), $invoke->invoke(null, $nul, 4 + $trailer - $lead), $class.' keeps NUL items on Rust through the length column');
            $this->assertSame($class::{$entry.'Php'}(...$this->argsFor($class, ["\0"])), $invoke->invoke(null, ["\0"], 1 + $trailer - $lead), $class.' single NUL item');
            if ($lead === 0) {
                $this->assertSame($class::{$entry.'Php'}([]), $invoke->invoke(null, [], $trailer), $class.' empty batch');
            }
            $this->assertCount($trailer, $class::{$entry.'Native'}(...$this->argsFor($class, $lead === 0 ? [] : ['x'])), $class);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCases')]
    public function test_known_cases(string $class, array $args, array $expected): void
    {
        $this->assertSame($expected, $class::{self::TARGETS[$class][0].'Php'}(...$args));
        $this->assertParity($class, $args, 'known case');
    }

    /**
     * @return array<string, array{class-string, list<mixed>, list<int>}>
     */
    public static function knownCases(): array
    {
        return [
            'image basenames, URLs and extensions' => [ReleaseImageService::class,
                [['abc-123_x', ' HTTPS://user:pw@cdn.example.com/a/cover.JPEG?w=1 ', 'http://example.com/x.png#top', '', '_hidden', 'ftp://example.com/x.webp',
                    'http:/example.com', 'http://a/b@c.gif.txt']],
                [2 | 9 << 16, 4 | 8 | 16 | 32 | 64 | 48 << 16, 4 | 32 | 28 << 16, 1, 7 << 16, 32 | 24 << 16, 17 << 16, 4 | 20 << 16, 1, 1, 3, 1, 1, 3, 1]],
            'browse order fields and directions' => [ReleaseBrowseService::class,
                [['', 'name_ASC', "cat_desc\n", 'size', 'stats_up', 'Cat_asc', 'added_asc_extra', "comments_desc\n\n"]],
                [16 | 64, 2 | 16 | 32, 1 | 16 | 64, 3 | 64, 5 | 64, 16 | 32 | 64, 7 | 16 | 32 | 64, 6 | 64, 2, 1, 1, 1, 0, 1, 1, 1, 5, 3, 8]],
            'duplicate exact, folded and prefix keys' => [ReleaseDuplicateFinder::class,
                ['Some.Movie.2019.1080p', ['Some.Movie.2019.1080p', 'some movie 2019 1080p', 'Some.Movie.2019', '', 'Other.Movie.2019.2019', 'Some.Movie.2019.1080p.REPACK']],
                [2 | 4 | 8 | 4 << 16 | 4 << 32, 4 | 8 | 4 << 16 | 4 << 32, 8 | 3 << 16 | 3 << 32, 1, 2 << 16 | 4 << 32, 8 | 4 << 16 | 5 << 32, 1, 1, 2, 4, 4]],
            'duplicate empty search name' => [ReleaseDuplicateFinder::class, ['', ['', 'x']], [1, 1 << 32, 1, 0, 0, 0, 0]],
            'group validation, aliases and repeats' => [ReleaseGroupResolver::class,
                [['a.b.teevee', 'alt.binaries.teevee', 'A.B.teevee', 'alt.binaries.teevee', 'alt..x', 'single', "alt.bin\n", 'alt.b!n', 'alt.'.str_repeat('x', 250),
                    'a.b.'.str_repeat('x', 245)]],
                [2 | 3 << 8 | 19 << 16, 3 << 8 | 19 << 16, 2 | 3 << 8 | 19 << 16, 8 | 3 << 8 | 19 << 16, 1, 1, 2 << 8 | 8 << 16, 1, 2 << 8 | 254 << 16,
                    2 | 4 | 3 << 8 | 258 << 16, 3, 3, 1, 1, 3]],
            'export date keys' => [ReleaseManagementService::class,
                [['25/12/2023', '1/2/1999', '', '2023-12-25', '29/02/2023', '29/02/2024', '1/1/99', 'a/b/c', '1/2/3/4', '25/12/2023', '01/01/0000']],
                [20231225, 19990201, -1, -2, -4, 20240229, -3, -3, -2, 20231225, -4, 4, 1, 2, 2, 2, 19990201, 20240229, 3]],
            'image empty batch' => [ReleaseImageService::class, [[]], [0, 0, 0, 0, 0, 0, 0]],
            'export date empty batch' => [ReleaseManagementService::class, [[]], [0, 0, 0, 0, 0, 0, 0, 0]],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_edge_case_parity(string $class): void
    {
        $this->assertParity($class, $this->argsFor($class, ['Some.Movie.2019', ...self::EDGE]), 'edge batch');
        foreach (self::EDGE as $item) {
            $this->assertParity($class, $this->argsFor($class, [$item, $item]), 'edge item '.bin2hex($item));
            $this->assertParity($class, $this->argsFor($class, ['Some.Movie.2019', $item]), 'edge item vs name '.bin2hex($item));
        }
        // Keys are ignored and the per-record words keep the input order.
        $keyed = ['b' => 'name_asc', 7 => 'a.b.tv', 'a' => '', 'c' => '1/2/2024'];
        $this->assertParity($class, $this->argsFor($class, $keyed), 'keyed batch');
        $this->assertCount(4 + self::TARGETS[$class][2] - self::TARGETS[$class][3], $class::{self::TARGETS[$class][0].'Php'}(...$this->argsFor($class, $keyed)));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('targetClasses')]
    public function test_randomized_realistic_parity(string $class): void
    {
        foreach ([51, 52, 53] as $seed) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity($class, $this->benchArgs($class, new Randomizer(new Mt19937($seed)), $uncommon), 'seed '.$seed);
            }
        }
        // Random byte soup, including NUL, control and high bytes.
        $random = new Randomizer(new Mt19937(5553));
        $soup = [];
        for ($i = 0; $i < 400; $i++) {
            $length = $random->getInt(0, 40);
            $soup[] = $length === 0 ? '' : $random->getBytesFromString("httpsHTTPS:/?#@.jpgPNGwebp_-abAB01239/_ascdescnameNAME \0\t\n\xff\xc3\xa9", $length);
        }
        $this->assertParity($class, $this->argsFor($class, $soup), 'byte soup');
        $this->assertParity($class, $this->argsFor($class, array_values(array_filter($soup, static fn (string $s): bool => ! str_contains($s, "\0")))), 'NUL-free soup');
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
        // the FFI call, decoding and any PHP fallback (release dylib, 5 warmups, 401 paired samples per helper
        // and workload, 20 iterations per common sample and 10 per uncommon sample). Common = one typical batch of
        // 128 well-formed image references, browse orders, candidate names, group names or export dates;
        // uncommon = 256 long, noisy and malformed rows (edge cases, non-ASCII bytes, boundary values, empty and
        // whitespace-only rows).
        // Uncommon batches usually hold a NUL-bearing row, so they also cover the length-column path.
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5 NTS, release dylib, Apple Silicon,
        // shared host at load average ~9.5-10.4):
        // - ReleaseImageService:      common mean 3.00-3.06x / p99 3.02-3.22x, uncommon mean 2.45-2.51x / p99 2.16-2.51x
        // - ReleaseBrowseService:     common mean 5.57-6.46x / p99 4.63-8.09x, uncommon mean 5.96-6.67x / p99 5.00-9.78x
        // - ReleaseDuplicateFinder:   common mean 3.85-3.91x / p99 3.58-4.39x, uncommon mean 2.95-3.15x / p99 2.58-2.70x
        // - ReleaseGroupResolver:     common mean 4.03-4.11x / p99 3.80-3.96x, uncommon mean 2.43-2.47x / p99 2.09-2.36x
        // - ReleaseManagementService: common mean 6.94-7.13x / p99 5.86-6.29x, uncommon mean 3.24-3.37x / p99 2.77-2.92x
        // Every helper clears every gate in every round and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every default to match its gates.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustReleaseImageBrowseDuplicateGroupManagementW55ParityTest.php --filter benchmark
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5536));
        $warmup = 5;
        $samples = 401;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(5537)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(5538)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW55RustFfi($useRust, static fn (): array => $class::$entry(...$args)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry(...$args));
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
        fwrite(STDERR, "\nRustReleaseImageBrowseDuplicateGroupManagementW55 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW55RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}(...$args);
        $this->assertSame($php, $class::{$entry.'Native'}(...$args), $class.' '.$message);
        $this->assertSame($php, $class::withW55RustFfi(true, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
        $this->assertSame($php, $class::withW55RustFfi(false, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
    }

    /**
     * Entry arguments for $items: ReleaseDuplicateFinder takes the first item as its search name.
     *
     * @param  array<array-key, string>  $items
     * @return list<mixed>
     */
    private function argsFor(string $class, array $items): array
    {
        if ($class !== ReleaseDuplicateFinder::class) {
            return [$items];
        }
        $searchName = (string) (array_shift($items) ?? '');

        return [$searchName, $items];
    }

    /**
     * Realistic rows for each helper; uncommon batches are larger and mix in edge cases, long noisy rows,
     * case changes and non-ASCII words.
     *
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        $size = $uncommon ? 256 : 128;
        $rows = [];
        $searchName = $this->releaseName($random, $uncommon);
        for ($i = 0; $i < $size; $i++) {
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $rows[] = $this->pick($random, self::EDGE);

                continue;
            }
            $row = match ($class) {
                ReleaseImageService::class => $this->imageReference($random, $uncommon),
                ReleaseBrowseService::class => $this->browseOrder($random, $uncommon),
                ReleaseDuplicateFinder::class => $random->getInt(0, 3) === 0 ? $searchName : $this->releaseName($random, $uncommon),
                ReleaseGroupResolver::class => $this->groupName($random, $uncommon),
                default => $this->exportDate($random, $uncommon),
            };
            $rows[] = $uncommon && $random->getInt(0, 3) === 0 ? strtoupper($row) : $row;
        }

        return $class === ReleaseDuplicateFinder::class ? [$searchName, $rows] : [$rows];
    }

    private function imageReference(Randomizer $random, bool $noisy): string
    {
        $base = bin2hex($random->getBytes($random->getInt(4, 16)));

        return match ($random->getInt(0, 3)) {
            0 => $base,
            1 => $this->pick($random, ['https://', 'http://', 'HTTPS://']).$this->pick($random, ['', 'user:pw@']).$this->pick($random, ['image.tmdb.org', 'm.media-amazon.com', 'cdn.example.com:8443'])
                .'/t/p/w500/'.$base.$this->pick($random, ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.JPG', '']).$this->pick($random, ['', '', '?w=500', '#x'])
                .($noisy ? str_repeat('/'.$base, $random->getInt(1, 6)) : ''),
            2 => $base.$this->pick($random, ['-cover', '_thumb', '-screenshot', '.jpg', '.nfo']),
            default => $this->pick($random, ['', ' ', $base.' ', "\t".$base]),
        };
    }

    private function browseOrder(Randomizer $random, bool $noisy): string
    {
        $order = $this->pick($random, ['name', 'cat', 'posted', 'added', 'size', 'files', 'stats', 'comments'])
            .$this->pick($random, ['_asc', '_desc', '_asc', '_desc', '_ASC', '', '_up']);

        return $noisy && $random->getInt(0, 2) === 0 ? $order.$this->pick($random, ["\n", '_extra', '_asc_desc', 'x']) : $order;
    }

    private function releaseName(Randomizer $random, bool $noisy): string
    {
        $words = [$this->pick($random, ['Some', 'The', 'A', 'Another', 'Big']), $this->pick($random, ['Movie', 'Show', 'Album', 'Title', 'Quest'])];
        for ($t = $random->getInt(2, $noisy ? 12 : 5); $t > 0; $t--) {
            $words[] = $this->pick($random, ['2019', '2023', '1080p', '720p', '2160p', 'WEB-DL', 'BluRay', 'x264', 'x265', 'HEVC', 'S01E02', 'REPACK',
                'PROPER', 'DDP5', '1', 'AAC', 'Atmos', 'MULTi', 'German', 'DL']);
        }
        if ($noisy && $random->getInt(0, 3) === 0) {
            $words[] = "\xc3\xa9dition";
        }

        return implode($this->pick($random, ['.', ' ', '_', '.']), $words).$this->pick($random, ['-GRP', '-NTb', '-FLUX', '', ' [x]']);
    }

    private function groupName(Randomizer $random, bool $noisy): string
    {
        $parts = [$this->pick($random, ['alt.binaries', 'alt.binaries', 'a.b', 'A.B', 'alt'])];
        for ($t = $random->getInt(1, $noisy ? 8 : 3); $t > 0; $t--) {
            $parts[] = $this->pick($random, ['teevee', 'hdtv', 'x264', 'movies', 'mom', 'multimedia', 'boneless', 'misc', 'erotica', 'sounds',
                'mp3', 'complete', 'big', 'etc', 'nl', 'de', 'ghosts', 'u-4all', 'cores', 'frogs']);
        }
        $name = implode('.', $parts);

        return $noisy && $random->getInt(0, 4) === 0 ? $name.$this->pick($random, ['..x', '!', "\n", ' ', '.']) : $name;
    }

    private function exportDate(Randomizer $random, bool $noisy): string
    {
        $day = $random->getInt(1, $noisy ? 33 : 28);
        $month = $random->getInt(1, $noisy ? 13 : 12);
        $year = $random->getInt($noisy ? 1890 : 2005, 2030);
        $date = ($random->getInt(0, 1) === 1 ? str_pad((string) $day, 2, '0', STR_PAD_LEFT) : (string) $day).'/'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'/'.$year;

        return $noisy && $random->getInt(0, 3) === 0 ? $this->pick($random, [$year.'-'.$month.'-'.$day, $date.'/1', ' '.$date, $day.'/'.$month.'/'.($year % 100)]) : $date;
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
     * Calls a Wave 55.3 export by name.
     */
    private function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionMethod($class, 'w55RustFfiState'))->invoke(null, 'set', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W55_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('release_w55', ReleaseImageService::W55_FFI_CDEF) === null) {
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
