<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnidbService;
use App\Services\Predb\Feeds\Sources\PredbClubSource;
use App\Services\Predb\Feeds\Sources\PredbNetSource;
use App\Services\Predb\Feeds\Sources\PredbOrgSource;
use App\Services\Predb\Feeds\Sources\PredbOvhSource;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 45.4 pure CPU predb.club/.net/.org/.ovh JSON feed release title and
 * AniDB raw title batch summaries. The opt-in benchmark retains raw randomized
 * pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W454_BENCH=1 php vendor/bin/phpunit tests/Unit/RustPredbFeedsClubNetOrgOvhAnidbW45ParityTest.php
 *
 * Seven runs at 4d202c888 + this change (Apple M5, PHP 8.5.12, rustc 1.99.1,
 * release build, 3000 pairs per workload), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99):
 *   predb.club 2.21/1.76, 2.49/2.09; predb.net 2.52/1.74, 2.46/1.98;
 *   predb.org 2.25/1.71, 2.48/1.94; predb.ovh 2.45/1.75, 2.47/2.01;
 *   AniDB 2.69/1.69, 3.53/2.82.
 * Common batches are one 100-row feed page (24 AniDB titles); uncommon
 * batches hold 1024 mixed rows. Every helper clears every gate and defaults
 * to Rust; PHP remains the fallback when the library is unavailable.
 */
final class RustPredbFeedsClubNetOrgOvhAnidbW45ParityTest extends TestCase
{
    private const array HELPERS = [
        [PredbClubSource::class, 'summarizePredbClubTitleBatch', 'nntmux_w454_predb_feed_title_batch', 'name'],
        [PredbNetSource::class, 'summarizePredbNetTitleBatch', 'nntmux_w454_predb_feed_title_batch', 'release'],
        [PredbOrgSource::class, 'summarizePredbOrgTitleBatch', 'nntmux_w454_predb_feed_title_batch', 'rlsname'],
        [PredbOvhSource::class, 'summarizePredbOvhTitleBatch', 'nntmux_w454_predb_feed_title_batch', 'name'],
        [AnidbService::class, 'summarizeAnidbTitleBatch', 'nntmux_w454_anidb_title_batch', 'title'],
    ];

    private const array DEFAULTS = [true, true, true, true, true];

    /** Earlier public APIs that must stay intact. */
    private const array LEGACY_METHODS = [
        PredbClubSource::class => ['key', 'fetch', 'parseRows'],
        PredbNetSource::class => ['key', 'fetch', 'parseRows'],
        PredbOrgSource::class => ['key', 'fetch', 'parseRows'],
        PredbOvhSource::class => ['key', 'fetch', 'parseRows'],
        AnidbService::class => ['cleanTitle', 'cleanTitlePhp', 'extractBaseTitlePhp', 'planAnidbRateLimitWindow', 'useW394RustFfi', 'setUseRustFfi'],
    ];

    private const array FIELDS = ['name', 'release', 'rlsname', 'title', 'searchname', 'team'];

    private const int SEED = 4542026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW454RustFfi();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW454RustFfi', 'usesW454RustFfi', 'resetW454RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W454_RUST_FFI_DEFAULT'));
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
        // Earlier constants keep their values; read at run time so PHPStan does not fold them.
        self::assertSame(100, (new ReflectionClass(PredbNetSource::class))->getConstant('MAX_PAGE'));
        self::assertFalse((new ReflectionClass(AnidbService::class))->getConstant('W394_RUST_FFI_DEFAULT'));
    }

    private const array PREDB_EXPECTED = ['total_titles' => 20, 'empty_title_count' => 4, 'padded_title_count' => 1, 'group_suffix_count' => 7, 'dotted_count' => 8, 'episode_count' => 1, 'resolution_count' => 3, 'scene_tag_count' => 3, 'non_ascii_count' => 1, 'control_char_count' => 2, 'overlong_count' => 1];

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        $predb = static fn (string $field): array => [
            'Show.Name.S01E02.1080p.WEB.H264-GRP', '  Movie.2024.PROPER.720p.BluRay.x264-GROUP ', 'Artist-Album-WEB-2024-iNT', 'Some Release Name', 'Show.S1234E01-GRP', 'Show.S01E12345.2160i', 'Rel.REAL.RERIP-x',
            "Caf\xc3\xa9.2024-GRP", "Tab\x01Inside", str_repeat('A', 256), '', " \t\n", 'Title-', 'Title.Internal.READNFO', 'No-Group!', "Form\f.Feed", [$field => 'Row.Title-GRP', 'title' => ''], [$field => null, 'title' => 'x'], null, 2024,
        ];

        return [
            [$predb('name'), self::PREDB_EXPECTED],
            [$predb('release'), self::PREDB_EXPECTED],
            [$predb('rlsname'), self::PREDB_EXPECTED],
            [$predb('name'), self::PREDB_EXPECTED],
            [[
                '[SubsPlease] Frieren - 12 (1080p) [ENG].mkv', 'Show S01E02 BDRip', 'Show_-_007', 'Show 12345', 'Show Ep 007', 'Show Episode0012', 'Show E00000', 'Show Vol. 3', 'Show Ch', 'Show Ch.12x', 'JAV 240101 Uncensored',
                'Mosaic-less HD', 'Blu-Ray Box', 'Blu-Rays', '[] () Title', 'Show (TV)', "\xc3\x83\xc2\xa9 Title", 'Show FINAL Complete', '', ['title' => 'Kimi no Na wa Movie [BD]'], ['name' => 'Show 4K x265'], ['searchname' => 'Show 1x05'], null, 12,
            ], ['total_titles' => 24, 'empty_title_count' => 2, 'bracket_tag_count' => 2, 'paren_tag_count' => 2, 'language_tag_count' => 1, 'adult_tag_count' => 2, 'date_code_count' => 1, 'quality_tag_count' => 5, 'release_tag_count' => 1, 'sxxexx_count' => 1, 'trailing_number_count' => 3, 'episode_suffix_count' => 3, 'volume_suffix_count' => 2, 'mojibake_count' => 1]],
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
                $class::useW454RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
                $single = ['x'];
                self::assertSame($class::{$method.'Php'}($single), $class::{$method}($single));
            }
        }
    }

    /** Non-empty title counts match the rows the real parseRows() caller keeps. */
    public function test_predb_summaries_agree_with_parse_rows(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (array_slice(self::HELPERS, 0, 4) as [$class, $method, , $field]) {
            $source = new $class('https://feed.invalid/api');
            for ($trial = 0; $trial < 100; $trial++) {
                $rows = [];
                for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
                    $rows[] = $random->getInt(0, 4) === 0 ? ['team' => 'GRP'] : [$field => self::randomEntry($random), 'size' => '1.5', 'files' => 3];
                }
                $summary = $class::{$method.'Native'}($rows);
                self::assertSame(count($source->parseRows($rows)), $summary['total_titles'] - $summary['empty_title_count'], $class);
                $titles = array_map(static fn ($entry): string => $entry->title, $source->parseRows($rows));
                self::assertSame(count(array_filter($titles, static fn (string $title): bool => strlen($title) > 255)), $summary['overlong_count']);
            }
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW454RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW454RustFfi());
            $class::useW454RustFfi(false);
        }
        $legacy = [(new ReflectionProperty(AnidbService::class, 'useRustFfi'))->getValue(), (new ReflectionProperty(AnidbService::class, 'w394UseRustFfi'))->getValue()];
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW454RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW454RustFfi());
            }
            $class::useW454RustFfi(null);
            self::assertSame(self::DEFAULTS[$index], $class::usesW454RustFfi(), 'useW454RustFfi(null) restores the default.');
            $class::useW454RustFfi(true);
            $class::resetW454RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW454RustFfi());
            $class::useW454RustFfi(false);
            self::assertFalse($class::usesW454RustFfi());
        }
        self::assertSame($legacy, [(new ReflectionProperty(AnidbService::class, 'useRustFfi'))->getValue(), (new ReflectionProperty(AnidbService::class, 'w394UseRustFfi'))->getValue()], 'W454 switches must not touch earlier toggles.');
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW454RustFfi(true);
            (new ReflectionProperty($class, 'w454Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w454Ffi'))->getValue());
        }
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new ReflectionClass($class))->getConstant('W454_RUST_FFI_DEFAULT') === true;
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    private static function ffi(string $class): FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W454_FFI_CDEF');
        self::assertIsString($cdef);
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w454', $cdef);
        self::assertNotNull($ffi, 'Build the release pipeline library with both W454 symbols.');

        return $ffi;
    }

    /** @return list<string> */
    private static function entries(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w454Entries'))->invoke(null, $input);
    }

    /** @param array<array-key, mixed> $input
     * @return array<string, int>
     */
    private static function rawResult(string $class, FFI $ffi, string $symbol, array $input): array
    {
        $wire = serialize([self::entries($class, $input)]);
        $out = $ffi->new('char[2048]');
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, 2048);
        self::assertGreaterThan(0, $written, 'Native export must execute, not silently fall back.');
        self::assertLessThanOrEqual(2048, $written);
        $result = unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
        self::assertIsArray($result);

        return $result;
    }

    /** @param array<array-key, mixed> $input
     * @param  array<string, int>  $expected
     */
    private static function assertNativeDispatch(string $class, string $method, FFI $ffi, array $input, array $expected): void
    {
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 2048);
        $class::useW454RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = serialize($expected);
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w454Ffi'))->getValue());
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $malformed = ['', 'N;', 'a:1:{i:0;', 'a:0:{}', 'a:1:{i:1;a:0:{}}', 'a:2:{i:0;a:0:{}i:1;a:0:{}}', 'a:1:{i:0;N;}', 'a:1:{i:0;a:1:{i:2;s:0:"";}}', 'a:1:{i:0;a:1:{s:1:"x";s:0:"";}}', 'a:1:{i:0;a:1:{i:0;N;}}', 'a:1:{i:0;a:1:{i:0;i:5;}}', 'a:1:{i:0;a:1:{i:0;a:0:{}}}', 'a:1:{i:0;a:1:{i:0;s:5:"x";}}', 'a:1:{i:0;a:0:{}}trailing', serialize(['x']), serialize([['x'], ['y']])];
        foreach (self::HELPERS as [$class, , $symbol]) {
            $ffi = self::ffi($class);
            $out = $ffi->new('char[2048]');
            foreach ($malformed as $wire) {
                FFI::memset($out, 90, 2048);
                self::assertSame(-1, self::export($ffi, $symbol, $wire, $out, 2048), $wire);
                self::assertSame(str_repeat('Z', 2048), FFI::string($out, 2048));
            }
            $wire = serialize([['entry']]);
            $required = self::export($ffi, $symbol, $wire, $out, 1);
            self::assertLessThan(-1, $required, 'Insufficient capacity reports the required size.');
            self::assertSame(str_repeat('Z', 2048), FFI::string($out, 2048), 'Insufficient capacity must not write.');
            self::assertSame(-$required, self::export($ffi, $symbol, $wire, $out, -$required));
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
        foreach (self::HELPERS as [$class, $method]) {
            $input = [$object, array_fill_keys(self::FIELDS, $object), ['name' => 'Ok.S01E01.1080p-GRP', 'release' => 'Ok.S01E01.1080p-GRP', 'rlsname' => 'Ok.S01E01.1080p-GRP', 'title' => $object]];
            $class::useW454RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW454RustFfi();
            $class::useW454RustFfi(true);
            $oversized = [$large, array_fill_keys(self::FIELDS, $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w454Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '.', '-', '_', ' - ', '[', ']', '(', ')', '[]', '()', '/', '+', ':', '!', "\0", "\t", "\v", "\f", "\n", "\r", "\x01", "\x1f", "\x7f", "\xa0", "\x85", "\xff", "\x80", "\xc3\xa9", '日本語',
        "\xc3\x83", "\xc3\x82", "\xc3", "\xef\xbb\xbf", "\xf0\x9f\x98\x80",
        // predb feed titles.
        'Show', 'Name', 'GRP', '-GRP', 'iNT', 'x264', 'H264', 'WEB', 'S01E02', 's1e1', 'S123E1234', 'S1234E01', 'S01E12345', 'S', 'E', '1080p', '720P', '2160i', '576i', '480p', '1080', 'p', 'i',
        'PROPER', 'Repack', 'INTERNAL', 'iNTERNAL', 'READNFO', 'DIRFIX', 'NFOFIX', 'SUBFIX', 'RERIP', 'REAL', 'REALLY',
        // AniDB titles.
        '[SubsPlease]', '(1080p)', '[ENG]', 'ENG', 'eng', 'JAP', 'JPN', 'MULTI', 'HARDSUB', 'KOR', 'JAV', 'Uncensored', 'censored', 'Mosaic', 'Mosaic-less', 'HD', 'SD', 'FHD', 'UHD', '240101', '123456', '1234567',
        'BD', 'BDRip', 'BluRay', 'Blu-Ray', 'blu-ray', 'Blu', 'Ray', '4K', '4k', 'x265', 'HEVC', 'WEBRip', 'DVDRip', 'TVRip', 'RIP', 'ISO', 'CRACK', 'BETA', 'ALPHA', 'FINAL', 'Complete', 'FULL',
        'Ep', 'ep', 'Episode', 'EPISODE', 'pisode', 'Vol', 'vol.', 'Vol.', 'Ch', 'ch.', 'x', '1x05', '0', '00', '000', '0000', '00000', '1', '7', '12', '123', '1234', '12345', 'Frieren', 'Movie',
    ];

    private static function randomEntry(Randomizer $random): string
    {
        $entry = '';
        for ($k = 0, $n = $random->getInt(0, 12); $k < $n; $k++) {
            $entry .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $entry .= ['.', '-', ' ', '_', ' - ', '/', '+', "\n", "\xff", "\0", '', '9', 'a', '['][$random->getInt(0, 13)];
            }
        }
        if ($random->getInt(0, 40) === 0) {
            $entry .= str_repeat('x', $random->getInt(240, 270));
        }

        return $entry;
    }

    /** @return array<array-key, mixed> */
    private static function randomBatch(Randomizer $random): array
    {
        $rows = [];
        for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
            $entry = self::randomEntry($random);
            $field = self::FIELDS[$random->getInt(0, count(self::FIELDS) - 1)];
            $rows['key'.$j] = match ($random->getInt(0, 9)) {
                0 => [$field => $entry],
                1 => [$field => $entry, 'team' => 'ignored'],
                2 => [$field => null, 'name' => $entry, 'release' => $entry, 'rlsname' => $entry, 'title' => $entry, 'searchname' => $entry],
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
        foreach (self::HELPERS as $i => [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            $cases = [[], self::literalCases()[$i][0]];
            for ($trial = 0; $trial < 500; $trial++) {
                $cases[] = self::randomBatch($random);
            }
            foreach ($cases as $input) {
                $expected = $class::{$method.'Php'}($input);
                self::assertSame($expected, self::rawResult($class, $ffi, $symbol, $input));
                self::assertSame($expected, $class::{$method.'Native'}($input));
                $class::useW454RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    /** Word-boundary, separator, bracket and suffix rules see word, non-word and digit neighbours on both sides. */
    public function test_patterns_with_every_neighbour_match_php(): void
    {
        $patterns = [
            '-GRP', 'S01E02', 'S1E1234', 'S123E1', '1080p', '2160I', 'PROPER', 'iNTERNAL', 'REAL', '.x.', "\xc3\xa9", "\x01", "\x7f",
            '[Grp]', '(TV)', '[]', '()', 'ENG', 'JAV', 'Mosaic-less', '240101', '1080p', 'Blu-Ray', 'BDRip', 'FINAL', ' S01E02', ' Ep 007', ' Episode0012', ' E00000', ' Vol. 3', ' Ch', '_-_007', ' 12', "\xc3\x83",
        ];
        foreach (self::HELPERS as [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            foreach (['a', '_', '.', '1', '0', ' ', '-', "\f", "\v", 'S', 'e', '[', ']', '(', ')', "\xc3"] as $filler) {
                for ($pad = 0; $pad <= 3; $pad++) {
                    $batch = [];
                    foreach ($patterns as $pattern) {
                        $around = str_repeat($filler, $pad);
                        array_push($batch, $around.$pattern, $pattern.$around, $around.$pattern.$around, $pattern);
                    }
                    self::assertSame($class::{$method.'Php'}($batch), self::rawResult($class, $ffi, $symbol, $batch), $class.' pad '.$pad.' filler '.bin2hex($filler));
                }
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::resetW454RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW454RustFfi());
            self::assertSame(self::DEFAULTS[$i], self::defaultEnabled($class));
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 2048);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            if (self::defaultEnabled($class)) {
                $wire = serialize($expected);
                self::assertSame($wire, FFI::string($out, strlen($wire)));
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w454Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w454Ffi'))->getValue());
                self::assertSame(str_repeat('Z', 2048), FFI::string($out, 2048));
            }
        }
    }

    /** @param list<int> $samples
     * @return array{mean_ns: float, p99_ns: int, p99_approx_95pct_rank_interval_ns: array{int,int}}
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
            $path = (new ReflectionClass($class))->getFileName();
            self::assertNotFalse($path);
            $hashes[$path] = hash_file('sha256', $path);
        }
        foreach ([__FILE__, $root.'/rust/pipeline-ffi/src/metadata.rs', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/composer.lock'] as $path) {
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
            'order' => 'fixed-seed randomized paired; timings include PHP packing, FFI call and decoding',
            'p99' => 'nearest rank; approximate 95 percent binomial rank interval',
            'required_speedup' => 1.5,
            'source_sha256' => $hashes,
            'reproduction_command' => 'NNTMUX_W454_BENCH=1 php vendor/bin/phpunit tests/Unit/RustPredbFeedsClubNetOrgOvhAnidbW45ParityTest.php',
        ];
    }

    /** @return list<mixed> Typical production rows for each helper, shaped like the feed's JSON rows. */
    private static function commonSource(int $index): array
    {
        $titles = ['Show.Name.S03E07.1080p.WEB.H264-GRP', 'Movie.Title.2024.PROPER.2160p.UHD.BluRay.x265-TERMiNAL', 'Artist-Album_Title-WEB-2024-iNT', 'Some.Documentary.2023.720p.HDTV.x264-DHD', 'Game.Title-RUNE', 'Various_Artists-Club_Hits-(CAT123)-WEB-2024-ZzZz', 'Show.S01E02.READNFO.1080p.WEB.h264-NTb'];
        if ($index === 4) {
            return ['[SubsPlease] Sousou no Frieren - 12 (1080p) [ENG].mkv', ['title' => 'Shingeki no Kyojin'], 'One Piece - 1100', ['name' => 'Spy x Family Ep 07'], 'Kimi no Na wa Movie [BD]', 'Mob Psycho 100 S02E05 HEVC', "Sh\xc3\x83\xc2\xa9ngeki Vol. 3"];
        }
        $field = self::HELPERS[$index][3];

        return array_map(static fn (string $title): array => [$field => $title, 'section' => 'TV-HD-X264', 'size' => '1234.5', 'files' => 42, 'preAt' => 1729850000, 'nuke' => null], $titles);
    }

    /** Common batches are one 100-row feed page (24 AniDB titles); uncommon batches contain 1024 rows
     * mixing array rows, missing fields, invalid bytes and long entries.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $source = self::commonSource($index);
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long_Entry-", 32);
            $source = [...$source, ...self::literalCases()[$index][0], $long.' - 12 [1080p] S01E02 PROPER-GRP', array_fill_keys(self::FIELDS, $long.' (TV) Vol. 3')];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : ($index === 4 ? 24 : 100); $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W454_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W454_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W454_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w454-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave45.4 benchmark artifact: ".$artifact."\n");
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::HELPERS as $index => [$class, $method, $symbol]) {
                $ffi = self::ffi($class);
                foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                    $args = self::benchmarkInput($index, $uncommon);
                    $pairs = [];
                    $expected = $class::{$method.'Php'}($args);
                    self::assertSame($expected, self::rawResult($class, $ffi, $symbol, $args));
                    self::assertNativeDispatch($class, $method, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::useW454RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW454RustFfi($native);
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
                        'input_sha256' => hash('sha256', serialize(self::entries($class, $args))),
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
            file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
