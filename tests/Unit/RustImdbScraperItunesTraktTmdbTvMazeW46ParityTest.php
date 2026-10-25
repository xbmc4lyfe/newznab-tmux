<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use App\Services\ItunesService;
use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Services\TvMazeClient;
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
 * Wave 46.4 pure CPU IMDb search text, iTunes artwork URL, Trakt media id,
 * TMDB image path and TVMaze AKA name batch summaries. Rust reads the
 * length-prefixed serialize(list<string>) frame without building a document
 * tree and writes eleven little-endian int64 counters that PHP decodes with
 * one named unpack(). The opt-in benchmark retains raw randomized pairs,
 * input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W464_BENCH=1 php vendor/bin/phpunit tests/Unit/RustImdbScraperItunesTraktTmdbTvMazeW46ParityTest.php
 *
 * Seven runs at ca7655924 + this change (Apple M5, PHP 8.5.12, rustc 1.99.1,
 * release build, 3000 pairs per workload), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99):
 *   IMDb 3.92/3.14, 4.43/4.07; iTunes 3.16/2.49, 2.97/2.88;
 *   Trakt 4.29/3.47, 4.08/3.83; TMDB 2.22/1.53, 2.13/2.05;
 *   TVMaze 1.97/1.31, 3.51/3.17.
 * Common batches are one provider page; uncommon batches hold 1024 mixed
 * rows. TVMaze's 16-row AKA page (about 2 microseconds through Rust) cleared
 * every gate in six runs but fell to 1.31x common p99 in one, so it stays on
 * PHP by default; the other four default to Rust. PHP remains the fallback
 * when the library is unavailable.
 */
final class RustImdbScraperItunesTraktTmdbTvMazeW46ParityTest extends TestCase
{
    private const array HELPERS = [
        [ImdbScraper::class, 'summarizeImdbSearchTextBatch', 'nntmux_w464_imdb_search_text_batch', 'l'],
        [ItunesService::class, 'summarizeItunesArtworkBatch', 'nntmux_w464_itunes_artwork_batch', 'artworkUrl100'],
        [TraktService::class, 'summarizeTraktMediaIdBatch', 'nntmux_w464_trakt_media_id_batch', 'slug'],
        [TmdbClient::class, 'summarizeTmdbImagePathBatch', 'nntmux_w464_tmdb_image_path_batch', 'poster_path'],
        [TvMazeClient::class, 'summarizeTvMazeAkaNameBatch', 'nntmux_w464_tvmaze_aka_name_batch', 'name'],
    ];

    private const array DEFAULTS = [true, true, true, true, false];

    /** Earlier public APIs that must stay intact. */
    private const array LEGACY_METHODS = [
        ImdbScraper::class => ['fetchById', 'search', 'extractYearFromTextNative', 'normalizeImageUrlNative', 'normalizeSearchSlugNative', 'parseSuggestionJsonNative', 'parseSearchHtmlNative'],
        ItunesService::class => ['normalizerFfi', 'normalizeAlbumResultNative', 'normalizeAlbumResultPhp', 'getHighResCoverNative', 'getHighResCoverPhp'],
        TraktService::class => ['traktFfi', 'formatImdbIdPhp', 'formatImdbIdNative', 'formatMediaIdPhp', 'formatMediaIdNative', 'isUsableExternalIdPhp', 'isUsableExternalIdNative'],
        TmdbClient::class => ['setUseRustFfi', 'usesRustFfi', 'getImageUrlNative', 'getImageUrlPhp', 'getStringPhp', 'getBoolPhp'],
        TvMazeClient::class => ['setUseRustFfi', 'usesRustFfi', 'extractAkaNamesNative', 'extractAkaNamesPhp', 'normalizeAirdatePhp'],
    ];

    private const array FIELDS = ['title', 'l', 'name', 'query', 'artworkUrl100', 'artworkUrl60', 'artworkUrl30', 'slug', 'imdb', 'trakt', 'tmdb', 'tvdb', 'id', 'file_path', 'poster_path', 'backdrop_path', 'profile_path', 'still_path', 'logo_path', 'team'];

    private const int SEED = 4642026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW464RustFfi();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW464RustFfi', 'usesW464RustFfi', 'resetW464RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W464_RUST_FFI_DEFAULT'));
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
                'The Matrix 1999', '  tt0133093 ', 'tt1234', "Am\xc3\xa9lie (2001)", '!!!', '', " \t", 'tvSeries', 'TV Mini Series', 'tvseriesx', 'https://m.media-amazon.com/images/M/x._V1_UX182.jpg', 'window.awsWafCookieDomainList',
                'Please solve the CAPTCHA', 'Window.GokuProps', 'one ', 'a !b', '2100 1899 2019', str_repeat('a', 256), ['l' => 'Heat', 'id' => 'tt0113277', 'y' => 1995], ['title' => null, 'name' => 'tv_movie'], null, 1984,
            ], ['total_entries' => 22, 'empty_count' => 3, 'year_count' => 4, 'imdb_id_count' => 1, 'slug_empty_count' => 1, 'multi_word_slug_count' => 6, 'stripped_char_count' => 7, 'v1_image_count' => 1, 'waf_marker_count' => 3, 'tv_type_count' => 3, 'overlong_count' => 1]],
            [[
                'https://is1-ssl.mzstatic.com/image/thumb/Music/v4/ab/100x100bb.jpg', 'http://a1.mzstatic.com/us/r30/60x60.JPEG', 'https://x/100x100b.jpg', 'https://x/x100bb.png', '0', '', '00', ' https://x/1x2bb.png?x=1', "caf\xc3\xa9/12x34", '9x9bb.9x9.',
                ['artworkUrl100' => null, 'artworkUrl60' => 'https://a.mzstatic.com/60x60bb.png'], ['trackName' => 'x'], 0, true,
            ], ['total_entries' => 14, 'empty_count' => 5, 'resizable_count' => 5, 'bb_suffix_count' => 4, 'https_count' => 4, 'mzstatic_count' => 3, 'jpeg_count' => 3, 'png_count' => 2, 'whitespace_count' => 1, 'non_ascii_count' => 1, 'query_count' => 1]],
            [[
                'tt0137523', ' TT12345 ', 'tt-12-34-56', '12345', '000', 'breaking-bad', '-bad', 'bad--show', 'Breaking Bad', 'me@home', "caf\xc3\xa9", '', "\f",
                ['trakt' => 1388, 'slug' => 'breaking-bad', 'imdb' => 'tt0903747'], ['ids' => ['trakt' => 1]], 1388, 0, 1.5,
            ], ['total_entries' => 18, 'empty_count' => 3, 'imdb_prefixed_count' => 3, 'imdb_valid_count' => 2, 'numeric_count' => 4, 'positive_numeric_count' => 2, 'slug_ready_count' => 8, 'uppercase_count' => 2, 'whitespace_count' => 2, 'at_sign_count' => 1, 'non_ascii_count' => 1]],
            [[
                '/kqjL17yufvn9OVLyXYpvtyrFfak.jpg', '/abc.PNG', '/logo.svg', 'https://image.tmdb.org/t/p/w500/x.jpeg', '0', '', '//double.jpg', '/../etc/passwd', '/with space.jpg', '/a_b.jpg',
                ['poster_path' => '/p.png', 'backdrop_path' => '/b.jpg'], ['file_path' => null, 'still_path' => '/s.jpg'], ['poster_path' => 5], null,
            ], ['total_entries' => 14, 'empty_count' => 3, 'rooted_count' => 9, 'absolute_url_count' => 1, 'jpeg_count' => 6, 'png_count' => 2, 'svg_count' => 1, 'canonical_count' => 4, 'unsafe_char_count' => 2, 'double_slash_count' => 2, 'traversal_count' => 1]],
            [[
                'The Office (US)', ' An Idiot Abroad ', 'A  Show', 'Doctor Who (2005)', 'Castle (UK) ', '', '   ', 'Theory', "Kaz\xc3\xa1n: Rising!", 'Show (us)', '(1234)',
                ['name' => 'Les Revenants (FR)', 'country' => ['code' => 'FR']], ['name' => null], ['country' => null], 24, false,
            ], ['total_entries' => 16, 'empty_count' => 4, 'blank_count' => 1, 'padded_count' => 3, 'article_prefix_count' => 3, 'year_suffix_count' => 2, 'country_suffix_count' => 2, 'punctuation_count' => 1, 'digit_count' => 3, 'non_ascii_count' => 1, 'multi_space_count' => 1]],
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
                $class::useW464RustFfi($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
                $single = ['x'];
                self::assertSame($class::{$method.'Php'}($single), $class::{$method}($single));
            }
        }
    }

    private static function instance(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /** Counters agree with the real helpers each summary describes. */
    public function test_summaries_agree_with_real_callers(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $imdb = self::instance(ImdbScraper::class);
        $year = new ReflectionMethod(ImdbScraper::class, 'extractYearFromText');
        $slug = new ReflectionMethod(ImdbScraper::class, 'normalizeSearchSlug');
        $type = new ReflectionMethod(ImdbScraper::class, 'normalizeApiDevType');
        $itunes = self::instance(ItunesService::class);
        self::assertInstanceOf(ItunesService::class, $itunes);
        $trakt = self::instance(TraktService::class);
        self::assertInstanceOf(TraktService::class, $trakt);
        $tmdb = self::instance(TmdbClient::class);
        self::assertInstanceOf(TmdbClient::class, $tmdb);
        for ($trial = 0; $trial < 200; $trial++) {
            $entries = [];
            for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
                $entries[] = self::randomEntry($random);
            }
            $texts = array_values(array_filter(array_map(trim(...), $entries), static fn (string $text): bool => $text !== ''));
            $summary = ImdbScraper::summarizeImdbSearchTextBatchNative($entries);
            self::assertSame(count(array_filter($texts, static fn (string $text): bool => $year->invoke($imdb, $text) !== '')), $summary['year_count']);
            self::assertSame(count(array_filter($texts, static fn (string $text): bool => $slug->invoke($imdb, $text) === '')), $summary['slug_empty_count']);
            self::assertSame(count(array_filter($texts, static fn (string $text): bool => str_contains($slug->invoke($imdb, $text), '_'))), $summary['multi_word_slug_count']);
            self::assertSame(count(array_filter($texts, static fn (string $text): bool => $type->invoke($imdb, $text) === 'tv')), $summary['tv_type_count']);

            $summary = ItunesService::summarizeItunesArtworkBatchNative($entries);
            // empty() turns '0' into ''; no generated URL already holds 801x801bb, so every match is rewritten.
            self::assertSame(count(array_filter($entries, static fn (string $url): bool => $url !== '0' && $itunes->getHighResCoverPhp($url, 801) !== $url)), $summary['resizable_count']);
            self::assertSame(count(array_filter($entries, static fn (string $url): bool => $itunes->getHighResCoverPhp($url) === '')), $summary['empty_count']);

            $summary = TraktService::summarizeTraktMediaIdBatchNative($entries);
            self::assertSame(count(array_filter($entries, static fn (string $id): bool => $trakt->formatMediaIdPhp($id) === null)), $summary['empty_count'] + $summary['imdb_prefixed_count'] - $summary['imdb_valid_count']);
            self::assertSame(count(array_filter($entries, static fn (string $id): bool => $trakt->isUsableExternalIdPhp(trim($id), 'imdb'))), count(array_filter($entries, static fn (string $id): bool => strlen(preg_replace('/[^0-9]/', '', $id) ?? '') >= 6)));

            $summary = TmdbClient::summarizeTmdbImagePathBatchNative($entries);
            self::assertSame(count(array_filter($entries, static fn (string $path): bool => $tmdb->getImageUrlPhp($path) === '')), $summary['empty_count']);

            $akas = array_map(static fn (string $name): array => ['name' => $name, 'country' => null], $entries);
            $akas[] = ['name' => null];
            $summary = TvMazeClient::summarizeTvMazeAkaNameBatchNative($akas);
            self::assertSame(count(TvMazeClient::extractAkaNamesPhp($akas)), $summary['total_entries'] - $summary['empty_count']);
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class]) {
            $class::resetW464RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW464RustFfi());
            $class::useW464RustFfi(false);
        }
        $legacy = [TmdbClient::usesRustFfi(), TvMazeClient::usesRustFfi(), (new ReflectionProperty(TmdbClient::class, 'useRustFfi'))->getValue(), (new ReflectionProperty(TvMazeClient::class, 'useRustFfi'))->getValue()];
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW464RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW464RustFfi());
            }
            $class::useW464RustFfi(null);
            self::assertSame(self::DEFAULTS[$index], $class::usesW464RustFfi(), 'useW464RustFfi(null) restores the default.');
            $class::useW464RustFfi(true);
            $class::resetW464RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW464RustFfi());
            $class::useW464RustFfi(false);
            self::assertFalse($class::usesW464RustFfi());
        }
        self::assertSame($legacy, [TmdbClient::usesRustFfi(), TvMazeClient::usesRustFfi(), (new ReflectionProperty(TmdbClient::class, 'useRustFfi'))->getValue(), (new ReflectionProperty(TvMazeClient::class, 'useRustFfi'))->getValue()], 'W464 switches must not touch earlier toggles.');
        foreach ([ItunesService::class, TraktService::class] as $class) {
            self::assertNull((new ReflectionClass($class))->newInstanceWithoutConstructor()->useRustFfi);
        }
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW464RustFfi(true);
            (new ReflectionProperty($class, 'w464Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w464Ffi'))->getValue());
        }
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new ReflectionClass($class))->getConstant('W464_RUST_FFI_DEFAULT') === true;
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    private static function ffi(string $class): FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W464_FFI_CDEF');
        self::assertIsString($cdef);
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w464', $cdef);
        self::assertNotNull($ffi, 'Build the release pipeline library with every W464 symbol.');

        return $ffi;
    }

    /** @return list<string> */
    private static function entries(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w464Entries'))->invoke(null, $input);
    }

    /** @param array<array-key, mixed> $input
     * @return array<string, int>
     */
    private static function rawResult(string $class, FFI $ffi, string $symbol, array $input): array
    {
        $wire = serialize(self::entries($class, $input));
        $out = $ffi->new('char[256]');
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, 256);
        self::assertSame(88, $written, 'Native export must execute, not silently fall back.');
        $format = (new ReflectionClass($class))->getConstant('W464_UNPACK');
        self::assertIsString($format);
        $result = unpack($format, FFI::string($out, 88));
        self::assertIsArray($result);

        return $result;
    }

    /** @param array<array-key, mixed> $input
     * @param  array<string, int>  $expected
     */
    private static function assertNativeDispatch(string $class, string $method, FFI $ffi, array $input, array $expected): void
    {
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 256);
        $class::useW464RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        self::assertSame(pack('P*', ...array_values($expected)), FFI::string($out, 88), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w464Ffi'))->getValue());
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $malformed = ['', 'N;', 'a:1:{i:0;', 'a:01:{i:0;s:0:"";}', 'a:1:{i:0;s:0:"";}trailing', 'a:1:{i:1;s:0:"";}', 'a:1:{i:0;N;}', 'a:1:{i:0;i:5;}', 'a:1:{s:1:"x";s:0:"";}', 'a:1:{i:0;a:0:{}}', 'a:1:{i:0;s:5:"x";}', 'a:2:{i:0;s:0:"";}', serialize([['x']]), serialize(['a' => 'x']), serialize([1 => 'x'])];
        foreach (self::HELPERS as [$class, , $symbol]) {
            $ffi = self::ffi($class);
            $out = $ffi->new('char[256]');
            foreach ($malformed as $wire) {
                FFI::memset($out, 90, 256);
                self::assertSame(-1, self::export($ffi, $symbol, $wire, $out, 256), $wire);
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
            $wire = serialize(['entry']);
            self::assertSame(-88, self::export($ffi, $symbol, $wire, $out, 87), 'Insufficient capacity reports the required size.');
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256), 'Insufficient capacity must not write.');
            self::assertSame(88, self::export($ffi, $symbol, $wire, $out, 88));
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
            $input = [$object, array_fill_keys(self::FIELDS, $object), array_fill_keys(self::FIELDS, 'tt0137523 1999 /x.jpg'), ['title' => $object, 'name' => 'Ok']];
            $class::useW464RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW464RustFfi();
            $class::useW464RustFfi(true);
            $oversized = [$large, array_fill_keys(self::FIELDS, $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w464Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '  ', '.', '..', '-', '--', '_', '/', '//', ':', '!', '?', '&', '@', '(', ')', '"', ';', '}', "\0", "\t", "\v", "\f", "\n", "\r", "\x01", "\x7f", "\xa0", "\xff", "\x80", "\xc3\xa9", '日本語',
        // IMDb search text.
        'The', 'Matrix', '1999', '2019', '1899', '2100', '19', '20', 'tt', 'TT', 'tt0133093', 'tt1234', 'tt12345', '._V1_', '_V1_', 'captcha', 'CAPTCHA', 'challenge.js', 'awsWaf', 'AwSwAf', 'window.gokuProps', 'window.awsWafCookieDomainList',
        'tvSeries', 'tv_series', 'tv mini series', 'TVMovie', 'tv_episode', 'tvspecial', 'movie',
        // iTunes artwork.
        'https://', 'http://', 'is1-ssl.mzstatic.com/', '.mzstatic.com/', '100x100bb.jpg', '60x60.JPEG', '100x100b.', 'x100bb.', '1x2bb.png', '9x9', 'x', 'bb', 'bb.', '.jpg', '.jpeg', '.JPG', '.png', '.PNG', '.svg', '?x=1',
        // Trakt ids.
        'tt0137523', 'breaking-bad', 'Breaking Bad', '12345', '000', '0', '1388', '-bad', 'bad-', 'a-b-c', 'me@home',
        // TMDB paths.
        '/kqjL17yufvn9OVLyXYpvtyrFfak.jpg', '/abc.PNG', '/logo.svg', '/a_b.jpg', '/../', 'image.tmdb.org/t/p/w500',
        // TVMaze AKAs.
        'An', 'A', 'a', 'Theory', '(US)', '(UK)', '(us)', '(FR)', '(2005)', '(123)', '(12345)', 'Doctor Who', 'Les Revenants',
    ];

    private static function randomEntry(Randomizer $random): string
    {
        $entry = '';
        for ($k = 0, $n = $random->getInt(0, 8); $k < $n; $k++) {
            $entry .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $entry .= ['.', '-', ' ', '_', '/', "\n", "\xff", "\0", '', '9', 'a', 'x', '(', ')'][$random->getInt(0, 13)];
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
                2 => [$field => null, ...array_fill_keys(self::FIELDS, $entry)],
                3 => [null, true, false, 1.5, -7, 0, PHP_INT_MAX, [], new \stdClass][$random->getInt(0, 8)],
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
                $class::useW464RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    /** Prefix, suffix, size-token and class rules see every kind of neighbour on both sides. */
    public function test_patterns_with_every_neighbour_match_php(): void
    {
        $patterns = [
            '1999', '20x', 'tt12345', 'tt1234', '._V1_', 'CaPtChA', 'challenge.js', 'AWSWAF', 'tvSeries', 'a b', ' ',
            '100x100bb.', '60x60.', 'x1.', '1x.', '1xbb.', 'https://', '.mzstatic.com/', '.jpg', '.JPEG', '.png', '?',
            'tt123456', 'TT', '123', '0', 'a-b', '-', '--', 'A', '@',
            '/', '/a.jpg', '/a.svg', 'http://', 'HTTPS://', '//', '..', '_',
            'The ', 'An ', 'A ', '(US)', '(KR)', '(1999)', ':', '&', '  ', "\t\f",
        ];
        foreach (self::HELPERS as [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            foreach (['a', 'A', '_', '.', '1', '0', ' ', '-', '/', "\f", "\v", "\0", 'x', 'b', 't', '(', ')', "\xc3"] as $filler) {
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
            $class::resetW464RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW464RustFfi());
            self::assertSame(self::DEFAULTS[$i], self::defaultEnabled($class));
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 256);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            if (self::defaultEnabled($class)) {
                self::assertSame(pack('P*', ...array_values($expected)), FFI::string($out, 88));
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w464Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w464Ffi'))->getValue());
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
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
            'reproduction_command' => 'NNTMUX_W464_BENCH=1 php vendor/bin/phpunit tests/Unit/RustImdbScraperItunesTraktTmdbTvMazeW46ParityTest.php',
        ];
    }

    /** @return list<mixed> Typical production rows for each helper, shaped like the provider's JSON rows. */
    private static function commonSource(int $index): array
    {
        return match ($index) {
            0 => [
                ['id' => 'tt0133093', 'l' => 'The Matrix', 'y' => 1999, 'q' => 'feature'], ['id' => 'tt0234215', 'l' => 'The Matrix Reloaded', 'y' => 2003], ['id' => 'tt10838180', 'l' => 'The Matrix Resurrections', 'y' => 2021],
                ['id' => 'tt0106062', 'l' => 'Matrix', 'y' => 1993, 'q' => 'TV series'], ['id' => 'tt0410519', 'l' => 'The Matrix Revisited (2001)'], ['id' => 'tt9847360', 'l' => "Am\xc3\xa9lie: The Musical"], ['id' => 'tt0903747', 'l' => 'Breaking Bad'],
            ],
            1 => array_map(static fn (string $url): array => ['wrapperType' => 'collection', 'collectionId' => 1440857781, 'collectionName' => 'Album', 'artistName' => 'Artist', 'artworkUrl60' => $url, 'artworkUrl100' => $url, 'releaseDate' => '2020-01-01T08:00:00Z'], [
                'https://is1-ssl.mzstatic.com/image/thumb/Music125/v4/0b/9f/1a/0b9f1a2b-1c3d/source/100x100bb.jpg', 'https://is2-ssl.mzstatic.com/image/thumb/Music/v4/ab/cd/ef/abcdef12/source/100x100bb.jpg',
                'https://is3-ssl.mzstatic.com/image/thumb/Publication115/v4/12/34/56/123456/source/100x100bb.png', 'https://is4-ssl.mzstatic.com/image/thumb/Music114/v4/aa/bb/cc/aabbcc/pr_source.png/100x100bb.jpg',
                'http://a1.mzstatic.com/us/r30/Music/60x60-50.jpg',
            ]),
            2 => [
                ['trakt' => 1388, 'slug' => 'breaking-bad', 'imdb' => 'tt0903747', 'tmdb' => 1396, 'tvdb' => 81189], ['trakt' => 1390, 'slug' => 'game-of-thrones', 'imdb' => 'tt0944947'], 'tt0137523', 'the-office-us', ' 1396 ', 'Stranger Things', 'tt12345',
            ],
            3 => array_map(static fn (string $path): array => ['id' => 603, 'title' => 'The Matrix', 'poster_path' => $path, 'backdrop_path' => '/fNG7i7RqMErkcqhohV2a6cV1Ehy.jpg', 'vote_average' => 8.2], [
                '/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg', '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg', '/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg', '/kqjL17yufvn9OVLyXYpvtyrFfak.png', '/logo_x.svg',
            ]),
            default => [
                ['name' => 'The Office (US)', 'country' => ['name' => 'United States', 'code' => 'US']], ['name' => 'La Oficina', 'country' => ['code' => 'ES']], ['name' => "Kaz\xc3\xa1n: Rising!", 'country' => null],
                ['name' => 'Doctor Who (2005)', 'country' => ['code' => 'GB']], ['name' => 'An Idiot Abroad'], ['name' => ''], ['name' => 'Les Revenants'],
            ],
        };
    }

    /** Common batches are one provider page (25 IMDb suggestions, 25 iTunes results, 50 Trakt ids,
     * 20 TMDB results, 16 TVMaze AKAs); uncommon batches contain 1024 rows mixing array rows, missing
     * fields, invalid bytes and long entries.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $source = self::commonSource($index);
        if ($uncommon) {
            $long = str_repeat("日本語.\xff.Long_Entry-", 32);
            $field = self::HELPERS[$index][3];
            $source = [...$source, ...self::literalCases()[$index][0], $long.' tt0133093 1999 /x.jpg 100x100bb.jpg (US)', array_fill_keys(self::FIELDS, $long.' (2005)'), [$field => $long]];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : [25, 25, 50, 20, 16][$index]; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W464_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W464_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W464_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w464-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave46.4 benchmark artifact: ".$artifact."\n");
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
                        $class::useW464RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW464RustFfi($native);
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
