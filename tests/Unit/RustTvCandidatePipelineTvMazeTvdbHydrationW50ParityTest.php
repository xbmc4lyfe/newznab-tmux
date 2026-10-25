<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EpisodeHydrationService;
use App\Services\TvProcessing\Providers\TvdbProvider;
use App\Services\TvProcessing\Providers\TvMazeProvider;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class RustTvCandidatePipelineTvMazeTvdbHydrationW50ParityTest extends TestCase
{
    /** Per class: the batch entry and its Rust export. */
    private const TARGETS = [
        TvProcessingCandidateQuery::class => ['w50CandidatePlanBatch', 'nntmux_w503_tv_candidate_plan_batch'],
        TvProcessingPipeline::class => ['w50ResultTallyBatch', 'nntmux_w503_tv_pipeline_result_tally_batch'],
        TvMazeProvider::class => ['w50EpisodeFormatBatch', 'nntmux_w503_tvmaze_episode_format_batch'],
        TvdbProvider::class => ['w50EpisodeMatchBatch', 'nntmux_w503_tvdb_episode_match_batch'],
        EpisodeHydrationService::class => ['w50SearchnameHydrateBatch', 'nntmux_w503_episode_searchname_hydrate_batch'],
    ];

    /** Benchmark-gated production defaults (see the benchmark test below). */
    private const DEFAULTS = [
        TvProcessingCandidateQuery::class => true,
        TvProcessingPipeline::class => true,
        TvMazeProvider::class => true,
        TvdbProvider::class => true,
        EpisodeHydrationService::class => true,
    ];

    /** 2023-11-14 22:13:20 UTC. */
    private const NOW = 1_700_000_000;

    private const WORDS = ['Show', 'Name', 'The', 'Office', 'S01E02', 's1e2', '1x02', 'Season', 'Episode', 'Part', 'Pt', 'Ep', 'E', '102',
        '2024.01.15', '2024-01-15', '720p', 'HDTV', 'x264', '.', ' ', '_', '-', '(', ')', "'", '!', "\xC3\xA9", "\xff", '0', '00', '1234',
        "\t", "\n", "\x0B", "\x0C", "\r", 'S', 'x', '9', 'Lost', 'GRP'];

    private const DATES = ['2020-01-02', '2024-02-29', '2023-02-29', '1900-02-29', '2000-02-29', '0000-01-01', '2020-13-01', '2020-00-10',
        '2020-01-32', '2020-1-2', ' 2021-06-30 ', "2021-06-30\n", "\t2021-06-30", '2021-06-30T00:00:00Z', '', 'TBA', '20200102', '9999-12-31'];

    private const STAMPS = ['2023-11-14 22:13:20', '2023-11-14 22:13:21', '2023-11-14T22:13:19', '1970-01-01 00:00:00', '0001-01-01 00:00:00',
        '9999-12-31 23:59:59', '2024-02-29 12:00:00', '2023-02-29 12:00:00', '2023-11-14 24:00:00', '2023-11-14 23:60:00', '2023-11-14 23:59:60',
        '2023-11-14 22:13', "2023-11-14 22:13:20\n", ' 2023-11-14 22:13:20', '2023-11-14  22:13:2', 'now', '', '0', '0000-00-00 00:00:00'];

    private const STATUSES = ['matched', 'season_pack', 'episode_missing', 'not_found', 'parse_failed', 'transient', 'skipped', 'pending', '',
        'Matched', 'matched ', 'unknown'];

    private const PROVIDERS = ['TVDB', 'TVMaze', 'TMDB', 'Trakt', 'Local DB'];

    private const SHOWS = ['The Office', 'Law and Order', 'Silent Witness', 'Doctor Who', 'Theater of Blood', "Bob's Burgers", 'Monster Hunter',
        'Stranger Things', 'The Mandalorian', 'Top Gear', 'Shameless (US)', 'Better Call Saul', 'Grey\'s Anatomy', 'NCIS: Los Angeles'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->setOverride($class, null);
        }
        parent::tearDown();
    }

    public function test_w50_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW50RustFfi', 'withW50RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                if (! $reflection->hasMethod($method) || ! $reflection->getMethod($method)->isStatic() || ! $reflection->getMethod($method)->isPublic()) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach (['W50_RUST_FFI_DEFAULT', 'W50_FFI_CDEF'] as $name) {
                if (! $reflection->hasConstant($name)) {
                    $missing[] = $class.'::'.$name;
                }
            }
            if (! $reflection->hasProperty('useW50RustFfi') || ! $reflection->getProperty('useW50RustFfi')->isPrivate()) {
                $missing[] = $class.'::$useW50RustFfi';
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_w50_defaults_and_scoped_toggle(): void
    {
        foreach (array_keys(self::TARGETS) as $class) {
            $this->assertSame(self::DEFAULTS[$class], $this->defaultOf($class), $class);
            $this->assertSame(self::DEFAULTS[$class], $class::usesW50RustFfi(), $class);
            $this->assertTrue($class::withW50RustFfi(true, static fn (): bool => $class::usesW50RustFfi()));
            $this->assertFalse($class::withW50RustFfi(false, static fn (): bool => $class::usesW50RustFfi()));
            $this->assertTrue($class::withW50RustFfi(false, static fn (): bool => $class::withW50RustFfi(true, static fn (): bool => $class::usesW50RustFfi())));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW50RustFfi(), $class);
            try {
                $class::withW50RustFfi(! $class::usesW50RustFfi(), static fn () => throw new RuntimeException('boom'));
                $this->fail('expected the callback exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertSame(self::DEFAULTS[$class], $class::usesW50RustFfi(), $class.' restores after an exception');
        }

        // The TVMaze and TVDB providers share AbstractTvProvider but keep independent toggles.
        TvMazeProvider::withW50RustFfi(false, function (): void {
            $this->assertSame(self::DEFAULTS[TvdbProvider::class], TvdbProvider::usesW50RustFfi());
        });
    }

    public function test_w50_exports_resolve_and_guard_their_buffers(): void
    {
        $this->requireLibrary();
        foreach (self::TARGETS as $class => [, $symbol]) {
            $cdef = \constant($class.'::W50_FFI_CDEF');
            $this->assertStringContainsString($symbol, $cdef);
            $this->assertInstanceOf(FFI::class, RustPipelineLibrary::resolveDomain('categorize_w50', $cdef), $class);
        }

        $ffi = RustPipelineLibrary::resolveDomain('categorize_w50', EpisodeHydrationService::W50_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[16]');
        $ints = pack('q*', 0, 0);
        // @phpstan-ignore method.notFound
        $this->assertSame(6, $ffi->nntmux_w503_episode_searchname_hydrate_batch('Show.S02E05', 11, $ints, 16, 1, $out, 16));
        $this->assertSame(
            EpisodeHydrationService::w50SearchnameHydrateBatchPhp([['searchname' => 'Show.S02E05']]),
            array_values((array) unpack('P*', FFI::string($out, 48))),
        );
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w503_episode_searchname_hydrate_batch("a\0b", 3, $ints, 16, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-6, $ffi->nntmux_w503_episode_searchname_hydrate_batch('Show', 4, $ints, 16, 1, $out, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w503_episode_searchname_hydrate_batch('Show', 4, $ints, 16, 1, null, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w503_episode_searchname_hydrate_batch(null, 4, $ints, 16, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w503_episode_searchname_hydrate_batch('Show', 4, '1234567', 7, 1, $out, 16));

        $ffi = RustPipelineLibrary::resolveDomain('categorize_w50', TvMazeProvider::W50_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        // A null-mask index outside the batch declines.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w503_tvmaze_episode_format_batch("\0\0", 2, pack('q*', 1, 5, 0, 1, 1), 40, 1, $out, 16));
        // @phpstan-ignore method.notFound
        $this->assertSame(5, $ffi->nntmux_w503_tvmaze_episode_format_batch(null, 0, pack('q*', 0, 0), 16, 0, $out, 16));
    }

    public function test_candidate_plan_parity(): void
    {
        $empty = [0, 0, 0, 0, 0, 0, 0, 0, PHP_INT_MAX];
        $this->assertSame($empty, TvProcessingCandidateQuery::w50CandidatePlanBatchPhp([], self::NOW));
        $this->assertSame($empty, TvProcessingCandidateQuery::w50CandidatePlanBatchNative([], self::NOW));
        $base = ['categories_id' => 5040, 'size' => 2_000_000, 'videos_id' => 0, 'tv_episodes_id' => 0, 'tv_lookup_attempts' => 0];
        $fixed = [
            $base,
            ['tv_lookup_retry_at' => '2023-11-14 22:13:20', 'tv_lookup_attempts' => 2] + $base,
            ['tv_lookup_retry_at' => '2023-11-14 22:13:21', 'tv_lookup_attempts' => 3] + $base,
            ['tv_lookup_retry_at' => 'bad', 'tv_lookup_attempts' => 1] + $base,
            ['categories_id' => 5070] + $base,
            ['size' => 1_048_576] + $base,
            ['categories_id' => 5999, 'videos_id' => 7, 'tv_episodes_id' => -11, 'tv_lookup_attempts' => 1] + $base,
            ['videos_id' => 7, 'tv_episodes_id' => -11, 'tv_lookup_attempts' => 4, 'tv_lookup_retry_at' => '2030-01-01T00:00:00'] + $base,
            ['videos_id' => 7, 'tv_episodes_id' => -11, 'tv_lookup_attempts' => 6] + $base,
            ['late_article_candidate_id' => 9] + $base,
            ['late_article_candidate_id' => null, 'tv_episodes_id' => 123] + $base,
            ['tv_episodes_id' => -10] + $base,
            [],
        ];
        $php = TvProcessingCandidateQuery::w50CandidatePlanBatchPhp($fixed, self::NOW);
        $this->assertSame([1, 0, 0, 21600, 1, 1, self::NOW, 900, 2, 1, self::NOW + 1, 3600, 2, 2, 0, 300], \array_slice($php, 0, 16));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 3, 0, 0, 3600, 4, 1, 1_893_456_000, 259200, 5, 0, 0, 0], \array_slice($php, 16, 20));
        $this->assertSame([6, 0, 0, 0, 7, 0, 0, 0, 7, 0, 0, 0, 0, 0, 0, 0], \array_slice($php, 36, 16));
        $this->assertSame([3, 2, 2, 1, 1, 1, 1, 2, self::NOW + 1], \array_slice($php, 52));
        $this->assertSame(7, TvProcessingCandidateQuery::w50CandidatePlanBatchPhp([$base], self::NOW, 2)[0]);
        $this->assertSame(1, TvProcessingCandidateQuery::w50CandidatePlanBatchPhp([['isrenamed' => 1] + $base], self::NOW, 2)[0]);
        $this->assertSame(7, TvProcessingCandidateQuery::w50CandidatePlanBatchPhp([$base], self::NOW, 0)[0]);

        $mixed = [['categories_id' => '5040', 'size' => '2e6', 'videos_id' => null, 'tv_episodes_id' => false, 'tv_lookup_attempts' => '2x',
            'isrenamed' => true, 'late_article_candidate_id' => '0', 'tv_lookup_retry_at' => null],
            ['categories_id' => 5040.0, 'size' => PHP_INT_MAX, 'videos_id' => '7', 'tv_episodes_id' => '-11', 'tv_lookup_attempts' => PHP_INT_MAX,
                'tv_lookup_retry_at' => 20231114]];
        $batches = [[$fixed, self::NOW, 1], [$fixed, self::NOW, 2], [$fixed, 0, 1], [$fixed, PHP_INT_MIN, 1], [$fixed, PHP_INT_MAX, 0],
            [$mixed, self::NOW, 2], [[['tv_lookup_retry_at' => "2023-11-14 22:13:20\0"] + $base], self::NOW, 1]];
        $random = new Randomizer(new Mt19937(5031));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = $args = $this->candidateArgs($random, $size, $noisy);
                $batches[] = [$this->sparse($random, $args[0]), ...\array_slice($args, 1)];
            }
        }
        foreach ($batches as $i => $args) {
            $this->assertParity(TvProcessingCandidateQuery::class, $args, "batch {$i}");
        }
    }

    public function test_pipeline_result_tally_parity(): void
    {
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], TvProcessingPipeline::w50ResultTallyBatchPhp([]));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], TvProcessingPipeline::w50ResultTallyBatchNative([]));
        $fixed = [
            ['status' => 'matched', 'provider' => 'TVDB', 'title' => 'The Office (US)', 'video_id' => 5, 'episode_id' => 9],
            ['status' => 'season_pack', 'provider' => null, 'title' => 'the.office.us!', 'video_id' => 5],
            ['status' => 'transient', 'provider' => 'TVDB', 'title' => 'Lost'],
            ['status' => 'bogus', 'title' => ''],
            ['status' => 'matched', 'provider' => 'tvdb', 'title' => 'Lost', 'episode_id' => '3'],
            ['status' => 'episode_missing', 'provider' => 'TMDB', 'title' => "Caf\xC3\xA9"],
            ['status' => 'matched', 'title' => 'LOST'],
            [],
        ];
        $php = TvProcessingPipeline::w50ResultTallyBatchPhp($fixed);
        $this->assertSame([0, 0, $this->fnv('the office us'), 3, 0, 1, $this->fnv('the office us'), 5, 3, -1, $this->fnv('lost'), 0], \array_slice($php, 0, 12));
        $this->assertSame([2, -1, $this->fnv(''), 0, 0, 2, $this->fnv('lost'), 2, 1, -1, $this->fnv('caf'), 0, 0, 1, $this->fnv('lost'), 0], \array_slice($php, 12, 16));
        $this->assertSame([8, 4, 1, 2, 1, 3, 2, 1, 2, 1], \array_slice($php, 32));

        $mixed = [['status' => 0, 'provider' => 42, 'title' => 1.5, 'video_id' => '7x', 'episode_id' => true],
            ['status' => 'matched', 'provider' => 42, 'title' => null, 'video_id' => -3, 'episode_id' => false],
            ['status' => 'matched', 'provider' => '42', 'title' => 'x']];
        $batches = [[$fixed], [$mixed], [[['status' => "matched\0"]]], [[['status' => 'matched', 'provider' => "a\0b"]]], [[['title' => "\0"]]]];
        $random = new Randomizer(new Mt19937(5032));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = [$rows = $this->pipelineResults($random, $size, $noisy)];
                $batches[] = [$this->sparse($random, $rows)];
            }
        }
        foreach ($batches as $i => $args) {
            $this->assertParity(TvProcessingPipeline::class, $args, "batch {$i}");
        }
    }

    public function test_tvmaze_episode_format_parity(): void
    {
        $this->assertSame([0, 0, 0, 0, 0], TvMazeProvider::w50EpisodeFormatBatchPhp([]));
        $this->assertSame([0, 0, 0, 0, 0], TvMazeProvider::w50EpisodeFormatBatchNative([]));
        $fixed = [
            ['season' => 1, 'number' => 2, 'airdate' => '2020-01-02', 'name' => 'Pilot', 'summary' => '<p>Sum</p>'],
            ['season' => 0, 'number' => null, 'airdate' => ' 2021-06-30 ', 'name' => ' ', 'summary' => ''],
            ['season' => null, 'number' => 9, 'airdate' => 'bad', 'name' => 'Special', 'summary' => "\t"],
            ['season' => 3, 'number' => 123, 'airdate' => '2023-02-29'],
            ['season' => -5, 'number' => -1, 'airdate' => '2024-02-29'],
            [],
            ['season' => PHP_INT_MIN, 'number' => PHP_INT_MAX, 'airdate' => "2021-06-30\n"],
        ];
        $php = TvMazeProvider::w50EpisodeFormatBatchPhp($fixed);
        $this->assertSame([1, 1, 2, $this->fnv('S01E02'), 20200102, 7, 1, 0, 0, $this->fnv('S00E00'), 20210630, 0], \array_slice($php, 0, 12));
        $this->assertSame([0, 0, 9, $this->fnv('S00E09'), 0, 3, 1, 3, 123, $this->fnv('S03E123'), 0, 1], \array_slice($php, 12, 12));
        $this->assertSame([1, -5, -1, $this->fnv('S-5E-1'), 20240229, 1, 0, 0, 0, $this->fnv('S00E00'), 0, 0], \array_slice($php, 24, 12));
        $this->assertSame([1, PHP_INT_MIN, PHP_INT_MAX, $this->fnv('S'.PHP_INT_MIN.'E'.PHP_INT_MAX), 20210630, 1], \array_slice($php, 36, 6));
        $this->assertSame([5, 2, 1, 1, PHP_INT_MAX], \array_slice($php, 42));

        $mixed = [['season' => '2', 'number' => '1e1', 'airdate' => 20200102, 'name' => 0, 'summary' => false],
            ['season' => '', 'number' => '', 'airdate' => null, 'name' => null, 'summary' => true],
            ['season' => false, 'number' => true, 'airdate' => '2020-01-02', 'name' => 1.5, 'summary' => '0']];
        $batches = [[$fixed], [$mixed], [[['airdate' => "\0"]]], [[['season' => 1, 'summary' => "<p>\0</p>"]]]];
        $random = new Randomizer(new Mt19937(5033));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = [$rows = $this->tvMazeEpisodes($random, $size, $noisy)];
                $batches[] = [$this->sparse($random, $rows)];
            }
        }
        foreach ($batches as $i => $args) {
            $this->assertParity(TvMazeProvider::class, $args, "batch {$i}");
        }
    }

    public function test_tvdb_episode_match_parity(): void
    {
        $this->assertSame([0, -1, 0, 0], TvdbProvider::w50EpisodeMatchBatchPhp([], 1, 2));
        $this->assertSame([0, -1, 0, 0], TvdbProvider::w50EpisodeMatchBatchNative([], 1, 2));
        $fixed = [
            ['seasonNumber' => 1, 'number' => 1, 'aired' => '2020-01-01', 'name' => 'Pilot'],
            ['seasonNumber' => 1, 'number' => 2, 'aired' => '2020-01-08', 'name' => ' '],
            ['seasonNumber' => 1, 'number' => 2, 'aired' => ''],
            ['seasonNumber' => '', 'number' => 2, 'aired' => '2021-01-01', 'name' => 'x'],
            ['seasonNumber' => 0, 'number' => null, 'aired' => '2022-01-01'],
            ['seasonNumber' => '1', 'number' => '2', 'aired' => ' 2020-01-09'],
            [],
        ];
        $php = TvdbProvider::w50EpisodeMatchBatchPhp($fixed, 1, 2);
        $this->assertSame([1, 1, 1, $this->fnv('S01E01'), 20200101, 2, 1, 1, 2, $this->fnv('S01E02'), 20200108, 1], \array_slice($php, 0, 12));
        $this->assertSame([1, 1, 2, $this->fnv('S01E02'), 0, 1, 0, 0, 2, $this->fnv('S00E02'), 20210101, 2], \array_slice($php, 12, 12));
        $this->assertSame([0, 0, 0, $this->fnv('S00E00'), 20220101, 0, 1, 1, 2, $this->fnv('S01E02'), 0, 1], \array_slice($php, 24, 12));
        $this->assertSame([0, 0, 0, $this->fnv('S00E00'), 0, 0], \array_slice($php, 36, 6));
        $this->assertSame([4, 1, 20200108, 2], \array_slice($php, 42));
        $this->assertSame(-1, TvdbProvider::w50EpisodeMatchBatchPhp($fixed, 9, 9)[43]);

        $mixed = [['seasonNumber' => false, 'number' => 0.0, 'aired' => 20200101, 'name' => 7],
            ['seasonNumber' => '2x', 'number' => '1e1', 'aired' => null, 'name' => null]];
        $batches = [[$fixed, 1, 2], [$fixed, 0, 0], [$fixed, PHP_INT_MIN, PHP_INT_MAX], [$mixed, 2, 10], [[['aired' => "\0"]], 0, 0],
            [[['name' => "x\0"]], 0, 0]];
        $random = new Randomizer(new Mt19937(5034));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = $args = $this->tvdbArgs($random, $size, $noisy);
                $batches[] = [$this->sparse($random, $args[0]), ...\array_slice($args, 1)];
            }
        }
        foreach ($batches as $i => $args) {
            $this->assertParity(TvdbProvider::class, $args, "batch {$i}");
        }
    }

    public function test_episode_searchname_hydrate_parity(): void
    {
        $this->assertSame([0, 0, 0], EpisodeHydrationService::w50SearchnameHydrateBatchPhp([]));
        $this->assertSame([0, 0, 0], EpisodeHydrationService::w50SearchnameHydrateBatchNative([]));
        $fixed = [
            ['searchname' => 'Show.S02E05.720p'],
            ['searchname' => 'Show 3x07', 'series' => 4],
            ['searchname' => 'Show.2024.01.15', 'series' => 5],
            ['searchname' => 'Show Part 4'],
            ['searchname' => ''],
            ['searchname' => '0'],
            ['searchname' => 'Show.Name', 'series' => 7],
            ['searchname' => 'Show.S01E01', 'series' => 1, 'episode' => 1],
            ['searchname' => 'Show.102.HDTV'],
            ['searchname' => 'Show.102.HDTV', 'series' => 3],
            ['searchname' => 'Show Season 2 Episode 10'],
            ['searchname' => 'Show.Ep.12', 'series' => '0'],
            ['searchname' => 'Show.S00E00'],
        ];
        $php = EpisodeHydrationService::w50SearchnameHydrateBatchPhp($fixed);
        $this->assertSame([2, 5, 3, 4, 7, 2, 5, 115, 2, 1, 4, 3, 0, 0, -1, 0, 0, -1, 7, 0, 0, 1, 1, -1], \array_slice($php, 0, 24));
        $this->assertSame([1, 2, 3, 3, 0, 0, 2, 10, 3, 1, 12, 3, 0, 0, 3], \array_slice($php, 24, 15));
        $this->assertSame([3, 2, 8], \array_slice($php, 39));

        // The batch reference agrees with the production single-name parser for releases missing both numbers.
        $service = new EpisodeHydrationService;
        foreach (['Show.S02E05', 'Show 3x07', 'Show.2024.01.15', 'Show Part 4', 'Show.102', 'Show.Name', 'Show.Ep 9'] as $name) {
            $parsed = $service->parseSearchnamePhp($name);
            $batch = EpisodeHydrationService::w50SearchnameHydrateBatchPhp([['searchname' => $name]]);
            $this->assertSame([(int) $parsed['series'], (int) $parsed['episode']], [$batch[0], $batch[1]], $name);
        }

        $mixed = [['searchname' => 102, 'series' => null, 'episode' => false], ['searchname' => 'S01E02', 'series' => '3x', 'episode' => 0.4],
            ['searchname' => null, 'series' => true], ['searchname' => 1.5, 'series' => '', 'episode' => '07']];
        $batches = [[$fixed], [$mixed], [[['searchname' => "S01E02\0"]]], [[['searchname' => "\0"], ['searchname' => 'x']]]];
        $random = new Randomizer(new Mt19937(5035));
        foreach ([64, 128, 256] as $size) {
            foreach ([false, true] as $noisy) {
                $batches[] = [$rows = $this->hydrationRows($random, $size, $noisy)];
                $batches[] = [$this->sparse($random, $rows)];
            }
        }
        foreach ($batches as $i => $args) {
            $this->assertParity(EpisodeHydrationService::class, $args, "batch {$i}");
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including column
        // extraction, packing, the FFI call and decoding (release dylib, 5 warmups, 401 paired samples per helper and
        // workload, 20 iterations per common sample and 10 per uncommon sample). Common = one 75-128 row batch of
        // typical payloads; uncommon = 256 long, noisy, malformed and null-heavy rows. Rows missing keys (which
        // production rows never are) take the slower per-row fill and are covered by the parity tests only.
        // Speedup = PHP latency / Rust latency, ranges over 3 rounds (PHP 8.5.12 NTS, release dylib, Apple
        // Silicon, shared host at load average ~14-17):
        // - TvProcessingCandidateQuery: common mean 2.95-3.11x / p99 2.69-3.59x, uncommon mean 2.19-2.32x / p99 1.97-3.08x
        // - TvProcessingPipeline:       common mean 2.21-2.31x / p99 2.06-2.15x, uncommon mean 2.08-2.16x / p99 1.81-1.99x
        // - TvMazeProvider:             common mean 2.91-3.11x / p99 2.62-2.96x, uncommon mean 2.30-2.39x / p99 1.79-2.43x
        // - TvdbProvider:               common mean 3.77-3.87x / p99 3.61-3.82x, uncommon mean 2.79-2.94x / p99 2.49-4.07x
        // - EpisodeHydrationService:    common mean 3.79-3.82x / p99 3.59-3.89x, uncommon mean 3.96-4.04x / p99 3.37-4.04x
        // Every helper clears every gate in every round and defaults to Rust.
        // Each helper defaults to Rust only when it clears 1.5x on both workloads' mean and p99, per
        // .ai/rules/rust-performance.md; the assertion below requires every enabled default to clear 1.5x.
        // Reproduce:
        // cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
        // php artisan test --compact tests/Unit/RustTvCandidatePipelineTvMazeTvdbHydrationW50ParityTest.php --filter benchmark
        $this->requireLibrary();
        $random = new Randomizer(new Mt19937(5036));
        $warmup = 5;
        $samples = 401;
        $sink = 0;
        $speedups = [];
        foreach (self::TARGETS as $class => [$entry]) {
            $workloads = [
                'common' => [$this->benchArgs($class, new Randomizer(new Mt19937(5037)), false), 20],
                'uncommon' => [$this->benchArgs($class, new Randomizer(new Mt19937(5038)), true), 10],
            ];
            foreach ($workloads as $name => [$args, $iterations]) {
                $this->assertSame($class::{$entry.'Php'}(...$args), $class::{$entry.'Native'}(...$args), $class.' '.$name);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $sink += \count($class::withW50RustFfi($useRust, static fn (): array => $class::$entry(...$args)));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $useRust) {
                        $this->setOverride($class, $useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += \count($class::$entry(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $this->setOverride($class, null);
                $speedups[$class][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'php_mean_us' => round($this->mean($times['php']), 2),
                    'rust_mean_us' => round($this->mean($times['rust']), 2),
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) ?: '';
        fwrite(STDERR, "\nRustTvCandidatePipelineTvMazeTvdbHydrationW50 speedup: {$summary}\n");
        foreach (array_keys(self::TARGETS) as $class) {
            $qualifies = true;
            foreach ($speedups[$class] as $speedup) {
                $qualifies = $qualifies && $speedup['mean'] >= 1.5 && $speedup['p99'] >= 1.5;
            }
            $this->assertSame($qualifies, $class::usesW50RustFfi(), $class.' default must match its 1.5x gates: '.$summary);
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args, string $message): void
    {
        $entry = self::TARGETS[$class][0];
        $php = $class::{$entry.'Php'}(...$args);
        $this->assertSame($php, $class::{$entry.'Native'}(...$args), $class.' '.$message);
        $this->assertSame($php, $class::withW50RustFfi(true, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
        $this->assertSame($php, $class::withW50RustFfi(false, static fn (): array => $class::$entry(...$args)), $class.' '.$message);
    }

    /**
     * Common: typical batches (128 candidate rows, 96 pipeline results, 128 TVMaze episodes, 96 TVDB records, 75
     * hydration rows, the default max_tv_processed). Uncommon: 256 noisy, malformed or null-heavy rows.
     *
     * @return list<mixed>
     */
    private function benchArgs(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            TvProcessingCandidateQuery::class => $this->candidateArgs($random, $uncommon ? 256 : 128, $uncommon),
            TvProcessingPipeline::class => [$this->pipelineResults($random, $uncommon ? 256 : 96, $uncommon)],
            TvMazeProvider::class => [$this->tvMazeEpisodes($random, $uncommon ? 256 : 128, $uncommon)],
            TvdbProvider::class => $this->tvdbArgs($random, $uncommon ? 256 : 96, $uncommon),
            default => [$this->hydrationRows($random, $uncommon ? 256 : 75, $uncommon)],
        };
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    private function candidateArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $linked = $random->getInt(0, 2) === 0;
            $row = [
                'categories_id' => $noisy ? $random->getInt(4990, 6010) : $this->pick($random, [5030, 5040, 5040, 5045, 5070, 5080, 2040]),
                'size' => $noisy ? $random->getInt(-5, 3_000_000) : $random->getInt(500_000, 5_000_000_000),
                'videos_id' => $linked ? $random->getInt($noisy ? -3 : 1, 90_000) : 0,
                'tv_episodes_id' => $this->pick($random, $linked ? [-11, -11, -12, $random->getInt(1, 900_000)] : [0, 0, 0, -10, -100]),
                'tv_lookup_attempts' => $random->getInt($noisy ? -2 : 0, $noisy ? 12 : 6),
                'isrenamed' => $random->getInt(0, 1),
                'late_article_candidate_id' => $random->getInt(0, 9) === 0 ? $random->getInt(1, 50_000) : null,
                'tv_lookup_retry_at' => $random->getInt(0, 2) === 0 ? null
                    : ($noisy ? $this->pick($random, self::STAMPS) : gmdate('Y-m-d H:i:s', self::NOW + $random->getInt(-86_400, 86_400))),
            ];
            if ($noisy && $random->getInt(0, 3) === 0) {
                $row[$this->pick($random, ['size', 'isrenamed', 'late_article_candidate_id', 'tv_lookup_retry_at'])] = null;
            }
            $rows[] = $row;
        }

        return [$rows, $noisy ? $random->getInt(-1, 3) * self::NOW : self::NOW, $noisy ? $random->getInt(0, 2) : $random->getInt(1, 2)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pipelineResults(Randomizer $random, int $size, bool $noisy): array
    {
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $row = [
                'status' => $noisy ? $this->pick($random, self::STATUSES) : $this->pick($random, ['matched', 'matched', 'matched', 'episode_missing', 'not_found', 'transient']),
                'matched' => true,
                'video_id' => $random->getInt($noisy ? -5 : 0, 90_000),
                'episode_id' => $random->getInt($noisy ? -5 : 0, 900_000),
                'provider' => $noisy ? $this->pick($random, [...self::PROVIDERS, '', 'x'.$random->getInt(0, 40), 'tvdb']) : $this->pick($random, self::PROVIDERS),
                'title' => $noisy ? $this->randomName($random, 4, 30) : $this->pick($random, self::SHOWS),
            ];
            if ($noisy && $random->getInt(0, 3) === 0) {
                $row[$this->pick($random, ['provider', 'title', 'episode_id'])] = null;
            } elseif ($noisy && $random->getInt(0, 4) === 0) {
                $row['provider'] = null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tvMazeEpisodes(Randomizer $random, int $size, bool $noisy): array
    {
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $row = [
                'season' => $random->getInt(0, 19) === 0 ? null : $random->getInt($noisy ? -2 : 0, $noisy ? 120 : 9),
                'number' => $random->getInt(0, 9) === 0 ? null : $random->getInt($noisy ? -2 : 1, $noisy ? 2000 : 24),
                'airdate' => $noisy ? $this->pick($random, self::DATES) : $random->getInt(1995, 2026).'-'.str_pad((string) $random->getInt(1, 12), 2, '0', STR_PAD_LEFT)
                    .'-'.str_pad((string) $random->getInt(1, 28), 2, '0', STR_PAD_LEFT),
                'name' => $noisy ? $this->randomName($random, 0, 12) : $this->pick($random, ['Pilot', 'The One Where', 'Chapter '.$random->getInt(1, 9), '']),
                'summary' => $noisy ? $this->randomName($random, 0, 80) : '<p>'.$this->randomName($random, 10, 40).'</p>',
            ];
            if ($noisy && $random->getInt(0, 3) === 0) {
                $row[$this->pick($random, ['season', 'number', 'airdate', 'summary'])] = null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    private function tvdbArgs(Randomizer $random, int $size, bool $noisy): array
    {
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $row = [
                'seasonNumber' => $noisy ? $this->pick($random, [$random->getInt(0, 30), '', null, (string) $random->getInt(0, 9)]) : $random->getInt(0, 8),
                'number' => $noisy ? $this->pick($random, [$random->getInt(0, 30), '', null, (string) $random->getInt(0, 9)]) : $random->getInt(1, 24),
                'aired' => $noisy ? $this->pick($random, self::DATES) : $random->getInt(1995, 2026).'-0'.$random->getInt(1, 9).'-1'.$random->getInt(0, 9),
                'name' => $noisy ? $this->randomName($random, 0, 12) : $this->pick($random, ['Pilot', 'Episode '.$random->getInt(1, 24), '']),
            ];
            if ($noisy && $random->getInt(0, 3) === 0) {
                $row[$this->pick($random, ['seasonNumber', 'number', 'aired', 'name'])] = null;
            }
            $rows[] = $row;
        }

        return [$rows, $random->getInt(0, 8), $random->getInt(1, 24)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hydrationRows(Randomizer $random, int $size, bool $noisy): array
    {
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $show = str_replace(' ', '.', $this->pick($random, self::SHOWS));
            $s = $random->getInt(1, 12);
            $e = $random->getInt(1, 24);
            $tag = $this->pick($random, [
                sprintf('S%02dE%02d', $s, $e), sprintf('%dx%02d', $s, $e), sprintf('Season.%d.Episode.%d', $s, $e), sprintf('%d%02d', $s % 10, $e),
                sprintf('2024.%02d.%02d', $random->getInt(1, 12), $random->getInt(1, 28)), 'Part.'.$e, 'Ep.'.$e, 'E'.$e, 'Special',
            ]);
            $row = [
                'searchname' => $noisy ? $this->randomName($random, 4, 24) : $show.'.'.$tag.'.'.$this->pick($random, ['720p.HDTV.x264-GRP', '1080p.WEB.h264-GRP', 'iNTERNAL.480p']),
                'series' => $random->getInt(0, 4) === 0 ? $random->getInt(1, 9) : $this->pick($random, [null, 0, '']),
                'episode' => $random->getInt(0, 4) === 0 ? $random->getInt(1, 9) : $this->pick($random, [null, 0, '0']),
            ];
            if ($noisy && $random->getInt(0, 3) === 0) {
                $row[$this->pick($random, ['series', 'episode', 'searchname'])] = null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Drops one key from about a quarter of the rows, which sends the native packer through its per-row fill.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sparse(Randomizer $random, array $rows): array
    {
        foreach ($rows as $i => $row) {
            if ($row !== [] && $random->getInt(0, 3) === 0) {
                unset($rows[$i][$this->pick($random, array_keys($row))]);
            }
        }

        return $rows;
    }

    private function setOverride(string $class, ?bool $enabled): void
    {
        (new ReflectionClass($class))->setStaticPropertyValue('useW50RustFfi', $enabled);
    }

    private function defaultOf(string $class): bool
    {
        return (bool) (new ReflectionClass($class))->getConstant('W50_RUST_FFI_DEFAULT');
    }

    private function requireLibrary(): void
    {
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('categorize_w50', EpisodeHydrationService::W50_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }
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
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
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
