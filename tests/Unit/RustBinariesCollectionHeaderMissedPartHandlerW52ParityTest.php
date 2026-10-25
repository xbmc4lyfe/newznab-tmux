<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\MissedPartHandler;
use App\Services\Binaries\PartHandler;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustBinariesCollectionHeaderMissedPartHandlerW52ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        BinaryHandler::class => 'planBinaryKeys',
        CollectionHandler::class => 'planCollectionKeys',
        HeaderParser::class => 'planSubjects',
        MissedPartHandler::class => 'planRepairBatch',
        PartHandler::class => 'planPartAdmission',
    ];

    /** Class => Wave 52.2 export. */
    private const SYMBOLS = [
        BinaryHandler::class => 'nntmux_w522_binary_key_plan_batch',
        CollectionHandler::class => 'nntmux_w522_collection_key_plan_batch',
        HeaderParser::class => 'nntmux_w522_header_subject_plan_batch',
        MissedPartHandler::class => 'nntmux_w522_missed_part_plan_batch',
        PartHandler::class => 'nntmux_w522_part_admission_plan_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns): the per-metric median of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 52, each metric
     * the median of 3 rounds of 1000 pairs after 50 warmups, each side the fastest of 3
     * back-to-back calls, randomized fixtures, alternating call order, release Rust, PHP 8.5.12
     * CLI without opcache, Apple Silicon macOS on a shared host at load average 6-10). Timings
     * include dispatch, packing, the FFI call and unpack('P*'). The runs' low was
     * MissedPartHandler Uncommon p99 1.456x in one run (1.897x and 1.912x in the others); one
     * output word per queued part measured 1.52x-1.64x on its mean, so the plan packs eight flag
     * bytes per word. A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        BinaryHandler::class => [3.861, 3.687, 4.465, 4.246],
        CollectionHandler::class => [3.115, 3.228, 3.157, 2.948],
        HeaderParser::class => [4.392, 4.135, 4.041, 3.914],
        MissedPartHandler::class => [1.894, 2.024, 1.821, 1.897],
        PartHandler::class => [5.036, 4.854, 4.833, 4.750],
    ];

    private const ROUNDS = 3;

    private const PAIRS = 1000;

    private const WARMUPS = 50;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW52RustFfi', 'withW52RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->hasMethod('w52RustFfiState'), $class);
            self::assertTrue($reflection->getMethod('w52RustFfiState')->isPrivate(), $class);
            self::assertTrue($reflection->hasConstant('W52_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W52_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W52_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W52_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w52', strtolower((string) $property), 'Wave 52 state lives in w52RustFfiState().');
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW52RustFfi(), $class);
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW52RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW52RustFfi(! $enabled, static fn (): bool => $class::usesW52RustFfi());

                    return [$class::usesW52RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW52RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW52RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW52RustFfi(), $class);
        }
        // The Wave 52 toggles leave the earlier-wave Rust switches untouched.
        BinaryHandler::withW52RustFfi(true, static function (): void {
            HeaderParser::withW52RustFfi(true, static function (): void {
                self::assertFalse(BinaryHandler::usesW411RustFfi());
                self::assertFalse(HeaderParser::usesW411RustFfi());
                self::assertFalse(CollectionHandler::usesW401RustFfi());
                self::assertFalse(MissedPartHandler::usesW421RustFfi());
                self::assertFalse(CollectionHandler::usesRustFfi());
                self::assertFalse(MissedPartHandler::usesRustFfi());
                self::assertTrue(PartHandler::usesRustFfi());
            });
        });
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5200));
        foreach (array_keys(self::ENTRIES) as $class) {
            $args = $this->fixture($class, $random, false);
            $php = $this->call($class, 'Php', $args);
            foreach ([true, false] as $native) {
                self::assertSame($php, $class::withW52RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
            }
            self::assertSame($php, $this->call($class, 'Native', $args), $class);
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes, ragged columns and keyed arrays keep the PHP semantics.
        $this->assertParity(HeaderParser::class, [["a\0b (1/2)", 'c (3/4)']]);
        $this->assertParity(BinaryHandler::class, [[md5('a'), "x\0y"], [1, 2], [0, 0], [1, 1]]);
        $this->assertParity(BinaryHandler::class, [[md5('a'), md5('b')], [1], [0, 0], [1, 1]]);
        $this->assertParity(CollectionHandler::class, [[1 => 'a', 2 => 'b'], [1 => 1, 2 => 2]]);
        $this->assertParity(CollectionHandler::class, [['a', 'b'], [1]]);
        $this->assertParity(MissedPartHandler::class, [[1, 2, 3], [0, 0], [2], 3]);
        $this->assertParity(MissedPartHandler::class, [[1, 2], [0, 0], [5 => 2], 3]);
        $this->assertParity(PartHandler::class, [["<a\0b>", '<c>'], [1, 1], [1, 1], [5, 5], [1, 2]]);
        $this->assertParity(PartHandler::class, [['<a>', '<c>'], [1, 1], [1], [5, 5], [1, 2]]);
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $headers = $this->ffi(HeaderParser::class);
        $out = $headers->new('int64_t[16]');
        $symbol = self::SYMBOLS[HeaderParser::class];
        self::assertSame(-1, $this->invoke($headers, $symbol, ['a (1/2)', 7, 1, null, 16]));
        self::assertSame(-9, $this->invoke($headers, $symbol, ['a (1/2)', 7, 1, $out, 8]));
        self::assertSame(-1, $this->invoke($headers, $symbol, ["a\0b", 3, 1, $out, 16]));
        self::assertSame(-1, $this->invoke($headers, $symbol, [null, 1, 1, $out, 16]));
        self::assertSame(6, $this->invoke($headers, $symbol, [null, 0, 0, $out, 16]));

        $binary = $this->ffi(BinaryHandler::class);
        $binaryOut = $binary->new('int64_t[16]');
        $binarySymbol = self::SYMBOLS[BinaryHandler::class];
        $table = pack('P*', 1, 0, 2);
        self::assertSame(7, $this->invoke($binary, $binarySymbol, ['h', 1, $table, 24, 1, $binaryOut, 16]));
        self::assertSame(-1, $this->invoke($binary, $binarySymbol, ['h', 1, $table, 16, 1, $binaryOut, 16]));
        self::assertSame(-1, $this->invoke($binary, $binarySymbol, ['h', 1, null, 24, 1, $binaryOut, 16]));
        self::assertSame(-7, $this->invoke($binary, $binarySymbol, ['h', 1, $table, 24, 1, $binaryOut, 3]));

        $collection = $this->ffi(CollectionHandler::class);
        $collectionOut = $collection->new('int64_t[16]');
        $collectionSymbol = self::SYMBOLS[CollectionHandler::class];
        self::assertSame(7, $this->invoke($collection, $collectionSymbol, ['a', 1, pack('P', 3), 8, 1, $collectionOut, 16]));
        self::assertSame(-1, $this->invoke($collection, $collectionSymbol, ['a', 1, pack('P', 3), 8, 2, $collectionOut, 16]));
        self::assertSame(-1, $this->invoke($collection, $collectionSymbol, ['a', 1, pack('P', 3), 8, 1, null, 16]));

        $missed = $this->ffi(MissedPartHandler::class);
        $missedOut = $missed->new('int64_t[16]');
        $missedSymbol = self::SYMBOLS[MissedPartHandler::class];
        $ints = pack('P*', 4, 5, 0, 1);
        self::assertSame(10, $this->invoke($missed, $missedSymbol, [$ints, 32, 2, pack('P', 5), 8, 1, 3, $missedOut, 16]));
        self::assertSame(-1, $this->invoke($missed, $missedSymbol, [$ints, 32, 2, pack('P', 5), 8, 2, 3, $missedOut, 16]));
        self::assertSame(-1, $this->invoke($missed, $missedSymbol, [$ints, 32, 2, null, 8, 1, 3, $missedOut, 16]));
        self::assertSame(-10, $this->invoke($missed, $missedSymbol, [$ints, 32, 2, '', 0, 0, 3, $missedOut, 9]));

        $part = $this->ffi(PartHandler::class);
        $partOut = $part->new('int64_t[16]');
        $partSymbol = self::SYMBOLS[PartHandler::class];
        $columns = pack('P*', 1, 1, 10, 7);
        self::assertSame(7, $this->invoke($part, $partSymbol, ['<m>', 3, $columns, 32, 1, $partOut, 16]));
        self::assertSame(-1, $this->invoke($part, $partSymbol, ['<m>', 3, $columns, 24, 1, $partOut, 16]));
        self::assertSame(-1, $this->invoke($part, $partSymbol, ["<m>\0", 4, $columns, 32, 1, $partOut, 16]));
    }

    public function test_binary_key_plan_parity(): void
    {
        $this->requireBindings();
        $a = md5('a');
        $upper = strtoupper(md5('b'));
        $lower = strtolower($upper);
        $words = [
            3 | 32 | crc32($a.':5') << 32,
            3 | 8 | 1 << 8 | crc32($lower.':5') << 32,
            3 | 4 | 32 | crc32($a.':5') << 32,
            3 | 4 | 1 << 8 | crc32($lower.':5') << 32,
            1 | 16 | 4 << 8 | crc32($a.':0') << 32,
            32 | 5 << 8 | crc32('bad:-2') << 32,
            4, 2, 2, 1, 1,
        ];
        $args = [[$a, $upper, $a, $lower, $a, 'bad'], [5, 5, 5, 5, 0, -2], [0, 3, -1, 2, 9, 0], [0, 5, 5, 5, 8, 0]];
        self::assertSame([...$words, $this->digest($words)], BinaryHandler::planBinaryKeysPhp(...$args));
        $this->assertParity(BinaryHandler::class, $args);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->binaryBatch(new Randomizer(new Mt19937(5210 + $i)), $uncommon);
                $this->assertParity(BinaryHandler::class, $args);
                $words = BinaryHandler::planBinaryKeysPhp(...$args);
                foreach ($args[0] as $row => $hash) {
                    self::assertSame(crc32(strtolower($hash).':'.$args[1][$row]), ($words[$row] >> 32) & 0xFFFFFFFF, $hash);
                }
            }
        }
    }

    public function test_collection_key_plan_parity(): void
    {
        $this->requireBindings();
        $sha = static function (string $key): int {
            /** @var array{1: int} $head */
            $head = unpack('J', sha1($key, true));

            return $head[1];
        };
        $long = str_repeat('n', 256);
        $words = [
            1 | crc32('a12') << 32, $sha('a12'),
            2 | crc32('a12') << 32, $sha('a12'),
            1 | 4 | 8 | 2 << 8 | crc32('0') << 32, $sha('0'),
            1 | 16 | 3 << 8 | crc32($long.'-1') << 32 | 8, $sha($long.'-1'),
            1 | 4 | 8 | 4 << 8 | crc32('-9223372036854775808') << 32, $sha('-9223372036854775808'),
            4, 1, 2, 3,
        ];
        $args = [['a1', 'a', '', $long, ''], [2, 12, 0, -1, PHP_INT_MIN]];
        self::assertSame([...$words, $this->digest($words)], CollectionHandler::planCollectionKeysPhp(...$args));
        $this->assertParity(CollectionHandler::class, $args);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->collectionBatch(new Randomizer(new Mt19937(5220 + $i)), $uncommon);
                $this->assertParity(CollectionHandler::class, $args);
                $words = CollectionHandler::planCollectionKeysPhp(...$args);
                foreach ($args[0] as $row => $name) {
                    self::assertSame($sha($name.$args[1][$row]), $words[2 * $row + 1]);
                }
            }
        }
    }

    public function test_header_subject_plan_parity(): void
    {
        $this->requireBindings();
        $subjects = ['[1/3] "a.rar" yEnc (1/50)', 'no counter', 'b (2/1)', ' Usenet Index Post (0/0)', 'Usenet Index Post x (1/2)'];
        $words = [
            1 | 16 | 18 << 8 | crc32('[1/3] "a.rar" yEnc') << 32, 1, 50,
            0, 0, 0,
            1 | 2 | 4 | 6 << 8 | crc32('b yEnc') << 32, 2, 1,
            1 | 2 | 8 | 23 << 8 | crc32(' Usenet Index Post yEnc') << 32, 0, 0,
            0, 0, 0,
            3, 2, 2, 1, 50,
        ];
        self::assertSame([...$words, $this->digest($words)], HeaderParser::planSubjectsPhp($subjects));
        $this->assertParity(HeaderParser::class, [$subjects]);
        $pattern = (new ReflectionClass(HeaderParser::class))->getConstant('SUBJECT_PATTERN');
        self::assertIsString($pattern);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->subjectBatch(new Randomizer(new Mt19937(5230 + $i)), $uncommon);
                $this->assertParity(HeaderParser::class, $args);
                $words = HeaderParser::planSubjectsPhp(...$args);
                foreach ($args[0] as $row => $subject) {
                    $matched = preg_match($pattern, $subject, $matches) === 1;
                    self::assertSame($matched, ($words[3 * $row] & 1) === 1, $subject);
                    if ($matched) {
                        self::assertSame((int) $matches[2], $words[3 * $row + 1], $subject);
                        self::assertSame((int) $matches[3], $words[3 * $row + 2], $subject);
                    }
                }
            }
        }
    }

    public function test_missed_part_plan_parity(): void
    {
        $this->requireBindings();
        $words = [(2 | 32) | 2 << 8 | (2 | 32) << 16 | 8 << 24 | 16 << 32 | 4 << 40 | 1 << 48, 1, 3, 1, 1, 1, 2, 5, 8];
        $args = [[5, 6, 8, 6, 0, 9, 10], [0, 0, 0, 0, 0, 2, 0], [10, 77], 3];
        self::assertSame([...$words, $this->digest($words)], MissedPartHandler::planRepairBatchPhp(...$args));
        $this->assertParity(MissedPartHandler::class, $args);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->missedBatch(new Randomizer(new Mt19937(5240 + $i)), $uncommon);
                $this->assertParity(MissedPartHandler::class, $args);
                $words = MissedPartHandler::planRepairBatchPhp(...$args);
                $count = \count($args[0]);
                $flags = array_map(static fn (int $row): int => ($words[intdiv($row, 8)] >> (8 * ($row % 8))) & 0xFF, range(0, $count - 1));
                $packed = intdiv($count + 7, 8);
                self::assertSame($words[$packed + 1], \count(array_filter($flags, static fn (int $f): bool => ($f & 2) === 2)));
                self::assertSame($words[$packed + 5], \count(array_filter($flags, static fn (int $f): bool => ($f & 32) === 32)));
            }
        }
    }

    public function test_part_admission_plan_parity(): void
    {
        $this->requireBindings();
        $words = [
            2 << 8 | crc32('<a>') << 32,
            8 | 2 << 8 | crc32('<b>') << 32,
            4 | 8 | 2 << 8 | crc32('<c>') << 32,
            2 | 3 << 8 | crc32("<d>\x01") << 32,
            1 | 4 << 8 | crc32('<e>') << 32,
            3, 1, 1, 1, 2,
        ];
        $args = [[" <a>\n", '<b>', '<c>', "<d>\x01", '<e>'], [1, 1, 1, 1, 2], [1, 1, 1, 1, 0], [5, 9, 9, 1, 1], [3, 4, 2, 1, 1]];
        self::assertSame([...$words, $this->digest($words)], PartHandler::planPartAdmissionPhp(...$args));
        $this->assertParity(PartHandler::class, $args);
        $handler = new PartHandler;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->partBatch(new Randomizer(new Mt19937(5250 + $i)), $uncommon);
                $this->assertParity(PartHandler::class, $args);
                $words = PartHandler::planPartAdmissionPhp(...$args);
                $rows = $survivors = [];
                foreach ($args[0] as $row => $id) {
                    $header = ['matches' => [2 => (string) $args[2][$row]], 'Message-ID' => $id];
                    $error = PartHandler::validationErrorPhp($header);
                    self::assertSame($error === 'Invalid part number', ($words[$row] & 1) === 1, $id);
                    self::assertSame($error === 'Invalid message ID', ($words[$row] & 2) === 2, $id);
                    if ($error === null) {
                        $rows[] = ['binaries_id' => $args[1][$row], 'number' => $args[4][$row], 'messageid' => trim($id), 'partnumber' => $args[2][$row], 'size' => $args[3][$row]];
                    }
                    if (($words[$row] & 4) === 4) {
                        $survivors[] = $row;
                    }
                }
                self::assertCount(\count($handler->deduplicatePartsPhp($rows)), $survivors);
            }
        }
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $args): void
    {
        $this->requireBindings();
        $this->assertParity($class, $args);
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<mixed>}> */
    public static function edgeCases(): iterable
    {
        $md5 = md5('x');
        yield 'binary empty' => [BinaryHandler::class, [[], [], [], []]];
        yield 'binary hash shapes' => [BinaryHandler::class, [['', substr($md5, 1), $md5.'0', strtoupper($md5), str_replace('a', 'g', $md5), "\xff".substr($md5, 1), " {$md5}", $md5],
            [1, 1, 1, 1, 1, 1, 1, PHP_INT_MAX], [0, 1, PHP_INT_MIN, PHP_INT_MAX, -1, 2, 3, 4], [0, 0, 1, 1, PHP_INT_MAX, PHP_INT_MIN, 2, 3]]];
        yield 'binary repeated keys' => [BinaryHandler::class, [[$md5, strtoupper($md5), $md5, $md5, $md5], [7, 7, 8, 7, PHP_INT_MIN], [1, 2, 3, 4, 5], [3, 3, 3, 3, 3]]];
        yield 'collection empty' => [CollectionHandler::class, [[], []]];
        yield 'collection ambiguous keys' => [CollectionHandler::class, [['a1', 'a', 'a', 'a-', 'a', '', '1', "\xff\xfe", str_repeat('k', 255), str_repeat('k', 256), str_repeat('k', 70000)],
            [2, 12, -12, 12, -12, 12, 2, 0, 1, 1, PHP_INT_MAX]]];
        yield 'subjects empty' => [HeaderParser::class, [[]]];
        yield 'subjects pattern edges' => [HeaderParser::class, [['', ' ', '(1/2)', ' (1/2)', '  (1/2)', '   (1/2)', "\t\n (1/2)", "\n\n (1/2)", "a\n (1/2)", "a\nb (1/2)", "a\rb (1/2)", 'Usenet Index Post (1/2)',
            ' Usenet Index Post (1/2)', "\tUsenet Index Post a (1/2)", 'usenet index post (1/2)', 'a (1/x) b (2/3)', 'a (/2)', 'a (1/)', 'a(1/2)', "a\x0b(1/2)", "a \x0c\r(1/2)", 'a (1/2', 'a  (01/002) yEnC', 'a (0/0)',
            'yenc (3/4)', 'YENC b (3/4)', 'ye nc (3/4)', 'a (1/2) (3/4) (5/6)', 'a (1/2)x', 'x'.str_repeat(' ', 3000).'(1/2)', str_repeat(' ', 3000).'(1/2)', str_repeat("\n", 300).' (1/2)']]];
        yield 'subjects numeric edges' => [HeaderParser::class, [['a ('.str_repeat('9', 19).'/'.str_repeat('9', 19).')', 'a ('.str_repeat('9', 20).'/1)', 'a (1/'.str_repeat('9', 308).')', 'a (1/'.str_repeat('9', 309).')',
            'a (1/'.str_repeat('9', 400).')', 'a (1/'.str_repeat('0', 400).'7)', 'a (9223372036854775807/9223372036854775808)']]];
        yield 'subjects bytes' => [HeaderParser::class, [["\xff\xfe (1/2)", "\xc3\xa9t\xc3\xa9 yEnc (1/2)", "a\x85b (1/2)", "a\xa0(1/2)", "\x01\x02 (3/4)", str_repeat('x', 70000).' (1/2)']]];
        yield 'missed empty' => [MissedPartHandler::class, [[], [], [], 3]];
        yield 'missed received only' => [MissedPartHandler::class, [[1], [0], [1, 1, 1, PHP_INT_MIN, PHP_INT_MAX], 3]];
        yield 'missed bounds' => [MissedPartHandler::class, [[PHP_INT_MAX, 1, PHP_INT_MIN, 0, -1, 2, PHP_INT_MAX, 3], [0, PHP_INT_MIN, 0, 0, 0, PHP_INT_MAX, 0, -1], [], PHP_INT_MIN]];
        yield 'missed max tries' => [MissedPartHandler::class, [[10, 11, 12, 14, 15, 13, 16], [0, 1, 2, 0, 5, 0, 1], [14], PHP_INT_MAX]];
        yield 'missed one try' => [MissedPartHandler::class, [[10, 11, 12], [0, -1, 1], [], 1]];
        yield 'missed zero tries' => [MissedPartHandler::class, [[10, 11, 12], [0, -1, 1], [], 0]];
        yield 'missed unordered' => [MissedPartHandler::class, [[9, 3, 9, 4, 5, 1, 3, 2, 8, 7], [0, 0, 0, 0, 0, 0, 0, 0, 0, 0], [5, 2], 3]];
        yield 'parts empty' => [PartHandler::class, [[], [], [], [], []]];
        yield 'parts ids' => [PartHandler::class, [['', ' ', "\t\n\r\x0b", "\x0c", '<a b>', "<a\x7f>", "<\xff>", '~', str_repeat('m', 255), str_repeat('m', 256), " \x0b".str_repeat('m', 255)."\n", '<a>', '<a>'],
            [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 2, 2], [1, 1, 1, 1, 1, 1, 1, 2, 3, 4, 5, 0, -1], [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1], [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]]];
        yield 'parts preference' => [PartHandler::class, [['<a>', '<b>', '<c>', '<d>', '<e>', '<f>'], [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
            [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 1, 1, 1], [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX, 0, 0, -1], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, 5, 5, 5]]];
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture, alternating which runs first; each side
     * keeps the fastest of REPEATS back-to-back calls. Common batches hold 64 typical records,
     * Uncommon batches 128-256 long, malformed or adversarial ones. Each of ROUNDS rounds times
     * PAIRS pairs after WARMUPS warmups; a metric is the median of its round values (nearest-rank
     * p99 per round). Every pair must agree; raw pairs are written outside the checkout. A class
     * must default to Rust exactly when all four metrics reach 1.5x.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(52));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $metrics = [];
            foreach (['Common', 'Uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
                $rounds = [];
                for ($round = 0; $round < self::ROUNDS; $round++) {
                    $php = $rust = [];
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW52RustFfi($native, fn (): array => $this->timed($class, $args));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $php[] = $elapsed[0];
                            $rust[] = $elapsed[1];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                    sort($php);
                    sort($rust);
                    $p99 = (int) ceil(\count($php) * 0.99) - 1;
                    $rounds[] = [
                        'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                        'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                        'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                    ];
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => $this->median(array_column($rounds, 'mean_speedup')), 'p99_speedup' => $this->median(array_column($rounds, 'p99_speedup')),
                    'php_mean_ns' => $this->median(array_column($rounds, 'php_mean_ns')), 'rust_mean_ns' => $this->median(array_column($rounds, 'rust_mean_ns')),
                    'php_p99_ns' => $this->median(array_column($rounds, 'php_p99_ns')), 'rust_p99_ns' => $this->median(array_column($rounds, 'rust_p99_ns')),
                    'rounds' => $rounds,
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w522-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => 52, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW52.2 paired benchmark artifact: {$artifact}\n".json_encode(array_map(static fn (array $class): array => array_map(static fn (mixed $w): mixed => \is_array($w) ? array_diff_key($w, ['rounds' => true]) : $w, $class), $summary), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW52RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $args);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W52_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $args): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix](...$args);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $php = $class::withW52RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW52RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w52RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w52 binding');

        return $ffi;
    }

    /** Each Wave 52.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            BinaryHandler::class => [[md5('a'), 32, pack('P*', 1, 0, 1), 24, 1], 7],
            CollectionHandler::class => [['a', 1, pack('P', 1), 8, 1], 7],
            HeaderParser::class => [['a (1/2)', 7, 1], 9],
            MissedPartHandler::class => [[pack('P*', 1, 0), 16, 1, '', 0, 0, 3], 10],
            PartHandler::class => [['<a>', 3, pack('P*', 1, 1, 1, 1), 32, 1], 7],
        ];
        foreach ($calls as $class => [$args, $words]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[16]');
            self::assertSame($words, $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 16]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W52_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            BinaryHandler::class => $this->binaryBatch($random, $uncommon),
            CollectionHandler::class => $this->collectionBatch($random, $uncommon),
            HeaderParser::class => $this->subjectBatch($random, $uncommon),
            MissedPartHandler::class => $this->missedBatch($random, $uncommon),
            PartHandler::class => $this->partBatch($random, $uncommon),
        };
    }

    /** @param  list<string>  $values */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789.-_:/ "\'()[]<>@', $length);
    }

    /** A release file name as uploaders post it. */
    private function fileName(Randomizer $random): string
    {
        $stem = $this->pick($random, ['Some.Show.S01E02.1080p.WEB.h264-GRP', 'Linux.Distro.2026.x64.ISO', 'Album-Artist-2026-FLAC', 'Movie.2026.2160p.UHD.BluRay.x265']);

        return $stem.$this->pick($random, ['.part'.str_pad((string) $random->getInt(1, 99), 2, '0', STR_PAD_LEFT).'.rar', '.vol'.$random->getInt(0, 63).'+'.$random->getInt(1, 64).'.par2', '.par2', '.nfo', '.sfv', '.7z.'.str_pad((string) $random->getInt(1, 999), 3, '0', STR_PAD_LEFT)]);
    }

    /** A posted file subject: `[nn/total] - "name" yEnc (part/parts)` and close variants. */
    private function subject(Randomizer $random): string
    {
        $total = $random->getInt(1, 120);
        $index = $random->getInt(1, $total);
        $parts = $random->getInt(1, 400);

        return match ($random->getInt(0, 4)) {
            0 => "[{$index}/{$total}] - \"{$this->fileName($random)}\" yEnc ({$random->getInt(1, $parts)}/{$parts})",
            1 => "Group.Post [{$index}/{$total}] \"{$this->fileName($random)}\" - ".$random->getInt(1, 9_999_999)." bytes yEnc ({$random->getInt(1, $parts)}/{$parts})",
            2 => "({$index}/{$total}) \"{$this->fileName($random)}\" ({$random->getInt(1, $parts)}/{$parts}) ",
            3 => bin2hex($random->getBytes(16))." [{$index}/{$total}] - \"".bin2hex($random->getBytes(10))."\" yEnc ({$random->getInt(1, $parts)}/{$parts}) ".$random->getInt(1, 9_999_999),
            default => "{$this->fileName($random)} yEnc ({$random->getInt(1, $parts)}/{$parts})",
        };
    }

    /** @return list<mixed> */
    private function binaryBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $files = $random->getInt(2, 12);
        $collection = $random->getInt(1, 5_000_000);
        $hashes = $collections = $numbers = $totals = [];
        for ($i = 0; $i < $count; $i++) {
            $file = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, $files);
            $hash = $file > 0 ? md5('file:'.$file) : md5('subject:'.$this->fileName($random)."\0poster:p@x");
            $hashes[] = ! $uncommon ? $hash : match ($random->getInt(0, 9)) {
                0 => strtoupper($hash),
                1 => substr($hash, 0, $random->getInt(0, 31)),
                2 => $hash.$this->pick($random, ['0', ' ', "\n"]),
                3 => $this->noise($random, 40, true),
                4 => sha1($hash),
                default => $hash,
            };
            $collections[] = ! $uncommon ? $collection + $random->getInt(0, 3) : (int) $this->pick($random, [(string) $collection, (string) ($collection + 1), '0', '-1', (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
            $numbers[] = ! $uncommon ? $file : (int) $this->pick($random, [(string) $file, '-1', (string) PHP_INT_MAX, (string) PHP_INT_MIN, (string) ($files + 1)]);
            $totals[] = ! $uncommon ? $files : (int) $this->pick($random, [(string) $files, '0', '-3', (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
        }

        return [$hashes, $collections, $numbers, $totals];
    }

    /** @return list<mixed> */
    private function collectionBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $pool = [];
        for ($p = $random->getInt(4, 16); $p > 0; $p--) {
            $pool[] = strtolower(str_replace(['.', '-'], ' ', $this->fileName($random)));
        }
        $names = $totals = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $this->pick($random, $pool);
            $names[] = ! $uncommon ? $name : match ($random->getInt(0, 9)) {
                0 => '',
                1 => str_repeat($name, $random->getInt(10, 40)),
                2 => (string) $random->getInt(0, 99),
                3 => $this->noise($random, 120, true),
                4 => $name.$random->getInt(0, 9),
                default => $name,
            };
            $totals[] = ! $uncommon ? $random->getInt(1, 60) : (int) $this->pick($random, [(string) $random->getInt(1, 60), '0', '-1', (string) $random->getInt(0, 9), (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
        }

        return [$names, $totals];
    }

    /** @return list<mixed> */
    private function subjectBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $subjects = [];
        for ($i = 0; $i < $count; $i++) {
            $subject = $this->subject($random);
            $subjects[] = $random->getInt(0, 15) === 0 ? ($subjects[$random->getInt(0, max(0, $i - 1))] ?? $subject) : (! $uncommon ? $subject : match ($random->getInt(0, 13)) {
                0 => $this->noise($random, 160),
                1 => $this->noise($random, 120, true),
                2 => str_replace(' ', $this->pick($random, ["\n", "\r", "\t", "\x0b", '  ', '']), $subject),
                3 => $subject.$this->pick($random, ["\n", "\r\n", "\x0b\x0c", ' x', ')', ' (1/)', ' (/2)', ' (01/02)']),
                4 => $this->pick($random, [' ', "\t", "\n", '  ', 'Usenet Index Post ', ' Usenet Index Post ']).$subject,
                5 => $subject.' (1/'.str_repeat('9', $random->getInt(18, 320)).')',
                6 => str_repeat($this->fileName($random).' ', $random->getInt(10, 30)).'('.$random->getInt(0, 9).'/'.$random->getInt(0, 9).')',
                7 => '(x) [1/2]x [3/4]( [5/6] '.$subject,
                8 => str_ireplace('yEnc', $this->pick($random, ['YENC', 'yenc', 'y Enc', '']), $subject),
                default => $subject,
            });
        }

        return [$subjects];
    }

    /** @return list<mixed> */
    private function missedBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $maxTries = $uncommon ? (int) $this->pick($random, ['3', '1', '0', '-2', '5', (string) PHP_INT_MAX, (string) PHP_INT_MIN]) : 3;
        $number = $random->getInt(1_000_000, 4_000_000_000);
        $numbers = $attempts = $received = [];
        for ($i = 0; $i < $count; $i++) {
            $number += $random->getInt(0, 4) === 0 ? $random->getInt(2, 50) : 1;
            $numbers[] = ! $uncommon ? $number : (int) match ($random->getInt(0, 11)) {
                0 => (string) ($numbers[$random->getInt(0, max(0, $i - 1))] ?? $number),
                1 => $this->pick($random, ['0', '-1', (string) PHP_INT_MIN]),
                2 => (string) PHP_INT_MAX,
                3 => (string) $random->getInt(1, $number),
                default => (string) $number,
            };
            $attempts[] = ! $uncommon ? $random->getInt(0, 2) : (int) $this->pick($random, [(string) $random->getInt(0, 6), '-1', (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
            if ($random->getInt(0, 2) === 0) {
                $received[] = $numbers[$i];
            }
        }
        if ($uncommon) {
            for ($extra = $random->getInt(0, 40); $extra > 0; $extra--) {
                $received[] = $random->getInt(0, 1) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $number + $random->getInt(1, 100);
            }
            shuffle($received);
        }

        return [$numbers, $attempts, $received, $maxTries];
    }

    /** @return list<mixed> */
    private function partBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $host = $this->pick($random, ['news.example.com', 'powerpost2000AA.local', 'ngPost.com', 'JBinUp.local']);
        $binary = $random->getInt(1, 50_000_000);
        $article = $random->getInt(1_000_000, 4_000_000_000);
        $ids = $binaries = $parts = $sizes = $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $part = $random->getInt(1, 40);
            $id = 'part'.$part.'of40.'.bin2hex($random->getBytes(8)).'@'.$host;
            $id = $random->getInt(0, 1) === 0 ? '<'.$id.'>' : $id;
            $ids[] = ! $uncommon ? ($random->getInt(0, 7) === 0 ? " {$id}\n" : $id) : match ($random->getInt(0, 11)) {
                0 => $this->pick($random, ['', ' ', "\t\r\n", "\x0b", "\x0c", '<a b>', "<a\tb>", "a\x7f@b", "<\x1f>", '~']),
                1 => $this->noise($random, 80, true),
                2 => '<'.str_repeat('m', $random->getInt(240, 300)).'@'.$host.'>',
                3 => "\x0b\t ".$id." \r\n",
                default => $id,
            };
            $binaries[] = ! $uncommon ? $binary + $random->getInt(0, 3) : (int) $this->pick($random, [(string) $binary, (string) PHP_INT_MAX, (string) PHP_INT_MIN, '0']);
            $parts[] = ! $uncommon ? $part : (int) $this->pick($random, [(string) $part, '0', '-1', (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
            $sizes[] = ! $uncommon ? $random->getInt(700_000, 700_010) : (int) $this->pick($random, [(string) $random->getInt(0, 3), '0', (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
            $numbers[] = ! $uncommon ? $article + $i : (int) $this->pick($random, [(string) ($article + $i), (string) $article, (string) PHP_INT_MAX, (string) PHP_INT_MIN]);
        }

        return [$ids, $binaries, $parts, $sizes, $numbers];
    }
}
