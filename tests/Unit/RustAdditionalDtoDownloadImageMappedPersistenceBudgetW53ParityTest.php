<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\DTO\ImageCandidate;
use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\PersistenceMetrics;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustAdditionalDtoDownloadImageMappedPersistenceBudgetW53ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        DownloadMetrics::class => 'transferEfficiencyBatch',
        ImageCandidate::class => 'messageIdPlanBatch',
        MappedNzbFile::class => 'summarizeMappingBatch',
        PersistenceMetrics::class => 'summarizeLoadBatch',
        MediaByteBudget::class => 'spendPlanBatch',
    ];

    /** Header words before the per-record rows, and rows per record. */
    private const LAYOUT = [
        DownloadMetrics::class => [DownloadMetrics::W53_TRANSFER_HEADER, 1],
        ImageCandidate::class => [ImageCandidate::W53_MESSAGE_HEADER, 1],
        MappedNzbFile::class => [MappedNzbFile::W53_MAPPING_HEADER, 1],
        PersistenceMetrics::class => [PersistenceMetrics::W53_LOAD_HEADER, 2],
        MediaByteBudget::class => [MediaByteBudget::W53_SPEND_HEADER, 1],
    ];

    /**
     * Apple M5/macOS arm64 (shared host, load ~3-6), PHP 8.5.12 CLI without opcache/JIT, release
     * Rust; base 3805d156e plus this change, seed 536, 50 warmups and 1,000 pairs/workload
     * (w536-benchmark-43882ab31fe3f24f.json in system tmp). Common mean/p99 then uncommon mean/p99
     * speedups (PHP time / Rust time); a default is Rust only when all four reach 1.5x. Across five
     * further 1,000-pair runs of the final kernels ImageCandidate's uncommon p99 stayed at 1.38-1.48x
     * (its mean at 1.60-1.64x), and MediaByteBudget's common p99 ranged 1.475-3.26x (host-contention
     * tails on ~1.5us native calls) while its means stayed above 2.1x.
     */
    private const QUALIFICATION = [
        DownloadMetrics::class => [4.962, 5.289, 6.057, 4.686],
        ImageCandidate::class => [1.981, 1.965, 1.631, 1.479],
        MappedNzbFile::class => [2.642, 2.600, 2.708, 2.223],
        PersistenceMetrics::class => [3.877, 4.434, 4.965, 4.004],
        MediaByteBudget::class => [2.283, 3.370, 3.277, 3.366],
    ];

    private const VALUE_CAP = 1_099_511_627_775;

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW53RustFfi', 'withW53RustFfi', 'w53RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w53RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW53RustFfi', 'withW53RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W53_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w536_', (string) $reflection->getConstant('W53_FFI_CDEF'), $class);
            // The Wave 53 state lives in function statics, not in new static properties.
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                self::assertStringNotContainsStringIgnoringCase('w53', $property->getName(), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W53_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW53RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW53RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW53RustFfi(! $enabled, static fn (): bool => $class::usesW53RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W53_RUST_FFI_DEFAULT'), $other::usesW53RustFfi());
                        }
                    }

                    return $class::usesW53RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW53RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW53RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW53RustFfi(), $class);
        }
        // The Wave 53 switches leave the earlier toggles alone.
        DownloadMetrics::withW53RustFfi(true, static function (): void {
            self::assertFalse(DownloadMetrics::usesRustFfi());
            self::assertFalse(ImageCandidate::usesRustFfi());
            self::assertFalse(MappedNzbFile::usesRustFfi());
            self::assertFalse(PersistenceMetrics::usesRustFfi());
            self::assertFalse(MediaByteBudget::usesW45RustFfi());
        });
    }

    public function test_transfer_efficiency_parity(): void
    {
        $this->requireBindings();
        $this->assertTransferParity([], [], [], [], [], []);
        $this->assertTransferParity([0], [0], [0], [0], [0], [0]);
        $out = $this->assertTransferParity([10, 0, 4], [4, 0, 9], [6, 0, 9], [1000, 0, 100], [1000, 0, 0], [250, 0, 400]);
        self::assertSame([3, 14, 13, 15, 1100, 1000, 650, 6, 533_333, 166_666, 1], \array_slice($out, 0, 11));
        self::assertSame(600_000 | 500_000 << 20 | 250_000 << 40, $out[DownloadMetrics::W53_TRANSFER_HEADER]);
        self::assertSame(1_000_000 | 1_000_000 << 40 | 6 << 60, $out[DownloadMetrics::W53_TRANSFER_HEADER + 2]);
        // Extreme counters clamp to 2^40-1; the PHP ratios then match the original summary.
        $extreme = [PHP_INT_MAX, PHP_INT_MIN, -1, self::VALUE_CAP + 1];
        $this->assertTransferParity($extreme, array_reverse($extreme), $extreme, $extreme, array_reverse($extreme), $extreme);
        // Short and keyed columns pad with 0.
        $this->assertTransferParity(['a' => 5, 'b' => 7], [3], ['x' => 2, 'y' => 9], [], [100], [50, 60, 70]);
        $summary = DownloadMetrics::summarizeTransferEfficiencyPhp(9, 3, 4, 800, 200, 100);
        $row = $this->assertTransferParity([9], [3], [4], [800], [200], [100])[DownloadMetrics::W53_TRANSFER_HEADER];
        self::assertSame((int) ($summary['cacheHitRatio'] * 1_000_000), $row & 0xFFFFF);
        self::assertSame((int) round($summary['byteReuseRatio'] * 1_000_000), ($row >> 20) & 0xFFFFF);
        self::assertSame((int) round($summary['mediaByteRatio'] * 1_000_000), ($row >> 40) & 0xFFFFF);
    }

    public function test_message_id_plan_parity(): void
    {
        $this->requireBindings();
        $this->assertMessageParity([]);
        $this->assertMessageParity([[]]);
        $this->assertMessageParity([[''], [' '], []]);
        $out = $this->assertMessageParity([[' <a@x> ', '<b@x>', '<a@x>'], [], ['<b@x>', "\t", '<c@x>']]);
        self::assertSame([3, 6, 4, 3, 1, 1, 2], \array_slice($out, 0, 7));
        self::assertSame([2 | 1 << 14, 1 << 42, 2 | 1 << 14 | 1 << 28], \array_slice($out, ImageCandidate::W53_MESSAGE_HEADER));
        // IDs holding NUL or 0x01 bytes, numeric-looking IDs and keyed lists.
        $this->assertMessageParity([["<a\0b>", "<a\x01b>", '<a'], ['1', '01', ' 1 '], ['k' => "\0<a\0b>\0", 'l' => '1']]);
        $this->assertMessageParity(['x' => ['<z@x>'], 'y' => ['<z@x>', '<z@x>'], 'z' => ['<y@x>', '<z@x>']]);
        // An ID holding the "\0\x01" separator pair takes the NUL/0x01 twin encoding.
        $this->assertMessageParity([["<a\0\x01b>"]]);
        $this->assertMessageParity([["\0\x01"], [], ["<a\0\x01b>", '<a>', "<a\0\x01b>"]]);
        // String keys repeated across candidates survive the flattening.
        $this->assertMessageParity([['k' => '<a>', 'l' => '<b>'], ['k' => '<b>'], ['l' => ' <a>', 0 => '<c>']]);
        // Kept IDs match normalizeCandidateDescriptorPhp().
        $lists = [[' <a> ', '', '<b>', '<a>'], ['<c>', '<b>', '<c>']];
        $out = $this->assertMessageParity($lists);
        foreach ($lists as $index => $ids) {
            $descriptor = ImageCandidate::normalizeCandidateDescriptorPhp('t', $ids, 0);
            self::assertSame(\count($descriptor['messageIds']), $out[ImageCandidate::W53_MESSAGE_HEADER + $index] & 0x3FFF);
        }
    }

    public function test_mapping_summary_parity(): void
    {
        $this->requireBindings();
        $this->assertMappingParity([], [], [], []);
        $this->assertMappingParity([0], [''], [0], ['']);
        $out = $this->assertMappingParity([3, 3, -1, 7], [' a.mkv', 'a.mkv ', '', 'b.nfo'], [100, -5, 50, PHP_INT_MAX], ['verified', 'unique_size', 'bogus', 'VERIFIED']);
        self::assertSame([4, 2, 1, 1, 100 + self::VALUE_CAP, 3, 1, 3], \array_slice($out, 0, 8));
        self::assertSame([2 | 5 << 2, 1 | 5 << 2, 2 << 22, 2 | 5 << 2 | 3 << 22], \array_slice($out, MappedNzbFile::W53_MAPPING_HEADER));
        // Names and confidences holding NULs, short and keyed columns.
        $this->assertMappingParity(['p' => 1], ["a\0b", "a\0b ", "\0"], [], ["\0verified", "Verified\0", " UNIQUE-SIZE\n"]);
        $this->assertMappingParity([PHP_INT_MIN, PHP_INT_MAX], ['1', '01', '1'], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX], ['verified', 'unique-size']);
        foreach (['verified', ' Unique_Size ', 'unique size', 'verified.', "\x0Bverified\0"] as $value) {
            $rank = $this->assertMappingParity([0], ['f'], [1], [$value])[MappedNzbFile::W53_MAPPING_HEADER] & 3;
            self::assertSame(MappedNzbFile::summarizeMappingPhp(0, 'f', 1, $value)['confidenceRank'], $rank, $value);
        }
    }

    public function test_load_summary_parity(): void
    {
        $this->requireBindings();
        $this->assertLoadParity([], [], [], [], 0);
        $out = $this->assertLoadParity([4, 0, 3], [10.0, 5.0, NAN], [10, 5, 2], [7, 9, 2], 2000);
        self::assertSame([3, 7, 15_000, 17, 18, 3, 1, 2500, 2], \array_slice($out, 0, 9));
        self::assertSame([2500, 1 | 3 << 2, 0, 2, 0, 0], \array_slice($out, PersistenceMetrics::W53_LOAD_HEADER));
        $this->assertLoadParity([1, 1, 1, 1, 1, 1], [INF, -INF, -0.0, -1.5, PHP_FLOAT_MAX, PHP_FLOAT_MIN], [PHP_INT_MAX, PHP_INT_MIN, 0, 1, 2, 3], [0, 1], 1);
        $this->assertLoadParity(['a' => 3, 'b' => PHP_INT_MAX], ['x' => 1.0], [], [5, 6, 7], -1);
        $this->assertLoadParity([7, 3], [1e15, 0.3333], [0, 0], [0, 0], PHP_INT_MAX);
        // Means agree with summarizeDatabaseAndSearchLoadPhp().
        $summary = PersistenceMetrics::summarizeDatabaseAndSearchLoadPhp(7, 123.456, 4, 1);
        $out = $this->assertLoadParity([7], [123.456], [4], [1], 0);
        self::assertSame((int) ($summary['averageStatementMs'] * 1000.0), $out[PersistenceMetrics::W53_LOAD_HEADER]);
        self::assertSame($summary['coalescedSearchSyncRequests'], $out[PersistenceMetrics::W53_LOAD_HEADER + 1] >> 2);
    }

    public function test_spend_plan_parity(): void
    {
        $this->requireBindings();
        $this->assertSpendParity(0, 0, []);
        $this->assertSpendParity(-9, PHP_INT_MAX, []);
        $out = $this->assertSpendParity(100, 0, [40, -5, 70, 10, 0]);
        self::assertSame([5, 100, 0, 100, 100, 3, 2, 2, 1_000_000], \array_slice($out, 0, 9));
        self::assertSame([1 | 40 << 3, 1, 2 | 4 | 60 << 3, 2 | 4, 1 | 4], \array_slice($out, MediaByteBudget::W53_SPEND_HEADER));
        self::assertSame(333_333, $this->assertSpendParity(3, 0, [1])[8]);
        $this->assertSpendParity(PHP_INT_MAX, PHP_INT_MIN, [PHP_INT_MAX, PHP_INT_MIN, self::VALUE_CAP, 1]);
        $this->assertSpendParity(10, 50, ['a' => 1, 'b' => 0]);
        // The plan matches stepping a MediaByteBudget through clampRequestBytes() and spend().
        $requests = [300, 0, 250, 600, -4, 100];
        $out = $this->assertSpendParity(1000, 0, $requests);
        $budget = new MediaByteBudget(1000);
        foreach ($requests as $index => $request) {
            $allowed = $budget->allowsPhp($request);
            $granted = max(0, $budget->clampRequestBytesPhp(max(0, $request)));
            $budget->spend($granted);
            $row = $out[MediaByteBudget::W53_SPEND_HEADER + $index];
            self::assertSame([$allowed, $granted, $budget->exhaustedPhp()], [($row & 1) === 1, $row >> 3, ($row & 4) === 4]);
        }
        self::assertSame($budget->spent(), $out[3]);
        self::assertSame((int) ($budget->utilizationPhp() * 1_000_000), $out[8]);
    }

    /** @return iterable<string, array{class-string, int, bool, int}> */
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
        $php = $class::withW53RustFfi(false, $call);
        $rust = $class::withW53RustFfi(true, $call);
        self::assertSame($php, $rust, $class);
        // Realistic fixtures stay on the Rust path rather than falling back to PHP.
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $native = self::WAVE[$class].'Native';
        self::assertSame($php, $class::$native(...$args), $class);
        $method = self::WAVE[$class].'Php';
        self::assertSame($class::$method(...$args), $rust, $class);
        [$header, $perRecord] = self::LAYOUT[$class];
        self::assertCount($header + $perRecord * $count, $rust);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(536));
        $alphabet = "abcdefABCDEF0123456789 ._-<>@\t\n\0\x01\x0B";
        $text = static function (int $max) use ($random, $alphabet): string {
            $length = $random->getInt(0, $max);
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $random->getInt(0, 30) === 0 ? \chr($random->getInt(0, 255)) : $alphabet[$random->getInt(0, \strlen($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 6)) {
            0 => PHP_INT_MIN, 1 => PHP_INT_MAX, 2 => 0, 3 => -1, 4 => 1,
            default => $random->getInt(-2_000_000_000, 2_000_000_000),
        };
        $float = static fn (): float => match ($random->getInt(0, 6)) {
            0 => NAN, 1 => INF, 2 => -1.0, 3 => 0.0, 4 => 1e13,
            default => $random->getFloat(0.0, 10_000.0),
        };
        for ($round = 0; $round < 80; $round++) {
            $count = $random->getInt(0, 24);
            $columns = array_fill(0, 6, []);
            $lists = $sources = $names = $sizes = $confidences = $statements = $millis = $requests = $executions = $spends = [];
            for ($i = 0; $i < $count; $i++) {
                foreach ($columns as $k => $column) {
                    $columns[$k][] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 100_000);
                }
                $ids = [];
                for ($j = $random->getInt(0, 5); $j > 0; $j--) {
                    $ids[] = $random->getInt(0, 2) === 0 ? $text(6) : '<'.$random->getInt(0, 9).'@x>';
                }
                $lists[] = $ids;
                $sources[] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 5);
                $names[] = $random->getInt(0, 2) === 0 ? $text(12) : ['a.rar', ' a.rar', 'b.par2', ''][$random->getInt(0, 3)];
                $sizes[] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 1_000_000);
                $confidences[] = $random->getInt(0, 2) === 0 ? $text(12) : ['verified', 'Unique_Size', ' unique-size ', 'VERIFIED', ''][$random->getInt(0, 4)];
                $statements[] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 50);
                $millis[] = $float();
                $requests[] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 20);
                $executions[] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 20);
                $spends[] = $random->getInt(0, 4) === 0 ? $int() : $random->getInt(-10, 5000);
            }
            $this->assertTransferParity(...$columns);
            $this->assertMessageParity($lists);
            $this->assertMappingParity($sources, $names, $sizes, $confidences);
            $this->assertLoadParity($statements, $millis, $requests, $executions, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 3000));
            $this->assertSpendParity($random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 40_000), $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 500), $spends);
        }
    }

    /**
     * Protocol (set NNTMUX_W536_BENCH=1): seed 536, 8 fixture variants, 50 warmups and
     * NNTMUX_W536_BENCH_PAIRS (default 1,000, at least 100) randomized-order pairs per
     * class/workload. Common batches hold 64 typical records; uncommon ones 256 padded, mixed-case,
     * empty, invalid or extreme records. Fixtures are built outside timing and parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W536_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W536_BENCH=1 to run the paired Wave 53.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(100, (int) (getenv('NNTMUX_W536_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(536));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 256 : 64, $uncommon, 536 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW53RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 536, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w536-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW53.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertSame($eligible, $class::usesW53RustFfi(), $class.' must default to Rust exactly when it clears >=1.5x mean/p99 on both workloads');
        }
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
            DownloadMetrics::class => $this->transferBatch($random, $count, $uncommon),
            ImageCandidate::class => [$this->messageBatch($random, $count, $uncommon)],
            MappedNzbFile::class => $this->mappingBatch($random, $count, $uncommon),
            PersistenceMetrics::class => $this->loadBatch($random, $count, $uncommon),
            default => $this->spendBatch($random, $count, $uncommon),
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
     * Per-release transfer counters; uncommon ones are idle, cache-only, inconsistent or extreme.
     *
     * @return list<list<int>>
     */
    private function transferBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $columns = array_fill(0, 6, []);
        for ($i = 0; $i < $count; $i++) {
            $logical = $random->getInt(1, 400);
            $network = $random->getInt(0, $logical);
            $downloaded = $random->getInt(0, 80_000_000);
            $row = [$logical, $network, $logical - $network, $downloaded, $random->getInt(0, 20_000_000), $random->getInt(0, $downloaded)];
            if ($uncommon) {
                $row = match ($random->getInt(0, 5)) {
                    0 => [0, 0, 0, 0, 0, 0],
                    1 => [$logical, 0, $logical, 0, $row[4], 0],
                    2 => [$logical, $network, $logical * 3, $downloaded, 0, $downloaded * 2],
                    3 => [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX, self::VALUE_CAP, -1],
                    4 => [-5, -1, $random->getInt(0, 9), -$downloaded, $row[4], $row[5]],
                    default => [$logical * 1_000_000, $network * 1_000_000, $row[2] * 999_999, $downloaded * 10_000, $row[4] * 10_000, $row[5] * 10_000],
                };
            }
            foreach ($row as $k => $value) {
                $columns[$k][] = $value;
            }
        }

        return $columns;
    }

    /**
     * Image candidates' message IDs, some reposted across candidates; uncommon ones are padded,
     * empty, repeated, NUL-holding or long.
     *
     * @return list<list<string>>
     */
    private function messageBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $lists = [];
        for ($i = 0; $i < $count; $i++) {
            $ids = [];
            $parts = $random->getInt(1, 8);
            $post = $random->getInt(0, 3) === 0 ? $random->getInt(0, $count) : $i;
            for ($part = 1; $part <= $parts; $part++) {
                $id = '<part'.$part.'of'.$parts.'.'.dechex($post * 7919).'@news.example.com>';
                if ($uncommon) {
                    $id = match ($random->getInt(0, 7)) {
                        0 => " \t".$id."\n",
                        1 => '',
                        2 => '   ',
                        3 => $ids[0] ?? $id,
                        4 => str_replace('@', "\0@", $id),
                        5 => strtoupper($id),
                        6 => $id.str_repeat('x', 200),
                        default => $id,
                    };
                }
                $ids[] = $id;
            }
            $lists[] = $uncommon && $random->getInt(0, 9) === 0 ? [] : $ids;
        }

        return $lists;
    }

    /**
     * PAR2-mapped files of a few releases; uncommon names and confidences are padded, mixed-case,
     * empty or NUL-holding, with extreme indexes and sizes.
     *
     * @return array{0: list<int>, 1: list<string>, 2: list<int>, 3: list<string>}
     */
    private function mappingBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $sources = $names = $sizes = $confidences = [];
        for ($i = 0; $i < $count; $i++) {
            $release = $random->getInt(0, 3);
            $name = 'Some.Release.'.$release.'.2024.1080p.WEB-DL.part'.str_pad((string) $random->getInt(1, intdiv($count, 2) + 1), 3, '0', STR_PAD_LEFT).'.rar';
            $confidence = ['verified', 'unique-size'][$random->getInt(0, 1)];
            $source = $i;
            $size = $random->getInt(10_000_000, 52_428_800);
            if ($uncommon) {
                $name = match ($random->getInt(0, 5)) {
                    0 => '  '.$name."\t",
                    1 => '',
                    2 => str_replace('.', "\0", $name),
                    3 => strtoupper($name),
                    default => $name,
                };
                $confidence = match ($random->getInt(0, 5)) {
                    0 => ' VERIFIED ',
                    1 => 'Unique_Size',
                    2 => 'unknown',
                    3 => '',
                    4 => "verified\0",
                    default => $confidence,
                };
                $source = $random->getInt(0, 4) === 0 ? [PHP_INT_MIN, PHP_INT_MAX, -1][$random->getInt(0, 2)] : $random->getInt(0, 9);
                $size = $random->getInt(0, 4) === 0 ? [PHP_INT_MIN, PHP_INT_MAX, -1, 0][$random->getInt(0, 3)] : $size;
            }
            $sources[] = $source;
            $names[] = $name;
            $sizes[] = $size;
            $confidences[] = $confidence;
        }

        return [$sources, $names, $sizes, $confidences];
    }

    /**
     * Per-release database and search load; uncommon rows are idle, non-finite, negative or extreme.
     *
     * @return array{0: list<int>, 1: list<float>, 2: list<int>, 3: list<int>, 4: int}
     */
    private function loadBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $statements = $millis = $requests = $executions = [];
        for ($i = 0; $i < $count; $i++) {
            $statement = $random->getInt(1, 240);
            $ms = $random->getFloat(0.05, 4.0) * $statement;
            $request = $random->getInt(0, 12);
            $execution = $random->getInt(0, $request);
            if ($uncommon) {
                [$statement, $ms, $request, $execution] = match ($random->getInt(0, 6)) {
                    0 => [0, 0.0, 0, 0],
                    1 => [$statement, NAN, $request, $execution],
                    2 => [$statement, INF, $request, $request + 3],
                    3 => [-$statement, -$ms, -1, PHP_INT_MIN],
                    4 => [PHP_INT_MAX, 1e15, PHP_INT_MAX, 0],
                    5 => [$statement * 1000, $ms * 50_000.0, $request * 100, $execution],
                    default => [$statement, $ms, $request, $execution],
                };
            }
            $statements[] = $statement;
            $millis[] = $ms;
            $requests[] = $request;
            $executions[] = $execution;
        }

        return [$statements, $millis, $requests, $executions, $uncommon ? 1500 : 2000];
    }

    /**
     * Preview-source request sizes against a release's media byte budget; uncommon sequences hold
     * negative, huge or zero requests against a tight or pre-spent budget.
     *
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function spendBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $requests = [];
        for ($i = 0; $i < $count; $i++) {
            $request = $random->getInt(16_384, 786_432);
            if ($uncommon) {
                $request = match ($random->getInt(0, 5)) {
                    0 => -$request,
                    1 => 0,
                    2 => [PHP_INT_MAX, PHP_INT_MIN, self::VALUE_CAP + 1][$random->getInt(0, 2)],
                    3 => $request * 64,
                    default => $request,
                };
            }
            $requests[] = $request;
        }
        $limit = $uncommon ? [0, 1, 4_194_304, PHP_INT_MAX][$random->getInt(0, 3)] : 8 * 1_048_576 * $random->getInt(2, 8);
        $spent = $uncommon ? [0, 2_097_152, PHP_INT_MIN, PHP_INT_MAX][$random->getInt(0, 3)] : $random->getInt(0, 1_048_576);

        return [$limit, $spent, $requests];
    }

    /**
     * @param  array<array-key, int>  $logical
     * @param  array<array-key, int>  $network
     * @param  array<array-key, int>  $hits
     * @param  array<array-key, int>  $downloaded
     * @param  array<array-key, int>  $reused
     * @param  array<array-key, int>  $media
     * @return list<int>
     */
    private function assertTransferParity(array $logical, array $network, array $hits, array $downloaded, array $reused, array $media): array
    {
        $args = [$logical, $network, $hits, $downloaded, $reused, $media];
        $php = DownloadMetrics::withW53RustFfi(false, static fn (): array => DownloadMetrics::transferEfficiencyBatch(...$args));
        $rust = DownloadMetrics::withW53RustFfi(true, static fn (): array => DownloadMetrics::transferEfficiencyBatch(...$args));
        self::assertSame($php, DownloadMetrics::transferEfficiencyBatchPhp(...$args));
        self::assertSame($php, DownloadMetrics::transferEfficiencyBatchNative(...$args));
        self::assertSame($php, $rust);
        self::assertCount(DownloadMetrics::W53_TRANSFER_HEADER + \count($logical), $php);

        return $php;
    }

    /**
     * @param  array<array-key, array<array-key, string>>  $lists
     * @return list<int>
     */
    private function assertMessageParity(array $lists): array
    {
        $php = ImageCandidate::withW53RustFfi(false, static fn (): array => ImageCandidate::messageIdPlanBatch($lists));
        $rust = ImageCandidate::withW53RustFfi(true, static fn (): array => ImageCandidate::messageIdPlanBatch($lists));
        self::assertSame($php, ImageCandidate::messageIdPlanBatchPhp($lists));
        self::assertSame($php, ImageCandidate::messageIdPlanBatchNative($lists), json_encode(array_map(static fn (array $ids): array => array_map(bin2hex(...), array_values($ids)), array_values($lists)), JSON_THROW_ON_ERROR));
        self::assertSame($php, $rust);
        self::assertCount(ImageCandidate::W53_MESSAGE_HEADER + \count($lists), $php);

        return $php;
    }

    /**
     * @param  array<array-key, int>  $sources
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, string>  $confidences
     * @return list<int>
     */
    private function assertMappingParity(array $sources, array $names, array $sizes, array $confidences): array
    {
        $args = [$sources, $names, $sizes, $confidences];
        $php = MappedNzbFile::withW53RustFfi(false, static fn (): array => MappedNzbFile::summarizeMappingBatch(...$args));
        $rust = MappedNzbFile::withW53RustFfi(true, static fn (): array => MappedNzbFile::summarizeMappingBatch(...$args));
        self::assertSame($php, MappedNzbFile::summarizeMappingBatchPhp(...$args));
        self::assertSame($php, MappedNzbFile::summarizeMappingBatchNative(...$args));
        self::assertSame($php, $rust);
        self::assertCount(MappedNzbFile::W53_MAPPING_HEADER + \count($names), $php);

        return $php;
    }

    /**
     * @param  array<array-key, int>  $statements
     * @param  array<array-key, float>  $millis
     * @param  array<array-key, int>  $requests
     * @param  array<array-key, int>  $executions
     * @return list<int>
     */
    private function assertLoadParity(array $statements, array $millis, array $requests, array $executions, int $slowMicros): array
    {
        $args = [$statements, $millis, $requests, $executions, $slowMicros];
        $php = PersistenceMetrics::withW53RustFfi(false, static fn (): array => PersistenceMetrics::summarizeLoadBatch(...$args));
        $rust = PersistenceMetrics::withW53RustFfi(true, static fn (): array => PersistenceMetrics::summarizeLoadBatch(...$args));
        self::assertSame($php, PersistenceMetrics::summarizeLoadBatchPhp(...$args));
        self::assertSame($php, PersistenceMetrics::summarizeLoadBatchNative(...$args));
        self::assertSame($php, $rust);
        self::assertCount(PersistenceMetrics::W53_LOAD_HEADER + 2 * \count($statements), $php);

        return $php;
    }

    /**
     * @param  array<array-key, int>  $requests
     * @return list<int>
     */
    private function assertSpendParity(int $limit, int $spent, array $requests): array
    {
        $php = MediaByteBudget::withW53RustFfi(false, static fn (): array => MediaByteBudget::spendPlanBatch($limit, $spent, $requests));
        $rust = MediaByteBudget::withW53RustFfi(true, static fn (): array => MediaByteBudget::spendPlanBatch($limit, $spent, $requests));
        self::assertSame($php, MediaByteBudget::spendPlanBatchPhp($limit, $spent, $requests));
        self::assertSame($php, MediaByteBudget::spendPlanBatchNative($limit, $spent, $requests));
        self::assertSame($php, $rust);
        self::assertCount(MediaByteBudget::W53_SPEND_HEADER + \count($requests), $php);

        return $php;
    }

    private function requireBindings(): void
    {
        if (DownloadMetrics::transferEfficiencyBatchNative([], [], [], [], [], []) === null
            || ImageCandidate::messageIdPlanBatchNative([]) === null
            || MappedNzbFile::summarizeMappingBatchNative([], [], [], []) === null
            || PersistenceMetrics::summarizeLoadBatchNative([], [], [], [], 0) === null
            || MediaByteBudget::spendPlanBatchNative(0, 0, []) === null
        ) {
            self::markTestSkipped('The Wave 53.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
