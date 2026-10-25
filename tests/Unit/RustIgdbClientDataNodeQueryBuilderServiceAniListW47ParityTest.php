<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\Client;
use App\Services\IGDB\DataNode;
use App\Services\IGDB\QueryBuilder;
use App\Services\IGDBService;
use App\Services\PopulateAniListService;
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
 * Wave 47.4 IGDB client request, DataNode field path, Apicalypse query
 * clause, IGDB candidate score and AniList media batch summaries. PHP
 * flattens each batch to a fixed number of strings per row, Rust streams the
 * serialize(list<string>) frame and writes twelve little-endian int64 values
 * that PHP decodes with one unpack('P*'). The opt-in benchmark keeps raw
 * randomized pairs, input/source/binary hashes, warmups and p99 rank
 * uncertainty.
 * Reproduce: NNTMUX_W47_BENCH=1 php vendor/bin/phpunit tests/Unit/RustIgdbClientDataNodeQueryBuilderServiceAniListW47ParityTest.php
 *
 * Seven runs at d1ef11308 + this change (Apple M5, PHP 8.5.12 without
 * opcache, rustc 1.99.1, release build, 3000 pairs per workload after 64
 * warmups per mode, host load average 6-10), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99):
 *   Client request 1.77/1.34, 1.80/1.63; DataNode field path 2.31/1.63, 1.94/1.63;
 *   QueryBuilder clause 6.23/4.42, 7.19/5.35; IGDBService candidate 2.74/2.27, 2.60/2.49;
 *   AniList media 2.16/1.82, 2.40/2.21.
 * Median-run means (microseconds, PHP vs Rust): request 21.8/12.2 and
 * 43.2/21.9; field path 28.3/12.2 and 44.1/22.6; query 103.5/16.3 and
 * 186.3/25.3; candidate 97.3/35.0 and 261.9/99.8; media 74.5/34.3 and
 * 218.5/89.7. Common batches hold 64 typical rows; uncommon batches hold
 * 128 rows mixing hand cases, malformed rows and long text. The client
 * request summary does little per-row work, so the shared PHP flattening
 * bounds it near 1.8x and it fell below 1.5x common p99 in three runs: it
 * stays on PHP by default. The other four default to Rust; PHP remains the
 * fallback when the library is unavailable.
 */
final class RustIgdbClientDataNodeQueryBuilderServiceAniListW47ParityTest extends TestCase
{
    /** [class, entry method, export, fields per row, switch suffix, default constant]. */
    private const array HELPERS = [
        [Client::class, 'summarizeRequestBatch', 'nntmux_w47_igdb_client_request_batch', 3, 'Request', 'W47_REQUEST_RUST_FFI_DEFAULT'],
        [DataNode::class, 'summarizeFieldPathBatch', 'nntmux_w47_igdb_datanode_field_path_batch', 2, 'FieldPath', 'W47_FIELD_PATH_RUST_FFI_DEFAULT'],
        [QueryBuilder::class, 'summarizeQueryClauseBatch', 'nntmux_w47_igdb_query_clause_batch', 1, 'QueryClause', 'W47_QUERY_CLAUSE_RUST_FFI_DEFAULT'],
        [IGDBService::class, 'scoreCandidateBatch', 'nntmux_w47_igdb_candidate_score_batch', 5, 'CandidateScore', 'W47_CANDIDATE_SCORE_RUST_FFI_DEFAULT'],
        [PopulateAniListService::class, 'summarizeMediaBatch', 'nntmux_w47_anilist_media_batch', 7, 'Media', 'W47_MEDIA_RUST_FFI_DEFAULT'],
    ];

    private const array DEFAULTS = [false, true, true, true, true];

    /** Earlier public APIs that must stay intact. */
    private const array LEGACY_METHODS = [
        Client::class => ['setUseRustFfi', 'usesRustFfi', 'buildQueryCacheKeyPhp', 'normalizeRequestTargetPhp', 'computeTokenTtlPhp', 'request'],
        DataNode::class => ['setUseRustFfi', 'usesRustFfi', 'isDateFieldPhp', 'resolveDateTimestampPhp', 'toArray'],
        QueryBuilder::class => ['queryFfi', 'toQueryPhp', 'toQueryNative', 'formatValuePhp', 'whereInNative'],
        IGDBService::class => ['setUseRustFfi', 'scoreCandidatesNative', 'normalizeForMatchNative', 'computeSimilarityNative', 'matchGenre', 'isKnownGenre'],
        PopulateAniListService::class => ['normalizerFfi', 'normalizeAniListMediaPhp', 'normalizeAniListMediaNative', 'searchAnime'],
    ];

    private const int SEED = 4742026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class, , , , $switch]) {
            $class::{'resetW47'.$switch.'RustFfi'}();
        }
        parent::tearDown();
    }

    public function test_required_additive_api_exists(): void
    {
        foreach (self::HELPERS as $index => [$class, $method, , , $switch, $constant]) {
            // ReflectionClass avoids method_exists() narrowing, which stalls PHPStan across these loops.
            $reflection = new ReflectionClass($class);
            foreach ([$method, $method.'Php', $method.'Native', 'useW47'.$switch.'RustFfi', 'usesW47'.$switch.'RustFfi', 'resetW47'.$switch.'RustFfi'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertSame(self::DEFAULTS[$index], $reflection->getConstant($constant));
            self::assertIsString($reflection->getConstant('W47_FFI_CDEF'));
            foreach (self::LEGACY_METHODS[$class] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
        }
    }

    /** @return list<array{array<array-key, mixed>, array<string, int>}> */
    private static function literalCases(): array
    {
        $digest = unpack('J', hash('fnv1a64', "games\ncovers\n", true));
        self::assertIsArray($digest);

        return [
            [[
                ['endpoint' => '/games', 'expires_in' => 5000000, 'status' => 200], ['endpoint' => 'covers/ ', 'expires_in' => '100', 'status' => '429'], ['endpoint' => 'Games/x', 'expires_in' => '1e3', 'status' => 503],
                ['endpoint' => '//', 'expires_in' => '0', 'status' => '404'], '', ['endpoint' => null, 'expires_in' => 1.5, 'status' => '2000'], new \stdClass,
            ], ['total_requests' => 7, 'empty_endpoint_count' => 4, 'known_endpoint_count' => 2, 'nested_endpoint_count' => 1, 'invalid_endpoint_count' => 1, 'token_ttl_sum' => 4999940 + 60 + 3540 + 60 + 3540 * 3, 'short_token_count' => 2, 'rate_limited_count' => 1, 'server_error_count' => 1, 'client_error_count' => 1, 'success_count' => 1, 'endpoint_digest' => $digest[1]]],
            [[
                ['path' => 'first_release_date', 'value' => 1700000000], ['path' => 'cover.image_id', 'value' => 'co1abc'], ['path' => 'platforms.*', 'value' => []], ['path' => 'involved_companies.company.name', 'value' => 5],
                ['path' => 'rating', 'value' => 87.5], ['path' => 'a..b', 'value' => 1], ['path' => '*.name'], ['path' => 'Name', 'value' => 'x'], 'cover', '', ['path' => 'created_at', 'value' => '2020'], ['path' => 'genres', 'value' => ['a' => 1]],
            ], ['total_paths' => 12, 'invalid_path_count' => 4, 'nested_path_count' => 3, 'deep_path_count' => 1, 'wildcard_path_count' => 1, 'date_leaf_count' => 2, 'known_leaf_count' => 7, 'type_match_count' => 3, 'type_mismatch_count' => 3, 'bad_char_count' => 1, 'depth_sum' => 12, 'timestamp_ready_count' => 1]],
            [[
                'fields name,cover.image_id; search "Halo; \\"CE\\""; where platforms = (6,48) & rating > 70 | id = 1; sort rating DESC; limit 10;',
                'fields *; where (a = 1;', 'FIELDS id; fields name;', 'fields id; bogus 1;', 'fields id; limit 501; sort a', '   ', 'fields id;;  ; offset 5;', 'fields id; search "open', 'sort x asc;', 'fields*;',
            ], ['total_queries' => 10, 'empty_query_count' => 1, 'valid_query_count' => 2, 'fields_query_count' => 7, 'search_query_count' => 2, 'where_query_count' => 2, 'valid_sort_count' => 2, 'valid_limit_count' => 1, 'unbalanced_count' => 2, 'unknown_keyword_count' => 2, 'clause_sum' => 20, 'where_condition_sum' => 4]],
            [[
                ['query' => 'Halo: Reach', 'name' => 'Halo Reach', 'platform' => 'x360', 'platforms' => [['name' => 'Xbox 360'], 'PC'], 'genres' => ['Shooter']],
                ['query' => 'The Witcher 3 GOTY', 'name' => 'Witcher 3: Wild Hunt', 'platform' => 'PC', 'platforms' => 'pc,ps4', 'genres' => 'role-playing, adventure'],
                ['query' => 'Halo', 'name' => 'Halo 2', 'platform' => 'ps3', 'platforms' => ['Xbox'], 'genres' => 'shooter'],
                ['query' => '!!!', 'name' => 'Doom', 'genres' => ' -action'], 'Doom', ['query' => 'Doom', 'name' => 'Doom', 'platform' => '', 'genres' => [5]],
            ], ['total_candidates' => 6, 'empty_title_count' => 2, 'exact_title_count' => 2, 'matched_count' => 2, 'platform_match_count' => 2, 'platform_mismatch_count' => 1, 'known_genre_count' => 4, 'score_sum' => 115 + 75 + 50 + 100, 'best_score' => 115, 'best_index' => 0, 'partial_match_count' => 4, 'title_score_sum' => 100 + 60 + 60 + 100]],
            [[
                ['title' => ['romaji' => "Shingeki  no\tKyojin", 'english' => 'Attack on Titan', 'native' => "\xe9\x80\xb2\xe6\x92\x83"], 'synonyms' => ['AoT', 'attack on titan', ' ', 7], 'description' => 'Humans <br>fight &amp; die.', 'averageScore' => 85, 'episodes' => 25],
                ['title' => ['romaji' => ' ', 'english' => null], 'synonyms' => "One\x1eTwo", 'description' => '&#x27; &#12;', 'averageScore' => '101', 'episodes' => '99999'],
                ['title' => 'flat', 'description' => 'a < b & c;', 'averageScore' => 0, 'episodes' => 120],
                'Bare Title', null,
            ], ['total_media' => 5, 'untitled_count' => 3, 'english_title_count' => 1, 'native_non_ascii_count' => 1, 'synonym_count' => 3, 'html_description_count' => 1, 'entity_description_count' => 2, 'scored_count' => 2, 'rank_score_sum' => (340 + 25 + 10 + 2) + 4 + 100, 'long_title_count' => 0, 'sanitized_title_count' => 2, 'max_rank_score' => 377]],
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
                $class::{'useW47'.$switch.'RustFfi'}($enabled);
                self::assertSame($expected, $class::{$method}($input));
                self::assertSame($empty, $class::{$method}([]));
            }
        }
    }

    public function test_summaries_agree_with_real_callers(): void
    {
        // Token TTLs follow computeTokenTtl() for strict digit strings.
        foreach (['61', '120', '121', '3600', '999999999'] as $expires) {
            self::assertSame(Client::computeTokenTtlPhp($expires), Client::summarizeRequestBatchPhp([['endpoint' => 'games', 'expires_in' => $expires]])['token_ttl_sum']);
        }
        // Date leaves agree with DataNode::isDateFieldPhp() on its IGDB date fields.
        $dates = ['created_at', 'updated_at', 'first_release_date', 'date', 'start_date'];
        foreach ([...$dates, 'name', 'rating'] as $leaf) {
            self::assertSame((int) DataNode::isDateFieldPhp($leaf, $dates), DataNode::summarizeFieldPathBatchNative([['path' => 'game.'.$leaf, 'value' => 1]])['date_leaf_count']);
        }
        // toQueryPhp() output is always a valid, fully terminated Apicalypse body.
        $builder = (new ReflectionClass(QueryBuilder::class))->newInstanceWithoutConstructor();
        foreach (['fields' => ['name', 'cover.image_id'], 'whereClauses' => ['platforms = (6,48)', 'rating > 70'], 'searchTerm' => 'Halo "CE"; \\ x', 'sort' => 'rating desc', 'limitValue' => 10] as $property => $value) {
            (new ReflectionProperty(QueryBuilder::class, $property))->setValue($builder, $value);
        }
        $summary = QueryBuilder::summarizeQueryClauseBatchNative([$builder->toQueryPhp().';']);
        self::assertSame([1, 1, 1, 1, 1, 1, 0, 5, 2], [$summary['valid_query_count'], $summary['fields_query_count'], $summary['search_query_count'], $summary['where_query_count'], $summary['valid_sort_count'], $summary['valid_limit_count'], $summary['unbalanced_count'], $summary['clause_sum'], $summary['where_condition_sum']]);
        // Known genres mirror matchGenre()/isKnownGenre().
        $service = (new ReflectionClass(IGDBService::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(IGDBService::class, $service);
        foreach (['shooter', 'role-playing', 'open-world', 'board games, x', 'x, puzzle', 'nothing', '', 'point & click'] as $genre) {
            $known = false;
            foreach (explode(',', str_replace('-', ' ', $genre)) as $part) {
                $known = $known || $service->isKnownGenre(ucwords(trim($part))) !== false;
            }
            self::assertSame((int) $known, IGDBService::scoreCandidateBatchNative([['genres' => $genre]])['known_genre_count'], $genre);
        }
        // Platform aliases follow normalizePlatformHint() for ASCII hints.
        $normalize = new ReflectionMethod(IGDBService::class, 'normalizePlatformHint');
        foreach ([['x360', 'Xbox 360'], [' PS3 ', 'PlayStation 3'], ['gc', 'GameCube'], ['snes', 'Super Nintendo'], ['psp', 'PSP']] as [$hint, $platform]) {
            $expected = $normalize->invoke($service, $hint) === $normalize->invoke($service, $platform);
            self::assertSame((int) $expected, IGDBService::scoreCandidateBatchNative([['query' => 'x', 'name' => 'x', 'platform' => $hint, 'platforms' => [$platform]]])['platform_match_count'], $hint);
        }
        // AniList English-title and averageScore counters follow normalizeAniListMediaPhp().
        $anilist = (new ReflectionClass(PopulateAniListService::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(PopulateAniListService::class, $anilist);
        $media = ['id' => 1, 'title' => ['romaji' => 'Kimi no Na wa', 'english' => 'Your Name'], 'averageScore' => 85, 'description' => '<i>x</i>'];
        $normalized = $anilist->normalizeAniListMediaPhp($media);
        $summary = PopulateAniListService::summarizeMediaBatchNative([$media]);
        self::assertSame(count(array_filter($normalized['titles'], static fn (array $t): bool => $t['lang'] === 'en')), $summary['english_title_count']);
        self::assertSame($normalized['rating'] !== null ? 1 : 0, $summary['scored_count']);
        self::assertSame($normalized['description'] !== $media['description'] ? 1 : 0, $summary['html_description_count']);
    }

    public function test_switches_are_independent_and_missing_library_falls_back(): void
    {
        foreach (self::HELPERS as $index => [$class, , , , $switch]) {
            $class::{'resetW47'.$switch.'RustFfi'}();
            self::assertSame(self::DEFAULTS[$index], $class::{'usesW47'.$switch.'RustFfi'}());
            $class::{'useW47'.$switch.'RustFfi'}(false);
        }
        $legacy = [Client::usesRustFfi(), DataNode::usesRustFfi()];
        foreach (self::HELPERS as $index => [$class, , , , $switch]) {
            $class::{'useW47'.$switch.'RustFfi'}();
            foreach (self::HELPERS as [$other, , , , $otherSwitch]) {
                self::assertSame($class === $other, $other::{'usesW47'.$otherSwitch.'RustFfi'}());
            }
            $class::{'useW47'.$switch.'RustFfi'}(null);
            self::assertSame(self::DEFAULTS[$index], $class::{'usesW47'.$switch.'RustFfi'}(), 'null restores the default.');
            $class::{'useW47'.$switch.'RustFfi'}(true);
            $class::{'resetW47'.$switch.'RustFfi'}();
            self::assertSame(self::DEFAULTS[$index], $class::{'usesW47'.$switch.'RustFfi'}());
            $class::{'useW47'.$switch.'RustFfi'}(false);
            self::assertFalse($class::{'usesW47'.$switch.'RustFfi'}());
        }
        self::assertSame($legacy, [Client::usesRustFfi(), DataNode::usesRustFfi()], 'W47 switches must not touch earlier toggles.');
        foreach (self::HELPERS as $i => [$class, $method, , , $switch]) {
            $class::{'useW47'.$switch.'RustFfi'}(true);
            (new ReflectionProperty($class, 'w47Ffi'))->setValue(null, false);
            [$input, $expected] = self::literalCases()[$i];
            self::assertSame($expected, $class::{$method}($input));
            self::assertSame($expected, $class::{$method.'Native'}($input));
            self::assertFalse((new ReflectionProperty($class, 'w47Ffi'))->getValue());
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
        $cdef = (new ReflectionClass($class))->getConstant('W47_FFI_CDEF');
        self::assertIsString($cdef);
        $ffi = RustPipelineLibrary::resolveDomain('metadata_w47', $cdef);
        self::assertNotNull($ffi, 'Build the release pipeline library with every W47 symbol.');

        return $ffi;
    }

    /** @return list<int|string> */
    private static function fields(string $class, mixed $input): array
    {
        return (new ReflectionMethod($class, 'w47Fields'))->invoke(null, $input);
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
        $class::{'useW47'.$switch.'RustFfi'}(true);
        self::assertSame($expected, $class::{$method}($input));
        self::assertSame(pack('P*', ...array_values($expected)), FFI::string($out, 96), 'Public dispatch must write the Rust output, rather than fall back.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w47Ffi'))->getValue());
    }

    public function test_malformed_frames_are_rejected_without_writing(): void
    {
        $malformed = ['', 'N;', 'a:1:{i:0;', 'a:01:{i:0;s:0:"";}', 'a:1:{i:0;s:0:"";}trailing', 'a:1:{i:1;s:0:"";}', 'a:1:{i:0;N;}', 'a:1:{i:0;i:05;}', 'a:1:{i:0;i:-0;}', 'a:1:{i:0;i:;}', 'a:1:{i:0;i:-;}', 'a:1:{i:0;i:5}', 'a:1:{i:0;i:12345678901234567890;}', 'a:1:{i:0;d:5;}', 'a:1:{s:1:"x";s:0:"";}', 'a:1:{i:0;a:0:{}}', 'a:1:{i:0;s:5:"x";}', 'a:2:{i:0;s:0:"";}', serialize([['x']]), serialize(['a' => 'x']), serialize([1 => 'x'])];
        foreach (self::HELPERS as [$class, , $symbol, $stride]) {
            $ffi = self::ffi($class);
            $out = $ffi->new('char[256]');
            $partial = $stride > 1 ? [serialize(array_fill(0, $stride - 1, 'x')), serialize(array_fill(0, $stride + 1, 'x'))] : [];
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
            foreach ([0, 5, -5, 100, 429, 5184000, PHP_INT_MAX, PHP_INT_MIN] as $int) {
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
        $fields = ['endpoint', 'expires_in', 'status', 'path', 'value', 'query', 'name', 'platform', 'platforms', 'genres', 'title', 'synonyms', 'description', 'averageScore', 'episodes'];
        $large = str_repeat('x', 16777217);
        foreach (self::HELPERS as [$class, $method, , , $switch]) {
            $input = [$object, array_fill_keys($fields, $object), ['title' => ['romaji' => $object], 'synonyms' => [$object], 'platforms' => [$object, ['name' => $object]]]];
            $class::{'useW47'.$switch.'RustFfi'}(true);
            self::assertSame($class::{$method.'Php'}($input), $class::{$method}($input));
            $class::{'resetW47'.$switch.'RustFfi'}();
            $class::{'useW47'.$switch.'RustFfi'}(true);
            $oversized = [$large, array_fill_keys($fields, $large)];
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method}($oversized));
            self::assertSame($class::{$method.'Php'}($oversized), $class::{$method.'Native'}($oversized));
            self::assertNull((new ReflectionProperty($class, 'w47Ffi'))->getValue(), 'Size guard must run before resolving FFI.');
        }
    }

    private const array VOCABULARY = [
        // Shared separators, control bytes, invalid UTF-8 and boundary text.
        '', ' ', '  ', '.', '..', '-', '_', '/', '//', ',', ':', '!', '?', '&', '|', '(', ')', '"', '\\', ';', '<', '>', '#', '*', "\0", "\t", "\v", "\f", "\n", "\r", "\x01", "\x1e", "\x1f", "\x7f", "\xa0", "\xff", "\xc3\xa9", '日本語',
        // Client endpoints and statuses.
        'games', 'covers', 'Games', 'multiquery', 'search', 'v4', '200', '204', '404', '429', '500', '3600', '60', '120', '121', '1e3',
        // DataNode paths.
        'cover', 'image_id', 'first_release_date', 'created_at', 'platforms', 'involved_companies', 'company', 'name', 'rating', 'id', 'Name',
        // Apicalypse clauses.
        'fields', 'FIELDS', 'exclude', 'where', 'sort', 'limit', 'offset', 'bogus', 'asc', 'DESC', 'desc', '500', '501', '0', '10', '= (6,48)', '>', '"Halo"', '\\"',
        // Titles, platforms and genres.
        'Halo', 'Reach', 'The', 'Witcher', '3', 'GOTY', 'Edition', 'of', 'a', 'x360', 'Xbox 360', 'ps3', 'PlayStation 3', 'PC', 'snes', 'Super Nintendo', 'shooter', 'role-playing', 'Open World', 'point & click', 'action',
        // AniList text.
        'Shingeki', 'no', 'Kyojin', 'Attack on Titan', '<br>', '<i>', '</i>', '&amp;', '&#39;', '&#x27;', '&#;', '&x', '85', '100', '101', '25', '9999',
    ];

    private static function randomText(Randomizer $random, int $max = 6): string
    {
        $text = '';
        for ($k = 0, $n = $random->getInt(0, $max); $k < $n; $k++) {
            $text .= self::VOCABULARY[$random->getInt(0, count(self::VOCABULARY) - 1)];
            if ($random->getInt(0, 2) === 0) {
                $text .= [' ', '.', ';', ',', '/', '-', '_', "\0", "\xff", '"', '(', ')', '&', '9', 'a', 'X'][$random->getInt(0, 15)];
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
            1 => $random->getInt(0, 1) ? $random->getInt(-5, 5000000) : [-1, 0, 99, 100, 101, 200, 299, 429, 503, 999, 1000, 9999, 10000, 999999999, 1000000000, PHP_INT_MAX, PHP_INT_MIN][$random->getInt(0, 16)],
            2 => [1.5, true, false, [], ['k' => 1], [1, 2], new \stdClass][$random->getInt(0, 6)],
            default => self::randomText($random),
        };
    }

    /** @return array<array-key, mixed> */
    private static function randomBatch(Randomizer $random, int $index): array
    {
        $rows = [];
        for ($j = 0, $n = $random->getInt(0, 24); $j < $n; $j++) {
            $text = static fn (): mixed => self::randomScalar($random);
            $list = static fn (): array => array_map(static fn (): mixed => $random->getInt(0, 3) === 0 ? ['name' => self::randomScalar($random)] : self::randomScalar($random), range(0, $random->getInt(0, 3)));
            $row = match ($index) {
                0 => ['endpoint' => $text(), 'expires_in' => $text(), 'status' => $random->getInt(0, 1) ? [200, 204, 404, 429, 500, 503, '429', '2000', 99][$random->getInt(0, 8)] : $text()],
                1 => $random->getInt(0, 3) === 0 ? ['path' => $text()] : ['path' => $text(), 'value' => $text()],
                2 => $text(),
                3 => ['query' => $text(), 'name' => $text(), 'platform' => $text(), 'platforms' => $random->getInt(0, 1) ? $list() : $text(), 'genres' => $random->getInt(0, 1) ? $list() : $text()],
                default => ['title' => $random->getInt(0, 5) === 0 ? $text() : ['romaji' => $text(), 'english' => $text(), 'native' => $text()], 'synonyms' => $random->getInt(0, 1) ? $list() : $text(), 'description' => $text(), 'averageScore' => $text(), 'episodes' => $text()],
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
                $class::{'useW47'.$switch.'RustFfi'}(false);
                self::assertSame($expected, $class::{$method}($input));
                self::assertNativeDispatch($class, $method, $switch, $ffi, $input, $expected);
            }
        }
    }

    public function test_benchmark_qualified_defaults_dispatch_without_overrides(): void
    {
        foreach (self::HELPERS as $i => [$class, $method, , , $switch, $constant]) {
            $class::{'resetW47'.$switch.'RustFfi'}();
            self::assertSame(self::DEFAULTS[$i], $class::{'usesW47'.$switch.'RustFfi'}());
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
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w47Ffi'))->getValue());
            } else {
                self::assertNull((new ReflectionProperty($class, 'w47Ffi'))->getValue());
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
        return max(60, (int) (getenv('NNTMUX_W47_BENCH_PAIRS') ?: 2000));
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
            'reproduction_command' => 'NNTMUX_W47_BENCH=1 php vendor/bin/phpunit tests/Unit/RustIgdbClientDataNodeQueryBuilderServiceAniListW47ParityTest.php',
        ];
    }

    /** @return list<mixed> Typical production rows for each helper. */
    private static function commonSource(int $index): array
    {
        return match ($index) {
            0 => [
                ['endpoint' => 'games', 'expires_in' => 5184000, 'status' => 200], ['endpoint' => '/covers', 'expires_in' => 5183990, 'status' => 200], ['endpoint' => 'platforms/', 'expires_in' => '5184000', 'status' => 200],
                ['endpoint' => 'involved_companies', 'expires_in' => 3600, 'status' => 429], ['endpoint' => 'search', 'expires_in' => 5184000, 'status' => 200], ['endpoint' => 'games', 'expires_in' => 5184000, 'status' => 500],
                ['endpoint' => 'alternative_names', 'expires_in' => 5184000, 'status' => 200], ['endpoint' => 'screenshots', 'expires_in' => 5184000, 'status' => 401],
            ],
            1 => [
                ['path' => 'id', 'value' => 1942], ['path' => 'name', 'value' => 'The Witcher 3: Wild Hunt'], ['path' => 'first_release_date', 'value' => 1431993600], ['path' => 'cover.image_id', 'value' => 'co1wyy'],
                ['path' => 'platforms', 'value' => [6, 48, 49]], ['path' => 'involved_companies.company.name', 'value' => 'CD Projekt RED'], ['path' => 'aggregated_rating', 'value' => 92.4], ['path' => 'genres.*', 'value' => []],
                ['path' => 'alternative_names.name', 'value' => 'Wiedzmin 3'], ['path' => 'release_dates.date', 'value' => 1431993600], ['path' => 'summary', 'value' => 'RPG'], ['path' => 'cover', 'value' => ['id' => 1, 'image_id' => 'co1wyy']],
            ],
            2 => [
                "fields name,slug,first_release_date,cover.image_id,platforms.name,genres.name;\nsearch \"The Witcher 3\";\nwhere platforms = (6,48,49) & version_parent = null;\nlimit 10;",
                "fields *;\nwhere id = (1942,1020,7346);\nsort first_release_date desc;\nlimit 50;",
                "fields name,alternative_names.name,involved_companies.company.name;\nsearch \"Halo: \\\"Reach\\\"\";\nlimit 25;",
                "fields name,aggregated_rating;\nwhere aggregated_rating > 80 & aggregated_rating_count > 10 | category = 0;\nsort aggregated_rating desc;\nlimit 500;",
            ],
            3 => [
                ['query' => 'The Witcher 3 Wild Hunt GOTY', 'name' => 'The Witcher 3: Wild Hunt', 'platform' => 'PC', 'platforms' => [['name' => 'PC (Microsoft Windows)', 'abbreviation' => 'PC'], ['name' => 'PlayStation 4']], 'genres' => [['name' => 'Role-playing (RPG)'], ['name' => 'Adventure']]],
                ['query' => 'The Witcher 3 Wild Hunt GOTY', 'name' => 'The Witcher 3: Wild Hunt - Blood and Wine', 'platform' => 'PC', 'platforms' => [['name' => 'PC']], 'genres' => 'Role-Playing, Adventure'],
                ['query' => 'Halo Reach', 'name' => 'Halo: Reach', 'platform' => 'x360', 'platforms' => [['name' => 'Xbox 360'], ['name' => 'Xbox One']], 'genres' => [['name' => 'Shooter']]],
                ['query' => 'Halo Reach', 'name' => 'Halo: The Master Chief Collection', 'platform' => 'x360', 'platforms' => [['name' => 'Xbox One']], 'genres' => [['name' => 'Shooter']]],
                ['query' => 'Super Mario World', 'name' => 'Super Mario World', 'platform' => 'snes', 'platforms' => [['name' => 'Super Nintendo Entertainment System'], ['name' => 'SNES']], 'genres' => 'Platformer'],
                ['query' => 'Final Fantasy VII Remake', 'name' => 'Final Fantasy VII', 'platform' => 'PS4', 'platforms' => [['name' => 'PlayStation']], 'genres' => [['name' => 'Role-playing (RPG)']]],
            ],
            default => [
                ['id' => 16498, 'idMal' => 16498, 'title' => ['romaji' => 'Shingeki no Kyojin', 'english' => 'Attack on Titan', 'native' => '進撃の巨人'], 'synonyms' => ['AoT', 'SnK', 'L\'Attaque des Titans'], 'description' => 'Several hundred years ago, humans were nearly exterminated by Titans.<br><br>\n(Source: Crunchyroll)', 'averageScore' => 85, 'episodes' => 25, 'genres' => ['Action', 'Drama']],
                ['id' => 21, 'title' => ['romaji' => 'ONE PIECE', 'english' => 'ONE PIECE', 'native' => 'ONE PIECE'], 'synonyms' => ['OP', 'ワンピース'], 'description' => 'Gold Roger was known as the &quot;Pirate King&quot;.', 'averageScore' => 88, 'episodes' => null],
                ['id' => 1535, 'title' => ['romaji' => 'Death Note', 'english' => 'Death Note', 'native' => 'デスノート'], 'synonyms' => ['DN'], 'description' => 'Light Yagami is an ace student.<br>', 'averageScore' => 84, 'episodes' => 37],
                ['id' => 101922, 'title' => ['romaji' => 'Kimetsu no Yaiba', 'english' => 'Demon Slayer: Kimetsu no Yaiba', 'native' => '鬼滅の刃'], 'synonyms' => [], 'description' => '<i>It is the Taisho Period in Japan.</i>', 'averageScore' => 83, 'episodes' => 26],
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
            $long = str_repeat("日本語 \xff Long_Entry-x360; <b>&amp; ", 24);
            $source = [...$source, ...self::literalCases()[$index][0], $long, ['endpoint' => $long, 'path' => $long, 'query' => $long, 'name' => $long.' Halo', 'platform' => 'x360', 'platforms' => [$long], 'genres' => $long, 'title' => ['romaji' => $long, 'english' => $long], 'synonyms' => [$long, $long], 'description' => $long], null, 7];
        }
        $rows = [];
        for ($i = 0, $n = $uncommon ? 128 : 64; $i < $n; $i++) {
            $rows[] = $source[$i % count($source)];
        }

        return $rows;
    }

    public function test_opt_in_paired_public_dispatch_benchmark(): void
    {
        if (getenv('NNTMUX_W47_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W47_BENCH=1 for paired samples per helper/workload (NNTMUX_W47_BENCH_PAIRS, default 2000, minimum 60).');
        }
        $artifact = getenv('NNTMUX_W47_BENCH_ARTIFACT') ?: tempnam(sys_get_temp_dir(), 'nntmux-w47-bench-');
        self::assertNotFalse($artifact);
        $report = ['metadata' => self::benchmarkMetadata(), 'status' => 'running', 'gate_passed' => false, 'helper_qualification' => [], 'summary' => [], 'raw_pairs' => []];
        fwrite(STDERR, "\nWave47.4 benchmark artifact: ".$artifact."\n");
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
                        $class::{'useW47'.$switch.'RustFfi'}($native);
                        for ($warmup = 0; $warmup < 64; $warmup++) {
                            self::assertSame($expected, $class::{$method}($args));
                        }
                    }
                    for ($sample = 0, $total = self::pairs(); $sample < $total; $sample++) {
                        $order = $random->getInt(0, 1) ? [false, true] : [true, false];
                        $pair = ['order' => $order[0] ? 'rust-first' : 'php-first'];
                        foreach ($order as $native) {
                            $class::{'useW47'.$switch.'RustFfi'}($native);
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
                fwrite(STDERR, sprintf("%s: %s\n", $method, json_encode($ratios, JSON_THROW_ON_ERROR)));
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
