<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\CandidateAdmission;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CandidateSampleBatch;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustVerificationAdmissionInventoryPrepRepoSampleW49ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        CandidateAdmission::class => 'fingerprintAdmissions',
        CandidateHeaderInventory::class => 'classifyArticleReferences',
        CandidatePreparationService::class => 'planAssessmentUpdates',
        CandidateRepository::class => 'classifyPartialPromotions',
        CandidateSampleBatch::class => 'planPrefetchBatch',
    ];

    /** Class => Wave 49 export. */
    private const SYMBOLS = [
        CandidateAdmission::class => 'nntmux_w49_candidate_admission_batch',
        CandidateHeaderInventory::class => 'nntmux_w49_candidate_header_inventory_batch',
        CandidatePreparationService::class => 'nntmux_w49_candidate_preparation_batch',
        CandidateRepository::class => 'nntmux_w49_candidate_repository_partial_batch',
        CandidateSampleBatch::class => 'nntmux_w49_candidate_sample_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns): the per-metric median of
     * five runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 49, each metric
     * the median of 3 rounds of 1000 pairs after 50 warmups, randomized call order, release Rust,
     * PHP 8.5.12 CLI without opcache, Apple Silicon macOS on a shared host at load ~7-14).
     * Timings include dispatch, packing, the FFI call and unpack('P*'). p99 tracks scheduler
     * stalls of 100-450 us that hit both paths alike: the loaded runs' lows were
     * CandidatePreparationService Common/Uncommon p99 1.334x/1.215x and CandidateRepository
     * Uncommon p99 1.556x, whose means stayed at 2.0-2.8x. A class defaults to Rust only when all
     * four reach 1.5x.
     */
    private const QUALIFICATION = [
        CandidateAdmission::class => [5.066, 3.047, 4.718, 3.543],
        CandidateHeaderInventory::class => [3.946, 2.949, 4.803, 3.860],
        CandidatePreparationService::class => [2.411, 2.083, 2.252, 1.782],
        CandidateRepository::class => [2.685, 1.962, 2.564, 1.850],
        CandidateSampleBatch::class => [2.790, 1.952, 2.879, 2.254],
    ];

    private const ROUNDS = 3;

    private const PAIRS = 1000;

    private const WARMUPS = 50;

    private const DEFERRED = 'Partial publication deferred: ';

    private const QUEUED = 'Upload stopped growing before it was complete; queued for partial publication.';

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW49RustFfi', 'withW49RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->hasConstant('W49_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W49_RUST_FFI_DEFAULT'), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W49_FFI_CDEF'), $class);
        }
        $admission = new ReflectionClass(CandidateAdmission::class);
        self::assertTrue($admission->isReadOnly());
        self::assertSame([], $admission->getStaticProperties(), 'Readonly classes cannot declare static properties.');
        self::assertTrue($admission->hasMethod('w49RustFfiState'));
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW49RustFfi(), $class);
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW49RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW49RustFfi(! $enabled, static fn (): bool => $class::usesW49RustFfi());

                    return [$class::usesW49RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW49RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW49RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW49RustFfi(), $class);
        }
        // The Wave 49 toggles leave the earlier Rust switches untouched.
        self::assertFalse(CandidatePreparationService::usesRustFfi());
        self::assertFalse(CandidateRepository::usesRustFfi());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(4900));
        foreach (array_keys(self::ENTRIES) as $class) {
            $args = $this->fixture($class, $random, false);
            $php = $this->call($class, 'Php', $args);
            foreach ([true, false] as $native) {
                self::assertSame($php, $class::withW49RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
            }
            self::assertSame($php, $this->call($class, 'Native', $args), $class);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $ffi = $this->ffi(CandidateSampleBatch::class);
        $out = $ffi->new('int64_t[16]');
        $claims = pack('P', 7);
        $symbol = self::SYMBOLS[CandidateSampleBatch::class];
        self::assertSame(-1, $this->invoke($ffi, $symbol, [$claims, 8, 'a', 1, 1, 1, 0, 1, 1, null, 16]));
        self::assertSame(-6, $this->invoke($ffi, $symbol, [$claims, 8, 'a', 1, 1, 1, 0, 1, 1, $out, 5]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, [$claims, 8, "a\0b", 3, 1, 1, 0, 1, 1, $out, 16]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, [$claims, 4, 'a', 1, 1, 1, 0, 1, 1, $out, 16]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 8, 'a', 1, 1, 1, 0, 1, 1, $out, 16]));
        self::assertSame(5, $this->invoke($ffi, $symbol, [null, 0, null, 0, 0, 1, 0, 1, 1, $out, 16]));
        $prep = $this->ffi(CandidatePreparationService::class);
        $prepOut = $prep->new('int64_t[16]');
        self::assertSame(-1, $this->invoke($prep, self::SYMBOLS[CandidatePreparationService::class], ['ready', 5, 'x', 1, 1, -1, 25, $prepOut, 16]));
    }

    public function test_admission_parity(): void
    {
        $this->requireBindings();
        $hash = hash('sha256', 'manifest');
        $identity = sha1('collection');
        $crc = static fn (string $s, int $version): int => crc32($s) | ($version & 0x7FFFFFFF) << 32;
        $words = [
            0x9F | 40 << 8, $crc($identity.':'.$hash, 1),
            0x7F | 40 << 8, $crc($identity.':'.$hash, 2),
            0x0A | 3 << 8, $crc('XYZ:short', 3),
            0x01, $crc(':', -1),
            1, 1,
        ];
        $args = [[5, 5, -1, 6], [$identity, $identity, 'XYZ', ''], [1, 2, 3, -1], [$hash, $hash, 'short', '']];
        self::assertSame([...$words, $this->digest($words)], CandidateAdmission::fingerprintAdmissionsPhp(...$args));
        $this->assertParity(CandidateAdmission::class, $args);
        $this->assertParity(CandidateAdmission::class, [[], [], [], []]);
        $this->assertParity(CandidateAdmission::class, [[1, 2], ['a'], [1, 2], [$hash, $hash]]);
        $this->assertParity(CandidateAdmission::class, [[1], ["a\0b"], [1], [$hash]]);
        $this->assertParity(CandidateAdmission::class, [[PHP_INT_MAX, PHP_INT_MIN, 0], [str_repeat('f', 70000), strtoupper($hash), "é\x80"], [PHP_INT_MAX, PHP_INT_MIN, 0], [strtoupper($hash), $hash.'0', substr($hash, 1)]]);
        $this->assertParity(CandidateAdmission::class, [[1, 2, 3], ['123', '0123', '123'], [1, 1, 1], [$hash, $hash, $hash]]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(CandidateAdmission::class, $this->admissionBatch(new Randomizer(new Mt19937(4910 + $i)), $uncommon));
            }
        }
    }

    public function test_header_inventory_parity(): void
    {
        $this->requireBindings();
        $crc = static fn (string $s): int => crc32($s) << 32;
        $words = [3 << 8 | $crc('a@b'), 2 | 16 | 3 << 8 | $crc('a@b'), 1 | 4 | 6 << 8 | $crc('bad id'), 14 | 1 << 8, 15];
        $args = [['<a@b>', 'a@b', 'bad id'], [1, 2, 9], [10, 0, 5], 4];
        self::assertSame([...$words, $this->digest($words)], CandidateHeaderInventory::classifyArticleReferencesPhp(...$args));
        $this->assertParity(CandidateHeaderInventory::class, $args);
        $this->assertParity(CandidateHeaderInventory::class, [[], [], [], 0]);
        $this->assertParity(CandidateHeaderInventory::class, [[], [], [], 3]);
        $this->assertParity(CandidateHeaderInventory::class, [['a@b'], [1], [1], 0]);
        $this->assertParity(CandidateHeaderInventory::class, [['a@b', 'c@d'], [1], [1, 1], 2]);
        $this->assertParity(CandidateHeaderInventory::class, [["a\0b"], [1], [1], 1]);
        $bytes = array_map(chr(...), range(0, 255));
        $ids = array_map(static fn (string $b): string => 'x'.$b.'y', array_values(array_diff($bytes, ["\0"])));
        $this->assertParity(CandidateHeaderInventory::class, [$ids, range(1, \count($ids)), array_fill(0, \count($ids), PHP_INT_MAX), \count($ids)]);
        $this->assertParity(CandidateHeaderInventory::class, [['<>', '<<a>>', '<a', 'a>', '><', '', '<a b>', "\x7f", '0', '00'], [0, -1, PHP_INT_MIN, PHP_INT_MAX, 1, 1, 2, 3, 4, 5], [0, -5, 1, 1, 1, 1, PHP_INT_MIN, 1, 1, 1], 10]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->inventoryBatch(new Randomizer(new Mt19937(4920 + $i)), $uncommon);
                $this->assertParity(CandidateHeaderInventory::class, $args);
                $this->assertInventoryMatchesAssessment($args);
            }
        }
    }

    public function test_preparation_parity(): void
    {
        $this->requireBindings();
        $words = [1 | 1 << 3 | 1 << 8, 1 | 1 << 3 | 64 | 1 << 8 | 1 << 32, 1 << 32, 3 | 3 << 3 | 1 << 32, 1 | 1 << 3 | 1 << 8 | 1 << 32, 2, 2, 3];
        $args = [['waiting_upload', 'waiting_upload', 'ready', 'unknown', 'waiting_upload'], ['x', 'x', null, 'é!', 'y'], 1, 2];
        self::assertSame([...$words, $this->digest($words)], CandidatePreparationService::planAssessmentUpdatesPhp(...$args));
        $this->assertParity(CandidatePreparationService::class, $args);
        $this->assertParity(CandidatePreparationService::class, [[], [], 2000, 25]);
        $this->assertParity(CandidatePreparationService::class, [['waiting_upload'], [], 2000, 25]);
        $this->assertParity(CandidatePreparationService::class, [['waiting_upload'], ["a\0b"], 2000, 25]);
        $this->assertParity(CandidatePreparationService::class, [['waiting_upload', 'unknown'], ['abcdef', 'ghijkl'], -2, 25]);
        $this->assertParity(CandidatePreparationService::class, [['waiting_upload', 'waiting_upload', 'header_rejected', 'pending', ''], ["\x80\x80\x80", "\xe2\x82\xac\xe2\x82", str_repeat('€', 900), null, ''], 3, 0]);
        $this->assertParity(CandidatePreparationService::class, [array_fill(0, 60, 'waiting_upload'), array_fill(0, 60, 'same'), PHP_INT_MAX, 25]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->preparationBatch(new Randomizer(new Mt19937(4930 + $i)), $uncommon, $i % 2 === 1);
                $this->assertParity(CandidatePreparationService::class, $args);
                $words = CandidatePreparationService::planAssessmentUpdatesPhp(...$args);
                foreach ($args[0] as $row => $status) {
                    $stored = $status === 'ready' ? '' : CandidatePreparationService::truncateAssessmentReasonPhp($args[1][$row], $args[2]);
                    self::assertSame(min(\strlen($stored), 0xFFFFFF), $words[$row] >> 8 & 0xFFFFFF);
                }
            }
        }
    }

    public function test_repository_partial_parity(): void
    {
        $this->requireBindings();
        $owed = '2026-01-01 00:00:00';
        $hold = '2026-01-03 00:00:00';
        $words = [1 | 4 | 32 | 64 | 128, 2 | 4 | 32 | 128, 64 | 128, 16 | 64 | 256, 1 | 4 | 16 | 32 | 64 | 256, 8 | 32 | 64 | 128, 2, 3, 1, 3];
        $args = [
            ['2026-01-02 00:00:00', '2026-01-05 00:00:00', '2025-12-31 23:59:59', null, '2026-01-02 12:00:00', '2026-01-02 00:00:00'],
            ['waiting_upload', 'waiting_upload', 'waiting_upload', 'ready', 'ready', 'waiting_upload'],
            [null, 'other', '', self::QUEUED, self::QUEUED, self::DEFERRED.'size'],
            $owed, $hold,
        ];
        self::assertSame([...$words, $this->digest($words)], CandidateRepository::classifyPartialPromotionsPhp(...$args));
        $this->assertParity(CandidateRepository::class, $args);
        $this->assertParity(CandidateRepository::class, [[], [], [], $owed, $hold]);
        $this->assertParity(CandidateRepository::class, [['5', '10', ' 7 ', '1e1', 'x'], ['ready', 'waiting_upload', 'ready', 'ready', 'ready'], ['', '', '', '', ''], '6', '9']);
        $this->assertParity(CandidateRepository::class, [['5', '10'], ['ready', 'ready'], ['', ''], '2026', 'x']);
        $this->assertParity(CandidateRepository::class, [['5', '10', '2026-01-01'], ['ready', 'waiting_upload', 'ready'], ['', '', ''], $owed, $hold]);
        $this->assertParity(CandidateRepository::class, [['', null, $owed, $hold], ['waiting_upload', 'waiting_upload', 'ready', 'ready'], [self::DEFERRED, rtrim(self::DEFERRED), self::QUEUED.' ', null], '', '']);
        $this->assertParity(CandidateRepository::class, [['a'], ['ready'], ["x\0"], $owed, $hold]);
        $this->assertParity(CandidateRepository::class, [['a'], ['ready'], [''], "a\0", $hold]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->repositoryBatch(new Randomizer(new Mt19937(4940 + $i)), $uncommon);
                $this->assertParity(CandidateRepository::class, $args);
                $words = CandidateRepository::classifyPartialPromotionsPhp(...$args);
                foreach ($args[0] as $row => $last) {
                    $owes = CandidateRepository::isOwedPartialAttemptPhp($last, $args[3], $args[1][$row], $args[2][$row]);
                    self::assertSame($owes, ($words[$row] & 4) !== 0);
                }
            }
        }
    }

    public function test_sample_batch_parity(): void
    {
        $this->requireBindings();
        $words = [1 | 32 | 3 << 8, 1 | 2 | 16 | 3 << 8, 1 | 4 | 8 | 2 << 32, 3 << 8 | 3 << 32, 3, 1, 0, 1];
        $args = [[7, 7, 0, 9], ['<a@b>', 'a@b', '', '<c@d>'], 10, 1, 3, 8];
        self::assertSame([...$words, $this->digest($words)], CandidateSampleBatch::planPrefetchBatchPhp(...$args));
        $this->assertParity(CandidateSampleBatch::class, $args);
        $this->assertParity(CandidateSampleBatch::class, [[], [], 0, 0, 0, 0]);
        $this->assertParity(CandidateSampleBatch::class, [[1, 2], ['a'], 4, 0, 4, 4]);
        $this->assertParity(CandidateSampleBatch::class, [[1], ["a\0"], 4, 0, 4, 4]);
        $this->assertParity(CandidateSampleBatch::class, [[1, 2, 3], ['<>', '<<>>', '0'], PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN]);
        $this->assertParity(CandidateSampleBatch::class, [[PHP_INT_MIN, PHP_INT_MAX, -1], ['123', '0123', '123'], 1, 5, -3, 0]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->sampleBatch(new Randomizer(new Mt19937(4950 + $i)), $uncommon);
                $this->assertParity(CandidateSampleBatch::class, $args);
                $words = CandidateSampleBatch::planPrefetchBatchPhp(...$args);
                [, , $max, $admitted, $configured, $source] = $args;
                if (min($max, $admitted, $configured, $source) >= 0 && max($max, $admitted, $configured, $source) <= 1 << 31) {
                    self::assertSame(CandidateSampleBatch::claimBudgetPhp($max, $admitted, $configured, $source), $words[\count($args[0])]);
                }
            }
        }
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture in random order. Common batches hold 64
     * typical records, Uncommon batches 128-256 long, malformed or adversarial ones. Each of
     * ROUNDS rounds times PAIRS pairs after WARMUPS warmups; a metric is the median of its round
     * values (nearest-rank p99 per round), which damps scheduler spikes on a shared host. Every
     * pair must agree; raw pairs are written outside the checkout. A class must default to Rust
     * exactly when all four metrics reach 1.5x.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(49));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $metrics = [];
            foreach (['Common', 'Uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
                $rounds = [];
                for ($round = 0; $round < self::ROUNDS; $round++) {
                    $php = $rust = [];
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW49RustFfi($native, fn (): array => $this->timed($class, $args));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $php[] = $elapsed[0];
                            $rust[] = $elapsed[1];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                    sort($php);
                    sort($rust);
                    $p99 = (int) ceil(\count($php) * 0.99) - 1;
                    $rounds[] = [
                        'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                        'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                        'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                    ];
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => $this->median(array_column($rounds, 'mean_speedup')), 'p99_speedup' => $this->median(array_column($rounds, 'p99_speedup')),
                    'php_mean_ns' => $this->median(array_column($rounds, 'php_mean_ns')), 'rust_mean_ns' => $this->median(array_column($rounds, 'rust_mean_ns')),
                    'php_p99_ns' => $this->median(array_column($rounds, 'php_p99_ns')), 'rust_p99_ns' => $this->median(array_column($rounds, 'rust_p99_ns')),
                    'rounds' => $rounds,
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w492-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'seed' => 49, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW49.2 paired benchmark artifact: {$artifact}\n".json_encode(array_map(static fn (array $class): array => array_map(static fn (mixed $w): mixed => \is_array($w) ? array_diff_key($w, ['rounds' => true]) : $w, $class), $summary), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW49RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * One timed call of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $start = hrtime(true);
        $result = $this->call($class, '', $args);

        return [$result, hrtime(true) - $start];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W49_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $args): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix](...$args);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $php = $class::withW49RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW49RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = $class === CandidateAdmission::class
            ? (new ReflectionMethod($class, 'w49RustFfiState'))->invoke(null, 'ffi')
            : (new ReflectionMethod($class, 'w49Ffi'))->invoke(null);
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w49 binding');

        return $ffi;
    }

    /** Each Wave 49 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $one = pack('P', 1);
        $two = pack('P*', 1, 1);
        $calls = [
            CandidateAdmission::class => [$two, 16, 'a', 1, 'b', 1, 1],
            CandidateHeaderInventory::class => ['a', 1, $two, 16, 1, 1],
            CandidatePreparationService::class => ['ready', 5, '', 0, 1, 2000, 25],
            CandidateRepository::class => ['a', 1, 'ready', 5, '', 0, 1, 'a', 1, 'b', 1],
            CandidateSampleBatch::class => [$one, 8, 'a', 1, 1, 1, 0, 1, 1],
        ];
        $words = [
            CandidateAdmission::class => 5,
            CandidateHeaderInventory::class => 4,
            CandidatePreparationService::class => 5,
            CandidateRepository::class => 6,
            CandidateSampleBatch::class => 6,
        ];
        foreach ($calls as $class => $args) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[16]');
            self::assertSame($words[$class], $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 16]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W49_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            CandidateAdmission::class => $this->admissionBatch($random, $uncommon),
            CandidateHeaderInventory::class => $this->inventoryBatch($random, $uncommon),
            CandidatePreparationService::class => $this->preparationBatch($random, $uncommon),
            CandidateRepository::class => $this->repositoryBatch($random, $uncommon),
            CandidateSampleBatch::class => $this->sampleBatch($random, $uncommon),
        };
    }

    /** @param  list<string>  $values */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789.-_$+=@<> ', $length);
    }

    private function messageId(Randomizer $random, int $part, int $total): string
    {
        return '<part'.$part.'of'.$total.'.'.bin2hex($random->getBytes(6)).'@'.$this->pick($random, ['news.example.com', 'powerpost2000AA.local', 'ngPost.com', 'JBinUp.local']).'>';
    }

    /** @return list<mixed> */
    private function admissionBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $ids = $identities = $versions = $hashes = [];
        $base = $random->getInt(1, 50_000_000);
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $base + $i;
            $identities[] = bin2hex($random->getBytes(20));
            $versions[] = $random->getInt(1, 6);
            $hashes[] = bin2hex($random->getBytes(32));
            if (! $uncommon) {
                continue;
            }
            match ($random->getInt(0, 11)) {
                0 => $ids[$i] = $ids[$random->getInt(0, $i)],
                1 => $identities[$i] = $identities[$random->getInt(0, $i)],
                2 => $identities[$i] = strtoupper($identities[$i]),
                3 => $identities[$i] = '',
                4 => $identities[$i] = $this->noise($random, 400, true),
                5 => $hashes[$i] = substr($hashes[$i], 0, $random->getInt(0, 63)),
                6 => $hashes[$i] = strtoupper($hashes[$i]),
                7 => $versions[$i] = $this->pick($random, ['0', '-1']) === '0' ? 0 : PHP_INT_MIN + $random->getInt(0, 9),
                8 => $versions[$i] = PHP_INT_MAX - $random->getInt(0, 9),
                9 => $ids[$i] = -$random->getInt(0, 9),
                10 => $identities[$i] = $this->noise($random, 2000).bin2hex($random->getBytes(20)),
                default => null,
            };
        }

        return [$ids, $identities, $versions, $hashes];
    }

    /** @return list<mixed> */
    private function inventoryBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $total = $count + ($random->getInt(0, 3) === 0 ? $random->getInt(1, 5) : 0);
        $ids = $numbers = $bytes = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->messageId($random, $i + 1, $total);
            $numbers[] = $i + 1;
            $bytes[] = $random->getInt(300_000, 800_000);
        }
        if (! $uncommon) {
            if ($random->getInt(0, 3) === 0) {
                $ids[$random->getInt(0, $count - 1)] = 'not valid@x';
            }

            return [$ids, $numbers, $bytes, $total];
        }
        for ($i = 0; $i < $count; $i++) {
            match ($random->getInt(0, 13)) {
                0 => $ids[$i] = $ids[$random->getInt(0, $i)],
                1 => $ids[$i] = trim($ids[$i], '<>'),
                2 => $ids[$i] = '<'.$this->noise($random, 120, true).'>',
                3 => $ids[$i] = $this->pick($random, ['', '<>', '<<>>', '>a<', "<a\tb>", "<a\x7fb>"]),
                4 => $numbers[$i] = $this->pick($random, ['0', '1']) === '0' ? $random->getInt(PHP_INT_MIN, 0) : $total + $random->getInt(1, 9),
                5 => $numbers[$i] = $numbers[$random->getInt(0, $i)],
                6 => $bytes[$i] = $random->getInt(-9, 0),
                7 => $bytes[$i] = PHP_INT_MAX - $random->getInt(0, 9),
                8 => $ids[$i] = '<'.str_repeat('x', $random->getInt(200, 1000)).'@long.example>',
                9 => $ids[$i] = '<'.bin2hex($random->getBytes(4))."\xc3\xa9\xff@utf8.example>",
                default => null,
            };
        }
        if ($random->getInt(0, 7) === 0) {
            $total = $random->getInt(-2, $count - 1);
        }
        if ($random->getInt(0, 3) === 0) {
            shuffle($numbers);
        }

        return [$ids, $numbers, $bytes, $total];
    }

    /**
     * Uncommon batches hold long multibyte or binary reasons and unusual statuses. Parity also
     * varies the byte and batch limits ($edgeLimits); the benchmark keeps prepare()'s 2000 / 25.
     *
     * @return list<mixed>
     */
    private function preparationBatch(Randomizer $random, bool $uncommon, bool $edgeLimits = false): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $waitingReasons = ['Declared file inventory is still uploading; publication deferred.', 'Declared article inventory is still uploading; publication deferred.'];
        $statuses = $reasons = [];
        $current = $this->pick($random, $waitingReasons);
        for ($i = 0; $i < $count; $i++) {
            $roll = $random->getInt(0, 19);
            if ($roll < 14) {
                if ($random->getInt(0, 9) === 0) {
                    $current = $this->pick($random, $waitingReasons);
                }
                $statuses[] = 'waiting_upload';
                $reasons[] = $current;
            } elseif ($roll < 16) {
                $statuses[] = 'ready';
                $reasons[] = null;
            } elseif ($roll < 18) {
                $statuses[] = 'header_rejected';
                $reasons[] = $this->pick($random, CandidateHeaderInventory::REJECTION_REASONS);
            } else {
                $statuses[] = 'unknown';
                $reasons[] = 'Manifest read failed: '.$this->noise($random, 80);
            }
            if (! $uncommon) {
                continue;
            }
            match ($random->getInt(0, 9)) {
                0 => $reasons[$i] = str_repeat($this->pick($random, ['é', '€', '𝄞', 'a']), $random->getInt(400, 1200)),
                1 => $reasons[$i] = $this->noise($random, 3000, true),
                2 => $statuses[$i] = $this->pick($random, ['pending', '', 'READY', 'waiting_upload ']),
                3 => $reasons[$i] = null,
                4 => $reasons[$i] = $current.$this->noise($random, 2),
                default => null,
            };
        }

        if (! $edgeLimits) {
            return [$statuses, $reasons, 2000, 25];
        }

        return [$statuses, $reasons, $this->pick($random, ['0', '1', '7', '64', '2000']) === '2000' ? 2000 : $random->getInt(0, 64), $random->getInt(0, 6)];
    }

    private function timestamp(Randomizer $random, int $base, int $spread): string
    {
        return date('Y-m-d H:i:s', $base + $random->getInt(-$spread, $spread));
    }

    /** @return list<mixed> */
    private function repositoryBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $now = 1_790_000_000;
        $owed = date('Y-m-d H:i:s', $now - 52 * 3600);
        $hold = date('Y-m-d H:i:s', $now - 4 * 3600);
        $lasts = $statuses = $reasons = [];
        for ($i = 0; $i < $count; $i++) {
            $lasts[] = $this->timestamp($random, $now - 24 * 3600, 40 * 3600);
            $statuses[] = $random->getInt(0, 4) === 0 ? 'ready' : 'waiting_upload';
            $reasons[] = match ($random->getInt(0, 5)) {
                0 => null,
                1 => self::QUEUED,
                2 => self::DEFERRED.$this->pick($random, ['Partial release lacks the required completion.', 'Archive password detected.']),
                default => $this->pick($random, ['Declared file inventory is still uploading; publication deferred.', 'Declared article inventory is still uploading; publication deferred.']),
            };
            if (! $uncommon) {
                continue;
            }
            match ($random->getInt(0, 11)) {
                0 => $lasts[$i] = null,
                1 => $lasts[$i] = $this->pick($random, ['', $owed, $hold, '0000-00-00 00:00:00', '9999-12-31 23:59:59']),
                2 => $lasts[$i] = (string) $random->getInt(0, 99999),
                3 => $lasts[$i] = $this->noise($random, 40, true),
                4 => $reasons[$i] = substr(self::DEFERRED, 0, $random->getInt(0, 29)),
                5 => $reasons[$i] = self::QUEUED.$this->noise($random, 3),
                6 => $statuses[$i] = $this->pick($random, ['', 'pending', 'Ready', 'unknown']),
                7 => $reasons[$i] = self::DEFERRED.$this->noise($random, 2000, true),
                default => null,
            };
        }

        return [$lasts, $statuses, $reasons, $owed, $hold];
    }

    /** @return list<mixed> */
    private function sampleBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $claims = $messages = [];
        $base = $random->getInt(1, 10_000_000);
        for ($i = 0; $i < $count; $i++) {
            $claims[] = $base + $i * $random->getInt(1, 3);
            $messages[] = $this->messageId($random, 1, $random->getInt(1, 200));
            if (! $uncommon) {
                if ($random->getInt(0, 31) === 0) {
                    $messages[$i] = $messages[$random->getInt(0, $i)];
                }

                continue;
            }
            match ($random->getInt(0, 9)) {
                0 => $claims[$i] = $claims[$random->getInt(0, $i)],
                1 => $claims[$i] = $random->getInt(PHP_INT_MIN, 0),
                2 => $messages[$i] = $messages[$random->getInt(0, $i)],
                3 => $messages[$i] = trim($messages[$random->getInt(0, $i)], '<>'),
                4 => $messages[$i] = $this->pick($random, ['', '<>', '<<>>', '0', '00']),
                5 => $messages[$i] = '<'.$this->noise($random, 600, true).'>',
                default => null,
            };
        }
        $params = $uncommon
            ? [$random->getInt(-5, 300), $random->getInt(-5, 300), $random->getInt(-5, 40), $random->getInt(-5, 300)]
            : [$random->getInt(100, 1000), $random->getInt(0, 99), $random->getInt(4, 16), $random->getInt(8, 64)];
        if ($uncommon && $random->getInt(0, 3) === 0) {
            $params[$random->getInt(0, 3)] = $this->pick($random, ['min', 'max']) === 'min' ? PHP_INT_MIN : PHP_INT_MAX;
        }

        return [$claims, $messages, ...$params];
    }

    /**
     * The batch status agrees with assessment() of the same segments as a one-file manifest.
     *
     * @param  list<mixed>  $args
     */
    private function assertInventoryMatchesAssessment(array $args): void
    {
        [$ids, $numbers, $bytes, $total] = $args;
        $segments = [];
        foreach ($ids as $i => $id) {
            $segments[] = ['number' => $numbers[$i], 'bytes' => $bytes[$i], 'messageid' => $id];
        }
        $words = CandidateHeaderInventory::classifyArticleReferencesPhp($ids, $numbers, $bytes, $total);
        $status = $words[\count($ids)] & 0xFF;
        $expected = (new CandidateHeaderInventory)->assessment(['files' => [['key' => 'f', 'totalparts' => $total, 'segments' => $segments]]]);
        self::assertSame(match ($status) {
            0 => 'ready',
            2 => 'Declared article inventory is still uploading; publication deferred.',
            11 => 'Invalid declared article count in candidate headers.',
            14 => 'Invalid or duplicate article reference in candidate headers.',
            default => 'unexpected status '.$status,
        }, $expected['reason'] ?? 'ready');
    }
}
