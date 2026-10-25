<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustBinariesConfigServiceHandlerHeaderW41ParityTest extends TestCase
{
    private const CLASSES = [
        BinariesConfig::class,
        BinariesService::class,
        BinaryHandler::class,
        HeaderParser::class,
        HeaderStorageService::class,
    ];

    /** Helper name => owning class. */
    private const HELPERS = [
        'summarizeChunkSettingsBatch' => BinariesConfig::class,
        'summarizeScanRangeBatch' => BinariesService::class,
        'summarizeBinaryRecordBatch' => BinaryHandler::class,
        'summarizeHeaderBatch' => HeaderParser::class,
        'summarizeStorageChunkBatch' => HeaderStorageService::class,
    ];

    private const RANDOM_CASES = 1_000;

    private const HASH = '0123456789abcdef0123456789abcdef';

    private const INTS = [
        null, true, false, 0, 1, 2, 3, 5, 10, 16, -1, 100, 101, 500, 501, 10_000, 10_001, PHP_INT_MAX, PHP_INT_MAX - 1, PHP_INT_MIN, 1.0, 99.5, '', '0', '1',
        '2', '10', '-1', '-0', '007', '1.0', ' 1', '1 ', '+1', '1e3', '0x1', '999999999999999999', '1234567890123456789', '-', 'abc', [],
    ];

    private const STRINGS = [
        null, 5, 1.5, true, [], '', ' ', " \t\n\r\0\x0B", "\x0C", 'A', 'yEnc', 'YENC', 'yen', 'xyencx', '(1/2)', '(1/2', '(/2)', '(1/)', '((12/345)', '( 1/2)',
        '(١/٢)', 'Show - "a.rar" yEnc (01/50)', '[1/2] - "A.rar" yEnc', '<abc@example.com>', self::HASH, '0123456789ABCDEF0123456789ABCDEF', self::HASH.'0',
        'ÿENC', "\xff\xfe", 'ÄÄÄÄ', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
        .'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW411RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_follow_the_benchmark_and_toggle_independently(): void
    {
        // No helper cleared 1.5x on every required workload; see the benchmark below.
        $defaults = [];
        foreach (self::CLASSES as $class) {
            $defaults[$class] = $this->rustDefault($class);
        }
        $this->assertSame([BinariesConfig::class => false, BinariesService::class => false, BinaryHandler::class => false, HeaderParser::class => false,
            HeaderStorageService::class => false], $defaults);
        foreach (self::CLASSES as $class) {
            $default = $this->rustDefault($class);
            $this->assertSame($default, $class::usesW411RustFfi(), $class);
            $class::useW411RustFfi(! $default);
            $this->assertSame(! $default, $class::usesW411RustFfi(), $class);
            foreach ($defaults as $other => $otherDefault) {
                if ($other !== $class) {
                    $this->assertSame($otherDefault, $other::usesW411RustFfi(), $other.' must not follow '.$class);
                }
            }
            $class::useW411RustFfi($default);
            $this->assertSame($default, $class::usesW411RustFfi(), $class);
            $class::useW411RustFfi();
            $this->assertTrue($class::usesW411RustFfi(), $class);
            $class::resetW411RustFfi();
            $this->assertSame([$default, null], [$class::usesW411RustFfi(), $this->ffiSlot($class)], $class);
        }

        // The Wave 41.1 toggles are separate from the earlier helpers' toggles on these classes.
        $earlier = [BinariesConfig::class => BinariesConfig::usesRustFfi(), BinariesService::class => BinariesService::usesRustFfi(),
            HeaderStorageService::class => HeaderStorageService::usesRustFfi()];
        foreach ($earlier as $class => $before) {
            $class::useW411RustFfi(! $before);
            $this->assertSame($before, $class::usesRustFfi(), $class);
            $class::setUseRustFfi(! $before);
            $this->assertSame(! $before, $class::usesW411RustFfi(), $class);
            $class::setUseRustFfi($before);
            $class::resetW411RustFfi();
            $this->assertSame($before, $class::usesRustFfi(), $class);
        }
        BinariesConfig::setUseRustFfi(null);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'configs' => 6, 'invalid_rows' => 1, 'non_positive' => 2, 'header_exceeds_sql' => 1, 'over_cap' => 1, 'obfuscation_disabled' => 2, 'max_sql_chunk' => 2_000,
        ], BinariesConfig::summarizeChunkSettingsBatch([
            ['headerChunkSize' => 500, 'sqlChunkSize' => 500, 'perArticleObfuscationMinArticles' => 16],
            ['headerChunkSize' => '800', 'sqlChunkSize' => '400', 'perArticleObfuscationMinArticles' => '0'],
            ['headerChunkSize' => 10, 'sqlChunkSize' => 2_000, 'perArticleObfuscationMinArticles' => 0],
            ['headerChunkSize' => 0, 'sqlChunkSize' => 500],
            ['headerChunkSize' => 5.0, 'sqlChunkSize' => 5, 'perArticleObfuscationMinArticles' => 1],
            'row',
        ], 1_000));

        $this->assertSame([
            'ranges' => 7, 'invalid_rows' => 2, 'negative' => 1, 'empty' => 1, 'valid' => 3, 'capped' => 2, 'total_articles' => PHP_INT_MAX,
        ], BinariesService::summarizeScanRangeBatch([
            ['first' => 10, 'last' => 19],
            ['first' => '5', 'last' => '500'],
            ['first' => 20, 'last' => 19],
            ['first' => -1, 'last' => 5],
            ['first' => 1, 'last' => 'x'],
            ['first' => 0, 'last' => PHP_INT_MAX],
            null,
        ], 100));
        $this->assertSame(['ranges' => 1, 'invalid_rows' => 0, 'negative' => 0, 'empty' => 0, 'valid' => 1, 'capped' => 0, 'total_articles' => 500],
            BinariesService::summarizeScanRangeBatch([['first' => 1, 'last' => 500]]));

        $this->assertSame([
            'records' => 7, 'invalid_rows' => 1, 'bad_hash' => 2, 'bad_collection' => 2, 'duplicate_keys' => 1, 'file_over_total' => 1, 'total_parts' => PHP_INT_MAX,
        ], BinaryHandler::summarizeBinaryRecordBatch([
            ['hash' => self::HASH, 'collections_id' => 1, 'totalparts' => 10, 'filenumber' => 3],
            ['hash' => self::HASH, 'collections_id' => '1', 'totalparts' => '10', 'filenumber' => 11],
            ['hash' => self::HASH, 'collections_id' => 2, 'totalparts' => 5, 'filenumber' => 1],
            ['hash' => strtoupper(self::HASH), 'collections_id' => 1, 'totalparts' => 0, 'filenumber' => 1],
            ['hash' => self::HASH, 'collections_id' => 0, 'totalparts' => -4],
            ['totalparts' => PHP_INT_MAX, 'filenumber' => 1],
            7,
        ]));

        $this->assertSame([
            'headers' => 6, 'invalid_rows' => 1, 'missing_subject' => 2, 'yenc' => 2, 'part_counter' => 2, 'bad_number' => 2, 'min_number' => 7, 'max_number' => 250,
        ], HeaderParser::summarizeHeaderBatch([
            ['Subject' => 'Show - "a.rar" yEnc (1/50)', 'Number' => 100],
            ['Subject' => " \t", 'Number' => '7'],
            ['Number' => 0],
            ['Subject' => 'no counter YENC', 'Number' => 250],
            ['Subject' => '(3/4)', 'Number' => 9.0],
            false,
        ]));
        $this->assertSame([0, 0], array_values(array_slice(HeaderParser::summarizeHeaderBatch([['Subject' => 'x', 'Number' => -1]]), 6)));

        $this->assertSame([
            'headers' => 5, 'invalid_rows' => 1, 'chunks' => 3, 'missing_message_id' => 2, 'bad_bytes' => 1, 'oversized_subject' => 1, 'total_bytes' => PHP_INT_MAX,
        ], HeaderStorageService::summarizeStorageChunkBatch([
            ['Message-ID' => '<a@b>', 'Bytes' => 100, 'Subject' => 's'],
            ['Message-ID' => ' ', 'Bytes' => '20', 'Subject' => str_repeat('a', 256)],
            ['Bytes' => -1, 'Subject' => str_repeat('a', 255)],
            ['Message-ID' => '<c@d>', 'Bytes' => PHP_INT_MAX],
            'x',
        ], 2));
        $this->assertSame(0, HeaderStorageService::summarizeStorageChunkBatch([], 0)['chunks']);
        $this->assertSame(1, HeaderStorageService::summarizeStorageChunkBatch(['x'], PHP_INT_MAX)['chunks']);
        $this->assertSame(3, HeaderStorageService::summarizeStorageChunkBatch(['x', 'y', 'z'], -5)['chunks']);
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $ints = count(self::INTS);

        $configs = [];
        foreach (self::INTS as $i => $header) {
            foreach (self::INTS as $j => $sql) {
                $configs[] = ['headerChunkSize' => $header, 'sqlChunkSize' => $sql, 'perArticleObfuscationMinArticles' => self::INTS[($i + $j) % $ints]];
            }
        }
        $configs[] = 'row';
        $configs[] = [];
        foreach ([10_000, 0, -1, 1, 500, PHP_INT_MAX, PHP_INT_MIN] as $cap) {
            $this->assertHelperParity('summarizeChunkSettingsBatch', [$configs, $cap]);
        }
        $this->assertHelperParity('summarizeChunkSettingsBatch', [[], 10_000]);

        $ranges = [];
        foreach (self::INTS as $first) {
            foreach (self::INTS as $last) {
                $ranges[] = ['first' => $first, 'last' => $last];
            }
        }
        $ranges[] = null;
        $ranges[] = ['last' => 5];
        foreach ([0, -1, 1, 100, 10_000, PHP_INT_MAX, PHP_INT_MIN] as $max) {
            $this->assertHelperParity('summarizeScanRangeBatch', [$ranges, $max]);
        }
        $this->assertHelperParity('summarizeScanRangeBatch', [['a' => ['first' => 1, 'last' => 2], 9 => 'z'], 0]);

        $records = [];
        foreach (self::STRINGS as $i => $hash) {
            foreach (self::INTS as $j => $collection) {
                $records[] = ['hash' => $hash, 'collections_id' => $collection, 'totalparts' => self::INTS[($i * 3 + $j) % $ints],
                    'filenumber' => self::INTS[($i + $j * 7) % $ints]];
            }
        }
        $records = [...$records, ...$records, 7];
        $this->assertHelperParity('summarizeBinaryRecordBatch', [$records]);
        $this->assertHelperParity('summarizeBinaryRecordBatch', [[]]);

        $headers = [];
        foreach (self::STRINGS as $subject) {
            foreach (self::INTS as $number) {
                $headers[] = ['Subject' => $subject, 'Number' => $number];
            }
        }
        $headers[] = 'x';
        $this->assertHelperParity('summarizeHeaderBatch', [$headers]);
        $this->assertHelperParity('summarizeHeaderBatch', [[]]);
        $this->assertHelperParity('summarizeHeaderBatch', [[['Subject' => 'a', 'Number' => PHP_INT_MAX], ['Number' => 1]]]);

        $stored = [];
        foreach (self::STRINGS as $i => $messageId) {
            foreach (self::INTS as $j => $bytes) {
                $stored[] = ['Message-ID' => $messageId, 'Bytes' => $bytes, 'Subject' => self::STRINGS[($i + $j) % count(self::STRINGS)]];
            }
        }
        $stored[] = null;
        foreach ([500, 0, -1, 1, 7, PHP_INT_MAX, PHP_INT_MIN] as $size) {
            $this->assertHelperParity('summarizeStorageChunkBatch', [$stored, $size]);
        }
        $this->assertHelperParity('summarizeStorageChunkBatch', [[], 500]);
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4111));
        $pick = fn (array $pool): mixed => $pool[$random->getInt(0, count($pool) - 1)];
        $bytes = fn (int $max): string => ($length = $random->getInt(0, $max)) === 0 ? '' : $random->getBytes($length);
        $int = fn (): mixed => match ($random->getInt(0, 3)) {
            0 => $pick(self::INTS),
            1 => $random->getInt(-5, 200),
            2 => (string) $random->getInt(-5, 2_000_000),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $pieces = ['a', 'Z', '0', '9', '12', '345', ' ', "\t", "\n", "\0", "\x0B", '(', ')', '/', '(1/2)', 'yEnc', 'YeN', 'c', "\xff", 'Ä', '"', '.', '<', '>'];
        $text = function (int $max) use ($random, $pick, $pieces): string {
            $value = '';
            for ($n = $random->getInt(0, $max); $n > 0; $n--) {
                $value .= $pick($pieces);
            }

            return $value;
        };
        $string = fn (): mixed => match ($random->getInt(0, 5)) {
            0 => $pick(self::STRINGS),
            1 => $bytes(40),
            2 => bin2hex($bytes(16)),
            3 => str_repeat('x', $random->getInt(250, 260)),
            default => $text(12),
        };
        $hashes = [self::HASH, md5('a'), md5('b'), md5('c')];
        $rows = function (Closure $row) use ($random): array {
            $list = [];
            for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                $list[] = $random->getInt(0, 19) === 0 ? null : $row();
            }

            return $list;
        };

        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $this->assertHelperParity('summarizeChunkSettingsBatch', [$rows(fn (): array => ['headerChunkSize' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 2_000) : $int(),
                'sqlChunkSize' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 12_000) : $int(), 'perArticleObfuscationMinArticles' => $pick([0, '0', 16, '16', null, -1, $int()])]),
                $pick([10_000, 500, 0, -1, PHP_INT_MAX])]);
            $this->assertHelperParity('summarizeScanRangeBatch', [$rows(function () use ($random, $int): array {
                $first = $random->getInt(0, 1) === 0 ? $random->getInt(-10, 1_000_000) : $int();

                return ['first' => $first, 'last' => match ($random->getInt(0, 3)) {
                    0 => is_int($first) && $first < PHP_INT_MAX - 200_000 ? $first + $random->getInt(-5, 200_000) : $first,
                    1 => (string) $random->getInt(-10, 1_000_000),
                    default => $int(),
                }];
            }), $pick([0, 100, 20_000, 100_000, -1, PHP_INT_MAX])]);
            $this->assertHelperParity('summarizeBinaryRecordBatch', [$rows(fn (): array => ['hash' => $random->getInt(0, 2) === 0 ? $string() : $pick($hashes),
                'collections_id' => $random->getInt(0, 1) === 0 ? $pick([1, 2, '1', '2', 0, -1]) : $int(), 'totalparts' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 100) : $int(),
                'filenumber' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 120) : $int()])]);
            $this->assertHelperParity('summarizeHeaderBatch', [$rows(fn (): array => ['Subject' => $random->getInt(0, 1) === 0 ? $text(20) : $string(),
                'Number' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 5_000_000) : $int()])]);
            $this->assertHelperParity('summarizeStorageChunkBatch', [$rows(fn (): array => ['Message-ID' => $random->getInt(0, 1) === 0 ? '<'.bin2hex($bytes(8)).'@x>' : $string(),
                'Bytes' => $random->getInt(0, 1) === 0 ? $random->getInt(-2, 800_000) : $int(), 'Subject' => $string()]), $pick([500, 1, 7, 0, -1, PHP_INT_MAX])]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[64]');
        FFI::memset($out, ord('Z'), 64);
        $input = pack('q2', 1, 0);
        $length = strlen($input);

        // A short buffer reports the exact size without writing.
        $this->assertSame(-56, $this->callFfi($ffi, 3, 0, $input, $length, $out, 55));
        $this->assertSame(-56, $this->callFfi($ffi, 3, 0, $input, $length, $out, 0));
        $this->assertSame(-64, $this->callFfi($ffi, 4, 0, $input, $length, $out, 63));
        foreach ([[0, 0], [6, 0], [3, 1], [1, 2]] as [$operation, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $flags, $input, $length, $out, 64), "op {$operation} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, null, $length, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, $length, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, $length - 1, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 16_777_217, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, pack('q', PHP_INT_MAX), 8, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, pack('q3', 2, 1, 2), 24, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, pack('q2', 1, 0).'x', 17, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q2', 1, 0), 16, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 4, 0, pack('q4', 3, 1, 1, 5), 32, $out, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));
        $this->assertSame(56, $this->callFfi($ffi, 3, 0, $input, $length, $out, 64));
        $this->assertSame(pack('q7', 0, 0, 0, 0, 0, 0, 0).str_repeat('Z', 8), FFI::string($out, 64));

        // The PHP frame packer produces frames the export accepts.
        $words = [100, 2];
        $blob = [];
        BinariesService::w411PackRow($words, $blob, [10, '19']);
        BinariesService::w411PackRow($words, $blob, [1.5, null]);
        $frame = pack('q', count($words)).pack('q*', ...$words).implode('', $blob);
        $this->assertSame(56, $this->callFfi($ffi, 2, 0, $frame, strlen($frame), $out, 64));
        $this->assertSame(pack('q7', 2, 1, 0, 0, 1, 0, 10), FFI::string($out, 56));

        foreach (self::CLASSES as $class) {
            $class::useW411RustFfi(true);
        }
        BinariesConfig::summarizeChunkSettingsBatch([['headerChunkSize' => 1]]);
        BinariesService::summarizeScanRangeBatch([['first' => 1, 'last' => 2]]);
        BinaryHandler::summarizeBinaryRecordBatch([['hash' => self::HASH]]);
        HeaderParser::summarizeHeaderBatch([['Subject' => 'a']]);
        HeaderStorageService::summarizeStorageChunkBatch([['Bytes' => 1]]);
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, $this->ffiSlot($class), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_and_oversized_frames_fall_back_to_php(): void
    {
        $cases = [
            'summarizeChunkSettingsBatch' => [[['headerChunkSize' => 800, 'sqlChunkSize' => 500, 'perArticleObfuscationMinArticles' => 0]], 10_000],
            'summarizeScanRangeBatch' => [[['first' => 1, 'last' => 10]], 5],
            'summarizeBinaryRecordBatch' => [[['hash' => self::HASH, 'collections_id' => 1, 'totalparts' => 2, 'filenumber' => 3]]],
            'summarizeHeaderBatch' => [[['Subject' => 'a yEnc (1/2)', 'Number' => 4]]],
            'summarizeStorageChunkBatch' => [[['Message-ID' => '<a@b>', 'Bytes' => 9, 'Subject' => 's']], 500],
        ];
        foreach (self::CLASSES as $class) {
            $class::useW411RustFfi(true);
            $this->setFfiSlot($class, false);
        }
        foreach ($cases as $helper => $args) {
            $class = self::HELPERS[$helper];
            $this->assertNull($class::{$helper.'Native'}(...$args), $helper);
            $this->assertSame($class::{$helper.'Php'}(...$args), $class::$helper(...$args), $helper);
        }

        // Frames past the 16 MiB export limit never reach Rust and fall back to PHP.
        $this->ffiOrSkip();
        foreach (self::CLASSES as $class) {
            $class::resetW411RustFfi();
            $class::useW411RustFfi(true);
        }
        $huge = [['Subject' => str_repeat('a', 16_777_216), 'Number' => 1]];
        $this->assertNull(HeaderParser::summarizeHeaderBatchNative($huge));
        $this->assertSame(HeaderParser::summarizeHeaderBatchPhp($huge), HeaderParser::summarizeHeaderBatch($huge));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // frame packing, the FFI call and decoding (release dylib, 5 warmups, 201 samples x 10
        // iterations). Common = 500 chunk settings rows, 1,000 scan ranges, 1,000 binary records,
        // 2,000 overview headers and a 500-header storage chunk; uncommon = 5,000 string-valued
        // settings rows, 10,000 ranges with string/negative bounds, 10,000 records with string ids and
        // bad hashes, 10,000 300-byte subjects without part counters and 10,000 storage rows with long
        // subjects and string byte counts.
        // Speedup = PHP latency / Rust latency per workload pass, common mean/p99 then uncommon mean/p99
        // (PHP 8.5 NTS, Apple Silicon, release dylib, 3 rounds):
        // - Round 1: settings 0.82x/0.77x, 1.68x/1.82x; ranges 0.87x/0.85x, 1.48x/1.55x; records 1.05x/1.02x, 1.52x/1.55x;
        //   headers 0.76x/0.75x, 0.48x/0.47x; storage 0.64x/0.67x, 0.73x/0.66x
        // - Round 2: settings 0.82x/0.81x, 1.64x/1.72x; ranges 0.84x/0.78x, 1.35x/1.19x; records 0.96x/1.01x, 1.41x/1.30x;
        //   headers 0.78x/0.81x, 0.48x/0.52x; storage 0.62x/0.66x, 0.66x/0.76x
        // - Round 3: settings 0.86x/0.95x, 1.66x/1.66x; ranges 0.88x/0.97x, 1.44x/1.29x; records 1.03x/1.03x, 1.42x/1.31x;
        //   headers 0.80x/0.81x, 0.50x/0.49x; storage 0.66x/0.69x, 0.70x/0.65x
        // No helper clears the 1.5x bar in .ai/rules/rust-performance.md on every workload, so every
        // W411_RUST_FFI_DEFAULT is false. The per-row PHP work (a few int checks, an isset, trim/strlen,
        // stripos and one PCRE match) is too small to repay frame packing and the FFI round trip on the common
        // workloads, and string-heavy rows cost more to copy into the frame than PHP's C string functions take.
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustBinariesConfigServiceHandlerHeaderW41ParityTest.php --filter benchmark
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4112));
        $workloads = ['common' => $this->commonWorkload($random), 'uncommon' => $this->uncommonWorkload($random)];
        $warmup = 5;
        $samples = 201;
        $iterations = 10;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => $class) {
            foreach ($workloads as $name => $workload) {
                $args = $workload[$helper];
                $this->assertHelperParity($helper, $args);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $class::useW411RustFfi($useRust);
                        $sink += count($class::$helper(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                        $class::useW411RustFfi($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$helper(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::resetW411RustFfi();
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustBinariesConfigServiceHandlerHeaderW41 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => $class) {
            $speedup = $speedups[$helper];
            if (min($speedup['common']['mean'], $speedup['common']['p99'], $speedup['uncommon']['mean'], $speedup['uncommon']['p99']) < 1.5) {
                $this->assertFalse($this->rustDefault($class), $class.' must keep PHP below 1.5x: '.$summary);
            }
            foreach ($speedup as $name => $values) {
                $this->assertGreaterThan(0.0, $values['mean'], $helper.' '.$name);
                $this->assertGreaterThan(0.0, $values['p99'], $helper.' '.$name);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertHelperParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        $expected = $class::{$helper.'Php'}(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $this->assertSame($expected, $class::{$helper.'Native'}(...$args), $context);
        foreach ([true, false] as $useRust) {
            $class::useW411RustFfi($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::resetW411RustFfi();
    }

    /** Reads a class's Wave 41.1 default at runtime so the toggle checks also hold if a later benchmark flips it. */
    private function rustDefault(string $class): bool
    {
        $value = constant($class.'::W411_RUST_FFI_DEFAULT');
        $this->assertIsBool($value, $class);

        return $value;
    }

    private function ffiSlot(string $class): mixed
    {
        if ($class === BinariesConfig::class) {
            return $this->configSlot()();
        }

        return (new ReflectionProperty($class, 'w411Ffi'))->getValue();
    }

    private function setFfiSlot(string $class, FFI|false|null $value): void
    {
        if ($class === BinariesConfig::class) {
            $slot = &$this->configSlot()();
            $slot = $value;

            return;
        }
        (new ReflectionProperty($class, 'w411Ffi'))->setValue(null, $value);
    }

    private function configSlot(): Closure
    {
        // BinariesConfig is a readonly class, so its Wave 41.1 library handle lives in a static local returned by reference.
        return $this->closures['config'] ??= (new ReflectionMethod(BinariesConfig::class, 'w411FfiSlot'))->getClosure(null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonWorkload(Randomizer $random): array
    {
        $configs = [];
        for ($i = 0; $i < 500; $i++) {
            $configs[] = ['headerChunkSize' => [500, 1_000, 250, 0][$i % 4], 'sqlChunkSize' => [500, 500, 2_000, 12_000][$i % 4],
                'reconcileBatchSize' => 500, 'perArticleObfuscationMinArticles' => [16, 0, 32][$i % 3]];
        }
        $ranges = [];
        for ($i = 0; $i < 1_000; $i++) {
            $first = 4_000_000_000 + $i * 20_000;
            $ranges[] = ['first' => $first, 'last' => $first + [19_999, 120_000, -1, 5][$i % 4], 'group' => 'alt.binaries.teevee'];
        }
        $records = [];
        for ($i = 0; $i < 1_000; $i++) {
            $records[] = ['hash' => md5((string) ($i % 900)), 'name' => 'Some.Show.S01E'.($i % 30).'.1080p.WEB.h264-GRP', 'collections_id' => 1 + $i % 3,
                'totalparts' => 50 + $i % 7, 'filenumber' => 1 + $i % 60];
        }
        $headers = [];
        for ($i = 0; $i < 2_000; $i++) {
            $headers[] = ['Number' => 4_000_000_000 + $i, 'Subject' => 'Some.Show.S01E'.($i % 30).'.1080p.WEB.h264-GRP - "file'.$i.'.part01.rar" yEnc ('.($i % 50 + 1).'/50)',
                'From' => 'poster@example.com', 'Date' => 'Sun, 25 Oct 2026 08:00:00 +0000', 'Message-ID' => '<'.bin2hex($random->getBytes(8)).'@example.com>', 'Bytes' => 750_000];
        }
        $stored = [];
        for ($i = 0; $i < 500; $i++) {
            $stored[] = ['Number' => 4_000_000_000 + $i, 'Subject' => 'Some.Movie.'.(1990 + $i % 30).'.1080p.BluRay.x264-GRP - "m.part'.$i.'.rar" yEnc (1/80)',
                'Message-ID' => '<'.bin2hex($random->getBytes(8)).'@example.com>', 'Bytes' => 700_000 + $i, 'Lines' => 5_000];
        }

        return [
            'summarizeChunkSettingsBatch' => [$configs, 10_000],
            'summarizeScanRangeBatch' => [$ranges, 100_000],
            'summarizeBinaryRecordBatch' => [$records],
            'summarizeHeaderBatch' => [$headers],
            'summarizeStorageChunkBatch' => [$stored, 500],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function uncommonWorkload(Randomizer $random): array
    {
        $configs = [];
        for ($i = 0; $i < 5_000; $i++) {
            $configs[] = $i % 500 === 499 ? 'row' : ['headerChunkSize' => (string) [500, 1_000, -1, 50][$i % 4], 'sqlChunkSize' => [(string) (500 + $i), 'x', null, '20000'][$i % 4],
                'perArticleObfuscationMinArticles' => (string) ($i % 3)];
        }
        $ranges = [];
        for ($i = 0; $i < 10_000; $i++) {
            $first = $random->getInt(0, 9_000_000_000);
            $ranges[] = $i % 1_000 === 999 ? null : ['first' => [(string) $first, $first, -$first, 'x'][$i % 4], 'last' => (string) ($first + $random->getInt(-50, 300_000))];
        }
        $records = [];
        for ($i = 0; $i < 10_000; $i++) {
            $records[] = ['hash' => $i % 9 === 0 ? strtoupper(md5((string) $i)) : md5((string) ($i % 7_000)), 'collections_id' => (string) (1 + $i % 50),
                'totalparts' => (string) ($i % 200), 'filenumber' => (string) ($i % 250)];
        }
        $headers = [];
        for ($i = 0; $i < 10_000; $i++) {
            $headers[] = ['Subject' => substr(str_repeat(bin2hex($random->getBytes(16)).' - ', 10), 0, 300), 'Number' => (string) (9_000_000_000 - $i)];
        }
        $stored = [];
        for ($i = 0; $i < 10_000; $i++) {
            $stored[] = $i % 700 === 0 ? 'x' : ['Message-ID' => $i % 50 === 0 ? '   ' : '<'.bin2hex($random->getBytes(12)).'@news.example.net>',
                'Bytes' => (string) $random->getInt(-5, 900_000), 'Subject' => substr(str_repeat(base64_encode($random->getBytes(30)), 8), 0, 200 + $i % 100)];
        }

        return [
            'summarizeChunkSettingsBatch' => [$configs, 10_000],
            'summarizeScanRangeBatch' => [$ranges, 100_000],
            'summarizeBinaryRecordBatch' => [$records],
            'summarizeHeaderBatch' => [$headers],
            'summarizeStorageChunkBatch' => [$stored, 500],
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w411_binaries_config_service_handler_header(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w411', BinariesService::W411_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 41.1 binaries config/service/handler/header export is not available.');
        }

        return $ffi;
    }
}
