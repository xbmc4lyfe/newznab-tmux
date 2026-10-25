<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GdprRequest;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\CollectionCleanupService;
use App\Services\CollectionsCleaningService;
use App\Services\Gdpr\GdprNotificationService;
use App\Services\Monitoring\Collectors\ApplicationCollector;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustHeaderStorageTxCollectionCleanupCleaningGdprAppCollectorW54ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        HeaderStorageTransaction::class => 'summarizeCleanupHashes',
        CollectionCleanupService::class => 'planDeletionIds',
        CollectionsCleaningService::class => 'subjectFeatureMasks',
        GdprNotificationService::class => 'planNotificationBatch',
        ApplicationCollector::class => 'runtimeVersionCodes',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of three runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 54, 200 pairs
     * per workload after 20 warmups, randomized call order, each side the fastest of three
     * back-to-back calls, release Rust, PHP 8.5.12 CLI without opcache, Apple Silicon macOS. Timings
     * include dispatch, packing, the FFI call and unpack('P*'). A class defaults to Rust only
     * when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        HeaderStorageTransaction::class => [3.044, 2.044, 2.256, 1.819],
        CollectionCleanupService::class => [3.165, 2.087, 2.504, 2.594],
        CollectionsCleaningService::class => [4.441, 3.898, 3.182, 2.247],
        GdprNotificationService::class => [2.331, 1.836, 2.501, 1.989],
        ApplicationCollector::class => [4.342, 3.388, 4.036, 2.95],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 54;

    private const WORDS = ['alpha', 'Bravo', 'charlie', 'DELTA', 'echo', 'Foxtrot', 'golf', 'hotel', 'India', 'juliet', 'kilo', 'Lima', 'mike', 'November', 'oscar', 'Papa'];

    private const TYPES = [GdprRequest::TYPE_EXPORT, GdprRequest::TYPE_ERASURE, GdprRequest::TYPE_RECTIFICATION, GdprRequest::TYPE_RESTRICTION];

    private const EVENTS = ['submitted', 'export_ready', 'erasure_completed', 'rejected'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW54RustFfi', 'withW54RustFfi', 'w54RustFfiState', 'w54Buffer', 'w54Decode', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW54RustFfi', 'withW54RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('w54RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W54_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w541_', (string) $reflection->getConstant('W54_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w54', $property->getName(), $class.' keeps Wave 54 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
        }
    }

    public function test_with_w54_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW54RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW54RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW54RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        self::assertFalse(HeaderStorageTransaction::usesRustFfi());
        self::assertTrue(HeaderStorageTransaction::usesW421RustFfi());
        self::assertFalse(CollectionCleanupService::usesRustFfi());
        self::assertTrue(CollectionCleanupService::usesW401RustFfi());
        self::assertFalse(CollectionsCleaningService::usesRustFfi());
        self::assertTrue(CollectionsCleaningService::usesW431RustFfi());
        self::assertFalse(GdprNotificationService::usesRustFfi());
        self::assertFalse(ApplicationCollector::usesRustFfi());
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: list<string>, 2: list<string>}>
     */
    public static function mismatchedColumns(): iterable
    {
        yield 'events' => [['export', 'erasure'], ['submitted'], ['a@x', 'b@x']];
        yield 'recipients' => [['export'], ['submitted'], ['a@x', 'b@x']];
        yield 'types' => [[], ['submitted'], ['a@x']];
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $events
     * @param  list<string>  $recipients
     */
    #[DataProvider('mismatchedColumns')]
    public function test_mismatched_columns_are_rejected_on_both_routes(array $types, array $events, array $recipients): void
    {
        foreach ([false, true] as $native) {
            try {
                GdprNotificationService::withW54RustFfi($native, static fn (): array => GdprNotificationService::planNotificationBatch($types, $events, $recipients));
                self::fail('Mismatched columns must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('same length', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function featureSubjects(): iterable
    {
        yield 'yenc suffix' => ['Movie yEnc', CollectionsCleaningService::W54_FEATURE_YENC_SUFFIX];
        yield 'yenc mid' => ['yEnc Movie', 0];
        yield 'yenc newline' => ["Movie yEnc\n", 0];
        yield 'size decimal' => ['a 12,5 Mb b', CollectionsCleaningService::W54_FEATURE_SIZE_TAG];
        yield 'size missing space' => ['12MB', 0];
        yield 'file counter' => ['[01/20] x', CollectionsCleaningService::W54_FEATURE_FILE_COUNTER];
        yield 'file counter open' => ['[01/20 x', 0];
        yield 'quoted' => ['"a.rar"', CollectionsCleaningService::W54_FEATURE_QUOTED_NAME];
        yield 'empty quotes' => ['"" x', 0];
        yield 'three quotes' => ['"""', 0];
        yield 'par2' => ['x.PAR2', CollectionsCleaningService::W54_FEATURE_PAR2];
        yield 'vol' => ['x.vol003+04', CollectionsCleaningService::W54_FEATURE_PAR2];
        yield 'vol missing count' => ['x.vol003+', 0];
        yield 'rar part' => ['x.Part12.RAR', CollectionsCleaningService::W54_FEATURE_RAR_PART];
        yield 'rar part no digits' => ['x.part.rar', 0];
        yield 'part counter' => ['x (3/9)', CollectionsCleaningService::W54_FEATURE_PART_COUNTER];
        yield 'all' => ['[1/2] "a.part1.rar" b.par2 1 GB (1/2) yenc', 127];
        yield 'empty' => ['', 0];
        yield 'binary' => ["\xFF\xFE (1/2) \xC3\xA9 2 kb", 66];
    }

    #[DataProvider('featureSubjects')]
    public function test_subject_feature_edge_cases(string $subject, int $mask): void
    {
        self::assertSame([$mask], $this->assertParity(CollectionsCleaningService::class, [[$subject]]));
    }

    public function test_cleanup_hash_summary_parity(): void
    {
        $sha = sha1('a');
        $md5 = md5('a');
        $summary = $this->assertParity(HeaderStorageTransaction::class, [[$sha, $md5, $sha, strtoupper($md5), 'xyz', '', $md5, substr($sha, 0, 39).'g', $sha.'0'], 1]);
        self::assertSame(['hashes' => 9, 'sha1' => 2, 'md5' => 2, 'mixed_case' => 1, 'invalid' => 4, 'distinct' => 2, 'statements' => 6], $summary);
        $this->assertParity(HeaderStorageTransaction::class, [[], 500]);
        $this->assertParity(HeaderStorageTransaction::class, [[$sha], 0]);
        $this->assertParity(HeaderStorageTransaction::class, [[$sha], PHP_INT_MIN]);
        $this->assertParity(HeaderStorageTransaction::class, [['a' => $sha, 9 => $md5], 500]);
        $this->assertParity(HeaderStorageTransaction::class, [[str_repeat('1', 32), str_repeat('1', 40), str_repeat('1', 32)], 500]);
        // A NUL byte splits the joined column, so Rust declines and PHP answers.
        $this->assertParity(HeaderStorageTransaction::class, [["a\0b", $sha], 500]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(HeaderStorageTransaction::class, $this->hashBatch(new Randomizer(new Mt19937(5400 + $i)), $uncommon));
            }
        }
    }

    public function test_deletion_id_plan_parity(): void
    {
        $plan = $this->assertParity(CollectionCleanupService::class, [[5, 3, 4, 4, 10, 0, -2, 12, 11], 2]);
        self::assertSame(['ids' => 9, 'invalid' => 2, 'distinct' => 6, 'duplicates' => 1, 'runs' => 2, 'chunks' => 3, 'min_id' => 3, 'max_id' => 12], $plan);
        $this->assertParity(CollectionCleanupService::class, [[], 500]);
        $this->assertParity(CollectionCleanupService::class, [[0, -1, PHP_INT_MIN], 500]);
        $this->assertParity(CollectionCleanupService::class, [[PHP_INT_MAX, PHP_INT_MAX - 1, 1], -5]);
        $this->assertParity(CollectionCleanupService::class, [['a' => 7, 'b' => 8, 3 => 8], 1]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(CollectionCleanupService::class, $this->idBatch(new Randomizer(new Mt19937(5410 + $i)), $uncommon));
            }
        }
    }

    public function test_subject_feature_mask_parity(): void
    {
        $this->assertParity(CollectionsCleaningService::class, [[]]);
        $this->assertParity(CollectionsCleaningService::class, [['k' => 'a.part1.rar', 3 => 'b yEnc']]);
        $this->assertParity(CollectionsCleaningService::class, [["a\0b yEnc", 'x (1/2)']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(CollectionsCleaningService::class, $this->subjectBatch(new Randomizer(new Mt19937(5420 + $i)), $uncommon));
            }
        }
    }

    public function test_notification_plan_parity(): void
    {
        $plan = $this->assertParity(GdprNotificationService::class, [
            ['erasure', 'export', 'Erasure', 'erasure', 'restriction', 'rectification', 'export'],
            ['submitted', 'export_ready', 'submitted', 'unknown', 'rejected', 'erasure_completed', 'rejected'],
            [" a@x.io\n", " \t", 'b@x.io', '', 'c@x', "\x0B\r\n", 'a b@x.io'],
        ]);
        self::assertSame(['rows' => 7, 'requester_mails' => 6, 'admin_copies' => 1, 'unknown_events' => 1, 'unknown_types' => 1, 'blank_recipients' => 3, 'malformed_recipients' => 2, 'deliverable' => 2], $plan);
        $shapes = ['a@b.c' => 1, '@b.c' => 0, 'a@@b.c' => 0, 'a@b@c.d' => 0, 'a@.c' => 0, 'a@b.' => 0, 'a.b@c' => 0, 'a@b.c.' => 0, 'a@b..c' => 1, "a\x0Bb@c.d" => 0, "a\tb@c.d" => 0, ' a@b.c ' => 1, 'é@ü.de' => 1];
        foreach ($shapes as $email => $deliverable) {
            $plan = $this->assertParity(GdprNotificationService::class, [['export'], ['rejected'], [(string) $email]]);
            self::assertSame($deliverable, $plan['deliverable'], (string) $email);
        }
        $this->assertParity(GdprNotificationService::class, [[], [], []]);
        $this->assertParity(GdprNotificationService::class, [['', ''], ['', ''], ['', '']]);
        $this->assertParity(GdprNotificationService::class, [['a' => 'erasure', 'b' => 'export'], [4 => 'submitted', 5 => 'rejected'], ['x' => ' a ', 'y' => "\xC2\xA0"]]);
        $this->assertParity(GdprNotificationService::class, [['erasure'], ['submitted'], ["\0"]]);
        $this->assertParity(GdprNotificationService::class, [["erasure\0", 'export'], ['submitted', 'rejected'], ['a', 'b']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(GdprNotificationService::class, $this->notificationBatch(new Randomizer(new Mt19937(5430 + $i)), $uncommon));
            }
        }
    }

    public function test_runtime_version_code_parity(): void
    {
        $codes = $this->assertParity(ApplicationCollector::class, [['8.5.12', 'v13.1', 'V2', '1234567.1', '1.2.3.4-dev', '1..2', '1.x.3', '999999.999999.999999', 'vv1', ' 1', '', '13.0.0-RC1', "8.5\n"]]);
        $code = static fn (int $a, int $b, int $c): int => ($a << 40) | ($b << 20) | $c;
        self::assertSame([$code(8, 5, 12), $code(13, 1, 0), $code(2, 0, 0), $code(123_456, 0, 0), $code(1, 2, 3), $code(1, 0, 0), $code(1, 0, 0), $code(999_999, 999_999, 999_999), -1, -1, -1, $code(13, 0, 0), $code(8, 5, 0)], $codes);
        $this->assertParity(ApplicationCollector::class, [[]]);
        $this->assertParity(ApplicationCollector::class, [['a' => '1.2', 7 => 'x']]);
        $this->assertParity(ApplicationCollector::class, [["1.2\0", '3']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(ApplicationCollector::class, $this->versionBatch(new Randomizer(new Mt19937(5440 + $i)), $uncommon));
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (self::ENTRIES as $class => $entry) {
            $call = $this->entry($class);
            foreach (['common', 'uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'uncommon');
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $args = $fixtures[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW54RustFfi($native, static function () use ($call, $args): array {
                            $result = null;
                            $best = PHP_INT_MAX;
                            for ($r = 0; $r < self::REPEATS; $r++) {
                                $start = hrtime(true);
                                $result = $call(...$args);
                                $best = min($best, hrtime(true) - $start);
                            }

                            return [$result, $best];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0], $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(\count($php) * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / max(1, array_sum($rust)), 3),
                    'p99_speedup' => round($php[$p99] / max(1, $rust[$p99]), 3),
                    'php_mean_ns' => intdiv(array_sum($php), \count($php)), 'rust_mean_ns' => intdiv(array_sum($rust), \count($rust)),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $artifact = sys_get_temp_dir().'/w541-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW54.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW54RustFfi'];

        return $uses();
    }

    private function entry(string $class): Closure
    {
        /** @var callable $entry */
        $entry = [$class, self::ENTRIES[$class]];

        return Closure::fromCallable($entry);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): mixed
    {
        $call = $this->entry($class);
        $php = $class::withW54RustFfi(false, static fn (): mixed => $call(...$args));
        $native = $class::withW54RustFfi(true, static fn (): mixed => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);

        return $php;
    }

    /** @return list<mixed> */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            HeaderStorageTransaction::class => $this->hashBatch($random, $uncommon),
            CollectionCleanupService::class => $this->idBatch($random, $uncommon),
            CollectionsCleaningService::class => $this->subjectBatch($random, $uncommon),
            GdprNotificationService::class => $this->notificationBatch($random, $uncommon),
            default => $this->versionBatch($random, $uncommon),
        };
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            HeaderStorageTransaction::class => ['nntmux_w541_cleanup_hash_summary_batch', [md5('a'), 32, 1, 500], 7, 7],
            CollectionCleanupService::class => ['nntmux_w541_deletion_id_plan_batch', [pack('P2', 1, 2), 16, 2, 500], 8, 8],
            CollectionsCleaningService::class => ['nntmux_w541_subject_feature_mask_batch', ['a (1/2)', 7, 1], 1, 1],
            GdprNotificationService::class => ['nntmux_w541_gdpr_notification_plan_batch', ['erasure', 7, 'submitted', 9, 'a@x.io', 6, 1], 8, 8],
            ApplicationCollector::class => ['nntmux_w541_version_code_batch', ['8.5.12', 6, 1], 1, 1],
        ];
        foreach ($calls as $class => [$symbol, $args, $words, $worst]) {
            $buffer = (new ReflectionMethod($class, 'w54Buffer'))->invoke(null, 4);
            self::assertIsArray($buffer, $class.' must load its Wave 54 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[128]');
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$args, $out, 128]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, $worst - 1]), $symbol.' short capacity');
        }
    }

    /**
     * Calls an export declared by a class's W54_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /**
     * @param  list<string>  $values
     */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function words(Randomizer $random, int $min, int $max, string $glue = '.'): string
    {
        $words = [];
        for ($i = $random->getInt($min, $max); $i > 0; $i--) {
            $words[] = $this->pick($random, self::WORDS);
        }

        return implode($glue, $words);
    }

    /** @return array{0: list<string>, 1: int} */
    private function hashBatch(Randomizer $random, bool $uncommon): array
    {
        $pool = [];
        for ($i = 0; $i < 48; $i++) {
            $pool[] = sha1($random->getBytes(8));
        }
        $hashes = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $hashes[] = match ($uncommon ? $random->getInt(3, 8) : $random->getInt(0, 4)) {
                0, 1 => sha1($random->getBytes(12)),
                2 => $pool[$random->getInt(0, 47)],
                3 => md5($random->getBytes(12)),
                4 => $random->getInt(0, 3) === 0 ? strtoupper(sha1($random->getBytes(6))) : sha1($random->getBytes(6)),
                5 => substr(sha1($random->getBytes(6)), 0, $random->getInt(0, 39)),
                6 => sha1($random->getBytes(6)).str_repeat('z', $random->getInt(1, 40)),
                7 => strtoupper(md5($random->getBytes(6))),
                default => $this->words($random, 1, 8, '-'),
            };
        }

        return [$hashes, $uncommon ? $random->getInt(-5, 64) : 500];
    }

    /** @return array{0: list<int>, 1: int} */
    private function idBatch(Randomizer $random, bool $uncommon): array
    {
        $base = $random->getInt(1, 50_000_000);
        $ids = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $ids[] = match ($uncommon ? $random->getInt(2, 6) : $random->getInt(0, 3)) {
                0, 1 => $base++,
                2 => $base + $random->getInt(-40, 40),
                3 => $base + $random->getInt(1, 5000),
                4 => $random->getInt(-1000, 0),
                5 => $random->getInt(1, PHP_INT_MAX),
                default => $random->getInt(0, 1) === 0 ? PHP_INT_MAX : PHP_INT_MIN,
            };
        }

        return [$ids, $uncommon ? $random->getInt(-3, 40) : 500];
    }

    /** @return array{0: list<string>} */
    private function subjectBatch(Randomizer $random, bool $uncommon): array
    {
        $subjects = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $total = $random->getInt(1, 400);
            $part = $random->getInt(1, $total);
            $name = $this->words($random, 2, 6);
            $subjects[] = match ($uncommon ? $random->getInt(4, 9) : $random->getInt(0, 4)) {
                0, 1 => '['.$random->getInt(1, 40).'/40] - "'.$name.'.part'.$random->getInt(1, 99).'.rar" yEnc ('.$part.'/'.$total.') '.$random->getInt(100, 800).'.'.$random->getInt(0, 99).' MB',
                2 => $name.' - "'.$name.'.vol'.$random->getInt(0, 63).'+'.$random->getInt(1, 64).'.par2" yEnc ('.$part.'/'.$total.')',
                3 => '"'.$name.'.nzb" yEnc',
                4 => $name.' plain text subject '.$random->getInt(1, 9999),
                5 => str_repeat('('.$this->words($random, 1, 3, ' ').') [x/] "" ', $random->getInt(10, 40)).'yenc',
                6 => '['.$name.'] '.str_repeat('é ü 1 x .par .vol1+ .part1.ra ', $random->getInt(5, 30)).'(1/',
                7 => str_repeat($this->words($random, 1, 3).' 12 KB ', $random->getInt(4, 12)).'"'.str_repeat('"', $random->getInt(0, 3)),
                8 => str_repeat($this->words($random, 3, 8, ' ').' ', $random->getInt(8, 20)),
                default => str_repeat('1', $random->getInt(1, 400)).' '.str_repeat('x', $random->getInt(200, 600)).' YENC',
            };
        }

        return [$subjects];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<string>} */
    private function notificationBatch(Randomizer $random, bool $uncommon): array
    {
        $types = $events = $recipients = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            if ($uncommon && $random->getInt(0, 1) === 0) {
                $types[] = $this->pick($random, ['Export', 'erasure ', 'unknown', '', 'restriction', str_repeat('x', $random->getInt(0, 40))]);
                $events[] = $this->pick($random, ['Submitted', 'export_ready ', '', 'cancelled', 'submitted', str_repeat('submitted', $random->getInt(1, 4))]);
                $recipients[] = $this->pick($random, ['', ' ', "\t\n", "\x0B", ' a@b.example ', str_repeat(' ', $random->getInt(1, 60)).'x', 'a@@b.c', '@b.c', 'a@b.', 'user@localhost', 'a b@c.d', str_repeat('x', $random->getInt(1, 80)).'@'.str_repeat('y.', $random->getInt(1, 20)).'z']);
            } else {
                $types[] = $this->pick($random, self::TYPES);
                $events[] = $this->pick($random, self::EVENTS);
                $recipients[] = strtolower($this->pick($random, self::WORDS)).$random->getInt(1, 9999).'@'.$this->pick($random, ['news.example', 'mail.example', 'host.invalid']);
            }
        }

        return [$types, $events, $recipients];
    }

    /** @return array{0: list<string>} */
    private function versionBatch(Randomizer $random, bool $uncommon): array
    {
        $versions = [];
        for ($i = $random->getInt(64, 256); $i > 0; $i--) {
            $versions[] = match ($uncommon ? $random->getInt(3, 8) : $random->getInt(0, 3)) {
                0, 1 => $random->getInt(5, 9).'.'.$random->getInt(0, 5).'.'.$random->getInt(0, 40),
                2 => 'v'.$random->getInt(10, 13).'.'.$random->getInt(0, 60).'.'.$random->getInt(0, 9),
                3 => $random->getInt(8, 13).'.'.$random->getInt(0, 9).'.'.$random->getInt(0, 9).'-'.$this->pick($random, ['dev', 'RC1', 'beta2', 'alpha']),
                4 => str_repeat((string) $random->getInt(1, 9), $random->getInt(1, 12)).'.'.str_repeat('0', $random->getInt(0, 9)),
                5 => $this->pick($random, ['', 'v', 'vv1', ' 1.0', 'dev-master', 'x.1']),
                6 => $random->getInt(0, 999_999).'..'.$random->getInt(0, 99),
                7 => 'V'.$random->getInt(0, 9).'.'.$this->words($random, 1, 3),
                default => $random->getInt(1, 9).'.'.$random->getInt(0, 9).'.'.$random->getInt(0, 9).'.'.$random->getInt(0, 9).str_repeat('+build', $random->getInt(1, 20)),
            };
        }

        return [$versions];
    }
}
