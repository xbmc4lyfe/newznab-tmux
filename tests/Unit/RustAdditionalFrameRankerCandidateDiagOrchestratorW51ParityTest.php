<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalProcessingDiagnostics;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustAdditionalFrameRankerCandidateDiagOrchestratorW51ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        FrameQualityScorer::class => 'scoreFrameBatch',
        ImageCandidateRanker::class => 'rankCandidateBatch',
        AdditionalCandidateQuery::class => 'planClaimBatch',
        AdditionalProcessingDiagnostics::class => 'matchClaimIndexBatch',
        AdditionalProcessingOrchestrator::class => 'summarizeReleaseRunBatch',
    ];

    /**
     * Apple M5/macOS arm64 (shared host, load ~7-9), PHP 8.5.12 CLI without opcache/JIT, release
     * Rust; base c1571d003 plus this change, seed 516, 50 warmups and 1,000 pairs/workload
     * (w516-benchmark-8b169d24487059ac.json in system tmp). Common mean/p99 then uncommon mean/p99
     * speedups (PHP time / Rust time); a default is Rust only when all four reach 1.5x. Across three
     * further 1,000-pair runs of the final code under heavier load, the lowest values were
     * AdditionalCandidateQuery's common p99 at 1.677x and AdditionalProcessingOrchestrator's at
     * 1.743x (host-contention tails on ~6us native calls); every mean stayed above 2.5x.
     */
    private const QUALIFICATION = [
        FrameQualityScorer::class => [27.455, 18.585, 26.957, 23.680],
        ImageCandidateRanker::class => [3.333, 3.147, 3.114, 2.892],
        AdditionalCandidateQuery::class => [2.963, 2.853, 2.910, 2.881],
        AdditionalProcessingDiagnostics::class => [4.681, 2.986, 4.146, 3.211],
        AdditionalProcessingOrchestrator::class => [4.241, 3.726, 5.154, 3.977],
    ];

    private const REQUIRED = ['passwordstatus', 'haspreview', 'nzbstatus', 'leftguid', 'postdate', 'id', 'additional_pp_claimed_at'];

    private const NOW = 1_790_000_000;

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW51RustFfi', 'withW51RustFfi', 'w51Binding', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertTrue($reflection->getMethod('w51Binding')->isPrivate(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW51RustFfi', 'withW51RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W51_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w516_', (string) $reflection->getConstant('W51_FFI_CDEF'), $class);
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (self::WAVE as $class => $method) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W51_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW51RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW51RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW51RustFfi(! $enabled, static fn (): bool => $class::usesW51RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W51_RUST_FFI_DEFAULT'), $other::usesW51RustFfi());
                        }
                    }

                    return $class::usesW51RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW51RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW51RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW51RustFfi(), $class);
        }
        // The Wave 51 switches leave the earlier toggles alone.
        self::assertFalse(FrameQualityScorer::usesRustFfi());
        self::assertFalse(ImageCandidateRanker::usesRustFfi());
        self::assertFalse(AdditionalCandidateQuery::usesW416RustFfi());
        self::assertFalse(AdditionalProcessingDiagnostics::usesW416RustFfi());
        self::assertFalse(AdditionalProcessingOrchestrator::usesRustFfi());
        self::assertFalse(AdditionalProcessingOrchestrator::usesW416RustFfi());
    }

    public function test_frame_quality_score_parity(): void
    {
        $this->requireBindings();
        $this->assertFrameParity([], 2.0, 20.0, 235.0);
        $this->assertFrameParity([''], 2.0, 20.0, 235.0);
        $flat = str_repeat("\x80\x80\x80", 16);
        $black = str_repeat("\0\0\0", 30);
        $white = str_repeat("\xFF\xFF\xFF", 30);
        $busy = '';
        for ($i = 0; $i < 144; $i++) {
            $busy .= \chr($i * 37 % 256).\chr($i * 11 % 256).\chr(255 - $i);
        }
        $samples = ['x' => $flat, 'y' => "\x01\x02", 3 => $black, 4 => $white, 5 => $busy, 6 => '', 7 => "\x10\x20\x30", 8 => substr($busy, 0, 300).$flat];
        $this->assertFrameParity($samples, 2.0, 20.0, 235.0);
        $this->assertFrameParity($samples, 0.0, 0.0, 255.0);
        $this->assertFrameParity($samples, 500.0, 300.0, -1.0);
        $this->assertFrameParity([$busy, $busy, $flat, $busy], 2.0, 20.0, 235.0);

        $out = FrameQualityScorer::scoreFrameBatchPhp([$flat, "\x01\x02", $busy], 2.0, 20.0, 235.0);
        self::assertSame([3, 2, 1, 1, 2], \array_slice($out, 0, 5));
        self::assertSame(1, $out[7]);
        self::assertSame(0b111, $out[FrameQualityScorer::W51_FRAME_HEADER] & 7);
        self::assertSame(0, $out[FrameQualityScorer::W51_FRAME_HEADER + 1]);
        // Each row carries measureRgbPhp()'s values; the chosen frame is the one best() picks.
        $measure = FrameQualityScorer::measureRgbPhp($busy);
        self::assertNotNull($measure);
        self::assertSame((int) floor($measure['mean'] * 1000.0), $out[5]);
        self::assertSame((int) floor($measure['stddev'] * 1000.0), $out[6]);
        self::assertSame($out[FrameQualityScorer::W51_FRAME_HEADER + 2], 1 | 1 << 2 | $out[5] << 4 | $out[6] << 24);
    }

    public function test_image_candidate_rank_parity(): void
    {
        $this->requireBindings();
        $this->assertRankParity([], []);
        $this->assertRankParity([''], []);
        $names = ['cover.jpg', 'Movie.2024.screens.jpg', 'CD1.jpg', 'random.jpg', 'front.png', 'cover.back.jpg', 'CD1.screens.jpg',
            '[01/10] - "poster.jpg" yEnc (1/1)', 'C:\\rel\\Proof.JPG', '  contact sheet.png  ', 'contact_sheets.jpg', 'disc12.jpg',
            'sigs.jpg', 'keyart.webp', 'x/y/z/', 'café cover.jpg', '""cover.jpg"', "nul\0cover.jpg", "\0thumb.jpg\0", 'a"b', 'cd1x.jpg'];
        $this->assertRankParity($names, [10, 5, 99, 7, 50, 1, 2, 3, PHP_INT_MAX, -5, 0]);
        $this->assertRankParity(['b' => 'cover.jpg', 'a' => 'screens.jpg'], ['z' => 9, 'q' => 1]);
        $this->assertRankParity(array_fill(0, 9, 'same.jpg'), [1, 3, 3, 3, 1, 0, 9, 9, 2]);
        // Rust declines names whose PHP file-name semantics it does not model.
        self::assertNull(ImageCandidateRanker::rankCandidateBatchNative(['café cover.jpg'], [0]));
        self::assertNull(ImageCandidateRanker::rankCandidateBatchNative(['dir/'], [0]));

        $out = ImageCandidateRanker::rankCandidateBatchPhp(['cover.jpg', 'Movie.screens.jpg', 'cd1.jpg', 'x.jpg', 'front.png'], [10, 5, 99, 7, 50]);
        self::assertSame([5, 1, 2, 1, 1, 1], \array_slice($out, 0, 6));
        self::assertSame([1, 4, 0, 3, 2], \array_slice($out, ImageCandidateRanker::W51_RANK_HEADER + 5));
        $items = [['cover.jpg', 10], ['Movie.screens.jpg', 5], ['cd1.jpg', 99], ['x.jpg', 7], ['front.png', 50]];
        $sorted = ImageCandidateRanker::sort($items, static fn (array $item): string => $item[0], static fn (array $item): int => $item[1]);
        self::assertSame(array_map(static fn (int $index): array => $items[$index], [1, 4, 0, 3, 2]), $sorted);
    }

    public function test_candidate_claim_plan_parity(): void
    {
        $this->requireBindings();
        $this->assertClaimParity(['guid' => []], 0, 0, self::NOW, self::NOW - 300, false, 1);
        $columns = [
            'guid' => ['a1', 'b2', '03', 'z', 'a9', 'c0', 'd0', 'e0', '', 'F1'],
            'id' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'postdate' => [100, 300, 200, 300, 50, 10, 10, 10, 300, 300],
            'size' => [10, 10, 10, 10, 10, 1, 10, 99, 10, 10],
            'passwordstatus' => [-1, 0, -1, -1, 1, -1, -1, -1, 0, -1],
            'haspreview' => [-1, -1, -1, -1, -1, -1, -1, -1, -1, 0],
            'nzbstatus' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
            'claimed_at' => [0, 0, 500, 0, 0, 0, 0, 0, 0, 0],
            'retry_at' => [0, 0, 0, 0, 0, 0, 2000, 0, 999, 0],
        ];
        foreach ([[5, 50, 1000, 400, false, 2], [5, 50, 1000, 400, true, 2], [0, 0, 0, 0, false, 0], [0, 0, 3000, 600, false, 100], [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, false, PHP_INT_MIN]] as $options) {
            $this->assertClaimParity($columns, ...$options);
        }
        $out = AdditionalCandidateQuery::planClaimBatchPhp($columns, 5, 50, 1000, 400, false, 2);
        self::assertSame([4, 1, 1, 0, 1, 1, 1, 1], \array_slice($out, 1, 8));
        self::assertSame(2, $out[43]);
        self::assertSame(10, $out[44], 'none of the short GUIDs is 40 hex digits');
        $rows = \array_slice($out, AdditionalCandidateQuery::W51_CLAIM_HEADER);
        // Claim order: postdate descending, then id.
        self::assertSame([1, 2, 3, 4], array_map(static fn (int $index): int => $rows[$index] >> 10, [1, 3, 8, 0]));
        self::assertSame([1, 1, 0, 0], array_map(static fn (int $index): int => $rows[$index] >> 8 & 1, [1, 3, 8, 0]));
        self::assertSame(7 | 0 << 3, $rows[2]);
        self::assertSame(1, $out[9], 'actively claimed rows count toward bucket totals');
        // Missing values make Rust decline; PHP treats them as 0.
        $ragged = ['guid' => ['a', 'b'], 'id' => [1]] + $columns;
        self::assertNull(AdditionalCandidateQuery::planClaimBatchNative($ragged, 0, 0, 0, 0, false, 1));
        $this->assertClaimParity($ragged, 0, 0, 0, 0, false, 1);
        $this->assertClaimParity(['guid' => ["a\0b", "\0", 'c']] + array_map(static fn (array $values): array => \array_slice($values, 0, 3), $columns), 0, 0, 1000, 400, false, 5);
    }

    public function test_claim_index_match_parity(): void
    {
        $this->requireBindings();
        $this->assertIndexParity([], []);
        $this->assertIndexParity([''], [[]]);
        $names = ['ix_claim', '', 'partial', 'PRIMARY', 'upper', 'shuffled', 'longer', "nul\0name"];
        $lists = [self::REQUIRED, ['ID'], ['PasswordStatus', 'HASPREVIEW', 'leftguid'], ['id'], array_map(strtoupper(...), self::REQUIRED),
            array_reverse(self::REQUIRED), [...self::REQUIRED, 'extra'], ["pass\0wordstatus", 'haspreview']];
        $this->assertIndexParity($names, $lists);
        $this->assertIndexParity(['a' => 'x', 'b' => 'y'], ['k' => self::REQUIRED, 'j' => ['id']]);
        $out = AdditionalProcessingDiagnostics::matchClaimIndexBatchPhp(['ix_claim', '', 'partial'], [self::REQUIRED, ['ID'], ['PasswordStatus', 'HASPREVIEW', 'leftguid']]);
        self::assertSame([3, 1, 0, 7, 0, 1, 11], \array_slice($out, 0, 7));
        self::assertSame(1 | 7 << 1 | 7 << 4 | 1 << 7 | 7 << 8, $out[AdditionalProcessingDiagnostics::W51_INDEX_HEADER]);
        self::assertSame(1 << 4 | 1 << 8, $out[AdditionalProcessingDiagnostics::W51_INDEX_HEADER + 1]);
        // Exact matches agree with the existing single-index check.
        foreach ($lists as $list) {
            $row = AdditionalProcessingDiagnostics::matchClaimIndexBatchPhp(['n'], [$list])[AdditionalProcessingDiagnostics::W51_INDEX_HEADER];
            self::assertSame(AdditionalProcessingDiagnostics::claimIndexMatchesPhp(array_map(strtolower(...), $list))['matches'], ($row & 1) === 1);
        }
        self::assertNull(AdditionalProcessingDiagnostics::matchClaimIndexBatchNative(['a', 'b'], [[]]));
        $this->assertIndexParity(['a', 'b'], [[]]);
    }

    public function test_release_run_summary_parity(): void
    {
        $this->requireBindings();
        $this->assertRunParity([], [], [], [], '', 0);
        $sha = '0123456789abcdef0123456789abcdef01234567';
        $ids = [5, 0, 5, 9, -3, 9];
        $guids = [$sha, 'zz', strtoupper($sha), '', $sha.'0', "\0".substr($sha, 1)];
        $starts = [0, 100, 0, PHP_INT_MIN, 50, 10];
        $ends = [4_000, 50, 9_000, PHP_INT_MAX, PHP_INT_MAX, 10];
        foreach ([['token', 5_000], ['', 5_000], ['t', 0], ['t', -1], ['t', PHP_INT_MAX]] as [$token, $slow]) {
            $this->assertRunParity($ids, $guids, $starts, $ends, $token, $slow);
        }
        $out = AdditionalProcessingOrchestrator::summarizeReleaseRunBatchPhp(\array_slice($ids, 0, 4), \array_slice($guids, 0, 4), \array_slice($starts, 0, 4), \array_slice($ends, 0, 4), 'token', 5_000);
        self::assertSame([4, AdditionalProcessingOrchestrator::BATCH_PROCESS, 3, 1, 3, 2, 2, 1 << 62, 1 << 62, 3], \array_slice($out, 0, 10));
        self::assertSame(1 | 1 << 2 | 4 << 9, $out[AdditionalProcessingOrchestrator::W51_RUN_HEADER]);
        self::assertSame(AdditionalProcessingOrchestrator::BATCH_EMPTY, AdditionalProcessingOrchestrator::summarizeReleaseRunBatchPhp([], [], [], [], '', 0)[1]);
        self::assertNull(AdditionalProcessingOrchestrator::summarizeReleaseRunBatchNative([1, 2], [$sha], [0, 0], [0, 0], 't', 0));
        $this->assertRunParity([1, 2], [$sha], [0], [5, 6, 7], 't', 1);
    }

    /** @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}> */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            foreach ([[64, false, 1], [256, true, 2], [128, true, 3], [200, false, 4]] as [$count, $uncommon, $seed]) {
                yield $short.' '.$count.($uncommon ? ' uncommon' : ' common').' #'.$seed => [$class, $count, $uncommon, $seed];
            }
        }
    }

    /** @param class-string $class */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $call = $this->benchmarkCall($class, $count, $uncommon, $seed);
        $php = $class::withW51RustFfi(false, $call);
        $rust = $class::withW51RustFfi(true, $call);
        self::assertSame($php, $rust, $class);
        // Realistic fixtures stay on the Rust path rather than falling back to PHP.
        $native = self::WAVE[$class].'Native';
        self::assertSame($php, $class::$native(...$this->benchmarkArgs($class, $count, $uncommon, $seed)), $class);
        self::assertGreaterThan($count, \count($php));
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(516));
        $alphabet = 'abcdefABCDEF0123456789 ._-/\\"screnovbakthumpdisxyz';
        $text = static function (int $max) use ($random, $alphabet): string {
            $length = $random->getInt(0, $max);
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $random->getInt(0, 30) === 0 ? \chr($random->getInt(0, 127)) : $alphabet[$random->getInt(0, \strlen($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 6)) {
            0 => PHP_INT_MIN, 1 => PHP_INT_MAX, 2 => 0, 3 => -1, 4 => 1,
            default => $random->getInt(-2_000_000_000, 2_000_000_000),
        };
        for ($round = 0; $round < 60; $round++) {
            $count = $random->getInt(0, 24);
            $samples = $names = $sizes = $guids = $lists = [];
            for ($i = 0; $i < $count; $i++) {
                $length = $random->getInt(0, 7) === 0 ? $random->getInt(0, 5) : 3 * $random->getInt(1, 40);
                $samples[] = $length === 0 ? '' : $random->getBytes($length);
                $names[] = $text(40);
                $sizes[] = $int();
                $guids[] = $random->getInt(0, 3) === 0 ? $text(42) : bin2hex($random->getBytes(20));
                $list = [];
                for ($c = $random->getInt(0, 9); $c > 0; $c--) {
                    $list[] = $random->getInt(0, 2) === 0 ? $text(12) : self::REQUIRED[$random->getInt(0, 6)];
                }
                $lists[] = $list;
            }
            $this->assertFrameParity($samples, (float) $random->getInt(0, 30), (float) $random->getInt(0, 60), (float) $random->getInt(100, 255));
            $this->assertRankParity($names, $sizes);
            $columns = ['guid' => $guids];
            foreach (['id', 'postdate', 'size', 'claimed_at', 'retry_at'] as $name) {
                $columns[$name] = array_map(static fn (): int => $int(), array_fill(0, $count, 0));
            }
            foreach (['passwordstatus', 'haspreview', 'nzbstatus'] as $name) {
                $columns[$name] = array_map(static fn (): int => $random->getInt(-2, 2), array_fill(0, $count, 0));
            }
            $this->assertClaimParity($columns, $int(), $int(), $int(), $int(), $random->getInt(0, 1) === 1, $random->getInt(-2, 30));
            $this->assertIndexParity($names, $lists);
            $this->assertRunParity($columns['id'], $guids, $columns['postdate'], $columns['size'], $random->getInt(0, 1) === 1 ? 'tok' : '', $int());
        }
    }

    /**
     * Protocol (set NNTMUX_W516_BENCH=1): seed 516, 8 fixture variants, 50 warmups and
     * NNTMUX_W516_BENCH_PAIRS (default 1,000, at least 100) randomized-order pairs per
     * class/workload. Common batches hold 64 typical records (100 claim candidates, see
     * benchmarkCount()); uncommon ones 256 long,
     * mixed-case, truncated, invalid or extreme records. Fixtures are built outside timing and
     * parity is asserted on every pair. Timings cover public dispatch, marshaling, FFI and
     * unpacking. Nearest-rank p99. The summary and raw pairs are written to the system temp
     * directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W516_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W516_BENCH=1 to run the paired Wave 51.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(100, (int) (getenv('NNTMUX_W516_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(516));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, self::benchmarkCount($class, $uncommon), $uncommon, 516 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $call = $variants[$variant];
                        // The routing scope is set outside the timed region; the public batch call (its own
                        // dispatch, marshaling, FFI and unpacking) is timed inside it.
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW51RustFfi($native, static function () use ($call): array {
                            $start = hrtime(true);
                            $result = $call();

                            return [$result, hrtime(true) - $start];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0] ? 'rust-first' : 'php-first', $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil($pairs * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => intdiv(array_sum($php), $pairs), 'rust_mean_ns' => intdiv(array_sum($rust), $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 516, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w516-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW51.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertSame($eligible, $class::usesW51RustFfi(), $class.' must default to Rust exactly when it clears >=1.5x mean/p99 on both workloads');
        }
    }

    /**
     * Records per batch: 64 common and 256 uncommon, except that a common claim plan covers the
     * candidate prefix claimBatch() reads for the default limit (25 releases x 4 candidates).
     *
     * @param  class-string  $class
     */
    private static function benchmarkCount(string $class, bool $uncommon): int
    {
        if ($uncommon) {
            return 256;
        }

        return $class === AdditionalCandidateQuery::class
            ? AdditionalCandidateQuery::CLAIM_CANDIDATE_FACTOR * AdditionalProcessingOrchestrator::DEFAULT_QUERY_LIMIT
            : 64;
    }

    /**
     * Arguments of the class's batch method for one fixture.
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function benchmarkArgs(string $class, int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return match ($class) {
            FrameQualityScorer::class => [$this->frameBatch($random, $count, $uncommon), 2.0, 20.0, 235.0],
            ImageCandidateRanker::class => $this->rankBatch($random, $count, $uncommon),
            AdditionalCandidateQuery::class => [$this->claimBatch($random, $count, $uncommon), 1_048_576, 107_374_182_400, self::NOW, self::NOW - 300, ! $uncommon, $uncommon ? 100 : 25],
            AdditionalProcessingDiagnostics::class => $this->indexBatch($random, $count, $uncommon),
            default => [...$this->runBatch($random, $count, $uncommon), $uncommon && $random->getInt(0, 1) === 0 ? '' : bin2hex($random->getBytes(16)), 30_000_000_000],
        };
    }

    /**
     * @param  class-string  $class
     * @return Closure(): list<int>
     */
    private function benchmarkCall(string $class, int $count, bool $uncommon, int $seed): Closure
    {
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];

        return static fn (): array => $class::$method(...$args);
    }

    /**
     * Downscaled preview frames: common ones are 16x9 RGB thumbnails; uncommon ones vary in
     * size and include flat, black, white, truncated and empty samples.
     *
     * @return list<string>
     */
    private function frameBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $samples = [];
        for ($i = 0; $i < $count; $i++) {
            $pixels = $uncommon ? $random->getInt(1, 240) : 144;
            $kind = $random->getInt(0, $uncommon ? 9 : 7);
            $samples[] = match ($kind) {
                0 => str_repeat(\chr($random->getInt(0, 255)).\chr($random->getInt(0, 255)).\chr($random->getInt(0, 255)), $pixels),
                8 => str_repeat($random->getInt(0, 1) === 0 ? "\0\0\0" : "\xFF\xFF\xFF", $pixels),
                9 => $random->getInt(0, 3) === 0 ? '' : $random->getBytes($random->getInt(1, 50) * 3 + $random->getInt(1, 2)),
                default => (static function () use ($random, $pixels, $kind): string {
                    $base = $random->getInt(0, 200);
                    $spread = $kind * 7;
                    $out = '';
                    for ($p = 0; $p < $pixels; $p++) {
                        $v = min(255, $base + ($p * 13 + $random->getInt(0, $spread)) % ($spread + 1));
                        $out .= \chr($v).\chr(min(255, $v + 3)).\chr(max(0, $v - 5));
                    }

                    return $out;
                })(),
            };
        }

        return $samples;
    }

    /** @return array{0: list<string>, 1: list<int>} */
    private function rankBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $titles = ['Movie.Title.2024.1080p.BluRay.x264-GRP', 'Some_Show_S01E02_720p', 'Album - Artist (2019) [FLAC]', 'Game.of.Things.2160p', 'Doc.Series'];
        $parts = ['screens', 'screenshot', 'caps', 'contact sheet', 'contact-sheets', 'thumb', 'preview', 'sample', 'cover', 'front', 'poster', 'folder',
            'box', 'keyart', 'back', 'rear', 'cd1', 'dvd2', 'disc', 'label', 'inlay', 'proof', 'logo', 'banner', 'sig', 'spine', 'image', 'pic01', 'art'];
        $names = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $title = $titles[$random->getInt(0, \count($titles) - 1)];
            $part = $parts[$random->getInt(0, \count($parts) - 1)];
            $file = $title.'.'.($random->getInt(0, 1) === 0 ? $part : strtoupper($part)).'.'.['jpg', 'png', 'JPG', 'webp'][$random->getInt(0, 3)];
            $names[] = match ($uncommon ? $random->getInt(0, 5) : $random->getInt(0, 2)) {
                0 => $file,
                1 => '['.$random->getInt(1, 40).'/40] - "'.$file.'" yEnc ('.$random->getInt(1, 9).'/9)',
                2 => 'Release/'.$title.'/'.$file,
                3 => str_repeat($title.'.', $random->getInt(3, 8)).$file,
                4 => '  C:\\Downloads\\'.$title.'\\'.$file."\t ",
                default => $random->getInt(0, 3) === 0 ? '' : strtolower($title).'_'.$part.$random->getInt(0, 99).'x.jpg',
            };
            $sizes[] = $uncommon && $random->getInt(0, 9) === 0 ? [PHP_INT_MAX, -1, 0][$random->getInt(0, 2)] : $random->getInt(1_000, 9_000_000);
        }

        return [$names, $sizes];
    }

    /** @return array<string, list<int>|list<string>> */
    private function claimBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $columns = array_fill_keys(['guid', 'id', 'postdate', 'size', 'passwordstatus', 'haspreview', 'nzbstatus', 'claimed_at', 'retry_at'], []);
        $base = $random->getInt(1_000_000, 90_000_000);
        for ($i = 0; $i < $count; $i++) {
            $guid = bin2hex($random->getBytes(20));
            if ($uncommon && $random->getInt(0, 7) === 0) {
                $guid = ['', strtoupper($guid), 'zz'.$guid, "\0".$guid][$random->getInt(0, 3)];
            }
            $columns['guid'][] = $guid;
            $columns['id'][] = $uncommon && $random->getInt(0, 9) === 0 ? $base : $base + $random->getInt(0, 500_000);
            $columns['postdate'][] = self::NOW - ($uncommon ? $random->getInt(0, 3) * 86_400 : $random->getInt(0, 30 * 86_400));
            $columns['size'][] = $uncommon && $random->getInt(0, 5) === 0 ? [0, 1, PHP_INT_MAX, PHP_INT_MIN, 1_048_576, 107_374_182_400][$random->getInt(0, 5)] : $random->getInt(500_000, 60_000_000_000);
            $columns['passwordstatus'][] = [-1, -1, -1, 0, 0, 1, -2][$random->getInt(0, $uncommon ? 6 : 4)];
            $columns['haspreview'][] = $random->getInt(0, $uncommon ? 3 : 15) === 0 ? $random->getInt(0, 1) : -1;
            $columns['nzbstatus'][] = $random->getInt(0, $uncommon ? 3 : 15) === 0 ? 0 : 1;
            $columns['claimed_at'][] = $random->getInt(0, $uncommon ? 1 : 5) === 0 ? self::NOW - $random->getInt(0, 900) : 0;
            $columns['retry_at'][] = $random->getInt(0, $uncommon ? 2 : 10) === 0 ? self::NOW + $random->getInt(-86_400, 86_400) : 0;
        }

        return $columns;
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function indexBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $others = ['guid', 'name', 'searchname', 'categories_id', 'groups_id', 'adddate', 'size', 'predb_id', 'fromname', 'nfostatus', 'imdbid', 'videos_id'];
        $names = $lists = [];
        for ($i = 0; $i < $count; $i++) {
            $kind = $random->getInt(0, $uncommon ? 6 : 4);
            $list = match ($kind) {
                0 => self::REQUIRED,
                1 => \array_slice(self::REQUIRED, 0, $random->getInt(1, 6)),
                2 => [$others[$random->getInt(0, 11)], $others[$random->getInt(0, 11)]],
                3 => ['id'],
                4 => [...\array_slice(self::REQUIRED, 0, $random->getInt(0, 7)), $others[$random->getInt(0, 11)]],
                5 => array_map(static fn (string $column): string => $random->getInt(0, 1) === 0 ? strtoupper($column) : ucfirst($column), $random->shuffleArray(self::REQUIRED)),
                default => array_map(static fn (): string => $others[$random->getInt(0, 11)], range(0, $random->getInt(0, 20))),
            };
            $lists[] = $list;
            $names[] = $uncommon && $random->getInt(0, 9) === 0 ? '' : ['ix_releases_', 'PRIMARY_', 'releases_', 'IX_'][$random->getInt(0, 3)].implode('_', \array_slice($list, 0, 3));
        }

        return [$names, $lists];
    }

    /** @return array{0: list<int>, 1: list<string>, 2: list<int>, 3: list<int>} */
    private function runBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $ids = $guids = $starts = $ends = [];
        $clock = $random->getInt(1_000_000_000_000, 9_000_000_000_000);
        $base = $random->getInt(1_000_000, 90_000_000);
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $uncommon && $random->getInt(0, 4) === 0 ? [$base, 0, -1, $base + 1][$random->getInt(0, 3)] : $base + $i * $random->getInt(1, 9);
            $guid = bin2hex($random->getBytes(20));
            $guids[] = $uncommon && $random->getInt(0, 4) === 0 ? ['', strtoupper($guid), substr($guid, 0, 39), $guid.'x', 'g'.substr($guid, 1)][$random->getInt(0, 4)] : $guid;
            $starts[] = $clock;
            $clock += $random->getInt(1_000_000, $uncommon ? 90_000_000_000 : 8_000_000_000);
            $ends[] = $uncommon && $random->getInt(0, 9) === 0 ? [0, PHP_INT_MAX, PHP_INT_MIN, $clock - 2_000_000_000_000][$random->getInt(0, 3)] : $clock;
        }

        return [$ids, $guids, $starts, $ends];
    }

    /** @param array<array-key, string> $samples */
    private function assertFrameParity(array $samples, float $minStddev, float $minLuma, float $maxLuma): void
    {
        $php = FrameQualityScorer::withW51RustFfi(false, static fn (): array => FrameQualityScorer::scoreFrameBatch($samples, $minStddev, $minLuma, $maxLuma));
        $rust = FrameQualityScorer::withW51RustFfi(true, static fn (): array => FrameQualityScorer::scoreFrameBatch($samples, $minStddev, $minLuma, $maxLuma));
        self::assertSame($php, FrameQualityScorer::scoreFrameBatchPhp($samples, $minStddev, $minLuma, $maxLuma));
        self::assertSame($php, FrameQualityScorer::scoreFrameBatchNative($samples, $minStddev, $minLuma, $maxLuma));
        self::assertSame($php, $rust);
        self::assertCount(FrameQualityScorer::W51_FRAME_HEADER + \count($samples), $php);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     */
    private function assertRankParity(array $names, array $sizes): void
    {
        $php = ImageCandidateRanker::withW51RustFfi(false, static fn (): array => ImageCandidateRanker::rankCandidateBatch($names, $sizes));
        $rust = ImageCandidateRanker::withW51RustFfi(true, static fn (): array => ImageCandidateRanker::rankCandidateBatch($names, $sizes));
        self::assertSame($php, ImageCandidateRanker::rankCandidateBatchPhp($names, $sizes));
        $native = ImageCandidateRanker::rankCandidateBatchNative($names, $sizes);
        if ($native !== null) {
            self::assertSame($php, $native);
        }
        self::assertSame($php, $rust);
        self::assertCount(ImageCandidateRanker::W51_RANK_HEADER + 2 * \count($names), $php);
    }

    /** @param array<string, list<int>|list<string>> $columns */
    private function assertClaimParity(array $columns, int $min, int $max, int $now, int $stale, bool $checks, int $limit): void
    {
        $php = AdditionalCandidateQuery::withW51RustFfi(false, static fn (): array => AdditionalCandidateQuery::planClaimBatch($columns, $min, $max, $now, $stale, $checks, $limit));
        $rust = AdditionalCandidateQuery::withW51RustFfi(true, static fn (): array => AdditionalCandidateQuery::planClaimBatch($columns, $min, $max, $now, $stale, $checks, $limit));
        self::assertSame($php, AdditionalCandidateQuery::planClaimBatchPhp($columns, $min, $max, $now, $stale, $checks, $limit));
        $native = AdditionalCandidateQuery::planClaimBatchNative($columns, $min, $max, $now, $stale, $checks, $limit);
        if ($native !== null) {
            self::assertSame($php, $native);
        }
        self::assertSame($php, $rust);
        self::assertCount(AdditionalCandidateQuery::W51_CLAIM_HEADER + \count($columns['guid'] ?? []), $php);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, list<string>>  $lists
     */
    private function assertIndexParity(array $names, array $lists): void
    {
        $php = AdditionalProcessingDiagnostics::withW51RustFfi(false, static fn (): array => AdditionalProcessingDiagnostics::matchClaimIndexBatch($names, $lists));
        $rust = AdditionalProcessingDiagnostics::withW51RustFfi(true, static fn (): array => AdditionalProcessingDiagnostics::matchClaimIndexBatch($names, $lists));
        self::assertSame($php, AdditionalProcessingDiagnostics::matchClaimIndexBatchPhp($names, $lists));
        $native = AdditionalProcessingDiagnostics::matchClaimIndexBatchNative($names, $lists);
        if ($native !== null) {
            self::assertSame($php, $native);
        }
        self::assertSame($php, $rust);
        self::assertCount(AdditionalProcessingDiagnostics::W51_INDEX_HEADER + \count($names), $php);
    }

    /**
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, int>  $starts
     * @param  array<array-key, int>  $ends
     */
    private function assertRunParity(array $ids, array $guids, array $starts, array $ends, string $token, int $slow): void
    {
        $php = AdditionalProcessingOrchestrator::withW51RustFfi(false, static fn (): array => AdditionalProcessingOrchestrator::summarizeReleaseRunBatch($ids, $guids, $starts, $ends, $token, $slow));
        $rust = AdditionalProcessingOrchestrator::withW51RustFfi(true, static fn (): array => AdditionalProcessingOrchestrator::summarizeReleaseRunBatch($ids, $guids, $starts, $ends, $token, $slow));
        self::assertSame($php, AdditionalProcessingOrchestrator::summarizeReleaseRunBatchPhp($ids, $guids, $starts, $ends, $token, $slow));
        $native = AdditionalProcessingOrchestrator::summarizeReleaseRunBatchNative($ids, $guids, $starts, $ends, $token, $slow);
        if ($native !== null) {
            self::assertSame($php, $native);
        }
        self::assertSame($php, $rust);
        self::assertCount(AdditionalProcessingOrchestrator::W51_RUN_HEADER + \count($ids), $php);
    }

    private function requireBindings(): void
    {
        if (FrameQualityScorer::scoreFrameBatchNative([], 0.0, 0.0, 0.0) === null
            || ImageCandidateRanker::rankCandidateBatchNative([], []) === null
            || AdditionalCandidateQuery::planClaimBatchNative(['guid' => []], 0, 0, 0, 0, false, 1) === null
            || AdditionalProcessingDiagnostics::matchClaimIndexBatchNative([], []) === null
            || AdditionalProcessingOrchestrator::summarizeReleaseRunBatchNative([], [], [], [], '', 0) === null
        ) {
            self::markTestSkipped('The Wave 51.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
