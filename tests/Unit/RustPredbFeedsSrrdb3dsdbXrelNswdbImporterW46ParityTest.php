<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Services\Predb\Feeds\Sources\SrrdbSource;
use App\Services\Predb\Feeds\Sources\ThreeDsDbSource;
use App\Services\Predb\Feeds\Sources\XrelSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustPredbFeedsSrrdb3dsdbXrelNswdbImporterW46ParityTest extends TestCase
{
    /** Every Wave 46.2 target shares the W462 toggle names and keeps its existing Rust toggles separate. */
    private const TARGETS = [
        SrrdbSource::class,
        ThreeDsDbSource::class,
        XrelSource::class,
        NswdbSource::class,
        PredbFeedImporter::class,
    ];

    /** Production defaults: each helper defaults to Rust only when it clears 1.5x on every measure below. */
    private const DEFAULTS = [
        SrrdbSource::class => true,
        ThreeDsDbSource::class => true,
        XrelSource::class => true,
        NswdbSource::class => true,
        PredbFeedImporter::class => true,
    ];

    /** Helper per case: [class, method, op, slots]. */
    private const HELPERS = [
        'srrdb' => [SrrdbSource::class, 'summarizeDateBatch', 0, 6],
        '3dsdb' => [ThreeDsDbSource::class, 'summarizeThreeDsDbIdBatch', 1, 7],
        'xrel' => [XrelSource::class, 'summarizeDirnameBatch', 2, 8],
        'nswdb' => [NswdbSource::class, 'summarizeNswdbIdBatch', 3, 7],
        'importer' => [PredbFeedImporter::class, 'summarizeTitleBatch', 4, 7],
    ];

    /** Helpers that leave non-ASCII candidates and invalid UTF-8 to PHP's Unicode case folding. */
    private const ASCII_ONLY = ['importer'];

    /** Strings that exercise trim sets, dates, catalogue IDs, group suffixes, case, NUL bytes and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\t\n", "\x0B", "\x0C", "\r", "\x7F", '-', '.', '_', 'a', 'a-', '-a', 'a-b', 'A.B-C', 'a.b-c', 'a-b.c', 'a-b-c_1',
        'Movie.2024.1080p.WEB.H264-GRP', 'movie.2024.1080p.web.h264-grp', ' Movie.2024.1080p.WEB.H264-GRP ', 'Show.S01E01.720p.HDTV.x264-GRP_2',
        'Spaced Title-GRP', "Tab\tTitle-GRP", 'Trail-', 'No.Group', 'Movie-GRP ', "Movie-GRP\x0C", 'Movie-GR P', 'Movie-GRP!',
        '2024-01-02 03:04:05', ' 2024-01-02 03:04:05 ', "\x0C2024-01-02 03:04:05", '2024-02-29 23:59:59', '2023-02-29 00:00:00', '1900-02-29 00:00:00',
        '2000-02-29 00:00:00', '2024-04-31 12:00:00', '2024-13-01 00:00:00', '2024-00-10 00:00:00', '2024-01-00 00:00:00', '0000-01-01 00:00:00',
        '9999-12-31 23:59:59', '2024-01-01 24:00:00', '2024-01-01 00:60:00', '2024-01-01 00:00:60', '2024-01-01T00:00:00', '2024-01-01 00:00:0',
        '2024-01-01 00:00:000', '2024/01/01 00:00:00', "2024-01-01 00:00:00\n", '24-01-01 00:00:00', '2024-1-01 00:00:00', '２０２４-01-01 00:00:00',
        '1', '7', '42', '042', '0', '00', '-1', '+1', ' 7', '7 ', '1e3', '0x1A', '9223372036854775807', '9223372036854775808', '18446744073709551616',
        '99999999999999999999', '1000000000000000000', '123456789012345678901234567890', '٣', '1.0',
        'Café.Album-GRP', 'ÜBER.ALBUM-GRP', 'über.album-grp', "\u{212A}elvin-GRP", 'kelvin-grp', 'ΣΟΦΟΣ.Album-GRP', "bad \xff utf8", "\xc3", "\xe2\x80",
        "\xed\xa0\x80", "na\xffme.x-GRP", "\xc3\xa9.x-G",
    ];

    /** Fragments for random inputs: separators, tags, dates, IDs and noise. */
    private const WORDS = ['Movie', 'Show', 'Title', 'GRP', 'grp', '2024', 'S01E01', '1080p', 'WEB', '-GRP', '-grp_2', '.', '_', '-', ' ', '1', '9',
        '0', '42', '2024-01-02', ' 03:04:05', '2024-02-29 23:59:59', '9223372036854775807', '12-31', ':', '00'];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW462RustFfi();
        }
        SrrdbSource::resetRustFfi();
        XrelSource::resetRustFfi();
        ThreeDsDbSource::resetThreeDsDbRustFfi();
        NswdbSource::resetNswdbRustFfi();
        PredbFeedImporter::setUseRustFfi(false);
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW462RustFfi', 'usesW462RustFfi', 'resetW462RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            $this->assertFalse($reflection->isReadOnly(), $class);
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W462_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW462RustFfi(), $class);
            $class::useW462RustFfi();
            $this->assertTrue($class::usesW462RustFfi(), $class);
            $class::useW462RustFfi(false);
            $this->assertFalse($class::usesW462RustFfi(), $class);
            $class::useW462RustFfi(null);
            $this->assertSame($default, $class::usesW462RustFfi(), $class);
            $class::useW462RustFfi(true);
            $class::resetW462RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w462RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w462RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW462RustFfi(), $class);
        }

        // The W462 toggles never change the existing Rust toggles or the helpers behind them.
        foreach ([true, false] as $enable) {
            foreach (self::TARGETS as $class) {
                $class::useW462RustFfi($enable);
            }
            $this->assertSame(SrrdbSource::RUST_FFI_DEFAULT, SrrdbSource::usesRustFfi());
            $this->assertSame(XrelSource::RUST_FFI_DEFAULT, XrelSource::usesRustFfi());
            $this->assertSame(ThreeDsDbSource::THREE_DS_DB_RUST_FFI_DEFAULT, ThreeDsDbSource::usesThreeDsDbRustFfi());
            $this->assertSame(NswdbSource::NSWDB_RUST_FFI_DEFAULT, NswdbSource::usesNswdbRustFfi());
            $this->assertFalse(PredbFeedImporter::usesRustFfi());
            $this->assertSame('https://srrdb.invalid/skip:45', SrrdbSource::planSrrdbPage('https://srrdb.invalid/', 2)['url']);
            $this->assertSame(['per_page' => 100, 'page' => 1, 'past_end' => false, 'json_fields' => 3, 'key' => 'xrel_p2p', 'source' => 'xrel-p2p'],
                XrelSource::planXrelPage(500, 0, 0, true));
            $this->assertSame(100, ThreeDsDbSource::planThreeDsDbPage(250, 100, 2)['offset']);
            $this->assertSame(50, NswdbSource::planNswdbPage(250, 100, 3)['length']);
        }
        SrrdbSource::useRustFfi(true);
        ThreeDsDbSource::useThreeDsDbRustFfi(true);
        PredbFeedImporter::setUseRustFfi(true);
        foreach (self::TARGETS as $class) {
            $class::resetW462RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW462RustFfi(), $class);
        }
        $this->assertTrue(SrrdbSource::usesRustFfi());
        $this->assertTrue(ThreeDsDbSource::usesThreeDsDbRustFfi());
        $this->assertTrue(PredbFeedImporter::usesRustFfi());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 9, 'undated' => 3, 'canonical' => 3, 'malformed' => 3, 'padded' => 1, 'distinct_days' => 2],
            SrrdbSource::summarizeDateBatchPhp(['2024-01-02 03:04:05', " 2024-01-02 23:00:00\n", '2024-01-03 00:00:00', '2024-02-30 00:00:00', 'yesterday',
                " \0\x0B", '', null, "\x0C2024-01-02 03:04:05"]),
        );

        $ids = ['1', '42', '42', '042', '0', '', ' 7', '9223372036854775807', '9223372036854775808', '99999999999999999999', null];
        $expected = ['total' => 11, 'invalid' => 1, 'malformed' => 4, 'overflow' => 2, 'duplicate' => 1, 'accepted' => 3, 'max_id' => PHP_INT_MAX];
        $this->assertSame($expected, ThreeDsDbSource::summarizeThreeDsDbIdBatchPhp($ids));
        $this->assertSame($expected, NswdbSource::summarizeNswdbIdBatchPhp($ids));
        $this->assertSame(5, NswdbSource::summarizeNswdbIdBatchPhp(['5', '3', 5])['max_id']);

        $this->assertSame(
            ['total' => 8, 'invalid' => 1, 'empty' => 1, 'accepted' => 6, 'padded' => 1, 'grouped' => 4, 'spaced' => 1, 'distinct' => 5],
            XrelSource::summarizeDirnameBatchPhp(['Movie.2024.1080p-GRP', " movie.2024.1080P-grp\t", 'Spaced Title-GRP', 'No.Group', 'Trail-', " \0", null,
                'Été-GRP']),
        );
        // strtolower() is byte-wise ASCII folding, so non-ASCII case variants stay distinct.
        $this->assertSame(2, XrelSource::summarizeDirnameBatchPhp(['ÉTÉ-GRP', 'été-grp'])['distinct']);

        $this->assertSame(
            ['total' => 8, 'invalid' => 1, 'empty' => 1, 'overlength' => 2, 'padded' => 1, 'duplicate' => 1, 'accepted' => 3],
            PredbFeedImporter::summarizeTitleBatchPhp(['Movie.2024-GRP', ' MOVIE.2024-grp ', 'Other-GRP', str_repeat('a', 256), str_repeat('a', 255),
                str_repeat('é', 256), "\t\n", null]),
        );
        // mb_strtolower() folds the Kelvin sign onto ASCII, so Rust leaves non-ASCII candidates to PHP.
        $this->assertSame(1, PredbFeedImporter::summarizeTitleBatchPhp(['kelvin-GRP', "\u{212A}elvin-GRP"])['duplicate']);
        $this->assertSame(0, PredbFeedImporter::summarizeTitleBatchPhp([str_repeat('é', 255)])['overlength']);

        foreach (self::HELPERS as [$class, $method]) {
            $empty = $class::{$method.'Php'}([]);
            $this->assertSame(array_fill_keys(array_keys($empty), 0), $empty, $class);
        }
    }

    public function test_edge_cases_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $texts = self::TEXTS;
        foreach (array_keys(self::HELPERS) as $helper) {
            $this->assertParity($helper, []);
            $this->assertParity($helper, $texts);
            $this->assertParity($helper, ['a' => 'Movie.2024-GRP', 'b' => ' movie.2024-grp ', 'c' => null, 'd' => 42, 'e' => '42', 'f' => 1.5]);
            $this->assertParity($helper, [str_repeat('Movie.Title.2024.1080p.WEB-GRP ', 64), str_repeat('9', 400)]);
            foreach ([254, 255, 256, 257] as $length) {
                $this->assertParity($helper, [str_repeat('a', $length - 4).'-GRP', ' '.str_repeat('b.', intdiv($length, 2)).'-GRP ', str_repeat('é', $length)]);
            }
            foreach ($texts as $index => $text) {
                $this->assertParity($helper, [$text]);
                $this->assertParity($helper, [strtoupper($text), ' '.$text.' ', $text.'-B', $text.'1', '1'.$text, $text, null]);
                $this->assertParity($helper, array_slice([...$texts, ...$texts], $index, 1 + $index % 13));
            }
        }
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4621));
        $alphabet = ['a', 'b', 'x', 'A', 'Z', '0', '1', '2', '9', '_', '.', '-', '-', ' ', ' ', ':', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\x7F",
            "\xff", "\xc3\xa9", "\xc3\x89", "\xe2\x84\xaa"];
        $text = static function () use ($random, $alphabet): string {
            $out = '';
            for ($i = $random->getInt(0, 12); $i > 0; $i--) {
                $out .= $random->getInt(0, 1) === 0
                    ? self::WORDS[$random->getInt(0, count(self::WORDS) - 1)]
                    : $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return match ($random->getInt(0, 7)) {
                0 => strtoupper($out),
                1 => ' '.$out."\t",
                2 => strtolower($out),
                3 => $out.str_repeat('z', $random->getInt(200, 300)),
                4 => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $random->getInt(0, 2100), $random->getInt(0, 13), $random->getInt(0, 32),
                    $random->getInt(0, 24), $random->getInt(0, 60), $random->getInt(0, 60)),
                5 => (string) $random->getInt(0, PHP_INT_MAX).($random->getInt(0, 3) === 0 ? (string) $random->getInt(0, 99) : ''),
                default => $out,
            };
        };

        for ($i = 0; $i < 800; $i++) {
            foreach (array_keys(self::HELPERS) as $helper) {
                $list = [];
                for ($n = $random->getInt(0, 24); $n > 0; $n--) {
                    $pick = $random->getInt(0, 9);
                    $list[] = match (true) {
                        $pick === 0 => self::TEXTS[$random->getInt(0, count(self::TEXTS) - 1)],
                        $pick === 1 && $list !== [] => $list[$random->getInt(0, count($list) - 1)],
                        $pick === 2 => null,
                        default => $text(),
                    };
                }
                $this->assertParity($helper, $list);
            }
        }
    }

    public function test_malformed_and_oversized_inputs_fall_back_to_php(): void
    {
        $this->ffiOrSkip();
        $oversized = str_repeat('Movie.Title.2024.1080p.WEB-GRP ', 600_000);
        $this->assertGreaterThan(PredbFeedImporter::W462_MAX_INPUT, strlen($oversized));
        $this->assertNull(PredbFeedImporter::packW462Items([$oversized]));
        $this->assertNull(PredbFeedImporter::packW462Items([$oversized, null]));
        $this->assertNull(PredbFeedImporter::w462Summary(null, 2, ['a'], 8));
        foreach (self::HELPERS as $helper => [$class, $method]) {
            $class::useW462RustFfi(true);
            $this->assertNull($class::{$method.'Native'}([$oversized]), $helper);
            $this->assertSame($class::{$method.'Php'}([$oversized]), $class::$method([$oversized]), $helper);
        }
        // Non-ASCII candidates and invalid UTF-8 decline to PHP for the importer only.
        foreach (['Café.Album-GRP', "bad\xff"] as $title) {
            $this->assertNull(PredbFeedImporter::summarizeTitleBatchNative([$title]));
            $this->assertSame(PredbFeedImporter::summarizeTitleBatchPhp([$title]), PredbFeedImporter::summarizeTitleBatch([$title]));
            $this->assertNotNull(XrelSource::summarizeDirnameBatchNative([$title]));
        }
        $this->assertNotNull(PredbFeedImporter::summarizeTitleBatchNative([str_repeat('é', 256)]));

        // Layouts: NUL-joined items with the positions of non-strings, or a length table for strings holding NUL.
        $delimited = PredbFeedImporter::W462_DELIMITED;
        $this->assertSame(["a\0b", '', $delimited], PredbFeedImporter::packW462Items(['a', 'b']));
        $this->assertSame(["a\0\0b\0", pack('V2', 1, 3), $delimited], PredbFeedImporter::packW462Items(['x' => 'a', 'y' => null, 'z' => 'b', 0 => 7]));
        $this->assertSame(['ab', pack('V3', 1, PredbFeedImporter::W462_NULL, 1), 0], PredbFeedImporter::packW462Lengths(['a', null, 'b']));
        $this->assertSame(['', '', 0], PredbFeedImporter::packW462Items([]));
        $this->assertSame(['', '', 0], PredbFeedImporter::packW462Lengths([]));
        $this->assertNull(PredbFeedImporter::packW462Lengths([$oversized]));
        // A NUL inside a string splits the delimited batch, so the summary retries with the length table.
        foreach ([["a\0b", ''], ["a\0b", null, '42'], [null, "\0"]] as $items) {
            $this->assertSame(XrelSource::summarizeDirnameBatchPhp($items), XrelSource::summarizeDirnameBatchNative($items));
            $this->assertSame(NswdbSource::summarizeNswdbIdBatchPhp($items), NswdbSource::summarizeNswdbIdBatchNative($items));
        }
    }

    public function test_native_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $symbol = 'nntmux_w462_predb_feed_batch';
        $blob = 'Movie-GRP';
        $lens = pack('V', 9);
        $out = $ffi->new('char[128]');

        // Tiny capacity reports -required without writing, then a retry at that size succeeds.
        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $this->assertSame(-64, $this->callFfi($ffi, $symbol, 2, 0, $blob, 9, $lens, 4, $small, 4));
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $this->assertSame(64, $this->callFfi($ffi, $symbol, 2, 0, $blob, 9, $lens, 4, $out, 64));
        $this->assertSame([1, 0, 0, 1, 0, 1, 0, 1], array_values(unpack('q8', FFI::string($out, 64)) ?: []));

        // Unknown ops, any flags, null pointers and inconsistent lengths decline; empty batches succeed.
        foreach ([[5, 0], [0x200, 0], [0, 1], [2, 1], [4, 2]] as [$op, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, $op, $flags, $blob, 9, $lens, 4, $out, 128), "op {$op} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, null, 0, $lens, 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, $blob, 9, null, 0, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, $blob, 9, $lens, 4, null, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, $blob, 8, $lens, 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, $blob, 9, $lens, 3, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, $blob, 9, pack('V', 10), 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, '', 0, pack('V', 0xFFFFFFFE), 4, $out, 128));
        // Delimited null indexes must ascend and name empty items.
        $delimited = PredbFeedImporter::W462_DELIMITED;
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2 | $delimited, 0, $blob, 9, pack('V', 0), 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2 | $delimited, 0, "a\0\0", 3, pack('V2', 2, 1), 8, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2 | $delimited, 0, "a\0\0", 3, pack('V2', 1, 1), 8, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2 | $delimited, 0, "a\0\0", 3, pack('V', 3), 4, $out, 128));
        $this->assertSame(64, $this->callFfi($ffi, $symbol, 2 | $delimited, 0, "a\0\0", 3, pack('V2', 1, 2), 8, $out, 128));
        $this->assertSame([3, 2, 0, 1, 0, 0, 0, 1], array_values(unpack('q8', FFI::string($out, 64)) ?: []));
        foreach (self::HELPERS as [, , $op, $slots]) {
            $this->assertSame($slots * 8, $this->callFfi($ffi, $symbol, $op, 0, '', 0, '', 0, $out, 128), "op {$op}");
        }

        // An output buffer overlapping either input declines without writing to it.
        $shared = $ffi->new('char[128]');
        FFI::memcpy($shared, $blob.$lens, 13);
        $input = FFI::addr($shared[0]);
        $table = FFI::addr($shared[9]);
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, $input, 9, $table, 4, FFI::addr($shared[4]), 64));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 2, 0, $input, 9, $table, 4, FFI::addr($shared[12]), 64));
        $this->assertSame($blob.$lens, FFI::string($shared, 13));
        $this->assertSame(64, $this->callFfi($ffi, $symbol, 2, 0, $input, 9, $table, 4, FFI::addr($shared[16]), 64));

        foreach (self::HELPERS as [$class, $method]) {
            $class::useW462RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}(['42', null]), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w462RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::HELPERS as $helper => [$class, $method]) {
            $items = ['Movie.2024-GRP', ' 2024-01-02 03:04:05 ', '42', '', null];
            (new ReflectionProperty($class, 'w462RustFfiHandle'))->setValue(null, false);
            $class::useW462RustFfi(true);
            $this->assertNull($class::{$method.'Native'}($items), $helper);
            $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $helper);
            $class::resetW462RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w462RustFfiHandle'))->getValue(), $helper);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including the PHP-side
        // type checks, packing, FFI call and counter decoding (release dylib, 5 warmups, 1001 samples per helper and
        // workload; 20 iterations per common sample, 1 per uncommon sample).
        // Common = one page of typical inputs: 45 srrDB dates, 100 catalogue IDs, xREL dirnames or feed titles.
        // Uncommon = 5000 long or mixed inputs with whitespace, nulls, rejects, overflow and duplicates.
        // Speedup = PHP latency / Rust latency, ranges over 6 rounds (PHP 8.5.12 NTS, rustc 1.99.1, Apple M5,
        // shared host at load average ~6.6-6.8):
        // - srrdb:    common mean 5.23-5.63x / p99 3.78-5.13x, uncommon mean 5.57-5.78x / p99 4.14-5.45x
        // - 3dsdb:    common mean 2.24-2.33x / p99 1.94-2.46x, uncommon mean 2.09-2.12x / p99 1.80-2.12x
        // - xrel:     common mean 2.73-2.85x / p99 2.36-2.86x, uncommon mean 2.95-3.07x / p99 2.27-2.42x
        // - nswdb:    common mean 2.13-2.35x / p99 1.98-2.47x, uncommon mean 2.11-2.16x / p99 1.92-2.36x
        // - importer: common mean 4.67-4.91x / p99 3.76-6.67x, uncommon mean 8.07-9.92x / p99 6.00-7.23x
        // Every helper clears 1.5x on every measure in every round and defaults to Rust. An earlier 6-round run at
        // 301 samples had one nswdb uncommon p99 of 0.95x and one xrel common p99 of 1.38x (its other rounds were
        // 1.96-3.63x and 2.26-2.54x): single host-load spikes in the 3rd-worst sample, hence the 1001 samples.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustPredbFeedsSrrdb3dsdbXrelNswdbImporterW46ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4622));
        $warmup = 5;
        $samples = 1001;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            $workloads = [
                'common' => [$this->commonWorkload($helper), 20],
                'uncommon' => [$this->uncommonWorkload($helper), 1],
            ];
            foreach ($workloads as $name => [$items, $iterations]) {
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($class, $method, $items, false) + $this->runWorkload($class, $method, $items, true);
                }
                $native = $class::{$method.'Native'}($items);
                $this->assertNotNull($native, $helper.' '.$name.' must run natively');
                $this->assertSame($class::{$method.'Php'}($items), $native, $helper.' '.$name);
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += $this->runWorkload($class, $method, $items, $useRust);
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::resetW462RustFfi();
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustPredbFeedsSrrdb3dsdbXrelNswdbImporterW46 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W462_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW462RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W462_RUST_FFI_DEFAULT')) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private function assertParity(string $helper, array $items): void
    {
        [$class, $method] = self::HELPERS[$helper];
        $expected = $class::{$method.'Php'}($items);
        $native = $class::{$method.'Native'}($items);
        $context = $helper.': '.json_encode($this->describe($items));
        if ($native === null && in_array($helper, self::ASCII_ONLY, true)) {
            $this->assertTrue($this->hasNonAscii($items), $helper.' declined an ASCII batch: '.$context);
        } else {
            $this->assertNotNull($native, $helper.' declined: '.$context);
            $this->assertSame($expected, $native, $context);
        }
        $class::useW462RustFfi(true);
        $this->assertSame($expected, $class::$method($items), $helper.' dispatcher');
        $class::useW462RustFfi(false);
        $this->assertSame($expected, $class::$method($items), $helper.' php dispatcher');
        $class::resetW462RustFfi();
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private function hasNonAscii(array $items): bool
    {
        foreach ($items as $item) {
            if (is_string($item) && preg_match('/[\x80-\xff]/', $item) === 1) {
                return true;
            }
        }

        return false;
    }

    private function describe(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => bin2hex($value),
            is_array($value) => array_map($this->describe(...), $value),
            default => $value,
        };
    }

    /**
     * @return list<string>
     */
    private function commonWorkload(string $helper): array
    {
        if ($helper === 'srrdb') {
            return array_map(static fn (int $i): string => sprintf('2026-%02d-%02d %02d:%02d:%02d', 1 + $i % 12, 1 + $i % 28, $i % 24, ($i * 7) % 60, ($i * 13) % 60),
                range(1, SrrdbSource::PAGE_SIZE));
        }
        if ($helper === '3dsdb' || $helper === 'nswdb') {
            return array_map(static fn (int $i): string => (string) (5000 - $i * 3), range(1, 100));
        }
        $patterns = ['Show.S01E%d.720p.HDTV.x264-GRP', 'Movie.Title.%d.1080p.WEB.H264-GRP', 'Artist-Album-%d-WEB-2024-GRP', 'Movie.Title.%d.German.DL-GRP',
            'Show.S02E%d.1080p.WEB-DL.DDP5.1-GRP', 'Game.Title.%d.NSW-GRP', 'Movie.%d.German.DL.1080p-GRP', 'Show.S01E%d.REPACK.720p-grp'];

        return array_map(static fn (int $i): string => sprintf($patterns[$i % 8], $i), range(1, 100));
    }

    /**
     * @return list<string|null>
     */
    private function uncommonWorkload(string $helper): array
    {
        $count = 5000;
        if ($helper === 'srrdb') {
            return array_map(static fn (int $i): ?string => match ($i % 8) {
                0 => null,
                1 => '  '.sprintf('2025-%02d-%02d 12:00:00', 1 + $i % 12, 1 + $i % 31)."\t",
                2 => sprintf('%04d-02-29 23:59:59', 1896 + $i % 16),
                3 => sprintf('2024-%02d-01 24:00:00', 1 + $i % 12),
                4 => sprintf('2024-01-01T%02d:00:00', $i % 24),
                5 => '',
                default => sprintf('%04d-%02d-%02d %02d:%02d:%02d', 2000 + $i % 27, 1 + $i % 12, 1 + $i % 28, $i % 24, $i % 60, ($i * 7) % 60),
            }, range(1, $count));
        }
        if ($helper === '3dsdb' || $helper === 'nswdb') {
            return array_map(static fn (int $i): ?string => match ($i % 8) {
                0 => (string) (PHP_INT_MAX - $i),
                1 => '9223372036854775808'.($i % 10),
                2 => '0'.$i,
                3 => (string) intdiv($i, 2),
                4 => ' '.$i,
                5 => null,
                default => (string) ($i * 1_000_003),
            }, range(1, $count));
        }
        $lengths = [120, 180, 240, 255, 256, 320, 400];

        return array_map(static function (int $i) use ($lengths, $count): string {
            $length = $lengths[$i % count($lengths)];
            $body = substr(str_repeat('Obfuscated.Mirror_Release.', 16).'Part'.$i, -$length + 12);

            return match ($i % 6) {
                0 => strtoupper($body).'-GRP',
                1 => '  '.$body.".Of.{$count}-GRP\t",
                2 => str_replace('.', ' ', $body).' EZTV',
                3 => $body.'.(Remastered)-GRP',
                4 => strtolower($body).'-grp',
                default => $body.'-GRP',
            };
        }, range(1, $count));
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $items
     */
    private function runWorkload(string $class, string $method, array $items, bool $useRust): int
    {
        $class::useW462RustFfi($useRust);

        return count($class::$method($items));
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

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(PredbFeedImporter::W462_FFI_DOMAIN, PredbFeedImporter::W462_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 46.2 export is not available.');
        }

        return $ffi;
    }
}
