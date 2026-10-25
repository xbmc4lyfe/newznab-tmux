<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvProcessingRunState;
use App\Services\TvProcessing\TvReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use Tests\TestCase;

final class RustTvProcessingParsedContextPassableResultStateW47ParityTest extends TestCase
{
    /** Per class: [default constant, toggle suffix, batch entry]. */
    private const TARGETS = [
        ParsedTvRelease::class => ['W47_PARSED_RELEASE_RUST_FFI_DEFAULT', 'ParsedRelease', 'w47NormalizeBatch'],
        TvReleaseContext::class => ['W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT', 'ReleaseContext', 'w47EligibilityBatch'],
        TvProcessingPassable::class => ['W47_PASSABLE_RUST_FFI_DEFAULT', 'Passable', 'w47TraceBatch'],
        TvProcessingResult::class => ['W47_RESULT_RUST_FFI_DEFAULT', 'Result', 'w47AggregateBatch'],
        TvProcessingRunState::class => ['W47_RUN_STATE_RUST_FFI_DEFAULT', 'RunState', 'w47BackoffBatch'],
    ];

    /** Benchmark-gated production defaults (see the Wave 47.3 commit message for the measurements). */
    private const DEFAULTS = [
        ParsedTvRelease::class => true,
        TvReleaseContext::class => true,
        TvProcessingPassable::class => false,
        TvProcessingResult::class => false,
        TvProcessingRunState::class => true,
    ];

    private const NAMES = [
        '', ' ', '.', '-', '[]()', '_._', 'The.Office.US.S05E14.720p.HDTV-GRP', 'Show Name 2024 01 15 [1080p]',
        '1923.2022.S01.COMPLETE', 'Show_3x07_s0123e45678', 'S01E01', 's1234e5', 'S01E', 'S01E123456', '123x45', '12x4567',
        'Doctor.Who.2005.S13E01.UK', 'A.B.2020.13.40.S1E1.US', 'Show.us.S01E01', 'Show.US.UK.S01E01', 'Show.2024.02.30.x',
        '2024.01.01.News', 'News.1999', 'News.1899.S01E01', "Caf\xC3\xA9.UK.s01e02", "bad.\xff.utf8.S02E03", "tab\tname\nS01E01",
        'Show.Name.(2019).S02E05.Episode.Title.1080p.WEB-DL.DDP5.1.H.264-GRP', 'Show-Name-1x01', 'Show.S01.E01', 'X.S001E0001',
        'Show.Name.S03.1080p.BluRay-GRP', 'Talk.Show.2023.11.07.Guest.720p.WEB.h264-GRP', 'IT.Crowd.S01E01', 'Show.IT.S01E01',
    ];

    private const WORDS = ['Show', 'Name', 'The', 'Office', '.', ' ', '_', '-', '[', ']', '(', ')', 'S01E02', 's3e10', '3x07', 'S02',
        '2024', '01', '15', '1999', '13', '32', 'US', 'UK', 'us', 'IT', '720p', '1080p', 'WEB-DL', 'GRP', "\t", "\xC3\xA9", "\xff", '0'];

    private const PROVIDERS = ['TVDB', 'TVMaze', 'TMDB', 'Trakt', 'Local DB', 'existing', 'pipeline', 'tvdb', 'TvMaze', 'Other', '', 'É'];

    private const STATUSES = ['matched', 'season_pack', 'episode_missing', 'not_found', 'parse_failed', 'transient', 'skipped', 'pending',
        'MATCHED', '', 'weird', "matched\n"];

    protected function tearDown(): void
    {
        ParsedTvRelease::useW47ParsedReleaseRustFfi(null);
        TvReleaseContext::useW47ReleaseContextRustFfi(null);
        TvProcessingPassable::useW47PassableRustFfi(null);
        TvProcessingResult::useW47ResultRustFfi(null);
        TvProcessingRunState::useW47RunStateRustFfi(null);
        parent::tearDown();
    }

    public function test_w47_methods_and_constants_exist(): void
    {
        $missing = [];
        foreach (self::TARGETS as $class => [$constant, $suffix, $entry]) {
            $reflection = new ReflectionClass($class);
            foreach (["useW47{$suffix}RustFfi", "usesW47{$suffix}RustFfi", $entry, $entry.'Php', $entry.'Native', 'w47RustFfi'] as $method) {
                if (! $reflection->hasMethod($method)) {
                    $missing[] = $class.'::'.$method;
                }
            }
            foreach ([$constant, 'W47_FFI_CDEF'] as $name) {
                if (! $reflection->hasConstant($name)) {
                    $missing[] = $class.'::'.$name;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_w47_defaults_and_routing(): void
    {
        foreach (self::TARGETS as $class => [$constant, $suffix]) {
            $this->assertSame(self::DEFAULTS[$class], \constant($class.'::'.$constant), $class);
            $this->assertSame(\constant($class.'::'.$constant), $class::{"usesW47{$suffix}RustFfi"}(), $class);
            $class::{"useW47{$suffix}RustFfi"}(true);
            $this->assertTrue($class::{"usesW47{$suffix}RustFfi"}());
            $class::{"useW47{$suffix}RustFfi"}(false);
            $this->assertFalse($class::{"usesW47{$suffix}RustFfi"}());
            $class::{"useW47{$suffix}RustFfi"}(null);
            $this->assertSame(self::DEFAULTS[$class], $class::{"usesW47{$suffix}RustFfi"}());
        }

        $names = self::NAMES;
        $expected = ParsedTvRelease::w47NormalizeBatchPhp($names);
        foreach ([true, false] as $enable) {
            ParsedTvRelease::useW47ParsedReleaseRustFfi($enable);
            $this->assertSame($expected, ParsedTvRelease::w47NormalizeBatch($names));
        }
    }

    public function test_w47_exports_resolve_when_library_is_built(): void
    {
        $missing = [];
        foreach (array_keys(self::TARGETS) as $class) {
            $cdef = \constant($class.'::W47_FFI_CDEF');
            if (RustPipelineLibrary::resolveDomain('categorize_w47', $cdef) === null) {
                $missing[] = $class;
            }
        }
        if (! \extension_loaded('FFI') || RustPipelineLibrary::resolveDomain('metadata', ParsedTvRelease::TV_FFI_CDEF) === null) {
            $this->markTestSkipped('libnntmux_pipeline is not available.');
        }

        $this->assertSame([], $missing);
        $ffi = RustPipelineLibrary::resolveDomain('categorize_w47', ParsedTvRelease::W47_FFI_CDEF);
        $this->assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('char[56]');
        // @phpstan-ignore method.notFound
        $this->assertSame(56, $ffi->nntmux_w47_tv_parsed_release_batch('Show.S01E02', 11, 1, $out, 56));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w47_tv_parsed_release_batch("a\0b", 3, 1, $out, 56));
        $this->assertSame(array_values((array) unpack('P*', FFI::string($out, 56))), ParsedTvRelease::w47NormalizeBatchPhp(['Show.S01E02']));
    }

    public function test_parsed_release_parity(): void
    {
        $this->assertSame([], ParsedTvRelease::w47NormalizeBatchPhp([]));
        $this->assertSame([], ParsedTvRelease::w47NormalizeBatchNative([]));
        $this->assertSame(
            [5, 14, 0, 0, (\ord('U') << 8) | \ord('S'), 2, $this->fnv('the office')],
            ParsedTvRelease::w47NormalizeBatchPhp(['The.Office.US.S05E14.720p.HDTV-GRP']),
        );
        $this->assertSame(
            [-1, -1, 20240115, 0, 0, 2, $this->fnv('show name'), 1, 0, 0, 2022, 0, 1, $this->fnv('1923')],
            ParsedTvRelease::w47NormalizeBatchPhp(['Show Name 2024 01 15 [1080p]', '1923.2022.S01.COMPLETE']),
        );

        $batches = [self::NAMES, [''], ["with\0nul.S01E01", 'x'], ["\0"]];
        $random = new Randomizer(new Mt19937(473));
        foreach ([64, 96, 128] as $size) {
            $batches[] = array_map(fn (): string => $this->randomName($random, 2, 10), range(1, $size));
            $batches[] = array_map(fn (): string => $this->randomName($random, 30, 80), range(1, $size));
        }
        foreach ($batches as $i => $names) {
            $this->assertSame(ParsedTvRelease::w47NormalizeBatchPhp($names), ParsedTvRelease::w47NormalizeBatchNative($names), "batch {$i}");
        }
    }

    public function test_release_context_parity(): void
    {
        $this->assertSame([0, 0], TvReleaseContext::w47EligibilityBatchPhp([]));
        $this->assertSame([0, 0], TvReleaseContext::w47EligibilityBatchNative([]));
        $fixed = [
            new TvReleaseContext(1, '  Show.Name.S01E02.720p ', 10, 5030),
            new TvReleaseContext(2, 'x', 0, 5040, 7),
            new TvReleaseContext(3, "Caf\xC3\xA9 S1E1", 10, 5000, 0, 9),
            new TvReleaseContext(0, 'Movie.2020', 10, 2000),
            new TvReleaseContext(-5, '', -1, 5999, -3, -4),
            new TvReleaseContext(PHP_INT_MAX, "\t._ \t", PHP_INT_MIN, 6000),
            new TvReleaseContext(9, 'Show s01e', 1, 5070),
            new TvReleaseContext(9, 'Shows01e02', 1, 4999),
        ];
        $this->assertSame(
            [21, $this->fnv('show name s01e02 720p'), 1 | 8, 90],
            \array_slice(TvReleaseContext::w47EligibilityBatchPhp($fixed), 0, 4),
        );

        $batches = [$fixed, [new TvReleaseContext(1, "nul\0name", 1, 5030)], [new TvReleaseContext(1, '', 1, 5030)]];
        $random = new Randomizer(new Mt19937(4732));
        foreach ([64, 128] as $size) {
            foreach ([[2, 8], [30, 70]] as [$min, $max]) {
                $batches[] = array_map(fn (int $i): TvReleaseContext => new TvReleaseContext(
                    $random->getInt(-2, 1_000_000),
                    $this->randomName($random, $min, $max),
                    $random->getInt(-1, 500),
                    $random->pickArrayKeys([5030 => 1, 5040 => 1, 5070 => 1, 2000 => 1, 5999 => 1, 6000 => 1], 1)[0],
                    $random->getInt(0, 3) === 0 ? $random->getInt(1, 9999) : 0,
                    $random->getInt(0, 5) === 0 ? $random->getInt(1, 9999) : 0,
                ), range(1, $size));
            }
        }
        foreach ($batches as $i => $contexts) {
            $this->assertSame(TvReleaseContext::w47EligibilityBatchPhp($contexts), TvReleaseContext::w47EligibilityBatchNative($contexts), "batch {$i}");
        }
    }

    public function test_passable_parity(): void
    {
        $empty = [0, 0, 0, 0, 0, 0, 0, -1];
        $this->assertSame($empty, TvProcessingPassable::w47TraceBatchPhp([]));
        $this->assertSame($empty, TvProcessingPassable::w47TraceBatchNative([]));

        $matched = $this->passable(9);
        $matched->setParsed($this->parsed());
        $matched->markMatched(77, 'TVDB');
        $missing = $this->passable(4);
        $missing->setParsed($this->parsed());
        $missing->markTransient('tvmaze');
        $missing->markTransient('tvmaze', 30);
        $missing->markTransient('TMDB');
        $this->assertSame(
            [1, 95, 0, $this->fnv("1existing\x1ETVDB\x1E"), 3, 25, 2, $this->fnv("3existing\x1E\x1Etvmaze\x1Ftvmaze\x1FTMDB")],
            \array_slice(TvProcessingPassable::w47TraceBatchPhp([$matched, $missing]), 0, 8),
        );

        $failed = $this->passable(0);
        $failed->markParseFailed();
        $pack = $this->passable(0);
        $pack->setParsed($this->parsed());
        $pack->setShow(12, 'Trakt');
        $pack->markSeasonPack('Local DB');
        $transient = $this->passable(0);
        $transient->setParsed($this->parsed());
        $transient->markTransient('pipeline', -50);
        $notFound = $this->passable(0);
        $notFound->setParsed($this->parsed());
        $emptyName = $this->passable(0);
        $emptyName->setParsed($this->parsed());
        $emptyName->markTransient('');
        $edge = $this->passable(-3);
        $edge->setParsed($this->parsed());
        $edge->markMatched(PHP_INT_MAX, 'É');
        $fixed = [$matched, $missing, $failed, $pack, $transient, $notFound, $emptyName, $edge, $this->passable(5)];

        $sep = $this->passable(0);
        $sep->setParsed($this->parsed());
        $sep->markTransient("a\x1Fb");
        $sep2 = $this->passable(0);
        $sep2->setShow(3, "x\x1Ey");
        $nul = $this->passable(0);
        $nul->markMatched(1, "nul\0");
        $batches = [$fixed, [$sep], [$sep2], [$nul, $matched]];
        $random = new Randomizer(new Mt19937(4733));
        foreach ([64, 128] as $size) {
            foreach ([2, 12] as $transients) {
                $batches[] = array_map(fn (): TvProcessingPassable => $this->randomPassable($random, $transients), range(1, $size));
            }
        }
        foreach ($batches as $i => $passables) {
            $this->assertSame(TvProcessingPassable::w47TraceBatchPhp($passables), TvProcessingPassable::w47TraceBatchNative($passables), "batch {$i}");
        }
    }

    public function test_result_parity(): void
    {
        $empty = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, -1, 0];
        $this->assertSame($empty, TvProcessingResult::w47AggregateBatchPhp([]));
        $this->assertSame($empty, TvProcessingResult::w47AggregateBatchNative([]));
        $fixed = [
            TvProcessingResult::matched(12, 34, 'TVDB'),
            TvProcessingResult::transient(null, 30),
            TvProcessingResult::matched(12, 35, 'TMDB'),
            new TvProcessingResult('weird', -4),
            TvProcessingResult::seasonPack(13, 'Trakt'),
            TvProcessingResult::episodeMissing(14, null, [], true, PHP_INT_MAX),
            TvProcessingResult::notFound(),
            TvProcessingResult::parseFailed(),
            TvProcessingResult::skipped('x', 'Local DB'),
            TvProcessingResult::pending(),
            new TvProcessingResult('', PHP_INT_MIN, PHP_INT_MIN, '', [], false, PHP_INT_MIN),
        ];
        $php = TvProcessingResult::w47AggregateBatchPhp($fixed);
        $this->assertSame([1, $this->fnv("matched\x1FTVDB\x1F12\x1F34"), 6, $this->fnv("transient\x1F\x1F\x1F")], \array_slice($php, 0, 4));
        $this->assertSame([2, 2, 1, 1, 1, 1, 1, 1, 1, 2, 3, 2, 1 << 31, (1 << 31) + 30], \array_slice($php, 22));

        $batches = [$fixed, [new TvProcessingResult("mat\x1Fched")], [TvProcessingResult::notFound("a\x1Fb")], [TvProcessingResult::notFound("nul\0")]];
        $random = new Randomizer(new Mt19937(4734));
        foreach ([64, 128] as $size) {
            foreach ([4, 40] as $providerLength) {
                $batches[] = array_map(fn (): TvProcessingResult => $this->randomResult($random, $providerLength), range(1, $size));
            }
        }
        foreach ($batches as $i => $results) {
            $this->assertSame(TvProcessingResult::w47AggregateBatchPhp($results), TvProcessingResult::w47AggregateBatchNative($results), "batch {$i}");
        }
    }

    public function test_run_state_parity(): void
    {
        $this->assertSame([0, 0, 0], TvProcessingRunState::w47BackoffBatchPhp([], 0));
        $this->assertSame([0, 0, 0], TvProcessingRunState::w47BackoffBatchNative([], 0));
        $fixed = [
            ['provider' => 'TVDB', 'video_id' => 5, 'episode_key' => 'S01E01', 'failures' => 4, 'last_failure_at' => 900],
            ['provider' => 'tvdb', 'video_id' => -2, 'episode_key' => '', 'failures' => 0, 'last_failure_at' => 0],
            ['provider' => 'Trakt', 'video_id' => 9, 'episode_key' => 'x', 'failures' => 99, 'last_failure_at' => 990],
            [],
            ['provider' => 123, 'video_id' => '77abc', 'episode_key' => 5, 'failures' => '8', 'last_failure_at' => PHP_INT_MAX],
            ['provider' => 'TMDB', 'failures' => -9, 'last_failure_at' => PHP_INT_MIN],
            ['provider' => "\xC3\x89", 'video_id' => PHP_INT_MIN, 'failures' => 3, 'last_failure_at' => 1000],
        ];
        $php = TvProcessingRunState::w47BackoffBatchPhp($fixed, 1000);
        $this->assertSame([$this->fnv('tvdb:5:S01E01'), 120, 1020, 20], \array_slice($php, 0, 4));
        $this->assertSame([$this->fnv('trakt:9:x'), 3600, 4590, 3590], \array_slice($php, 8, 4));

        $batches = [$fixed, [['provider' => "a\x1Fb"]], [['episode_key' => "nul\0"]]];
        $random = new Randomizer(new Mt19937(4735));
        foreach ([64, 128] as $size) {
            foreach ([8, 60] as $keyLength) {
                $batches[] = array_map(fn (): array => [
                    'provider' => self::PROVIDERS[$random->getInt(0, \count(self::PROVIDERS) - 1)],
                    'video_id' => $random->getInt(-10, 1_000_000),
                    'episode_key' => 'k'.$random->getBytesFromString('abcdefSE0123456789:-', $random->getInt(1, $keyLength)),
                    'failures' => $random->getInt(-1, 12),
                    'last_failure_at' => 1_700_000_000 + $random->getInt(-4000, 4000),
                ], range(1, $size));
            }
        }
        foreach ($batches as $i => $items) {
            foreach ([0, 1_700_000_000, PHP_INT_MAX, PHP_INT_MIN] as $now) {
                $this->assertSame(TvProcessingRunState::w47BackoffBatchPhp($items, $now), TvProcessingRunState::w47BackoffBatchNative($items, $now), "batch {$i} now {$now}");
            }
        }
    }

    private function fnv(string $bytes): int
    {
        return (int) hexdec(hash('fnv1a32', $bytes));
    }

    private function randomName(Randomizer $random, int $minWords, int $maxWords): string
    {
        $name = '';
        for ($i = $random->getInt($minWords, $maxWords); $i > 0; $i--) {
            $name .= self::WORDS[$random->getInt(0, \count(self::WORDS) - 1)];
        }

        return $name;
    }

    private function parsed(): ParsedTvRelease
    {
        return new ParsedTvRelease('Show', 'Show', '', 1, 2, false, null, null);
    }

    private function passable(int $videosId): TvProcessingPassable
    {
        return new TvProcessingPassable(new TvReleaseContext(1, 'Show.S01E02', 1, 5030, $videosId), new TvProcessingRunState);
    }

    private function randomPassable(Randomizer $random, int $maxTransients): TvProcessingPassable
    {
        $pick = fn (): string => self::PROVIDERS[$random->getInt(0, \count(self::PROVIDERS) - 1)];
        $passable = $this->passable($random->getInt(0, 2) === 0 ? $random->getInt(1, 5000) : 0);
        match ($random->getInt(0, 9)) {
            0 => $passable->markParseFailed(),
            1 => null,
            default => $passable->setParsed($this->parsed()),
        };
        if ($random->getInt(0, 1) === 1) {
            $passable->setShow($random->getInt(1, 5000), $pick());
        }
        match ($random->getInt(0, 3)) {
            0 => $passable->markMatched($random->getInt(1, 99999), $pick()),
            1 => $passable->markSeasonPack($pick()),
            default => null,
        };
        for ($i = $random->getInt(0, $maxTransients); $i > 0; $i--) {
            $passable->markTransient($pick(), $random->getInt(0, 1) === 1 ? $random->getInt(-5, 900) : null);
        }

        return $passable;
    }

    private function randomResult(Randomizer $random, int $providerLength): TvProcessingResult
    {
        $status = self::STATUSES[$random->getInt(0, \count(self::STATUSES) - 1)];
        $provider = match ($random->getInt(0, 2)) {
            0 => null,
            1 => self::PROVIDERS[$random->getInt(0, \count(self::PROVIDERS) - 1)],
            default => $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz ', $random->getInt(1, $providerLength)),
        };

        return new TvProcessingResult(
            $status,
            $random->getInt(0, 3) === 0 ? null : $random->getInt(-5, 40),
            $random->getInt(0, 2) === 0 ? null : $random->getInt(-5, 1_000_000),
            $provider,
            [],
            $random->getInt(0, 3) === 0,
            $random->getInt(0, 2) === 0 ? null : $random->getInt(-100, 7200),
        );
    }
}
