<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\RefreshAnimeData;
use App\Services\SteamService;
use App\Services\TvProcessing\Providers\TmdbProvider;
use App\Support\BookIsbn;
use App\Support\RustPipelineLibrary;
use App\Support\Utf8;
use FFI;
use FFI\CData;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wave 44.4 pure CPU Steam release title, TMDB show name, anime searchname,
 * ISBN value and UTF-8 value batch summaries. The opt-in benchmark retains raw
 * randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W444_BENCH=1 php vendor/bin/phpunit tests/Unit/RustSteamTmdbRefreshAnimeBookIsbnUtf8W44ParityTest.php
 *
 * Seven runs at 7f1e9cb74 + this change (Apple Silicon, PHP 8.5, release build),
 * minimum speedup per gate (common mean/p99, uncommon mean/p99):
 *   Steam 2.26/1.68, 2.82/2.35; TMDB 1.73/1.17, 2.88/2.33;
 *   Anime 1.94/1.63, 2.06/1.96; ISBN 4.36/2.83, 11.99/7.59;
 *   UTF-8 1.48/1.14, 1.70/1.63.
 * Steam, Anime and ISBN clear every gate and default to Rust. TMDB and UTF-8
 * stay on PHP: their 24-row common batches (~3 us Rust) sit on the FFI
 * marshaling floor, so p99 falls below 1.5x on some runs.
 */
final class RustSteamTmdbRefreshAnimeBookIsbnUtf8W44ParityTest extends TestCase
{
    private const array HELPERS = [
        [SteamService::class, 'summarizeSteamTitleBatch', 'nntmux_w444_steam_title_batch'],
        [TmdbProvider::class, 'summarizeTmdbShowNameBatch', 'nntmux_w444_tmdb_show_name_batch'],
        [RefreshAnimeData::class, 'summarizeAnimeSearchnameBatch', 'nntmux_w444_anime_searchname_batch'],
        [BookIsbn::class, 'summarizeIsbnBatch', 'nntmux_w444_isbn_value_batch'],
        [Utf8::class, 'summarizeUtf8Batch', 'nntmux_w444_utf8_value_batch'],
    ];

    private const array DEFAULTS = [true, false, true, true, false];

    /** Earlier-wave public APIs that must stay intact. */
    private const array LEGACY_METHODS = [
        SteamService::class => ['cleanTitlePhp', 'generateQueryVariantsNative', 'buildLikePatternPhp', 'scoreTitlePhp'],
        TmdbProvider::class => ['selectBestShowPhp', 'selectBestShowNative', 'bestCandidateIndexNative', 'encodeCandidateStarted'],
        RefreshAnimeData::class => ['handle', 'extractTitleFromSearchname', 'cleanTitle', 'fixEncoding'],
        BookIsbn::class => ['normalize', 'normalizePhp', 'equivalents', 'normalizeBatch', 'toIsbn10Php'],
        Utf8::class => ['clean', 'isValidNative', 'cleanNative'],
    ];

    private const array FIELDS = ['title', 'name', 'searchname', 'original_name', 'isbn', 'identifier', 'text', 'value'];

    private const int SEED = 4442026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW444RustFfi();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW444RustFfi', 'usesW444RustFfi', 'resetW444RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W444_RUST_FFI_DEFAULT'));
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
                'Cyberpunk.2077.Ultimate.Edition.v2.12.1-GOG', 'Game Name - PLAZA ', 'Game_I_KNOW', '[PC] Game (2020) {x64}', 'Game.REPACK2.zip', 'Game CRACKFIX', 'Game.Repacked',
                'Final Fantasy XVI', 'Rocky IIII', 'Foo%20Bar', 'Foo%2', 'Game v1.2', 'Game 1.2.3a', 'Game.1.2.3.4b', '', " \t", ['title' => 'Doom.Eternal.Deluxe-CODEX.iso'], ['name' => 'Hades II'], null, 2077,
            ], ['total_titles' => 20, 'empty_title_count' => 3, 'scene_group_count' => 3, 'edition_tag_count' => 2, 'release_tag_count' => 3, 'platform_tag_count' => 1, 'version_count' => 2, 'bracket_count' => 1, 'extension_count' => 2, 'url_encoded_count' => 1, 'roman_numeral_count' => 2]],
            [[
                'Breaking Bad (2008)', 'Doctor Who (2005) ', 'Show (2101)', 'Show(2020)', 'The Office', 'the-office', 'An Idiot Abroad', 'A', 'tt0903747', 'imdb TT123456', 'tt12345', 'Star Trek: Discovery',
                "Pok\xc3\xa9mon", '', ['name' => 'The Wire', 'original_name' => 'x'], ['original_name' => 'Dark (2017)'], ['title' => 'Lost'], 1999, false,
            ], ['total_names' => 19, 'empty_name_count' => 2, 'year_suffix_count' => 3, 'imdb_id_count' => 2, 'article_prefix_count' => 3, 'colon_count' => 1, 'parenthetical_count' => 5, 'non_ascii_count' => 1, 'numeric_count' => 9]],
            [[
                '[SubsPlease] Frieren - 12 (1080p) [ENG].mkv', 'Show.S01E02.720p', 'Show 1x05', 'Show_-_007', 'Show - 1234', 'Show Ep 007', 'Show Episode0012', 'Show E0000', 'Show E1234', 'Show The Movie',
                'Show Complete Series', 'Show [BD]', 'Show [1080p]', 'Show[ENG]S01E01', '[Grp][Two] Title', '[] Title', 'Plain Title', '', ['searchname' => 'Show OVA'], ['name' => 'Show [x265]'], 12,
            ], ['total_searchnames' => 21, 'empty_searchname_count' => 1, 'group_tag_count' => 2, 'language_tag_count' => 2, 'sxxexx_count' => 2, 'cross_count' => 1, 'dash_number_count' => 2, 'episode_count' => 3, 'keyword_count' => 3, 'bracket_quality_count' => 3, 'whole_title_count' => 6]],
            [[
                '978-0-306-40615-7', '0-306-40615-2', 'ISBN 0306406152', '080442957x', '9780804429573', '9790000000001', '9780306406158', '123', 'OCLC', '', ['isbn' => '978-0-8044-2957-3'],
                ['identifier' => '0306406152'], [], 306406152,
            ], ['total_values' => 14, 'empty_value_count' => 2, 'isbn10_count' => 4, 'isbn13_count' => 4, 'invalid_count' => 4, 'duplicate_count' => 5, 'isbn10_convertible_count' => 7, 'x_check_digit_count' => 1]],
            [[
                'plain', "caf\xc3\xa9", '日本語', "\xff\xfe", "\xed\xa0\x80", "\xc0\xaf", "\xf4\x90\x80\x80", "a\x01b", "a\x7f", "tab\there", "\xef\xbb\xbfBOM", 'Ã©', "\xc3\x82\xa0", "\0", '',
                ['text' => "x\x1f"], ['value' => 'ok'], 0, null,
            ], ['total_values' => 19, 'empty_value_count' => 3, 'ascii_count' => 7, 'valid_multibyte_count' => 4, 'invalid_utf8_count' => 5, 'control_char_count' => 3, 'bom_count' => 1, 'mojibake_count' => 2]],
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
                $class::useW444RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
                $single = ['x'];
                self::assertSame($class::{$method.'Php'}($single), $class::{$method}($single));
            }
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW444RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW444RustFfi());
            $class::useW444RustFfi(false);
        }
        $bookIsbnNative = (new ReflectionProperty(BookIsbn::class, 'useNative'))->getValue();
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW444RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW444RustFfi());
            }
            $class::useW444RustFfi(null);
            self::assertSame(self::DEFAULTS[$index], $class::usesW444RustFfi(), 'useW444RustFfi(null) restores the default.');
            $class::useW444RustFfi(true);
            $class::resetW444RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW444RustFfi());
            $class::useW444RustFfi(false);
            self::assertFalse($class::usesW444RustFfi());
        }
        self::assertSame($bookIsbnNative, (new ReflectionProperty(BookIsbn::class, 'useNative'))->getValue(), 'W444 switches must not touch earlier toggles.');
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW444RustFfi(true);
            (new ReflectionProperty($class, 'w444Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w444Ffi'))->getValue());
        }
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new ReflectionClass($class))->getConstant('W444_RUST_FFI_DEFAULT') === true;
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    private static function ffi(string $class): FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W444_FFI_CDEF');
        self::assertIsString($cdef);
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w444', $cdef);
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W444 symbols.');

        return $ffi;
    }

    /** @return list<string> */
    private static function entries(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w444Entries'))->invoke(null, $input);
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
        $class::useW444RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = serialize($expected);
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w444Ffi'))->getValue());
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
            $input = [$object, array_fill_keys(self::FIELDS, $object), ['title' => $object, 'isbn' => $object, 'text' => 'Ok.S01E01.v1.2.3-CODEX (2020)']];
            $class::useW444RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW444RustFfi();
            $class::useW444RustFfi(true);
            $oversized = [$large, array_fill_keys(self::FIELDS, $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w444Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '.', '-', '_', ' - ', '[', ']', '(', ')', '{', '}', '/', '+', '%', ':', "\0", "\t", "\v", "\f", "\n", "\r", "\x01", "\x1f", "\x7f", "\xa0", "\x85", "\xff", "\x80", "\xc3\xa9", '日本語',
        "\xc3\x83", "\xc3\x82", "\xef\xbb\xbf", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xc0\xaf", "\xe2\x82", "\xf0\x9f\x98\x80",
        // Steam release titles.
        'CODEX', 'codex', 'GOG', 'PLAZA', 'FLT', 'FLTDOX', 'I_KNOW', 'i_KnOw', '3DMGAME', 'ONLINEFIX', 'DARKSIIDERS', 'GOTY', 'Edition', 'Collector', 'Collectors', 'Deluxe', 'Digital', 'REPACK', 'repack2', 'CRACK',
        'CRACKFIX', 'FIX', 'Incl', 'DLC', 'DLCs', 'MULTI12', 'PREORDER', 'PC', 'win', 'Windows', 'x64', 'X86', 'v1.2.3', 'V1.2', '1.2', '1.2.3', '1.2.3.4', '.5', 'ii', 'III', 'iv', 'X', 'xx', 'xxi', 'IIII',
        'zip', '.rar', '.7z', '.7Z', '.mkv', '.ISO', '.nfo', '%2F', '%zz', '%2', 'Game',
        // TMDB show names.
        'The', 'the ', 'a ', 'A', 'An', 'an ', 'tt1234567', 'TT123456', 'tt12345', 'tt', '(2020)', ' (1999)', ' (2101)', ' (20201)', '(19', 'Show',
        // Anime searchnames.
        'S01E02', 's1e1', 'S', 'E', '1x05', '12X3', 'x', 'Ep', 'Episode', 'episode', 'pisode', 'E 07', 'Ep001', 'E0001', 'E0000', '0', '00', '01', '001', '0001', '7', '12', '123', '1234',
        'Movie', 'OVA', 'Complete', 'Complete Series', 'Full Series', 'Series', '[BD]', '[BDRip]', '[1080p]', '[720P]', '[4K]', '[10800p]', '[999x]', '[HEVC]', '[x265]', '[Blu-Ray]',
        '[ENG]', '[eng]', '(JPN)', '(MULTI)', '[SubsPlease]', '[Group]', '[]', '(ENG', '[ENG',
        // ISBN values.
        'ISBN', 'isbn', 'ISBN:', '978-0-306-40615-7', '9780306406157', '9780306406158', '0-306-40615-2', '0306406152', '080442957X', '080442957x', 'X306406152', '9790000000001', '9780804429573', '979', '978', 'OCLC',
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
                1 => [$field => $entry, 'name' => 'ignored'],
                2 => [$field => null, 'title' => $entry, 'isbn' => $entry, 'name' => $entry, 'text' => $entry, 'searchname' => $entry],
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
                $class::useW444RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    /** Word-boundary, separator, bracket and suffix rules see word, non-word and digit neighbours on both sides. */
    public function test_patterns_with_every_neighbour_match_php(): void
    {
        $patterns = [
            '-CODEX', '_ I_KNOW', ' - PLAZA ', '-FLTDOX', 'GOTY', 'Collectors', 'REPACK2', 'CRACKFIX', 'x64', 'v1.2.3', '1.2.3.4', '1.2', 'XVI', 'ii', '.zip', '.7Z', '%2F', '[x]', '{y}',
            ' (2020)', ' (1999)', 'tt1234567', 'The ', 'an ', 'Star: Trek', ' S01E02', ' 1x05', ' - 12', ' - 1234', ' Ep 007', ' Episode0012', ' E0000', ' E1234', ' Movie', ' Complete Series', ' Full Series',
            '[BD]', '[1080p]', '[ENG]', '(JPN)', '[Grp] ', '978-0-306-40615-7', '080442957X', 'ISBN 0306406152', "\xc3\xa9", "\xff", "\xef\xbb\xbf", "\xc3\x83", "\x01", "\x7f",
        ];
        foreach (self::HELPERS as [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            foreach (['a', '_', '.', '1', '0', ' ', '-', "\f", "\v", 'X', 'e', '[', ']', "\xc3"] as $filler) {
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
            $class::resetW444RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW444RustFfi());
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
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w444Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w444Ffi'))->getValue());
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
            'reproduction_command' => 'NNTMUX_W444_BENCH=1 php vendor/bin/phpunit tests/Unit/RustSteamTmdbRefreshAnimeBookIsbnUtf8W44ParityTest.php',
        ];
    }

    /** @return list<mixed> Typical production rows for each helper. */
    private static function commonSource(int $index): array
    {
        return match ($index) {
            0 => ['Cyberpunk.2077.Ultimate.Edition.v2.12-GOG', 'Elden.Ring.Shadow.of.the.Erdtree.Deluxe.Edition.MULTi14-FitGirl', ['searchname' => 'Hades.II.v0.92596-TENOKE'], 'Baldurs.Gate.3.Update.v4.1.1.5009956-RUNE', 'Stardew.Valley.v1.6.8.Incl.DLC-SKIDROW', '[PC] Starfield (2023) REPACK - DODI', ['title' => 'Final Fantasy XVI Complete Edition']],
            1 => [['id' => 1396, 'name' => 'Breaking Bad', 'original_name' => 'Breaking Bad', 'first_air_date' => '2008-01-20'], ['id' => 1399, 'name' => 'Game of Thrones', 'first_air_date' => '2011-04-17'], ['name' => 'The Office (2005)'], 'Star Trek: Discovery', ['original_name' => 'La Casa de Papel'], ['name' => 'Doctor Who (2005)'], "Pok\xc3\xa9mon"],
            2 => ['[SubsPlease] Sousou no Frieren - 12 (1080p) [ENG].mkv', '[Erai-raws] One Piece - 1100 [1080p][Multiple Subtitle]', ['searchname' => 'Shingeki.no.Kyojin.S04E28.1080p.WEB.H264-GRP'], '[Judas] Jujutsu Kaisen S02E05 [1080p][HEVC x265 10bit]', 'Spy x Family Ep 07 [720p]', 'Kimi no Na wa Movie [BD][1080p]', '[HorribleSubs] Mob Psycho 100 - 01 [720p].mkv'],
            3 => [['isbn' => '9780306406157'], ['identifier' => '0306406152'], '978-1-4028-9462-6', 'ISBN 0-8044-2957-X', ['isbn' => '9781402894626'], 'OCLC:12345'],
            default => ['Breaking.Bad.S05E14.Ozymandias.1080p.WEB-DL', ['text' => "Am\xc3\xa9lie.2001.1080p.BluRay"], 'Some.Release.Name-GRP', ['value' => "\xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e.Anime.2024"], "Bad.\xe9ncoding.Latin1", 'yEnc (1/50) "file.part01.rar"', 'Ã©dition.Mojibake'],
        };
    }

    /** Common batches contain 24 typical rows; uncommon batches contain 1024 rows
     * mixing array rows, missing fields, invalid bytes and long entries.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $source = self::commonSource($index);
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long_Entry-", 32);
            $source = [...$source, ...self::literalCases()[$index][0], $long.' - 12 [1080p] S01E02 v1.2.3-CODEX (2020)', array_fill_keys(self::FIELDS, $long.'978-0-306-40615-7 Movie')];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : 24; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W444_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W444_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W444_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w444-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave44.4 benchmark artifact: ".$artifact."\n");
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
                        $class::useW444RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW444RustFfi($native);
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
