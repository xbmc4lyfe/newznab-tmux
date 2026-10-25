<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\ReleaseContext;
use App\Services\Predb\Feeds\Sources\G4uSource;
use App\Services\Predb\Feeds\Sources\GabberSource;
use App\Services\Predb\Feeds\Sources\KnabenSource;
use App\Services\Predb\Feeds\Sources\Nima4kSource;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class RustPredbFeedsG4uGabberKnabenNima4kPassableW45ParityTest extends TestCase
{
    /** Every Wave 45.3 target shares the W453 toggle names and keeps its existing native paths separate. */
    private const TARGETS = [
        G4uSource::class,
        GabberSource::class,
        KnabenSource::class,
        Nima4kSource::class,
        CategorizationPassable::class,
    ];

    /** Production defaults: each helper defaults to Rust only when it clears 1.5x on every measure below. */
    private const DEFAULTS = [
        G4uSource::class => false,
        GabberSource::class => true,
        KnabenSource::class => true,
        Nima4kSource::class => true,
        CategorizationPassable::class => false,
    ];

    /** Helper per target: [class, method, export]. */
    private const HELPERS = [
        'g4u' => [G4uSource::class, 'summarizeG4uCardTitleBatch', 'nntmux_w453_g4u_card_title_batch'],
        'gabber' => [GabberSource::class, 'summarizeGabberDirectoryNameBatch', 'nntmux_w453_gabber_directory_name_batch'],
        'knaben' => [KnabenSource::class, 'summarizeKnabenTitleBatch', 'nntmux_w453_knaben_title_batch'],
        'nima4k' => [Nima4kSource::class, 'summarizeNima4kTitleBatch', 'nntmux_w453_nima4k_title_batch'],
        'passable' => [CategorizationPassable::class, 'summarizeMatchedByBatch', 'nntmux_w453_categorization_passable_matched_by_batch'],
    ];

    /**
     * Strings that exercise trim sets, separators, dotted shapes, truncation, labels, NUL bytes, invalid UTF-8 and the
     * non-ASCII characters Rust decides itself (Latin-1, punctuation, kana, CJK, Hangul, Unicode spaces, format,
     * private-use and noncharacter code points).
     */
    private const TEXTS = [
        '', ' ', "\0", "\x01", "\x08", "\t", "\n", "\x0B", "\x0C", "\r", "\x1F", "\x7F", '.', '..', '...', '…', 'a', 'a.b', '.a.b', 'a.b.', 'a..b',
        'Game.Title-GRP', 'Game_Title_v1.2-GRP', 'Game Title', "Game\x7FTitle", 'Artist-Title-WEB-2024-GRP', 'ARTIST-TITLE-WEB-2024-GRP',
        'Movie.Title.2024.1080p.WEB.H264-GRP', 'Movie.Title.20...', 'Movie.Title…', 'MovieTitle', '.Movie.Title', 'Movie..Title', 'Movie.Title.',
        '  Movie.Title.2024-GRP', "Movie.Title.2024-GRP\n", " \t\0", "\x0CMovie", 'no_match', 'no_match_x', 'NO_MATCH', 'group_name_tv',
        'group_name', 'hash_md5', 'hash', 'HASH_md5', 'gibberish_zero_vowels', 'gibberish', 'hd_webdl_fallback', 'fallback', 'webdl',
        'Ünïcödé.FLAC-GRP', 'ÉTÉ-GRP', 'été-grp', 'ß.ss', 'İstanbul.2024', 'Ÿ.ÿ', 'ſ.s', 'Œ.œ', '‐‧', 'ひらがな.カタカナ', '漢字.한국어', "Spiel\u{a0}Titel",
        "a\u{85}b", "a\u{ad}b", "a\u{1680}b", "a\u{2000}b", "a\u{200b}b", "a\u{2028}b", "a\u{202f}b", "a\u{2060}b", "a\u{3000}b", "a\u{e000}b",
        "a\u{fdd0}b", "a\u{feff}b", "a\u{fff9}b", "a\u{fffe}b", "a\u{1fffe}b", "a\u{e0001}b", "a\u{e0041}b", "a\u{f0000}b", "a\u{10ffff}b",
        "bad.\xff.utf8", "\xc3", "\xc3\xa9.x-G", "na\xffme.x-GRP", "\xe2\x80", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xc0\xaf",
    ];

    /** Fragments for random names: separators, markers, labels and noise. */
    private const WORDS = ['Movie', 'Game', 'Show', 'Title', 'GRP', '-GRP', '.2024', '.1080p', '.WEB', 'no_match', 'group_name_', 'hash', 'gibberish_',
        'fallback', 'webdl', '...', '…', '..', '.', '-', '_', 'é', 'ÉTÉ', 'ß', 'İ', '‐', 'ひら', '漢', '한', "\u{a0}", "\u{2028}", "\u{feff}", "\u{fdd0}",
        "\u{e000}", "\u{85}"];

    private const UNCOMMON_LENGTHS = [120, 180, 240, 255, 256, 320, 400];

    protected function tearDown(): void
    {
        foreach (self::TARGETS as $class) {
            $class::resetW453RustFfi();
        }
        CategorizationPassable::setUseRustFfi(false);
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_and_toggles(): void
    {
        foreach (self::TARGETS as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW453RustFfi', 'usesW453RustFfi', 'resetW453RustFfi'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            $this->assertFalse($reflection->isReadOnly(), $class);
            $default = self::DEFAULTS[$class];
            $this->assertSame($default, constant($class.'::W453_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW453RustFfi(), $class);
            $class::useW453RustFfi();
            $this->assertTrue($class::usesW453RustFfi(), $class);
            $class::useW453RustFfi(false);
            $this->assertFalse($class::usesW453RustFfi(), $class);
            $class::useW453RustFfi(null);
            $this->assertSame($default, $class::usesW453RustFfi(), $class);
            $class::useW453RustFfi(true);
            $this->assertTrue($class::usesW453RustFfi(), $class);
            $class::resetW453RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w453RustFfiOverride'))->getValue(), $class);
            $this->assertNull((new ReflectionProperty($class, 'w453RustFfiHandle'))->getValue(), $class);
            $this->assertSame($default, $class::usesW453RustFfi(), $class);
        }

        // The W453 toggles never touch the classes' existing parsing or passable toggles.
        foreach ([true, false] as $enable) {
            foreach (self::TARGETS as $class) {
                $class::useW453RustFfi($enable);
            }
            $this->assertFalse(CategorizationPassable::$useRustFfi);
            $passable = new CategorizationPassable(new ReleaseContext('Movie.Title.2024.1080p.WEB-DL-GRP', 1, 'alt.binaries.movies'), true);
            $this->assertFalse($passable->shouldStopProcessing());
            $passable->updateBestResult(new CategorizationResult(2040, 0.97, 'webdl'), 'movie');
            $this->assertTrue($passable->shouldStopProcessing());
            $this->assertSame(2040, $passable->toArray()['categories_id']);
            $this->assertSame('g4u', (new G4uSource('https://g4u.invalid/'))->key());
            $this->assertSame('gabber', (new GabberSource('https://gabber.invalid/'))->key());
            $this->assertSame('knaben', (new KnabenSource('https://knaben.invalid/api/v2'))->key());
            $this->assertSame('nima4k', (new Nima4kSource('https://nima4k.invalid/'))->key());
            $entries = (new KnabenSource('https://knaben.invalid/api/v2', useNative: false))
                ->parseJson('{"hits":[{"title":"Movie.Title.2024.1080p-GRP","categoryId":[3000000],"category":"Movies"}],"total":{"relation":"eq","value":1}}');
            $this->assertCount(1, $entries);
            $this->assertSame('Movie.Title.2024.1080p-GRP', $entries[0]->title);
        }
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['total' => 10, 'literal' => 3, 'empty' => 2, 'nonliteral' => 4, 'overlength' => 1],
            G4uSource::summarizeG4uCardTitleBatch(['Game.Title-GRP', 'Game_Title_v1.2-GRP', '', 'Game Title', "Game\x7FTitle", "Bad\xffUTF8",
                str_repeat('a', 256), str_repeat('é', 255), "Spiel\u{a0}Titel", null]),
        );

        $this->assertSame(
            ['total' => 10, 'literal' => 7, 'empty' => 2, 'nonliteral' => 1, 'overlength' => 0, 'duplicate' => 4],
            GabberSource::summarizeGabberDirectoryNameBatch(['Artist-Title-WEB-2024-GRP', 'ARTIST-TITLE-WEB-2024-GRP', 'artist-title-web-2024-grp',
                'ÉTÉ-GRP', 'été-grp', 'Other-Title-GRP', '', 'Two Words', 'Other-Title-GRP', 42]),
        );

        $this->assertSame(
            ['total' => 12, 'accepted' => 1, 'empty' => 2, 'truncated' => 2, 'nonliteral' => 6, 'overlength' => 1],
            KnabenSource::summarizeKnabenTitleBatch(['Movie.Title.2024.1080p.WEB.H264-GRP', 'Movie.Title.20...', 'Movie.Title…', 'Movie Title.2024',
                'MovieTitle', '.Movie.Title', 'Movie..Title', 'Movie.Title.', str_repeat('a', 254).'.x', '', "Bad.\xff", false]),
        );

        $this->assertSame(
            ['total' => 10, 'literal' => 2, 'empty' => 3, 'nonliteral' => 5, 'normalized' => 1, 'forbidden' => 5],
            Nima4kSource::summarizeNima4kTitleBatch(['Movie.Title.2024.2160p.UHD.BluRay-GRP', "  Movie.Title.2024-GRP\n", '', " \t\0", 'Movie Title',
                "Movie\x01Title", "\x0CMovie", "Movie\u{fdd0}", "Bad\xff", 1.5]),
        );

        $this->assertSame(
            ['total' => 11, 'empty' => 2, 'no_match' => 1, 'group_name' => 1, 'hash' => 2, 'gibberish' => 1, 'other' => 4, 'fallback' => 2],
            CategorizationPassable::summarizeMatchedByBatch(['', 'no_match', 'no_match_x', 'group_name_tv', 'group_name', 'hash_md5', 'hash',
                'gibberish_zero_vowels', 'hd_webdl_fallback', 'fallback', ['x']]),
        );

        // Literal checks use characters, not bytes: 255 two-byte characters still fit.
        $this->assertSame(1, G4uSource::summarizeG4uCardTitleBatchPhp([str_repeat('é', 255)])['literal']);
        $this->assertSame(1, G4uSource::summarizeG4uCardTitleBatchPhp([str_repeat('é', 256)])['overlength']);
        // mb_strtolower() identity folds full case mappings.
        $this->assertSame(1, GabberSource::summarizeGabberDirectoryNameBatchPhp(['İ.x', 'i̇.x'])['duplicate']);
        // Truncation wins over a missing dotted shape; NIMA4K keeps TAB, LF and CR out of FORBIDDEN_CHARACTERS.
        $this->assertSame(1, KnabenSource::summarizeKnabenTitleBatchPhp(['Movie Title...'])['truncated']);
        $this->assertSame(0, Nima4kSource::summarizeNima4kTitleBatchPhp(["\tMovie\r\n"])['forbidden']);

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
            $this->assertParity($helper, ['a' => 'Movie.Title-GRP', 'b' => ' Game.Title ', 'c' => 'no_match']);
            $this->assertParity($helper, [str_repeat('Movie.Title.é.ひら.hash_fallback-GRP', 64)]);
            foreach ([254, 255, 256, 257] as $length) {
                $this->assertParity($helper, [str_repeat('a', $length - 2).'.b', str_repeat('é', $length - 2).'.b', ' '.str_repeat('b.', intdiv($length, 2)).' ']);
            }
            foreach ($texts as $index => $text) {
                $this->assertParity($helper, [$text]);
                $this->assertParity($helper, [strtoupper($text), ' '.$text.' ', $text.'.a-B', $text.$text]);
                $this->assertParity($helper, array_slice([...$texts, ...$texts], $index, 1 + $index % 13));
            }
        }
    }

    public function test_code_point_sweep_matches_or_declines_to_php(): void
    {
        $this->ffiOrSkip();
        $ranges = [[0x80, 0x3100], [0x3FF0, 0x4000], [0x4DF0, 0x4E10], [0x9FA0, 0x9FB0], [0xABF0, 0xAC10], [0xD790, 0xD7B0], [0xDFF0, 0xE010],
            [0xF8F0, 0xF910], [0xFDC0, 0xFDF8], [0xFEF0, 0xFF10], [0xFFE0, 0xFFFF], [0x1FFF0, 0x20010], [0xDFFF0, 0xE0090], [0xEFFF0, 0xF0010],
            [0x10FFF0, 0x10FFFF]];
        $decided = 0;
        foreach ($ranges as [$from, $to]) {
            for ($code = $from; $code <= $to; $code++) {
                $char = mb_chr($code, 'UTF-8');
                if ($char === false) {
                    continue;
                }
                // Case variants can leave the decided ranges (µ uppercases to Greek), so only the first batch must decide.
                $batches = [['a'.$char.'.b', $char, ' '.$char, $char.$char], ['a'.$char.'.b', mb_strtoupper($char, 'UTF-8').'.b', mb_strtolower($char, 'UTF-8').'.b']];
                foreach (self::HELPERS as $helper => [$class, $method]) {
                    foreach ($batches as $index => $batch) {
                        $expected = $class::{$method.'Php'}($batch);
                        $native = $class::{$method.'Native'}($batch);
                        if ($native !== null) {
                            $decided++;
                            $this->assertSame($expected, $native, sprintf('%s U+%04X batch %d', $helper, $code, $index));
                        }
                        // Rust decides every code point whose class is stable here: Latin-1 and Latin Extended-A
                        // letters, general punctuation, and the permanent controls, separators and format characters.
                        if ($index === 0 && ($code <= 0x17F || ($code >= 0x2000 && $code <= 0x202F))) {
                            $this->assertNotNull($native, sprintf('%s U+%04X declined', $helper, $code));
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(10_000, $decided);
    }

    public function test_undecided_characters_decline_to_php(): void
    {
        $this->ffiOrSkip();
        foreach (['g4u', 'gabber', 'knaben', 'nima4k'] as $helper) {
            [$class, $method] = self::HELPERS[$helper];
            $class::useW453RustFfi(true);
            foreach (["\u{3b1}.\u{3b2}", "Movie.\u{180e}.x", "Movie.\u{e0002}.x", "Movie.\u{1f600}.x"] as $text) {
                $this->assertNull($class::{$method.'Native'}([$text, 'Movie.Title-GRP']), $helper.' '.bin2hex($text));
                $this->assertSame($class::{$method.'Php'}([$text, 'Movie.Title-GRP']), $class::$method([$text, 'Movie.Title-GRP']), $helper);
            }
        }
        // Matched-by labels are compared bytewise, so no character ever declines.
        $this->assertSame(
            CategorizationPassable::summarizeMatchedByBatchPhp(["\u{3b1}", "hash\u{1f600}"]),
            CategorizationPassable::summarizeMatchedByBatchNative(["\u{3b1}", "hash\u{1f600}"]),
        );
        // A definite separator still decides a title holding an undecided character.
        $this->assertSame(['total' => 1, 'literal' => 0, 'empty' => 0, 'nonliteral' => 1, 'overlength' => 0], G4uSource::summarizeG4uCardTitleBatchNative(["\u{3b1} b"]));
        $this->assertSame(1, KnabenSource::summarizeKnabenTitleBatchNative(["\u{3b1}\u{3b2}"])['nonliteral'] ?? null);
    }

    public function test_randomized_inputs_match_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4531));
        $alphabet = ['a', 'b', 'c', 'x', 'A', 'Z', '0', '9', '_', '.', '.', '.', '-', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\x1F", "\x7F", "\xff",
            "\xc3\xa9", "\xe2\x80\xa6", "\xc2\xa0", '/', 'İ', 'Ÿ'];
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
                2 => mb_strtoupper($out, 'UTF-8'),
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
        $oversized = str_repeat('Movie.Title.2024.1080p.WEB-GRP.hash_fallback.', 400_000);
        foreach (self::TARGETS as $class) {
            $class::useW453RustFfi(true);
        }
        foreach (self::HELPERS as [$class, $method]) {
            foreach ([['Movie.Title', null], [1, 'x'], [1.5], [true], [['nested']], ['a', new \stdClass]] as $items) {
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
            $items = ['Movie.Title.2024-GRP', 'hash_x'];
            $expected = implode("\x1F", $class::{$method.'Php'}($items));
            $args = ['Movie.Title.2024-GRPhash_x', 26, pack('V2', 20, 6), 8];
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
            $class::useW453RustFfi(true);
            $this->assertNotNull($class::{$method.'Native'}(['Movie.Title.2024-GRP', 'hash_x']), $class);
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w453RustFfiHandle'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $items = ['Movie.Title.2024-GRP', ' Game.Title ', '', 'hash_x'];
        foreach (self::HELPERS as [$class, $method]) {
            (new ReflectionProperty($class, 'w453RustFfiHandle'))->setValue(null, false);
            $class::useW453RustFfi(true);
            $this->assertNull($class::{$method.'Native'}($items), $class);
            $this->assertSame($class::{$method.'Php'}($items), $class::$method($items), $class);
            $class::resetW453RustFfi();
            $this->assertNull((new ReflectionProperty($class, 'w453RustFfiHandle'))->getValue(), $class);
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // the PHP-side type checks, packing, FFI call and decoding (release dylib, 5 warmups, 301
        // samples per helper and workload; 40 iterations per common sample, 4 per uncommon sample).
        // Common = 32 typical names for the helper's domain; uncommon = 2000 mixed-case dotted names
        // of 120-400 bytes, a quarter with Latin-1 letters.
        // Speedup = PHP latency / Rust latency, ranges over 6 rounds (PHP 8.5.12 NTS, Apple M5, shared
        // host at load average ~5.9-10.3):
        // - g4u:      common mean 1.73-2.16x / p99 0.94-2.35x, uncommon mean 4.95-5.35x / p99 3.74-5.40x
        // - gabber:   common mean 2.68-3.06x / p99 2.41-5.47x, uncommon mean 3.04-3.15x / p99 2.31-3.48x
        // - knaben:   common mean 2.68-2.79x / p99 2.23-2.59x, uncommon mean 6.51-7.19x / p99 5.41-5.86x
        // - nima4k:   common mean 3.23-3.91x / p99 2.72-3.81x, uncommon mean 4.54-4.64x / p99 3.60-4.46x
        // - passable: common mean 1.53-1.64x / p99 1.21-1.52x, uncommon mean 2.60-2.92x / p99 1.93-2.21x
        // GabberSource, KnabenSource and Nima4kSource clear 1.5x on every measure in every round and
        // default to Rust. G4uSource (common p99) and CategorizationPassable (common p99) do not, so
        // they keep PHP by default: on 32 short names the fixed FFI marshaling (~1.3-1.5us) is close
        // to the whole PHP cost.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustPredbFeedsG4uGabberKnabenNima4kPassableW45ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99,
        // per .ai/rules/rust-performance.md.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4532));
        $warmup = 5;
        $samples = 301;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => [$class, $method]) {
            foreach (['common' => [$this->commonWorkload($helper), 40], 'uncommon' => [$this->uncommonWorkload($helper), 4]] as $name => [$items, $iterations]) {
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
                $class::resetW453RustFfi();
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
        fwrite(STDERR, "\nRustPredbFeedsG4uGabberKnabenNima4kPassableW45 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => [$class]) {
            $qualifies = true;
            foreach ($speedups[$helper] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
                $this->assertGreaterThan(0.0, $speedup['mean'], $helper);
                $this->assertGreaterThan(0.0, $speedup['p99'], $helper);
            }
            if (! $qualifies) {
                $this->assertFalse(constant($class.'::W453_RUST_FFI_DEFAULT'), $class.' must keep PHP below 1.5x: '.$summary);
                $this->assertFalse($class::usesW453RustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            if (constant($class.'::W453_RUST_FFI_DEFAULT')) {
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
        $class::useW453RustFfi(true);
        $this->assertSame($expected, $class::$method($items), $helper.' dispatcher');
        $class::useW453RustFfi(false);
        $this->assertSame($expected, $class::$method($items), $helper.' php dispatcher');
        $class::resetW453RustFfi();
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
            'g4u' => ['Game.Title.%d.MULTi12-ElAmigos', 'Game_Title_v1.%d.0-GOG', 'Game.Title.Deluxe.Edition.%d-RUNE', 'Game.Title.Update.v%d-TENOKE',
                'Game.Title.%d.REPACK-KaOs', 'Game.Title.Build.%d-P2P', 'Game.Title.%d-FLT', 'Game.Title.Remastered.%d-DOGE'],
            'gabber' => ['Artist-Title-(CAT%d)-WEB-2024-GRP', 'VA-Hardcore_Compilation_Vol_%d-WEB-2024-GRP', 'Artist_-_Title-(REC%d)-WEB-FLAC-2024-GRP',
                'ARTIST-TITLE-(CAT%d)-WEB-2024-GRP', 'Artist-Title_Remix-(DNB%d)-WEB-2024-GRP', 'Artist-Title-(HS%d)-SINGLE-WEB-2024-GRP',
                'Artist-Title-(CAT%d)-WEB-2024-GRP', 'VA-Drum_And_Bass_Arena_%d-WEB-2024-GRP'],
            'knaben' => ['Movie.Title.%d.1080p.WEB.H264-GRP', 'Movie.Title.%d.2160p.UHD.BluRay.x265-GRP', 'Movie Title %d 1080p WEBRip', 'Movie.Title.%d.720p...',
                'Movie.Title.%d.1080p.BluRay.x264-GRP', 'Movie.Title.%d.HDR.2160p.WEB-DL.DDP5.1-GRP', 'Movie.Title.(%d).1080p.AMZN.WEB-DL-GRP', 'MovieTitle%d'],
            'nima4k' => ['Movie.Title.%d.German.DL.2160p.UHD.BluRay.HEVC-GRP', ' Movie.Title.%d.German.2160p.UHD.BluRay.x265-GRP ',
                'Movie.Title.%d.German.DTSHD.DL.2160p.UHD.BluRay.HDR.HEVC.Remux-GRP', 'Movie.Title.%d.German.DL.2160p.WEB.H265-GRP',
                'Show.S01E%d.German.DL.2160p.UHD.BluRay.HEVC-GRP', 'Movie.Title.%d.German.AC3.DL.2160p.UHD.BluRay.x265-GRP',
                'Movie.Title.%d.German.DL.DV.2160p.WEB.H265-GRP', 'Movie.Title.%d.TrueHD.Atmos.2160p-GRP'],
            default => ['webdl_%d', 'group_name_tv_%d', 'hash_md5_%d', 'no_match', 'hd_webdl_fallback_%d', 'tv_season_pack_%d', 'gibberish_zero_vowels_%d', 'x265_group_%d'],
        };

        return array_map(static fn (int $i): string => sprintf($patterns[$i % 8], $i), range(1, 32));
    }

    /**
     * @return list<string>
     */
    private function uncommonWorkload(string $helper): array
    {
        return array_map(static function (int $i) use ($helper): string {
            $length = self::UNCOMMON_LENGTHS[$i % count(self::UNCOMMON_LENGTHS)];
            $word = $i % 4 === 0 ? 'Écoute.Réédition.' : 'Obfuscated.Mirror.Release.';
            $body = substr(str_repeat($word, 24).'Part'.$i, -$length + 12);
            if ($helper === 'passable') {
                return ['group_name_', 'hash_', 'gibberish_', 'webdl_'][$i % 4].strtolower(strtr($body, '.', '_')).($i % 3 === 0 ? '_fallback' : '');
            }

            return ($helper === 'nima4k' ? ' ' : '').$body.'.Of.2000-GRP';
        }, range(1, 2000));
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $items
     */
    private function runWorkload(string $class, string $method, array $items, bool $useRust): int
    {
        $class::useW453RustFfi($useRust);

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
        $cdef = implode("\n", array_map(static fn (string $class): string => constant($class.'::W453_FFI_CDEF'), self::TARGETS));
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w453', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 45.3 exports is not available.');
        }

        return $ffi;
    }
}
