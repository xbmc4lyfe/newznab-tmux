<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EpisodeHydrationService;
use App\Services\FanartTvService;
use App\Services\GamesTitleParser;
use App\Services\GenreService;
use App\Services\GoogleBooksService;
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
 * Wave 43.4 pure CPU episode searchname, Fanart.TV image, game release, genre
 * title and book identifier batch summaries. The opt-in benchmark retains raw
 * randomized pairs, input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W434_BENCH=1 php vendor/bin/phpunit tests/Unit/RustEpisodeHydrationFanartGamesGenreGoogleBooksW43ParityTest.php
 *
 * Five runs at 7ec9bf35c + this change (Apple Silicon, PHP 8.5, release build),
 * minimum speedup per gate (common mean/p99, uncommon mean/p99):
 *   Episode 1.47/1.12, 1.82/1.75; Fanart 1.29/1.06, 1.41/1.44;
 *   Games 4.06/2.94, 5.17/4.30; Genre 1.45/1.21, 1.65/1.50;
 *   Books 3.40/2.54, 11.41/9.11.
 * Only Games and Books clear every gate, so only they default to Rust; the
 * 24-row common batches sit near the ~3.4 µs marshaling floor.
 */
final class RustEpisodeHydrationFanartGamesGenreGoogleBooksW43ParityTest extends TestCase
{
    private const array HELPERS = [
        [EpisodeHydrationService::class, 'summarizeEpisodeSearchnameBatch', 'nntmux_w434_episode_searchname_batch'],
        [FanartTvService::class, 'summarizeFanartImageBatch', 'nntmux_w434_fanart_image_batch'],
        [GamesTitleParser::class, 'summarizeGameReleaseBatch', 'nntmux_w434_game_release_batch'],
        [GenreService::class, 'summarizeGenreTitleBatch', 'nntmux_w434_genre_title_batch'],
        [GoogleBooksService::class, 'summarizeBookIdentifierBatch', 'nntmux_w434_book_identifier_batch'],
    ];

    private const array DEFAULTS = [false, false, true, false, true];

    /** Earlier-wave public APIs that must stay intact. */
    private const array LEGACY_METHODS = [
        EpisodeHydrationService::class => ['hydrateEpisodeMetadataNative', 'parseSearchnamePhp', 'parseSearchnameNative'],
        FanartTvService::class => ['usesRustFfi', 'setUseRustFfi', 'normalizeImdbIdPhp', 'selectBestPosterUrlNative'],
        GamesTitleParser::class => ['cleanReleaseNamePhp', 'computeSimilarityNative', 'fallbackParseNative'],
        GenreService::class => ['usesRustFfi', 'setUseRustFfi', 'normalizeGenresMapPhp'],
        GoogleBooksService::class => ['buildSearchQueryPhp', 'providerFields', 'normalizerFfi'],
    ];

    private const int SEED = 4342026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::resetW434RustFfi();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW434RustFfi', 'usesW434RustFfi', 'resetW434RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant('W434_RUST_FFI_DEFAULT'));
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
                'Show.S01E02.720p', 'show.s1e123-GRP', 'Show.S01E1234', 'Show.1x05.HDTV', 'Show Season 2 Episode 10', 'Show.Season.03_Episode-7.x',
                'Show.102.HDTV', 'Show.100.HDTV', 'Show.2024.01.15.HDTV', 'Show_2024_01_15', 'Show.Part.3', 'Show.Pt12', 'Show.Ep.07', 'Show E1234',
                'Show.Part.1234', " \0\t", 'S01E02x', ['searchname' => 'Show.S02E03'], ['name' => 'Show.3x04'], null, ['searchname' => ['nested']], 2024,
            ], ['total_searchnames' => 22, 'empty_searchname_count' => 3, 'sxxexx_count' => 3, 'cross_count' => 2, 'season_episode_count' => 2, 'compact_count' => 1, 'date_count' => 1, 'part_count' => 2, 'episode_only_count' => 1, 'unresolved_count' => 7]],
            [[
                'https://assets.fanart.tv/fanart/movies/1/movieposter/x.jpg', 'HTTPS://ASSETS.FANART.TV/PREVIEW/x.JPEG', 'http://example.com/a.png?size=2',
                'https://example.com/a.jpg?x', 'fanart.tv', " \t", 'https:/x.PNG', ['url' => 'https://assets.fanart.tv/x.png', 'likes' => '3'], null, 42,
            ], ['total_images' => 10, 'empty_url_count' => 2, 'https_count' => 4, 'fanart_host_count' => 3, 'jpg_count' => 2, 'png_count' => 2, 'preview_count' => 1, 'query_count' => 2]],
            [[
                'Game.Name.GOTY.Edition-CODEX', 'Game Name v1.2.3 Update - PLAZA', '[PC] Game.MULTi12.REPACK-FitGirl', 'Game.Incl.DLCs-GOG-GAMES',
                'Game.Remastered.v12345678901234567890-UNKNOWN', 'Game (2020) Hotfix', '', 'Game-I_KnoW', ['searchname' => 'Game.v2-SKIDROW'],
                ['title' => 'Game.Deluxe.Edition.MULTI5'], false,
            ], ['total_releases' => 11, 'empty_title_count' => 2, 'scene_group_count' => 4, 'edition_tag_count' => 3, 'version_tag_count' => 3, 'update_patch_count' => 2, 'release_tag_count' => 1, 'multi_language_count' => 2, 'dlc_count' => 1, 'bracket_tag_count' => 2]],
            [[
                'Action', ' action ', 'Sci-Fi & Fantasy', 'rock/pop', "K\xc3\xa9-Pop", '80s', '', 'ACTION', 'Rock/Pop', ['title' => 'Drama'], ['genre' => 'drama'], 1.5,
            ], ['total_genres' => 12, 'empty_title_count' => 2, 'duplicate_title_count' => 4, 'multi_genre_count' => 3, 'non_ascii_count' => 1, 'hyphenated_count' => 2, 'numeric_count' => 1, 'mixed_case_count' => 5]],
            [[
                '978-0-306-40615-7', 'ISBN 0-306-40615-2', '080442957x', '9780306406158', '0306406153', 'X306406152', 'OCLC:12345', '', 'isbn:9790000000001',
                ['identifier' => '9780306406157', 'type' => 'ISBN_13'], ['isbn' => '123'], [],
            ], ['total_identifiers' => 12, 'empty_identifier_count' => 2, 'isbn13_count' => 3, 'isbn10_count' => 2, 'invalid_checksum_count' => 3, 'non_isbn_count' => 2, 'hyphenated_count' => 2, 'prefixed_count' => 2]],
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
                $class::useW434RustFfi($enabled);
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
            $class::resetW434RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW434RustFfi());
            $class::useW434RustFfi(false);
        }
        foreach (self::HELPERS as $index => [$class]) {
            $class::useW434RustFfi();
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, $other::usesW434RustFfi());
            }
            $legacy = self::legacyToggle($class);
            $class::useW434RustFfi(null);
            $restored = $class::usesW434RustFfi();
            self::assertSame(self::DEFAULTS[$index], $restored, 'useW434RustFfi(null) restores the default.');
            $class::useW434RustFfi(true);
            $class::resetW434RustFfi();
            self::assertSame(self::DEFAULTS[$index], $class::usesW434RustFfi());
            self::assertSame($legacy, self::legacyToggle($class), 'W434 reset must not touch earlier toggles.');
            $class::useW434RustFfi(false);
            self::assertFalse($class::usesW434RustFfi());
        }
        foreach (self::HELPERS as $i => [$class, $method]) {
            $class::useW434RustFfi(true);
            (new ReflectionProperty($class, 'w434Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w434Ffi'))->getValue());
        }
    }

    /** Earlier-wave static toggle, when the class has one. */
    private static function legacyToggle(string $class): ?bool
    {
        $reflection = new ReflectionClass($class);
        if (! $reflection->hasMethod('usesRustFfi') || ! $reflection->getMethod('usesRustFfi')->isStatic()) {
            return null;
        }
        $value = $reflection->getMethod('usesRustFfi')->invoke(null);
        self::assertIsBool($value);

        return $value;
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class): bool
    {
        return (new ReflectionClass($class))->getConstant('W434_RUST_FFI_DEFAULT') === true;
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    private static function ffi(string $class): FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W434_FFI_CDEF');
        self::assertIsString($cdef);
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w434', $cdef);
        self::assertNotNull($ffi, 'Build the release pipeline library with all five W434 symbols.');

        return $ffi;
    }

    /** @return list<string> */
    private static function entries(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w434Entries'))->invoke(null, $input);
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
        $class::useW434RustFfi(true);
        self::assertSame($expected, $class::{$method}($input));
        $wire = serialize($expected);
        self::assertSame($wire, FFI::string($out, strlen($wire)), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w434Ffi'))->getValue());
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
        $fields = ['searchname', 'name', 'title', 'url', 'genre', 'identifier', 'isbn'];
        foreach (self::HELPERS as [$class, $method]) {
            $input = [$object, array_fill_keys($fields, $object), ['title' => $object, 'identifier' => 'Ok.S01E01.v2-CODEX.jpg']];
            $class::useW434RustFfi(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::resetW434RustFfi();
            $class::useW434RustFfi(true);
            $oversized = [$large, array_fill_keys($fields, $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w434Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '.', '-', '_', ' - ', '[', ']', '(', ')', '/', '\\', '+', '?', '&', ',', '|', ';', ':', "\0", "\t", "\v", "\f", "\n", "\r", "\xa0", "\x85", "\xff", "\x80", "\xc3\xa9", '日本語',
        // Episode searchnames.
        'S01E02', 's1e123', 'S01E1234', 'S123E01', 'S1', 'E02', '1x05', '12x123', '123x1', 'X', 'x', 'Season', 'season', 'SEASON', 'Episode', 'episode', 'Part', 'PART', 'Pt', 'pt', 'Ep', 'EP', 'E', 'e', 'p',
        '0', '1', '7', '12', '100', '102', '999', '1234', '2024', '01', '15', '2024.01.15', '2024-01-15', '2024_01_15', '20240115', 'HDTV', '720p', 'Show',
        // Fanart.TV URLs.
        'https://', 'HTTPS://', 'http://', 'https:/', 'assets.fanart.tv/', 'FANART.TV/', 'fanart.tv', '/preview/', '/PREVIEW/', 'preview', 'movieposter', '.jpg', '.JPEG', '.png', '.PNG', '.webp', 'jpg', '?x=1',
        // Game releases.
        'CODEX', 'codex', 'PLAZA', 'GOG', 'GOG-GAMES', 'I_KnoW', 'FitGirl', 'KaOs', 'RAZOR1911', '3DMGAME', 'P2P', 'UNKNOWN', 'GOTY', 'Remastered', 'Definitive', 'Edition', 'Deluxe', 'ULTIMATE', 'Complete',
        'Anniversary', 'v1', 'V2', 'v', 'v1a', 'v12345678901234567890', 'Update', 'PATCH', 'hotfix', 'REPACK', 'Rip', 'ISO', 'Crack', 'CRACKFIX', 'Proper', 'MULTI5', 'MULTi12', 'MULTI', 'multi123456789012345', 'DLC', 'DLCs', 'Incl',
        // Genres.
        'Action', 'action', 'ACTION', 'Sci-Fi', 'Fantasy', 'Rock', 'pop', 'Hip-Hop', 'R&B', '80s', 'K-Pop', 'Drama',
        // Book identifiers.
        'ISBN', 'isbn', 'ISBN:', '978-0-306-40615-7', '9780306406157', '9780306406158', '0-306-40615-2', '0306406152', '080442957X', '080442957x', 'X306406152', '9790000000001', '979', '978', '0', 'OCLC',
    ];

    private static function randomEntry(Randomizer $random): string
    {
        $entry = '';
        for ($k = 0, $n = $random->getInt(0, 12); $k < $n; $k++) {
            $entry .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $entry .= ['.', '-', ' ', '_', ' - ', '/', '+', "\n", "\xff", "\0", '', '9'][$random->getInt(0, 11)];
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
            $field = ['searchname', 'name', 'title', 'url', 'genre', 'identifier', 'isbn'][$random->getInt(0, 6)];
            $rows['key'.$j] = match ($random->getInt(0, 9)) {
                0 => [$field => $entry],
                1 => [$field => $entry, 'name' => 'ignored'],
                2 => [$field => null, 'title' => $entry, 'isbn' => $entry, 'name' => $entry],
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
                $class::useW434RustFfi(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $ffi, $input, $expected);
            }
        }
    }

    /** Word-boundary, separator and suffix rules see word, non-word and digit neighbours on both sides. */
    public function test_patterns_with_every_neighbour_match_php(): void
    {
        $patterns = [
            'S01E02', 'S1E123', 'S01E1234', '1x05', '12x123', 'Season 2 Episode 10', 'Season.03_Episode-7', 'Season 123 Episode 1', 'Season 1 Episode 1234', '102', '100', '1000',
            '2024.01.15', '2024_01_15', '2024-1-15', 'Part.3', 'Pt12', 'Part1234', 'Ep.07', 'E1234', "E\f7", "Part\v9", "Part\xa09", 'https://x', 'fanart.tv/', '/preview/', 'a.jpg', 'a.jpeg?x',
            '-CODEX', '- PLAZA', '-  FitGirl', '-GOG-GAMES', '-I_KnoW', 'v1', 'MULTI5', 'GOTY', 'DLCs', '[PC]', '(2020)', 'Sci-Fi & Fantasy', "K\xc3\xa9", '978-0-306-40615-7', 'ISBN 0306406152', '080442957x',
        ];
        foreach (self::HELPERS as [$class, $method, $symbol]) {
            $ffi = self::ffi($class);
            foreach (['a', '_', '.', '1', ' ', '-', "\f", 'X', 'e'] as $filler) {
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
            $class::resetW434RustFfi();
            self::assertSame(self::DEFAULTS[$i], $class::usesW434RustFfi());
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
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w434Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w434Ffi'))->getValue());
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
            'reproduction_command' => 'NNTMUX_W434_BENCH=1 php vendor/bin/phpunit tests/Unit/RustEpisodeHydrationFanartGamesGenreGoogleBooksW43ParityTest.php',
        ];
    }

    /** @return list<mixed> Typical production rows for each helper. */
    private static function commonSource(int $index): array
    {
        return match ($index) {
            0 => ['The.Show.S03E07.1080p.WEB.h264-GRP', 'Another.Show.2x11.720p.HDTV.x264', ['searchname' => 'Daily.Show.2024.03.18.Guest.720p.WEB'], 'Doc.Series.Part.4.1080p.BluRay', 'Anime.Show.Ep.12.1080p', 'Movie.Title.2021.1080p.BluRay.x264-GRP'],
            1 => [['id' => '101', 'url' => 'https://assets.fanart.tv/fanart/movies/603/movieposter/the-matrix-5226d2ac4a5f5.jpg', 'lang' => 'en', 'likes' => '12'], ['url' => 'https://assets.fanart.tv/fanart/movies/603/moviebackground/the-matrix-4f8f2f1e.jpg'], ['url' => 'https://assets.fanart.tv/fanart/tv/81189/hdtvlogo/breaking-bad-5049b1b2.png'], 'https://assets.fanart.tv/preview/movies/603/moviebanner/the-matrix-52b0.jpg'],
            2 => ['Cyberpunk.2077.Ultimate.Edition.v2.12-GOG', 'Elden.Ring.Shadow.of.the.Erdtree.Deluxe.Edition.MULTi14-FitGirl', ['searchname' => 'Hades.II.v0.92596-TENOKE'], 'Baldurs.Gate.3.Update.v4.1.1.5009956-RUNE', 'Stardew.Valley.v1.6.8.Incl.DLC-SKIDROW', '[PC] Starfield (2023) REPACK - DODI'],
            3 => ['Action', 'Adventure', ['title' => 'Sci-Fi & Fantasy'], 'Comedy', ['genre' => 'Rock/Pop'], 'Hip-Hop', 'drama', 'Documentary', 'K-Pop', '80s'],
            default => [['identifier' => '9780306406157', 'type' => 'ISBN_13'], ['identifier' => '0306406152', 'type' => 'ISBN_10'], '978-1-4028-9462-6', 'ISBN 0-8044-2957-X', ['identifier' => 'OCLC:12345', 'type' => 'OTHER']],
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
            $source = [...$source, ...self::literalCases()[$index][0], $long.'Season 2 Episode 10.https://assets.fanart.tv/preview/x.jpg?x', ['searchname' => $long.'-CODEX', 'title' => $long.'Rock/Pop', 'url' => $long.'.png', 'identifier' => $long.'978-0-306-40615-7', 'genre' => $long]];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 1024 : 24; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W434_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W434_BENCH=1 for 3000 paired samples per helper/workload.');
        }
        $artifact = getenv('NNTMUX_W434_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w434-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave43.4 benchmark artifact: ".$artifact."\n");
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
                        $class::useW434RustFfi($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0; $sample < 3000; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::useW434RustFfi($native);
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
