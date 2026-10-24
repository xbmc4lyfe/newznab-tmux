<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\Pipes\TmdbPipe;
use App\Services\TvProcessing\Pipes\TraktPipe;
use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_TV_PROCESSING_PIPES_PROVIDER_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 0.144 ms, Rust FFI median = 1.279 ms (0.11x; per-call FFI marshaling overhead dominates sub-microsecond pipe/provider metadata helpers)
 * - Round 2: PHP median = 0.145 ms, Rust FFI median = 1.280 ms (0.11x)
 * - Round 3: PHP median = 0.143 ms, Rust FFI median = 1.284 ms (0.11x)
 * - Overall: PHP stays the production default (`usesRustFfi() === false`) across all 5 classes because the 1.5x speedup threshold is not met.
 */
class RustTvProcessingPipesProviderParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_tv_pipe_resolve_spec(
        int32_t pipe_kind,
        int64_t *out_priority,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_tv_pipe_is_available(
        int32_t pipe_kind,
        uint32_t has_unavailable_reason,
        const char *reason_ptr,
        size_t reason_len
    );
    ssize_t nntmux_tv_local_db_normalize_show_lookup(
        const char *name_ptr,
        size_t name_len,
        uint32_t has_year,
        int64_t year,
        const char *country_ptr,
        size_t country_len,
        int64_t *out_scalars,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_tv_local_db_normalize_episode_lookup(
        int64_t site_id,
        int64_t season,
        int64_t episode,
        int64_t video_id,
        int64_t *out_scalars
    );
    CDEF;

    protected function tearDown(): void
    {
        TmdbPipe::resetRustFfi();
        TraktPipe::resetRustFfi();
        TvMazePipe::resetRustFfi();
        TvdbPipe::resetRustFfi();
        LocalDbProvider::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        $classes = [
            TmdbPipe::class,
            TraktPipe::class,
            TvMazePipe::class,
            TvdbPipe::class,
            LocalDbProvider::class,
        ];

        foreach ($classes as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP implementation");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: class-string<TmdbPipe|TraktPipe|TvMazePipe|TvdbPipe>, 1: array{name: string, site_column: string, priority: int, source_key: string}}>
     */
    public static function pipeSpecProvider(): array
    {
        return [
            'tvdb_pipe' => [
                TvdbPipe::class,
                [
                    'name' => 'TVDB',
                    'site_column' => 'tvdb',
                    'priority' => 20,
                    'source_key' => MetadataSources::TVDB,
                ],
            ],
            'tvmaze_pipe' => [
                TvMazePipe::class,
                [
                    'name' => 'TVMaze',
                    'site_column' => 'tvmaze',
                    'priority' => 30,
                    'source_key' => '',
                ],
            ],
            'tmdb_pipe' => [
                TmdbPipe::class,
                [
                    'name' => 'TMDB',
                    'site_column' => 'tmdb',
                    'priority' => 40,
                    'source_key' => MetadataSources::TMDB,
                ],
            ],
            'trakt_pipe' => [
                TraktPipe::class,
                [
                    'name' => 'Trakt',
                    'site_column' => 'trakt',
                    'priority' => 50,
                    'source_key' => MetadataSources::TRAKT,
                ],
            ],
        ];
    }

    /**
     * @param  class-string<TmdbPipe|TraktPipe|TvMazePipe|TvdbPipe>  $pipeClass
     * @param  array{name: string, site_column: string, priority: int, source_key: string}  $expectedSpec
     */
    #[DataProvider('pipeSpecProvider')]
    public function test_tv_pipe_resolve_spec_and_availability_parity(string $pipeClass, array $expectedSpec): void
    {
        $this->requireMetadataFfi();

        $phpSpec = $pipeClass::resolvePipeSpecPhp();
        $nativeSpec = $pipeClass::resolvePipeSpecNative();
        $this->assertSame($expectedSpec, $phpSpec);
        $this->assertSame($phpSpec, $nativeSpec);

        foreach ([null, 'API key is not configured', ''] as $reason) {
            $phpAvail = $pipeClass::evaluateAvailabilityPhp($reason);
            $nativeAvail = $pipeClass::evaluateAvailabilityNative($reason);
            $this->assertSame($reason === null, $phpAvail);
            $this->assertSame($phpAvail, $nativeAvail);
        }

        $instance = new $pipeClass;
        foreach ([false, true] as $useRust) {
            $pipeClass::setUseRustFfi($useRust);
            $this->assertSame($phpSpec, $pipeClass::resolvePipeSpec());
            $this->assertSame($expectedSpec['name'], $instance->getName());
            $this->assertSame($expectedSpec['priority'], $instance->getPriority());
            $this->assertTrue($pipeClass::evaluateAvailability(null));
            $this->assertFalse($pipeClass::evaluateAvailability('missing key'));
        }
    }

    /**
     * @return array<string, array{0: string, 1: ?int, 2: string}>
     */
    public static function localDbShowLookupProvider(): array
    {
        return [
            'standard_show_with_year_and_country' => ['  The Expanse  ', 2015, ' us '],
            'empty_name_and_zero_year' => [" \t\n ", 0, ''],
            'negative_year_normalized_to_null' => ['Severance', -1, 'uk'],
            'utf8_show_title_and_null_year' => ['進撃の巨人 — Attack on Titan', null, 'jp'],
        ];
    }

    #[DataProvider('localDbShowLookupProvider')]
    public function test_local_db_provider_normalize_show_lookup_parity(
        string $name,
        ?int $year,
        string $country,
    ): void {
        $this->requireMetadataFfi();

        $php = LocalDbProvider::normalizeShowLookupPhp($name, $year, $country);
        $native = LocalDbProvider::normalizeShowLookupNative($name, $year, $country);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            LocalDbProvider::setUseRustFfi($useRust);
            $this->assertSame($php, LocalDbProvider::normalizeShowLookup($name, $year, $country));
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function localDbEpisodeLookupProvider(): array
    {
        return [
            'valid_episode_lookup' => [12345, 2, 8, 99],
            'special_season_zero_episode_zero' => [0, 0, 0, 42],
            'missing_video_id_invalid' => [100, 1, 1, 0],
            'negative_values_clamped_and_invalid' => [-5, -1, -2, -10],
        ];
    }

    #[DataProvider('localDbEpisodeLookupProvider')]
    public function test_local_db_provider_normalize_episode_lookup_parity(
        int $siteId,
        int $season,
        int $episode,
        int $videoId,
    ): void {
        $this->requireMetadataFfi();

        $php = LocalDbProvider::normalizeEpisodeLookupPhp($siteId, $season, $episode, $videoId);
        $native = LocalDbProvider::normalizeEpisodeLookupNative($siteId, $season, $episode, $videoId);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            LocalDbProvider::setUseRustFfi($useRust);
            $this->assertSame($php, LocalDbProvider::normalizeEpisodeLookup($siteId, $season, $episode, $videoId));
        }
    }

    public function test_all_five_classes_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        foreach ([TvdbPipe::class, TvMazePipe::class, TmdbPipe::class, TraktPipe::class] as $pipeClass) {
            $pipeClass::setFfiForTesting(false);
            $pipeClass::enableRustFfi();
            $this->assertSame($pipeClass::resolvePipeSpecPhp(), $pipeClass::resolvePipeSpec());
            $this->assertTrue($pipeClass::evaluateAvailability(null));
            $this->assertFalse($pipeClass::evaluateAvailability('unavailable'));
        }

        LocalDbProvider::setFfiForTesting(false);
        LocalDbProvider::enableRustFfi();
        $this->assertSame(
            LocalDbProvider::normalizeShowLookupPhp('  Andor ', 2022, ' us '),
            LocalDbProvider::normalizeShowLookup('  Andor ', 2022, ' us ')
        );
        $this->assertSame(
            LocalDbProvider::normalizeEpisodeLookupPhp(10, 1, 5, 77),
            LocalDbProvider::normalizeEpisodeLookup(10, 1, 5, 77)
        );
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_kinds_and_undersized_buffers(): void
    {
        $ffi = $this->requireMetadataFfi();

        $raw = $ffi->new('uint8_t[128]');
        $unalignedInt64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $unalignedSizeT = $ffi->cast('size_t *', FFI::addr($raw[1]));

        $smallBuf = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $smallBuf[$i] = 'Z';
        }

        // 1. nntmux_tv_pipe_resolve_spec & nntmux_tv_pipe_is_available
        $prio = $ffi->new('int64_t[1]');
        $lens3 = $ffi->new('size_t[3]');
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', 0, $prio, $lens3, $smallBuf, 4));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', 5, $prio, $lens3, $smallBuf, 4));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', 3, $unalignedInt64, $lens3, $smallBuf, 4));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', 3, $prio, $unalignedSizeT, $smallBuf, 4));
        $this->assertSame(-12, (int) self::callFfi($ffi, 'nntmux_tv_pipe_resolve_spec', 3, $prio, $lens3, $smallBuf, 4));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_tv_pipe_is_available', 99, 0, '', 0));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_tv_pipe_is_available', 1, 2, '', 0));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_tv_pipe_is_available', 1, 1, null, 5));

        // 2. nntmux_tv_local_db_normalize_show_lookup & normalize_episode_lookup
        $scalars3 = $ffi->new('int64_t[3]');
        $lens2 = $ffi->new('size_t[2]');
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_tv_local_db_normalize_show_lookup',
            'Severance',
            9,
            2,
            2022,
            'us',
            2,
            $scalars3,
            $lens2,
            $smallBuf,
            4
        ));
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_tv_local_db_normalize_show_lookup',
            'Severance',
            9,
            1,
            2022,
            'us',
            2,
            $unalignedInt64,
            $lens2,
            $smallBuf,
            4
        ));
        $this->assertSame(-11, (int) self::callFfi(
            $ffi,
            'nntmux_tv_local_db_normalize_show_lookup',
            'Severance',
            9,
            1,
            2022,
            'us',
            2,
            $scalars3,
            $lens2,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_tv_local_db_normalize_episode_lookup',
            1,
            1,
            1,
            1,
            $unalignedInt64
        ));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_TV_PROCESSING_PIPES_PROVIDER_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_TV_PROCESSING_PIPES_PROVIDER_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $runBatch = static function (int $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                TvdbPipe::resolvePipeSpec();
                TvdbPipe::evaluateAvailability(null);
                TvMazePipe::resolvePipeSpec();
                TvMazePipe::evaluateAvailability(null);
                TmdbPipe::resolvePipeSpec();
                TmdbPipe::evaluateAvailability('TMDB_APIKEY is not set');
                TraktPipe::resolvePipeSpec();
                TraktPipe::evaluateAvailability(null);
                LocalDbProvider::normalizeShowLookup('  The Expanse  ', 2015, ' us ');
                LocalDbProvider::normalizeEpisodeLookup(12345, 2, 8, 99);
            }
        };

        $setAllModes = static function (bool $rust): void {
            TvdbPipe::setUseRustFfi($rust);
            TvMazePipe::setUseRustFfi($rust);
            TmdbPipe::setUseRustFfi($rust);
            TraktPipe::setUseRustFfi($rust);
            LocalDbProvider::setUseRustFfi($rust);
        };

        for ($w = 0; $w < 8; $w++) {
            $setAllModes(false);
            $runBatch(10);
            $setAllModes(true);
            $runBatch(10);
        }

        $rounds = 3;
        $samples = 41;
        $iterations = 200;
        $summary = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $phpTimes = [];
            $rustTimes = [];

            for ($s = 0; $s < $samples; $s++) {
                $rustFirst = (($round + $s) % 2) === 1;
                if ($rustFirst) {
                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;
                } else {
                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;
                }
            }

            sort($phpTimes);
            sort($rustTimes);
            $phpMedian = $phpTimes[intdiv($samples, 2)];
            $rustMedian = $rustTimes[intdiv($samples, 2)];
            $speedup = $phpMedian / max(0.0001, $rustMedian);
            $summary[] = sprintf(
                'Round %d: PHP median=%.3f ms, Rust median=%.3f ms, speedup=%.2fx',
                $round,
                $phpMedian,
                $rustMedian,
                $speedup
            );
        }

        fwrite(STDERR, "\n[Wave 27.4 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount(3, $summary);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function requireMetadataFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::DIRECT_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('Rust metadata FFI library is not built or available.');
        }

        return $ffi;
    }
}
