<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\Models\BaseModel;
use App\Services\IGDB\Models\Company;
use App\Services\IGDB\Models\Game;
use App\Services\IGDB\Models\Platform;
use App\Services\MovieBrowseService;
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
 * Wave 48.4 IGDB model attribute, company role, game quality, platform
 * family and movie browse facet batch summaries. PHP flattens each batch to a
 * fixed number of strings per row, Rust streams the serialize(list<string>)
 * frame and writes twelve little-endian int64 values that PHP decodes with
 * one unpack('P*'). The opt-in benchmark keeps raw randomized pairs,
 * input/source/binary hashes, warmups and p99 rank uncertainty.
 * Reproduce: NNTMUX_W48_BENCH=1 php vendor/bin/phpunit tests/Unit/RustIgdbModelsBaseCompanyGamePlatformMovieBrowseW48ParityTest.php
 *
 * Seven runs at 578679cdd + this change (Apple M5, PHP 8.5.12 without
 * opcache, rustc 1.99.1, release build, 3000 pairs per workload after 64
 * warmups per mode, host load average 18-54), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99):
 *   BaseModel attribute 3.21/2.35, 9.13/6.11; Company role 2.96/2.24, 3.16/2.76;
 *   Game quality 2.04/1.78, 2.40/2.11; Platform family 1.60/1.23, 2.95/2.35;
 *   MovieBrowse facet 2.52/2.04, 2.57/2.23.
 * Median-run means (microseconds, PHP vs Rust): attribute 76.3/23.3 and
 * 681.8/74.6; company 108.1/36.0 and 364.2/112.5; game 145.0/70.6 and
 * 332.8/138.8; platform 45.8/27.6 and 161.5/54.3; movie 94.3/36.6 and
 * 239.0/92.6. Common batches hold 64 typical rows; uncommon batches hold
 * 128 rows mixing hand cases, malformed rows and long text. The platform
 * family summary does little per-row work, so the shared PHP flattening
 * bounds it near 1.65x and it fell below 1.5x common p99 in five runs: it
 * stays on PHP by default. The other four default to Rust; PHP remains the
 * fallback when the library is unavailable.
 */
final class RustIgdbModelsBaseCompanyGamePlatformMovieBrowseW48ParityTest extends TestCase
{
    /** [class, entry method, export, fields per row, switch suffix, default constant]. */
    private const array HELPERS = [
        [BaseModel::class, 'summarizeAttributeBatch', 'nntmux_w48_igdb_model_attribute_batch', 3, 'Attribute', 'W48_ATTRIBUTE_RUST_FFI_DEFAULT'],
        [Company::class, 'classifyCompanyRoleBatch', 'nntmux_w48_igdb_company_role_batch', 4, 'CompanyRole', 'W48_COMPANY_ROLE_RUST_FFI_DEFAULT'],
        [Game::class, 'scoreGameQualityBatch', 'nntmux_w48_igdb_game_quality_batch', 8, 'GameQuality', 'W48_GAME_QUALITY_RUST_FFI_DEFAULT'],
        [Platform::class, 'mapPlatformFamilyBatch', 'nntmux_w48_igdb_platform_family_batch', 5, 'PlatformFamily', 'W48_PLATFORM_FAMILY_RUST_FFI_DEFAULT'],
        [MovieBrowseService::class, 'scoreMovieFacetBatch', 'nntmux_w48_movie_browse_facet_batch', 6, 'MovieFacet', 'W48_MOVIE_FACET_RUST_FFI_DEFAULT'],
    ];

    private const array DEFAULTS = [true, true, true, false, true];

    /** Earlier public APIs that must stay intact. */
    private const array LEGACY_METHODS = [
        BaseModel::class => ['setUseRustFfi', 'usesRustFfi', 'normalizeEndpointPhp', 'normalizeAttributeKeyPhp', 'extractIdentifierPhp', 'extractIdentifiersPhp', 'query'],
        Company::class => ['setUseRustFfi', 'usesRustFfi', 'normalizeCompanyRecord', 'normalizeCompanyRecordNative', 'normalizeCompanyRecordPhp'],
        Game::class => ['setUseRustFfi', 'usesRustFfi', 'normalizeGameLookupSpec', 'normalizeGameLookupSpecNative', 'normalizeGameLookupSpecPhp'],
        Platform::class => ['setUseRustFfi', 'usesRustFfi', 'normalizePlatformRecord', 'normalizePlatformRecordNative', 'normalizePlatformRecordPhp'],
        MovieBrowseService::class => ['setUseRustFfi', 'useMovieBrowseFilterRustFfi', 'usesMovieBrowseFilterRustFfi', 'planMovieBrowseFilter', 'getGenres', 'getMovieOrderPhp'],
    ];

    private const int SEED = 4842026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class, , , , $switch]) {
            $class::{'resetW48'.$switch.'RustFfi'}();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method, , , $switch, $constant]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW48'.$switch.'RustFfi', 'usesW48'.$switch.'RustFfi', 'resetW48'.$switch.'RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant($constant));
            self::assertIsString($reflection->getConstant('W48_FFI_CDEF'));
            self::assertStringContainsString(self::HELPERS[$index][2], $reflection->getConstant('W48_FFI_CDEF'));
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
    }

    private static function digest(string $text): int
    {
        $digest = unpack('J', hash('fnv1a64', $text, true));
        self::assertIsArray($digest);

        return $digest[1];
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        return [
            [[
                ['key' => 'firstReleaseDate', 'value' => 1431993600], ['key' => 'platforms', 'value' => [6, '48', ['id' => 6], 'x', 0]], ['key' => 'cover', 'value' => ['id' => 99, 'image_id' => 'co1']],
                ['key' => 'name', 'value' => 42], ['key' => 'Created At', 'value' => ' 2020'], ['key' => '__', 'value' => 1], 'rating', ['key' => 'genres', 'value' => ' +7 '],
                ['key' => 'rating', 'value' => 87.5], ['key' => 'id'], ['key' => 'updated_at', 'value' => null],
            ], ['total_attributes' => 11, 'empty_key_count' => 1, 'renamed_key_count' => 2, 'date_field_count' => 3, 'date_castable_count' => 1, 'int_coercible_count' => 4, 'relation_field_count' => 3, 'relation_id_count' => 4, 'relation_id_sum' => 54 + 99 + 7, 'type_mismatch_count' => 4, 'key_digest' => self::digest("first_release_date\nplatforms\ncover\nname\ncreated_at\nrating\ngenres\nrating\nid\nupdated_at\n"), 'max_relation_ids' => 2]],
            [[
                ['name' => '  Ubisoft  Entertainment SA ', 'slug' => 'ubisoft-entertainment-sa', 'developer' => true, 'publisher' => true, 'country' => 250],
                ['name' => 'Inc', 'slug' => 'INC ', 'porting' => true, 'country' => '0'],
                ['name' => 'Nintendo Co., Ltd.', 'developer' => 1, 'publisher' => true, 'country' => '392'],
                ['name' => " \t "], 'Sega', ['name' => '!!!', 'supporting' => true, 'country' => 1000],
            ], ['total_companies' => 6, 'empty_name_count' => 1, 'developer_count' => 1, 'publisher_count' => 2, 'dual_role_count' => 1, 'auxiliary_only_count' => 2, 'no_role_count' => 2, 'slug_match_count' => 2, 'suffix_stripped_count' => 2, 'known_publisher_count' => 3, 'valid_country_count' => 2, 'canonical_digest' => self::digest("ubisoft-entertainment\ninc\nnintendo\nsega\n")]],
            [[
                ['name' => 'Halo', 'summary' => 'Master Chief returns', 'storyline' => 'War.', 'rating' => 85.25, 'first_release_date' => 1000000000, 'genres' => [['id' => 5], ['id' => 5], ['name' => 'Shooter']], 'platforms' => [6, 48], 'rating_count' => 12],
                ['name' => ' ', 'summary' => '<p>Hi</p> there', 'rating' => '10001', 'first_release_date' => '4102444800', 'genres' => 'RPG, rpg ,,', 'platforms' => 'PC'],
                'Doom', ['name' => 'X', 'rating' => -5, 'first_release_date' => 0, 'rating_count' => '9'], ['summary' => str_repeat('word ', 250), 'rating' => 100],
            ], ['total_games' => 5, 'untitled_count' => 2, 'summary_count' => 3, 'storyline_count' => 1, 'html_summary_count' => 1, 'rated_count' => 2, 'dated_count' => 2, 'genre_sum' => 3, 'platform_sum' => 3, 'quality_sum' => 70 + 4 + 10 + 20 + 40, 'best_quality' => 70, 'release_year_sum' => 2001 + 1970]],
            [[
                ['name' => 'PlayStation 4', 'abbreviation' => 'PS4', 'category' => 1, 'generation' => 8], ['name' => 'Nintendo Switch', 'abbreviation' => 'Switch', 'category' => 5, 'generation' => 9],
                ['name' => 'PC (Microsoft Windows)', 'abbreviation' => 'Win'], ['name' => 'Sega Mega Drive/Genesis', 'abbreviation' => '', 'alternative_name' => 'Genesis'],
                ['name' => 'Ouya', 'category' => '5'], 'Android', ['name' => 'Xbox 360', 'abbreviation' => 'X360', 'generation' => '07'],
            ], ['total_platforms' => 7, 'sony_count' => 1, 'microsoft_count' => 1, 'nintendo_count' => 1, 'sega_count' => 1, 'pc_count' => 1, 'mobile_count' => 1, 'unmatched_count' => 1, 'handheld_count' => 3, 'generation_match_count' => 2, 'generation_mismatch_count' => 1, 'generation_sum' => 8 + 8 + 4 + 7]],
            [[
                ['imdbid' => 'tt0111161', 'tmdbid' => 278, 'title' => 'The Shawshank Redemption', 'year' => '1994', 'rating' => '9.3', 'genre' => 'Drama|Crime'],
                ['imdbid' => '1234567890', 'tmdbid' => '0', 'title' => 'A  Beautiful Mind!', 'year' => 2001, 'rating' => 8.0, 'genre' => 'drama, Biography / western,unknown,DRAMA'],
                'tt7654321', ['imdbid' => 'tt012345', 'tmdbid' => ' 603 ', 'title' => 'An', 'year' => '2100', 'rating' => '10.5', 'genre' => 'Sci-Fi'], ['rating' => '10', 'year' => '1899'],
            ], ['total_movies' => 5, 'valid_imdb_count' => 2, 'valid_tmdb_count' => 2, 'valid_year_count' => 2, 'rated_count' => 3, 'rating_tenths_sum' => 93 + 80 + 100, 'known_genre_sum' => 6, 'unknown_genre_sum' => 1, 'genre_mask' => (1 << 3) | (1 << 5) | (1 << 7) | (1 << 20) | (1 << 25), 'facet_score_sum' => 123 + 105 + 5 + 10 + 100, 'max_sort_key' => 10000000100, 'title_digest' => self::digest("shawshank redemption\nbeautiful mind\nan\n")]],
        ];
    }

    public function test_hand_calculated_contracts_on_both_dispatches(): void
    {
        foreach (self::literalCases() as $i => [$input, $expected]) {
            [$class, $method, , , $switch] = self::HELPERS[$i];
            self::assertSame($expected, $class::{$method.'Php'}($input), $class);
            self::assertSame($expected, $class::{$method.'Native'}($input), $class);
            $empty = $class::{$method.'Php'}([]);
            self::assertSame(array_keys($expected), array_keys($empty));
            foreach ([false, true] as $enabled) {
                $class::{'useW48'.$switch.'RustFfi'}($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
            }
        }
    }

    public function test_summaries_agree_with_real_callers(): void
    {
        // Renamed keys follow normalizeAttributeKeyPhp().
        foreach (['firstReleaseDate', 'first_release_date', 'IGDBGameId', 'cover.image_id', ' name ', 'involved-companies', 'X', 'a__b_'] as $key) {
            $renamed = BaseModel::normalizeAttributeKeyPhp($key) !== $key;
            self::assertSame((int) $renamed, BaseModel::summarizeAttributeBatchNative([['key' => $key, 'value' => 1]])['renamed_key_count'], $key);
        }
        // Positive and non-numeric strings coerce exactly when extractIdentifierPhp() accepts them.
        foreach (['42', ' 7 ', '+3', 'x', '1e3', '12a', '999999999999999999', ''] as $value) {
            $coercible = BaseModel::extractIdentifierPhp(['id' => $value]) !== null;
            self::assertSame((int) $coercible, BaseModel::summarizeAttributeBatchNative([['key' => 'k', 'value' => $value]])['int_coercible_count'], $value);
        }
        // Empty names, slugs and countries follow normalizeCompanyRecordPhp().
        foreach ([['Ubisoft Montreal', 250], [" \t ", 0], ['CD Projekt  RED', 616], ['Nintendo', 1000], ['日本', 392]] as [$name, $country]) {
            $record = Company::normalizeCompanyRecordPhp(1, $name, $country);
            $summary = Company::classifyCompanyRoleBatchNative([['name' => $name, 'slug' => $record['slug'], 'country' => $country]]);
            self::assertSame((int) ($record['name'] === ''), $summary['empty_name_count'], $name);
            self::assertSame((int) ($record['slug'] !== ''), $summary['slug_match_count'], $name);
            self::assertSame((int) ($record['country'] !== null), $summary['valid_country_count'], $name);
        }
        // Release years agree with gmdate().
        foreach ([0, 86399, 951782400, 1000000000, 1431993600, 1704067199, 1704067200, 4102444799] as $timestamp) {
            self::assertSame((int) gmdate('Y', $timestamp), Game::scoreGameQualityBatchNative([['first_release_date' => $timestamp]])['release_year_sum'], (string) $timestamp);
        }
        // Platform aliases match the abbreviation first.
        $summary = Platform::mapPlatformFamilyBatchNative([['name' => 'PlayStation 4', 'abbreviation' => 'X360']]);
        self::assertSame([0, 1], [$summary['sony_count'], $summary['microsoft_count']]);
        // Every getGenres() entry is known, and years follow planMovieBrowseFilter().
        $movies = (new ReflectionClass(MovieBrowseService::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(MovieBrowseService::class, $movies);
        $genres = $movies->getGenres();
        $summary = MovieBrowseService::scoreMovieFacetBatchNative([['genre' => implode('|', $genres)]]);
        self::assertSame([count($genres), 0, (1 << count($genres)) - 1], [$summary['known_genre_sum'], $summary['unknown_genre_sum'], $summary['genre_mask']]);
        MovieBrowseService::useMovieBrowseFilterRustFfi(false);
        try {
            foreach (['1994', '2001', '2099', '1899', '2100', '199', '19944', 'abcd', ''] as $year) {
                $applied = MovieBrowseService::planMovieBrowseFilter('year', $year)['applied'];
                self::assertSame((int) $applied, MovieBrowseService::scoreMovieFacetBatchNative([['year' => $year]])['valid_year_count'], $year);
            }
        } finally {
            MovieBrowseService::resetMovieBrowseFilterRustFfi();
        }
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class, , , , $switch]) {
            $class::{'resetW48'.$switch.'RustFfi'}();
            self::assertSame(self::DEFAULTS[$index], $class::{'usesW48'.$switch.'RustFfi'}());
            $class::{'useW48'.$switch.'RustFfi'}(false);
        }
        $legacy = [BaseModel::usesRustFfi(), Company::usesRustFfi(), Game::usesRustFfi(), Platform::usesRustFfi(), MovieBrowseService::usesMovieBrowseFilterRustFfi()];
        foreach (self::HELPERS as $index => [$class, , , , $switch]) {
            $class::{'useW48'.$switch.'RustFfi'}();
            foreach (self::HELPERS as [$other, , , , $otherSwitch]) {
                self::assertSame($class === $other, $other::{'usesW48'.$otherSwitch.'RustFfi'}());
            }
            $class::{'useW48'.$switch.'RustFfi'}(null);
            self::assertSame(self::DEFAULTS[$index], $class::{'usesW48'.$switch.'RustFfi'}(), 'null restores the default.');
            $class::{'useW48'.$switch.'RustFfi'}(true);
            $class::{'resetW48'.$switch.'RustFfi'}();
            self::assertSame(self::DEFAULTS[$index], $class::{'usesW48'.$switch.'RustFfi'}());
            $class::{'useW48'.$switch.'RustFfi'}(false);
            self::assertFalse($class::{'usesW48'.$switch.'RustFfi'}());
        }
        self::assertSame($legacy, [BaseModel::usesRustFfi(), Company::usesRustFfi(), Game::usesRustFfi(), Platform::usesRustFfi(), MovieBrowseService::usesMovieBrowseFilterRustFfi()], 'W48 switches must not touch earlier toggles.');
        foreach (self::HELPERS as $i => [$class, $method, , , $switch]) {
            $class::{'useW48'.$switch.'RustFfi'}(true);
            (new ReflectionProperty($class, 'w48Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w48Ffi'))->getValue());
        }
    }

    /** Reads the constant at run time so the gate assertions survive future default flips. */
    private static function defaultEnabled(string $class, string $constant): bool
    {
        return (new ReflectionClass($class))->getConstant($constant) === true;
    }

    private static function export(FFI $ffi, string $symbol, string $wire, CData $out, int $capacity): int
    {
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, $capacity);
        self::assertIsInt($written);

        return $written;
    }

    private static function ffi(string $class): FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W48_FFI_CDEF');
        self::assertIsString($cdef);
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w48', $cdef);
        self::assertNotNull($ffi, 'Build the release pipeline library with every W48 symbol.');

        return $ffi;
    }

    /** @return list<int|string> */
    private static function fields(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w48Fields'))->invoke(null, $input);
    }

    /** @param array<array-key, mixed> $input
     * @return array<string, int>
     */
    private static function rawResult(string $class, string $method, FFI $ffi, string $symbol, array $input): array
    {
        $wire = serialize(self::fields($class, $input));
        $out = $ffi->new('char[256]');
        $written = $ffi->{$symbol}($wire, strlen($wire), $out, 256);
        self::assertSame(96, $written, 'Native export must execute, not silently fall back.');
        $values = unpack('P*', FFI::string($out, 96));
        self::assertIsArray($values);

        return array_combine(array_keys($class::{$method.'Php'}([])), $values);
    }

    /** @param array<array-key, mixed> $input
     * @param  array<string, int>  $expected
     */
    private static function assertNativeDispatch(string $class, string $method, string $switch, FFI $ffi, array $input, array $expected): void
    {
        $capacity = 0;
        $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
        self::assertNotNull($out);
        FFI::memset($out, 90, 256);
        $class::{'useW48'.$switch.'RustFfi'}(true);
        self::assertSame($expected, $class::{$method}($input));
        self::assertSame(pack('P*', ...array_values($expected)), FFI::string($out, 96), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w48Ffi'))->getValue());
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $malformed = ['', 'N;', 'a:1:{i:0;', 'a:01:{i:0;s:0:"";}', 'a:1:{i:0;s:0:"";}trailing', 'a:1:{i:1;s:0:"";}', 'a:1:{i:0;N;}', 'a:1:{i:0;i:05;}', 'a:1:{i:0;i:-0;}', 'a:1:{i:0;i:;}', 'a:1:{i:0;i:-;}', 'a:1:{i:0;i:5}', 'a:1:{i:0;i:12345678901234567890;}', 'a:1:{i:0;d:5;}', 'a:1:{s:1:"x";s:0:"";}', 'a:1:{i:0;a:0:{}}', 'a:1:{i:0;s:5:"x";}', 'a:2:{i:0;s:0:"";}', serialize([['x']]), serialize(['a' => 'x']), serialize([1 => 'x'])];
        foreach (self::HELPERS as [$class, , $symbol, $stride]) {
            $ffi = self::ffi($class);
            $out = $ffi->new('char[256]');
            $partial = [serialize(array_fill(0, $stride - 1, 'x')), serialize(array_fill(0, $stride + 1, 'x'))];
            foreach ([...$malformed, ...$partial] as $wire) {
                FFI::memset($out, 90, 256);
                self::assertSame(-1, self::export($ffi, $symbol, $wire, $out, 256), $wire);
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
            $wire = serialize(array_fill(0, $stride, 'entry'));
            self::assertSame(-96, self::export($ffi, $symbol, $wire, $out, 95), 'Insufficient capacity reports the required size.');
            self::assertSame(str_repeat('Z', 256), FFI::string($out, 256), 'Insufficient capacity must not write.');
            self::assertSame(96, self::export($ffi, $symbol, $wire, $out, 96));
            self::assertSame(96, self::export($ffi, $symbol, 'a:0:{}', $out, 96));
            // Entries may embed quotes, NULs and frame syntax.
            self::assertSame(96, self::export($ffi, $symbol, serialize(array_fill(0, $stride, "\";}i:1;s:0:\"\0")), $out, 96));
            // Integer entries read exactly as their (string) cast.
            foreach ([0, 5, -5, 100, 1994, 1431993600, PHP_INT_MAX, PHP_INT_MIN] as $int) {
                $text = $ffi->new('char[96]');
                self::assertSame(96, self::export($ffi, $symbol, serialize(array_fill(0, $stride, (string) $int)), $text, 96));
                self::assertSame(96, self::export($ffi, $symbol, serialize(array_fill(0, $stride, $int)), $out, 96));
                self::assertSame(FFI::string($text, 96), FFI::string($out, 96), (string) $int);
            }
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
        $fields = ['key', 'value', 'name', 'slug', 'developer', 'publisher', 'country', 'summary', 'storyline', 'rating', 'first_release_date', 'genres', 'platforms', 'rating_count', 'abbreviation', 'alternative_name', 'category', 'generation', 'imdbid', 'tmdbid', 'title', 'year', 'genre'];
        $large = str_repeat('x', 16777217);
        foreach (self::HELPERS as [$class, $method, , , $switch]) {
            $input = [$object, array_fill_keys($fields, $object), ['key' => 'genres', 'value' => [$object, ['id' => $object]], 'genres' => [$object, ['name' => $object]]]];
            $class::{'useW48'.$switch.'RustFfi'}(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::{'resetW48'.$switch.'RustFfi'}();
            $class::{'useW48'.$switch.'RustFfi'}(true);
            $oversized = [$large, array_fill_keys($fields, $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w48Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '  ', '.', '..', '-', '_', '__', '/', '|', ',', ':', '!', '+', '&', '(', ')', '"', '\\', ';', '<', '>', '<p>', '</b>', '<!', '*', "\0", "\t", "\v", "\f", "\n", "\r", "\x01", "\x1f", "\x7f", "\xa0", "\xff", "\xc3\xa9", '日本語',
        // Attribute keys and identifiers.
        'firstReleaseDate', 'created_at', 'Updated At', 'platforms', 'cover', 'involved-companies', 'IGDBId', 'name', 'id', 'rating', 'genres', 'versions', 'image_id', '0', '1', '7', '42', '+7', '-3', '999999999999', '1000000000000', '1431993600', '4102444799', '4102444800', '1e3',
        // Companies.
        'Ubisoft', 'Entertainment', 'SA', 'Inc', 'Ltd', 'Co', 'GmbH', 'Corp', 'Nintendo', 'Sega', 'Sony', 'Bandai Namco', 'Square Enix', 'Electronic Arts', 'ubisoft-entertainment', '250', '392', '1000', '15', '16',
        // Games and platforms.
        'Halo', 'Reach', 'Master', 'Chief', 'Shooter', 'RPG', 'rpg', 'PC', 'PS4', 'PlayStation', '4', 'Switch', 'Nintendo Switch', 'Xbox 360', 'X360', 'Genesis', 'Mega Drive', 'GameCube', 'Game Boy', 'Android', 'iOS', '07', '8', '9', '10000', '10001', '8525', '-500',
        // Movies.
        'tt', 'tt0111161', 'TT1234567', '0111161', '12345678', '278', '1994', '2001', '1899', '2100', '9.3', '10.0', '10.5', '7.', '.5', 'The', 'A', 'An', 'Shawshank', 'Drama', 'crime', 'Sci-Fi', 'Film-Noir', 'Western', 'Unknown',
    ];

    private static function randomText(Randomizer $random, int $max = 5): string
    {
        $text = '';
        for ($k = 0, $n = $random->getInt(0, $max); $k < $n; $k++) {
            $text .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $text .= [' ', '.', ',', '|', '/', '-', '_', "\0", "\xff", '"', '+', '<', '9', 'a', 'X', "\f"][$random->getInt(0, 15)];
            }
        }
        if ($random->getInt(0, 40) === 0) {
            $text .= str_repeat('x', $random->getInt(95, 130));
        }

        return $text;
    }

    private static function randomScalar(Randomizer $random): mixed
    {
        return match ($random->getInt(0, 9)) {
            0 => null,
            1 => $random->getInt(0, 1) ? $random->getInt(-5, 5000000000) : [-1, 0, 1, 5, 7, 8, 15, 16, 99, 100, 101, 999, 1000, 1994, 2001, 9999, 10000, 999999999999, 1000000000000, 4102444799, 4102444800, PHP_INT_MAX, PHP_INT_MIN][$random->getInt(0, 22)],
            2 => [1.5, 8.0, 85.25, -0.5, 10000.0, 1e30, true, false, [], ['id' => 3], [1, 2], new \stdClass][$random->getInt(0, 11)],
            default => self::randomText($random),
        };
    }

    /** @return array<array-key, mixed> */
    private static function randomBatch(Randomizer $random, int $index): array
    {
        $rows = [];
        for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
            $text = static fn (): mixed => self::randomScalar($random);
            $list = static fn (): array => array_map(static fn (): mixed => match ($random->getInt(0, 4)) {
                0 => ['id' => self::randomScalar($random)],
                1 => ['name' => self::randomScalar($random)],
                default => self::randomScalar($random),
            }, range(0, $random->getInt(0, 4)));
            $flag = static fn (): mixed => [true, false, 1, null, 'true'][$random->getInt(0, 4)];
            $row = match ($index) {
                0 => match ($random->getInt(0, 4)) {
                    0 => ['key' => $text()],
                    1 => ['key' => $text(), 'value' => $list()],
                    2 => ['key' => $text(), 'value' => ['id' => $text(), 'name' => $text()]],
                    default => ['key' => $text(), 'value' => $text()],
                },
                1 => ['name' => $text(), 'slug' => $text(), 'developer' => $flag(), 'publisher' => $flag(), 'porting' => $flag(), 'supporting' => $flag(), 'country' => $text()],
                2 => ['name' => $text(), 'summary' => $text(), 'storyline' => $text(), 'rating' => $text(), 'first_release_date' => $text(), 'genres' => $random->getInt(0, 1) ? $list() : $text(), 'platforms' => $random->getInt(0, 1) ? $list() : $text(), 'rating_count' => $text()],
                3 => ['name' => $text(), 'abbreviation' => $text(), 'alternative_name' => $text(), 'category' => $text(), 'generation' => $text()],
                default => ['imdbid' => $text(), 'tmdbid' => $text(), 'title' => $text(), 'year' => $text(), 'rating' => $text(), 'genre' => $text()],
            };
            $rows['key'.$j] = match ($random->getInt(0, 11)) {
                0 => self::randomScalar($random),
                1 => [],
                default => $row,
            };
        }

        return $rows;
    }

    public function test_raw_native_and_real_dispatch_randomized_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $i => [$class, $method, $symbol, , $switch]) {
            $ffi = self::ffi($class);
            $cases = [[], self::literalCases()[$i][0], self::benchmarkInput($i, false), self::benchmarkInput($i, true)];
            for ($trial = 0; $trial < 600; $trial++) {
                $cases[] = self::randomBatch($random, $i);
            }
            foreach ($cases as $input) {
                $expected = $class::{$method.'Php'}($input);
                self::assertSame($expected, self::rawResult($class, $method, $ffi, $symbol, $input), $class.' '.json_encode(self::fields($class, $input), JSON_INVALID_UTF8_SUBSTITUTE));
                self::assertSame($expected, $class::{$method.'Native'}($input));
                $class::{'useW48'.$switch.'RustFfi'}(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $switch, $ffi, $input, $expected);
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method, , , $switch, $constant]) {
            $class::{'resetW48'.$switch.'RustFfi'}();
            self::assertSame(self::DEFAULTS[$i], $class::{'usesW48'.$switch.'RustFfi'}());
            self::assertSame(self::DEFAULTS[$i], self::defaultEnabled($class, $constant));
            $ffi = self::ffi($class);
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            self::assertNotNull($out);
            FFI::memset($out, 90, 256);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            if (self::defaultEnabled($class, $constant)) {
                self::assertSame(pack('P*', ...array_values($expected)), FFI::string($out, 96));
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w48Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w48Ffi'))->getValue());
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

    private static function pairs(): int
    {
        return max(60, (int) (getenv('NNTMUX_W48_BENCH_PAIRS') ?: 2000));
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
            'paired_samples_per_workload' => self::pairs(),
            'order' => 'fixed-seed randomized paired; timings include PHP flattening, packing, FFI call and decoding',
            'p99' => 'nearest rank; approximate 95 percent binomial rank interval',
            'required_speedup' => 1.5,
            'source_sha256' => $hashes,
            'reproduction_command' => 'NNTMUX_W48_BENCH=1 php vendor/bin/phpunit tests/Unit/RustIgdbModelsBaseCompanyGamePlatformMovieBrowseW48ParityTest.php',
        ];
    }

    /** @return list<mixed> Typical production rows for each helper. */
    private static function commonSource(int $index): array
    {
        return match ($index) {
            0 => [
                ['key' => 'id', 'value' => 1942], ['key' => 'name', 'value' => 'The Witcher 3: Wild Hunt'], ['key' => 'slug', 'value' => 'the-witcher-3-wild-hunt'], ['key' => 'first_release_date', 'value' => 1431993600],
                ['key' => 'cover', 'value' => ['id' => 89386, 'image_id' => 'co1wyy']], ['key' => 'platforms', 'value' => [6, 48, 49, 130]], ['key' => 'genres', 'value' => [['id' => 12], ['id' => 31]]], ['key' => 'aggregated_rating', 'value' => 92.4],
                ['key' => 'summary', 'value' => 'RPG and sequel to The Witcher 2.'], ['key' => 'involved_companies', 'value' => [72060, 72061]], ['key' => 'updated_at', 'value' => 1700000000], ['key' => 'checksum', 'value' => 'ec8d6a2b-1c4e'],
            ],
            1 => [
                ['name' => 'CD Projekt RED', 'slug' => 'cd-projekt-red', 'developer' => true, 'publisher' => false, 'country' => 616],
                ['name' => 'Ubisoft Entertainment SA', 'slug' => 'ubisoft-entertainment', 'developer' => false, 'publisher' => true, 'country' => 250],
                ['name' => 'Nintendo Co., Ltd.', 'slug' => 'nintendo', 'developer' => true, 'publisher' => true, 'country' => 392],
                ['name' => 'Bandai Namco Entertainment Inc.', 'slug' => 'bandai-namco-entertainment', 'developer' => false, 'publisher' => true, 'country' => 392],
                ['name' => 'Panic Button', 'slug' => 'panic-button', 'developer' => false, 'publisher' => false, 'porting' => true, 'country' => 840],
                ['name' => 'Electronic Arts', 'slug' => 'electronic-arts', 'developer' => false, 'publisher' => true, 'country' => 840],
            ],
            2 => [
                ['name' => 'The Witcher 3: Wild Hunt', 'summary' => 'RPG and sequel to The Witcher 2 (2011) and the third game in The Witcher series, played in an open world.', 'storyline' => 'Geralt of Rivia searches for Ciri.', 'rating' => 93.47, 'first_release_date' => 1431993600, 'genres' => [['id' => 12], ['id' => 31]], 'platforms' => [['id' => 6], ['id' => 48], ['id' => 49], ['id' => 130]], 'rating_count' => 3021],
                ['name' => 'Halo: Reach', 'summary' => 'Prequel to the Halo trilogy.', 'rating' => 86.1, 'first_release_date' => 1284422400, 'genres' => [5], 'platforms' => [12, 49, 6], 'rating_count' => 820],
                ['name' => 'Super Mario World', 'summary' => '<p>Mario and Luigi travel to Dinosaur Land.</p>', 'rating' => 90.0, 'first_release_date' => 658972800, 'genres' => 'Platform', 'platforms' => 'SNES, Wii U', 'rating_count' => 990],
                ['name' => 'Final Fantasy VII', 'summary' => 'Cloud Strife joins AVALANCHE.', 'storyline' => 'Midgar.', 'rating' => 88, 'first_release_date' => 855619200, 'genres' => [['name' => 'Role-playing (RPG)']], 'platforms' => [7, 6], 'rating_count' => 1400],
            ],
            3 => [
                ['name' => 'PlayStation 4', 'abbreviation' => 'PS4', 'alternative_name' => 'PS4', 'category' => 1, 'generation' => 8],
                ['name' => 'Xbox Series X|S', 'abbreviation' => 'Series X|S', 'alternative_name' => 'XSX', 'category' => 1, 'generation' => 9],
                ['name' => 'Nintendo Switch', 'abbreviation' => 'Switch', 'alternative_name' => 'NX', 'category' => 5, 'generation' => 8],
                ['name' => 'PC (Microsoft Windows)', 'abbreviation' => 'PC', 'alternative_name' => 'mswin', 'category' => 6],
                ['name' => 'Sega Mega Drive/Genesis', 'abbreviation' => 'Genesis/MegaDrive', 'alternative_name' => 'Sega Genesis', 'category' => 1, 'generation' => 4],
                ['name' => 'Android', 'abbreviation' => 'Android', 'category' => 4],
                ['name' => 'Nintendo 3DS', 'abbreviation' => '3DS', 'category' => 5, 'generation' => 8],
                ['name' => 'Atari 2600', 'abbreviation' => 'Atari2600', 'category' => 1, 'generation' => 2],
            ],
            default => [
                ['imdbid' => '0111161', 'tmdbid' => 278, 'title' => 'The Shawshank Redemption', 'year' => '1994', 'rating' => '9.3', 'genre' => 'Drama'],
                ['imdbid' => '0068646', 'tmdbid' => 238, 'title' => 'The Godfather', 'year' => '1972', 'rating' => '9.2', 'genre' => 'Crime, Drama'],
                ['imdbid' => '0468569', 'tmdbid' => 155, 'title' => 'The Dark Knight', 'year' => '2008', 'rating' => '9.0', 'genre' => 'Action, Crime, Drama'],
                ['imdbid' => '1375666', 'tmdbid' => 27205, 'title' => 'Inception', 'year' => '2010', 'rating' => '8.8', 'genre' => 'Action|Adventure|Sci-Fi'],
                ['imdbid' => '0268978', 'tmdbid' => 453, 'title' => 'A Beautiful Mind', 'year' => '2001', 'rating' => '8.2', 'genre' => 'Biography / Drama'],
                ['imdbid' => '15398776', 'tmdbid' => 872585, 'title' => 'Oppenheimer', 'year' => '2023', 'rating' => '8.3', 'genre' => 'Biography, Drama, History'],
            ],
        };
    }

    /** Common batches hold 64 typical rows; uncommon batches hold 128 rows mixing the hand cases, malformed rows and long text.
     *
     * @return array<array-key, mixed>
     */
    private static function benchmarkInput(int $index, bool $uncommon): array
    {
        $source = self::commonSource($index);
        if ($uncommon) {
            $long = str_repeat("日本語 \xff Long_Entry-PS4, <b>Sci-Fi| ", 24);
            $source = [...$source, ...self::literalCases()[$index][0], $long, ['key' => $long, 'value' => [$long, 5, '6'], 'name' => $long.' Inc', 'slug' => $long, 'summary' => $long, 'storyline' => $long, 'genres' => $long, 'platforms' => [$long], 'abbreviation' => $long, 'alternative_name' => $long, 'title' => $long, 'genre' => $long, 'imdbid' => $long], null, 7];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 128 : 64; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W48_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W48_BENCH=1 for paired samples per helper/workload (NNTMUX_W48_BENCH_PAIRS, default 2000, minimum 60).');
        }
        $artifact = getenv('NNTMUX_W48_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w48-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave48.4 benchmark artifact: ".$artifact."\n");
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::HELPERS as $index => [$class, $method, $symbol, , $switch, $constant]) {
                $ffi = self::ffi($class);
                foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                    $args = self::benchmarkInput($index, $uncommon);
                    $pairs = [];
                    $expected = $class::{$method.'Php'}($args);
                    self::assertSame($expected, self::rawResult($class, $method, $ffi, $symbol, $args));
                    self::assertNativeDispatch($class, $method, $switch, $ffi, $args, $expected);
                    foreach ([false, true] as $native) {
                        $class::{'useW48'.$switch.'RustFfi'}($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0, $total = self::pairs(); $sample < $total; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::{'useW48'.$switch.'RustFfi'}($native);
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
                        'input_sha256' => hash('sha256', serialize(self::fields($class, $args))),
                        'php' => $php,
                        'rust' => $rust,
                        'mean_speedup' => $php['mean_ns'] / max(1, $rust['mean_ns']),
                        'p99_speedup' => $php['p99_ns'] / max(1, $rust['p99_ns']),
                        'conservative_p99_speedup' => $php['p99_approx_95pct_rank_interval_ns'][0] / max(1, $rust['p99_approx_95pct_rank_interval_ns'][1]),
                    ];
                }
                $ratios = [];
                foreach (['common', 'uncommon'] as $workload) {
                    $ratios[$workload.'_mean_speedup'] = round($report['summary'][$method][$workload]['mean_speedup'], 2);
                    $ratios[$workload.'_p99_speedup'] = round($report['summary'][$method][$workload]['p99_speedup'], 2);
                }
                $report['helper_qualification'][$method] = ['ratios' => $ratios, 'qualified' => min($ratios) >= 1.5];
                fwrite(STDERR, sprintf("%s: %s %s\n", $method, json_encode($ratios, JSON_THROW_ON_ERROR), json_encode(['common_us' => [round($report['summary'][$method]['common']['php']['mean_ns'] / 1000, 1), round($report['summary'][$method]['common']['rust']['mean_ns'] / 1000, 1)], 'uncommon_us' => [round($report['summary'][$method]['uncommon']['php']['mean_ns'] / 1000, 1), round($report['summary'][$method]['uncommon']['rust']['mean_ns'] / 1000, 1)]], JSON_THROW_ON_ERROR)));
                if (self::defaultEnabled($class, $constant)) {
                    self::assertTrue($report['helper_qualification'][$method]['qualified'], $method.' enabled without qualifying every mean/p99 gate.');
                }
            }
            $report['gate_passed'] = ! in_array(false, array_column($report['helper_qualification'], 'qualified'), true);
            $report['status'] = 'completed';
        } catch (\Throwable $failure) {
            $report['status'] = 'invalid';
            $report['failure'] = ['type' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            file_put_contents($artifact, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
