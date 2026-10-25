<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Predb\Feeds\Sources\NzedbArchiveSource;
use App\Services\Predb\Feeds\Sources\PredatabaseSource;
use App\Services\Predb\Feeds\Sources\RssFeedSource;
use App\Services\Predb\Feeds\Sources\SceneArchiveSqlSource;
use App\Services\Predb\Feeds\Sources\SeriousAboutSoundSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustPredbFeedsNzedbPredatabaseRssSceneArchiveSasW45ParityTest extends TestCase
{
    /** Every Wave 45.2 target shares the W452 toggle names and keeps its existing native parsers separate. */
    private const TARGETS = [
        NzedbArchiveSource::class,
        PredatabaseSource::class,
        RssFeedSource::class,
        SceneArchiveSqlSource::class,
        SeriousAboutSoundSource::class,
    ];

    /** Production defaults: each helper defaults to Rust only when it clears 1.5x on every measure below. */
    private const DEFAULTS = [
        NzedbArchiveSource::class => true,
        PredatabaseSource::class => false,
        RssFeedSource::class => false,
        SceneArchiveSqlSource::class => false,
        SeriousAboutSoundSource::class => true,
    ];

    /** Helper per case: [class, method, extra arguments, op, flags, slots]. */
    private const HELPERS = [
        'nzedb' => [NzedbArchiveSource::class, 'summarizeTitleBatch', [], 0, 0, 8],
        'predatabase' => [PredatabaseSource::class, 'summarizeRlsnameBatch', [], 1, 0, 6],
        'rss' => [RssFeedSource::class, 'summarizeItemTitleBatch', [false, false], 2, 0, 8],
        'rss_spaced' => [RssFeedSource::class, 'summarizeItemTitleBatch', [true, false], 2, 1, 8],
        'rss_strip' => [RssFeedSource::class, 'summarizeItemTitleBatch', [false, true], 2, 2, 8],
        'rss_both' => [RssFeedSource::class, 'summarizeItemTitleBatch', [true, true], 2, 3, 8],
        'scene' => [SceneArchiveSqlSource::class, 'summarizeEligibilityBatch', [], 3, 0, 6],
        'sas' => [SeriousAboutSoundSource::class, 'summarizeHeadingBatch', [], 4, 0, 9],
    ];

    /** Helpers that leave non-ASCII candidates to PHP's Unicode case folding and property classes. */
    private const ASCII_ONLY = ['nzedb', 'sas'];

    /** Benchmarked helper per target class. */
    private const BENCHMARKS = [
        'nzedb' => NzedbArchiveSource::class,
        'predatabase' => PredatabaseSource::class,
        'rss' => RssFeedSource::class,
        'scene' => SceneArchiveSqlSource::class,
        'sas' => SeriousAboutSoundSource::class,
    ];

    /** Strings that exercise trim sets, separators, suffixes, tags, case, NUL bytes, C1 controls and invalid UTF-8. */
    private const TEXTS = [
        '', ' ', "\0", "\t\n", "\x0B", "\x0C", "\r", "\x7F", '-', '.', '_', '...', '…', 'a', 'a-', '-a', 'a-b', 'A.B-C', 'a.b-c', 'a..b-c', 'a-b-c',
        'Movie.2024.1080p.WEB.H264-GRP', 'movie.2024.1080p.web.h264-grp', ' Movie.2024.1080p.WEB.H264-GRP ', 'Show.S01E01.720p.HDTV.x264-GRP_2',
        'Artist-Album-WEB-2024-GRP', 'Artist-Album.WEB-2024-GRP', 'Artist_-_Album-(WEB)-2024-GRP', "Artist-Album's.Best+More,&Co-GRP",
        'Show S01E01 720p WEB h264-GRP EZTV', 'Show S01E01 720p WEB h264-GRP  eztv', 'Show.S01E01-GRP EZTV', "Show\tS01E01\x0CEZTV", 'EZTV', ' EZTV', "\x0CEZTV",
        "Album-GRP \u{2013} 12.5 MB", "Album-GRP \u{2013} 700 GB", "Album-GRP \u{2013} .5 MB", "Album-GRP \u{2013} 1.2.3 MB", 'Album-GRP - 12 MB',
        "Album-GRP  \u{2013} 12 MB", "Album GRP \u{2013} 12 MB", "Album-GRP \u{2013} 12 mb", "\u{2013} 1 MB", " \u{2013} 1 MB", "Album \u{2013} 1 MB EZTV",
        'Spaced Title', "Tab\tTitle", 'Line'."\n".'Title', "Ctl\x01-GRP", "Ctl\x7F-GRP", "C1\xc2\x85-GRP", "Form\x0C-GRP", 'Movie...Title-GRP', 'Movie…Title-GRP',
        'NoSeparator-GRP', '-Lead.Dash-GRP', 'Trail.Dash-', 'Bad Title-GRP', 'Title-GRP', 'Title-grp', 'TITLE-GRP', 'Other(2007)&Co-X_1', "O'Brien.2007-GRP",
        'Café.Album-GRP', 'ÜBER.ALBUM-GRP', 'über.album-grp', "\u{212A}elvin-GRP", 'kelvin-grp', 'ΣΟΦΟΣ.Album-GRP', 'Ünïcödé.FLAC-GRP', "bad \xff utf8", "\xc3",
        "\xe2\x80", "\xed\xa0\x80", "na\xffme.x-GRP", "\xc3\xa9.x-G", '123', '0123', '-0', '1e3',
    ];

    /** Fragments for random titles: separators, tags, suffixes and noise. */
    private const WORDS = ['Movie', 'Show', 'Artist', 'Album', 'Title', 'GRP', 'grp', '2024', 'S01E01', '1080p', 'WEB', 'h264', 'EZTV', 'eztv', ' EZTV',
        "\u{2013}", " \u{2013} 12.5 MB", " \u{2013} 7 GB", ' MB', ' GB', '...', '…', '-GRP', '-grp_2', '.', '_', '-', ' ', '(', ')', '&', "'", '+', ','];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW452RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW452RustFfi', 'usesW452RustFfi', 'resetW452RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            $this->assertFalse($reflection->isReadOnly(), $class);
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W452_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW452RustFfi(), $class);
            $class::useW452RustFfi();
            $this->assertTrue($class::usesW452RustFfi(), $class);
            $class::useW452RustFfi(false);
            $this->assertFalse($class::usesW452RustFfi(), $class);
            $class::useW452RustFfi(null);
            $this->assertSame($default, $class::usesW452RustFfi(), $class);
            $class::useW452RustFfi(true);
            $class::resetW452RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w452RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w452RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW452RustFfi(), $class);
        }

        // The W452 toggles never change the sources' existing parsers.
        $csv = "'Movie.2024-GRP'".str_repeat("\t\t\\N", 6)."\t\t'MOVIE'".str_repeat("\t\t\\N", 4)."\r\n";
        $rss = '<?xml version="1.0"?><rss version="2.0"><channel><item><title>Show S01E01 720p WEB h264-GRP EZTV</title></item></channel></rss>';
        foreach ([true, false] as $enable) {
            foreach (self::TARGETS as $class) {
                $class::useW452RustFfi($enable);
            }
            $entries = (new NzedbArchiveSource('https://nzedb.invalid/', useNative: false))->parseCsv($csv);
            $this->assertSame('Movie.2024-GRP', $entries[0]->title);
            $this->assertSame('MOVIE', $entries[0]->category);
            $this->assertSame(['Movie.2024-GRP'], array_map(static fn ($e): string => $e->title,
                (new PredatabaseSource('https://predb.invalid/'))->parseRows([['rlsname' => ' Movie.2024-GRP '], ['rlsname' => ' ']])));
            $this->assertSame('Show.S01E01.720p.WEB.h264-GRP', (new RssFeedSource('rss', 'RSS', 'https://rss.invalid/', spacedTitles: true))->parseXml($rss)[0]->title);
            $this->assertSame('scene_archive_sql', (new SceneArchiveSqlSource('/nonexistent.sql'))->key());
            $this->assertSame('seriousaboutsound', (new SeriousAboutSoundSource('https://sas.invalid/'))->key());
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 12, 'accepted' => 3, 'empty_title' => 3, 'invalid_utf8' => 1, 'outer_title_whitespace' => 1, 'overlength_title' => 1, 'control_title' => 2, 'duplicate_title' => 1],
            NzedbArchiveSource::summarizeTitleBatchPhp(['Movie.2024-GRP', 'MOVIE.2024-grp', 'Other-GRP', null, ' ', '', ' Lead-GRP', str_repeat('a', 256),
                "Ctl\x7F", "C1\xc2\x85", "bad\xff", 'Ünïcödé-GRP']),
        );
        // mb_strtolower() folds the Kelvin sign onto ASCII, so Rust leaves non-ASCII candidates to PHP.
        $this->assertSame(1, NzedbArchiveSource::summarizeTitleBatchPhp(['kelvin-GRP', "\u{212A}elvin-GRP"])['duplicate_title']);
        $this->assertSame(0, NzedbArchiveSource::summarizeTitleBatchPhp([str_repeat('é', 255)])['overlength_title']);

        $this->assertSame(
            ['total' => 7, 'invalid' => 2, 'empty' => 2, 'accepted' => 3, 'trimmed' => 1, 'distinct' => 2],
            PredatabaseSource::summarizeRlsnameBatchPhp(['Movie-GRP', " Movie-GRP\n", "\x0CX", "\0 ", '', null, 42]),
        );

        $titles = ['Show S01E01 720p WEB h264-GRP EZTV', " Album-GRP \u{2013} 12.5 MB ", 'Show.Name-GRP', 'Spaced Title', '  ', null, "bad\xff \u{2013} 1 MB"];
        $this->assertSame(['total' => 7, 'invalid' => 1, 'stripped' => 0, 'eztv' => 0, 'empty' => 1, 'spaced' => 4, 'accepted' => 1, 'changed' => 0],
            RssFeedSource::summarizeItemTitleBatchPhp($titles));
        $this->assertSame(['total' => 7, 'invalid' => 1, 'stripped' => 1, 'eztv' => 0, 'empty' => 1, 'spaced' => 3, 'accepted' => 2, 'changed' => 1],
            RssFeedSource::summarizeItemTitleBatchPhp($titles, false, true));
        $this->assertSame(['total' => 7, 'invalid' => 1, 'stripped' => 1, 'eztv' => 1, 'empty' => 1, 'spaced' => 0, 'accepted' => 5, 'changed' => 4],
            RssFeedSource::summarizeItemTitleBatchPhp($titles, true, true));

        $row = static fn (mixed $title, mixed $group): array => ['1', 'MP3', $title, $group, '0', null, '0', null, null];
        $this->assertSame(
            ['total' => 10, 'invalid' => 3, 'noncanonical_title' => 2, 'group_conflict' => 2, 'eligible' => 3, 'distinct' => 2],
            SceneArchiveSqlSource::summarizeEligibilityBatchPhp([$row('Movie.2007-GRP', 'grp'), $row('movie.2007-grp', 'GRP'), $row('A-B-C', 'b-c'),
                $row('Bad Title-GRP', 'GRP'), $row(null, 'GRP'), $row('Title-GRP', 'OTHER'), $row('Title-GRP', null), 'row', $row(7, 'GRP'), $row('Title-GRP', 7)]),
        );

        $this->assertSame(
            ['total' => 12, 'invalid' => 2, 'empty' => 1, 'overlength' => 1, 'control' => 1, 'elided' => 2, 'noncanonical' => 2, 'duplicate' => 1, 'accepted' => 2],
            SeriousAboutSoundSource::summarizeHeadingBatchPhp(['Artist-Album.WEB-2024-GRP', ' artist-album.web-2024-grp ', 'Artist_Album-GRP', 'Artist Album-GRP',
                'Artist...Album-GRP', 'Artist…Album-GRP', 'NoSeparator-GRP', 'Trail.Dash-', "bad\xff", null, " \0", str_repeat('a', 256)]),
        );
        $this->assertSame(1, SeriousAboutSoundSource::summarizeHeadingBatchPhp(['Café.Album-GRP'])['accepted']);

        foreach (self::HELPERS as [$class, $method, $args]) {
            $empty = $class::{$method.'Php'}([], ...$args);
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
            $this->assertParity($helper, ['a' => 'Movie.2024-GRP', 'b' => ' movie.2024-grp ', 'c' => null, 'd' => 42]);
            $this->assertParity($helper, [str_repeat('Movie.Title.2024.1080p.WEB-GRP ', 64)]);
            foreach ([254, 255, 256, 257] as $length) {
                $this->assertParity($helper, [str_repeat('a', $length - 4).'-GRP', ' '.str_repeat('b.', intdiv($length, 2)).'-GRP ', str_repeat('é', $length)]);
            }
            foreach ($texts as $index => $text) {
                $this->assertParity($helper, [$text]);
                $this->assertParity($helper, [strtoupper($text), ' '.$text.' ', $text.'.a-B', $text.' EZTV', $text." \u{2013} 1.5 GB"]);
                $this->assertParity($helper, array_slice([...$texts, ...$texts], $index, 1 + $index % 13));
            }
        }
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4521));
        $alphabet = ['a', 'b', 'x', 'A', 'Z', '0', '9', '_', '.', '.', '-', '-', ' ', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\x01", "\x7F",
            "\xff", "\xc2\x85", "\xc3\xa9", "\xe2\x80\x93", "\xe2\x80\xa6", '(', ')', '&', "'", '+', ','];
        $text = static function () use ($random, $alphabet): string {
            $out = '';
            for ($i = $random->getInt(0, 16); $i > 0; $i--) {
                $out .= $random->getInt(0, 1) === 0
                    ? self::WORDS[$random->getInt(0, count(self::WORDS) - 1)]
                    : $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return match ($random->getInt(0, 7)) {
                0 => strtoupper($out),
                1 => ' '.$out."\t",
                2 => strtolower($out),
                3 => $out.str_repeat('z', $random->getInt(200, 300)),
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
        $this->assertGreaterThan(PredatabaseSource::W452_MAX_INPUT, strlen($oversized));
        foreach (self::TARGETS as $class) {
            $class::useW452RustFfi(true);
        }
        foreach (array_keys(self::HELPERS) as $helper) {
            [$class, $method, $args] = self::HELPERS[$helper];
            $items = $this->itemsFor($helper, [$oversized]);
            $this->assertNull($class::{$method.'Native'}($items, ...$args), $helper);
            $this->assertSame($class::{$method.'Php'}($items, ...$args), $class::$method($items, ...$args), $helper);
        }
        // Non-ASCII candidates decline to PHP for the Unicode-folding helpers only.
        foreach (self::ASCII_ONLY as $helper) {
            [$class, $method] = self::HELPERS[$helper];
            $this->assertNull($class::{$method.'Native'}(['Café.Album-GRP']), $helper);
            $this->assertSame($class::{$method.'Php'}(['Café.Album-GRP']), $class::$method(['Café.Album-GRP']), $helper);
        }
        $this->assertNotNull(PredatabaseSource::summarizeRlsnameBatchNative(['Café.Album-GRP']));
    }

    public function test_native_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $symbol = 'nntmux_w452_predb_feed_source_batch';
        $blob = 'Movie-GRP';
        $lens = pack('V', 9);
        $out = $ffi->new('char[128]');

        // Tiny capacity reports -required without writing, then a retry at that size succeeds.
        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $this->assertSame(-48, $this->callFfi($ffi, $symbol, 1, 0, $blob, 9, $lens, 4, $small, 4));
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $this->assertSame(48, $this->callFfi($ffi, $symbol, 1, 0, $blob, 9, $lens, 4, $out, 48));
        $this->assertSame([1, 0, 0, 1, 0, 1], array_values(unpack('q6', FFI::string($out, 48)) ?: []));

        // Unknown ops and flags, null pointers and inconsistent lengths decline; empty batches succeed.
        foreach ([[5, 0], [0, 1], [1, 1], [2, 4], [3, 2], [4, 1]] as [$op, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $symbol, $op, $flags, $blob, 9, $lens, 4, $out, 128), "op {$op} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, null, 0, $lens, 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, $blob, 9, null, 0, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, $blob, 9, $lens, 4, null, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, $blob, 8, $lens, 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, $blob, 9, $lens, 3, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, $blob, 9, pack('V', 10), 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, '', 0, pack('V', PredatabaseSource::W452_INVALID), 4, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 3, 0, '', 0, pack('V2', PredatabaseSource::W452_INVALID, PredatabaseSource::W452_NULL), 8, $out, 128));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 3, 0, '', 0, pack('V', PredatabaseSource::W452_NULL), 4, $out, 128));
        foreach (self::HELPERS as [, , , $op, $flags, $slots]) {
            $this->assertSame($slots * 8, $this->callFfi($ffi, $symbol, $op, $flags, '', 0, '', 0, $out, 128), "op {$op}");
        }

        // An output buffer overlapping either input declines without writing to it.
        $shared = $ffi->new('char[96]');
        FFI::memcpy($shared, $blob.$lens, 13);
        $input = FFI::addr($shared[0]);
        $table = FFI::addr($shared[9]);
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, $input, 9, $table, 4, FFI::addr($shared[4]), 48));
        $this->assertSame(-1, $this->callFfi($ffi, $symbol, 1, 0, $input, 9, $table, 4, FFI::addr($shared[12]), 48));
        $this->assertSame($blob.$lens, FFI::string($shared, 13));
        $this->assertSame(48, $this->callFfi($ffi, $symbol, 1, 0, $input, 9, $table, 4, FFI::addr($shared[16]), 48));

        foreach (self::HELPERS as [$class, $method, $args]) {
            $class::useW452RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}($this->itemsFor($method === 'summarizeEligibilityBatch' ? 'scene' : 'nzedb', ['Movie-GRP']), ...$args), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w452RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (array_keys(self::HELPERS) as $helper) {
            [$class, $method, $args] = self::HELPERS[$helper];
            $items = $this->itemsFor($helper, ['Movie.2024-GRP', ' movie.2024-grp ', '', null]);
            (new ReflectionProperty($class, 'w452RustFfiHandle'))->setValue(null, false);
            $class::useW452RustFfi(true);
            $this->assertNull($class::{$method.'Native'}($items, ...$args), $helper);
            $this->assertSame($class::{$method.'Php'}($items, ...$args), $class::$method($items, ...$args), $helper);
            $class::resetW452RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w452RustFfiHandle'))->getValue(), $helper);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including the PHP-side
        // type checks, packing, FFI call and counter decoding (release dylib, 5 warmups, 301 samples per helper and
        // workload; 20 iterations per common sample, 1 per uncommon sample).
        // Common = one page of typical inputs (100 titles or rows; 25 forum headings), default RSS flags.
        // Uncommon = 5000 long (120-400 byte) mixed-case inputs with whitespace, rejects and duplicates
        // (2000 headings), with RSS spaced titles and display-size stripping enabled.
        // Speedup = PHP latency / Rust latency, ranges over 6 rounds (PHP 8.5.12 NTS, rustc 1.99.1, Apple M5,
        // shared host at load average ~7.4-8.5):
        // - nzedb:       common mean 3.85-4.27x / p99 3.24-4.36x, uncommon mean 6.45-7.45x / p99 4.97-7.12x
        // - predatabase: common mean 1.01-1.06x / p99 0.87-1.07x, uncommon mean 1.07-1.12x / p99 0.95-1.31x
        // - rss:         common mean 0.94-0.96x / p99 0.84-1.02x, uncommon mean 3.94-4.23x / p99 2.94-5.05x
        // - scene:       common mean 1.72-1.88x / p99 1.37-2.38x, uncommon mean 2.07-2.13x / p99 1.50-2.02x
        // - sas:         common mean 2.67-2.79x / p99 2.05-3.00x, uncommon mean 6.79-7.56x / p99 5.00-7.83x
        // NzedbArchiveSource and SeriousAboutSoundSource clear 1.5x on every measure in every round and default
        // to Rust. PredatabaseSource and RssFeedSource (common) stay near 1x: trim() and cached string hashes
        // leave PHP about as cheap as packing the batch. SceneArchiveSqlSource misses 1.5x p99 in some rounds,
        // so it keeps PHP by default.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustPredbFeedsNzedbPredatabaseRssSceneArchiveSasW45ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4522));
        $warmup = 5;
        $samples = 301;
        $sink = 0;

        $speedups = [];
        foreach (self::BENCHMARKS as $helper => $class) {
            $workloads = [
                'common' => [$helper, $this->commonWorkload($helper), 20],
                'uncommon' => [$helper === 'rss' ? 'rss_both' : $helper, $this->uncommonWorkload($helper), 1],
            ];
            foreach ($workloads as $name => [$case, $items, $iterations]) {
                [, $method, $args] = self::HELPERS[$case];
                for ($w = 0; $w < $warmup; $w++) {
                    $sink += $this->runWorkload($class, $method, $args, $items, false) + $this->runWorkload($class, $method, $args, $items, true);
                }
                $native = $class::{$method.'Native'}($items, ...$args);
                $this->assertNotNull($native, $helper.' '.$name.' must run natively');
                $this->assertSame($class::{$method.'Php'}($items, ...$args), $native, $helper.' '.$name);
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += $this->runWorkload($class, $method, $args, $items, $useRust);
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::resetW452RustFfi();
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
        fwrite(STDERR, "\nRustPredbFeedsNzedbPredatabaseRssSceneArchiveSasW45 speedup: {$summary}\n");
        foreach (self::BENCHMARKS as $helper => $class) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W452_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW452RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W452_RUST_FFI_DEFAULT')) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $texts
     */
    private function assertParity(string $helper, array $texts): void
    {
        [$class, $method, $args] = self::HELPERS[$helper];
        $items = $this->itemsFor($helper, $texts);
        $expected = $class::{$method.'Php'}($items, ...$args);
        $native = $class::{$method.'Native'}($items, ...$args);
        $context = $helper.': '.json_encode($this->describe($items));
        if ($native === null && in_array($helper, self::ASCII_ONLY, true)) {
            $this->assertTrue($this->hasNonAscii($texts), $helper.' declined an ASCII batch: '.$context);
        } else {
            $this->assertNotNull($native, $helper.' declined: '.$context);
            $this->assertSame($expected, $native, $context);
        }
        $class::useW452RustFfi(true);
        $this->assertSame($expected, $class::$method($items, ...$args), $helper.' dispatcher');
        $class::useW452RustFfi(false);
        $this->assertSame($expected, $class::$method($items, ...$args), $helper.' php dispatcher');
        $class::resetW452RustFfi();
    }

    /**
     * Scene SQL helpers read parsed nine-column rows; the group follows the title's last dash, with case,
     * mismatch, empty, null and non-string variants ($parsed keeps the string groups validateRow() admits).
     * Other helpers read the texts directly.
     *
     * @param  array<array-key, mixed>  $texts
     * @return array<array-key, mixed>
     */
    private function itemsFor(string $helper, array $texts, bool $parsed = false): array
    {
        if ($helper !== 'scene') {
            return $texts;
        }

        return array_map(static function (mixed $title) use ($parsed): mixed {
            if (is_int($title)) {
                return 'not-a-row';
            }
            $key = is_string($title) ? crc32($title) % ($parsed ? 6 : 8) : 0;
            $dash = is_string($title) ? strrpos($title, '-') : false;
            $suffix = $dash === false ? 'GRP' : substr((string) $title, $dash + 1);
            $group = match ($key) {
                0, 1, 2 => $suffix,
                3 => strtoupper($suffix),
                4 => 'OTHER',
                5 => '',
                6 => null,
                default => 7,
            };

            return ['1', 'MP3', $title, $group, '0', null, '0', null, null];
        }, $texts);
    }

    /**
     * @param  array<array-key, mixed>  $texts
     */
    private function hasNonAscii(array $texts): bool
    {
        foreach ($texts as $text) {
            if (is_string($text) && preg_match('/[\x80-\xff]/', $text) === 1) {
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
     * @return list<mixed>
     */
    private function commonWorkload(string $helper): array
    {
        $patterns = match ($helper) {
            'sas' => ['Artist-Album_%d-WEB-2024-GRP', 'Artist_-_Album.%d-(WEB)-2024-GRP', 'VA-Compilation.%d-2CD-2024-GRP', 'Artist-Single.%d-WEB-2024-GRP',
                'Artist-Album %d-WEB-2024-GRP', 'Artist-Album...%d-GRP', 'Artist-Album-%d-WEB-GRP', 'Label-Sampler.%d-WEB-2024-GRP'],
            'rss' => ['Show.S01E%d.720p.HDTV.x264-GRP', 'Movie.Title.%d.1080p.WEB.H264-GRP', ' Artist-Album-%d-WEB-2024-GRP ', 'Movie %d 2160p HDR',
                'Show.S02E%d.1080p.WEB-DL.DDP5.1-GRP', 'Game.Title.%d.NSW-GRP', 'Movie.%d.German.DL.1080p-GRP', 'Show.S01E%d.REPACK.720p-GRP'],
            default => ['Show.S01E%d.720p.HDTV.x264-GRP', 'Movie.Title.%d.1080p.WEB.H264-GRP', ' Artist-Album-%d-WEB-2024-GRP ', 'Movie Title %d-GRP',
                'Show.S02E%d.1080p.WEB-DL.DDP5.1-GRP', 'Game.Title.%d.NSW-GRP', 'Movie.%d.German.DL.1080p-GRP', 'Show.S01E%d.REPACK.720p-grp'],
        };
        $count = $helper === 'sas' ? 25 : 100;

        return $this->itemsFor($helper, array_map(static fn (int $i): string => sprintf($patterns[$i % 8], $i), range(1, $count)), true);
    }

    /**
     * @return list<mixed>
     */
    private function uncommonWorkload(string $helper): array
    {
        $lengths = [120, 180, 240, 255, 256, 320, 400];
        $count = $helper === 'sas' ? 2000 : 5000;
        $texts = array_map(static function (int $i) use ($lengths, $helper, $count): string {
            $length = $lengths[$i % count($lengths)];
            $body = substr(str_repeat('Obfuscated.Mirror_Release.', 16).'Part'.$i, -$length + 12);

            return match ($i % 6) {
                0 => strtoupper($body).'-GRP',
                1 => '  '.$body.".Of.{$count}-GRP\t",
                2 => str_replace('.', ' ', $body).' EZTV',
                // Only RSS display titles carry the non-ASCII size suffix; archive titles stay ASCII.
                3 => $helper === 'rss' ? $body."-GRP \u{2013} ".($i % 900).'.5 MB' : $body.'.(Remastered)-GRP',
                4 => strtolower($body).'-grp',
                default => $body.'-GRP',
            };
        }, range(1, $count));

        return $this->itemsFor($helper, $texts, true);
    }

    /**
     * @param  class-string  $class
     * @param  list<bool>  $args
     * @param  array<array-key, mixed>  $items
     */
    private function runWorkload(string $class, string $method, array $args, array $items, bool $useRust): int
    {
        $class::useW452RustFfi($useRust);

        return count($class::$method($items, ...$args));
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
        $ffi = RustPipelineLibrary::resolveDomain(PredatabaseSource::W452_FFI_DOMAIN, PredatabaseSource::W452_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 45.2 export is not available.');
        }

        return $ffi;
    }
}
