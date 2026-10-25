<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\FingerprintDispatch;
use App\Services\Releases\Fingerprint\FingerprintProviderBreaker;
use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustFingerprintDispatchBreakerParserAnalyzerCollectorW47ParityTest extends TestCase
{
    /** Helper per case: [class, batch method, toggle infix, export, slots]. */
    private const HELPERS = [
        'dispatch' => [FingerprintDispatch::class, 'planEnqueueBatch', 'W47Dispatch', 'nntmux_w47_fingerprint_dispatch_batch', 13],
        'breaker' => [FingerprintProviderBreaker::class, 'simulateBatch', 'W47Breaker', 'nntmux_w47_fingerprint_breaker_batch', 17],
        'parser' => [NativeMetadataParser::class, 'summarizeFrameBatch', 'W47Parser', 'nntmux_w47_metadata_frame_batch', 17],
        'analyzer' => [NzbFingerprintAnalyzer::class, 'summarizeSegmentBatch', 'W47Analyzer', 'nntmux_w47_nzb_segment_batch', 20],
        'collector' => [ReleaseFingerprintCollector::class, 'aggregateCandidateBatch', 'W47Collector', 'nntmux_w47_fingerprint_candidate_batch', 13],
    ];

    /** Production defaults: each helper defaults to Rust only when it clears 1.5x on every measure of the benchmark below. */
    private const DEFAULTS = [
        'dispatch' => true,
        'breaker' => true,
        'parser' => true,
        'analyzer' => true,
        'collector' => true,
    ];

    /** Default constant per helper. */
    private const CONSTANTS = [
        'dispatch' => 'W47_DISPATCH_RUST_FFI_DEFAULT',
        'breaker' => 'W47_BREAKER_RUST_FFI_DEFAULT',
        'parser' => 'W47_PARSER_RUST_FFI_DEFAULT',
        'analyzer' => 'W47_ANALYZER_RUST_FFI_DEFAULT',
        'collector' => 'W47_COLLECTOR_RUST_FFI_DEFAULT',
    ];

    /** Strings that exercise trim sets, whitespace runs, brackets, case, slashes and invalid UTF-8 (no NUL bytes). */
    private const TEXTS = [
        '', ' ', "\t", "\n", "\r", "\x0B", "\x0C", "\x7F", '<', '>', '<>', '<<>>', '@', 'a@', '@b', 'a@b', '<a@b>', ' <a@b> ', "<a\tb@c>", '<a b@c>',
        'part1of9.AbCdEfGh@news.example.com', '<Part1Of9.abcdefgh@NEWS.Example.com>', 'aaaaaaaa@x', 'abcde@x', 'abcdef@x', 'no-domain', '<x@y@z>',
        'ngPost 4.16', 'NGPOST  4.16', "Nyuu/0.4.1\t", 'newsmangler', 'GoPostStuff/1.0 (linux)', '/leading', 'trailing/', 'a /b', " \x0C ",
        'gen-1', ' gen-1 ', "\tgen-2\n", 'GEN-1', 'é', "\xc3\xa9t\xc3\xa9", "bad\xff", "\xc3", '0', '00', '123', '0123', '-1',
        "Subject: x\r\nFrom: y\r\n", 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ',
    ];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class, , $toggle]) {
            $class::{'reset'.$toggle.'RustFfi'}();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::HELPERS as $helper => [$class, $method, $toggle]) {
            $reflection = new ReflectionClass($class);
            foreach (['use'.$toggle.'RustFfi', 'uses'.$toggle.'RustFfi', 'reset'.$toggle.'RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
                $this->assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $this->assertTrue($reflection->hasConstant('W47_FFI_CDEF'), $class);
            $this->assertStringContainsString(self::HELPERS[$helper][3], (string) $reflection->getConstant('W47_FFI_CDEF'), $class);
            $default = self::DEFAULTS[$helper];
            $this->assertSame($default, $reflection->getConstant(self::CONSTANTS[$helper]), $class);
            $this->assertSame($default, $class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}();
            $this->assertTrue($class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}(false);
            $this->assertFalse($class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}(null);
            $this->assertSame($default, $class::{'uses'.$toggle.'RustFfi'}(), $class);
            $class::{'use'.$toggle.'RustFfi'}(true);
            $class::{'reset'.$toggle.'RustFfi'}();
            $property = lcfirst($toggle);
            $this->assertNull((new ReflectionProperty($class, $property.'RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, $property.'RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::{'uses'.$toggle.'RustFfi'}(), $class);
        }

        // The W47 toggles leave the existing toggles of these classes untouched.
        $this->assertSame(FingerprintDispatch::RUST_FFI_DEFAULT, FingerprintDispatch::usesRustFfi());
        foreach (self::HELPERS as [$class, , $toggle]) {
            $class::{'use'.$toggle.'RustFfi'}(true);
        }
        $this->assertFalse(FingerprintDispatch::usesRustFfi());
        $this->assertFalse(FingerprintProviderBreaker::usesRustFfi());
        $this->assertSame(3600, FingerprintProviderBreaker::suspectSecondsPhp(900));
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(-3750763034362895579, FingerprintDispatch::w47Fnv(''));
        $this->assertSame(-5808556873153909620, FingerprintDispatch::w47Fnv('a'));
        $this->assertSame(-8821353812377114648, FingerprintDispatch::w47Fnv('foobar'));

        $ids = [5, 5, 0, 17, -3];
        $generations = [' gen-a ', 'gen-a', '', "\t", 'x'];
        $forces = [false, true, true, false, false];
        $stream = static fn (array $records): string => implode('', array_map(
            static fn (array $r): string => pack('CPV', $r[0], $r[1], crc32($r[2])), $records));
        $this->assertSame(['total' => 5, 'database' => 3, 'job' => 0, 'rejected' => 2, 'queued' => 2, 'forced' => 1, 'generation' => 0,
            'no_generation' => 0, 'invalid_release_id' => 2, 'duplicate' => 1, 'distinct_generations' => 0, 'max_release_id' => 17,
            'generation_bytes' => 0, 'busiest_shard' => 1, 'max_shard_load' => 3,
            'digest' => FingerprintDispatch::w47Fnv($stream([[0, 5, ''], [1, 5, ''], [4, 0, ''], [0, 17, ''], [4, -3, '']]))],
            FingerprintDispatch::planEnqueueBatchPhp($ids, $generations, $forces, 'rust', 4));
        $job = FingerprintDispatch::planEnqueueBatchPhp($ids, $generations, $forces, 'horizon', 4);
        $this->assertSame([0, 3, 2, 2, 1, 1, 10], [$job['database'], $job['job'], $job['rejected'], $job['generation'], $job['no_generation'],
            $job['distinct_generations'], $job['generation_bytes']]);
        $this->assertSame(FingerprintDispatch::w47Fnv($stream([[2, 5, 'gen-a'], [2, 5, 'gen-a'], [4, 0, ''], [3, 17, ''], [4, -3, '']])), $job['digest']);
        // Each request plans exactly as planEnqueue() routes it.
        foreach ([[5, ' g ', false, 'rust'], [5, ' g ', true, null], [5, '', false, 'php'], [5, ' g ', true, 'php'], [0, 'g', true, 'rust']] as [$id, $g, $f, $w]) {
            $single = FingerprintDispatch::planEnqueueBatchPhp([$id], [$g], [$f], $w);
            $plan = FingerprintDispatch::planEnqueue($id, $g, $f, $w);
            $this->assertSame(1, $single[$plan['reason']], $plan['reason']);
            $this->assertSame(1, $single[$plan['route']], $plan['route']);
        }

        $this->assertSame([10, 1, 1, 1, 3, 1, 2, 1, 2, 0, 0, 1, 0, 0, 1, 222, 5000], array_values(FingerprintProviderBreaker::simulateBatchPhp(
            [1, 1, 1, 1, 1, 1, 1, 2, 1, 1], [0, 10, 20, 950, 960, 970, 965, 980, 990, 5000], [0, 1, 0, 0, 0, 2, 0, 9, 1, 0], 900, 0, 1800)));
        $replay = [[7, 7, 7, 7, 7, 7, 7, 7, 7], [0, 100, 101, 102, 103, 104, 170, 171, 172], [1, 0, 2, 0, 1, 0, 0, 3, 0]];
        $this->assertSame([9, 0, 0, 1, 4, 1, 0, 3, 2, 1, 1, 0, 0, 1, 1, 222, 172], array_values(FingerprintProviderBreaker::simulateBatchPhp(...$replay, ...[60, 0, 50])));
        $this->assertSame([4, 1, 0, 0, 2, 0, 0, 2], array_slice(array_values(FingerprintProviderBreaker::simulateBatchPhp(...$replay, ...[60, 40, 50])), 4, 8));

        [$frames, $valid] = $this->referenceFrames();
        $crc = array_sum(array_map(crc32(...), array_filter($frames, is_string(...))));
        $this->assertSame([9, 1, 1, 1, 1, 2, 2, 1, 8, 2, 76, 21, 3, 3, 1, $crc, FingerprintDispatch::w47Fnv(implode('', $valid))],
            array_values(NativeMetadataParser::summarizeFrameBatchPhp($frames)));

        $this->assertSame([10, 2, 3, 4, 1, 1, 2, 21, 52, 861, 2, 5, 1, 2920, 0, 120, 700, 700, 4, 1], array_values(NzbFingerprintAnalyzer::summarizeSegmentBatchPhp(
            ['<abcdefgh@news.example>', 'abcdefgh@NEWS.example', 'aaaa@x', '<bad id@x>', '<>', ' ', 'noat', '<abcdefgh@news.example>', null, 42],
            [700, 700, 700, 120, -1, 700])));
        $this->assertSame([3, 0, PHP_INT_MAX, 1, 1, PHP_INT_MAX, 1, 1, 2], array_slice(array_values(NzbFingerprintAnalyzer::summarizeSegmentBatchPhp([],
            [PHP_INT_MAX, 1, 5])), 11));

        $this->assertSame([8, 1, 1, 6, 5, 1, 3, 3, 2, 1, 3, 0, FingerprintDispatch::w47Fnv("ngpost\nnyuu\nnewsmangler\n")],
            array_values(ReleaseFingerprintCollector::aggregateCandidateBatchPhp(['ngPost 4.16', " NGPOST\t\t4.16 ", 'Nyuu/0.4.1', 'ngpost', "\x0C", 'newsmangler',
                'nyuu', null])));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, -1, FingerprintDispatch::w47Fnv('')],
            array_values(ReleaseFingerprintCollector::aggregateCandidateBatchPhp([])));

        foreach (array_keys(self::HELPERS) as $helper) {
            $summary = $this->callHelper($helper, 'Php', $this->emptyArgs($helper));
            $this->assertSame(0, $summary['total'], $helper);
        }
    }

    public function test_empty_common_uncommon_and_edge_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        foreach (array_keys(self::HELPERS) as $helper) {
            $this->assertParity($helper, $this->emptyArgs($helper));
            $this->assertParity($helper, $this->commonArgs($helper, new Randomizer(new Mt19937(4721))));
            $this->assertParity($helper, $this->uncommonArgs($helper, new Randomizer(new Mt19937(4722))));
        }

        // Dispatch: every text as a generation under each worker, with boundary ids and shard counts.
        $count = count(self::TEXTS);
        $ids = array_map(static fn (int $i): int => match ($i % 7) {
            0 => PHP_INT_MAX - $i,
            1 => PHP_INT_MIN + $i,
            2 => 0,
            3 => -$i,
            default => [1, 1024, 1025, 1][$i % 4] + intdiv($i, 7),
        }, range(0, $count - 1));
        $forces = array_map(static fn (int $i): bool => $i % 3 === 0, range(0, $count - 1));
        foreach (['rust', null, 'php', '', 'RUST'] as $worker) {
            foreach ([-5, 0, 1, 7, 1024, 4096] as $shards) {
                $this->assertParity('dispatch', [$ids, self::TEXTS, $forces, $worker, $shards]);
            }
        }
        $this->assertParity('dispatch', [['a' => 3, 'b' => 4], [9 => 'x', 2 => ' y '], [false, true], 'php', 16]);

        // Breaker: unknown events, out-of-order and negative times, disabled and short windows, boundary spans.
        $providers = [1, 2, 1, PHP_INT_MAX, PHP_INT_MIN, 1, 2, 1, 1, 1, 2, 2];
        $times = [-50, -40, -60, 0, 1, 2, 3, 2000, 1999, 9000, 9001, 9002];
        $events = [0, 1, 1, 0, -1, 4, 0, 0, 2, 3, 0, PHP_INT_MAX];
        foreach ([[900, 0, 1800], [0, 0, 0], [-5, 10, 5], [1, 1, 1], [60, 45, 0], [60, 45, -10], [1 << 40, 1 << 40, -(1 << 40)]] as [$window, $cooldown, $probe]) {
            $this->assertParity('breaker', [$providers, $times, $events, $window, $cooldown, $probe]);
        }
        $this->assertParity('breaker', [[1, 1], [1 << 52, -(1 << 52)], [1, 0], 900, 0, 1800]);

        // Frames: every text alone and behind each magic, plus malformed field and line lists.
        [$frames] = $this->referenceFrames();
        $this->assertParity('parser', [$frames]);
        $wrapped = [];
        foreach (self::TEXTS as $text) {
            $wrapped[] = $text;
            $wrapped[] = 'AI02'.$text;
            $wrapped[] = 'XR01'.$text;
        }
        $this->assertParity('parser', [$wrapped]);
        $field = static fn (?string $value): string => $value === null ? "\xff\xff\xff\xff" : pack('V', strlen($value)).$value;
        $lines = static fn (array $rows): string => pack('V', count($rows)).implode('', array_map($field, $rows));
        $this->assertParity('parser', [[
            'XR01'.$field('').$field($lines([])).$field($lines([''])),
            'XR01'.$field('').$field(pack('V', 2).$field('a')).$field($lines([])),
            'XR01'.$field('').$field(pack('V', 0xFFFFFFFF)).$field($lines([])),
            'XR01'.$field('').$field(pack('V', 1)."\xff\xff\xff\xff").$field($lines([])),
            'XR01'.$field('').$field($lines(['a']).'x').$field($lines([])),
            'XR01'.$field('').$field('ab').$field($lines([])),
            'XR01'.$field('').$field(null),
            'AI02'.$field('<>').$field('').$field(null).$field(null).$field(null),
            'AI02'.$field('<a>').$field("\n\n").$field('').$field(null).$field(''),
            'AI02'.$field('<a>').$field('').$field(null).$field(null),
            'AI02'.$field('<a>').$field('').$field(null).$field(null).$field(null)."\0",
            'AI02'.pack('V', 0xFFFFFFFE).'x',
            'AI02'.pack('V', 3).'ab',
            'AI02'."\x01\x00",
            "AI02\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0",
            "XR01\0\0\0\0\4\0\0\0\0\0\0\0\4\0\0\0\0\0\0\0",
        ]]);

        // Segments and candidates: every text alone, in variants and as a sliding window.
        foreach (self::TEXTS as $index => $text) {
            $variants = [$text, strtoupper($text), ' '.$text.' ', '<'.$text.'>', $text.'@Host', $text.' 1.0', $text.'/2', $text, null];
            $window = array_slice([...self::TEXTS, ...self::TEXTS], $index, 1 + $index % 13);
            $this->assertParity('analyzer', [[$text], [$index - 3]]);
            $this->assertParity('analyzer', [$variants, [$index, $index, PHP_INT_MAX - $index, -$index]]);
            $this->assertParity('analyzer', [$window, array_fill(0, $index % 5, 768000)]);
            $this->assertParity('collector', [[$text]]);
            $this->assertParity('collector', [$variants]);
            $this->assertParity('collector', [$window]);
        }
        $this->assertParity('analyzer', [['k' => '<a@b>', 'm' => 7, 'n' => '<a@b>', 'o' => 1.5, 'p' => true], [0, 0, 1, 1]]);
        $this->assertParity('collector', [['k' => 'ngPost 1', 'm' => 7, 'n' => 'NGPOST 1', 'o' => [], 'p' => false]]);
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4723));
        for ($i = 0; $i < 300; $i++) {
            foreach (array_keys(self::HELPERS) as $helper) {
                $args = $random->getInt(0, 1) === 0 ? $this->commonArgs($helper, $random, $random->getInt(0, 24))
                    : $this->uncommonArgs($helper, $random, $random->getInt(0, 24));
                $this->assertParity($helper, $args);
            }
        }
    }

    public function test_declined_and_malformed_batches_fall_back_to_php(): void
    {
        $this->ffiOrSkip();
        // A NUL byte inside a NUL-joined string no longer splits into the batch, so Rust declines it.
        $declined = [
            'dispatch' => [[1, 2], ["a\0b", ''], [false, false], 'php', 16],
            'analyzer' => [["<a\0b@c>", '<x@y>'], [1]],
            'collector' => [["ng\0post", 'nyuu']],
            // Times and spans beyond the native limits would leave PHP integers.
            'breaker' => [[1], [(1 << 52) + 1], [0], 900, 0, 1800],
        ];
        $declined['breaker_span'] = [[1], [0], [1], (1 << 40) + 1, 0, 1800];
        foreach ($declined as $case => $args) {
            $helper = $case === 'breaker_span' ? 'breaker' : $case;
            [$class, $method, $toggle] = self::HELPERS[$helper];
            $this->assertNull($class::{$method.'Native'}(...$args), $case);
            $class::{'use'.$toggle.'RustFfi'}(true);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $case);
        }
        // NUL bytes are ordinary frame bytes.
        $this->assertNotNull(NativeMetadataParser::summarizeFrameBatchNative(["AI02\0\0", "\0"]));

        // Mismatched parallel lists are rejected identically by every path.
        foreach (['planEnqueueBatch', 'planEnqueueBatchPhp', 'planEnqueueBatchNative'] as $method) {
            $this->assertRejectsMismatch(fn () => FingerprintDispatch::$method([1, 2], ['a'], [false, true]), $method);
            $this->assertRejectsMismatch(fn () => FingerprintDispatch::$method([1], ['a'], []), $method);
        }
        foreach (['simulateBatch', 'simulateBatchPhp', 'simulateBatchNative'] as $method) {
            $this->assertRejectsMismatch(fn () => FingerprintProviderBreaker::$method([1, 2], [0, 1], [0], 900), $method);
            $this->assertRejectsMismatch(fn () => FingerprintProviderBreaker::$method([1], [], [0], 900), $method);
        }

        // Batches over the native item cap stay in PHP.
        $many = array_fill(0, FingerprintDispatch::W47_MAX_ITEMS + 1, 'a');
        $this->assertNull(ReleaseFingerprintCollector::aggregateCandidateBatchNative($many));
        $this->assertNull(NzbFingerprintAnalyzer::summarizeSegmentBatchNative($many));
        $this->assertNull(NativeMetadataParser::summarizeFrameBatchNative($many));
        $this->assertNull(NzbFingerprintAnalyzer::summarizeSegmentBatchNative(['a'], array_fill(0, FingerprintDispatch::W47_MAX_ITEMS + 1, 1)));
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (array_keys(self::HELPERS) as $helper) {
            [$class, $method, $toggle] = self::HELPERS[$helper];
            $args = $this->commonArgs($helper, new Randomizer(new Mt19937(4724)), 12);
            (new ReflectionProperty($class, lcfirst($toggle).'RustFfiHandle'))->setValue(null, false);
            $class::{'use'.$toggle.'RustFfi'}(true);
            $this->assertNull($class::{$method.'Native'}(...$args), $helper);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $helper);
            $class::{'reset'.$toggle.'RustFfi'}();
            $this->assertNull((new ReflectionProperty($class, lcfirst($toggle).'RustFfiHandle'))->getValue(), $helper);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[256]');
        $small = $ffi->new('char[8]');
        $probe = pack('q', 0);
        // Every export reports -required without writing when the output is too small, then fills it.
        foreach (self::HELPERS as $helper => [$class, , , $symbol, $slots]) {
            $handle = RustPipelineLibrary::resolveDomain(FingerprintDispatch::W47_FFI_DOMAIN, constant($class.'::W47_FFI_CDEF'));
            $this->assertInstanceOf(FFI::class, $handle, $helper);
            $aux = $helper === 'breaker' ? $probe : '';
            $paramB = $helper === 'dispatch' ? 16 : 0;
            FFI::memset($small, ord('Z'), 8);
            $this->assertSame(-$slots * 8, $this->callFfi($handle, $symbol, '', 0, $aux, strlen($aux), 0, $paramB, $small, 8), $helper);
            $this->assertSame('ZZZZZZZZ', FFI::string($small, 8), $helper);
            $this->assertSame($slots * 8, $this->callFfi($handle, $symbol, '', 0, $aux, strlen($aux), 0, $paramB, $out, 256), $helper);
            $this->assertSame(-1, $this->callFfi($handle, $symbol, '', 0, $aux, strlen($aux), 0, $paramB, null, 256), $helper);
            $this->assertSame(-1, $this->callFfi($handle, $symbol, null, 0, $aux, strlen($aux), 0, $paramB, $out, 256), $helper);
        }
        $dispatch = RustPipelineLibrary::resolveDomain(FingerprintDispatch::W47_FFI_DOMAIN, FingerprintDispatch::W47_FFI_CDEF);
        $this->assertNotNull($dispatch);
        $symbol = 'nntmux_w47_fingerprint_dispatch_batch';
        $aux = pack('q', 9)."\x01";
        $this->assertSame(104, $this->callFfi($dispatch, $symbol, 'g', 1, $aux, 9, 1, 16, $out, 256));
        $this->assertSame([1, 0, 1, 0, 0, 0, 0, 0, 9, 0, 9, 1], array_slice(array_values(unpack('P13', FFI::string($out, 104)) ?: []), 0, 12));
        // Bad database flags, shard counts, force bytes, table lengths and generation splits decline.
        foreach ([[2, 16, $aux, 'g'], [1, 0, $aux, 'g'], [1, 1025, $aux, 'g'], [1, 16, pack('q', 9)."\x02", 'g'], [1, 16, pack('q', 9), 'g'],
            [1, 16, $aux, "g\0h"]] as [$database, $shards, $table, $blob]) {
            $this->assertSame(-1, $this->callFfi($dispatch, $symbol, $blob, strlen($blob), $table, strlen($table), $database, $shards, $out, 256));
        }
        $parser = RustPipelineLibrary::resolveDomain(FingerprintDispatch::W47_FFI_DOMAIN, NativeMetadataParser::W47_FFI_CDEF);
        $this->assertNotNull($parser);
        $frame = 'nntmux_w47_metadata_frame_batch';
        $this->assertSame(-1, $this->callFfi($parser, $frame, 'ab', 2, pack('V', 1), 4, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($parser, $frame, 'ab', 2, pack('V', 3), 4, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($parser, $frame, 'ab', 2, pack('V', 2).'x', 5, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($parser, $frame, 'ab', 2, pack('V', 2), 4, 1, 0, $out, 256));
        $this->assertSame(136, $this->callFfi($parser, $frame, 'ab', 2, pack('V', 2), 4, 0, 0, $out, 256));
        // An output overlapping an input declines without writing to it.
        $shared = $ffi->new('char[256]');
        FFI::memcpy($shared, 'ab'.pack('V', 2), 6);
        $this->assertSame(-1, $this->callFfi($parser, $frame, FFI::addr($shared[0]), 2, FFI::addr($shared[2]), 4, 0, 0, FFI::addr($shared[4]), 136));
        $this->assertSame('ab'.pack('V', 2), FFI::string($shared, 6));
        $this->assertSame(136, $this->callFfi($parser, $frame, FFI::addr($shared[0]), 2, FFI::addr($shared[2]), 4, 0, 0, FFI::addr($shared[8]), 136));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including the PHP-side type
        // checks, packing, FFI call and counter decoding (release dylib, 5 warmups, 601 paired samples per helper and
        // workload, 20 iterations per common sample and 10 per uncommon sample).
        // Common = one 64-128 item batch of typical inputs; uncommon = 128 mixed, long, malformed and duplicate inputs.
        // Speedup = PHP latency / Rust latency, ranges over 6 rounds (PHP 8.5.12 NTS, release dylib, Apple Silicon, shared host at
        // load average ~7.3-9.2):
        // - dispatch:  common mean 2.34-2.47x / p99 2.27-2.44x, uncommon mean 3.00-3.76x / p99 2.84-4.15x
        // - breaker:   common mean 2.68-2.85x / p99 2.20-2.58x, uncommon mean 1.99-2.19x / p99 1.68-1.92x
        // - parser:    common mean 3.86-3.93x / p99 2.59-3.99x, uncommon mean 4.78-5.23x / p99 3.99-6.11x
        // - analyzer:  common mean 3.29-3.42x / p99 2.95-3.38x, uncommon mean 3.25-3.47x / p99 2.61-3.15x
        // - collector: common mean 2.18-2.29x / p99 1.94-2.17x, uncommon mean 1.77-2.06x / p99 1.72-2.03x
        // Every helper clears 1.5x on every measure in every round and defaults to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustFingerprintDispatchBreakerParserAnalyzerCollectorW47ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4725));
        $warmup = 5;
        $samples = 601;
        $sink = 0;
        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method, $toggle]) {
            $workloads = [
                'common' => [$this->commonArgs($helper, new Randomizer(new Mt19937(4726))), 20],
                'uncommon' => [$this->uncommonArgs($helper, new Randomizer(new Mt19937(4727))), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $native = $class::{$method.'Native'}(...$args);
                $this->assertNotNull($native, $helper.' '.$name.' must run natively');
                $this->assertSame($class::{$method.'Php'}(...$args), $native, $helper.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($helper, $args, false) + $this->runWorkload($helper, $args, true);
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $class::{'use'.$toggle.'RustFfi'}($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$method(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::{'reset'.$toggle.'RustFfi'}();
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
        fwrite(STDERR, "\nRustFingerprintDispatchBreakerParserAnalyzerCollectorW47 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class, , $toggle]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            $default = (new ReflectionClass($class))->getConstant(self::CONSTANTS[$helper]);
            if (! $qualifies) {
                $this->assertFalse($default, $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::{'uses'.$toggle.'RustFfi'}(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if ($default === true) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $helper, array $args): void
    {
        [$class, $method, $toggle] = self::HELPERS[$helper];
        $expected = $class::{$method.'Php'}(...$args);
        $native = $class::{$method.'Native'}(...$args);
        $context = $helper.': '.json_encode($this->describe($args));
        $this->assertNotNull($native, $helper.' declined: '.$context);
        $this->assertSame($expected, $native, $context);
        $class::{'use'.$toggle.'RustFfi'}(true);
        $this->assertSame($expected, $class::$method(...$args), $helper.' rust dispatcher');
        $class::{'use'.$toggle.'RustFfi'}(false);
        $this->assertSame($expected, $class::$method(...$args), $helper.' php dispatcher');
        $class::{'reset'.$toggle.'RustFfi'}();
    }

    private function assertRejectsMismatch(callable $call, string $context): void
    {
        try {
            $call();
            $this->fail($context.' accepted mismatched lists');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('same length', $exception->getMessage(), $context);
        }
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, int>
     */
    private function callHelper(string $helper, string $variant, array $args): array
    {
        [$class, $method] = self::HELPERS[$helper];

        return $class::{$method.$variant}(...$args);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function runWorkload(string $helper, array $args, bool $useRust): int
    {
        [$class, $method, $toggle] = self::HELPERS[$helper];
        $class::{'use'.$toggle.'RustFfi'}($useRust);

        return count($class::$method(...$args));
    }

    /**
     * @return list<mixed>
     */
    private function emptyArgs(string $helper): array
    {
        return match ($helper) {
            'dispatch' => [[], [], [], 'rust', 16],
            'breaker' => [[], [], [], 900, 0, 1800],
            'analyzer' => [[], []],
            default => [[]],
        };
    }

    /**
     * Typical batches: 96 rust-worker requests, 96 breaker events over four providers, 64 well-formed frames, 128
     * segment ids of a multipart post and 64 uploader software names.
     *
     * @return list<mixed>
     */
    private function commonArgs(string $helper, Randomizer $random, ?int $count = null): array
    {
        $hex = static fn (int $bytes): string => bin2hex($random->getBytes($bytes));
        $range = static fn (int $n): array => $n > 0 ? range(1, $n) : [];
        if ($helper === 'dispatch') {
            $count ??= 96;
            $ids = array_map(static fn (int $i): int => 1_000_000 + $random->getInt(0, 4 * max(1, $count)), $range($count));

            return [$ids, array_map(static fn (): string => 'nzb-'.$hex(8), array_fill(0, $count, 0)),
                array_map(static fn (): bool => $random->getInt(0, 9) === 0, array_fill(0, $count, 0)), $random->getInt(0, 3) === 0 ? 'php' : 'rust', 16];
        }
        if ($helper === 'breaker') {
            $count ??= 96;
            $time = 1_700_000_000;
            $providers = $times = $events = [];
            for ($i = 0; $i < $count; $i++) {
                $providers[] = $random->getInt(1, 4);
                $times[] = $time += $random->getInt(0, 240);
                $events[] = [0, 0, 0, 0, 1, 2, 3][$random->getInt(0, 6)];
            }

            return [$providers, $times, $events, 900, 0, 1800];
        }
        if ($helper === 'parser') {
            $count ??= 64;
            $field = static fn (?string $value): string => $value === null ? "\xff\xff\xff\xff" : pack('V', strlen($value)).$value;
            $frames = [];
            for ($i = 0; $i < $count; $i++) {
                $headers = "Path: news.example\r\nFrom: poster@example.com\r\nSubject: [".$i.'/'.$count.'] - "file.part'.$i.'.rar" yEnc ('.$i."/64)\r\n"
                    .'Message-ID: <part'.$i.'.'.$hex(12)."@news.example>\r\nNewsgroups: alt.binaries.test\r\nDate: Sat, 24 Oct 2026 12:00:00 +0000\r\n"
                    .'Bytes: 768000'."\r\nLines: 5000\r\n";
                $frames[] = $i % 4 === 3
                    ? 'XR01'.$field($headers).$field(pack('V', 2).$field($i."\tSubject\tposter\tdate\t<id>\t\t768000\t5000").$field($i."\tXref: news a:$i"))
                        .$field(pack('V', 3).$field('Subject:').$field('From:').$field('Xref:full'))
                    : 'AI02'.$field('<part'.$i.'.'.$hex(12).'@news.example>').$field($headers).$field($i % 2 === 0 ? null : $i."\tSubject\t<id>")
                        .$field('221 0 <id> head').$field($i % 3 === 0 ? null : '222 0 <id> body');
            }

            return [$frames];
        }
        if ($helper === 'analyzer') {
            $count ??= 128;
            $token = $hex(10);

            return [array_map(static fn (int $i): string => '<part'.$i.'of'.$count.'.'.$token.$hex(4).'@news.example.com>', $range($count)),
                array_map(static fn (int $i): int => $i === $count ? 412_345 : 768_000, $range($count))];
        }
        $count ??= 64;
        $names = ['ngPost 4.16', 'Nyuu/0.4.1', 'newsmangler 0.1.3', 'GoPostStuff/1.0', 'JBinUp 0.90', 'yEnc-POWER-POST-A&A 11b', 'Newspost 2.1.1', 'ngPost',
            'NZBGet', 'camelsystem-powerpost'];

        return [array_map(static fn (): string => $names[$random->getInt(0, count($names) - 1)], array_fill(0, $count, 0))];
    }

    /**
     * Unusual batches of 128 items: rejected ids and blank or long generations, out-of-order and invalid events over
     * many providers, truncated, trailing, unknown and malformed frames, malformed/duplicate/low-entropy ids with
     * negative and overflowing sizes, and odd whitespace, slashes, case and non-strings in candidates.
     *
     * @return list<mixed>
     */
    private function uncommonArgs(string $helper, Randomizer $random, ?int $count = null): array
    {
        $count ??= 128;
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $text = static function () use ($random, $pick): string {
            $out = '';
            for ($i = $random->getInt(0, 6); $i > 0; $i--) {
                $out .= $random->getInt(0, 1) === 0 ? $pick(self::TEXTS) : $pick(['a', 'Z', '9', ' ', "\t", '/', '<', '>', '@', '.', "\xc3\xa9", "\xff"]);
            }

            return $out;
        };
        if ($helper === 'dispatch') {
            $ids = $generations = $forces = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = $pick([0, -1, PHP_INT_MIN, PHP_INT_MAX, $random->getInt(1, 50), $random->getInt(1, 1 << 40)]);
                $generations[] = $pick(['', ' ', "\t\n", str_repeat('g', $random->getInt(150, 300)), ' '.$text().' ', $text()]);
                $forces[] = $random->getInt(0, 1) === 1;
            }

            return [$ids, $generations, $forces, $pick([null, 'rust', 'php', 'horizon', '']), $pick([1, 3, 7, 16, 1024])];
        }
        if ($helper === 'breaker') {
            $providers = $times = $events = [];
            $time = 0;
            for ($i = 0; $i < $count; $i++) {
                $providers[] = $random->getInt(0, 31);
                $time += $random->getInt(-120, 400);
                $times[] = $time;
                $events[] = $pick([0, 0, 1, 1, 2, 3, -1, 7]);
            }

            return [$providers, $times, $events, $pick([60, 900, 0, -1]), $pick([0, 45, 120]), $pick([30, 1800, 0])];
        }
        if ($helper === 'parser') {
            [$common] = $this->commonArgs('parser', $random, $count);
            $frames = [];
            foreach ($common as $i => $frame) {
                $frames[] = match ($i % 8) {
                    0 => substr($frame, 0, $random->getInt(0, strlen($frame) - 1)),
                    1 => $frame."\0",
                    2 => 'ZZ99'.substr($frame, 4),
                    3 => null,
                    4 => substr($frame, 0, 4).pack('V', 0xFFFFFFFF).substr($frame, 8),
                    5 => $text(),
                    default => $frame,
                };
            }

            return [$frames];
        }
        if ($helper === 'analyzer') {
            $ids = $sizes = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = match ($i % 9) {
                    0 => null,
                    1 => '<aaaa'.$i.'@x>',
                    2 => ' <'.str_repeat('ab', $random->getInt(1, 90)).'@Host'.($i % 3).'.example> ',
                    3 => '<bad id'.$i.'@x>',
                    4 => $ids[$random->getInt(0, max(0, $i - 1))] ?? '<dup@x>',
                    5 => 'noat'.$i,
                    6 => $text(),
                    default => '<part'.$i.'.'.bin2hex($random->getBytes(6)).'@news.example.com>',
                };
                $sizes[] = $pick([768_000, 768_000, 768_000, 0, -5, $random->getInt(1, 1 << 20), PHP_INT_MAX - $i]);
            }

            return [$ids, $sizes];
        }
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = match ($i % 7) {
                0 => null,
                1 => " \t".strtoupper($pick(['ngpost', 'nyuu', 'newsmangler']))."\x0C\x0B ".$random->getInt(1, 9).'.'.$random->getInt(0, 20),
                2 => $pick(['', ' ', "\x0C", '/', '//x', 'a/']),
                3 => str_repeat('Long Software Name ', $random->getInt(1, 12)),
                4 => $text(),
                default => $pick(['ngPost 4.16', 'Nyuu/0.4.1', 'newsmangler', 'GoPostStuff/1.0 (linux)']),
            };
        }

        return [$names];
    }

    /**
     * The frames the Rust unit test summarizes: identity, xref, broken line list, trailing byte, truncated, short,
     * unknown magic, non-string and a null requested id; the second list holds the two valid frames.
     *
     * @return array{0: list<string|null>, 1: list<string>}
     */
    private function referenceFrames(): array
    {
        $field = static fn (?string $value): string => $value === null ? "\xff\xff\xff\xff" : pack('V', strlen($value)).$value;
        $lines = static fn (array $rows): string => pack('V', count($rows)).implode('', array_map($field, $rows));
        $identity = 'AI02'.$field('<a@b>').$field("Subject: x\r\nFrom: y\r\n").$field(null).$field('221 ok').$field(null);
        $xref = 'XR01'.$field("Xref: a\n").$field($lines(['row1', 'row2'])).$field($lines(['Subject:']));
        $badLines = 'XR01'.$field('').$field(substr($lines(['r']), 0, 7)).$field($lines([]));

        return [[$identity, $xref, $badLines, $identity.'!', substr($identity, 0, 30), 'AI0', 'ZZ99data', null, 'AI02'.$field(null)], [$identity, $xref]];
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
        $ffi = RustPipelineLibrary::resolveDomain(FingerprintDispatch::W47_FFI_DOMAIN, FingerprintDispatch::W47_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 47.2 exports is not available.');
        }

        return $ffi;
    }
}
