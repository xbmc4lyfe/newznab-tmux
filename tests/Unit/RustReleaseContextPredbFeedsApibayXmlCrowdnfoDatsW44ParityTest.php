<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\ReleaseContext;
use App\Services\Predb\Feeds\Sources\ApibaySource;
use App\Services\Predb\Feeds\Sources\CompleteXmlCatalogueSource;
use App\Services\Predb\Feeds\Sources\CrowdnfoSource;
use App\Services\Predb\Feeds\Sources\DatsPcIsoSource;
use App\Services\Predb\Feeds\Sources\NswdbSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44ParityTest extends TestCase
{
    /** Every Wave 44.3 target shares the W443 toggle names and keeps its existing native paths separate. */
    private const TARGETS = [
        ReleaseContext::class,
        ApibaySource::class,
        CompleteXmlCatalogueSource::class,
        CrowdnfoSource::class,
        DatsPcIsoSource::class,
    ];

    /** Production defaults: each helper defaults to Rust only when it clears 1.5x on every measure below. */
    private const DEFAULTS = [
        ReleaseContext::class => true,
        ApibaySource::class => true,
        CompleteXmlCatalogueSource::class => false,
        CrowdnfoSource::class => false,
        DatsPcIsoSource::class => true,
    ];

    /** Helper per target: [class, method, export]. */
    private const HELPERS = [
        'context' => [ReleaseContext::class, 'summarizeContextMarkerBatch', 'nntmux_w443_release_context_marker_batch'],
        'apibay' => [ApibaySource::class, 'summarizeApibayTitleBatch', 'nntmux_w443_apibay_title_batch'],
        'catalogue' => [CompleteXmlCatalogueSource::class, 'summarizeCatalogueTitleBatch', 'nntmux_w443_complete_xml_catalogue_title_batch'],
        'crowdnfo' => [CrowdnfoSource::class, 'summarizeCrowdnfoHeadingBatch', 'nntmux_w443_crowdnfo_heading_batch'],
        'dats' => [DatsPcIsoSource::class, 'summarizeRomNameBatch', 'nntmux_w443_dats_pciso_rom_name_batch'],
    ];

    /** Strings that exercise case, markers, trim sets, separators, scene shapes, NUL bytes and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\x1F", "\x7F", "\t\n", "\x0B", "\x0C", "\r", '-', '.', '_', ' . ', '...', '…', 'a', 'x-', 'a.b-c', 'a.b-', '.a.b-c', 'a..b-c', 'a.b-c.d', 'a-b.c-d',
        'Brazzers.24.01.01.Model.XXX.1080p', 'Show.Complete.Season.1.PROPER', 'Show.S01E01.1080p.AMZN.WEB-DL', 'Movie.2024.GERMAN.1080p.BluRay', 'Movie.2024.MULTi.REPACK',
        'OnlyFans.Model.Season.Pack', 'Plain.Name.RERIP', 'xx', 'web-d', 'mult', 'boxse', 'Movie (2024) [1080p] [WEBRip] [YTS.MX]', 'Movie.2024.1080p.WEBRip.x264-RARBG',
        'Show.S01E01.720p.HDTV.x264-EZTV', 'Movie.2024.HDCAM.x264-TGx', 'Movie.2024.HDTS.x264-GalaxyRG', 'Movie.2024.TELESYNC-GRP', 'YIFY', 'tg', 'eztv.re',
        'Game.Title.USA.NSW-GRP', "  Game.Title.EUR-GRP\n", 'Game Title', "Game\x7FTitle", " \t\r\0\x0B", 'Movie.Title.20...-GRP', 'Movie.Title…-GRP', 'Movie-GRP',
        'Movie..Title-GRP', 'Movie.Title-', 'Movie.Title-GR.P', '.Movie.Title-GRP', 'Movie.Title-GRP_2', 'Game.Title.1998.PC.ISO-GRP', 'game-grp.ZIP', 'disk1.rar', 'x.7Z',
        "Game\x01Title", 'Game/Title.7z', 'Game\\Title', '.zi', 'Ünïcödé.FLAC-GRP', "bad \xff utf8", "\xc3", "\xc3\xa9.x-G", "A.B-G\xc3\xa9", "na\xffme.x-GRP", "\xe2\x80",
    ];

    /** Fragments for random names: markers, separators and noise. */
    private const WORDS = ['Show', 'Movie', 'Game', 'xxx', 'XXX', 'onlyfans', 'porn', 'brazzers', 'milf', 'complete', 'Season', 'collection', 'boxset', 'web-dl', 'WEBRip',
        'web.dl', 'AMZN', 'german', 'FRENCH', 'spanish', 'italian', 'nordic', 'MULTi', 'proper', 'REPACK', 'rerip', 'yts', 'YIFY', 'rarbg', 'eztv', 'TGx', 'torrentgalaxy',
        'GalaxyRG', 'HDCAM', 'camrip', 'telesync', 'hdts', '.zip', '.RAR', '.7z', '...', '…', '-GRP', '-grp_2', '.S01E01', '.1080p', '/', '\\', ' '];

    private const UNCOMMON_LENGTHS = [120, 180, 240, 255, 256, 320, 400];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW443RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW443RustFfi', 'usesW443RustFfi', 'resetW443RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            $this->assertFalse($reflection->isReadOnly(), $class);
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W443_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW443RustFfi(), $class);
            $class::useW443RustFfi();
            $this->assertTrue($class::usesW443RustFfi(), $class);
            $class::useW443RustFfi(false);
            $this->assertFalse($class::usesW443RustFfi(), $class);
            $class::useW443RustFfi(null);
            $this->assertSame($default, $class::usesW443RustFfi(), $class);
            $class::useW443RustFfi(true);
            $this->assertTrue($class::usesW443RustFfi(), $class);
            $class::resetW443RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w443RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w443RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW443RustFfi(), $class);
        }

        // The W443 toggles never touch the classes' existing parsing, marker or feed toggles.
        $context = new ReleaseContext('Brazzers.24.01.01.Model.Name.XXX.1080p.MP4-GRP', 1);
        $rows = [['name' => ' Movie.2024.1080p.WEB-DL-GRP ', 'category' => 207, 'size' => 1048576, 'num_files' => 3, 'added' => 1700000000], ['name' => 'Skip', 'category' => 500]];
        foreach ([true, false] as $enable) {
            foreach (self::TARGETS as $class) {
                $class::useW443RustFfi($enable);
            }
            $this->assertSame($enable, NswdbSource::usesW443RustFfi());
            $this->assertFalse(DatsPcIsoSource::usesRustFfi());
            $this->assertFalse(CrowdnfoSource::usesRustFfi());
            $this->assertTrue($context->hasAdultMarkers());
            $this->assertFalse($context->hasStandaloneSeasonToken());
            $entries = (new ApibaySource('https://apibay.invalid/precompiled/data_top100_recent.json'))->parseRows($rows);
            $this->assertCount(1, $entries);
            $this->assertSame('Movie.2024.1080p.WEB-DL-GRP', $entries[0]->title);
            $this->assertSame('MOVIE-HD', $entries[0]->category);
            $this->assertSame('apibay', (new ApibaySource('https://apibay.invalid/'))->key());
            $this->assertSame('crowdnfo', (new CrowdnfoSource('https://crowdnfo.invalid/'))->key());
            $this->assertSame('dats_pciso_1998', (new DatsPcIsoSource('https://dats.invalid/'))->key());
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 10, 'adult' => 2, 'pack' => 1, 'web' => 1, 'foreign' => 2, 'unknown' => 4, 'proper' => 3],
            ReleaseContext::summarizeContextMarkerBatch(['Brazzers.24.01.01.Model.XXX.1080p', 'Show.Complete.Season.1.PROPER', 'Show.S01E01.1080p.AMZN.WEB-DL',
                'Movie.2024.GERMAN.1080p.BluRay', 'Movie.2024.MULTi.REPACK', 'OnlyFans.Model.Season.Pack', 'Plain.Name.RERIP', 'Plain.Name', '', null]),
        );

        $this->assertSame(
            ['total' => 10, 'empty' => 3, 'yts' => 1, 'rarbg' => 1, 'eztv' => 1, 'galaxy' => 2, 'other' => 2, 'cam' => 3],
            ApibaySource::summarizeApibayTitleBatch(['Movie (2024) [1080p] [WEBRip] [YTS.MX]', 'Movie.2024.1080p.WEBRip.x264-RARBG', 'Show.S01E01.720p.HDTV.x264-EZTV',
                'Movie.2024.HDCAM.x264-TGx', 'Movie.2024.HDTS.x264-GalaxyRG', 'Movie.2024.TELESYNC-GRP', 'Plain.Name', "  \t\0", '', 42]),
        );

        $this->assertSame(
            ['total' => 10, 'accepted' => 4, 'missing' => 3, 'nonliteral' => 2, 'overlength' => 1, 'normalized' => 1],
            CompleteXmlCatalogueSource::summarizeCatalogueTitleBatch(['Game.Title.USA.NSW-GRP', "  Game.Title.EUR-GRP\n", 'Game Title', "Game\x7FTitle", '',
                " \t\r\0\x0B", str_repeat('a', 256), str_repeat('a', 255), "\xc3\xa9t\xc3\xa9", false]),
        );

        $this->assertSame(
            ['total' => 12, 'empty' => 2, 'nonliteral' => 1, 'truncated' => 2, 'scene' => 2, 'other' => 5],
            CrowdnfoSource::summarizeCrowdnfoHeadingBatch(['Movie.Title.2024.1080p.WEB.H264-GRP', ' Show.S01E01.720p-GRP_2 ', 'Movie Title-GRP', 'Movie.Title.20...-GRP',
                'Movie.Title…-GRP', 'Movie-GRP', 'Movie..Title-GRP', 'Movie.Title-', 'Movie.Title-GR.P', '.Movie.Title-GRP', '', ['x']]),
        );

        $this->assertSame(
            ['total' => 10, 'literal' => 3, 'empty' => 2, 'overlength' => 1, 'control' => 1, 'nonliteral' => 3, 'archive' => 3],
            DatsPcIsoSource::summarizeRomNameBatch(['Game.Title.1998.PC.ISO-GRP', 'game-grp.ZIP', 'disk1.rar', '', str_repeat('a', 256), "Game\x01Title", 'Game Title',
                'Game/Title.7z', 'Game\\Title', 1.5]),
        );

        // Form feed is outside trim()'s set; ROM names are never trimmed.
        $this->assertSame(1, CompleteXmlCatalogueSource::summarizeCatalogueTitleBatchPhp(["\x0CTitle"])['nonliteral']);
        $this->assertSame(1, DatsPcIsoSource::summarizeRomNameBatchPhp([' Game'])['nonliteral']);
        // Scene shape needs two dot segments before the last dash and an ASCII word group after it.
        $this->assertSame(1, CrowdnfoSource::summarizeCrowdnfoHeadingBatchPhp(['A-B.C-GRP'])['scene']);
        $this->assertSame(1, CrowdnfoSource::summarizeCrowdnfoHeadingBatchPhp(["A.B-G\xc3\xa9"])['other']);
        // strtolower() is ASCII-only, so a non-ASCII byte never forms a marker.
        $this->assertSame(1, ReleaseContext::summarizeContextMarkerBatchPhp(["P\xc3\x96RN"])['unknown']);

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
            $this->assertParity($helper, ['a' => 'Movie.XXX-YTS', 'b' => ' Game.Title-GRP ', 'c' => 'disk1.RAR']);
            $this->assertParity($helper, [str_repeat('Movie.XXX.WEB-DL.REPACK.YTS.HDCAM-GRP.zip ', 64)]);
            foreach ([254, 255, 256, 257] as $length) {
                $this->assertParity($helper, [str_repeat('a', $length - 4).'-GRP', ' '.str_repeat('b.', intdiv($length, 2)).' ']);
            }
            foreach ($texts as $index => $text) {
                $this->assertParity($helper, [$text]);
                $this->assertParity($helper, [strtoupper($text), ' '.$text.' ', $text.'.a-B']);
                $this->assertParity($helper, array_slice([...$texts, ...$texts], $index, 1 + $index % 13));
            }
        }
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4431));
        $alphabet = ['a', 'b', 'c', 'x', 'p', 's', 'A', 'Z', '0', '1', '9', '_', '.', '.', '-', '-', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\x1F", "\x7F",
            "\xff", "\xc3\xa9", "\xe2\x80\xa6", '/', '\\'];
        $text = static function () use ($random, $alphabet): string {
            $out = '';
            for ($i = $random->getInt(0, 20); $i > 0; $i--) {
                $out .= $random->getInt(0, 2) === 0
                    ? self::WORDS[$random->getInt(0, count(self::WORDS) - 1)]
                    : $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return match ($random->getInt(0, 6)) {
                0 => strtoupper($out),
                1 => ' '.$out."\t",
                2 => ucwords($out, '.'),
                3 => $out.str_repeat('z', $random->getInt(200, 300)),
                default => $out,
            };
        };

        for ($i = 0; $i < 1000; $i++) {
            foreach (array_keys(self::HELPERS) as $helper) {
                $list = [];
                for ($n = $random->getInt(0, 30); $n > 0; $n--) {
                    $list[] = $random->getInt(0, 3) === 0 ? self::TEXTS[$random->getInt(0, count(self::TEXTS) - 1)] : $text();
                }
                $this->assertParity($helper, $list);
            }
        }
    }

    public function test_malformed_inputs_fall_back_to_php(): void
    {
        $oversized = str_repeat('Movie.XXX.WEB-DL.REPACK.YTS.HDCAM-GRP.zip ', 400_000);
        foreach (self::TARGETS as $class) {
            $class::useW443RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            foreach ([['Movie.XXX', null], [1, 'x'], [1.5], [true], [['nested']], ['a', new \stdClass]] as $items) {
                $this->assertNull($class::{$method.'Native'}($items), $class);
                $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            }
            // Batches over the 16 MiB native input cap decline to PHP.
            $this->assertGreaterThan(16 * 1024 * 1024, strlen($oversized));
            $this->assertNull($class::{$method.'Native'}([$oversized]), $class);
            $this->assertSame($class::{$method.'Php'}([$oversized]), $class::$method([$oversized]), $class);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        $out = $ffi->new('char[256]');
        foreach (self::HELPERS as [$class, $method, $symbol]) {
            $items = ['Movie.XXX.YTS-GRP.zip', 'hash_x'];
            $expected = implode("\x1F", $class::{$method.'Php'}($items));
            $args = ['Movie.XXX.YTS-GRP.ziphash_x', 27, pack('V2', 21, 6), 8];
            // Tiny capacity reports -required without writing, then a retry at that size succeeds.
            FFI::memset($small, ord('Z'), 4);
            $needed = $this->callFfi($ffi, $symbol, ...[...$args, $small, 2]);
            $this->assertSame(-strlen($expected), $needed, $symbol);
            $this->assertSame('ZZZZ', FFI::string($small, 4), $symbol);
            $buffer = $ffi->new('char['.(-$needed).']');
            $this->assertSame(-$needed, $this->callFfi($ffi, $symbol, ...[...$args, $buffer, -$needed]), $symbol);
            $this->assertSame($expected, FFI::string($buffer, -$needed), $symbol);
            // Null buffers, null inputs and inconsistent lengths decline; empty lists succeed.
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, ...[...$args, null, 64]), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, null, 0, '', 0, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, '', 0, null, 0, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 2), 4, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'xy', 2, pack('V', 1), 4, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 1).'z', 5, $out, 256), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, 'x', 1, pack('V', 0xFFFFFFFF), 4, $out, 256), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, '', 0, '', 0, $out, 256), $symbol);

            // An output buffer overlapping an input declines without writing to it.
            $shared = $ffi->new('char[64]');
            FFI::memcpy($shared, 'sample'.pack('V', 6), 10);
            $input = FFI::addr($shared[0]);
            $lens = FFI::addr($shared[6]);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, $input, 6, $lens, 4, FFI::addr($shared[3]), 40), $symbol);
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, $input, 6, $lens, 4, FFI::addr($shared[9]), 40), $symbol);
            $this->assertSame('sample'.pack('V', 6), FFI::string($shared, 10), $symbol);
            $this->assertGreaterThan(0, $this->callFfi($ffi, $symbol, $input, 6, $lens, 4, FFI::addr($shared[10]), 40), $symbol);
        }

        foreach (self::HELPERS as [$class, $method]) {
            $class::useW443RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}(['Movie.XXX.YTS-GRP.zip', 'hash_x']), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w443RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $items = ['Movie.XXX.YTS-GRP.zip', ' Game.Title-GRP ', ''];
        foreach (self::HELPERS as [$class, $method]) {
            (new ReflectionProperty($class, 'w443RustFfiHandle'))->setValue(null, false);
            $class::useW443RustFfi(true);
            $this->assertNull($class::{$method.'Native'}($items), $class);
            $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            $class::resetW443RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w443RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side type checks, packing, FFI call and decoding (release dylib, 5 warmups, 301
        // samples per helper and workload; 40 iterations per common sample, 4 per uncommon sample).
        // Common = 32 typical names for the helper's domain; uncommon = 2000 padded, mixed-case,
        // marker-free names of 120-400 bytes.
        // Speedup = PHP latency / Rust latency, ranges over 6 rounds (PHP 8.5.12 NTS, Apple M5, shared
        // host at load average ~5.4-6.6):
        // - context:   common mean 5.41-5.79x / p99 3.88-7.46x, uncommon mean 6.66-6.88x / p99 4.93-6.51x
        // - apibay:    common mean 3.59-3.88x / p99 2.16-3.93x, uncommon mean 4.67-4.79x / p99 3.61-4.13x
        // - catalogue: common mean 1.38-1.48x / p99 0.93-1.71x, uncommon mean 4.02-4.56x / p99 2.57-3.97x
        // - crowdnfo:  common mean 1.99-2.09x / p99 1.01-2.03x, uncommon mean 7.46-7.70x / p99 4.14-6.74x
        // - dats:      common mean 2.18-2.22x / p99 1.98-2.81x, uncommon mean 3.97-4.08x / p99 2.73-3.67x
        // ReleaseContext, ApibaySource and DatsPcIsoSource clear 1.5x on every measure in every round
        // and default to Rust. CompleteXmlCatalogueSource (common mean and p99) and CrowdnfoSource
        // (common p99) do not, so they keep PHP by default: on 32 short titles the fixed FFI
        // marshaling (~1.2-1.5us) is close to the whole PHP cost.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99,
        // per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4432));
        $warmup = 5;
        $samples = 301;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            foreach (['common' => [$this->commonWorkload($helper), 40], 'uncommon' => [$this->uncommonWorkload(), 4]] as $name => [$items, $iterations]) {
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($class, $method, $items, false) + $this->runWorkload($class, $method, $items, true);
                }
                $this->assertSame($class::{$method.'Php'}($items), $class::{$method.'Native'}($items), $helper.' '.$name);
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
                $class::resetW443RustFfi();
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
        fwrite(STDERR, "\nRustReleaseContextPredbFeedsApibayXmlCrowdnfoDatsW44 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W443_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW443RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W443_RUST_FFI_DEFAULT')) {
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
        $this->assertNotNull($native, $helper.' declined: '.json_encode($this->describe($items)));
        $this->assertSame($expected, $native, $helper.': '.json_encode($this->describe($items)));
        $class::useW443RustFfi(true);
        $this->assertSame($expected, $class::$method($items), $helper.' dispatcher');
        $class::useW443RustFfi(false);
        $this->assertSame($expected, $class::$method($items), $helper.' php dispatcher');
        $class::resetW443RustFfi();
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
        $patterns = match ($helper) {
            'context' => ['Show.S01E%d.1080p.WEB-DL.H264-GRP', 'Movie.Title.%d.1080p.BluRay.x264-GRP', 'Show.S%d.Complete.720p.HDTV-GRP', 'Movie.%d.GERMAN.DL.1080p-GRP',
                'Site.24.01.%d.Model.XXX.1080p-GRP', 'Show.S01E%d.REPACK.720p-GRP', 'Movie.%d.MULTi.2160p.UHD-GRP', 'Artist-Album-%d-WEB-FLAC-GRP'],
            'apibay' => ['Movie (%d) [1080p] [WEBRip] [YTS.MX]', 'Movie.%d.1080p.WEBRip.x264-RARBG', 'Show.S01E%d.720p.HDTV.x264-EZTV', 'Movie.%d.HDCAM.x264-TGx',
                'Movie.%d.1080p.WEB-DL.DDP5.1-GalaxyRG', 'Show.S01E%d.1080p.WEB.h264-ETHEL', ' Movie.Title.%d.720p.BluRay ', 'Movie %d 2160p HDR'],
            'catalogue', 'crowdnfo' => ['Game.Title.%d.USA.NSW-GRP', 'Movie.Title.%d.1080p.WEB.H264-GRP', ' Show.S01E%d.720p.HDTV.x264-GRP ', 'Game.Title.%d.EUR.3DS-GRP',
                'Game.Title.%d.Update.v1.2.NSW-GRP', 'Movie Title %d-GRP', 'Movie.Title.%d...-GRP', 'Show.S01E%d.1080p.WEB-DL-GRP_2'],
            default => ['Game.Title.%d.1998.PC.ISO-GRP', 'grp-gt%d.zip', 'grp-gt%d.rar', 'Game.Title.v1.%d.Patch-GRP', 'Game.Title.%d.Retail.CDRip-GRP',
                'Game Title %d', 'grp/gt%d.7z', 'Game.Title.%d.Crack-GRP'],
        };

        return array_map(static fn (int $i): string => sprintf($patterns[$i % 8], $i), range(1, 32));
    }

    /**
     * @return list<string>
     */
    private function uncommonWorkload(): array
    {
        return array_map(static function (int $i): string {
            $length = self::UNCOMMON_LENGTHS[$i % count(self::UNCOMMON_LENGTHS)];
            $body = substr(strtoupper(str_repeat('Obfuscated.Mirror.Release.', 16)).'Part'.$i, -$length + 12);

            return '  '.$body.'.Of.2000-GRP  ';
        }, range(1, 2000));
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     */
    private function runWorkload(string $class, string $method, array $items, bool $useRust): int
    {
        $class::useW443RustFfi($useRust);

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
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W443_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w443', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 44.3 exports is not available.');
        }

        return $ffi;
    }
}
