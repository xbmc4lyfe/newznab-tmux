<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\Pipes\AbstractApiProviderPipe;
use App\Services\TvProcessing\Pipes\AbstractTvProviderPipe;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Pipes\ParseInfoPipe;
use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use Tests\TestCase;

final class RustTvPipesApiAbstractLocalDbParseInfoTmdbW48ParityTest extends TestCase
{
    /** Per class: [default constant, toggle suffix, batch entry]. */
    private const TARGETS = [
        AbstractApiProviderPipe::class => ['W48_API_PROVIDER_RUST_FFI_DEFAULT', 'ApiProvider', 'w48GateBatch'],
        AbstractTvProviderPipe::class => ['W48_TV_PROVIDER_RUST_FFI_DEFAULT', 'TvProvider', 'w48RoutingBatch'],
        LocalDbPipe::class => ['W48_LOCAL_DB_RUST_FFI_DEFAULT', 'LocalDb', 'w48CandidateBatch'],
        ParseInfoPipe::class => ['W48_PARSE_INFO_RUST_FFI_DEFAULT', 'ParseInfo', 'w48TokenBatch'],
        TmdbPipe::class => ['W48_TMDB_RUST_FFI_DEFAULT', 'Tmdb', 'w48ScoreBatch'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        AbstractApiProviderPipe::class => false,
        AbstractTvProviderPipe::class => false,
        LocalDbPipe::class => true,
        ParseInfoPipe::class => true,
        TmdbPipe::class => true,
    ];

    private const WORDS = ['Show', 'Name', 'The', 'Office', '.', ' ', '_', '-', '[', ']', '(', ')', 'S01E02', 's3e10', '3x07', 'S02',
        'E03', 'e4', '2024', '01', '15', '1999', '13', '32', 'US', 'UK', '720p', 'x264', "\xC3\xA9", "\xff", '0', 'S01E01E02', 's1e',
        '12x345', '2023.11.07', 'tt', "\t"];

    private const PROVIDERS = ['TVDB', 'tvmaze', 'TMDB', 'Trakt', 'Local DB', 'LOCAL DB', 'existing', '', "\xC3\x89"];

    private const STATUSES = ['matched', 'season_pack', 'episode_missing', 'not_found', 'parse_failed', 'transient', 'skipped', 'pending',
        'MATCHED', '', 'weird', "matched\n"];

    protected function tearDown(): void
    {
        AbstractApiProviderPipe::useW48ApiProviderRustFfi(null);
        AbstractTvProviderPipe::useW48TvProviderRustFfi(null);
        LocalDbPipe::useW48LocalDbRustFfi(null);
        ParseInfoPipe::useW48ParseInfoRustFfi(null);
        TmdbPipe::useW48TmdbRustFfi(null);
        parent::tearDown();
    }

    public function test_w48_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$constant, $suffix, $entry]) {
            $reflection = new ReflectionClass($class);
            foreach (["useW48{$suffix}RustFfi", "usesW48{$suffix}RustFfi", $entry, $entry.'Php', $entry.'Native', 'w48RustFfi'] as $method) {
                if (! $reflection->hasMethod($method)) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach ([$constant, 'W48_FFI_CDEF'] as $name) {
                if (! $reflection->hasConstant($name)) {
                    $missing[] = $class.'::'.$name;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_w48_defaults_and_routing(): void
    {
        foreach (self::TARGETS as $class => [$constant, $suffix]) {
            $this->assertSame(self::DEFAULTS[$class], \constant($class.'::'.$constant), $class);
            $this->assertSame(\constant($class.'::'.$constant), $class::{"usesW48{$suffix}RustFfi"}(), $class);
            $class::{"useW48{$suffix}RustFfi"}(true);
            $this->assertTrue($class::{"usesW48{$suffix}RustFfi"}());
            $class::{"useW48{$suffix}RustFfi"}(false);
            $this->assertFalse($class::{"usesW48{$suffix}RustFfi"}());
            $class::{"useW48{$suffix}RustFfi"}(null);
            $this->assertSame(self::DEFAULTS[$class], $class::{"usesW48{$suffix}RustFfi"}());
        }

        $names = ['Show.S01E02', 'Talk.2023.11.07.Guest', ''];
        $expected = ParseInfoPipe::w48TokenBatchPhp($names);
        foreach ([true, false] as $enable) {
            ParseInfoPipe::useW48ParseInfoRustFfi($enable);
            $this->assertSame($expected, ParseInfoPipe::w48TokenBatch($names));
        }
        $items = [['status' => 'matched', 'provider' => 'TVDB', 'confidence' => 90, 'priority' => 1]];
        $expected = AbstractTvProviderPipe::w48RoutingBatchPhp($items);
        foreach ([true, false] as $enable) {
            AbstractTvProviderPipe::useW48TvProviderRustFfi($enable);
            $this->assertSame($expected, AbstractTvProviderPipe::w48RoutingBatch($items));
        }
    }

    public function test_w48_exports_resolve_when_library_is_built(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w48', ParseInfoPipe::W48_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
        $missing = [];
        foreach (array_keys(self::TARGETS) as $class) {
            if (RustPipelineLibrary::resolveDomain('categorize_w48', \constant($class.'::W48_FFI_CDEF')) === null) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing);
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w48', ParseInfoPipe::W48_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('char[104]');
        // @phpstan-ignore method.notFound
        $this->assertSame(104, $ffi->nntmux_w48_tv_parse_info_token_batch('Show.S01E02', 11, 1, $out, 104));
        $this->assertSame(array_values((array) unpack('P*', FFI::string($out, 104))), ParseInfoPipe::w48TokenBatchPhp(['Show.S01E02']));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w48_tv_parse_info_token_batch("a\0b", 3, 1, $out, 104));
        // @phpstan-ignore method.notFound
        $this->assertSame(-104, $ffi->nntmux_w48_tv_parse_info_token_batch('Show.S01E02', 11, 1, $out, 8));
    }

    public function test_api_provider_gate_parity(): void
    {
        $empty = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $this->assertSame($empty, AbstractApiProviderPipe::w48GateBatchPhp([], 1000));
        $this->assertSame($empty, AbstractApiProviderPipe::w48GateBatchNative([], 1000));
        $fixed = [
            ['provider' => 'TVDB', 'title' => 'Show.Name', 'configured' => 0],
            ['provider' => 'tvdb', 'title' => 'Show', 'configured' => 1, 'failures' => 3, 'last_failure_at' => 900],
            ['provider' => 'TMDB', 'title' => 'Show', 'configured' => 1, 'failures' => 1, 'last_failure_at' => 990, 'retry_after' => 30],
            ['provider' => 'x', 'title' => 'Show', 'configured' => 1, 'not_found_at' => 5],
            ['provider' => 'x', 'title' => '...', 'configured' => 1],
            ['provider' => 'x', 'title' => 'Show', 'configured' => 1],
            ['provider' => 'x', 'title' => 'Show', 'configured' => 1, 'has_show' => 1, 'season_pack' => 1],
            ['provider' => 'x', 'title' => 'Show', 'configured' => true, 'has_show' => '1', 'site_id' => -3],
            ['provider' => 'Trakt', 'title' => "Caf\xC3\xA9 (2020)", 'configured' => 1, 'has_show' => 1, 'site_id' => 7],
            [],
            ['provider' => null, 'title' => 123, 'configured' => '9abc', 'failures' => PHP_INT_MAX, 'last_failure_at' => PHP_INT_MAX, 'retry_after' => PHP_INT_MIN],
        ];
        $php = AbstractApiProviderPipe::w48GateBatchPhp($fixed, 1000);
        $this->assertSame([0, 0, $this->fnv('tvdb:show name'), 9], \array_slice($php, 0, 4));
        $this->assertSame([1, 200, 2, 20, 3, 20605, 4, 0, 5, 0, 6, 0, 7, 0, 8, 0], array_merge(...array_map(
            fn (int $i): array => \array_slice($php, $i * 4, 2),
            range(1, 8),
        )));
        $this->assertSame([2, 2, 1, 1, 1, 1, 1, 1, 1, (1 << 52) - 700, 5], \array_slice($php, 44));

        $batches = [$fixed, [['provider' => "nul\0", 'configured' => 1]], [['title' => "a\0b"]]];
        $random = new Randomizer(new Mt19937(4831));
        foreach ([64, 96, 128] as $size) {
            foreach ([[0, 6], [30, 80]] as [$min, $max]) {
                $batches[] = array_map(fn (): array => [
                    'provider' => $this->pick($random, self::PROVIDERS),
                    'title' => $this->randomName($random, $min, $max),
                    'configured' => $random->getInt(0, 3),
                    'has_show' => $random->getInt(0, 1),
                    'season_pack' => $random->getInt(0, 1),
                    'site_id' => $random->getInt(-2, 5),
                    'failures' => $random->getInt(-1, 6),
                    'last_failure_at' => 1_700_000_000 - $random->getInt(-50, 900),
                    'retry_after' => $random->getInt(-5, 900),
                    'not_found_at' => $random->getInt(0, 1) * (1_700_000_000 - $random->getInt(0, 30000)),
                ], range(1, $size));
            }
        }
        foreach ($batches as $i => $items) {
            foreach ([0, 1000, 1_700_000_000, PHP_INT_MAX, PHP_INT_MIN] as $now) {
                $this->assertSame(
                    AbstractApiProviderPipe::w48GateBatchPhp($items, $now),
                    AbstractApiProviderPipe::w48GateBatchNative($items, $now),
                    "batch {$i} now {$now}",
                );
            }
        }
    }

    public function test_tv_provider_routing_parity(): void
    {
        $empty = [0, 0, 0, 0, -1, 0, 0];
        $this->assertSame($empty, AbstractTvProviderPipe::w48RoutingBatchPhp([]));
        $this->assertSame($empty, AbstractTvProviderPipe::w48RoutingBatchNative([]));
        $fixed = [
            ['status' => 'matched', 'provider' => 'TVDB', 'confidence' => 100, 'priority' => 5],
            ['status' => 'matched', 'provider' => 'Trakt', 'confidence' => 90, 'priority' => 1],
            ['status' => 'transient', 'provider' => 'tvmaze', 'retry_after' => 9_999_999],
            ['status' => 'weird', 'provider' => 'x'],
            ['status' => 'season_pack', 'provider' => 'local db', 'confidence' => 79, 'priority' => -4],
            ['status' => 'season_pack', 'provider' => 'LOCAL DB', 'confidence' => 80],
            ['status' => 'parse_failed'],
            ['status' => 'MATCHED', 'provider' => 'TMDB', 'confidence' => PHP_INT_MAX],
            [],
            ['status' => 'not_found', 'provider' => "\xC3\x89", 'confidence' => PHP_INT_MIN, 'priority' => PHP_INT_MAX],
        ];
        $php = AbstractTvProviderPipe::w48RoutingBatchPhp($fixed);
        $this->assertSame([0, 0, 95, $this->fnv("tvdb\x1Fmatched\x1F0")], \array_slice($php, 0, 4));
        $this->assertSame([0, 0, 72], \array_slice($php, 4, 3));
        $this->assertSame([5, 2], \array_slice($php, 8, 2));
        $this->assertSame([1, 3, 59], \array_slice($php, 16, 3));
        $this->assertSame([4, 1, 1, 4, 0, 86400], \array_slice($php, 40, 6));

        $batches = [$fixed, [['status' => "nul\0"]], [['provider' => "a\0"]]];
        $random = new Randomizer(new Mt19937(4832));
        foreach ([64, 128] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = array_map(fn (): array => [
                    'status' => $noisy && $random->getInt(0, 2) === 0 ? $this->randomName($random, 1, 4) : $this->pick($random, self::STATUSES),
                    'provider' => $noisy ? $this->randomName($random, 1, 10) : $this->pick($random, self::PROVIDERS),
                    'confidence' => $random->getInt(-10, 120),
                    'priority' => $random->getInt(-3, 60),
                    'retry_after' => $random->getInt(-100, 100_000),
                ], range(1, $size));
            }
        }
        foreach ($batches as $i => $items) {
            $this->assertSame(AbstractTvProviderPipe::w48RoutingBatchPhp($items), AbstractTvProviderPipe::w48RoutingBatchNative($items), "batch {$i}");
        }
    }

    public function test_local_db_candidate_parity(): void
    {
        $empty = [0, 0, 0, -1, 0];
        $this->assertSame($empty, LocalDbPipe::w48CandidateBatchPhp([]));
        $this->assertSame($empty, LocalDbPipe::w48CandidateBatchNative([]));
        $fixed = [
            ['title' => 'The Office', 'candidate_title' => 'the.office', 'season' => 1, 'episode' => 2, 'candidate_season' => 1,
                'candidate_episode' => 2, 'tvdb' => 10, 'candidate_tvdb' => 10],
            ['title' => 'Show A B', 'candidate_title' => 'Show C', 'season' => -1, 'episode' => 3, 'tvdb' => 7, 'candidate_tvdb' => 8],
            ['title' => 'Talk Show', 'candidate_title' => 'Talk Show', 'season' => -1, 'episode' => -1, 'airdate' => '2024-01-02',
                'candidate_airdate' => '2024-01-02'],
            ['title' => '', 'candidate_title' => '', 'season' => -1, 'episode' => -1],
            ['title' => 'a a b', 'candidate_title' => 'b b c', 'season' => -1, 'episode' => -1, 'tmdb' => 3, 'candidate_tmdb' => 3],
            [],
            ['title' => 7, 'candidate_title' => '7', 'season' => PHP_INT_MIN, 'episode' => PHP_INT_MAX, 'tvdb' => PHP_INT_MAX, 'candidate_tvdb' => PHP_INT_MAX],
        ];
        $php = LocalDbPipe::w48CandidateBatchPhp($fixed);
        $this->assertSame([100, 2, 180, 2, $this->fnv('the office')], \array_slice($php, 0, 5));
        $this->assertSame([25, 0, -35, 0], \array_slice($php, 5, 4));
        $this->assertSame([100, 1, 140, 1], \array_slice($php, 10, 4));
        $this->assertSame([0, 0, 0, 0, $this->fnv('')], \array_slice($php, 15, 5));
        $this->assertSame([33, 0, 63, 0], \array_slice($php, 20, 4));

        $batches = [$fixed, [['title' => "nul\0"]], [['candidate_airdate' => "\0"]]];
        $random = new Randomizer(new Mt19937(4833));
        foreach ([64, 128] as $size) {
            foreach ([[0, 6], [20, 60]] as [$min, $max]) {
                $batches[] = array_map(fn (): array => [
                    'title' => $this->randomName($random, $min, $max),
                    'candidate_title' => $this->randomName($random, $min, $max),
                    'airdate' => $random->getInt(0, 1) === 1 ? '2020-01-0'.$random->getInt(1, 3) : '',
                    'candidate_airdate' => $random->getInt(0, 1) === 1 ? '2020-01-0'.$random->getInt(1, 3) : '',
                    'season' => $random->getInt(-1, 2),
                    'episode' => $random->getInt(-1, 2),
                    'candidate_season' => $random->getInt(-1, 2),
                    'candidate_episode' => $random->getInt(-1, 2),
                    'tvdb' => $random->getInt(-1, 3),
                    'candidate_tvdb' => $random->getInt(-1, 3),
                    'tmdb' => $random->getInt(0, 3),
                    'candidate_tmdb' => $random->getInt(0, 3),
                ], range(1, $size));
            }
        }
        foreach ($batches as $i => $items) {
            $this->assertSame(LocalDbPipe::w48CandidateBatchPhp($items), LocalDbPipe::w48CandidateBatchNative($items), "batch {$i}");
        }
    }

    public function test_parse_info_token_parity(): void
    {
        $this->assertSame([0, 0, 0, 0, 0], ParseInfoPipe::w48TokenBatchPhp([]));
        $this->assertSame([0, 0, 0, 0, 0], ParseInfoPipe::w48TokenBatchNative([]));
        $fixed = [
            'Show.Name.2019.S02E05E06.720p', 'Show 3x07', 'Show.S03.1080p', 'Talk.2023.11.07.Guest', 'S01E01-E03', 'Plain.Title.1999',
            '', '...', '1923.2022.S01.COMPLETE', 'Show.S01E05-E03', 'News.2024.13.01.x', 'News.2024.02.32', 'Show.s1e', 'Show.123x45',
            'Show.S1234E1', 'Show.S01E12345', 'Show.12x4567', "Caf\xC3\xA9.UK.s01e02", "bad.\xff.S02E03", "tab\tname\nS01E01", '1999',
        ];
        $php = ParseInfoPipe::w48TokenBatchPhp($fixed);
        $this->assertSame([2, 2, 5, 6, 0, 2019, $this->fnv('show name'), 9], \array_slice($php, 0, 8));
        $this->assertSame([1, 3, 7, 7], \array_slice($php, 8, 4));
        $this->assertSame([3, 3, -1, -1], \array_slice($php, 16, 4));
        $this->assertSame([4, -1, -1, -1, 20231107, 0], \array_slice($php, 24, 6));
        $this->assertSame([2, 1, 1, 3, 0, 0, $this->fnv(''), 0], \array_slice($php, 32, 8));
        $this->assertSame([0, -1, -1, -1, 0, 1999, $this->fnv('plain title'), 11], \array_slice($php, 40, 8));
        $this->assertSame([3, 1, -1, -1, 0, 2022, $this->fnv('1923'), 4], \array_slice($php, 64, 8));

        $batches = [$fixed, [''], ["with\0nul.S01E01", 'x'], ["\0"]];
        $random = new Randomizer(new Mt19937(4834));
        foreach ([64, 96, 128] as $size) {
            $batches[] = array_map(fn (): string => $this->randomName($random, 2, 10), range(1, $size));
            $batches[] = array_map(fn (): string => $this->randomName($random, 30, 80), range(1, $size));
        }
        foreach ($batches as $i => $names) {
            $this->assertSame(ParseInfoPipe::w48TokenBatchPhp($names), ParseInfoPipe::w48TokenBatchNative($names), "batch {$i}");
        }
    }

    public function test_tmdb_score_parity(): void
    {
        $this->assertSame([0, 0, 0], TmdbPipe::w48ScoreBatchPhp([]));
        $this->assertSame([0, 0, 0], TmdbPipe::w48ScoreBatchNative([]));
        $fixed = [
            ['query' => 'Show', 'name' => 'Show', 'first_air_date' => '2010-05-01', 'query_country' => 'US', 'origin_country' => 'US',
                'imdb_id' => 'tt123', 'group' => 1, 'query_year' => 2010, 'popularity' => 500, 'vote_count' => 100, 'tvdb_id' => 1],
            ['query' => 'Show', 'name' => 'Show Name', 'first_air_date' => '2011', 'query_country' => 'us', 'origin_country' => 'US',
                'imdb_id' => 'tt', 'group' => 1, 'query_year' => 2010],
            ['query' => 'Other', 'name' => 'Nope', 'first_air_date' => 'x', 'group' => 2],
            ['query' => 'The Office US', 'name' => 'Office', 'original_name' => 'The Office', 'query_season' => 9, 'season_count' => 3,
                'imdb_id' => "tt1\n", 'group' => 3, 'query_year' => 2005, 'first_air_date' => '2001-07-09'],
            ['query' => 'Show', 'name' => 'Show', 'group' => 3, 'popularity' => -5, 'query_year' => PHP_INT_MAX, 'first_air_date' => '9999'],
            [],
            ['query' => 'a b c d', 'name' => 'c d e', 'group' => PHP_INT_MIN, 'query_year' => PHP_INT_MIN, 'vote_count' => PHP_INT_MAX],
        ];
        $php = TmdbPipe::w48ScoreBatchPhp($fixed);
        $this->assertSame([155, 100, 0, 1], \array_slice($php, 0, 4));
        $this->assertSame([100, 80, 1, 0], \array_slice($php, 4, 4));
        $this->assertSame([0, 0, -1, 0], \array_slice($php, 8, 4));
        $this->assertSame([80 - 15 - 20, 80, 4, 0], \array_slice($php, 12, 4));
        $this->assertSame([120, 100, 0, 1], \array_slice($php, 16, 4));
        $this->assertSame([62, 57, -1, 0], \array_slice($php, 24, 4));
        $this->assertSame([2, 5, 155], \array_slice($php, 28));

        $batches = [$fixed, [['query' => "nul\0"]], [['imdb_id' => "\0"]]];
        $random = new Randomizer(new Mt19937(4835));
        foreach ([64, 128] as $size) {
            foreach ([[0, 5], [10, 40]] as [$min, $max]) {
                $batches[] = array_map(fn (): array => [
                    'query' => $this->randomName($random, $min, $max),
                    'name' => $this->randomName($random, $min, $max),
                    'original_name' => $random->getInt(0, 1) === 1 ? $this->randomName($random, $min, $max) : '',
                    'first_air_date' => $random->getInt(0, 3) > 0 ? $random->getInt(1998, 2026).'-01-01' : $this->randomName($random, 0, 2),
                    'query_country' => $this->pick($random, ['US', 'us', '', 'UK']),
                    'origin_country' => $this->pick($random, ['US', 'GB', '', 'uk']),
                    'imdb_id' => $this->pick($random, ['tt123', 'tt', '', 'TT1', "tt1\n"]),
                    'group' => $random->getInt(0, 8),
                    'query_year' => $random->getInt(-1, 1) * $random->getInt(1998, 2026),
                    'query_season' => $random->getInt(-1, 5),
                    'popularity' => $random->getInt(-5, 200),
                    'vote_count' => $random->getInt(0, 300),
                    'tvdb_id' => $random->getInt(-1, 3),
                    'season_count' => $random->getInt(-1, 4),
                ], range(1, $size));
            }
        }
        foreach ($batches as $i => $items) {
            $this->assertSame(TmdbPipe::w48ScoreBatchPhp($items), TmdbPipe::w48ScoreBatchNative($items), "batch {$i}");
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including column extraction,
        // packing, the FFI call and decoding (release dylib, 5 warmups, 601 paired samples per helper and workload,
        // 20 iterations per common sample and 10 per uncommon sample).
        // Common = one 64-128 item batch of typical inputs; uncommon = 128 long, noisy and malformed inputs.
        // Speedup = PHP latency / Rust latency, ranges over 7 rounds (PHP 8.5.12 NTS, release dylib, Apple M5, shared host at
        // load average ~10-45):
        // - ApiProvider: common mean 2.04-3.15x / p99 1.84-3.38x, uncommon mean 1.60-1.80x / p99 1.44-1.81x
        // - TvProvider:  common mean 2.25-2.37x / p99 1.48-2.36x, uncommon mean 2.38-2.54x / p99 1.89-2.59x
        // - LocalDb:     common mean 2.60-2.74x / p99 2.17-3.10x, uncommon mean 1.89-2.17x / p99 1.72-3.31x
        // - ParseInfo:   common mean 7.45-8.35x / p99 5.95-7.69x, uncommon mean 4.58-5.11x / p99 3.55-4.87x
        // - Tmdb:        common mean 2.83-3.02x / p99 2.17-2.90x, uncommon mean 2.01-2.17x / p99 1.74-2.07x
        // ApiProvider (uncommon p99) and TvProvider (common p99) each missed a gate in one round and keep PHP;
        // LocalDb, ParseInfo and Tmdb clear every gate in every round and default to Rust.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustTvPipesApiAbstractLocalDbParseInfoTmdbW48ParityTest.php --filter benchmark
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per .ai/rules/rust-performance.md.
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w48', ParseInfoPipe::W48_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
        $random = new Randomizer(new Mt19937(4836));
        $warmup = 5;
        $samples = 601;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [, $suffix, $entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(4837)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(4838)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $class::{"useW48{$suffix}RustFfi"}($useRust);
                        $sink += \count($class::$entry(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $class::{"useW48{$suffix}RustFfi"}($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::{"useW48{$suffix}RustFfi"}(null);
                $speedups[$suffix][$name] = [
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
        fwrite(STDERR, "\nRustTvPipesApiAbstractLocalDbParseInfoTmdbW48 speedup: {$summary}\n");
        foreach (self::TARGETS as $class => [$constant, $suffix]) {
            $qualifies = true;
            foreach ($speedups[$suffix] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            if (\constant($class.'::'.$constant) === true) {
                $this->assertTrue($qualifies, $class.' defaults to Rust only at 1.5x or more: '.$summary);
            }
        }
    }

    /**
     * Common: typical batches (96 gate items, 64 routing results, 128 local candidates, 128 release names, 96 TMDB
     * results). Uncommon: 128 items with long, noisy or malformed strings and out-of-range numbers.
     *
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        [$min, $max] = $uncommon ? [30, 80] : [2, 6];
        $range = static fn (int $n): array => range(1, $n);

        return match ($class) {
            AbstractApiProviderPipe::class => [array_map(fn (): array => [
                'provider' => $this->pick($random, self::PROVIDERS),
                'title' => $this->randomName($random, $min, $max),
                'configured' => $uncommon ? $random->getInt(0, 3) : 1,
                'has_show' => $random->getInt(0, 1),
                'season_pack' => $random->getInt(0, 4) === 0 ? 1 : 0,
                'site_id' => $random->getInt($uncommon ? -2 : 0, 500),
                'failures' => $random->getInt(0, $uncommon ? 6 : 2),
                'last_failure_at' => 1_700_000_000 - $random->getInt($uncommon ? -50 : 0, 900),
                'retry_after' => $uncommon ? $random->getInt(-5, 900) : 0,
                'not_found_at' => $random->getInt(0, 3) === 0 ? 1_700_000_000 - $random->getInt(0, 30000) : 0,
            ], $range($uncommon ? 128 : 96)), 1_700_000_000],
            AbstractTvProviderPipe::class => [array_map(fn (): array => [
                'status' => $uncommon && $random->getInt(0, 2) === 0 ? $this->randomName($random, 1, 4) : $this->pick($random, self::STATUSES),
                'provider' => $uncommon ? $this->randomName($random, 1, 10) : $this->pick($random, self::PROVIDERS),
                'confidence' => $random->getInt($uncommon ? -10 : 40, $uncommon ? 120 : 100),
                'priority' => $random->getInt(1, 60),
                'retry_after' => $random->getInt($uncommon ? -100 : 0, $uncommon ? 100_000 : 300),
            ], $range($uncommon ? 128 : 64))],
            LocalDbPipe::class => [array_map(fn (): array => [
                'title' => $this->randomName($random, $min, $max),
                'candidate_title' => $this->randomName($random, $min, $max),
                'airdate' => $random->getInt(0, 1) === 1 ? '2020-01-0'.$random->getInt(1, 3) : '',
                'candidate_airdate' => $random->getInt(0, 1) === 1 ? '2020-01-0'.$random->getInt(1, 3) : '',
                'season' => $random->getInt(-1, 3),
                'episode' => $random->getInt(-1, 12),
                'candidate_season' => $random->getInt(1, 3),
                'candidate_episode' => $random->getInt(1, 12),
                'tvdb' => $random->getInt(0, 3),
                'candidate_tvdb' => $random->getInt(0, 3),
                'tmdb' => $random->getInt(0, 3),
                'candidate_tmdb' => $random->getInt(0, 3),
            ], $range(128))],
            ParseInfoPipe::class => [array_map(fn (): string => $uncommon
                ? $this->randomName($random, 30, 80)
                : $this->pick($random, ['The.Office.US', 'Show.Name', 'Talk.Show', 'Some.Long.Series.Title']).'.'
                    .$this->pick($random, ['S0'.$random->getInt(1, 9).'E'.$random->getInt(10, 24), $random->getInt(1, 9).'x'.$random->getInt(10, 24),
                        'S0'.$random->getInt(1, 9), '2024.0'.$random->getInt(1, 9).'.1'.$random->getInt(0, 9)])
                    .'.720p.WEB.x264-GRP', $range(128))],
            default => [array_map(fn (): array => [
                'query' => $this->randomName($random, $min, $max),
                'name' => $this->randomName($random, $min, $max),
                'original_name' => $random->getInt(0, 1) === 1 ? $this->randomName($random, $min, $max) : '',
                'first_air_date' => $random->getInt(1998, 2026).'-01-01',
                'query_country' => $this->pick($random, ['US', 'us', '', 'UK']),
                'origin_country' => $this->pick($random, ['US', 'GB', '', 'uk']),
                'imdb_id' => $this->pick($random, ['tt123', 'tt', '', 'TT1', "tt1\n"]),
                'group' => $random->getInt(0, $uncommon ? 64 : 8),
                'query_year' => $random->getInt(-1, 1) * $random->getInt(1998, 2026),
                'query_season' => $random->getInt(-1, 5),
                'popularity' => $random->getInt(-5, 200),
                'vote_count' => $random->getInt(0, 300),
                'tvdb_id' => $random->getInt(-1, 3),
                'season_count' => $random->getInt(-1, 4),
            ], $range($uncommon ? 128 : 96))],
        };
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    private function fnv(string $bytes): int
    {
        return (int) hexdec(hash('fnv1a32', $bytes));
    }

    /**
     * @param  list<string>  $values
     */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function randomName(Randomizer $random, int $minWords, int $maxWords): string
    {
        $name = '';
        for ($i = $random->getInt($minWords, $maxWords); $i > 0; $i--) {
            $name .= self::WORDS[$random->getInt(0, \count(self::WORDS) - 1)];
        }

        return $name;
    }
}
