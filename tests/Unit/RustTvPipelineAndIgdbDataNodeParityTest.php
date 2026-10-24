<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\DataNode;
use App\Services\TvMazeClient;
use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Pipes\AbstractApiProviderPipe;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvProcessingRunState;
use App\Services\TvProcessing\TvReleaseContext;
use App\Support\Data\SteamPriceData;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 15 Slice 6:
 * TvProcessingPipeline, TvProcessingPassable, TvProcessingResult, AbstractApiProviderPipe,
 * TvMazeClient, IGDB\DataNode, SteamPriceData, and MetadataSources.
 *
 * Benchmarks run only with NNTMUX_TV_PIPELINE_IGDB_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_TV_PIPELINE_IGDB_BENCH=1 php artisan test --compact tests/Unit/RustTvPipelineAndIgdbDataNodeParityTest.php
 *
 * Recorded end-to-end PHP vs Rust FFI speedups (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                             | Common (mean / p99) | Uncommon (mean / p99) | Default    |
 * |----------------------------------------------------|---------------------|-----------------------|------------|
 * | TvProcessingPipeline::classifyResultStatus         | 0.40x / 0.36x       | 0.41x / 0.40x         | PHP (false)|
 * | TvProcessingPipeline::formatSummaryHeader          | 0.55x / 0.58x       | 0.56x / 0.56x         | PHP (false)|
 * | TvProcessingPipeline::formatProviderSummary        | 0.40x / 0.41x       | 0.50x / 0.47x         | PHP (false)|
 * | TvProcessingPassable::isResolved                   | 0.33x / 0.32x       | 0.33x / 0.30x         | PHP (false)|
 * | TvProcessingPassable::finalResult                  | 0.45x / 0.47x       | 0.39x / 0.39x         | PHP (false)|
 * | TvProcessingResult::isMatched                      | 0.37x / 0.38x       | 0.34x / 0.33x         | PHP (false)|
 * | AbstractApiProviderPipe::formatSeasonEpisodeKey    | 0.24x / 0.25x       | 0.26x / 0.28x         | PHP (false)|
 * | AbstractApiProviderPipe::formatMissingSiteIdReason | 0.20x / 0.21x       | 0.20x / 0.21x         | PHP (false)|
 * | TvMazeClient::extractAkaNames                      | 0.18x / 0.18x       | 0.18x / 0.20x         | PHP (false)|
 * | TvMazeClient::normalizeAirdate                     | 1.58x / 1.37x       | 0.96x / 0.80x         | PHP (false)|
 * | IGDB\DataNode::resolveDateTimestamp                | 0.15x / 0.15x       | 0.21x / 0.21x         | PHP (false)|
 * | SteamPriceData::fromApiResponse                    | 0.16x / 0.17x       | 0.19x / 0.20x         | PHP (false)|
 * | SteamPriceData::getSavings                         | 0.26x / 0.27x       | 0.38x / 0.37x         | PHP (false)|
 * | SteamPriceData::getDisplayPrice                    | 0.16x / 0.15x       | 0.37x / 0.35x         | PHP (false)|
 * | MetadataSources::missingKey                        | 0.80x / 0.76x       | 0.79x / 0.78x         | PHP (false)|
 * | MetadataSources::disabled                          | 0.81x / 0.81x       | 0.81x / 0.80x         | PHP (false)|
 * | MetadataSources::summaryLines                      | 0.74x / 0.73x       | 0.75x / 0.73x         | PHP (false)|
 */
class RustTvPipelineAndIgdbDataNodeParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED = [
        TvProcessingPipeline::class,
        TvProcessingPassable::class,
        TvProcessingResult::class,
        AbstractApiProviderPipe::class,
        TvMazeClient::class,
        DataNode::class,
        SteamPriceData::class,
        MetadataSources::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', TvProcessingPipeline::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED as $class) {
            $class::setUseRustFfi(null);
            (new \ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP until its Rust path passes the 1.5x gate");
        }
    }

    // ---------------------------------------------------------------------
    // 1. TvProcessingPipeline
    // ---------------------------------------------------------------------

    public function test_pipeline_classify_result_status_and_update_stats_parity(): void
    {
        $statuses = [
            TvProcessingResult::STATUS_MATCHED,
            TvProcessingResult::STATUS_SEASON_PACK,
            TvProcessingResult::STATUS_EPISODE_MISSING,
            TvProcessingResult::STATUS_TRANSIENT,
            TvProcessingResult::STATUS_NOT_FOUND,
            TvProcessingResult::STATUS_PARSE_FAILED,
            TvProcessingResult::STATUS_SKIPPED,
            TvProcessingResult::STATUS_PENDING,
            '',
            'MATCHED',
            'matched ',
            "matched\0",
            'unknown_status',
        ];

        foreach ($statuses as $status) {
            $expected = TvProcessingPipeline::classifyResultStatusPhp($status);
            $this->assertSame($expected, TvProcessingPipeline::classifyResultStatusNative($status), bin2hex($status));
            foreach ([true, false, null] as $toggle) {
                TvProcessingPipeline::setUseRustFfi($toggle);
                $this->assertSame($expected, TvProcessingPipeline::classifyResultStatus($status));
            }
        }

        $results = [
            ['status' => TvProcessingResult::STATUS_MATCHED, 'provider' => 'TVDB'],
            ['status' => TvProcessingResult::STATUS_SEASON_PACK, 'provider' => 'TVMaze'],
            ['status' => TvProcessingResult::STATUS_MATCHED, 'provider' => 'TVDB'],
            ['status' => TvProcessingResult::STATUS_MATCHED],
            ['status' => TvProcessingResult::STATUS_EPISODE_MISSING, 'provider' => 'TVDB'],
            ['status' => TvProcessingResult::STATUS_TRANSIENT, 'provider' => 'TMDB'],
            ['status' => TvProcessingResult::STATUS_NOT_FOUND],
            ['status' => TvProcessingResult::STATUS_PARSE_FAILED],
            [],
        ];

        $runWithToggle = function (bool $toggle) use ($results): array {
            TvProcessingPipeline::setUseRustFfi($toggle);
            $pipeline = new TvProcessingPipeline([], false);
            $update = Closure::bind(function (array $r): void {
                $this->updateStats($r);
            }, $pipeline, TvProcessingPipeline::class);
            foreach ($results as $r) {
                $update($r);
            }

            return $pipeline->getStats();
        };

        $phpStats = $runWithToggle(false);
        $rustStats = $runWithToggle(true);
        $this->assertSame($phpStats, $rustStats);
        $this->assertSame(9, $rustStats['processed']);
        $this->assertSame(4, $rustStats['matched']);
        $this->assertSame(1, $rustStats['episode_missing']);
        $this->assertSame(1, $rustStats['deferred']);
        $this->assertSame(3, $rustStats['not_found']);
        $this->assertSame(['TVDB' => 2, 'TVMaze' => 1, 'unknown' => 1], $rustStats['providers']);
    }

    public function test_pipeline_format_summary_header_and_providers_parity(): void
    {
        $durations = [0.0, -0.0, -0.001, 0.005, 0.015, 0.125, 0.135, 1.25, 12.3456, 99999.995, INF, -INF, NAN, 1e15];
        foreach ($durations as $idx => $duration) {
            $stats = [
                'processed' => $idx * 10,
                'matched' => $idx * 4,
                'episode_missing' => $idx * 2,
                'not_found' => $idx * 3,
                'deferred' => $idx,
                'duration' => $duration,
            ];
            $expected = TvProcessingPipeline::formatSummaryHeaderPhp($stats);
            $this->assertSame($expected, TvProcessingPipeline::formatSummaryHeaderNative($stats), "duration idx {$idx}");
            foreach ([true, false, null] as $toggle) {
                TvProcessingPipeline::setUseRustFfi($toggle);
                $this->assertSame($expected, TvProcessingPipeline::formatSummaryHeader($stats));
            }
        }

        $providerCases = [
            'empty' => [],
            'single' => ['TVDB' => 5],
            'multiple' => ['Local DB' => 12, 'TVDB' => 8, 'TVMaze' => 3, 'TMDB' => 1],
            'int keys' => [0 => 4, 2 => 9],
            'unicode and negative' => ['TVMaze 日本' => -2, 'Trakt' => 0],
            'many providers' => array_combine(
                array_map(static fn (int $i): string => "Provider_{$i}", range(1, 60)),
                range(1, 60),
            ),
        ];

        foreach ($providerCases as $label => $providers) {
            $expected = TvProcessingPipeline::formatProviderSummaryPhp($providers);
            $this->assertSame($expected, TvProcessingPipeline::formatProviderSummaryNative($providers), $label);
            foreach ([true, false, null] as $toggle) {
                TvProcessingPipeline::setUseRustFfi($toggle);
                $this->assertSame($expected, TvProcessingPipeline::formatProviderSummary($providers));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. TvProcessingPassable & 3. TvProcessingResult
    // ---------------------------------------------------------------------

    public function test_passable_is_resolved_and_final_result_parity(): void
    {
        $context = new TvReleaseContext(42, 'Severance.S01E02.1080p', 1, 5000);
        $parsed = new ParsedTvRelease('Severance', 'severance', '', 1, 2, false, null, null);

        foreach ([false, true] as $parseFailed) {
            foreach ([false, true] as $hasParsed) {
                foreach ([0, 15] as $videoId) {
                    foreach ([-1, 0, 99] as $episodeId) {
                        foreach ([false, true] as $seasonPack) {
                            foreach ([[], ['tvdb'], ['tvdb', 'tvmaze', 'tvdb', 'trakt']] as $transients) {
                                $passable = new TvProcessingPassable($context, new TvProcessingRunState, true);
                                if ($hasParsed) {
                                    $passable->setParsed($parsed);
                                }
                                if ($parseFailed) {
                                    $passable->markParseFailed();
                                }
                                if ($videoId > 0) {
                                    $passable->setShow($videoId, 'TVDB');
                                }
                                if ($episodeId !== 0) {
                                    $passable->episodeId = $episodeId;
                                    $passable->matchedBy = 'TVMaze';
                                }
                                if ($seasonPack) {
                                    $passable->markSeasonPack('TMDB');
                                }
                                foreach ($transients as $tp) {
                                    $passable->markTransient($tp, 120);
                                }

                                $expectedResolved = $passable->isResolvedPhp();
                                $this->assertSame($expectedResolved, $passable->isResolvedNative());

                                $expectedCode = TvProcessingPassable::resolveFinalOutcomePhp(
                                    $passable->parseFailed,
                                    $passable->parsed !== null,
                                    $passable->videoId,
                                    $passable->episodeId,
                                    $passable->seasonPack,
                                    $passable->transientProviders !== [],
                                );
                                $this->assertSame(
                                    $expectedCode,
                                    TvProcessingPassable::resolveFinalOutcomeNative(
                                        $passable->parseFailed,
                                        $passable->parsed !== null,
                                        $passable->videoId,
                                        $passable->episodeId,
                                        $passable->seasonPack,
                                        $passable->transientProviders !== [],
                                    )
                                );

                                $phpFinal = $passable->finalResultPhp();
                                $rustFinal = $passable->finalResultNative();
                                $this->assertEquals($phpFinal, $rustFinal);

                                foreach ([true, false, null] as $toggle) {
                                    TvProcessingPassable::setUseRustFfi($toggle);
                                    $this->assertSame($expectedResolved, $passable->isResolved());
                                    $this->assertEquals($phpFinal, $passable->finalResult());
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function test_passable_deduplicate_transient_providers_parity(): void
    {
        $cases = [
            [],
            ['tvdb'],
            ['tvdb', 'tvmaze', 'tvdb', 'trakt', 'tvmaze'],
            ['0', '00', '1', '01', '0', '00'],
            ['Ünïcödé', 'TVMaze', 'Ünïcödé'],
            ["with\0nul", 'tvdb', "with\0nul"],
            array_map(static fn (int $i): string => 'prov_'.($i % 15), range(1, 80)),
        ];

        foreach ($cases as $idx => $providers) {
            $expected = TvProcessingPassable::deduplicateTransientProvidersPhp($providers);
            $this->assertSame($expected, TvProcessingPassable::deduplicateTransientProvidersNative($providers), "case {$idx}");
            foreach ([true, false, null] as $toggle) {
                TvProcessingPassable::setUseRustFfi($toggle);
                $this->assertSame($expected, TvProcessingPassable::deduplicateTransientProviders($providers));
            }
        }
    }

    public function test_result_is_matched_parity(): void
    {
        $results = [
            TvProcessingResult::matched(10, 20, 'TVDB'),
            TvProcessingResult::seasonPack(10, 'TVDB'),
            TvProcessingResult::episodeMissing(10, 'TVDB'),
            TvProcessingResult::notFound('TVDB'),
            TvProcessingResult::parseFailed(),
            TvProcessingResult::transient('TVDB', 60),
            TvProcessingResult::skipped('not configured', 'TVDB'),
            TvProcessingResult::pending(),
            new TvProcessingResult('MATCHED'),
            new TvProcessingResult("matched\0"),
            new TvProcessingResult(''),
        ];

        foreach ($results as $idx => $result) {
            $expected = $result->isMatchedPhp();
            $this->assertSame($expected, $result->isMatchedNative(), "result {$idx}");
            $this->assertSame(
                TvProcessingResult::isMatchedStatusPhp($result->status),
                TvProcessingResult::isMatchedStatusNative($result->status),
            );
            foreach ([true, false, null] as $toggle) {
                TvProcessingResult::setUseRustFfi($toggle);
                $this->assertSame($expected, $result->isMatched());
                $this->assertSame($expected, TvProcessingResult::isMatchedStatus($result->status));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. AbstractApiProviderPipe
    // ---------------------------------------------------------------------

    public function test_api_provider_pipe_format_helpers_parity(): void
    {
        $seasonEpisodes = [
            [0, 1],
            [1, 2],
            [12, 34],
            [-1, 0],
            [100, 999],
            [PHP_INT_MAX, PHP_INT_MIN],
        ];

        foreach ($seasonEpisodes as [$season, $episode]) {
            $expected = AbstractApiProviderPipe::formatSeasonEpisodeKeyPhp($season, $episode);
            $this->assertSame($expected, AbstractApiProviderPipe::formatSeasonEpisodeKeyNative($season, $episode));
            foreach ([true, false, null] as $toggle) {
                AbstractApiProviderPipe::setUseRustFfi($toggle);
                $this->assertSame($expected, AbstractApiProviderPipe::formatSeasonEpisodeKey($season, $episode));
            }
        }

        $columns = ['tvdb', 'tvmaze', 'tmdb', 'trakt', '', 'custom_site_id', str_repeat('col_', 80)];
        foreach ($columns as $col) {
            $expected = AbstractApiProviderPipe::formatMissingSiteIdReasonPhp($col);
            $this->assertSame($expected, AbstractApiProviderPipe::formatMissingSiteIdReasonNative($col));
            foreach ([true, false, null] as $toggle) {
                AbstractApiProviderPipe::setUseRustFfi($toggle);
                $this->assertSame($expected, AbstractApiProviderPipe::formatMissingSiteIdReason($col));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 5. TvMazeClient
    // ---------------------------------------------------------------------

    public function test_tvmaze_extract_aka_names_and_normalize_airdate_parity(): void
    {
        $akaCases = [
            'empty' => [],
            'typical' => [
                ['name' => 'Haus des Geldes', 'country' => ['name' => 'Germany', 'code' => 'DE']],
                ['name' => 'La Casa de Papel', 'country' => null],
                ['name' => ''],
                ['country' => 'US'],
            ],
            'non string and non array' => [
                ['name' => 123],
                ['name' => null],
                ['name' => true],
                'not_an_array',
                null,
                ['name' => 'Valid Show'],
            ],
            'object element fallback' => [
                (object) ['name' => 'Object Show'],
                ['name' => 'Array Show'],
            ],
            'nul byte fallback' => [
                ['name' => "Null\0Show"],
                ['name' => 'Second Show'],
            ],
            'many akas' => array_map(
                static fn (int $i): array => ['name' => "AKA Title {$i} 日本語", 'country' => ['code' => 'JP']],
                range(1, 50),
            ),
        ];

        foreach ($akaCases as $label => $akas) {
            $expected = TvMazeClient::extractAkaNamesPhp($akas);
            $this->assertSame($expected, TvMazeClient::extractAkaNamesNative($akas), "akas {$label}");
            foreach ([true, false, null] as $toggle) {
                TvMazeClient::setUseRustFfi($toggle);
                $this->assertSame($expected, TvMazeClient::extractAkaNames($akas));
            }
        }

        $airdates = [
            '2024-03-05',
            '2024/03/05',
            '2024-3-5',
            '2024/3/5',
            '  2024-03-05  ',
            "\t2024/12/31\n",
            '2024-02-29',
            '2023-02-29',
            '2024-02-30',
            '2024-13-01',
            '2024-00-10',
            '2024-01-00',
            '1970-01-01',
            '2099-12-31',
            '1969-12-31',
            '2024.03.05',
            '2024-03-05 20:30:00',
            '',
            "  \t\n\r ",
            'not-a-date',
            '2024-03/05',
        ];

        foreach ($airdates as $airdate) {
            $expected = TvMazeClient::normalizeAirdatePhp($airdate);
            $this->assertSame($expected, TvMazeClient::normalizeAirdateNative($airdate), "airdate: {$airdate}");
            foreach ([true, false, null] as $toggle) {
                TvMazeClient::setUseRustFfi($toggle);
                $this->assertSame($expected, TvMazeClient::normalizeAirdate($airdate));
            }
        }

        Http::fake([
            'api.tvmaze.com/shows/1/akas' => Http::response([
                ['name' => 'Haus des Geldes', 'country' => null],
                ['name' => ''],
                ['name' => 'La Casa de Papel'],
            ]),
            'api.tvmaze.com/shows/1/episodesbydate*' => Http::response([
                ['id' => 10, 'name' => 'Pilot', 'season' => 1, 'number' => 1, 'airdate' => '2024-03-05', 'summary' => ''],
            ]),
        ]);

        foreach ([false, true] as $toggle) {
            TvMazeClient::setUseRustFfi($toggle);
            $client = new TvMazeClient;
            $this->assertSame(['Haus des Geldes', 'La Casa de Papel'], $client->akas(1));
            $episodes = $client->episodesByDate(1, '2024-03-05');
            $this->assertCount(1, $episodes);
            $this->assertSame(10, $episodes[0]->id);
            $this->assertSame([], $client->episodesByDate(1, ''));
        }
    }

    // ---------------------------------------------------------------------
    // 6. IGDB\DataNode
    // ---------------------------------------------------------------------

    public function test_igdb_datanode_date_fields_and_normalization_parity(): void
    {
        $dateFieldsList = [
            [],
            ['first_release_date', 'created_at', 'updated_at'],
            ['date', "with\0nul"],
        ];
        $keys = ['first_release_date', 'created_at', 'updated_at', 'name', 'id', '', "created_at\0"];
        $values = [
            1_700_000_000,
            0,
            -86400,
            '1700000000',
            ' -3600 ',
            '+7200',
            '1700000000.75',
            1_700_000_000.5,
            '1e6',
            '9999999999999999999999999',
            'not-numeric',
            '',
            null,
            true,
            false,
            ['nested' => 1],
        ];

        foreach ($dateFieldsList as $dfIdx => $dateFields) {
            foreach ($keys as $key) {
                $expectedIsDate = DataNode::isDateFieldPhp($key, $dateFields);
                $this->assertSame($expectedIsDate, DataNode::isDateFieldNative($key, $dateFields), "isDate df={$dfIdx} key=".bin2hex($key));

                foreach ($values as $vIdx => $value) {
                    $expectedTs = DataNode::resolveDateTimestampPhp($key, $value, $dateFields);
                    $this->assertSame(
                        $expectedTs,
                        DataNode::resolveDateTimestampNative($key, $value, $dateFields),
                        "resolveTs df={$dfIdx} key=".bin2hex($key)." val={$vIdx}"
                    );
                }
            }
        }

        $payload = [
            'id' => 1942,
            'name' => 'The Witcher 3: Wild Hunt',
            'first_release_date' => 1_431_993_600,
            'created_at' => '1400000000',
            'updated_at' => 'not-a-timestamp',
            'cover' => ['id' => 99, 'url' => '//images.igdb.com/cover.jpg', 'created_at' => 1_500_000_000],
            'genres' => [
                ['id' => 12, 'name' => 'Role-playing (RPG)', 'created_at' => 1_300_000_000],
                ['id' => 31, 'name' => 'Adventure'],
            ],
            'tags' => [1, 2, 3],
        ];
        $fields = ['first_release_date', 'created_at', 'updated_at'];

        DataNode::setUseRustFfi(false);
        $phpNode = new DataNode($payload, $fields);
        DataNode::setUseRustFfi(true);
        $rustNode = new DataNode($payload, $fields);

        $this->assertInstanceOf(Carbon::class, $rustNode['first_release_date']);
        $this->assertInstanceOf(Carbon::class, $rustNode['created_at']);
        $this->assertSame('not-a-timestamp', $rustNode['updated_at']);
        $this->assertSame($phpNode->toArray(), $rustNode->toArray());
    }

    // ---------------------------------------------------------------------
    // 7. SteamPriceData
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $apiData
     */
    #[DataProvider('steamPriceApiProvider')]
    public function test_steam_price_data_parity(array $apiData): void
    {
        $expected = SteamPriceData::fromApiResponsePhp($apiData);
        $native = SteamPriceData::fromApiResponseNative($apiData);

        $this->assertSame($expected->toArray(), $native->toArray());
        $this->assertSame($expected->getSavingsPhp(), $native->getSavingsNative());
        $this->assertSame($expected->getDisplayPricePhp(), $native->getDisplayPriceNative());

        foreach ([true, false, null] as $toggle) {
            SteamPriceData::setUseRustFfi($toggle);
            $dispatched = SteamPriceData::fromApiResponse($apiData);
            $this->assertSame($expected->toArray(), $dispatched->toArray());
            $this->assertSame($expected->getSavingsPhp(), $dispatched->getSavings());
            $this->assertSame($expected->getDisplayPricePhp(), $dispatched->getDisplayPrice());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function steamPriceApiProvider(): array
    {
        return [
            'standard sale' => [[
                'currency' => 'USD',
                'initial' => 5999,
                'final' => 2999,
                'discount_percent' => 50,
                'final_formatted' => '$29.99',
            ]],
            'empty defaults' => [[]],
            'unformatted paid' => [[
                'currency' => 'EUR',
                'initial' => 4999,
                'final' => 1999,
                'discount_percent' => 60,
            ]],
            'free unformatted' => [[
                'currency' => 'USD',
                'initial' => 0,
                'final' => 0,
                'discount_percent' => 0,
            ]],
            'empty formatted string' => [[
                'currency' => 'GBP',
                'initial' => 1000,
                'final' => 1000,
                'discount_percent' => 0,
                'final_formatted' => '',
            ]],
            'negative savings' => [[
                'currency' => 'CAD',
                'initial' => 1000,
                'final' => 2000,
                'discount_percent' => 0,
            ]],
            'nul in formatted fallback' => [[
                'currency' => 'USD',
                'initial' => 500,
                'final' => 250,
                'discount_percent' => 50,
                'final_formatted' => "\$2.50\0extra",
            ]],
            'float cents fallback' => [[
                'currency' => 'USD',
                'initial' => 5999.5,
                'final' => 2999.5,
                'discount_percent' => 50,
            ]],
        ];
    }

    public function test_steam_price_data_is_unaffected_by_static_toggle_properties(): void
    {
        $price = new SteamPriceData('USD', 59.99, 29.99, 50, '$29.99');
        $this->assertArrayNotHasKey('useRustFfi', $price->toArray());
        $this->assertArrayNotHasKey('ffi', $price->toArray());
    }

    // ---------------------------------------------------------------------
    // 8. MetadataSources
    // ---------------------------------------------------------------------

    public function test_metadata_sources_missing_key_disabled_and_summary_lines_parity(): void
    {
        Cache::flush();

        $keyConfigs = ['', '   ', "\t\n\r\0\x0B", null, 'valid-api-key', ' 0 ', 'false', 12345];
        foreach ($keyConfigs as $idx => $val) {
            config(['tmdb.api_key' => $val]);
            $expected = MetadataSources::missingKeyPhp('tmdb.api_key', 'TMDB_APIKEY');
            $this->assertSame($expected, MetadataSources::missingKeyNative('tmdb.api_key', 'TMDB_APIKEY'), "missingKey {$idx}");
            foreach ([true, false, null] as $toggle) {
                MetadataSources::setUseRustFfi($toggle);
                $this->assertSame($expected, MetadataSources::missingKey('tmdb.api_key', 'TMDB_APIKEY'));
            }
        }

        $boolConfigs = [
            true, false, 1, 0, 2, -1, 1.0, 0.0,
            'true', 'TRUE', '1', 'on', 'ON', 'yes', 'YES',
            'false', '0', 'off', 'no', '', " \t\n\r\x0Btrue\x0B",
            "\0true", "true\0", "\x0Ctrue", '+1', '01', null, [],
        ];
        foreach ($boolConfigs as $idx => $val) {
            config(['nntmux_api.imdb_scraper_enabled' => $val]);
            $expected = MetadataSources::disabledPhp('nntmux_api.imdb_scraper_enabled', 'IMDB_SCRAPER_ENABLED');
            $this->assertSame($expected, MetadataSources::disabledNative('nntmux_api.imdb_scraper_enabled', 'IMDB_SCRAPER_ENABLED'), "disabled {$idx}");
            foreach ([true, false, null] as $toggle) {
                MetadataSources::setUseRustFfi($toggle);
                $this->assertSame($expected, MetadataSources::disabled('nntmux_api.imdb_scraper_enabled', 'IMDB_SCRAPER_ENABLED'));
            }
        }

        config([
            'tmdb.api_key' => 'tmdb-key',
            'nntmux_api.omdb_api_key' => '',
            'tvdb.api_key' => 'tvdb-key',
            'nntmux_api.fanarttv_api_key' => '  ',
            'nntmux_api.trakttv_api_key' => null,
            'nntmux_api.imdb_scraper_enabled' => false,
            'nntmux_api.imdbapi_dev_enabled' => 'false',
            'igdb.credentials.client_id' => 'id',
            'igdb.credentials.client_secret' => '',
            'nntmux_api.isbndb_api_key' => 'isbn-key',
        ]);

        $expectedLines = MetadataSources::summaryLinesPhp();
        $this->assertSame($expectedLines, MetadataSources::summaryLinesNative());
        foreach ([true, false, null] as $toggle) {
            MetadataSources::setUseRustFfi($toggle);
            $this->assertSame($expectedLines, MetadataSources::summaryLines());
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI contract & Fallback
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_rust_symbols_resolve_and_honor_contract_codes(): void
    {
        $pipeline = RustPipelineLibrary::resolveDomain('metadata', TvProcessingPipeline::FFI_CDEF);
        $this->assertNotNull($pipeline);
        $this->assertSame(0, self::ffiCall($pipeline, 'nntmux_tv_pipeline_classify_status', 'matched', 7));
        $this->assertSame(-1, self::ffiCall($pipeline, 'nntmux_tv_pipeline_classify_status', null, 4));
        $this->assertSame(-1, self::ffiCall($pipeline, 'nntmux_tv_pipeline_format_summary_header', 1, 1, 0, 0, 0, NAN, null, 0));
        $this->assertLessThan(-1, self::ffiCall($pipeline, 'nntmux_tv_pipeline_format_summary_header', 1, 1, 0, 0, 0, 1.5, null, 0));

        $passable = RustPipelineLibrary::resolveDomain('metadata', TvProcessingPassable::FFI_CDEF);
        $this->assertNotNull($passable);
        $this->assertSame(1, self::ffiCall($passable, 'nntmux_tv_passable_is_resolved', 0, 10, 0));
        $this->assertSame(4, self::ffiCall($passable, 'nntmux_tv_passable_resolve_final_status', 0, 1, 0, 0, 0, 1));
        $this->assertSame(-1, self::ffiCall($passable, 'nntmux_tv_passable_dedup_transient_providers_nul', 'a', 1, 2, null, 0));

        $result = RustPipelineLibrary::resolveDomain('metadata', TvProcessingResult::FFI_CDEF);
        $this->assertNotNull($result);
        $this->assertSame(1, self::ffiCall($result, 'nntmux_tv_result_is_matched', 'matched', 7));
        $this->assertSame(-1, self::ffiCall($result, 'nntmux_tv_result_is_matched', null, 7));

        $apiPipe = RustPipelineLibrary::resolveDomain('metadata', AbstractApiProviderPipe::FFI_CDEF);
        $this->assertNotNull($apiPipe);
        $this->assertSame(-4, self::ffiCall($apiPipe, 'nntmux_tv_api_pipe_format', 0, 1, 20, '', 0, null, 0));
        $this->assertSame(-1, self::ffiCall($apiPipe, 'nntmux_tv_api_pipe_format', 99, 1, 2, '', 0, null, 0));

        $tvmaze = RustPipelineLibrary::resolveDomain('metadata', TvMazeClient::FFI_CDEF);
        $this->assertNotNull($tvmaze);
        $this->assertSame(0, self::ffiCall($tvmaze, 'nntmux_tvmaze_normalize_airdate', '   ', 3, null, 0));
        $this->assertSame(-10, self::ffiCall($tvmaze, 'nntmux_tvmaze_normalize_airdate', '2024-03-05', 10, null, 0));
        $this->assertSame(-1, self::ffiCall($tvmaze, 'nntmux_tvmaze_normalize_airdate', '2024-02-30', 10, null, 0));

        $datanode = RustPipelineLibrary::resolveDomain('metadata', DataNode::FFI_CDEF);
        $this->assertNotNull($datanode);
        $this->assertSame(1, self::ffiCall($datanode, 'nntmux_igdb_datanode_is_date_field_nul', 'created_at', 10, "id\0created_at", 13, 2));
        $this->assertSame(-1, self::ffiCall($datanode, 'nntmux_igdb_datanode_is_date_field_nul', null, 5, 'id', 2, 1));

        $steamPrice = RustPipelineLibrary::resolveDomain('metadata', SteamPriceData::FFI_CDEF);
        $this->assertNotNull($steamPrice);
        $this->assertSame(-1, self::ffiCall($steamPrice, 'nntmux_steam_price_get_savings', 10.0, 5.0, null));
        $this->assertSame(-4, self::ffiCall($steamPrice, 'nntmux_steam_price_display', 'USD', 3, 0.0, 0, '', 0, null, 0));

        $metaSources = RustPipelineLibrary::resolveDomain('metadata', MetadataSources::FFI_CDEF);
        $this->assertNotNull($metaSources);
        $this->assertSame(0, self::ffiCall($metaSources, 'nntmux_metadata_sources_missing_key', 'secret', 6, 'ENV', 3, null, 0));
        $this->assertSame(0, self::ffiCall($metaSources, 'nntmux_metadata_sources_disabled', 'true', 4, 'ENV', 3, null, 0));
    }

    public function test_native_methods_fall_back_to_php_when_library_unavailable(): void
    {
        foreach (self::TOGGLED as $class) {
            (new \ReflectionProperty($class, 'ffi'))->setValue(null, false);
            $class::setUseRustFfi(true);
        }

        $this->assertSame('matched', TvProcessingPipeline::classifyResultStatusNative('matched'));
        $this->assertStringContainsString('10 processed', TvProcessingPipeline::formatSummaryHeaderNative([
            'processed' => 10, 'matched' => 5, 'episode_missing' => 2, 'not_found' => 2, 'deferred' => 1, 'duration' => 1.5,
        ]));
        $this->assertSame('Matches by provider: TVDB: 3', TvProcessingPipeline::formatProviderSummaryNative(['TVDB' => 3]));

        $context = new TvReleaseContext(1, 'Show.S01E01', 1, 5000);
        $passable = new TvProcessingPassable($context, new TvProcessingRunState);
        $passable->markParseFailed();
        $this->assertTrue($passable->isResolvedNative());
        $this->assertSame(TvProcessingResult::STATUS_PARSE_FAILED, $passable->finalResultNative()->status);
        $this->assertSame('a,b', TvProcessingPassable::deduplicateTransientProvidersNative(['a', 'b', 'a']));

        $this->assertTrue(TvProcessingResult::matched(1, 2, 'TVDB')->isMatchedNative());
        $this->assertSame('1x2', AbstractApiProviderPipe::formatSeasonEpisodeKeyNative(1, 2));
        $this->assertSame('show has no tvdb id', AbstractApiProviderPipe::formatMissingSiteIdReasonNative('tvdb'));
        $this->assertSame(['Show A'], TvMazeClient::extractAkaNamesNative([['name' => 'Show A']]));
        $this->assertSame('2024-03-05', TvMazeClient::normalizeAirdateNative('2024-03-05'));
        $this->assertTrue(DataNode::isDateFieldNative('created_at', ['created_at']));
        $this->assertSame(1700000000, DataNode::resolveDateTimestampNative('created_at', '1700000000', ['created_at']));

        $price = SteamPriceData::fromApiResponseNative(['currency' => 'USD', 'initial' => 1999, 'final' => 999, 'discount_percent' => 50]);
        $this->assertEqualsWithDelta(10.0, $price->getSavingsNative(), 0.001);
        $this->assertSame('USD 9.99', $price->getDisplayPriceNative());

        config(['tmdb.api_key' => '', 'nntmux_api.imdb_scraper_enabled' => false]);
        $this->assertSame('no API key (TMDB_APIKEY)', MetadataSources::missingKeyNative('tmdb.api_key', 'TMDB_APIKEY'));
        $this->assertSame('disabled by IMDB_SCRAPER_ENABLED', MetadataSources::disabledNative('nntmux_api.imdb_scraper_enabled', 'IMDB_SCRAPER_ENABLED'));
        $this->assertNotEmpty(MetadataSources::summaryLinesNative());
    }

    // ---------------------------------------------------------------------
    // Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_TV_PIPELINE_IGDB_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_TV_PIPELINE_IGDB_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $context = new TvReleaseContext(42, 'Severance.S01E02.1080p', 1, 5000);
        $parsed = new ParsedTvRelease('Severance', 'severance', '', 1, 2, false, null, null);
        $passableMatched = new TvProcessingPassable($context, new TvProcessingRunState);
        $passableMatched->setParsed($parsed);
        $passableMatched->setShow(15, 'TVDB');
        $passableMatched->markMatched(99, 'TVDB');

        $passableTransient = new TvProcessingPassable($context, new TvProcessingRunState);
        $passableTransient->setParsed($parsed);
        foreach (['tvdb', 'tvmaze', 'tvdb', 'tmdb', 'trakt', 'tvmaze'] as $p) {
            $passableTransient->markTransient($p, 300);
        }

        $resultMatched = TvProcessingResult::matched(15, 99, 'TVDB');
        $resultMissing = TvProcessingResult::episodeMissing(15, 'TVDB');

        $commonAkas = [
            ['name' => 'Haus des Geldes', 'country' => ['name' => 'Germany', 'code' => 'DE']],
            ['name' => 'La Casa de Papel', 'country' => ['name' => 'Spain', 'code' => 'ES']],
            ['name' => 'Money Heist', 'country' => ['name' => 'United States', 'code' => 'US']],
        ];
        $uncommonAkas = array_map(
            static fn (int $i): array => ['name' => $i % 4 === 0 ? '' : "International Title {$i} 日本語", 'country' => ['code' => 'JP']],
            range(1, 35),
        );

        $dateFields = ['first_release_date', 'created_at', 'updated_at', 'change_date', 'start_date'];
        $paidPrice = new SteamPriceData('USD', 59.99, 29.99, 50, '$29.99');
        $unformattedPrice = new SteamPriceData('EUR', 49.99, 19.99, 60, null);
        $freePrice = SteamPriceData::free();

        config([
            'tmdb.api_key' => 'tmdb-key',
            'nntmux_api.omdb_api_key' => '',
            'tvdb.api_key' => 'tvdb-key',
            'nntmux_api.fanarttv_api_key' => '  ',
            'nntmux_api.trakttv_api_key' => null,
            'nntmux_api.imdb_scraper_enabled' => false,
            'nntmux_api.imdbapi_dev_enabled' => 'true',
            'igdb.credentials.client_id' => 'id',
            'igdb.credentials.client_secret' => '',
            'nntmux_api.isbndb_api_key' => 'isbn-key',
        ]);

        $workloads = [
            'TvProcessingPipeline::classifyResultStatus' => [TvProcessingPipeline::class,
                fn () => [
                    TvProcessingPipeline::classifyResultStatus('matched'),
                    TvProcessingPipeline::classifyResultStatus('episode_missing'),
                    TvProcessingPipeline::classifyResultStatus('not_found'),
                ],
                fn () => [
                    TvProcessingPipeline::classifyResultStatus('season_pack'),
                    TvProcessingPipeline::classifyResultStatus('transient'),
                    TvProcessingPipeline::classifyResultStatus('parse_failed'),
                ]],
            'TvProcessingPipeline::formatSummaryHeader' => [TvProcessingPipeline::class,
                fn () => TvProcessingPipeline::formatSummaryHeader([
                    'processed' => 75, 'matched' => 60, 'episode_missing' => 8, 'not_found' => 5, 'deferred' => 2, 'duration' => 1.42,
                ]),
                fn () => TvProcessingPipeline::formatSummaryHeader([
                    'processed' => 5000, 'matched' => 4200, 'episode_missing' => 500, 'not_found' => 250, 'deferred' => 50, 'duration' => 184.995,
                ])],
            'TvProcessingPipeline::formatProviderSummary' => [TvProcessingPipeline::class,
                fn () => TvProcessingPipeline::formatProviderSummary(['Local DB' => 45, 'TVDB' => 12, 'TVMaze' => 3]),
                fn () => TvProcessingPipeline::formatProviderSummary(['Local DB' => 1000, 'TVDB' => 500, 'TVMaze' => 250, 'TMDB' => 120, 'Trakt' => 30])],
            'TvProcessingPassable::isResolved' => [TvProcessingPassable::class,
                fn () => [$passableMatched->isResolved(), $passableTransient->isResolved()],
                fn () => [$passableTransient->isResolved(), $passableMatched->isResolved()]],
            'TvProcessingPassable::finalResult' => [TvProcessingPassable::class,
                fn () => [$passableMatched->finalResult()->status, $passableTransient->finalResult()->providerName],
                fn () => [
                    TvProcessingPassable::deduplicateTransientProviders(['tvdb', 'tvmaze', 'tvdb', 'tmdb', 'trakt', 'tvmaze', 'tvdb']),
                    $passableTransient->finalResult()->status,
                ]],
            'TvProcessingResult::isMatched' => [TvProcessingResult::class,
                fn () => [$resultMatched->isMatched(), $resultMissing->isMatched()],
                fn () => [TvProcessingResult::isMatchedStatus('season_pack'), TvProcessingResult::isMatchedStatus('matched')]],
            'AbstractApiProviderPipe::formatSeasonEpisodeKey' => [AbstractApiProviderPipe::class,
                fn () => [AbstractApiProviderPipe::formatSeasonEpisodeKey(1, 2), AbstractApiProviderPipe::formatSeasonEpisodeKey(3, 14)],
                fn () => [AbstractApiProviderPipe::formatSeasonEpisodeKey(0, 99), AbstractApiProviderPipe::formatSeasonEpisodeKey(2024, 365)]],
            'AbstractApiProviderPipe::formatMissingSiteIdReason' => [AbstractApiProviderPipe::class,
                fn () => [AbstractApiProviderPipe::formatMissingSiteIdReason('tvdb'), AbstractApiProviderPipe::formatMissingSiteIdReason('tvmaze')],
                fn () => [AbstractApiProviderPipe::formatMissingSiteIdReason('tmdb'), AbstractApiProviderPipe::formatMissingSiteIdReason('trakt')]],
            'TvMazeClient::extractAkaNames' => [TvMazeClient::class,
                fn () => TvMazeClient::extractAkaNames($commonAkas),
                fn () => TvMazeClient::extractAkaNames($uncommonAkas)],
            'TvMazeClient::normalizeAirdate' => [TvMazeClient::class,
                fn () => [TvMazeClient::normalizeAirdate('2024-03-05'), TvMazeClient::normalizeAirdate('2023-11-14')],
                fn () => [TvMazeClient::normalizeAirdate(' 2024/3/5 '), TvMazeClient::normalizeAirdate('')]],
            'IGDB\\DataNode::resolveDateTimestamp' => [DataNode::class,
                fn () => [
                    DataNode::resolveDateTimestamp('first_release_date', 1_700_000_000, $dateFields),
                    DataNode::resolveDateTimestamp('name', 'The Witcher 3', $dateFields),
                ],
                fn () => [
                    DataNode::resolveDateTimestamp('start_date', '1700000000', $dateFields),
                    DataNode::isDateField('updated_at', $dateFields),
                ]],
            'SteamPriceData::fromApiResponse' => [SteamPriceData::class,
                fn () => SteamPriceData::fromApiResponse([
                    'currency' => 'USD', 'initial' => 5999, 'final' => 2999, 'discount_percent' => 50, 'final_formatted' => '$29.99',
                ])->final,
                fn () => SteamPriceData::fromApiResponse(['currency' => 'EUR', 'initial' => 4999, 'final' => 1999])->final],
            'SteamPriceData::getSavings' => [SteamPriceData::class,
                fn () => [$paidPrice->getSavings(), $freePrice->getSavings()],
                fn () => [$unformattedPrice->getSavings(), (new SteamPriceData('USD', 10.0, 20.0, 0))->getSavings()]],
            'SteamPriceData::getDisplayPrice' => [SteamPriceData::class,
                fn () => [$paidPrice->getDisplayPrice(), $freePrice->getDisplayPrice()],
                fn () => [$unformattedPrice->getDisplayPrice(), (new SteamPriceData('JPY', 1500.0, 0.0, 100))->getDisplayPrice()]],
            'MetadataSources::missingKey' => [MetadataSources::class,
                fn () => [MetadataSources::missingKey('tmdb.api_key', 'TMDB_APIKEY'), MetadataSources::missingKey('nntmux_api.omdb_api_key', 'OMDB_APIKEY')],
                fn () => [MetadataSources::missingKey('nntmux_api.fanarttv_api_key', 'FANARTTV_APIKEY'), MetadataSources::missingKey('nntmux_api.trakttv_api_key', 'TRAKTTV_APIKEY')]],
            'MetadataSources::disabled' => [MetadataSources::class,
                fn () => [MetadataSources::disabled('nntmux_api.imdb_scraper_enabled', 'IMDB_SCRAPER_ENABLED'), MetadataSources::disabled('nntmux_api.imdbapi_dev_enabled', 'IMDBAPI_DEV_ENABLED')],
                fn () => [MetadataSources::disabled('nntmux_api.NonExistentSwitch', 'NON_EXISTENT_SWITCH'), MetadataSources::disabled('nntmux_api.imdb_scraper_enabled', 'IMDB_SCRAPER_ENABLED')]],
            'MetadataSources::summaryLines' => [MetadataSources::class,
                fn () => MetadataSources::summaryLines(),
                fn () => MetadataSources::formatSummaryLines(MetadataSources::all())],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 1506 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-47s | %.2fx / %.2fx     | %.2fx / %.2fx       | %-18s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Rust FFI (true)' : 'PHP (false)'
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $toggle) {
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
