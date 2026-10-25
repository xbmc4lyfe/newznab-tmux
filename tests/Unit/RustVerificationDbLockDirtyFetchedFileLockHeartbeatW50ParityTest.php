<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\DatabaseVerificationLockStore;
use App\Services\Releases\Verification\DirtyCandidateSettler;
use App\Services\Releases\Verification\FetchedArticle;
use App\Services\Releases\Verification\FileVerificationLockStore;
use App\Services\Releases\Verification\LeaseHeartbeat;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustVerificationDbLockDirtyFetchedFileLockHeartbeatW50ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        DatabaseVerificationLockStore::class => 'planLockKeys',
        DirtyCandidateSettler::class => 'classifyDirtyRows',
        FetchedArticle::class => 'summarizeTransfers',
        FileVerificationLockStore::class => 'planLockFiles',
        LeaseHeartbeat::class => 'planRenewals',
    ];

    /** Class => Wave 50.2 export. */
    private const SYMBOLS = [
        DatabaseVerificationLockStore::class => 'nntmux_w502_verification_lock_keys_batch',
        DirtyCandidateSettler::class => 'nntmux_w502_dirty_candidate_settle_batch',
        FetchedArticle::class => 'nntmux_w502_fetched_article_transfer_batch',
        FileVerificationLockStore::class => 'nntmux_w502_file_lock_plan_batch',
        LeaseHeartbeat::class => 'nntmux_w502_lease_heartbeat_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns): the per-metric median of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 50, each metric
     * the median of 3 rounds of 1000 pairs after 50 warmups, randomized fixtures, alternating
     * call order, release Rust, PHP 8.5.12 CLI without opcache, Apple Silicon macOS on a shared
     * host at load ~6-8). Timings include dispatch, packing, the FFI call and unpack('P*'). The
     * runs' lows were FetchedArticle Common mean 2.074x and LeaseHeartbeat Common mean 1.879x.
     * Earlier layouts that returned one or more words per article or tick measured below 1.5x
     * on Common p99 for both, which the packed flag words and the per-file article batch fixed.
     * A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        DatabaseVerificationLockStore::class => [3.311, 3.000, 3.660, 2.961],
        DirtyCandidateSettler::class => [3.376, 2.388, 3.221, 2.237],
        FetchedArticle::class => [2.088, 2.345, 2.713, 3.376],
        FileVerificationLockStore::class => [4.789, 4.290, 12.739, 10.659],
        LeaseHeartbeat::class => [2.073, 2.468, 3.409, 3.549],
    ];

    private const ROUNDS = 3;

    private const PAIRS = 1000;

    private const WARMUPS = 50;

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW50RustFfi', 'withW50RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->hasMethod('w50RustFfiState'), $class);
            self::assertTrue($reflection->getMethod('w50RustFfiState')->isPrivate(), $class);
            self::assertTrue($reflection->hasConstant('W50_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W50_RUST_FFI_DEFAULT'), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W50_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w50', strtolower((string) $property), 'Wave 50 state lives in w50RustFfiState().');
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW50RustFfi(), $class);
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW50RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW50RustFfi(! $enabled, static fn (): bool => $class::usesW50RustFfi());

                    return [$class::usesW50RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW50RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW50RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW50RustFfi(), $class);
        }
        // The Wave 50 toggles leave the earlier Rust switches untouched.
        self::assertFalse(FetchedArticle::usesRustFfi());
        self::assertFalse(FileVerificationLockStore::usesRustFfi());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5000));
        foreach (array_keys(self::ENTRIES) as $class) {
            $args = $this->fixture($class, $random, false);
            $php = $this->call($class, 'Php', $args);
            foreach ([true, false] as $native) {
                self::assertSame($php, $class::withW50RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
            }
            self::assertSame($php, $this->call($class, 'Native', $args), $class);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $ffi = $this->ffi(DatabaseVerificationLockStore::class);
        $out = $ffi->new('int64_t[16]');
        $symbol = self::SYMBOLS[DatabaseVerificationLockStore::class];
        self::assertSame(-1, $this->invoke($ffi, $symbol, ['p', 1, 'a', 1, 1, null, 16]));
        self::assertSame(-6, $this->invoke($ffi, $symbol, ['p', 1, 'a', 1, 1, $out, 5]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, ['p', 1, "a\0b", 3, 1, $out, 16]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 'a', 1, 1, $out, 16]));
        self::assertSame(-1, $this->invoke($ffi, $symbol, ['p', 1, null, 1, 1, $out, 16]));
        self::assertSame(4, $this->invoke($ffi, $symbol, [null, 0, null, 0, 0, $out, 16]));

        $article = $this->ffi(FetchedArticle::class);
        $articleOut = $article->new('int64_t[16]');
        $table = pack('P*', 1, 0, 1, 1);
        $articleSymbol = self::SYMBOLS[FetchedArticle::class];
        self::assertSame(7, $this->invoke($article, $articleSymbol, [$table, 32, 1, 5, 1, 1, $articleOut, 16]));
        self::assertSame(-7, $this->invoke($article, $articleSymbol, [$table, 32, 1, 5, 1, 1, $articleOut, 6]));
        self::assertSame(-1, $this->invoke($article, $articleSymbol, [$table, 24, 1, 5, 1, 1, $articleOut, 16]));
        self::assertSame(-1, $this->invoke($article, $articleSymbol, [$table, 32, 1, 5, 1, 2, $articleOut, 16]));
        self::assertSame(-1, $this->invoke($article, $articleSymbol, [null, 32, 1, 5, 1, 1, $articleOut, 16]));

        $dirty = $this->ffi(DirtyCandidateSettler::class);
        $dirtyOut = $dirty->new('int64_t[16]');
        $dirtySymbol = self::SYMBOLS[DirtyCandidateSettler::class];
        self::assertSame(7, $this->invoke($dirty, $dirtySymbol, [pack('P', 1), 8, 'x', 1, 't', 1, 1, '', 0, 0, 0, 'u', 1, 1, $dirtyOut, 16]));
        self::assertSame(-1, $this->invoke($dirty, $dirtySymbol, [pack('P', 1), 8, 'x', 1, 't', 1, 1, '', 0, 0, 2, 'u', 1, 1, $dirtyOut, 16]));
        self::assertSame(-1, $this->invoke($dirty, $dirtySymbol, [pack('P', 1), 8, 'x', 1, 't', 1, 1, null, 4, 0, 1, 'u', 1, 1, $dirtyOut, 16]));

        $file = $this->ffi(FileVerificationLockStore::class);
        $fileOut = $file->new('int64_t[16]');
        $fileSymbol = self::SYMBOLS[FileVerificationLockStore::class];
        self::assertSame(-1, $this->invoke($file, $fileSymbol, ['/d', 2, 'a', 1, pack('P', 1), 4, 1, 0, $fileOut, 16]));
        self::assertSame(-6, $this->invoke($file, $fileSymbol, ['/d', 2, 'a', 1, pack('P', 1), 8, 1, 0, $fileOut, 3]));

        $lease = $this->ffi(LeaseHeartbeat::class);
        $leaseOut = $lease->new('int64_t[16]');
        $leaseSymbol = self::SYMBOLS[LeaseHeartbeat::class];
        self::assertSame(-1, $this->invoke($lease, $leaseSymbol, [pack('e', 1.0), 8, 1, 5, 0.0, 1.0, $leaseOut, 16]));
        self::assertSame(-1, $this->invoke($lease, $leaseSymbol, [pack('e', 1.0), 8, 2, 0, 0.0, 1.0, $leaseOut, 16]));
        self::assertSame(-1, $this->invoke($lease, $leaseSymbol, [pack('e', 1.0), 8, 1, 0, 0.0, 1.0, null, 16]));
    }

    public function test_database_lock_key_parity(): void
    {
        $this->requireBindings();
        $scope = 'nntmux/';
        $prefix = 'nnv:'.substr(sha1($scope), 0, 12).':';
        $long = str_repeat('k', 64 - \strlen($prefix));
        $words = [
            1 | 2 | (\strlen($prefix) + 6) << 8 | 512 << 24, crc32($prefix.'worker'),
            1 | 4 | (\strlen($prefix) + 10) << 8 | 511 << 24, crc32($prefix.'worker.511'),
            1 | (\strlen($prefix) + 8) << 8, crc32($prefix.'reset.07'),
            1 | 16 | \strlen($prefix) << 8, crc32($prefix),
            1 | 64 << 8, crc32($prefix.$long),
            (65 << 8), crc32($prefix.$long.'x'),
            1 | 2 | 8 | (\strlen($prefix) + 6) << 8 | 512 << 24, crc32($prefix.'worker'),
            6, 1, 1,
        ];
        $args = [$scope, ['worker', 'worker.511', 'reset.07', '', $long, $long.'x', 'worker']];
        self::assertSame([...$words, $this->digest($words)], DatabaseVerificationLockStore::planLockKeysPhp(...$args));
        $this->assertParity(DatabaseVerificationLockStore::class, $args);
        $this->assertParity(DatabaseVerificationLockStore::class, [$scope, []]);
        $this->assertParity(DatabaseVerificationLockStore::class, ['', ['']]);
        $this->assertParity(DatabaseVerificationLockStore::class, [$scope, ["worker\0", 'reset']]);
        $this->assertParity(DatabaseVerificationLockStore::class, ["db\0/x", ['worker.0', 'worker.512', 'worker.0511', 'worker.', '.worker', 'reset.63', 'reset.64', 'reset.9999', 'reset.10000', 'worker.-1', 'worker.+1', 'worker. 1']]);
        $this->assertParity(DatabaseVerificationLockStore::class, [$scope, ['1', '01', '1', 'worker.1.2', 'worker..1', "\xff\x80", str_repeat('é', 40), 'WORKER', 'reset.reset.1']]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->lockKeyBatch(new Randomizer(new Mt19937(5010 + $i)), $uncommon);
                $this->assertParity(DatabaseVerificationLockStore::class, $args);
                $words = DatabaseVerificationLockStore::planLockKeysPhp(...$args);
                foreach ($args[1] as $row => $name) {
                    $fits = \strlen($prefix = 'nnv:'.substr(sha1($args[0]), 0, 12).':'.$name) <= 64;
                    self::assertSame($fits, ($words[2 * $row] & 1) === 1, $prefix);
                }
            }
        }
    }

    public function test_dirty_candidate_parity(): void
    {
        $this->requireBindings();
        $a = sha1('collection-a');
        $upperA = strtoupper($a);
        $bin = (string) hex2bin($a);
        $head = unpack('P', $bin);
        self::assertIsArray($head);
        $words = [
            1 | 4 | crc32($a) << 32, $head[1],
            2 | 4 | 8 | crc32('short') << 32, 0,
            1 | 2 | 4 | 8 | 16 | crc32($upperA) << 32, $head[1],
            1 | 2 | 16 | 32 | crc32($a) << 32, $head[1],
            2, 0, 2, 2,
        ];
        $args = [
            [5, 6, 7, 6],
            [$a, 'short', $upperA, $a],
            ['2026-01-01 00:00:00', '2026-01-01 00:00:01', '2026-01-02 00:00:00', '2026-01-03 00:00:00'],
            ['at' => '2026-01-01 00:00:00', 'id' => 5],
            ['at' => '2026-01-02 00:00:00', 'id' => 7],
        ];
        self::assertSame([...$words, $this->digest($words)], DirtyCandidateSettler::classifyDirtyRowsPhp(...$args));
        $this->assertParity(DirtyCandidateSettler::class, $args);
        $upper = ['at' => '2026-01-02 00:00:00', 'id' => PHP_INT_MAX];
        $this->assertParity(DirtyCandidateSettler::class, [[], [], [], null, $upper]);
        $this->assertParity(DirtyCandidateSettler::class, [[1], [$a], ['2026-01-01 00:00:00'], null, $upper]);
        $this->assertParity(DirtyCandidateSettler::class, [[1, 2], [$a], ['x', 'y'], null, $upper]);
        $this->assertParity(DirtyCandidateSettler::class, [[1], ["a\0b"], ['x'], null, $upper]);
        $this->assertParity(DirtyCandidateSettler::class, [[1, 2], [$a, $a], ["2026\0", 'x'], null, $upper]);
        $this->assertParity(DirtyCandidateSettler::class, [[1, 2, 3], [$a, $a, $a], ['10', '9', '010'], ['at' => "9\0", 'id' => 0], ['at' => '10', 'id' => 2]]);
        $this->assertParity(DirtyCandidateSettler::class, [[PHP_INT_MIN, PHP_INT_MAX, 0, -1], [str_repeat('g', 40), str_repeat('F', 40), $a.'0', substr($a, 1)], ['', '', "\xff", ' '], ['at' => '', 'id' => PHP_INT_MIN], ['at' => '', 'id' => PHP_INT_MAX]]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->dirtyBatch(new Randomizer(new Mt19937(5020 + $i)), $uncommon);
                $this->assertParity(DirtyCandidateSettler::class, $args);
                $words = DirtyCandidateSettler::classifyDirtyRowsPhp(...$args);
                foreach ($args[1] as $row => $identity) {
                    self::assertSame(DirtyCandidateSettler::sourceHashPhp($identity) !== null, ($words[2 * $row] & 1) === 1);
                }
            }
        }
    }

    public function test_fetched_article_parity(): void
    {
        $this->requireBindings();
        $words = [100, 0, -1, -1, 15 | (1 | 2 | 4 | 16 | 32) << 8 | 4 << 16 | 12 << 24, PHP_INT_MAX, 105, 2, 2];
        $args = [200, 3, [120, -5, 10, PHP_INT_MAX], [0, 0, -1, PHP_INT_MAX], [100, -1, 4, 1], [3, 1, 2, 3]];
        self::assertSame([...$words, $this->digest($words)], FetchedArticle::summarizeTransfersPhp(...$args));
        $this->assertParity(FetchedArticle::class, $args);
        $this->assertParity(FetchedArticle::class, [5, null, [], [], [], []]);
        $this->assertParity(FetchedArticle::class, [5, 1, [1, 2], [1], [1, 2], [1, 2]]);
        $this->assertParity(FetchedArticle::class, [1, null, [1], [0], [1], [1]]);
        $this->assertParity(FetchedArticle::class, [PHP_INT_MIN, PHP_INT_MIN, [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MAX, 0, 1], [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MIN, 0, 0]]);
        $this->assertParity(FetchedArticle::class, [PHP_INT_MAX, 0, [5, 5, 5], [5, 6, 0], [5, 5, 5], [0, 0, 0]]);
        $this->assertParity(FetchedArticle::class, [10, 9, range(1, 9), range(0, 8), range(1, 9), range(1, 9)]);
        // Each row agrees with the single-article summarizeTransferPhp().
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->transferBatch(new Randomizer(new Mt19937(5030 + $i)), $uncommon);
                $this->assertParity(FetchedArticle::class, $args);
                $words = FetchedArticle::summarizeTransfersPhp(...$args);
                [$size, $total, $rawBytes, $offsets, $decodedBytes, $parts] = $args;
                $count = \count($rawBytes);
                foreach ($rawBytes as $row => $raw) {
                    $summary = FetchedArticle::summarizeTransferPhp($raw, $size, $offsets[$row], $decodedBytes[$row], $parts[$row], $total);
                    $flags = $words[$count + ($row >> 3)] >> (($row & 7) << 3) & 0xFF;
                    self::assertSame($summary['end_offset'] ?? -1, $words[$row]);
                    self::assertSame($summary['within_file'], ($flags & 2) !== 0);
                    self::assertSame($summary['final_part'], ($flags & 4) === 0 ? null : ($flags & 8) !== 0);
                }
            }
        }
    }

    public function test_file_lock_parity(): void
    {
        $this->requireBindings();
        $path = static fn (string $name): string => '/tmp/v/'.$name.'.lock';
        $long = str_repeat('n', 251);
        $words = [
            39 | 19 << 8 | crc32($path('release')) << 32, 5_000_001_000,
            22 | 14 << 8 | crc32($path('..')) << 32, 1000,
            6 | 15 << 8 | crc32($path('a/b')) << 32, 1000,
            55 | 19 << 8 | crc32($path('.hidden')) << 32, 2_000_001_000,
            5 | 263 << 8 | crc32($path($long)) << 32, 1_000_001_000,
            15 | 19 << 8 | crc32($path('release')) << 32, 3_000_001_000,
            2, 1, 2_000_001_000,
        ];
        $args = ['/tmp/v', ['release', '..', 'a/b', '.hidden', $long, 'release'], [5, 0, -1, 2, 1, 3], 1000];
        self::assertSame([...$words, $this->digest($words)], FileVerificationLockStore::planLockFilesPhp(...$args));
        $this->assertParity(FileVerificationLockStore::class, $args);
        foreach ([0, 1, 5, -1, 3600] as $seconds) {
            $now = 1_700_000_000_000_000_000;
            self::assertSame(FileVerificationLockStore::computePollDeadlineNsPhp($now, $seconds), FileVerificationLockStore::planLockFilesPhp('/d', ['x'], [$seconds], $now)[1]);
        }
        $this->assertParity(FileVerificationLockStore::class, ['/d', [], [], 0]);
        $this->assertParity(FileVerificationLockStore::class, ['', ['', '.', 'x'], [1, 2, 3], -5]);
        $this->assertParity(FileVerificationLockStore::class, ['/d', ['a', 'b'], [1], 0]);
        $this->assertParity(FileVerificationLockStore::class, ['/d', ["a\0b"], [1], 0]);
        $this->assertParity(FileVerificationLockStore::class, ["/d\0x", ["a\tb", "a\x7f", "\xff\xfe", 'a b', '...'], [PHP_INT_MAX, PHP_INT_MIN, 9_223_372_036, 9_223_372_037, 1], PHP_INT_MAX - 5]);
        $this->assertParity(FileVerificationLockStore::class, [str_repeat('d', 1020), ['a', 'ab'], [1, 1], PHP_INT_MIN]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(FileVerificationLockStore::class, $this->fileLockBatch(new Randomizer(new Mt19937(5040 + $i)), $uncommon));
            }
        }
    }

    public function test_lease_heartbeat_parity(): void
    {
        $this->requireBindings();
        $bits = static function (float $value): int {
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('P', pack('e', $value));

            return $unpacked[1];
        };
        $words = [1 | 0 << 4 | 1 << 8 | 2 << 12 | 4 << 16 | 1 << 20 | 13 << 24, 4, 1, 1, $bits(INF)];
        $args = [null, [10.0, 10.5, 11.0, 9.0, NAN, 12.5, INF], 1.0];
        self::assertSame([...$words, $this->digest($words)], LeaseHeartbeat::planRenewalsPhp(...$args));
        $this->assertParity(LeaseHeartbeat::class, $args);
        $this->assertParity(LeaseHeartbeat::class, [null, [], 1.0]);
        $this->assertParity(LeaseHeartbeat::class, [5.0, [], 1.0]);
        $this->assertParity(LeaseHeartbeat::class, [5.0, [5.5, 6.0, 7.0], NAN]);
        $this->assertParity(LeaseHeartbeat::class, [NAN, [1.0, 2.0], 0.0]);
        $this->assertParity(LeaseHeartbeat::class, [-INF, [-INF, INF, -0.0, 0.0, PHP_FLOAT_MAX, -PHP_FLOAT_MAX], -1.0]);
        $this->assertParity(LeaseHeartbeat::class, [0.1, [0.30000000000000004, 0.4, 0.7], 0.2]);
        // Each tick's due flag matches the heartbeat object driven by the same clock.
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->heartbeatBatch(new Randomizer(new Mt19937(5050 + $i)), $uncommon);
                $this->assertParity(LeaseHeartbeat::class, $args);
                if ($args[0] !== null) {
                    continue;
                }
                $ticks = $args[1];
                $renewals = [];
                $clock = static function () use (&$ticks): float {
                    return (float) array_shift($ticks);
                };
                $heartbeat = new LeaseHeartbeat(static function () use (&$renewals, &$ticks): void {
                    $renewals[] = \count($ticks);
                }, $args[2], $clock(...));
                $due = [];
                foreach ($args[1] as $row => $tick) {
                    $before = \count($renewals);
                    $heartbeat();
                    $due[$row] = \count($renewals) > $before;
                }
                $words = LeaseHeartbeat::planRenewalsPhp(...$args);
                foreach ($due as $row => $renewed) {
                    self::assertSame($renewed, ($words[$row >> 4] >> (($row & 15) << 2) & 1) === 1);
                }
            }
        }
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture, alternating which runs first. Common
     * batches hold 64 typical records, Uncommon batches 128-256 long, malformed or adversarial
     * ones. Each of ROUNDS rounds times PAIRS pairs after WARMUPS warmups; a metric is the median
     * of its round values (nearest-rank p99 per round). Every pair must agree; raw pairs are
     * written outside the checkout. A class must default to Rust exactly when all four metrics
     * reach 1.5x.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(50));
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
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW50RustFfi($native, fn (): array => $this->timed($class, $args));
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
        $artifact = sys_get_temp_dir().'/w502-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'seed' => 50, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW50.2 paired benchmark artifact: {$artifact}\n".json_encode(array_map(static fn (array $class): array => array_map(static fn (mixed $w): mixed => \is_array($w) ? array_diff_key($w, ['rounds' => true]) : $w, $class), $summary), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW50RustFfi(), $class.' default must follow the measured 1.5x gate');
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
        $value = (new ReflectionClass($class))->getConstant('W50_RUST_FFI_DEFAULT');
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
        $php = $class::withW50RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW50RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w50RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w50 binding');

        return $ffi;
    }

    /** Each Wave 50.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            DatabaseVerificationLockStore::class => ['p', 1, 'a', 1, 1],
            DirtyCandidateSettler::class => [pack('P', 1), 8, 'x', 1, 't', 1, 1, '', 0, 0, 0, 'u', 1, 1],
            FetchedArticle::class => [pack('P*', 1, 0, 1, 1), 32, 1, 5, 1, 1],
            FileVerificationLockStore::class => ['/d', 2, 'a', 1, pack('P', 1), 8, 1, 0],
            LeaseHeartbeat::class => [pack('e', 1.0), 8, 1, 0, 0.0, 1.0],
        ];
        $words = [
            DatabaseVerificationLockStore::class => 6,
            DirtyCandidateSettler::class => 7,
            FetchedArticle::class => 7,
            FileVerificationLockStore::class => 6,
            LeaseHeartbeat::class => 6,
        ];
        foreach ($calls as $class => $args) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[16]');
            self::assertSame($words[$class], $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 16]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W50_FFI_CDEF.
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
            DatabaseVerificationLockStore::class => $this->lockKeyBatch($random, $uncommon),
            DirtyCandidateSettler::class => $this->dirtyBatch($random, $uncommon),
            FetchedArticle::class => $this->transferBatch($random, $uncommon),
            FileVerificationLockStore::class => $this->fileLockBatch($random, $uncommon),
            LeaseHeartbeat::class => $this->heartbeatBatch($random, $uncommon),
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
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789.-_:/ ', $length);
    }

    /** Lock names as verification takes them: groups, stripes and per-release names. */
    private function lockName(Randomizer $random): string
    {
        return match ($random->getInt(0, 5)) {
            0 => $this->pick($random, ['worker', 'reset']),
            1 => 'worker.'.$random->getInt(0, 511),
            2 => 'reset.'.$random->getInt(0, 63),
            3 => 'release.'.$random->getInt(1, 90_000_000),
            4 => 'collection.'.substr(sha1((string) $random->getInt(0, PHP_INT_MAX)), 0, 20),
            default => 'publish',
        };
    }

    /** @return list<mixed> */
    private function lockKeyBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = ! $uncommon ? $this->lockName($random) : match ($random->getInt(0, 7)) {
                0 => $this->lockName($random).str_repeat('x', $random->getInt(20, 80)),
                1 => $this->pick($random, ['worker', 'reset']).'.'.$this->pick($random, ['0512', '512', '64', '9999', '10000', '', '-1', '+1', '1.0', '00']),
                2 => $this->noise($random, 70, true),
                3 => $names[$random->getInt(0, max(0, $i - 1))] ?? '',
                4 => '',
                default => $this->lockName($random),
            };
        }

        return [$this->pick($random, ['nntmux/', 'nntmux/nn_', 'usenet_index/', 'db/'.$this->noise($random, 40)]), $names];
    }

    private function timestamp(Randomizer $random, int $base, int $spread): string
    {
        return date('Y-m-d H:i:s', $base + $random->getInt(-$spread, $spread));
    }

    /** @return list<mixed> */
    private function dirtyBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $base = 1_790_000_000 + $random->getInt(0, 1_000_000);
        $firstId = $random->getInt(1, 50_000_000);
        $ids = $identities = $ats = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $uncommon && $random->getInt(0, 9) === 0 ? $this->pick($random, ['0', '-1', (string) PHP_INT_MAX, (string) $firstId]) : $firstId + $i;
            $identity = sha1((string) $random->getInt(0, PHP_INT_MAX));
            $identities[] = ! $uncommon ? $identity : match ($random->getInt(0, 7)) {
                0 => strtoupper($identity),
                1 => substr($identity, 0, $random->getInt(0, 39)),
                2 => $identity.'0',
                3 => $identities[$random->getInt(0, max(0, $i - 1))] ?? $identity,
                4 => $this->noise($random, 48, true),
                default => $identity,
            };
            $ats[] = $uncommon && $random->getInt(0, 7) === 0 ? $this->noise($random, 24) : $this->timestamp($random, $base, 86_400);
        }
        $ids = array_map(intval(...), $ids);
        $after = $uncommon && $random->getInt(0, 3) === 0 ? null : ['at' => $this->timestamp($random, $base, 43_200), 'id' => $firstId + $random->getInt(0, $count)];
        $upper = ['at' => $this->timestamp($random, $base + 43_200, 21_600), 'id' => $random->getInt(0, 1) === 0 ? PHP_INT_MAX : $firstId + $random->getInt(0, $count)];

        return [$ids, $identities, $ats, $after, $upper];
    }

    /** @return list<mixed> */
    private function transferBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $segment = (int) $this->pick($random, ['384000', '512000', '716800', '768000']);
        $total = $count + ($uncommon ? $random->getInt(-5, 5) : 0);
        $size = $segment * $total - $random->getInt(0, $segment - 1);
        $first = $uncommon ? $random->getInt(0, 3) : 0;
        $raw = $offsets = $decoded = $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = ($first + $i) * $segment;
            $bytes = min($segment, max(0, $size - $offset));
            $raw[] = (int) ($bytes * (1.02 + $random->getFloat(0.0, 0.02))) + 200;
            $offsets[] = $offset;
            $decoded[] = $bytes;
            $parts[] = $first + $i + 1;
            if ($uncommon) {
                $slot = $i;
                match ($random->getInt(0, 9)) {
                    0 => $raw[$slot] = -$random->getInt(1, 1000),
                    1 => $decoded[$slot] = -$random->getInt(1, 1000),
                    2 => $offsets[$slot] = $random->getInt(0, 1) === 0 ? -1 : PHP_INT_MAX - $random->getInt(0, 10),
                    3 => $parts[$slot] = $total,
                    4 => $raw[$slot] = PHP_INT_MAX - $random->getInt(0, 10),
                    5 => $decoded[$slot] = $random->getInt(0, PHP_INT_MAX),
                    default => null,
                };
            }
        }
        if ($uncommon) {
            $size = match ($random->getInt(0, 3)) {
                0 => $random->getInt(PHP_INT_MIN, 0),
                1 => PHP_INT_MAX,
                default => $size,
            };
            $total = $random->getInt(0, 2) === 0 ? null : $total;
        }

        return [$size, $total, $raw, $offsets, $decoded, $parts];
    }

    /** @return list<mixed> */
    private function fileLockBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $names = $timeouts = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = ! $uncommon ? $this->lockName($random) : match ($random->getInt(0, 9)) {
                0 => $this->pick($random, ['', '.', '..', '.hidden', 'a/b', '/abs', "tab\tname", "del\x7f"]),
                1 => str_repeat('n', $random->getInt(240, 300)),
                2 => $this->noise($random, 40, true),
                3 => $names[$random->getInt(0, max(0, $i - 1))] ?? 'worker',
                default => $this->lockName($random),
            };
            $timeouts[] = ! $uncommon ? $random->getInt(0, 900) : match ($random->getInt(0, 5)) {
                0 => $random->getInt(PHP_INT_MIN, -1),
                1 => $random->getInt(9_223_372_000, PHP_INT_MAX),
                default => $random->getInt(0, 86_400),
            };
        }
        $directory = $uncommon && $random->getInt(0, 1) === 0 ? '/'.str_repeat('d', $random->getInt(900, 1020)) : '/var/lib/nntmux/storage/app/release-verification/locks';
        $now = $uncommon ? $this->pick($random, [(string) PHP_INT_MIN, (string) (PHP_INT_MAX - 1000), '0', (string) hrtime(true)]) : (string) (1_700_000_000_000_000_000 + $random->getInt(0, 1_000_000_000_000));

        return [$directory, $names, $timeouts, (int) $now];
    }

    /** @return list<mixed> */
    private function heartbeatBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : 64;
        $now = 1_000.0 + $random->getFloat(0.0, 100_000.0);
        $ticks = [];
        for ($i = 0; $i < $count; $i++) {
            $now += $random->getFloat(0.0, 0.75);
            $ticks[] = ! $uncommon ? $now : match ($random->getInt(0, 11)) {
                0 => $now - $random->getFloat(0.0, 30.0),
                1 => $this->pick($random, ['nan', 'inf', '-inf']) === 'nan' ? NAN : ($random->getInt(0, 1) === 0 ? INF : -INF),
                2 => -$now,
                default => $now,
            };
        }
        $interval = $uncommon ? (float) $this->pick($random, ['0', '-1', '0.001', '5', '30', '1e300']) : (float) $this->pick($random, ['1', '2.5', '5', '10']);
        $last = $uncommon && $random->getInt(0, 1) === 0 ? $ticks[0] - $random->getFloat(0.0, 10.0) : null;

        return [$last, $ticks, $interval];
    }
}
