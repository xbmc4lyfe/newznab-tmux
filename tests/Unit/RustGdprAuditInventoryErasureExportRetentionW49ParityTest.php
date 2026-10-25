<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gdpr\GdprAuditService;
use App\Services\Gdpr\GdprDataInventory;
use App\Services\Gdpr\GdprErasureService;
use App\Services\Gdpr\GdprExportService;
use App\Services\Gdpr\GdprRetentionService;
use Closure;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustGdprAuditInventoryErasureExportRetentionW49ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        GdprAuditService::class => 'summarizeAuditRows',
        GdprDataInventory::class => 'classifyTables',
        GdprErasureService::class => 'planMetadataMinimization',
        GdprExportService::class => 'planRowRedactions',
        GdprRetentionService::class => 'evaluatePurgeBatch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of three runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 49, 200 pairs
     * per workload after 20 warmups, randomized call order, each side the fastest of three
     * back-to-back calls, release Rust, PHP 8.5.12 CLI without opcache, Apple Silicon macOS
     * (shared host, load ~13). Timings include dispatch, NUL joining, the FFI call and
     * unpack('P*'). A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        GdprAuditService::class => [3.676, 4.198, 3.520, 3.715],
        GdprDataInventory::class => [2.551, 2.267, 3.023, 2.851],
        GdprErasureService::class => [3.585, 3.185, 3.890, 3.712],
        GdprExportService::class => [3.481, 3.155, 3.460, 3.482],
        GdprRetentionService::class => [2.729, 3.005, 5.518, 4.681],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 49;

    private const NOW = 1_793_000_000;

    private const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const AGENTS = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0',
        'SABnzbd/4.3.3',
        'NZBGet/24.3',
    ];

    /** @var list<string>|null */
    private static ?array $edgeCases = null;

    private const OTHER_TABLES = ['users', 'releases', 'settings', 'categories', 'usenet_groups', 'binaries', 'parts', 'collections', 'predb', 'jobs', 'failed_jobs', 'sessions', 'cache', 'migrations', 'roles', 'permissions', 'release_nfos', 'video_data', 'tv_episodes', 'movieinfo'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW49RustFfi', 'withW49RustFfi', $entry, $entry.'Php', $entry.'Native', 'w49Ffi'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('withW49RustFfi')->isStatic(), $class);
            $constant = $reflection->getReflectionConstant('W49_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            $property = $reflection->getProperty('useW49RustFfi');
            self::assertTrue($property->isPrivate() && $property->isStatic(), $class);
            self::assertSame('?bool', (string) $property->getType(), $class);
            self::assertNull($property->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w49_', (string) $reflection->getConstant('W49_FFI_CDEF'));

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
        }
    }

    public function test_with_w49_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW49RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW49RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW49RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
                self::fail('exception expected');
            } catch (RuntimeException $e) {
                self::assertSame('boom', $e->getMessage());
            }
            self::assertSame($default, $this->uses($class), $class.' routing must be restored after an exception');
        }
        // The Wave 49 switches are independent of the earlier Rust toggles.
        self::assertFalse(GdprAuditService::usesRustFfi());
        self::assertFalse(GdprDataInventory::usesRustFfi());
        self::assertFalse(GdprErasureService::usesRustFfi());
        self::assertFalse(GdprExportService::usesRustFfi());
        self::assertFalse(GdprRetentionService::usesRustFfi());
    }

    public function test_bindings_resolve_and_accept_a_one_item_batch(): void
    {
        $this->requireBindings();
    }

    public function test_audit_summary_parity(): void
    {
        $this->requireBindings();
        $events = ['erasure_force_delete', 'x', 'export_generated', 'request_rejected'];
        $metadata = ['{"force_delete":true,"retained_records":[],"email":"a@b"}', '{"subject":"deleted_user_1"}', null, '[1,2]'];
        $expected = [
            4 | 16 | 3 << 8 | (1 << 25 | 1 << 26 | 1 << 30) | 1 << 34 | 20 << 40 | 1 << 48, -1,
            15 | 16 | 1 << 8 | 1 << 24 | 1 << 33 | 1 << 40, crc32('deleted_user_1'),
            2 | 16 << 40, -1,
            1 | 16 | 2 << 8 | 16 << 40, -1,
        ];
        self::assertSame($expected, GdprAuditService::summarizeAuditRowsPhp($events, $metadata));
        $this->assertParity(GdprAuditService::class, [$events, $metadata]);
        $this->assertParity(GdprAuditService::class, [[], []]);
        $this->assertParity(GdprAuditService::class, [['a', 'b'], ['{}']]);
        $this->assertParity(GdprAuditService::class, [["a\0b"], ['{}']]);
        $this->assertParity(GdprAuditService::class, [['a'], ["{}\0"]]);
        $this->assertParity(GdprAuditService::class, [['', str_repeat('e', 300), 'REQUEST_SUBMITTED', ' request_submitted'], ['{"subject":1}', '{"subject":"deleted_user_"}', '{"subject":"Deleted_user_1","subject":"deleted_user_2"}', '{"\u0073ubject":"\u0064eleted_user_\ud83d\ude00"}']]);
        $edge = $this->jsonEdgeCases();
        $this->assertParity(GdprAuditService::class, [array_fill(0, \count($edge), 'request_submitted'), $edge]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(GdprAuditService::class, $this->auditBatch(new Randomizer(new Mt19937(4910 + $i)), $uncommon));
            }
        }
    }

    public function test_inventory_classification_parity(): void
    {
        $this->requireBindings();
        $names = [' `NNTMUX`.`Trusted_Devices` ', 'invitations', 'gdpr_audit_logs', 'passkeys', 'unknown', 'payments'];
        $expected = [
            1 | 4 | 9 << 8 | 13 << 16 | 1 << 21 | 2 << 24 | 2 << 32 | 15 << 40 | 1 << 48,
            4 | 11 << 16 | 2 << 21 | 5 << 24 | 2 << 32 | 11 << 40,
            4 | 8 | 18 << 16 | 1 << 21 | 2 << 24 | 2 << 32 | 2 << 36 | 15 << 40,
            1 | 11 << 8 | 3 << 24 | 1 << 28 | 8 << 40,
            7 << 40,
            8 | 1 << 36 | 8 << 40,
        ];
        self::assertSame($expected, GdprDataInventory::classifyTablesPhp($names));
        $this->assertParity(GdprDataInventory::class, [$names]);

        // Every inventoried table carries the flags of each list naming it.
        $words = GdprDataInventory::w49TableWords();
        foreach (array_keys(GdprDataInventory::erasableTablesPhp()) as $table) {
            self::assertSame(1, $words[$table] & 1, $table);
        }
        foreach (array_keys(GdprDataInventory::anonymizedTablesPhp()) as $table) {
            self::assertSame(2, $words[$table] & 2, $table);
        }
        foreach (GdprDataInventory::exportTablesPhp() as $key => $definition) {
            self::assertSame(4, $words[$definition['table'] ?? $key] & 4, $key);
        }
        foreach (GdprDataInventory::retainedRecordsPhp() as $record) {
            self::assertSame(8, $words[$record['table']] & 8, $record['table']);
        }
        $this->assertParity(GdprDataInventory::class, [[...array_keys($words), ...self::OTHER_TABLES]]);
        $this->assertParity(GdprDataInventory::class, [[]]);
        $this->assertParity(GdprDataInventory::class, [["user\0requests"]]);
        $this->assertParity(GdprDataInventory::class, [['', ' ', '.', '..', '`', '""', '`"`', 'a.b.c.passkeys', 'passkeys.', '.passkeys', "\t\x0B\rPASSKEYS\n", '"passkeys`', '`pass.keys`', "db.\tpasskeys", 'pässkeys', '123', str_repeat('t', 300), 'user_requests ', ' user_requests', "\x00x"]]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(GdprDataInventory::class, $this->inventoryBatch(new Randomizer(new Mt19937(4920 + $i)), $uncommon));
            }
        }
    }

    public function test_erasure_minimization_parity(): void
    {
        $this->requireBindings();
        $metadata = ['{"email":1,"host":2,"gdpr_minimized":true,"k":0}', null, '{"gdpr_minimized":true}', '[1,2]', '{"gdpr_minimized":1}'];
        $expected = [
            1 | 0b101 << 1 | 1 << 4 | 1 << 5 | 4 << 8 | 2 << 24 | 48 << 44,
            1 << 24,
            1 | 1 << 4 | 1 << 5 | 1 << 8 | 1 << 24 | 1 << 40 | 23 << 44,
            1 | 2 << 8 | 3 << 24 | 5 << 44,
            1 | 1 << 4 | 1 << 8 | 1 << 24 | 20 << 44,
        ];
        self::assertSame($expected, GdprErasureService::planMetadataMinimizationPhp($metadata));
        $this->assertParity(GdprErasureService::class, [$metadata]);
        $edge = $this->jsonEdgeCases();
        $this->assertParity(GdprErasureService::class, [$edge]);
        $this->assertParity(GdprErasureService::class, [[]]);
        $this->assertParity(GdprErasureService::class, [["{\"a\":\"\0\"}"]]);
        $this->assertParity(GdprErasureService::class, [['{"email":null,"ip_address":false,"host":"","gdpr_minimized":false,"gdpr_minimized":true}', '{"\u0065mail":"x","e\u006dail":"y"}', '{"0":1,"1":2,"email":3}', '{"gdpr_minimized":"true"}', '{"gdpr_minimized":true,"gdpr_minimized":null}']]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                [$batch] = $this->erasureBatch(new Randomizer(new Mt19937(4930 + $i)), $uncommon);
                $this->assertParity(GdprErasureService::class, [$batch]);
                // The sanitized-count field equals count(sanitizeMetadataPhp()) for every value.
                foreach (GdprErasureService::planMetadataMinimizationPhp($batch) as $j => $word) {
                    self::assertSame(min(0xFFFF, \count(GdprErasureService::sanitizeMetadataPhp($batch[$j] ?? ''))), $word >> 24 & 0xFFFF);
                }
            }
        }
    }

    public function test_export_redaction_parity(): void
    {
        $this->requireBindings();
        $rows = ['{"password":"x","1":2}', '[0,1]', 'nope', '{}'];
        $columns = ['password', '1', 'password'];
        $expected = [1 | 2 << 1 | 3 << 17 | 7 << 24, 1 | 2 << 1 | 1 << 17 | 2 << 24, 0, 1];
        self::assertSame($expected, GdprExportService::planRowRedactionsPhp($rows, $columns));
        $this->assertParity(GdprExportService::class, [$rows, $columns]);
        $this->assertParity(GdprExportService::class, [$rows, []]);
        $this->assertParity(GdprExportService::class, [$rows, ['']]);
        $this->assertParity(GdprExportService::class, [[], $columns]);
        $this->assertParity(GdprExportService::class, [['{}'], ["a\0b"]]);
        $this->assertParity(GdprExportService::class, [["{}\0"], ['a']]);
        $lists = ['[]', '[0]', '[0,1,2,3,4,5,6,7,8,9,10]', '{"0":1,"-0":2,"01":3,"":4}', '{"9223372036854775807":1,"9223372036854775808":2,"-9223372036854775808":3}'];
        $numeric = ['0', '1', '10', '11', '-0', '-1', '01', '1.0', ' 1', '', '9223372036854775807', '9223372036854775808', '-9223372036854775808'];
        $this->assertParity(GdprExportService::class, [$lists, $numeric]);
        $many = array_map(static fn (int $i): string => 'c'.$i, range(0, 39));
        $wide = json_encode(array_fill_keys($many, 1), JSON_THROW_ON_ERROR);
        $this->assertParity(GdprExportService::class, [[$wide, '{"c35":1,"c0":2}'], [...$many, ...$many]]);
        $edge = $this->jsonEdgeCases();
        $this->assertParity(GdprExportService::class, [$edge, ['password', 'a', 'subject', 'email', '0']]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(GdprExportService::class, $this->exportBatch(new Randomizer(new Mt19937(4940 + $i)), $uncommon));
            }
        }
    }

    public function test_retention_purge_parity(): void
    {
        $this->requireBindings();
        $path = GdprExportService::formatExportPathPhp(42, '20261025120000', 'AbCdEf123456');
        $expected = [0b111_1111 | \strlen($path) << 8 | 5 << 24, 42, 1 << 3 | 1 << 7 | 6 << 8 | 2 << 24, -1, 0b10110 | 5 << 24, -1];
        self::assertSame($expected, GdprRetentionService::evaluatePurgeBatchPhp(['0', 's3', null], [$path, 'a/../b', null], [5, null, 1], 10));
        $this->assertParity(GdprRetentionService::class, [['0', 's3', null], [$path, 'a/../b', null], [5, null, 1], 10]);
        $this->assertParity(GdprRetentionService::class, [[], [], [], 10]);
        $this->assertParity(GdprRetentionService::class, [['local'], [$path, $path], [1, 2], 10]);
        $this->assertParity(GdprRetentionService::class, [['local', 'local'], [$path, $path], [1], 10]);
        $this->assertParity(GdprRetentionService::class, [["lo\0cal"], [$path], [1], 10]);
        $this->assertParity(GdprRetentionService::class, [['local'], ["{$path}\0"], [1], 10]);
        $odd = [
            'gdpr/exports/user-0-20261025120000-AbCdEf123456.json', 'gdpr/exports/user-042-20261025120000-AbCdEf123456.json',
            'gdpr/exports/user-42-2026102512000-AbCdEf123456.json', 'gdpr/exports/user-42-20261025120000-AbCdEf12345_.json',
            'gdpr/exports/user-42-20261025120000-AbCdEf123456.JSON', "{$path}\n", " {$path}", 'gdpr/exports/user--1-20261025120000-AbCdEf123456.json',
            'gdpr/exports/user-123456789012345678-20261025120000-AbCdEf123456.json', 'gdpr/exports/user-1234567890123456789-20261025120000-AbCdEf123456.json',
            'gdpr/exports/user-42-20261025120000-AbCdEf1234567.json', 'gdpr/exports/user-42-20261025120000-AbCdEf12345.json', 'gdpr/exports/../../.env', '..', '../', '/..', 'a/..b/c', 'a/.../c',
            'gdpr/exports/user-42-٢٠٢٦١٠٢٥١٢٠٠٠٠-AbCdEf123456.json', str_repeat('p', 70_000), '', null,
        ];
        $n = \count($odd);
        $disks = array_slice(array_merge(...array_fill(0, 5, ['', '0', '00', ' local', 'LOCAL', null, 'local', 's3', str_repeat('d', 300)])), 0, $n);
        $expires = array_slice(array_merge(...array_fill(0, 5, [PHP_INT_MIN, PHP_INT_MAX, 10, 9, 11, null, -1, 0])), 0, $n);
        $this->assertParity(GdprRetentionService::class, [$disks, $odd, $expires, 10]);
        $this->assertParity(GdprRetentionService::class, [$disks, $odd, $expires, PHP_INT_MIN]);
        $this->assertParity(GdprRetentionService::class, [$disks, $odd, $expires, PHP_INT_MAX]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $args = $this->retentionBatch(new Randomizer(new Mt19937(4950 + $i)), $uncommon);
                $this->assertParity(GdprRetentionService::class, $args);
                // Purge, disk and path fields agree with resolveExportPurgeTargetPhp().
                [$disks, $paths, $expires, $now] = $args;
                $words = GdprRetentionService::evaluatePurgeBatchPhp($disks, $paths, $expires, $now);
                foreach ($paths as $j => $p) {
                    $target = GdprRetentionService::resolveExportPurgeTargetPhp($disks[$j], $p, $expires[$j], $now);
                    self::assertSame((int) $target['should_purge'], $words[$j * 2] & 1);
                    self::assertSame((int) ($target['disk'] === 'local'), $words[$j * 2] >> 2 & 1);
                    self::assertSame(min(0xFFFF, \strlen($target['path'])), $words[$j * 2] >> 8 & 0xFFFF);
                }
            }
        }
    }

    /**
     * Paired randomized benchmark: per class and workload, PHP and Rust run the same fixture in a
     * random order, 20 warmups then 200 measured pairs drawn from eight fixtures; each side of a
     * pair is the fastest of three back-to-back calls. Nearest-rank p99. Every pair must agree; the default must be Rust exactly when all four ratios reach
     * 1.5x. Raw pairs are written outside the checkout.
     */
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW49RustFfi($native, static function () use ($call, $args): array {
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
        $artifact = sys_get_temp_dir().'/w491-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW49.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function uses(string $class): bool
    {
        return $class::usesW49RustFfi();
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function entry(string $class): Closure
    {
        return match ($class) {
            GdprAuditService::class => GdprAuditService::summarizeAuditRows(...),
            GdprDataInventory::class => GdprDataInventory::classifyTables(...),
            GdprErasureService::class => GdprErasureService::planMetadataMinimization(...),
            GdprExportService::class => GdprExportService::planRowRedactions(...),
            GdprRetentionService::class => GdprRetentionService::evaluatePurgeBatch(...),
        };
    }

    /**
     * Rust-routed, PHP-routed, direct PHP and direct native results must be identical.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $call = $this->entry($class);
        $php = $class::withW49RustFfi(false, static fn (): mixed => $call(...$args));
        $rust = $class::withW49RustFfi(true, static fn (): mixed => $call(...$args));
        $entry = self::ENTRIES[$class];
        self::assertSame($php, $rust, $class);
        self::assertSame($php, [$class, $entry.'Php'](...$args), $class);
        self::assertSame($php, [$class, $entry.'Native'](...$args), $class);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            GdprAuditService::class => $this->auditBatch($random, $uncommon),
            GdprDataInventory::class => $this->inventoryBatch($random, $uncommon),
            GdprErasureService::class => $this->erasureBatch($random, $uncommon),
            GdprExportService::class => $this->exportBatch($random, $uncommon),
            GdprRetentionService::class => $this->retentionBatch($random, $uncommon),
        };
    }

    /**
     * Each Wave 49 export must resolve and accept a one-item batch, so parity runs Rust.
     */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            GdprAuditService::class => ['nntmux_w49_gdpr_audit_summarize_batch', ['e', 1, '{}', 2, 1], 2],
            GdprDataInventory::class => ['nntmux_w49_gdpr_inventory_classify_batch', ['t', 1, 1], 1],
            GdprErasureService::class => ['nntmux_w49_gdpr_erasure_metadata_plan_batch', ['{}', 2, 1], 1],
            GdprExportService::class => ['nntmux_w49_gdpr_export_redaction_plan_batch', ['{}', 2, 1, 'a', 1, 1], 1],
            GdprRetentionService::class => ['nntmux_w49_gdpr_retention_purge_batch', ['', 0, 'p', 1, pack('P', 1), 8, '', 0, 1, 5], 2],
        ];
        foreach ($calls as $class => [$symbol, $args, $words]) {
            $ffi = (new ReflectionMethod($class, 'w49Ffi'))->invoke(null);
            self::assertInstanceOf(FFI::class, $ffi, $class.' must load the gdpr_w49 binding');
            $out = $ffi->new('int64_t[4]');
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$args, $out, 4]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, $words - 1]), $symbol.' short capacity');
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

    /** @return list<string> */
    private function jsonEdgeCases(): array
    {
        return self::$edgeCases ??= $this->buildJsonEdgeCases();
    }

    /** @return list<string> */
    private function buildJsonEdgeCases(): array
    {
        $deep = static fn (int $n): string => str_repeat('[', $n).str_repeat(']', $n);

        return [
            '', ' ', 'null', 'true', 'TRUE', 'True', '1', '-0', '1e5', '"str"', '[]', '{}', ' {} ', "\t[\n]\r", '{"a":1}x', '{"a":1,}', '[1,]', '{"a"}', '{a:1}', "{'a':1}",
            '{"a":01}', '{"a":1.}', '{"a":.5}', '{"a":-}', '{"a":1e}', '{"a":1E+2,"b":-0.0e-0}', '{"a":123456789012345678901234567890}', '{"a":1e999}',
            '{"a":"\u0000"}', "{\"a\":\"\x01\"}", "{\"a\":\"\x7f\"}", '{"a":"\ud800"}', '{"a":"\udc00\ud800"}', '{"a":"\ud83d\ude00"}', '{"a":"\uD83D\uDE00"}', '{"a":"\u00e9"}', '{"a":"\x"}', '{"a":"\u12"}',
            "{\"a\":\"\xC3\xA9\"}", "{\"a\":\"\xC3\"}", "{\"a\":\"\xED\xA0\x80\"}", "{\"a\":\"\xF4\x90\x80\x80\"}", "\xEF\xBB\xBF{}", "{\"a\":\"\xE2\x82\"}",
            '{"":1,"":2}', '{"a":1,"A":2,"a":3}', '{"email":1,"email":2}', '{"\u0000":1}', '{"\u0000email":1}', '{"emai\u006c":1,"email":2}',
            '{"subject":"deleted_user_9","force_delete":true,"retained_records":null,"path":"","expires_at":0,"gdpr_minimized":true,"email":"","ip_address":"","host":""}',
            '{"force_delete":"true"}', '{"force_delete":1}', '{"force_delete":true ,"x":1}', '{"force_delete" : true}', '{"force_delete":truex}', '{"force_delete":tru}',
            $deep(510), $deep(511), $deep(512), '{"a":'.$deep(510).'}', '{"a":'.$deep(511).'}', str_repeat('{"a":', 511).'1'.str_repeat('}', 511), str_repeat('{"a":', 512).'1'.str_repeat('}', 512),
            json_encode(['subject' => 'deleted_user_1', 'nested' => ['email' => 'x', 'list' => [1, 2, ['host' => 'h']]]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            json_encode(array_fill_keys(array_map(static fn (int $i): string => 'k'.$i, range(0, 299)), 'v'), JSON_THROW_ON_ERROR),
            json_encode(range(0, 70_000), JSON_THROW_ON_ERROR),
            '[1,2,3,{"email":1}]', '[{"email":1}]', '{"0":"a","1":"b"}', '{"1":1,"01":2,"1":3}',
        ];
    }

    /** @param  list<string>  $values */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function alnum(Randomizer $random, int $length): string
    {
        return $random->getBytesFromString(self::ALNUM, $length);
    }

    private function exportPath(Randomizer $random): string
    {
        return GdprExportService::formatExportPathPhp($random->getInt(1, 250_000), '2026'.$random->getBytesFromString('0123456789', 10), $this->alnum($random, 12));
    }

    private function ip(Randomizer $random): string
    {
        return $random->getInt(0, 3) === 0
            ? sprintf('2001:db8:%x::%x', $random->getInt(0, 0xFFFF), $random->getInt(1, 0xFFFF))
            : sprintf('%d.%d.%d.%d', $random->getInt(1, 223), $random->getInt(0, 255), $random->getInt(0, 255), $random->getInt(1, 254));
    }

    private function email(Randomizer $random): string
    {
        return strtolower($this->alnum($random, $random->getInt(4, 12))).'@'.$this->pick($random, ['example.com', 'mail.example.org', 'proton.me']);
    }

    /** A malformed or unusual JSON value drawn from the edge cases plus random mutations. */
    private function oddJson(Randomizer $random, string $base): string
    {
        return match ($random->getInt(0, 5)) {
            0 => $this->pick($random, $this->jsonEdgeCases()),
            1 => substr($base, 0, $random->getInt(0, max(0, \strlen($base) - 1))),
            2 => json_encode(json_decode($base, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $base,
            3 => str_replace('"email"', '"\u0065mail"', $base),
            4 => '['.$base.','.$base.']',
            default => rtrim($base, '}').',"email":"dup@example.com","email":null}',
        };
    }

    /** @return array{0: list<string>, 1: list<?string>} */
    private function auditBatch(Randomizer $random, bool $uncommon): array
    {
        $retained = json_encode(GdprDataInventory::retainedRecordsPhp(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $events = $metadata = [];
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        for ($i = 0; $i < $count; $i++) {
            $id = $random->getInt(1, 250_000);
            [$event, $json] = match ($random->getInt(0, 5)) {
                0 => ['request_submitted', json_encode(['type' => $this->pick($random, ['export', 'erasure']), 'source' => 'privacy_center', 'notes' => 'Submitted from the privacy center.'], JSON_THROW_ON_ERROR)],
                1 => ['export_generated', json_encode(['path' => $this->exportPath($random), 'expires_at' => '2026-11-0'.$random->getInt(1, 9).'T12:00:00+00:00'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
                2 => [$this->pick($random, ['erasure_soft_delete', 'erasure_force_delete']), '{"retained_records":'.$retained.',"force_delete":'.($random->getInt(0, 1) === 1 ? 'true' : 'false').'}'],
                3 => ['erasure_soft_delete', json_encode(['subject' => 'deleted_user_'.$id], JSON_THROW_ON_ERROR)],
                4 => ['request_rejected', json_encode(['reason' => 'Identity could not be verified.', 'email' => $this->email($random), 'ip_address' => $this->ip($random)], JSON_THROW_ON_ERROR)],
                default => ['request_submitted', null],
            };
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $event = $random->getInt(0, 1) === 0 ? $this->pick($random, ['', 'export_downloaded', 'Erasure_Force_Delete', str_repeat('x', 300), 'consent_withdrawn']) : $event;
                $json = $this->oddJson($random, $json ?? '{"subject":"deleted_user_'.$id.'"}');
            }
            $events[] = $event;
            $metadata[] = $json;
        }

        return [$events, $metadata];
    }

    /** @return array{0: list<string>} */
    private function inventoryBatch(Randomizer $random, bool $uncommon): array
    {
        $known = array_keys(GdprDataInventory::w49TableWords());
        $names = [];
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        for ($i = 0; $i < $count; $i++) {
            $name = $random->getInt(0, 1) === 0 ? $this->pick($random, $known) : $this->pick($random, self::OTHER_TABLES);
            if ($random->getInt(0, 1) === 0) {
                $name = 'nntmux.'.$name;
            }
            if ($uncommon) {
                $name = match ($random->getInt(0, 7)) {
                    0 => '`'.strtoupper($name).'`',
                    1 => '"nntmux"."'.$name.'"',
                    2 => " \t".$name."\n",
                    3 => 'a.b.c.'.$name,
                    4 => $this->pick($random, ['', '.', '..', '`', '""', 'passkeys.', str_repeat('n', 300), '123', 'pässkeys']),
                    5 => ucfirst($name).'_archive',
                    6 => '`nntmux`.`'.$name.'`',
                    default => $name,
                };
            }
            $names[] = $name;
        }

        return [$names];
    }

    /** @return array{0: list<?string>} */
    private function erasureBatch(Randomizer $random, bool $uncommon): array
    {
        $values = [];
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        for ($i = 0; $i < $count; $i++) {
            $activity = ['route' => $this->pick($random, ['login', 'logout', 'api.search', 'profile.update', 'nzb.download']), 'user_agent' => $this->pick($random, self::AGENTS)];
            if ($random->getInt(0, 3) !== 0) {
                $activity['ip_address'] = $this->ip($random);
            }
            if ($random->getInt(0, 2) === 0) {
                $activity['email'] = $this->email($random);
            }
            if ($random->getInt(0, 2) === 0) {
                $activity['host'] = 'host-'.$this->alnum($random, 8).'.example.net';
            }
            if ($random->getInt(0, 4) === 0) {
                $activity += ['old_role' => 'User', 'new_role' => 'Friend', 'changed_by' => $random->getInt(1, 50)];
            }
            $json = json_encode($random->getInt(0, 5) === 0 ? GdprErasureService::sanitizeMetadataPhp($activity) : $activity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $json = $random->getInt(0, 9) === 0 ? null : $this->oddJson($random, $json);
            }
            $values[] = $json;
        }

        return [$values];
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function exportBatch(Randomizer $random, bool $uncommon): array
    {
        $rows = [];
        $passkeys = $random->getInt(0, 1) === 0;
        $columns = $passkeys ? ['credential_id', 'data'] : ['password', 'remember_token', 'session_token', 'api_token', 'resetguid', 'verification_token', 'sabapikey', 'nzbgetpassword', 'nzbvortex_api_key', 'cp_api'];
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        for ($i = 0; $i < $count; $i++) {
            $id = $random->getInt(1, 250_000);
            $row = match ($random->getInt(0, 2)) {
                0 => ['id' => $id, 'users_id' => $random->getInt(1, 5000), 'request' => 'api?t=search&q='.$this->alnum($random, 10), 'hosthash' => bin2hex($random->getBytes(16)), 'timestamp' => '2026-10-'.$random->getInt(10, 28).' 12:00:00'],
                1 => ['id' => $id, 'authenticatable_id' => $random->getInt(1, 5000), 'authenticatable_type' => 'App\\Models\\User', 'name' => 'YubiKey', 'credential_id' => base64_encode($random->getBytes(32)), 'data' => json_encode(['publicKey' => base64_encode($random->getBytes(64))]), 'last_used_at' => null, 'created_at' => '2026-01-01 00:00:00'],
                default => ['id' => $id, 'username' => strtolower($this->alnum($random, 9)), 'email' => $this->email($random), 'password' => '$2y$12$'.$this->alnum($random, 53), 'api_token' => bin2hex($random->getBytes(20)), 'roles_id' => 2, 'grabs' => $random->getInt(0, 9000), 'apiaccess' => 1000, 'host' => $this->ip($random), 'sabapikey' => null, 'nzbgetpassword' => null, 'cp_api' => null, 'created_at' => '2024-03-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00', 'style' => 'dark', 'rate_limit' => 60],
            };
            $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $json = $this->oddJson($random, $json);
            }
            $rows[] = $json;
        }
        if ($uncommon) {
            $extra = $random->getInt(0, 1) === 0 ? ['0', '1', '-1', '01', ''] : array_map(static fn (int $n): string => 'k'.$n, range(0, 29));
            $columns = [...$columns, ...$extra, 'email', 'password'];
        }

        return [$rows, $columns];
    }

    /** @return array{0: list<?string>, 1: list<?string>, 2: list<?int>, 3: int} */
    private function retentionBatch(Randomizer $random, bool $uncommon): array
    {
        $disks = $paths = $expires = [];
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        for ($i = 0; $i < $count; $i++) {
            $disks[] = $random->getInt(0, 9) < 8 ? 'local' : $this->pick($random, ['s3', 'exports']);
            $paths[] = $this->exportPath($random);
            $expires[] = self::NOW + $random->getInt(-14 * 86_400, 3 * 86_400);
            if ($uncommon && $random->getInt(0, 1) === 0) {
                $disks[$i] = $random->getInt(0, 4) === 0 ? null : $this->pick($random, ['', '0', '00', ' local', 'LOCAL', 'local', str_repeat('d', 300)]);
                $paths[$i] = match ($random->getInt(0, 6)) {
                    0 => null,
                    1 => $this->pick($random, ['', '..', '../', 'gdpr/exports/../../.env', 'a/../b', "{$paths[$i]}\n", ' '.$paths[$i]]),
                    2 => str_replace('user-', 'user-0', $paths[$i]),
                    3 => GdprExportService::formatExportPathPhp($this->pick($random, ['max', 'negative']) === 'max' ? PHP_INT_MAX : -$random->getInt(1, 99), '20261025120000', $this->alnum($random, 12)),
                    4 => substr($paths[$i], 0, -6).'.JSON',
                    5 => str_repeat('p/', $random->getInt(1, 40_000)),
                    default => $paths[$i],
                };
                $expires[$i] = match ($random->getInt(0, 4)) {
                    0 => null,
                    1 => $this->pick($random, ['min', 'max']) === 'min' ? PHP_INT_MIN : PHP_INT_MAX,
                    2 => self::NOW,
                    default => $expires[$i],
                };
            }
        }

        return [$disks, $paths, $expires, self::NOW];
    }
}
