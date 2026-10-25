<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Media\ImageInspector;
use App\Services\AdditionalProcessing\Media\Par2FileMapper;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\SevenZipListingParser;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W52_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, each side the fastest of three back-to-back calls, release dylib. Common workloads hold 64
 * records and uncommon ones 192 records with edge cases. Timings include PHP-side packing, the FFI call,
 * output decoding and PHP patches; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each run asserts that the Rust defaults still clear 1.5x.
 */
class RustNzbSevenZipImageInspectorPar2MapperEntryW52ParityTest extends TestCase
{
    /**
     * Batch method, words per record and the index of the record argument per class; each has
     * `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, array{string, int, int}>
     */
    private const METHODS = [
        NzbContentParser::class => ['subjectNameBatch', 4, 0],
        SevenZipListingParser::class => ['linePlanBatch', 4, 0],
        ImageInspector::class => ['headerProbeBatch', 3, 0],
        Par2FileMapper::class => ['candidatePlanBatch', 3, 1],
        Par2FileEntry::class => ['volumePlanBatch', 3, 0],
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        NzbContentParser::class => true,
        SevenZipListingParser::class => true,
        ImageInspector::class => true,
        Par2FileMapper::class => true,
        Par2FileEntry::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W52_BENCH=1` runs (1000 samples per workload, seed 5252, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        NzbContentParser::class => [2.42, 2.42, 2.13, 2.05],
        SevenZipListingParser::class => [2.03, 2.14, 2.13, 2.21],
        ImageInspector::class => [5.03, 4.31, 5.43, 4.14],
        Par2FileMapper::class => [13.92, 10.44, 13.34, 10.96],
        Par2FileEntry::class => [2.23, 1.79, 4.04, 3.98],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BYTES = ['a', 'Z', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", '-', '.', '"', '\\', '/', '(', ')', '=', '+', "\x01", "\x7f", "\0", "\xc3\xa9", "\xe2\x82\xac", "\xc3", "\xff"];

    private const SUBJECT_PARTS = ['[01/20] - ', 'Release.Name ', '"', '"', 'movie.part01.rar', 'file name.mkv', '.hidden', '-dash', 'a/b', 'a\\b', "na\x01me", "\xc3\xa9t\xc3\xa9", "bad\xff", ' yEnc', ' YENC', 'yEnc', ' (1/23)', ' (01/007)', '(1/2)', ' (1/)', ' (1/99999999999999999999)', ' (12/0000000000000000000000042)', '  ', "\t", "\r", "\n", "\x0b", "\x0c", ' ', 'x'];

    private const LINE_KEYS = ['Path', 'Size', 'Modified', 'Attributes', 'CRC', 'Encrypted', 'Method', 'Folder', 'Packed Size', 'Host OS', 'Path (alt)', 'Version_2', 'a-b.c', '9bad', 'bad=key', ' Path', 'Pa"th', "Path\n", "Path\n\n", '', "\xc3\xa9"];

    private const VOLUME_NAMES = ['release', 'Release.Name-GRP', 'a.b', 'x', '', 'vol', 'a.part', 'a.vol'];

    private const VOLUME_SUFFIXES = ['.part01.rar', '.PART1.RAR', '.part0001.rar', '.part12345.rar', '.part.rar', '.rar', '.r00', '.R99', '.r001', '.r0001', '.r1', '.001', '.0001', '.01', '.vol00+01.par2', '.VOL03+04.PAR2', '.vol123456+1.par2', '.vol1+123456.par2', '.vol+1.par2', '.par2', '.7z', '.7Z', '.zip', '.ZiP', '.mkv', '.nfo', "\n", '.rar ', '.rar.bak', ''];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string, int, int}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => [$method, $words, $recordArgument]) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method, $words, $recordArgument];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertGreaterThan(0, $words);
        $this->assertGreaterThanOrEqual(0, $recordArgument);
        $reflection = new ReflectionClass($class);
        foreach (['usesW52RustFfi', 'withW52RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w52RustFfiState')->isPrivate(), "{$class} keeps its W52 state private");
        $constant = new ReflectionClassConstant($class, 'W52_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW52RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW52RustFfi($enabled, static fn () => [$class::usesW52RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW52RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW52RustFfi(false, static fn () => [$class::withW52RustFfi(true, static fn () => $class::usesW52RustFfi()), $class::usesW52RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW52RustFfi(! $class::usesW52RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW52RustFfi(), "{$class} must restore after an exception");

        $class::withW52RustFfi(! $class::usesW52RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW52RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_readonly_classes_keep_w52_state_without_static_properties(): void
    {
        foreach ([Par2FileMapper::class, Par2FileEntry::class] as $class) {
            $reflection = new ReflectionClass($class);
            $this->assertTrue($reflection->isReadOnly(), $class);
            $this->assertSame([], array_values(array_filter(
                array_keys($reflection->getStaticProperties()),
                static fn (string $name): bool => str_starts_with(strtolower($name), 'w52'),
            )), $class);
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $this->assertFalse(NzbContentParser::usesW416RustFfi());
        $this->assertFalse(SevenZipListingParser::usesW426RustFfi());
        $this->assertFalse(ImageInspector::usesRustFfi());
        $this->assertFalse(Par2FileMapper::usesRustFfi());
        $this->assertFalse(Par2FileEntry::usesRustFfi());
        NzbContentParser::withW52RustFfi(true, fn () => $this->assertFalse(NzbContentParser::usesW416RustFfi()));
        SevenZipListingParser::withW52RustFfi(true, fn () => $this->assertFalse(SevenZipListingParser::usesW426RustFfi()));
        ImageInspector::withW52RustFfi(true, fn () => $this->assertFalse(ImageInspector::usesRustFfi()));
        Par2FileMapper::withW52RustFfi(true, fn () => $this->assertFalse(Par2FileMapper::usesRustFfi()));
        Par2FileEntry::withW52RustFfi(true, fn () => $this->assertFalse(Par2FileEntry::usesRustFfi()));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->requireFfi($class);
        $arguments = self::firstArguments($class);
        $php = $class::{$method.'Php'}(...$arguments);
        $this->assertCount($words * \count($arguments[$recordArgument]), $php);
        $this->assertSame($php, $class::withW52RustFfi(false, static fn () => $class::{$method}(...$arguments)), $class);
        $this->assertSame($php, $class::withW52RustFfi(true, static fn () => $class::{$method}(...$arguments)), $class);
        $this->assertSame($php, $class::{$method.'Native'}(...$arguments), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertGreaterThan(0, $words);
        $arguments = self::firstArguments($class);
        $arguments[$recordArgument] = [];
        $this->assertSame([], $class::{$method.'Php'}(...$arguments));
        $this->assertSame([], $class::{$method.'Native'}(...$arguments));
        $this->assertSame([], $class::{$method}(...$arguments));
    }

    public function test_nzb_subject_name_examples(): void
    {
        $this->assertBoth(NzbContentParser::class, [[
            '[1/3] - "movie.part01.rar" yEnc (1/50)',
            'plain.mkv yEnc (1/99999999999999999999)',
            '"x"oops',
            '".hidden"',
            'name with space  ',
            '"a/b" (2/3)',
            '',
            '"  spaced  " yEnc',
            "bad\xffname.bin",
            'Some.Release (01/007)',
            '"a\\b"',
            "no\rbreak",
        ]], [
            2, 9, 16, 50,
            2, 0, 9, (int) '99999999999999999999',
            0, 0, 0, -1,
            1, 1, 7, -1,
            2, 0, 15, -1,
            1, 1, 3, 3,
            0, 0, 0, -1,
            2, 3, 6, -1,
            1, 0, 12, -1,
            2, 0, 12, 7,
            1, 1, 3, -1,
            0, 0, 0, -1,
        ]);
        // A NUL byte cannot be delimited: the native path falls back to PHP.
        $this->assertBoth(NzbContentParser::class, [["with\0nul", 'ok.bin']], [1, 0, 8, -1, 2, 0, 6, -1]);
    }

    public function test_sevenzip_line_plan_examples(): void
    {
        $this->assertBoth(SevenZipListingParser::class, [[
            "Path = a.rar\r",
            " \t",
            'Size =',
            'garbage',
            '9bad = x',
            'Custom Key = v = w',
            '',
            "Path\n = x",
            ' = x',
            "\r",
            "\r\r",
            'CRC = 1A2B3C4D',
        ]], [
            3 | 1 << 2, 4, 7, 5,
            0, 0, 0, 0,
            3 | 2 << 2, 4, 6, 0,
            1, 0, 0, 0,
            2, 4, 7, 1,
            3, 10, 13, 5,
            0, 0, 0, 0,
            3, 5, 8, 1,
            2, 0, 3, 1,
            0, 0, 0, 0,
            1, 0, 0, 0,
            3 | 5 << 2, 3, 6, 8,
        ]);
        $this->assertBoth(SevenZipListingParser::class, [["Path = a\0b", 'Size = 1']], [3 | 1 << 2, 4, 7, 3, 3 | 2 << 2, 4, 7, 1]);
    }

    public function test_image_header_probe_examples(): void
    {
        $this->assertBoth(ImageInspector::class, [[
            self::png(512, 300),
            self::jpeg(640, 480, 40),
            self::webp('VP8X', 640, 480),
            self::webp('VP8 ', 1920, 1080),
            self::webp('VP8L', 300, 200),
            substr(self::webp('VP8 ', 10, 10), 0, 30).'',
            'RIFF'.str_repeat("\0", 4).'WEBPABCD'.str_repeat("\0", 20),
            'GI',
            '',
            substr(self::jpeg(640, 480, 40), 0, 30),
            "\xFF\xD8\xFF\xDA\x00\x08",
            substr(self::png(1, 1), 0, 23),
        ]], [
            IMAGETYPE_PNG, 512, 300,
            IMAGETYPE_JPEG, 640, 480,
            IMAGETYPE_WEBP, 640, 480,
            IMAGETYPE_WEBP, 1920, 1080,
            IMAGETYPE_WEBP, 300, 200,
            IMAGETYPE_WEBP, 10, 10,
            IMAGETYPE_WEBP, 0, 0,
            0, 0, 0,
            0, 0, 0,
            IMAGETYPE_JPEG, 0, 0,
            IMAGETYPE_JPEG, 0, 0,
            0, 0, 0,
        ]);
    }

    public function test_par2_candidate_plan_examples(): void
    {
        $nzb = [
            ['size' => 1000, 'segments' => [1, 2]],
            ['size' => '1000', 'segments' => ['a', 'b']],
            'junk',
            ['size' => 2000, 'filecount' => 2, 'segments' => [1]],
            ['size' => 2000, 'segments' => [1, 2, 3]],
            ['size' => 0, 'segments' => [1]],
            ['size' => 5000, 'segments' => []],
        ];
        $files = [new Par2FileEntry('a', 990), new Par2FileEntry('b', 1950), new Par2FileEntry('c', 10), new Par2FileEntry('d', 0), new Par2FileEntry('e', -5)];
        $this->assertBoth(Par2FileMapper::class, [$nzb, $files, null], [2, 0, 1, 1, 4, 4, 0, -1, -1, 0, -1, -1, 0, -1, -1]);
        $this->assertBoth(Par2FileMapper::class, [$nzb, $files, 500], [2, 0, 1, 0, -1, -1, 0, -1, -1, 0, -1, -1, 0, -1, -1]);
        $this->assertBoth(Par2FileMapper::class, [$nzb, $files, -1], [2, 0, 1, 1, 4, 4, 0, -1, -1, 0, -1, -1, 0, -1, -1]);
        $this->assertBoth(Par2FileMapper::class, [[], $files, null], [0, -1, -1, 0, -1, -1, 0, -1, -1, 0, -1, -1, 0, -1, -1]);
        // Sizes beyond 2^53 fall back to PHP's float division.
        $huge = [['size' => PHP_INT_MAX, 'segments' => [1]]];
        $this->assertBoth(Par2FileMapper::class, [$huge, [new Par2FileEntry('h', PHP_INT_MAX - 7)], 1 << 40], null);
    }

    public function test_par2_entry_volume_plan_examples(): void
    {
        $hash = 'd41d8cd98f00b204e9800998ecf8427e';
        $entries = array_map(
            static fn (array $row): Par2FileEntry => new Par2FileEntry($row[0], 100, $row[1]),
            [['a.part01.rar', $hash], ['a.rar', strtoupper($hash)], ['a.r00', 'x'], ['a.001', ''], ['a.vol03+04.PAR2', $hash], ['a.par2', ''], ['a.7z', ''], ['a.ZIP', ''], ['a.part12345.rar', ''], ['a.mkv', ''], ["a.rar\n", ''], ['a.r0001', $hash."\n"]],
        );
        $this->assertBoth(Par2FileEntry::class, [$entries], [
            1 | 256, 1, -1,
            2, -1, -1,
            3, 0, -1,
            4, 1, -1,
            6 | 256, 3, 4,
            5, -1, -1,
            7, -1, -1,
            8, -1, -1,
            2, -1, -1,
            0, -1, -1,
            0, -1, -1,
            0, -1, -1,
        ]);
        $this->assertBoth(Par2FileEntry::class, [[new Par2FileEntry("a\0.rar", 1, $hash), new Par2FileEntry('b.zip', 1, "\0")]], [2 | 256, -1, -1, 8, -1, -1]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $arguments = self::firstArguments($class);
        $this->assertCount($words * \count($arguments[$recordArgument]), $class::{$method.'Native'}(...$arguments));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    public function test_large_batches_grow_the_output_buffer(): void
    {
        $this->requireFfi(SevenZipListingParser::class);
        $lines = array_map(static fn (int $i): string => $i % 3 === 0 ? '' : 'Path = file-'.$i.'.bin', range(0, 4999));
        $this->assertSame(SevenZipListingParser::linePlanBatchPhp($lines), SevenZipListingParser::linePlanBatchNative($lines));
        $handle = self::state(SevenZipListingParser::class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(20000, $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method, int $words, int $recordArgument): void
    {
        self::state($class, 'reset', false);
        $arguments = self::firstArguments($class);
        $this->assertCount($words * \count($arguments[$recordArgument]), $class::{$method.'Native'}(...$arguments));
        $this->assertSame($class::{$method.'Php'}(...$arguments), $class::{$method.'Native'}(...$arguments), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method, int $words, int $recordArgument): void
    {
        $this->assertNotSame('', $method);
        $this->assertGreaterThanOrEqual(0, $recordArgument);
        $ffi = $this->requireFfi($class);
        [$function, $data, $ints, $count] = self::abiPacket($class);
        $needed = $count * $words;
        $out = $ffi->new('int64_t[16]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, $ints, $out, 16), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, $ints, $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, $ints, null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, $ints, null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, $ints, $out, 16), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, $ints, $out, 16), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, $ints.'x', $out, 16), "{$function} ragged int table");
        $this->assertSame(0, $call(0, 0, '', $class === Par2FileMapper::class ? pack('P2', 0, 0) : '', null, 0), "{$function} empty batch");
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi(NzbContentParser::class);
        mt_srand(5251);
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $count = mt_rand(1, 16);
            $withNul = $round % 50 === 49;
            $subjects = $lines = $heads = $nzb = $files = $entries = [];
            for ($i = 0; $i < $count; $i++) {
                $subjects[] = self::randomSubject($withNul);
                $lines[] = self::randomLine($withNul);
                $heads[] = self::randomHead();
                $entries[] = self::randomEntry($withNul);
            }
            for ($i = 0, $sources = mt_rand(0, 24); $i < $sources; $i++) {
                $nzb[] = self::randomSource();
            }
            for ($i = 0; $i < $count; $i++) {
                $posted = $nzb === [] ? mt_rand(1, 100_000) : (int) (\is_array($nzb[array_rand($nzb)]) ? ($nzb[array_rand($nzb)]['size'] ?? 1) : 1);
                $files[] = new Par2FileEntry('f'.$i, mt_rand(0, 9) === 0 ? mt_rand(-5, 5) : (int) round(max(1, $posted) * mt_rand(880, 1010) / 1000));
            }
            $part = [null, 0, -3, 500, 750_000, mt_rand(1, 5000)][mt_rand(0, 5)];

            $this->assertBoth(NzbContentParser::class, [$subjects], null);
            $this->assertBoth(SevenZipListingParser::class, [$lines], null);
            $this->assertBoth(ImageInspector::class, [$heads], null);
            $this->assertBoth(Par2FileMapper::class, [$nzb, $files, $part], null);
            $this->assertBoth(Par2FileEntry::class, [$entries], null);

            // The candidate plan summarizes candidateIndexesPhp() for every file.
            $mapper = new Par2FileMapper;
            $plan = Par2FileMapper::candidatePlanBatchNative($nzb, $files, $part);
            foreach ($files as $index => $file) {
                $indexes = $mapper->candidateIndexesPhp($nzb, $file, $part);
                $this->assertSame([\count($indexes), $indexes[0] ?? -1, $indexes === [] ? -1 : $indexes[\count($indexes) - 1]], \array_slice($plan, 3 * $index, 3));
            }
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi(NzbContentParser::class);
        mt_srand(5252);
        $strict = getenv('NNTMUX_W52_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];
        $floors = [];

        foreach (['common' => 64, 'uncommon' => 192] as $name => $count) {
            $odd = $name === 'uncommon';
            $subjects = $lines = $heads = $nzb = $files = $entries = [];
            for ($i = 0; $i < $count; $i++) {
                $subjects[] = $odd
                    ? match ($i % 8) {
                        0 => "[{$i}/{$count}] - \"Some.Show.S01E".sprintf('%02d', $i % 30).".720p.HDTV.x264-GRP.part{$i}.rar\" yEnc (1/".(100 + $i).')',
                        1 => "Show.Name.{$i}.mkv yEnc (1/99999999999999999999)",
                        2 => "\"  t\u{e9}l\u{e9}chargement {$i}.mkv  \" YENC  (3/9)\t",
                        3 => '"./bad/'.$i.'.bin" yEnc',
                        4 => "no quotes and no counter {$i}",
                        5 => "\"broken\r\n{$i}\" yEnc (1/2)",
                        6 => '"-dash'.$i.'.nfo" (01/0002)',
                        default => "\"bad\xff{$i}.bin\" yEnc (1/1)",
                    }
                : '[PRiVATE]-[WtFnZb]-['.$i.'/'.$count.'] - "Release.Name.2024.1080p.WEB-DL.DDP5.1.H.264-GRP.part'.sprintf('%03d', $i + 1).'.rar" yEnc (1/'.(140 + $i % 7).')';

                $lines[] = $odd
                    ? match ($i % 8) {
                        0 => "Path = dir/sub dir/t\u{e9}l\u{e9} file {$i}.bin\r",
                        1 => '',
                        2 => " \t ",
                        3 => 'Attributes = D....',
                        4 => "garbage line {$i}",
                        5 => "9bad = {$i}",
                        6 => 'Custom (Key)_2.x-y = a = b',
                        default => 'CRC =',
                    }
                : ['Path = Release.Name.2024.1080p/video.'.$i.'.mkv', 'Size = '.(1_000_000 + $i * 37), 'Packed Size = '.(990_000 + $i), 'Modified = 2024-05-0'.($i % 9 + 1).' 12:34:56', 'Attributes = A', 'CRC = 1A2B'.sprintf('%04X', $i), 'Encrypted = -', 'Method = LZMA2:24', ''][$i % 9];

                $heads[] = $odd
                    ? match ($i % 6) {
                        0 => self::jpeg(4000 + $i, 3000, 900 + $i * 7, true),
                        1 => self::webp(['VP8 ', 'VP8L', 'VP8X'][$i % 3], 100 + $i, 50 + $i),
                        2 => substr(self::jpeg(640, 480, 300), 0, 200),
                        3 => self::rawBytes(64),
                        4 => self::png(1 + $i, 70000 + $i),
                        default => "\xFF\xD8\xFF".str_repeat("\xFF", 20)."\xD9",
                    }
                : match ($i % 4) {
                    0, 1 => self::jpeg(1280 + $i, 720, 400 + ($i * 13) % 700),
                    2 => self::png(800 + $i, 600),
                    default => self::webp('VP8 ', 1024 + $i, 768),
                };

                $nzb[] = $odd
                    ? match ($i % 6) {
                        0 => ['size' => (string) (50_000_000 + $i * 1000), 'segments' => range(1, 70 + $i % 3), 'filecount' => 1],
                        1 => ['size' => 50_000_000, 'segments' => range(1, 70), 'filecount' => 2],
                        2 => 'not an entry',
                        3 => ['size' => 0, 'segments' => [1]],
                        default => ['size' => 15_000_000 + ($i % 3) * 10, 'segments' => range(1, 21)],
                    }
                : ['title' => 'file '.$i, 'size' => 52_428_800 + $i * 4096, 'segments' => range(1, 70), 'filecount' => 1];

                $files[] = new Par2FileEntry('file '.$i, $odd
                    ? [50_000_000 + $i * 1000 - 3_000_000, 14_000_000, 15_000_000 + $i, 0, -7, PHP_INT_MAX >> 12][$i % 6]
                    : (int) ((52_428_800 + (($i * 7) % $count) * 4096) * 0.97));

                $entries[] = new Par2FileEntry(
                    $odd ? self::VOLUME_NAMES[$i % \count(self::VOLUME_NAMES)].self::VOLUME_SUFFIXES[$i % \count(self::VOLUME_SUFFIXES)] : 'Release.Name.2024.1080p.WEB-DL-GRP.part'.sprintf('%02d', $i + 1).'.rar',
                    52_428_800,
                    $odd ? ['', 'D41D8CD98F00B204E9800998ECF8427E', md5((string) $i), 'zz'][$i % 4] : md5((string) $i),
                );
            }
            $part = $odd ? 716_800 : 768_000;

            $workloads[NzbContentParser::class][$name] = static fn (bool $rust) => $rust ? NzbContentParser::subjectNameBatchNative($subjects) : NzbContentParser::subjectNameBatchPhp($subjects);
            $workloads[SevenZipListingParser::class][$name] = static fn (bool $rust) => $rust ? SevenZipListingParser::linePlanBatchNative($lines) : SevenZipListingParser::linePlanBatchPhp($lines);
            $workloads[ImageInspector::class][$name] = static fn (bool $rust) => $rust ? ImageInspector::headerProbeBatchNative($heads) : ImageInspector::headerProbeBatchPhp($heads);
            $workloads[Par2FileMapper::class][$name] = static fn (bool $rust) => $rust ? Par2FileMapper::candidatePlanBatchNative($nzb, $files, $part) : Par2FileMapper::candidatePlanBatchPhp($nzb, $files, $part);
            $workloads[Par2FileEntry::class][$name] = static fn (bool $rust) => $rust ? Par2FileEntry::volumePlanBatchNative($entries) : Par2FileEntry::volumePlanBatchPhp($entries);
        }

        foreach ($workloads as $class => $byName) {
            $results = [];
            foreach ($byName as $name => $workload) {
                $this->assertSame($workload(false), $workload(true), "{$class} {$name} benchmark workload must agree");
                for ($w = 0; $w < 20; $w++) {
                    $workload(false);
                    $workload(true);
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach (mt_rand(0, 1) === 1 ? [true, false] : [false, true] as $rust) {
                        $best = PHP_INT_MAX;
                        for ($r = 0; $r < self::REPEATS; $r++) {
                            $t0 = hrtime(true);
                            $workload($rust);
                            $best = min($best, hrtime(true) - $t0);
                        }
                        $times[$rust ? 'rust' : 'php'][] = $best / 1000;
                    }
                }

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, \sprintf(
                "\n[Wave 52.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $floors[$class] = min($results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']);
        }
        fwrite(STDERR, "\n");

        // Every helper is reported before the gate is asserted; Rust defaults must still clear 1.5x.
        foreach ($floors as $class => $floor) {
            $this->assertSame(self::DEFAULTS[$class], $class::usesW52RustFfi(), $class);
            if ($class::usesW52RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $arguments
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $arguments, ?array $expected): void
    {
        $method = self::METHODS[$class][0];
        $php = $class::{$method.'Php'}(...$arguments);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertSame($php, $class::{$method.'Native'}(...$arguments), "{$class} native");
        $this->assertSame($php, $class::withW52RustFfi(true, static fn () => $class::{$method}(...$arguments)), "{$class} Rust route");
        $this->assertSame($php, $class::withW52RustFfi(false, static fn () => $class::{$method}(...$arguments)), "{$class} PHP route");
    }

    /**
     * @param  class-string  $class
     * @return list<mixed>
     */
    private static function firstArguments(string $class): array
    {
        return match ($class) {
            NzbContentParser::class => [['[1/2] - "a.rar" yEnc (1/5)', 'b.nfo', '"c']],
            SevenZipListingParser::class => [['Path = a', '', 'Size = 1', 'junk']],
            ImageInspector::class => [[self::png(200, 200), self::jpeg(300, 200, 10), 'x']],
            Par2FileMapper::class => [[['size' => 1000, 'segments' => [1]], ['size' => 2000, 'segments' => [1, 2]]], [new Par2FileEntry('a', 990), new Par2FileEntry('b', 1995), new Par2FileEntry('c', 5)], null],
            Par2FileEntry::class => [[new Par2FileEntry('a.part1.rar', 5, md5('a')), new Par2FileEntry('a.vol0+1.par2', 5), new Par2FileEntry('a.nfo', 5)]],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * Function, data, int table and record count of a valid one-record (or two-record) C call.
     *
     * @param  class-string  $class
     * @return array{string, string, string, int}
     */
    private static function abiPacket(string $class): array
    {
        return match ($class) {
            NzbContentParser::class => ['nntmux_w525_nzb_subject_names', '"a.rar" yEnc', '', 1],
            SevenZipListingParser::class => ['nntmux_w525_sevenzip_line_plan', 'Path = a', '', 1],
            ImageInspector::class => ['nntmux_w525_image_header_probe', 'GIF', pack('P', 3), 1],
            Par2FileMapper::class => ['nntmux_w525_par2_candidate_plan', '', pack('P*', 0, 1, 0, 1000, 1, 990), 1],
            Par2FileEntry::class => ['nntmux_w525_par2_entry_volume_plan', "a.rar\0", '', 1],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * Invokes the class's private `w52RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w52RustFfiState'))->invoke(null, $op, $val);
    }

    private static function png(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00".pack('N', 0);
    }

    /** JPEG start: SOI, APP0, an APP1 of $exif bytes, optional fill bytes and DQT, SOF0 and a little scan. */
    private static function jpeg(int $width, int $height, int $exif, bool $extra = false): string
    {
        $out = "\xFF\xD8\xFF\xE0".pack('n', 16)."JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $out .= "\xFF\xE1".pack('n', $exif + 2).str_repeat("\x2a", $exif);
        if ($extra) {
            $out .= "\xFF\xFF\xFF\xDB".pack('n', 67).str_repeat("\x01", 65)."\xFF\xC4".pack('n', 4)."\x00\x00";
        }

        return $out."\xFF\xC0".pack('n', 17)."\x08".pack('nn', $height, $width)."\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xDA\x00\x0C";
    }

    private static function webp(string $chunk, int $width, int $height): string
    {
        $head = 'RIFF'.pack('V', 1000).'WEBP'.$chunk.pack('V', 100);

        return $head.match ($chunk) {
            'VP8 ' => "\x30\x01\x00\x9d\x01\x2a".pack('vv', $width, $height),
            'VP8L' => "\x2f".pack('V', ($width - 1) | ($height - 1) << 14).'xxxxx',
            default => "\x10\x00\x00\x00".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3),
        };
    }

    /** $length bytes from the seeded mt_rand() sequence. */
    private static function rawBytes(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= \chr(mt_rand(0, 255));
        }

        return $out;
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $out = '';
        for ($i = 0, $length = mt_rand(0, $maxLength); $i < $length; $i++) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $out .= $byte === "\0" && ! $withNul ? 'n' : $byte;
        }

        return $out;
    }

    private static function randomSubject(bool $withNul): string
    {
        if (mt_rand(0, 9) === 0) {
            return self::randomBytes(24, $withNul);
        }
        $out = '';
        for ($i = 0, $parts = mt_rand(1, 6); $i < $parts; $i++) {
            $out .= mt_rand(0, 5) === 0 ? self::randomBytes(4, $withNul) : self::SUBJECT_PARTS[mt_rand(0, \count(self::SUBJECT_PARTS) - 1)];
        }

        return $out;
    }

    private static function randomLine(bool $withNul): string
    {
        return match (mt_rand(0, 5)) {
            0 => self::randomBytes(16, $withNul),
            1 => ['', ' ', "\t \t", "\r", "\r\r", " \r", "\n"][mt_rand(0, 6)],
            2 => self::LINE_KEYS[mt_rand(0, \count(self::LINE_KEYS) - 1)].[' =', ' = ', '=', ' =x', ' = = '][mt_rand(0, 4)],
            default => self::LINE_KEYS[mt_rand(0, \count(self::LINE_KEYS) - 1)].' = '.self::randomBytes(10, $withNul).(mt_rand(0, 3) === 0 ? "\r" : ''),
        };
    }

    private static function randomHead(): string
    {
        $head = match (mt_rand(0, 6)) {
            0 => self::png(mt_rand(0, 70000), mt_rand(0, 70000)),
            1 => self::jpeg(mt_rand(1, 65535), mt_rand(1, 65535), mt_rand(0, 300), mt_rand(0, 1) === 1),
            2 => self::webp(['VP8 ', 'VP8L', 'VP8X', 'ABCD'][mt_rand(0, 3)], mt_rand(1, 16383), mt_rand(1, 16383)),
            3 => "\xFF\xD8\xFF".self::rawBytes(mt_rand(0, 40)),
            4 => ["\x89PNG\r\n\x1a\n", 'RIFF', "\xFF\xD8", 'GIF89a'][mt_rand(0, 3)].self::rawBytes(mt_rand(0, 40)),
            default => self::rawBytes(mt_rand(0, 48)),
        };
        if (mt_rand(0, 3) === 0 && $head !== '') {
            $head = substr($head, 0, mt_rand(0, \strlen($head)));
        }
        if (mt_rand(0, 5) === 0 && $head !== '') {
            $head[mt_rand(0, \strlen($head) - 1)] = \chr(mt_rand(0, 255));
        }

        return $head;
    }

    /**
     * @return array<string, mixed>|string
     */
    private static function randomSource(): array|string
    {
        return match (mt_rand(0, 7)) {
            0 => 'junk',
            1 => ['size' => mt_rand(0, 1) === 0 ? '0' : (string) mt_rand(1, 100_000), 'segments' => ['a']],
            2 => ['size' => mt_rand(1, 100_000), 'segments' => [], 'filecount' => 1],
            3 => ['size' => mt_rand(1, 100_000), 'segments' => range(1, mt_rand(1, 4)), 'filecount' => mt_rand(0, 3)],
            4 => ['segments' => [1]],
            default => ['size' => mt_rand(1, 100_000), 'segments' => range(1, mt_rand(1, 400))],
        };
    }

    private static function randomEntry(bool $withNul): Par2FileEntry
    {
        $name = mt_rand(0, 6) === 0
            ? self::randomBytes(12, $withNul)
            : self::VOLUME_NAMES[mt_rand(0, \count(self::VOLUME_NAMES) - 1)].self::VOLUME_SUFFIXES[mt_rand(0, \count(self::VOLUME_SUFFIXES) - 1)].(mt_rand(0, 8) === 0 ? self::VOLUME_SUFFIXES[mt_rand(0, \count(self::VOLUME_SUFFIXES) - 1)] : '');
        $hash = match (mt_rand(0, 4)) {
            0 => '',
            1 => md5($name),
            2 => strtoupper(md5($name)),
            3 => substr(md5($name), 0, mt_rand(0, 33)).self::randomBytes(2, $withNul),
            default => md5($name)."\n",
        };

        return new Par2FileEntry($name, mt_rand(1, 1000), $hash);
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    /**
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        /** @var string $cdef */
        $cdef = (new ReflectionClassConstant($class, 'W52_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('w52_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust W52.5 FFI library is not built or available.');
        }

        return $ffi;
    }
}
