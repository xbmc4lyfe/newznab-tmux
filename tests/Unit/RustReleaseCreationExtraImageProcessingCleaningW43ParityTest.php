<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionsCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseProcessingService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 43.1 pure CPU release creation, codec, image, binary name and subject batch summaries.
 * The opt-in benchmark retains raw randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W431_BENCH=1 php vendor/bin/phpunit tests/Unit/RustReleaseCreationExtraImageProcessingCleaningW43ParityTest.php
 */
final class RustReleaseCreationExtraImageProcessingCleaningW43ParityTest extends TestCase
{
    /** @var list<array{class-string, string, string, list<string>}> */
    private const array HELPERS = [
        [ReleaseCreationService::class, 'summarizeCollectionSubjectBatch', 'nntmux_w431_release_creation_batch', ['subject', 'name']],
        [ReleaseExtraService::class, 'summarizeCodecBatch', 'nntmux_w431_release_extra_codec_batch', ['codec', 'format']],
        [ReleaseImageService::class, 'summarizeImageReferenceBatch', 'nntmux_w431_release_image_batch', ['url', 'basename', 'name']],
        [ReleaseProcessingService::class, 'summarizeBinaryNameBatch', 'nntmux_w431_release_processing_batch', ['name', 'filename']],
        [CollectionsCleaningService::class, 'summarizeSubjectBatch', 'nntmux_w431_collections_subject_batch', ['subject', 'name']],
    ];

    /**
     * Benchmark-qualified defaults. Eight paired runs (3000 samples each, Apple M5, PHP 8.5 CLI without opcache)
     * gave min common mean/p99 and uncommon mean/p99 speedups of: creation 1.83/1.65/2.31/2.15, codec
     * 2.19/1.78/3.75/3.26, image 1.90/1.41/2.31/2.06 (common p99 1.41x, 1.49x and 1.49x in three runs, so PHP
     * stays), binary names 2.12/1.53/2.72/2.26 and subjects 3.01/2.06/3.22/2.91.
     */
    private const array DEFAULTS = [true, true, false, true, true];

    /** Earlier-wave public toggles and helpers that must stay intact. */
    private const array LEGACY_METHODS = [
        ReleaseCreationService::class => ['setUseRustFfi', 'usesRustFfi', 'sanitizeCollectionNamesPhp'],
        ReleaseExtraService::class => ['makeCodecPretty', 'makeCodecPrettyPhp', 'makeCodecPrettyNative'],
        ReleaseImageService::class => ['setUseRustFfi', 'isValidBasenamePhp', 'redactedUrlPhp'],
        ReleaseProcessingService::class => ['setUseRustFfi', 'usesRustFfi'],
        CollectionsCleaningService::class => ['setUseRustFfi', 'usesRustFfi', 'isMusicGroupPhp'],
    ];

    private const int SEED = 4312026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW431RustFfi();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new \ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW431RustFfi', 'usesW431RustFfi', 'resetW431RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W431_RUST_FFI_DEFAULT'));
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        return [
            [[
                '[01/10] - "file.rar" yEnc',
                ['subject' => "  Caf\xc3\xa9 \xc2\xa9 #1  "],
                ['name' => '""'],
                ['subject' => null, 'name' => str_repeat('x', 256)],
                " \0\t",
                "\xc3\x96sterreich \"a\"",
                null,
                ['subject' => ['nested']],
                42,
                'user@host 100%',
            ], ['total_subjects' => 10, 'empty_subject_count' => 3, 'stripped_char_count' => 3, 'quoted_filename_count' => 2, 'non_ascii_count' => 2, 'oversized_subject_count' => 1]],
            [[
                'hevc', '36', "36\n", 'x36', ['codec' => 'H264'], "27\n", 'AVC1', ['format' => 'mpg4'], 'MPEG-4 ASP', '2', "2\n\n", 'MPEG', 'mpeg', 'DIV3', 'XviD', 'WVC1', '', 7, null, 'h.264', ' ',
            ], ['total_codecs' => 21, 'empty_codec_count' => 2, 'hevc_count' => 3, 'h264_count' => 4, 'mpeg4_count' => 2, 'mpeg2_count' => 1, 'mpeg1_count' => 1, 'divx_count' => 1, 'xvid_count' => 1, 'wmv_count' => 1, 'other_count' => 5]],
            [[
                'abc-123_x',
                '_abc',
                'HTTPS://user:pw@host/a.JPG?x=1',
                ['url' => 'http://host/p@x/img.webp'],
                ['basename' => 'cover'],
                'cover.png',
                '',
                ['name' => ' ftp://x/y.gif#z '],
                5,
                null,
            ], ['total_refs' => 10, 'empty_ref_count' => 2, 'valid_basename_count' => 3, 'http_url_count' => 2, 'credential_url_count' => 1, 'image_extension_count' => 4, 'query_url_count' => 1]],
            [[
                'a.vol07+08.PAR2',
                ['name' => 'b.par2'],
                ' ',
                'x.part01.rar "',
                ['filename' => 'x.r07'],
                'x.001"',
                'x.7zz',
                'Movie (1/2)',
                null,
                'c.vol+08.par2',
            ], ['total_names' => 10, 'empty_name_count' => 2, 'par2_count' => 3, 'par2_volume_count' => 1, 'archive_count' => 3, 'counter_count' => 1, 'par2_only' => 0]],
            [[
                'alt.binaries.sounds.flac',
                ['subject' => '[1/5] - "song.flac" - 12.5 MB yEnc'],
                "bad \xff yEnc",
                'x.mp3',
                '  (01/15) file.nfo" 365 kb  ',
                ['name' => 'Something YENC'],
                '',
                3,
                ['subject' => false],
            ], ['total_subjects' => 9, 'empty_subject_count' => 2, 'yenc_suffix_count' => 2, 'size_tagged_count' => 2, 'file_extension_count' => 2, 'part_counter_count' => 2, 'music_group_count' => 3, 'invalid_utf8_count' => 1]],
        ];
    }

    public function test_hand_calculated_contracts_on_both_dispatches(): void
    {
        foreach (self::literalCases() as $i => [$input, $expected]) {
            [$class, $method] = self::HELPERS[$i];
            self::assertSame($expected, $class::{$method.'Php'}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            $empty = array_fill_keys(array_keys($expected), 0);
            foreach ([false, true] as $enabled) {
                $class::useW431RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
                $single = ['x'];
                self::assertSame($class::{$method.'Php'}($single), $class::{$method}($single));
            }
        }
        $par2Only = ['a.par2', ['name' => 'a.vol00+01.PAR2'], '', null];
        $expected = ['total_names' => 4, 'empty_name_count' => 2, 'par2_count' => 2, 'par2_volume_count' => 1, 'archive_count' => 0, 'counter_count' => 0, 'par2_only' => 1];
        self::assertSame($expected, ReleaseProcessingService::summarizeBinaryNameBatchPhp($par2Only));
        self::assertSame($expected, ReleaseProcessingService::summarizeBinaryNameBatchNative($par2Only));
    }

    public function test_codec_label_refactor_preserves_make_codec_pretty(): void
    {
        $service = new ReleaseExtraService;
        $cases = ['36' => 'HEVC', "36\n" => 'HEVC', 'x265 HEVC' => 'HEVC', 'h264' => 'h.264', 'AVC' => 'h.264', '20' => 'MPEG-4', 'asp' => 'MPEG-4', '2' => 'MPEG-2',
            'MPEG' => 'MPEG-1', 'mpeg' => 'mpeg', 'DX50' => 'DivX', 'xvid' => 'XviD', 'WMV3' => 'wmv', 'h.264' => 'h.264', '' => '', 'VP9' => 'VP9'];
        foreach ($cases as $codec => $label) {
            self::assertSame($label, $service->makeCodecPrettyPhp((string) $codec), (string) $codec);
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW431RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW431RustFfi());
            $class::useW431RustFfi(false);
        }
        $legacy = [ReleaseCreationService::usesRustFfi(), ReleaseProcessingService::usesRustFfi(), CollectionsCleaningService::usesRustFfi()];
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW431RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW431RustFfi());
            }
            $class::resetW431RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW431RustFfi());
            $class::useW431RustFfi(false);
            self::assertFalse($class::usesW431RustFfi());
        }
        self::assertSame($legacy, [ReleaseCreationService::usesRustFfi(), ReleaseProcessingService::usesRustFfi(), CollectionsCleaningService::usesRustFfi()], 'W431 toggles must not touch earlier toggles.');
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW431RustFfi(true);
            (new ReflectionProperty($class, 'w431Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w431Ffi'))->getValue());
        }
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new \ReflectionClass($class))->getConstant('W431_RUST_FFI_DEFAULT') === true;
    }

    private static function ffi(string $class): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning_w431', (new \ReflectionClass($class))->getConstant('W431_FFI_CDEF'));
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W431 symbols.');

        return $ffi;
    }

    /** @param list<string> $entries */
    private static function wire(array $entries): string
    {
        return pack('V*', count($entries), ...array_map(strlen(...), $entries)).implode('', $entries);
    }

    /** @param list<string> $entries */
    private static function nulWire(array $entries): string
    {
        return pack('VV', count($entries), 0xFFFFFFFF).implode("\0", $entries);
    }

    /**
     * @param  list<string>  $fields
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function entries(array $fields, array $input): array
    {
        return CollectionsCleaningService::w431Entries($input, $fields);
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    /**
     * @param  list<string>  $fields
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private static function rawResult(FFI $ffi, string $symbol, array $fields, array $input, array $keys): array
    {
        $out = $ffi->new('char[256]');
        $entries = self::entries($fields, $input);
        $written = self::export($ffi, $symbol, self::wire($entries), $out, 256);
        self::assertSame(count($keys) * 8, $written, 'Native export must execute, not silently fall back.');
        $raw = FFI::string($out, $written);
        if ($entries !== [] && ! str_contains(implode('', $entries), "\0")) {
            self::assertSame(count($keys) * 8, self::export($ffi, $symbol, self::nulWire($entries), $out, 256));
            self::assertSame($raw, FFI::string($out, $written), 'NUL-separated and length frames must agree.');
        }
        $values = unpack('q*', $raw);
        self::assertIsArray($values);

        return array_combine($keys, array_values($values));
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, int>  $expected
     */
    private static function assertNativeDispatch(string $class, string $method, FFI $ffi, array $input, array $expected): void
    {
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 256);
        $class::useW431RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = pack('q*', ...array_values($expected));
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w431Ffi'))->getValue());
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $good = self::wire(['entry']);
        $malformed = ['', "\x01\0\0", "\x01\0\0\0", "\x01\0\0\0\x09\0\0\0abc", "\x02\0\0\0\x01\0\0\0x", $good.'x', substr($good, 0, -1), "\xff\xff\xff\xff",
            pack('VV', 0, 0xFFFFFFFF), self::nulWire(['a', 'b'])."\0c", substr(self::nulWire(['a', 'b', 'c']), 0, -2), pack('VV', 3, 0xFFFFFFFF)];
        foreach (self::HELPERS as $i => [$class, , $symbol]) {
            $keys = count(self::literalCases()[$i][1]);
            $ffi = self::ffi($class);
            $out = $ffi->new('char[256]');
            foreach ($malformed as $wire) {
                FFI::memset($out, 90, 256);
                self::assertSame(-1, self::export($ffi, $symbol, $wire, $out, 256), bin2hex($wire));
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
            $required = self::export($ffi, $symbol, $good, $out, 1);
            self::assertSame(-$keys * 8, $required, 'Insufficient capacity reports the required size.');
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256), 'Insufficient capacity must not write.');
            self::assertSame(-$required, self::export($ffi, $symbol, $good, $out, -$required));
        }
    }

    public function test_objects_are_not_serialized_and_oversized_inputs_fall_back(): void
    {
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \LogicException('Caller-owned objects must not be serialized.');
            }

            public function __toString(): string
            {
                throw new \LogicException('Caller-owned objects must not be cast.');
            }
        };
        $large = str_repeat('x', 16777217);
        foreach (self::HELPERS as [$class, $method, , $fields]) {
            $row = array_fill_keys($fields, $object);
            $input = [$object, $row, [$fields[0] => null, $fields[count($fields) - 1] => 'Ok.2020.nfo']];
            $class::useW431RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW431RustFfi();
            $class::useW431RustFfi(true);
            $oversized = [$large, array_fill_keys($fields, $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w431Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Separators, control bytes, invalid UTF-8 and multibyte text.
        '', ' ', '.', '-', '_', ' - ', '"', '""', '[', ']', '(', ')', '/', '\\', '+', '?', '#', '@', ':', "\0", "\t", "\v", "\f", "\n", "\r", "\xff", "\x80", "\xc2", "\xc3",
        "\xc2\xa7", "\xc2\xa8", "\xc2\xa9", "\xc2\xaa", "\xc3\x96", "\xc3\x97", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x82\xac", '日本語', '$', '%', '^', '&',
        // Subjects and counters.
        'yEnc', 'YENC', 'yenc', 'yEn', '1', '01', '15', '/', '(01/15)', '[1/5]', '(1/)', '[/2]', '12.5', '365', ' MB', ' kb', ' Gb', ' TB', 'MB', ' K', 'file', 'song',
        '.flac', '.FLAC', '.lossless', '.mp3', '.music', '.sounds', 'flac', 'alt.binaries', '.nfo"', '.r"', '.abcde"', '.ab"', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        // Codecs.
        '36', '7', '27', '2', '20', 'H264', 'h264', 'HEVC', 'hevc', 'AVC', 'avc1', 'ASP', 'FMP4', 'MP42', 'MP43', 'MPG4', 'MPEG', 'mpeg', 'DX50', 'DIVX', 'DIV3', 'XviD', 'WMV', 'wvc1',
        // Image references.
        'http://', 'https://', 'HTTP://', 'httpx://', 'ftp://', 'user:pw@', 'host', 'cover', 'a.jpg', '.jpeg', '.JPG', '.png', '.gif', '.webp', '.bmp', '.jpgx', '?x=1', '#frag', 'abc-123_x',
        // Binary names.
        '.par2', '.PAR2', '.vol07+08.par2', '.vol+08.par2', '.vol7+.par2', '.VOL01+02.PAR2', '.rar', '.RAR', '.zip', '.7z', '.r00', '.R99', '.r0', '.001', '.01', '.0001', 'part01',
    ];

    private static function randomEntry(Randomizer $random): string
    {
        $entry = '';
        for ($k = 0, $n = $random->getInt(0, 12); $k < $n; $k++) {
            $entry .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $entry .= ['.', '-', ' ', '_', '"', '/', '\\', '+', "\n", "\xff", "\0", '?'][$random->getInt(0, 11)];
            }
        }

        return $entry;
    }

    /**
     * @param  list<string>  $fields
     * @return array<array-key, mixed>
     */
    private static function randomBatch(Randomizer $random, array $fields): array
    {
        $rows = [];
        for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
            $entry = self::randomEntry($random);
            $field = [...$fields, 'other'][$random->getInt(0, count($fields))];
            $rows['key'.$j] = match ($random->getInt(0, 9)) {
                0 => [$field => $entry],
                1 => [$field => $entry, $fields[0] => 'ignored'],
                2 => [$fields[0] => null, $fields[count($fields) - 1] => $entry],
                3 => [null, true, false, 1.5, -7, PHP_INT_MAX, [], new \stdClass][$random->getInt(0, 7)],
                4 => [$field => [$entry]],
                default => $entry,
            };
        }

        return $rows;
    }

    public function test_raw_native_and_real_dispatch_randomized_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $i => [$class, $method, $symbol, $fields]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            $cases = [[], self::literalCases()[$i][0]];
            for ($trial = 0; $trial < 600; $trial++) {
                $cases[] = self::randomBatch($random, $fields);
            }
            foreach ($cases as $input) {
                $expected = $class::{$method.'Php'}($input);
                self::assertSame($expected, self::rawResult($ffi, $symbol, $fields, $input, $keys));
                self::assertSame($expected, $class::{$method.'Native'}($input));
                $class::useW431RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    /** Slides every pattern across prefixes and suffixes so boundary and anchor handling match PHP. */
    public function test_patterns_at_every_offset_match_php(): void
    {
        $patterns = [
            'yEnc', "yEnc\n", 'x yenc', "\xffyEnc", '1 MB', '1 mb', '1  MB', '.ab"', '.abcde"', '[1/2]', '(1/2]', '(1/2', '.flac', '.Flac', '#', "\xc2\xa9", "\xc2\xaa", "\xc3\x96",
            '"a"', '""', '36', "36\n", '2', "2\n", 'MPEG', 'HEVC', 'avc', 'div3', 'xvid', 'wvc1', 'http://a@b/', 'https://a/b@c', 'x.jpg', 'x.jpg?', 'x.jpgx', 'x.par2',
            'x.vol1+2.par2', 'x.r00', 'x.r00x', 'x.001', 'x.rar"', "x.zip\t", 'x.7z ', 'abc_-1', '-abc',
        ];
        foreach (self::HELPERS as $i => [$class, $method, $symbol, $fields]) {
            $ffi = self::ffi($class);
            $keys = array_keys(self::literalCases()[$i][1]);
            foreach (['a', '.', ' ', '"', "\n"] as $filler) {
                for ($pad = 0; $pad <= 6; $pad++) {
                    $batch = [];
                    foreach ($patterns as $pattern) {
                        $batch[] = $pattern;
                        $batch[] = 'q'.str_repeat($filler, $pad).$pattern;
                        $batch[] = $pattern.str_repeat($filler, $pad).'q';
                        $batch[] = $pattern.str_repeat($filler, $pad);
                    }
                    self::assertSame($class::{$method.'Php'}($batch), self::rawResult($ffi, $symbol, $fields, $batch, $keys), $class.' pad '.$pad.' filler '.bin2hex($filler));
                }
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::resetW431RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW431RustFfi());
            self::assertSame(self::DEFAULTS[$i], self::defaultEnabled($class));
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 256);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            if (self::defaultEnabled($class)) {
                $wire = pack('q*', ...array_values($expected));
                self::assertSame($wire, FFI::string($out, strlen($wire)));
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w431Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w431Ffi'))->getValue());
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
        }
    }

    /**
     * @param  list<int>  $samples
     * @return array{mean_ns: float, p99_ns: int, p99_approx_95pct_rank_interval_ns: array{int, int}}
     */
    private static function statistics(array $samples): array
    {
        sort($samples, SORT_NUMERIC);
        $n = count($samples);
        $radius = 1.96 * sqrt($n * 0.99 * 0.01);

        return [
            'mean_ns' => array_sum($samples) / $n,
            'p99_ns' => $samples[(int) ceil(0.99 * $n) - 1],
            'p99_approx_95pct_rank_interval_ns' => [$samples[max(0, (int) floor(0.99 * $n - $radius) - 1)], $samples[min($n - 1, (int) ceil(0.99 * $n + $radius) - 1)]],
        ];
    }

    /** @return array<string, mixed> */
    private static function benchmarkMetadata(): array
    {
        $root = dirname(__DIR__, 2);
        $hashes = [];
        foreach (self::HELPERS as [$class]) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertNotFalse($path);
            $hashes[$path] = hash_file('sha256', $path);
        }
        foreach ([__FILE__, $root.'/rust/pipeline-ffi/src/cleaning.rs', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/composer.lock'] as $path) {
            $hashes[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $native = [];
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix, '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix] as $path) {
            if (is_file($path)) {
                $native = ['path' => realpath($path), 'sha256' => hash_file('sha256', $path), 'profile' => 'release'];
                break;
            }
        }
        $git = 'git -C '.escapeshellarg($root).' ';

        return [
            'utc' => gmdate(DATE_ATOM),
            'head' => trim((string) shell_exec($git.'rev-parse HEAD 2>&1')),
            'base_origin_master' => trim((string) shell_exec($git.'rev-parse origin/master 2>&1')),
            'rustc' => trim((string) shell_exec('rustc --version 2>&1')),
            'native_library' => $native,
            'php' => PHP_VERSION,
            'platform' => php_uname(),
            'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl machdep.cpu.brand_string hw.memsize hw.logicalcpu 2>&1' : 'lscpu 2>&1')),
            'ffi_enable' => ini_get('ffi.enable'),
            'opcache_enable_cli' => ini_get('opcache.enable_cli'),
            'jit' => ini_get('opcache.jit'),
            'seed' => self::SEED,
            'warmups_per_mode' => 64,
            'paired_samples_per_workload' => 3000,
            'order' => 'fixed-seed randomized paired; timings include PHP entry extraction, framing, FFI call and decoding',
            'p99' => 'nearest rank; approximate 95 percent binomial rank interval',
            'required_speedup' => 1.5,
            'source_sha256' => $hashes,
            'reproduction_command' => 'NNTMUX_W431_BENCH=1 php vendor/bin/phpunit tests/Unit/RustReleaseCreationExtraImageProcessingCleaningW43ParityTest.php',
        ];
    }

    /**
     * Common batches hold 24 typical rows from the literal case; uncommon batches hold 1024 rows mixing array
     * rows, missing fields, invalid bytes and long entries.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $typical = [
            ['[01/15] - "Some.Movie.2021.1080p.BluRay.x264-GRP.part01.rar" - 4.37 GB yEnc', 'Some.Show.S01E02.720p.WEB-DL "s01e02.mkv" (1/40)', 'poster@example.com (Poster)'],
            ['V_MPEG4/ISO/AVC', 'HEVC', 'XVID', 'DIV3', 'WVC1', 'MPEG', '27', 'VP9'],
            ['https://images.example.com/covers/a1b2c3.jpg?size=large', 'cover-0123456789abcdef', 'http://user:pw@cdn.example.net/x/poster.png', 'not a basename.webp'],
            ['"Some.Movie.2021.1080p.BluRay.x264-GRP.part01.rar" yEnc (1/89)', 'Some.Movie.2021.vol015+16.par2', 'Some.Movie.2021.r07', 'Some.Movie.2021.nfo'],
            ['[01/15] - "Artist-Album-2021-GRP.flac" - 365.15 KB - yEnc', 'alt.binaries.sounds.flac', '(Some.Show.S01E02) [12/40] - "s01e02.mkv" yEnc', 'Plain subject 12.5 MB'],
        ][$index];
        $source = $typical;
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long.Entry.", 32);
            $source = [...$typical, ...self::literalCases()[$index][0], $long.'"x.vol07+08.PAR2" 12 MB [3/9] yEnc', [self::HELPERS[$index][3][0] => $long.'https://h/a.jpg?x HEVC'], null, 7];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : 24; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W431_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W431_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W431_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w431-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave43.1 benchmark artifact: ".$artifact."\n");
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::HELPERS as $index => [$class, $method, $symbol, $fields]) {
                $ffi = self::ffi($class);
                $keys = array_keys(self::literalCases()[$index][1]);
                foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                    $args = self::benchmarkInput($index, $uncommon);
                    $pairs = [];
                    $expected = $class::{$method.'Php'}($args);
                    self::assertSame($expected, self::rawResult($ffi, $symbol, $fields, $args, $keys));
                    self::assertNativeDispatch($class, $method, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW431RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW431RustFfi($native);
                            $start = hrtime(true);
                            $actual = $class::{$method}($args);
                            $pair[$native ? 'rust_ns' : 'php_ns'] = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                        }
                        $pairs[] = $pair;
                    }
                    $php = self::statistics(array_column($pairs, 'php_ns'));
                    $rust = self::statistics(array_column($pairs, 'rust_ns'));
                    $report['raw_pairs'][$method][$workload] = $pairs;
                    $report['summary'][$method][$workload] = [
                        'rows' => count($args),
                        'input_sha256' => hash('sha256', self::wire(self::entries($fields, $args))),
                        'php' => $php,
                        'rust' => $rust,
                        'mean_speedup' => $php['mean_ns'] / max(1, $rust['mean_ns']),
                        'p99_speedup' => $php['p99_ns'] / max(1, $rust['p99_ns']),
                        'conservative_p99_speedup' => $php['p99_approx_95pct_rank_interval_ns'][0] / max(1, $rust['p99_approx_95pct_rank_interval_ns'][1]),
                    ];
                }
                $ratios = [];
                foreach (['common', 'uncommon'] as $workload) {
                    $ratios[$workload.'_mean_speedup'] = $report['summary'][$method][$workload]['mean_speedup'];
                    $ratios[$workload.'_p99_speedup'] = $report['summary'][$method][$workload]['p99_speedup'];
                }
                $report['helper_qualification'][$method] = ['ratios' => $ratios, 'qualified' => min($ratios) >= 1.5];
                fwrite(STDERR, sprintf("%s: %s\n", $method, json_encode($ratios, JSON_THROW_ON_ERROR)));
            }
            $report['gate_passed'] = ! in_array(false, array_column($report['helper_qualification'], 'qualified'), true);
            $report['status'] = 'completed';
            foreach (self::HELPERS as [$class, $method]) {
                if (self::defaultEnabled($class)) {
                    self::assertTrue($report['helper_qualification'][$method]['qualified'], $method.' enabled without qualifying every mean/p99 gate.');
                }
            }
        } catch (\Throwable $failure) {
            $report['status'] = 'invalid';
            $report['failure'] = ['type' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
